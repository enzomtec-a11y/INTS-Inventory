<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id = getUsuarioId();
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;
$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id : null;

// Se admin_unidade, pega ids de locais permitidos
$ids_permitidos = [];
if ($filtro_unidade) {
    $ids_permitidos = getIdsLocaisDaUnidade($conn, $filtro_unidade);
}

// Carrega mapa de locais
$mapa_locais = function_exists('getLocaisFormatados') ? getLocaisFormatados($conn, false) : [];

/**
 * AJAX handler: retorna locais onde um produto está armazenado
 * Endpoint: solicitar.php?acao=locais_produto&produto_id=NN
 * Retorna JSON: {sucesso:true, locais:[{local_id, local_nome, quantidade}, ...]}
 */
if (isset($_GET['acao']) && $_GET['acao'] === 'locais_produto') {
    header('Content-Type: application/json; charset=utf-8');
    $produto_id_q = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    if ($produto_id_q <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'produto_id inválido', 'locais' => []]);
        exit;
    }

    // Buscar locais onde o produto tem estoque
    $sql = "
        SELECT e.local_id, COALESCE(l.nome, 'Sem local') as local_nome, e.quantidade
        FROM estoques e
        LEFT JOIN locais l ON e.local_id = l.id
        WHERE e.produto_id = ? AND e.quantidade > 0
    ";

    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $sql .= " AND e.local_id IN ($idsStr)";
    }

    $sql .= " ORDER BY e.local_id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao preparar SQL: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $produto_id_q);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'local_id' => (int)$r['local_id'],
            'local_nome' => $r['local_nome'],
            'quantidade' => (float)$r['quantidade']
        ];
    }
    $stmt->close();
    echo json_encode(['sucesso' => true, 'locais' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- BUSCAR LISTA DE PRODUTOS QUE POSSUEM ESTOQUE ---
$produtos = [];
$sql_produtos = "
    SELECT DISTINCT p.id AS produto_id, p.nome
    FROM produtos p
    WHERE p.deletado = FALSE
      AND EXISTS (
         SELECT 1 FROM estoques e
         WHERE e.produto_id = p.id AND e.quantidade > 0
    )
    ORDER BY p.nome
";

// Se admin_unidade, restringe aos produtos que têm estoque na unidade
if ($filtro_unidade && !empty($ids_permitidos)) {
    $idsStr = implode(',', array_map('intval', $ids_permitidos));
    $sql_produtos = "
        SELECT DISTINCT p.id AS produto_id, p.nome
        FROM produtos p
        WHERE p.deletado = FALSE
          AND EXISTS (
             SELECT 1 FROM estoques e
             WHERE e.produto_id = p.id 
               AND e.quantidade > 0
               AND e.local_id IN ($idsStr)
          )
        ORDER BY p.nome
    ";
}

$resp = $conn->query($sql_produtos);
if ($resp) {
    while ($r = $resp->fetch_assoc()) {
        $produtos[] = $r;
    }
}

// Carregar lista de destinos (locais)
$locais_destino = [];
if (function_exists('getLocaisFormatados')) {
    $restricao = $filtro_unidade ? $filtro_unidade : null;
    $locais_destino = getLocaisFormatados($conn, true, $restricao);
} else {
    $sql_l = "SELECT id, nome FROM locais WHERE deletado = FALSE";
    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $sql_l .= " AND id IN ($idsStr)";
    }
    $sql_l .= " ORDER BY nome";
    $r2 = $conn->query($sql_l);
    if ($r2) while ($row = $r2->fetch_assoc()) $locais_destino[$row['id']] = $row['nome'];
}

// --- PROCESSAR SUBMIT: criar movimentação ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['acao']) && $_POST['acao'] === 'solicitar') {
    $produto_id = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;
    $origem_id = isset($_POST['local_origem_id']) ? (int)$_POST['local_origem_id'] : 0;
    $destino_id = isset($_POST['local_destino_id']) ? (int)$_POST['local_destino_id'] : 0;
    $quantidade = 1; // fixo (cada movimentação = 1)
    
    if ($produto_id <= 0 || $origem_id <= 0 || $destino_id <= 0) {
        $status_message = "<div class='alert error'>Selecione o produto, origem e destino corretamente.</div>";
    } else {
        // Validar se o produto tem estoque na origem
        $stmt_check = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
        $stmt_check->bind_param("ii", $produto_id, $origem_id);
        $stmt_check->execute();
        $check = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if (!$check || $check['quantidade'] <= 0) {
            $status_message = "<div class='alert error'>Produto não possui estoque neste local.</div>";
        } elseif ($origem_id === $destino_id) {
            $status_message = "<div class='alert error'>Origem e destino não podem ser o mesmo local.</div>";
        } else {
            // Validações de permissão para admin_unidade
            if ($filtro_unidade && !empty($ids_permitidos)) {
                if (!in_array($origem_id, $ids_permitidos)) {
                    $status_message = "<div class='alert error'>Você não tem permissão sobre o local de origem.</div>";
                } elseif (!in_array($destino_id, $ids_permitidos)) {
                    $status_message = "<div class='alert error'>Você não tem permissão sobre o local de destino.</div>";
                }
            }

            if (empty($status_message)) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, quantidade, local_origem_id, local_destino_id, usuario_id, status, tipo_movimentacao) VALUES (?, ?, ?, ?, ?, 'pendente', 'TRANSFERENCIA')");
                    $stmt->bind_param("iiiii", $produto_id, $quantidade, $origem_id, $destino_id, $usuario_id);
                    if ($stmt->execute()) {
                        if (function_exists('registrarLog')) {
                            registrarLog($conn, $usuario_id, 'movimentacoes', $stmt->insert_id, 'SOLICITACAO', "Movimentação de $quantidade un.", $produto_id);
                        }
                        $conn->commit();
                        $status_message = "<div class='alert success'>Solicitação enviada com sucesso!</div>";
                    } else {
                        $conn->rollback();
                        $status_message = "<div class='alert error'>Erro ao salvar solicitação.</div>";
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    if ($conn->in_transaction) $conn->rollback();
                    $status_message = "<div class='alert error'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Solicitar Movimentação</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container { max-width: 700px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #ddd; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
        button { width: 100%; padding: 12px; background: #28a745; color: white; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:hover { background: #218838; }
        .small-note { font-size: 0.9em; color: #666; margin-top: 6px; display: block; }
        .origem-display { font-weight: bold; color: #155724; margin-top: 6px; padding: 8px; background: #f0f0f0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Nova Solicitação de Movimentação</h1>
        <?php echo $status_message; ?>

        <form method="POST" id="form-solicitar">
            <input type="hidden" name="acao" value="solicitar">
            <input type="hidden" name="local_origem_id" id="local_origem_id" value="">

            <div class="form-group">
                <label for="produto_id">Produto <span style="color:red">*</span></label>
                <select name="produto_id" id="produto_id" required>
                    <option value="">Selecione um produto...</option>
                    <?php foreach ($produtos as $pr): ?>
                        <option value="<?php echo (int)$pr['produto_id']; ?>">
                            <?php echo htmlspecialchars($pr['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="small-note">Selecione o produto que deseja movimentar.</span>
            </div>

            <div class="form-group">
                <label>Localização Atual</label>
                <div class="origem-display">
                    <span id="origem-text">—</span>
                </div>
            </div>

            <div class="form-group">
                <label for="local_destino_id">Local de Destino <span style="color:red">*</span></label>
                <select name="local_destino_id" id="local_destino_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($locais_destino as $id => $nome): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($nome); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit">Enviar Solicitação</button>
        </form>
    </div>

<script>
(function(){
    const produtoSel = document.getElementById('produto_id');
    const origemText = document.getElementById('origem-text');
    const origemHidden = document.getElementById('local_origem_id');
    const destinoSel = document.getElementById('local_destino_id');

    // Ao selecionar um produto, carrega os locais onde ele tem estoque
    produtoSel.addEventListener('change', async function() {
        const pid = this.value;
        origemText.textContent = '—';
        origemHidden.value = '';
        
        if (!pid) return;

        try {
            const res = await fetch(`?acao=locais_produto&produto_id=${encodeURIComponent(pid)}`);
            const data = await res.json();
            
            if (!data.sucesso || !data.locais || data.locais.length === 0) {
                origemText.textContent = 'Nenhum estoque encontrado para este produto';
                return;
            }

            // Pega o primeiro local com estoque e exibe
            const primeiro = data.locais[0];
            origemText.textContent = primeiro.local_nome + ' (' + primeiro.quantidade + ' un.)';
            origemHidden.value = primeiro.local_id;

            // Remove este local das opções de destino
            for (const opt of destinoSel.options) {
                opt.disabled = false;
                opt.style.display = '';
            }
            const same = destinoSel.querySelector(`option[value="${primeiro.local_id}"]`);
            if (same) {
                same.disabled = true;
                same.style.display = 'none';
                if (destinoSel.value === String(primeiro.local_id)) destinoSel.value = '';
            }
        } catch (err) {
            console.error(err);
            origemText.textContent = 'Erro ao carregar locais';
        }
    });
})();
</script>
</body>
</html>