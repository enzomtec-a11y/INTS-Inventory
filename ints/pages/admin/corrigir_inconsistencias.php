<?php
// pages/admin/corrigir_inconsistencias.php
require_once '../../config/_protecao.php';
exigirAdminGeral();

$usuario_id_log = getUsuarioId();
$msg      = '';
$msgClass = '';

// -------------------------------------------------------
// PROCESSAR AÇÃO (POST)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao       = $_POST['acao']       ?? '';
    $produto_id = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;
    $ids_bulk   = $_POST['ids_bulk']   ?? []; // array de IDs para ação em massa

    // Normaliza lista de IDs
    $ids_para_processar = [];
    if ($produto_id > 0) {
        $ids_para_processar = [$produto_id];
    } elseif (!empty($ids_bulk) && is_array($ids_bulk)) {
        $ids_para_processar = array_map('intval', $ids_bulk);
        $ids_para_processar = array_filter($ids_para_processar, fn($v) => $v > 0);
    }

    $acoes_validas = ['marcar_inativo', 'marcar_baixa_total', 'forcar_zeramento'];
    if (!in_array($acao, $acoes_validas) || empty($ids_para_processar)) {
        $msg      = 'Ação inválida ou nenhum produto selecionado.';
        $msgClass = 'msg-error';
    } else {
        $conn->begin_transaction();
        try {
            $total_afetados = 0;
            foreach ($ids_para_processar as $pid) {

                // Verificar se o produto ainda existe e está ativo (segurança)
                $stmt_chk = $conn->prepare("SELECT id, nome FROM produtos WHERE id = ? AND deletado = FALSE AND status_produto = 'ativo'");
                $stmt_chk->bind_param("i", $pid);
                $stmt_chk->execute();
                $prod_row = $stmt_chk->get_result()->fetch_assoc();
                $stmt_chk->close();

                if (!$prod_row) continue; // Já foi tratado ou não existe

                if ($acao === 'marcar_inativo') {
                    // Marca como inativo
                    $stmt_up = $conn->prepare("UPDATE produtos SET status_produto = 'inativo' WHERE id = ?");
                    $stmt_up->bind_param("i", $pid);
                    $stmt_up->execute();
                    $stmt_up->close();

                    registrarLog($conn, $usuario_id_log, 'produtos', $pid, 'CORRECAO_INCONSISTENCIA',
                        "Status alterado para 'inativo' via correção de inconsistências.", $pid);
                    $total_afetados++;

                } elseif ($acao === 'marcar_baixa_total') {
                    // Marca como baixa_total e zera estoque
                    $stmt_up = $conn->prepare("UPDATE produtos SET status_produto = 'baixa_total' WHERE id = ?");
                    $stmt_up->bind_param("i", $pid);
                    $stmt_up->execute();
                    $stmt_up->close();

                    // Zera qualquer resíduo de estoque
                    $stmt_est = $conn->prepare("UPDATE estoques SET quantidade = 0 WHERE produto_id = ?");
                    $stmt_est->bind_param("i", $pid);
                    $stmt_est->execute();
                    $stmt_est->close();

                    registrarLog($conn, $usuario_id_log, 'produtos', $pid, 'CORRECAO_INCONSISTENCIA',
                        "Status alterado para 'baixa_total' e estoque zerado via correção de inconsistências.", $pid);
                    $total_afetados++;

                } elseif ($acao === 'forcar_zeramento') {
                    // Apenas zera estoque residual (produto continua ativo mas com estoque = 0)
                    // Isso não resolve a inconsistência, mas limpa dados sujos
                    $stmt_est = $conn->prepare("UPDATE estoques SET quantidade = 0 WHERE produto_id = ?");
                    $stmt_est->bind_param("i", $pid);
                    $stmt_est->execute();
                    $stmt_est->close();

                    registrarLog($conn, $usuario_id_log, 'produtos', $pid, 'CORRECAO_INCONSISTENCIA',
                        "Estoque zerado manualmente via correção de inconsistências.", $pid);
                    $total_afetados++;
                }
            }

            $conn->commit();
            $label_acao = [
                'marcar_inativo'    => 'marcados como Inativo',
                'marcar_baixa_total'=> 'marcados como Baixa Total',
                'forcar_zeramento'  => 'com estoque zerado',
            ];
            $msg      = "✅ {$total_afetados} produto(s) {$label_acao[$acao]} com sucesso.";
            $msgClass = 'msg-success';

        } catch (Exception $e) {
            $conn->rollback();
            $msg      = '❌ Erro ao processar: ' . $e->getMessage();
            $msgClass = 'msg-error';
        }
    }
}

