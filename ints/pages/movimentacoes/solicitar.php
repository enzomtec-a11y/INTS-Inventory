<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id = getUsuarioId();
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;
$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id : null;

// --- FUNÇÕES AUXILIARES ---

/**
 * Retorna o saldo disponível do produto em um local específico.
 * Se o produto controla estoque próprio, retorna o valor em estoques.quantidade.
 * Se o produto é kit (controla_estoque_proprio == 0), calcula disponibilidade
 * com base nos componentes diretos (min(estoque_componente / qtd_por_unidade)).
 * Retorna float (0 se não houver registro).
 */
function getSaldoDisponivel($conn, $produto_id, $local_id) {
    $produto_id = (int)$produto_id;
    $local_id = (int)$local_id;
    if ($produto_id <= 0 || $local_id <= 0) return 0.0;

    // Busca se o produto controla estoque próprio
    $stmt = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$prod) return 0.0;

    $controla = (int)($prod['controla_estoque_proprio'] ?? 1);

    if ($controla === 1) {
        // Produto físico: pega estoque direto
        $stmt = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
        $stmt->bind_param("ii", $produto_id, $local_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row['quantidade']) ? (float)$row['quantidade'] : 0.0;
    } else {
        // Produto é kit/virtual: calcular por componentes diretos
        $stmt = $conn->prepare("SELECT pr.subproduto_id, pr.quantidade as qtd_por_unidade
                                FROM produto_relacionamento pr
                                WHERE pr.produto_principal_id = ?");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        $min_possible = null;
        while ($comp = $res->fetch_assoc()) {
            $sub_id = (int)$comp['subproduto_id'];
            $qtd_por_unidade = (float)$comp['qtd_por_unidade'];
            if ($qtd_por_unidade <= 0) {
                // evita divisão por zero: considera indisponível
                $min_possible = 0.0;
                break;
            }
            // pega estoque do componente no local
            $stmt2 = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
            $stmt2->bind_param("ii", $sub_id, $local_id);
            $stmt2->execute();
            $r2 = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            $estoque_comp = isset($r2['quantidade']) ? (float)$r2['quantidade'] : 0.0;

            // quantas unidades do kit esse componente suporta
            $possible = $estoque_comp / $qtd_por_unidade;
            if ($min_possible === null || $possible < $min_possible) $min_possible = $possible;
        }

        return ($min_possible === null) ? 0.0 : (float)$min_possible;
    }
}

// --- HANDLER AJAX: consulta saldo via GET simples ---
// Ex.: solicitar.php?acao=saldo&produto_id=5&local_id=10
if (isset($_GET['acao']) && $_GET['acao'] === 'saldo') {
    header('Content-Type: application/json; charset=utf-8');
    $produto_q = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    $local_q = isset($_GET['local_id']) ? (int)$_GET['local_id'] : 0;
    if ($produto_q <= 0 || $local_q <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Produto ou local inválido', 'saldo' => 0]);
        exit;
    }
    $saldo = getSaldoDisponivel($conn, $produto_q, $local_q);
    echo json_encode(['sucesso' => true, 'saldo' => (float)$saldo]);
    exit;
}

// 1. CARREGAR LOCAIS: origem filtrada para admin_unidade; destino mostra todos (para permitir solicitações entre unidades)
$locais_origem = getLocaisFormatados($conn, true, $filtro_unidade); // apenas salas da unidade (se for admin_unidade) ou todos se null
$locais_destino = getLocaisFormatados($conn, true, null); // todos as salas para escolher destino

// 2. CARREGAR PRODUTOS (Sem filtro por enquanto, solicitação pode ser global)
$produtos = [];
$res_prod = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
while ($row = $res_prod->fetch_assoc()) $produtos[] = $row;

