<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id    = getUsuarioId();
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id    = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;

$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id : null;
$ids_permitidos = [];
if ($filtro_unidade) {
    $ids_permitidos = getIdsLocaisDaUnidade($conn, $filtro_unidade);
}

// ══════════════════════════════════════════════════════════════════
// HELPERS — declarados ANTES de qualquer bloco que os use
// ══════════════════════════════════════════════════════════════════
if (!function_exists('getUnidadeDoLocal')) {
    function getUnidadeDoLocal($conn, $local_id) {
        $cur = (int)$local_id;
        while ($cur > 0) {
            $stmt = $conn->prepare("SELECT id, tipo_local, local_pai_id FROM locais WHERE id = ? AND deletado = FALSE LIMIT 1");
            $stmt->bind_param("i", $cur);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) break;
            if ($row['tipo_local'] === 'unidade') return (int)$row['id'];
            if (empty($row['local_pai_id'])) break;
            $cur = (int)$row['local_pai_id'];
        }
        return null;
    }
}

// ══════════════════════════════════════════════════════════════════
// AJAX — locais com estoque do produto (origem)
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['acao']) && $_GET['acao'] === 'locais_produto') {
    header('Content-Type: application/json; charset=utf-8');
    $produto_id_q = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    if ($produto_id_q <= 0) { echo json_encode(['sucesso' => false, 'locais' => []]); exit; }

    $sql = "
        SELECT e.local_id, COALESCE(l.nome, 'Sem local') AS local_nome, e.quantidade
        FROM estoques e
        LEFT JOIN locais l ON e.local_id = l.id
        WHERE e.produto_id = ? AND e.quantidade > 0
    ";
    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $sql .= " AND e.local_id IN ($idsStr)";
    }
    $sql .= " ORDER BY e.quantidade DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $produto_id_q);
    $stmt->execute();
    $out = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = ['local_id' => (int)$r['local_id'], 'local_nome' => $r['local_nome'], 'quantidade' => (float)$r['quantidade']];
    }
    $stmt->close();
    echo json_encode(['sucesso' => true, 'locais' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// AJAX — detalhes do produto para o painel lateral
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['acao']) && $_GET['acao'] === 'detalhes_produto') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    if ($pid <= 0) { echo json_encode(['sucesso' => false]); exit; }

    $stmt = $conn->prepare("
        SELECT p.id, p.nome, p.descricao, p.numero_patrimonio, p.tipo_posse, p.status_produto,
               c.nome AS categoria_nome
        FROM produtos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        WHERE p.id = ? AND p.deletado = FALSE
    ");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$prod) { echo json_encode(['sucesso' => false]); exit; }

    // Estoque por local
    $sql_est = "
        SELECT l.nome AS local_nome, e.quantidade
        FROM estoques e
        JOIN locais l ON e.local_id = l.id
        WHERE e.produto_id = ? AND e.quantidade > 0
    ";
    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $sql_est .= " AND e.local_id IN ($idsStr)";
    }
    $sql_est .= " ORDER BY e.quantidade DESC";
    $stmt2 = $conn->prepare($sql_est);
    $stmt2->bind_param("i", $pid);
    $stmt2->execute();
    $estoques = [];
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) $estoques[] = $r;
    $stmt2->close();

    // Imagem
    $stmt3 = $conn->prepare("SELECT caminho FROM arquivos WHERE produto_id = ? AND tipo = 'imagem' LIMIT 1");
    $stmt3->bind_param("i", $pid);
    $stmt3->execute();
    $img = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();

    // Atributos EAV
    $stmt4 = $conn->prepare("
        SELECT ad.nome, ad.tipo,
               COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) AS valor
        FROM atributos_valor av
        JOIN atributos_definicao ad ON av.atributo_id = ad.id
        WHERE av.produto_id = ?
    ");
    $stmt4->bind_param("i", $pid);
    $stmt4->execute();
    $attrs = [];
    $res4 = $stmt4->get_result();
    while ($r = $res4->fetch_assoc()) {
        if ($r['tipo'] === 'booleano') $r['valor'] = ($r['valor'] == '1') ? 'Sim' : 'Não';
        $attrs[] = $r;
    }
    $stmt4->close();

    echo json_encode([
        'sucesso'   => true,
        'produto'   => $prod,
        'estoques'  => $estoques,
        'imagem'    => $img['caminho'] ?? null,
        'atributos' => $attrs,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// LISTA DE PRODUTOS COM ESTOQUE
// ══════════════════════════════════════════════════════════════════
$produtos = [];
if ($filtro_unidade && !empty($ids_permitidos)) {
    $idsStr = implode(',', array_map('intval', $ids_permitidos));
    $sql_prod = "
        SELECT DISTINCT p.id AS produto_id, p.nome
        FROM produtos p
        WHERE p.deletado = FALSE
          AND EXISTS (
              SELECT 1 FROM estoques e
              WHERE e.produto_id = p.id AND e.quantidade > 0 AND e.local_id IN ($idsStr)
          )
        ORDER BY p.nome
    ";
} else {
    $sql_prod = "
        SELECT DISTINCT p.id AS produto_id, p.nome
        FROM produtos p
        WHERE p.deletado = FALSE
          AND EXISTS (SELECT 1 FROM estoques e WHERE e.produto_id = p.id AND e.quantidade > 0)
        ORDER BY p.nome
    ";
}
$resp = $conn->query($sql_prod);
if ($resp) while ($r = $resp->fetch_assoc()) $produtos[] = $r;

// ══════════════════════════════════════════════════════════════════
// LISTA DE UNIDADES DESTINO
// ══════════════════════════════════════════════════════════════════
$unidades_destino = [];
$res_unid = $conn->query("SELECT id, nome FROM locais WHERE tipo_local = 'unidade' AND deletado = FALSE ORDER BY nome");
if ($res_unid) while ($r = $res_unid->fetch_assoc()) $unidades_destino[] = $r;

// ══════════════════════════════════════════════════════════════════
// PROCESSAR SUBMIT
// ══════════════════════════════════════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['acao'] ?? '') === 'solicitar') {

    $produto_id      = isset($_POST['produto_id'])         ? (int)$_POST['produto_id']         : 0;
    $origem_id       = isset($_POST['local_origem_id'])    ? (int)$_POST['local_origem_id']    : 0;
    $unidade_destino = isset($_POST['unidade_destino_id']) ? (int)$_POST['unidade_destino_id'] : 0;
    $quantidade      = 1;

    if ($produto_id <= 0 || $origem_id <= 0 || $unidade_destino <= 0) {
        $status_message = "<div class='alert error'>Selecione o produto, a origem e a unidade de destino.</div>";
    } else {
        $stmt_chk = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
        $stmt_chk->bind_param("ii", $produto_id, $origem_id);
        $stmt_chk->execute();
        $chk = $stmt_chk->get_result()->fetch_assoc();
        $stmt_chk->close();

        if (!$chk || $chk['quantidade'] <= 0) {
            $status_message = "<div class='alert error'>Produto sem estoque neste local de origem.</div>";
        } else {
            $unidade_origem = getUnidadeDoLocal($conn, $origem_id);

            if ($unidade_origem && $unidade_origem == $unidade_destino) {
                $status_message = "<div class='alert error'>A unidade de destino deve ser diferente da unidade de origem.</div>";
            } elseif ($filtro_unidade && !empty($ids_permitidos) && !in_array($origem_id, $ids_permitidos)) {
                $status_message = "<div class='alert error'>Você não tem permissão sobre o local de origem selecionado.</div>";
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO movimentacoes
                            (produto_id, quantidade, local_origem_id, local_destino_id, unidade_destino_id, usuario_id, status, tipo_movimentacao)
                        VALUES (?, ?, ?, NULL, ?, ?, 'pendente', 'TRANSFERENCIA')
                    ");
                    $stmt->bind_param("iiiii", $produto_id, $quantidade, $origem_id, $unidade_destino, $usuario_id);

                    if ($stmt->execute()) {
                        $mov_id = $stmt->insert_id;
                        $conn->commit();
                        if (function_exists('registrarLog')) {
                            registrarLog($conn, $usuario_id, 'movimentacoes', $mov_id, 'SOLICITACAO',
                                "Solicitação para unidade #$unidade_destino.", $produto_id);
                        }
                        $status_message = "<div class='alert success'>✅ Solicitação enviada! Aguarde a aprovação pelo administrador.</div>";
                    } else {
                        throw new Exception("Erro ao salvar: " . $stmt->error);
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
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
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 0; }

        .layout {
            display: flex;
            gap: 24px;
            padding: 32px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: flex-start;
        }

        /* Coluna formulário */
        .form-col {
            flex: 1;
            min-width: 0;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.09);
            padding: 28px 32px;
        }

        /* Coluna detalhe */
        .detail-col { width: 320px; flex-shrink: 0; }

        .detail-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.09);
            overflow: hidden;
            display: none;
            transition: all .3s;
        }
        .detail-card.visible { display: block; }

        .dc-img {
            width: 100%; height: 170px;
            background: #eef0f5;
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: #bbb;
            overflow: hidden;
        }
        .dc-img img { width: 100%; height: 100%; object-fit: cover; }

        .dc-body { padding: 14px 16px; }
        .dc-title { font-size: 0.98rem; font-weight: 700; color: #2c3e50; margin: 0 0 3px; }
        .dc-cat   { font-size: 0.76em; color: #999; margin: 0 0 10px; }

        .dc-section { margin-bottom: 12px; }
        .dc-section h4 {
            font-size: 0.73em;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #bbb;
            margin: 0 0 5px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 3px;
        }

        .badge-s { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75em; font-weight: 600; }
        .badge-ativo      { background:#d4edda; color:#155724; }
        .badge-inativo    { background:#f8d7da; color:#721c24; }
        .badge-baixa_total{ background:#f8d7da; color:#721c24; }
        .badge-baixa_parcial { background:#fff3cd; color:#856404; }

        .estoque-item {
            display: flex; justify-content: space-between;
            font-size: 0.8em; padding: 4px 0;
            border-bottom: 1px solid #f7f7f7; color: #444;
        }
        .estoque-item:last-child { border-bottom: none; }
        .estoque-qtd { font-weight: 700; color: #007bff; }

        .attr-item {
            display: flex; justify-content: space-between;
            font-size: 0.8em; padding: 3px 0; color: #444;
        }
        .attr-item span:first-child { color: #999; }

        .dc-loading { text-align: center; padding: 28px; color: #bbb; font-size: 0.88em; }

        /* Formulário */
        h1 { font-size: 1.4rem; color: #2c3e50; margin: 0 0 18px; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.93em; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; color: #444; margin-bottom: 6px; font-size: 0.9em; }

        select {
            width: 100%; padding: 10px 12px;
            border: 1px solid #ccc; border-radius: 6px;
            font-size: 0.93em; background: #fafafa;
            transition: border-color .2s;
        }
        select:focus { outline: none; border-color: #007bff; background: #fff; }

        .origem-display {
            padding: 10px 12px;
            background: #eef2ff;
            border: 1px solid #c5cff5;
            border-radius: 6px;
            font-size: 0.9em;
            color: #333;
            min-height: 38px;
            line-height: 1.5;
        }

        .info-box {
            background: #fff8e1; border: 1px solid #ffe082;
            border-radius: 6px; padding: 10px 14px;
            font-size: 0.84em; color: #5d4037;
            margin-bottom: 20px; line-height: 1.6;
        }

        button[type="submit"] {
            width: 100%; padding: 11px;
            background: #007bff; color: #fff;
            border: none; border-radius: 6px;
            font-size: 0.97em; font-weight: 600;
            cursor: pointer; transition: background .2s;
            margin-top: 6px;
        }
        button[type="submit"]:hover { background: #0056b3; }
        .small-note { font-size: 0.77em; color: #aaa; margin-top: 3px; display: block; }

        @media (max-width: 768px) {
            .layout { flex-direction: column; padding: 16px; }
            .detail-col { width: 100%; }
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- ── Formulário ─────────────────────────────────────── -->
    <div class="form-col">
        <h1>🔄 Solicitar Movimentação</h1>

        <?php if ($status_message): echo $status_message; endif; ?>

        <div class="info-box">
            <strong>Como funciona o fluxo:</strong><br>
            1. Você indica o produto e a <strong>unidade de destino</strong>.<br>
            2. Um <strong>administrador</strong> aprova a saída → produto fica <em>Em Trânsito</em>.<br>
            3. O <strong>gestor da unidade destino</strong> confirma a chegada e escolhe a sala exata.
        </div>

        <form method="POST" action="solicitar.php">
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
                <span class="small-note">Apenas produtos com estoque disponível são listados.</span>
            </div>

            <div class="form-group">
                <label>Localização Atual (Origem)</label>
                <div class="origem-display">
                    <span id="origem-text" style="color:#aaa;">— Selecione um produto acima —</span>
                </div>
            </div>

            <div class="form-group">
                <label for="unidade_destino_id">Unidade de Destino <span style="color:red">*</span></label>
                <select name="unidade_destino_id" id="unidade_destino_id" required>
                    <option value="">Selecione a unidade...</option>
                    <?php foreach ($unidades_destino as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>">
                            <?php echo htmlspecialchars($u['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="small-note">A sala específica será escolhida pelo gestor ao confirmar o recebimento.</span>
            </div>

            <button type="submit">📤 Enviar Solicitação</button>
        </form>
    </div>

    <!-- ── Painel de Detalhes do Produto ──────────────────── -->
    <div class="detail-col">
        <div class="detail-card" id="detail-card">
            <div class="dc-loading">Selecione um produto para ver os detalhes.</div>
        </div>
    </div>

</div>

<script>
(function () {
    const produtoSel   = document.getElementById('produto_id');
    const origemText   = document.getElementById('origem-text');
    const origemHidden = document.getElementById('local_origem_id');
    const detailCard   = document.getElementById('detail-card');

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    produtoSel.addEventListener('change', async function () {
        const pid = this.value;

        origemText.textContent = '— Selecione um produto acima —';
        origemText.style.color = '#aaa';
        origemHidden.value = '';
        detailCard.classList.remove('visible');
        detailCard.innerHTML = '<div class="dc-loading">⏳ Carregando...</div>';

        if (!pid) return;

        detailCard.classList.add('visible');

        // Carrega em paralelo
        const [resLocais, resDet] = await Promise.all([
            fetch(`?acao=locais_produto&produto_id=${pid}`).then(r => r.json()).catch(() => null),
            fetch(`?acao=detalhes_produto&produto_id=${pid}`).then(r => r.json()).catch(() => null),
        ]);

        // ── Origem ───────────────────────────────────────────
        if (resLocais && resLocais.sucesso && resLocais.locais.length > 0) {
            const locais   = resLocais.locais; // já ordenado DESC por quantidade
            origemHidden.value = locais[0].local_id;

            if (locais.length === 1) {
                origemText.textContent = locais[0].local_nome + ' (' + locais[0].quantidade + ' un.)';
            } else {
                origemText.innerHTML = locais.map((l, i) =>
                    `<span style="display:block;font-size:${i===0?'0.95':'0.82'}em;color:${i===0?'#333':'#777'};">
                        ${i===0?'📦 ':' ↳ '}${esc(l.local_nome)} (${l.quantidade} un.)
                    </span>`
                ).join('');
            }
            origemText.style.color = '#333';
        } else {
            origemText.textContent = '⚠️ Nenhum estoque encontrado';
            origemText.style.color = '#c0392b';
        }

        // ── Detalhes ─────────────────────────────────────────
        if (!resDet || !resDet.sucesso) {
            detailCard.innerHTML = '<div class="dc-loading">Não foi possível carregar os detalhes.</div>';
            return;
        }

        const p = resDet.produto;

        const statusMap = {
            'ativo':         ['badge-ativo',        'Ativo'],
            'inativo':       ['badge-inativo',       'Inativo'],
            'baixa_total':   ['badge-baixa_total',   'Baixa Total'],
            'baixa_parcial': ['badge-baixa_parcial', 'Baixa Parcial'],
        };
        const [bClass, bLabel] = statusMap[p.status_produto] ?? ['badge-inativo', p.status_produto];

        const estoqueHtml = resDet.estoques.length > 0
            ? resDet.estoques.map(e =>
                `<div class="estoque-item">
                    <span>${esc(e.local_nome)}</span>
                    <span class="estoque-qtd">${e.quantidade} un.</span>
                </div>`).join('')
            : '<p style="color:#bbb;font-size:0.8em;margin:0;">Sem estoque registrado.</p>';

        const attrsHtml = resDet.atributos.length > 0
            ? `<div class="dc-section">
                    <h4>Atributos</h4>
                    ${resDet.atributos.map(a =>
                        `<div class="attr-item">
                            <span>${esc(a.nome)}</span>
                            <span><strong>${esc(a.valor ?? '—')}</strong></span>
                        </div>`).join('')}
               </div>`
            : '';

        const imgHtml = resDet.imagem
            ? `<div class="dc-img"><img src="../../../${esc(resDet.imagem)}" alt=""></div>`
            : `<div class="dc-img">📦</div>`;

        detailCard.innerHTML = `
            ${imgHtml}
            <div class="dc-body">
                <p class="dc-title">${esc(p.nome)}</p>
                <p class="dc-cat">
                    ${esc(p.categoria_nome ?? '—')}
                    &nbsp;<span class="badge-s ${bClass}">${bLabel}</span>
                    &nbsp;<a href="../produtos/detalhes.php?id=${p.id}" target="_blank"
                        style="font-size:0.78em;color:#007bff;text-decoration:none;">🔗 Ficha completa</a>
                </p>

                ${p.numero_patrimonio
                    ? `<p style="font-size:0.78em;color:#888;margin:0 0 8px;">🏷️ Patrimônio: <strong>${esc(p.numero_patrimonio)}</strong></p>`
                    : ''}

                ${p.descricao
                    ? `<div class="dc-section">
                           <h4>Descrição</h4>
                           <p style="font-size:0.8em;color:#555;margin:0;line-height:1.5;">${esc(p.descricao)}</p>
                       </div>`
                    : ''}

                <div class="dc-section">
                    <h4>Estoque por Local</h4>
                    ${estoqueHtml}
                </div>

                ${attrsHtml}
            </div>
        `;
    });
})();
</script>
</body>
</html>