// -------------------------------------------------------
// BUSCAR PRODUTOS INCONSISTENTES
// Query: status='ativo', controla_estoque_proprio=1, não deletado,
//        sem estoque E sem patrimônio ativo
// -------------------------------------------------------
$sql_incons = "
    SELECT
        p.id,
        p.nome,
        p.numero_patrimonio,
        p.data_criado,
        p.data_atualizado,
        c.nome AS categoria_nome,
        COALESCE(SUM(e.quantidade), 0)           AS total_estoque,
        COUNT(DISTINCT pt.id)                    AS total_patrimonios_ativos,
        (SELECT COUNT(*) FROM movimentacoes m
            WHERE m.produto_id = p.id
            AND m.status NOT IN ('cancelado','finalizado')
        )                                        AS mov_pendentes,
        (SELECT MAX(m2.data_movimentacao) FROM movimentacoes m2
            WHERE m2.produto_id = p.id
        )                                        AS ultima_movimentacao
    FROM produtos p
    LEFT JOIN categorias c       ON c.id = p.categoria_id
    LEFT JOIN estoques e         ON e.produto_id = p.id
    LEFT JOIN patrimonios pt     ON pt.produto_id = p.id AND pt.status != 'desativado'
    WHERE p.deletado = FALSE
      AND p.status_produto = 'ativo'
      AND p.controla_estoque_proprio = 1
    GROUP BY p.id, p.nome, p.numero_patrimonio, p.data_criado, p.data_atualizado, c.nome
    HAVING total_estoque = 0 AND total_patrimonios_ativos = 0
    ORDER BY p.data_atualizado DESC
";

$res_incons   = $conn->query($sql_incons);
$inconsistentes = [];
if ($res_incons) {
    while ($row = $res_incons->fetch_assoc()) {
        $inconsistentes[] = $row;
    }
}
$total = count($inconsistentes);