// 3. PROCESSAR ENVIO DE FORMULÁRIO
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'solicitar') {
    $produto_id = (int)$_POST['produto_id'];
    $quantidade = (float)$_POST['quantidade'];
    $origem_id = (int)$_POST['local_origem_id'];
    $destino_id = (int)$_POST['local_destino_id'];
    
    // Validar campos básicos
    if ($produto_id <= 0 || $quantidade <= 0 || $origem_id <= 0 || $destino_id <= 0) {
        $status_message = "<div class='alert error'>Preencha todos os campos corretamente.</div>";
    } else {
        // Se for admin_unidade, valida que a origem pertence à sua unidade.
        if ($filtro_unidade) {
            $ids_perm = getIdsLocaisDaUnidade($conn, $filtro_unidade);
            if (!in_array($origem_id, $ids_perm)) {
                $status_message = "<div class='alert error'>Origem inválida: você só pode solicitar retiradas de locais da sua unidade.</div>";
            }
            // Destino pode ser fora da unidade (cross-unit request) — será autorizado por admin geral e recebido pelo admin da unidade destino.
        }

        if (empty($status_message)) {
            if ($origem_id == $destino_id) {
                $status_message = "<div class='alert error'>Origem e destino iguais.</div>";
            } else {
                // Verificar saldo disponível no local de origem antes de criar a movimentação
                $saldo_disp = getSaldoDisponivel($conn, $produto_id, $origem_id);
                // Considera pequena tolerância para floats
                if ($quantidade > $saldo_disp + 0.00001) {
                    $status_message = "<div class='alert error'>Estoque insuficiente. Saldo disponível no local de origem: " . number_format($saldo_disp, 4, ',', '.') . "</div>";
                } else {
                    // Inserir movimentação como PENDENTE
                    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, quantidade, local_origem_id, local_destino_id, usuario_id, status, tipo_movimentacao) VALUES (?, ?, ?, ?, ?, 'pendente', 'TRANSFERENCIA')");
                    $stmt->bind_param("idiii", $produto_id, $quantidade, $origem_id, $destino_id, $usuario_id);
                    if ($stmt->execute()) {
                        $status_message = "<div class='alert success'>Solicitação enviada com sucesso!</div>";
                        if(function_exists('registrarLog')) registrarLog($conn, $usuario_id, 'movimentacoes', $stmt->insert_id, 'SOLICITACAO', "Qtd: $quantidade", $produto_id);
                    } else {
                        $status_message = "<div class='alert error'>Erro: " . htmlspecialchars($conn->error) . "</div>";
                    }
                    $stmt->close();
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
        .container { max-width: 600px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #ddd; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
        button { width: 100%; padding: 12px; background: #28a745; color: white; border: none; cursor: pointer; border-radius: 4px; }
        .small-note { font-size: 0.9em; color: #666; margin-top:6px; display:block; }
        #saldo-origem { font-weight: bold; margin-left: 6px; color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Nova Solicitação</h1>
        <?php echo $status_message; ?>
        <form method="POST" id="form-solicitar">
            <input type="hidden" name="acao" value="solicitar">
            
            <div class="form-group">
                <label>Produto</label>
                <select name="produto_id" id="produto_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($produtos as $p) echo "<option value='{$p['id']}'>".htmlspecialchars($p['nome'])."</option>"; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Quantidade</label>
                <input type="number" name="quantidade" id="quantidade" min="0.01" step="any" value="1" required>
            </div>
            
            <div class="form-group">
                <label>Origem</label>
                <select name="local_origem_id" id="local_origem_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($locais_origem as $id => $nome) echo "<option value='$id'>".htmlspecialchars($nome)."</option>"; ?>
                </select>
                <span class="small-note">Saldo disponível neste local: <span id="saldo-origem">-</span></span>
                <?php if ($filtro_unidade): ?>
                    <small style="color:#666">Você só pode escolher locais da sua unidade como origem.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label>Destino</label>
                <select name="local_destino_id" id="local_destino_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($locais_destino as $id => $nome) echo "<option value='$id'>".htmlspecialchars($nome)."</option>"; ?>
                </select>
                <?php if ($filtro_unidade): ?>
                    <small style="color:#666">Destino pode ser na sua unidade ou em outra unidade.</small>
                <?php endif; ?>
            </div>
            
            <button type="submit">Enviar</button>
        </form>
        <p style="text-align:center"><a href="listar.php">Voltar</a></p>
    </div>

<script>
// JS para: 1) buscar saldo via GET e exibir; 2) remover origem das opções de destino para evitar mesmo local; 3) validação cliente opcional

const produtoSel = document.getElementById('produto_id');
const origemSel = document.getElementById('local_origem_id');
const destinoSel = document.getElementById('local_destino_id');
const saldoSpan = document.getElementById('saldo-origem');

function atualizarDestinoExclusao() {
    const origemVal = origemSel.value;
    // reabilita todas as opções primeiro
    for (const opt of destinoSel.options) {
        opt.disabled = false;
        opt.style.display = '';
    }
    if (origemVal) {
        // desabilita / oculta a opção do mesmo local
        const opt = destinoSel.querySelector('option[value="' + origemVal + '"]');
        if (opt) {
            opt.disabled = true;
            opt.style.display = 'none';
            // se estava selecionado, limpa seleção
            if (destinoSel.value === origemVal) destinoSel.value = '';
        }
    }
}

async function buscarSaldo() {
    const prod = produtoSel.value;
    const loc = origemSel.value;
    saldoSpan.textContent = '-';
    if (!prod || !loc) return;
    try {
        const res = await fetch(`?acao=saldo&produto_id=${encodeURIComponent(prod)}&local_id=${encodeURIComponent(loc)}`);
        const data = await res.json();
        if (data && data.sucesso) {
            // formata exibindo com vírgula e até 4 casas (br)
            const val = Number(data.saldo);
            saldoSpan.textContent = val.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
        } else {
            saldoSpan.textContent = '0';
        }
    } catch (e) {
        console.error(e);
        saldoSpan.textContent = '-';
    }
}

// Event listeners
produtoSel.addEventListener('change', function() {
    // quando trocar produto, atualizar saldo se origem já selecionada
    if (origemSel.value) buscarSaldo();
});
origemSel.addEventListener('change', function() {
    atualizarDestinoExclusao();
    buscarSaldo();
});

// Inicializa ao carregar
document.addEventListener('DOMContentLoaded', function() {
    atualizarDestinoExclusao();
    // se já houver valores pré-selecionados (após submit com erro), tenta buscar saldo
    if (produtoSel.value && origemSel.value) buscarSaldo();
});

// Adicional: checagem cliente antes do envio para evitar round-trip quando o saldo já é insuficiente
document.getElementById('form-solicitar').addEventListener('submit', async function(e) {
    const prod = produtoSel.value;
    const loc = origemSel.value;
    const qtd = parseFloat(document.getElementById('quantidade').value) || 0;
    if (prod && loc && qtd > 0) {
        // busca saldo atual
        try {
            const res = await fetch(`?acao=saldo&produto_id=${encodeURIComponent(prod)}&local_id=${encodeURIComponent(loc)}`);
            const data = await res.json();
            const saldo = (data && data.sucesso) ? Number(data.saldo) : 0;
            if (qtd > saldo + 0.00001) {
                e.preventDefault();
                alert('Estoque insuficiente. Saldo disponível: ' + saldo.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 4 }));
                return false;
            }
        } catch (err) {
            // se falhar a checagem, deixamos o submit seguir para validação server-side
            console.error('Erro ao verificar saldo (cliente):', err);
        }
    }
});
</script>
</body>
</html>