$is_modal = isset($_GET['modal']) || isset($_POST['modal']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Inconsistências de Estoque</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f9;
            color: #2d3748;
            padding: <?php echo $is_modal ? '20px' : '30px'; ?>;
        }

        .page-header {
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header p {
            margin-top: 6px;
            color: #718096;
            font-size: 0.9rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            font-size: 0.8rem;
            color: #a0aec0;
            margin-bottom: 18px;
        }
        .breadcrumb a { color: #4a90d9; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        /* Msg */
        .msg-success, .msg-error, .msg-info {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            font-weight: 500;
        }
        .msg-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .msg-error   { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }
        .msg-info    { background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd; }

        /* Summary card */
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .summary-icon {
            font-size: 2.5rem;
            flex-shrink: 0;
        }
        .summary-text h2 {
            font-size: 1.1rem;
            color: #2d3748;
        }
        .summary-text p {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 4px;
            line-height: 1.5;
        }
        .summary-count {
            margin-left: auto;
            text-align: center;
            flex-shrink: 0;
        }
        .summary-count .num {
            font-size: 2.5rem;
            font-weight: 800;
            color: #e53e3e;
            line-height: 1;
        }
        .summary-count .label {
            font-size: 0.75rem;
            color: #a0aec0;
            margin-top: 2px;
        }

        /* Bulk toolbar */
        .toolbar {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .toolbar label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #4a5568;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .toolbar-sep { width: 1px; height: 28px; background: #e2e8f0; margin: 0 4px; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 7px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity .15s;
            text-decoration: none;
        }
        .btn:hover { opacity: .85; }
        .btn:disabled { opacity: .4; cursor: not-allowed; }

        .btn-danger  { background: #e53e3e; color: #fff; }
        .btn-warning { background: #d97706; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-info    { background: #3182ce; color: #fff; }
        .btn-sm { padding: 5px 10px; font-size: 0.78rem; }

        /* Table */
        .table-wrap {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        thead th {
            background: #f7fafc;
            color: #718096;
            font-weight: 600;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid #f0f4f8;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f7fafc; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info    { background: #dbeafe; color: #1e40af; }
        .badge-gray    { background: #f1f5f9; color: #64748b; }

        .nome-prod { font-weight: 600; color: #2d3748; }
        .patr-num  { font-size: 0.75rem; color: #a0aec0; margin-top: 2px; }

        .actions-cell { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #a0aec0;
        }
        .empty-state .icon { font-size: 3rem; margin-bottom: 12px; }
        .empty-state h3 { font-size: 1.1rem; color: #4a5568; margin-bottom: 6px; }
        .empty-state p { font-size: 0.85rem; }

        /* Modal de confirmação */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
        }
        .modal-box h3 { font-size: 1.05rem; margin-bottom: 10px; color: #1a202c; }
        .modal-box p  { font-size: 0.88rem; color: #4a5568; margin-bottom: 20px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

        @media (max-width: 768px) {
            .toolbar { flex-direction: column; align-items: flex-start; }
            .actions-cell { flex-direction: column; }
            .summary-card { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<?php if (!$is_modal): ?>
<div class="breadcrumb">
    <a href="../../index.php">🏠 Home</a> › <a href="index.php">Administração</a> › Corrigir Inconsistências
</div>
<?php endif; ?>

<div class="page-header">
    <h1>⚠️ Corrigir Inconsistências de Estoque</h1>
    <p>Produtos com status <strong>Ativo</strong> mas sem estoque registrado e sem patrimônio ativo.</p>
</div>

<?php if ($msg): ?>
    <div class="<?php echo $msgClass; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- Summary -->
<div class="summary-card">
    <div class="summary-icon">
        <?php echo $total > 0 ? '⚠️' : '✅'; ?>
    </div>
    <div class="summary-text">
        <h2><?php echo $total > 0 ? 'Inconsistências encontradas' : 'Tudo certo!'; ?></h2>
        <p>
            <?php if ($total > 0): ?>
                Estes produtos estão marcados como <strong>Ativo</strong> no sistema, porém não possuem
                estoque disponível nem patrimônio ativo associado. É necessário corrigir o status
                para refletir a situação real do inventário.
            <?php else: ?>
                Nenhuma inconsistência encontrada. Todos os produtos ativos possuem estoque ou patrimônio registrado.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($total > 0): ?>
    <div class="summary-count">
        <div class="num"><?php echo $total; ?></div>
        <div class="label">produto(s)<br>inconsistentes</div>
    </div>
    <?php endif; ?>
</div>

<?php if ($total > 0): ?>

<!-- Toolbar de ações em massa -->
<form method="POST" id="form-bulk">
    <?php if ($is_modal): ?><input type="hidden" name="modal" value="1"><?php endif; ?>

    <div class="toolbar">
        <label>
            <input type="checkbox" id="check-all" onchange="toggleAll(this)">
            Selecionar todos (<?php echo $total; ?>)
        </label>
        <div class="toolbar-sep"></div>
        <span style="font-size:.83rem; color:#718096; font-weight:500;">Ação em massa para selecionados:</span>
        <button type="button" class="btn btn-warning btn-sm"
            onclick="confirmarBulk('marcar_inativo', 'Marcar como Inativo')">
            🔕 Marcar como Inativo
        </button>
        <button type="button" class="btn btn-danger btn-sm"
            onclick="confirmarBulk('marcar_baixa_total', 'Marcar como Baixa Total')">
            🗑️ Marcar como Baixa Total
        </button>
    </div>

    <!-- Tabela -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th>Estoque Total</th>
                    <th>Patrimônios Ativos</th>
                    <th>Movimentações Abertas</th>
                    <th>Última Movimentação</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inconsistentes as $p): ?>
                <tr>
                    <td>
                        <input type="checkbox" name="ids_bulk[]"
                            value="<?php echo $p['id']; ?>"
                            class="chk-item">
                    </td>
                    <td>
                        <div class="nome-prod">
                            <?php echo htmlspecialchars($p['nome']); ?>
                        </div>
                        <?php if ($p['numero_patrimonio']): ?>
                            <div class="patr-num">🏷️ <?php echo htmlspecialchars($p['numero_patrimonio']); ?></div>
                        <?php endif; ?>
                        <div class="patr-num">ID: <?php echo $p['id']; ?></div>
                    </td>
                    <td>
                        <?php if ($p['categoria_nome']): ?>
                            <span class="badge badge-gray"><?php echo htmlspecialchars($p['categoria_nome']); ?></span>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-danger">0 un.</span>
                    </td>
                    <td>
                        <span class="badge badge-danger">0</span>
                    </td>
                    <td>
                        <?php if ($p['mov_pendentes'] > 0): ?>
                            <span class="badge badge-warning" title="Existem movimentações abertas para este produto">
                                ⚠️ <?php echo $p['mov_pendentes']; ?> aberta(s)
                            </span>
                        <?php else: ?>
                            <span class="badge badge-gray">Nenhuma</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#718096; font-size:.8rem;">
                        <?php
                        if ($p['ultima_movimentacao']) {
                            $dt = new DateTime($p['ultima_movimentacao']);
                            echo $dt->format('d/m/Y H:i');
                        } else {
                            echo '<span style="color:#ccc;">—</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <div class="actions-cell">
                            <a href="../../pages/produtos/detalhes.php?id=<?php echo $p['id']; ?>"
                               target="_blank"
                               class="btn btn-info btn-sm" title="Ver ficha do produto">
                                🔗 Ver
                            </a>
                            <button type="button" class="btn btn-warning btn-sm"
                                onclick="confirmarSingle(<?php echo $p['id']; ?>, 'marcar_inativo', '<?php echo htmlspecialchars(addslashes($p['nome'])); ?>')">
                                🔕 Inativo
                            </button>
                            <button type="button" class="btn btn-danger btn-sm"
                                onclick="confirmarSingle(<?php echo $p['id']; ?>, 'marcar_baixa_total', '<?php echo htmlspecialchars(addslashes($p['nome'])); ?>')">
                                🗑️ Baixa Total
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Hidden inputs para ação em massa -->
    <input type="hidden" name="acao" id="input-acao" value="">

</form>

<?php else: ?>

<div class="empty-state">
    <div class="icon">✅</div>
    <h3>Nenhuma inconsistência encontrada</h3>
    <p>Todos os produtos ativos estão com estoque ou patrimônio registrado corretamente.</p>
</div>

<?php endif; ?>

<!-- Botão voltar (fora do modal) -->
<?php if (!$is_modal): ?>
<div style="margin-top: 24px;">
    <a href="index.php" class="btn btn-secondary">← Voltar para Administração</a>
</div>
<?php endif; ?>

<!-- -------------------------------------------------------
     Modal de Confirmação
------------------------------------------------------- -->
<div class="modal-overlay" id="modalConfirm">
    <div class="modal-box">
        <h3 id="modalTitle">Confirmar ação</h3>
        <p id="modalDesc">Tem certeza que deseja continuar?</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
            <button class="btn btn-danger" id="modalConfirmBtn" onclick="executarAcao()">Confirmar</button>
        </div>
    </div>
</div>

<script>
// Estado da ação a ser executada
let acaoPendente     = '';
let produtoIdPendente = 0;
let isBulk           = false;

function confirmarSingle(produtoId, acao, nomeProduto) {
    const labels = {
        'marcar_inativo':     'Marcar como Inativo',
        'marcar_baixa_total': 'Marcar como Baixa Total',
        'forcar_zeramento':   'Zerar Estoque',
    };
    const descs = {
        'marcar_inativo':     `O produto "<strong>${nomeProduto}</strong>" será marcado como <strong>Inativo</strong>. Ele continuará no sistema mas não aparecerá como ativo.`,
        'marcar_baixa_total': `O produto "<strong>${nomeProduto}</strong>" será marcado como <strong>Baixa Total</strong> e o estoque será zerado. Esta ação indica que o item não existe mais no inventário.`,
        'forcar_zeramento':   `O estoque do produto "<strong>${nomeProduto}</strong>" será zerado. O status permanecerá como ativo.`,
    };

    acaoPendente      = acao;
    produtoIdPendente = produtoId;
    isBulk            = false;

    document.getElementById('modalTitle').innerText  = labels[acao] ?? 'Confirmar';
    document.getElementById('modalDesc').innerHTML   = descs[acao]  ?? 'Confirme a ação.';
    document.getElementById('modalConfirmBtn').className = acao === 'marcar_baixa_total'
        ? 'btn btn-danger' : 'btn btn-warning';
    document.getElementById('modalConfirmBtn').textContent = 'Confirmar';

    document.getElementById('modalConfirm').classList.add('active');
}

function confirmarBulk(acao, label) {
    const selecionados = document.querySelectorAll('.chk-item:checked');
    if (selecionados.length === 0) {
        alert('Selecione ao menos um produto para aplicar a ação em massa.');
        return;
    }

    acaoPendente      = acao;
    produtoIdPendente = 0;
    isBulk            = true;

    document.getElementById('modalTitle').innerText  = label + ' em massa';
    document.getElementById('modalDesc').innerHTML   =
        `Você está prestes a aplicar "<strong>${label}</strong>" em <strong>${selecionados.length}</strong> produto(s) selecionado(s). Deseja continuar?`;
    document.getElementById('modalConfirmBtn').className = acao === 'marcar_baixa_total'
        ? 'btn btn-danger' : 'btn btn-warning';
    document.getElementById('modalConfirmBtn').textContent = 'Confirmar';

    document.getElementById('modalConfirm').classList.add('active');
}

function fecharModal() {
    document.getElementById('modalConfirm').classList.remove('active');
    acaoPendente      = '';
    produtoIdPendente = 0;
    isBulk            = false;
}

function executarAcao() {
    const form = document.getElementById('form-bulk');
    document.getElementById('input-acao').value = acaoPendente;

    if (!isBulk) {
        // Ação individual: adicionar input hidden com produto_id
        let existing = form.querySelector('#hidden-produto-id');
        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'hidden';
            existing.id   = 'hidden-produto-id';
            existing.name = 'produto_id';
            form.appendChild(existing);
        }
        existing.value = produtoIdPendente;

        // Desmarcar checkboxes para não enviar ids_bulk
        document.querySelectorAll('.chk-item').forEach(c => c.checked = false);
    } else {
        // Ação em massa: remover hidden individual se existir
        const existing = form.querySelector('#hidden-produto-id');
        if (existing) existing.remove();
    }

    form.submit();
}

function toggleAll(masterChk) {
    document.querySelectorAll('.chk-item').forEach(c => c.checked = masterChk.checked);
}

// Fechar modal ao clicar fora
document.getElementById('modalConfirm').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});
</script>

</body>
</html>