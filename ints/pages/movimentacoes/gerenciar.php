<?php
require_once '../../config/_protecao.php';

$usuario_id_logado  = getUsuarioId();
$nivel_usuario      = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id_sessao  = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;

// Filtro: admin_unidade e gestor só veem movs da sua unidade
$filtro_unidade = in_array($nivel_usuario, ['admin_unidade', 'gestor']) ? $unidade_id_sessao : null;

$ids_permitidos = [];
if ($filtro_unidade) {
    $ids_permitidos = getIdsLocaisDaUnidade($conn, $filtro_unidade);
}

$status_message = "";

// ── HELPERS ───────────────────────────────────────────────────────────────────

function atualizarEstoque($conn, $produto_id, $local_id, $quantidade) {
    if ($local_id <= 0) return false;
    $stmt = $conn->prepare("SELECT id FROM estoques WHERE produto_id = ? AND local_id = ?");
    $stmt->bind_param("ii", $produto_id, $local_id);
    $stmt->execute();
    $existe = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($existe) {
        $stmt = $conn->prepare("UPDATE estoques SET quantidade = quantidade + ?, data_atualizado = NOW() WHERE produto_id = ? AND local_id = ?");
        $stmt->bind_param("dii", $quantidade, $produto_id, $local_id);
    } else {
        if ($quantidade <= 0) return false;
        $stmt = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade, data_atualizado) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iid", $produto_id, $local_id, $quantidade);
    }
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

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

/**
 * ✅ FIX: admin_unidade pode autorizar saídas de movimentações que partem da sua unidade.
 * Admin global pode autorizar qualquer saída.
 */
function podeAutorizarSaida($nivel_usuario, $unidade_id_sessao = null, $unidade_origem_id = null) {
    if ($nivel_usuario === 'admin') return true;
    if ($nivel_usuario === 'admin_unidade' && $unidade_id_sessao && $unidade_origem_id) {
        return (int)$unidade_id_sessao === (int)$unidade_origem_id;
    }
    return false;
}

function podeConfirmarChegada($nivel_usuario, $unidade_id_sessao, $unidade_destino_id) {
    if ($nivel_usuario === 'admin') return true;
    if (in_array($nivel_usuario, ['admin_unidade', 'gestor']) && (int)$unidade_id_sessao === (int)$unidade_destino_id) {
        return true;
    }
    return false;
}

// ── AJAX: salas da unidade ────────────────────────────────────────────────────
if (isset($_GET['acao']) && $_GET['acao'] === 'salas_unidade') {
    header('Content-Type: application/json; charset=utf-8');
    $unid = isset($_GET['unidade_id']) ? (int)$_GET['unidade_id'] : 0;
    if ($unid <= 0) { echo json_encode(['sucesso' => false, 'salas' => []]); exit; }

    $salas = [];
    $res_s = $conn->query("
        SELECT l.id, l.nome, l.tipo_local,
               pai.nome AS pai_nome
        FROM locais l
        LEFT JOIN locais pai ON l.local_pai_id = pai.id
        WHERE l.deletado = FALSE
          AND l.tipo_local IN ('andar', 'sala', 'deposito', 'corredor')
          AND (l.local_pai_id = $unid OR pai.local_pai_id = $unid)
        ORDER BY l.nome
    ");
    if ($res_s) while ($r = $res_s->fetch_assoc()) $salas[] = $r;

    echo json_encode(['sucesso' => true, 'salas' => $salas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── LÓGICA DE AÇÕES (POST) ────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'], $_POST['mov_id'])) {
    $mov_id = (int)$_POST['mov_id'];
    $acao   = $_POST['acao'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM movimentacoes WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $mov_id);
        $stmt->execute();
        $mov = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mov) throw new Exception("Movimentação não encontrada.");

        $stmt_prod = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
        $stmt_prod->bind_param("i", $mov['produto_id']);
        $stmt_prod->execute();
        $produto = $stmt_prod->get_result()->fetch_assoc();
        $stmt_prod->close();
        $controla_estoque = $produto['controla_estoque_proprio'] ?? 1;

        // ── AÇÃO: AUTORIZAR SAÍDA (pendente → em_transito) ──────────────────
        if ($acao === 'autorizar' && $mov['status'] === 'pendente') {

            // ✅ FIX: passa unidade de origem para verificar permissão de admin_unidade
            $unidade_origem = getUnidadeDoLocal($conn, $mov['local_origem_id']);
            if (!podeAutorizarSaida($nivel_usuario, $unidade_id_sessao, $unidade_origem)) {
                throw new Exception("Sem permissão para autorizar a saída.");
            }

            $stmt = $conn->prepare("
                UPDATE movimentacoes
                SET status = 'em_transito',
                    usuario_aprovacao_id = ?,
                    data_atualizacao = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $usuario_id_logado, $mov_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $status_message = "<div class='alert success'>✅ Saída autorizada! Produto em trânsito.</div>";

            if (function_exists('registrarLog'))
                registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'AUTORIZACAO_SAIDA',
                    "Saída autorizada. Produto em trânsito para unidade #{$mov['unidade_destino_id']}.", $mov['produto_id']);
        }

        // ── AÇÃO: CONFIRMAR CHEGADA (em_transito → finalizado) ──────────────
        elseif ($acao === 'confirmar_chegada' && $mov['status'] === 'em_transito') {

            $unidade_destino_id = (int)($mov['unidade_destino_id'] ?? 0);
            if (!podeConfirmarChegada($nivel_usuario, $unidade_id_sessao, $unidade_destino_id)) {
                throw new Exception("Sem permissão: seu ID de unidade não corresponde à unidade de destino.");
            }

            $local_chegada_id = isset($_POST['local_chegada_id']) ? (int)$_POST['local_chegada_id'] : 0;
            if ($local_chegada_id <= 0) {
                throw new Exception("Selecione a sala/local de destino dentro da unidade.");
            }

            $unidade_do_local = getUnidadeDoLocal($conn, $local_chegada_id);
            if ($unidade_do_local !== $unidade_destino_id) {
                throw new Exception("O local selecionado não pertence à unidade de destino.");
            }

            // 1. Reduzir estoque na origem
            if ($controla_estoque) {
                $stmt_saldo = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ?");
                $stmt_saldo->bind_param("ii", $mov['produto_id'], $mov['local_origem_id']);
                $stmt_saldo->execute();
                $saldo = $stmt_saldo->get_result()->fetch_assoc();
                $stmt_saldo->close();

                if (!$saldo || $saldo['quantidade'] < $mov['quantidade']) {
                    throw new Exception("Saldo insuficiente no local de origem.");
                }

                $stmt = $conn->prepare("UPDATE estoques SET quantidade = quantidade - ?, data_atualizado = NOW() WHERE produto_id = ? AND local_id = ?");
                $stmt->bind_param("dii", $mov['quantidade'], $mov['produto_id'], $mov['local_origem_id']);
                $stmt->execute();
                $stmt->close();

                // Limpar estoques zerados
                $stmt_clean = $conn->prepare("DELETE FROM estoques WHERE produto_id = ? AND local_id = ? AND quantidade <= 0");
                $stmt_clean->bind_param("ii", $mov['produto_id'], $mov['local_origem_id']);
                $stmt_clean->execute();
                $stmt_clean->close();

                // 2. Adicionar estoque no destino
                atualizarEstoque($conn, $mov['produto_id'], $local_chegada_id, $mov['quantidade']);
            }

            // 3. Atualizar movimentação
            $stmt = $conn->prepare("
                UPDATE movimentacoes
                SET status = 'finalizado',
                    local_destino_id = ?,
                    usuario_recebimento_id = ?,
                    data_atualizacao = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("iii", $local_chegada_id, $usuario_id_logado, $mov_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $status_message = "<div class='alert success'>✅ Chegada confirmada! Estoque atualizado.</div>";

            if (function_exists('registrarLog'))
                registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'CONFIRMACAO_CHEGADA',
                    "Chegada confirmada no local #{$local_chegada_id}.", $mov['produto_id']);

        } elseif ($acao === 'cancelar') {

            if (!in_array($nivel_usuario, ['admin', 'admin_unidade'])) {
                throw new Exception("Sem permissão para cancelar movimentações.");
            }

            if (!in_array($mov['status'], ['pendente', 'em_transito'])) {
                throw new Exception("Não é possível cancelar uma movimentação {$mov['status']}.");
            }

            $stmt = $conn->prepare("UPDATE movimentacoes SET status = 'cancelado', data_atualizacao = NOW() WHERE id = ?");
            $stmt->bind_param("i", $mov_id);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            $status_message = "<div class='alert success'>Movimentação cancelada.</div>";

            if (function_exists('registrarLog'))
                registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'CANCELAMENTO',
                    "Movimentação cancelada.", $mov['produto_id']);
        } else {
            throw new Exception("Ação inválida ou status incompatível.");
        }

    } catch (Exception $e) {
        $conn->rollback();
        $status_message = "<div class='alert error'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ── QUERY PRINCIPAL ───────────────────────────────────────────────────────────
$aba = in_array($_GET['aba'] ?? '', ['pendente', 'transito', 'finalizado', 'cancelado'])
    ? $_GET['aba'] : 'pendente';

$status_map = [
    'pendente'   => "'pendente'",
    'transito'   => "'em_transito'",
    'finalizado' => "'finalizado'",
    'cancelado'  => "'cancelado'",
];
$status_sql = $status_map[$aba];

$sql = "
    SELECT
        m.id, m.data_movimentacao, m.quantidade, m.status,
        m.local_origem_id, m.local_destino_id, m.unidade_destino_id,
        m.usuario_aprovacao_id, m.usuario_recebimento_id,
        p.nome  AS prod_nome,
        lo.nome AS orig_nome,
        ld.nome AS dest_nome,
        ud.nome AS unidade_dest_nome,
        u.nome  AS user_nome,
        u_aprov.nome AS aprov_nome
    FROM movimentacoes m
    JOIN produtos p   ON m.produto_id = p.id
    LEFT JOIN locais lo ON m.local_origem_id    = lo.id
    LEFT JOIN locais ld ON m.local_destino_id   = ld.id
    LEFT JOIN locais ud ON m.unidade_destino_id = ud.id
    LEFT JOIN usuarios u       ON m.usuario_id          = u.id
    LEFT JOIN usuarios u_aprov ON m.usuario_aprovacao_id = u_aprov.id
    WHERE m.status IN ($status_sql)
";

// ✅ FIX: filtro de unidade para admin_unidade e gestor
if ($filtro_unidade && !empty($ids_permitidos)) {
    $idsStr = implode(',', array_map('intval', $ids_permitidos));
    // Vê movimentações onde a origem está na sua unidade OU é a unidade de destino
    $sql .= " AND (m.local_origem_id IN ($idsStr) OR m.unidade_destino_id = $filtro_unidade)";
}

$sql .= " ORDER BY m.data_movimentacao DESC";
$res = $conn->query($sql);

// Contagens por aba
$counts = [];
foreach (['pendente', 'transito', 'finalizado', 'cancelado'] as $s) {
    $st = $status_map[$s];
    $q  = "SELECT COUNT(*) AS c FROM movimentacoes m WHERE m.status IN ($st)";
    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $q .= " AND (m.local_origem_id IN ($idsStr) OR m.unidade_destino_id = $filtro_unidade)";
    }
    $rc = $conn->query($q);
    $counts[$s] = $rc ? (int)$rc->fetch_assoc()['c'] : 0;
}

// Detecta modo modal
$is_modal = isset($_GET['modal']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Movimentações</title>
    <?php if (!$is_modal): ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <?php endif; ?>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: <?php echo $is_modal ? '16px' : '24px'; ?>; }

        .page-header { margin-bottom: 20px; }
        .page-header h1 { margin: 0 0 4px; font-size: 1.3rem; color: #2c3e50; }
        .page-header p  { margin: 0; font-size: 0.88rem; color: #888; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin-bottom: 0; flex-wrap: wrap; }
        .tab-btn {
            padding: 9px 18px;
            border: 1px solid #dee2e6;
            border-bottom: none;
            background: #f8f9fa;
            color: #666;
            cursor: pointer;
            font-size: 0.88rem;
            font-weight: 600;
            border-radius: 6px 6px 0 0;
            text-decoration: none;
            transition: .15s;
        }
        .tab-btn:hover  { background: #e9ecef; color: #333; }
        .tab-btn.active { background: #fff; color: #007bff; border-color: #dee2e6; }

        .badge-count {
            display: inline-block;
            background: #dc3545;
            color: #fff;
            border-radius: 10px;
            font-size: 0.72rem;
            padding: 1px 6px;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* Tabela */
        .table-wrap {
            background: #fff;
            border-radius: 0 8px 8px 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            overflow: hidden;
        }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: #f8f9fa;
            color: #555;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
        }

        td {
            padding: 12px 14px;
            font-size: 0.88rem;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fbff; }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .badge-pendente   { background: #fff3cd; color: #856404; }
        .badge-em_transito { background: #cce5ff; color: #004085; }
        .badge-finalizado { background: #d4edda; color: #155724; }
        .badge-cancelado  { background: #e2e3e5; color: #383d41; }

        .actions { display: flex; gap: 5px; flex-wrap: wrap; }

        .btn {
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-autorizar { background: #007bff; color: #fff; }
        .btn-autorizar:hover { background: #0056b3; }
        .btn-confirmar { background: #28a745; color: #fff; }
        .btn-confirmar:hover { background: #1e7e34; }
        .btn-cancelar  { background: #dc3545; color: #fff; }
        .btn-cancelar:hover  { background: #bd2130; }

        .empty-state { text-align: center; padding: 40px; color: #aaa; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff; border-radius: 12px;
            padding: 28px 32px; width: 100%; max-width: 480px;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
        }
        .modal-box h2 { margin: 0 0 16px; font-size: 1.2rem; color: #2c3e50; }
        .modal-box select, .modal-box input {
            width: 100%; padding: 10px; border: 1px solid #ccc;
            border-radius: 6px; font-size: 0.95em; margin-bottom: 16px;
        }
        .modal-actions { display: flex; gap: 10px; }
        .modal-actions button { flex: 1; padding: 10px; border: none; border-radius: 6px; font-size: 0.95em; font-weight: 600; cursor: pointer; }
        .btn-ok     { background: #007bff; color: #fff; }
        .btn-cancel-m { background: #e9ecef; color: #333; }

        .info-destino { font-size: 0.8em; color: #666; margin-top: 3px; }
    </style>
</head>
<body>

<div class="page-header">
    <h1>⚙️ Gerenciar Movimentações</h1>
    <p>
        <?php if ($nivel_usuario === 'admin'): ?>
            Como administrador, você pode autorizar saídas e confirmar chegadas.
        <?php elseif ($nivel_usuario === 'admin_unidade'): ?>
            Você pode autorizar saídas da sua unidade e confirmar chegadas.
        <?php elseif ($nivel_usuario === 'gestor'): ?>
            Você pode confirmar chegadas na sua unidade.
        <?php endif; ?>
    </p>
</div>

<?php echo $status_message; ?>

<!-- Tabs -->
<div class="tabs">
    <?php
    $tab_labels = [
        'pendente'   => ['emoji' => '⏳', 'label' => 'Pendentes'],
        'transito'   => ['emoji' => '🚚', 'label' => 'Em Trânsito'],
        'finalizado' => ['emoji' => '✅', 'label' => 'Finalizados'],
        'cancelado'  => ['emoji' => '❌', 'label' => 'Cancelados'],
    ];
    foreach ($tab_labels as $slug => $info):
        $active = ($aba === $slug) ? 'active' : '';
        $params_aba = $is_modal ? "aba=$slug&modal=1" : "aba=$slug";
    ?>
        <a href="?<?php echo $params_aba; ?>" class="tab-btn <?php echo $active; ?>">
            <?php echo $info['emoji'] . ' ' . $info['label']; ?>
            <?php if ($counts[$slug] > 0): ?>
                <span class="badge-count"><?php echo $counts[$slug]; ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="table-wrap">
    <?php if ($res && $res->num_rows > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Produto</th>
                <th>De → Para</th>
                <th>Solicitante</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $res->fetch_assoc()):
                // ✅ Calcula se o usuário pode autorizar para esta movimentação específica
                $unidade_origem_mov = getUnidadeDoLocal($conn, $row['local_origem_id']);
                $can_autorizar = ($aba === 'pendente') && podeAutorizarSaida($nivel_usuario, $unidade_id_sessao, $unidade_origem_mov);
                $can_confirmar = ($aba === 'transito') && podeConfirmarChegada($nivel_usuario, $unidade_id_sessao, $row['unidade_destino_id']);
                $can_cancelar  = in_array($nivel_usuario, ['admin', 'admin_unidade']) && in_array($aba, ['pendente', 'transito']);
            ?>
            <tr>
                <td style="white-space:nowrap;">
                    <?php echo date('d/m/Y H:i', strtotime($row['data_movimentacao'])); ?>
                </td>
                <td><strong><?php echo htmlspecialchars($row['prod_nome']); ?></strong></td>
                <td>
                    <strong>De:</strong> <?php echo htmlspecialchars($row['orig_nome'] ?? '—'); ?><br>
                    <strong>Para:</strong>
                    <?php if ($row['status'] === 'finalizado' && $row['dest_nome']): ?>
                        <?php echo htmlspecialchars($row['dest_nome']); ?>
                    <?php else: ?>
                        <em style="color:#888;">Unidade: <?php echo htmlspecialchars($row['unidade_dest_nome'] ?? '—'); ?></em>
                        <?php if ($row['status'] === 'em_transito'): ?>
                            <div class="info-destino">⏳ Sala a definir ao confirmar chegada</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo htmlspecialchars($row['user_nome'] ?? '—'); ?>
                    <?php if ($row['aprov_nome']): ?>
                        <br><small style="color:#888;">Autorizou: <?php echo htmlspecialchars($row['aprov_nome']); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $label_status = [
                        'pendente'    => '⏳ Pendente',
                        'em_transito' => '🚚 Em Trânsito',
                        'finalizado'  => '✅ Finalizado',
                        'cancelado'   => '❌ Cancelado',
                    ];
                    $badge_c = 'badge-' . $row['status'];
                    ?>
                    <span class="badge-status <?php echo $badge_c; ?>">
                        <?php echo $label_status[$row['status']] ?? $row['status']; ?>
                    </span>
                </td>
                <td>
                    <div class="actions">
                        <?php if ($can_autorizar): ?>
                            <form method="POST" onsubmit="return confirm('Confirmar autorização da saída?');" style="display:inline;">
                                <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="acao" value="autorizar" class="btn btn-autorizar">✅ Autorizar Saída</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($can_confirmar): ?>
                            <button class="btn btn-confirmar"
                                onclick="abrirModalChegada(<?php echo $row['id']; ?>, <?php echo (int)$row['unidade_destino_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['unidade_dest_nome'] ?? '')); ?>')">
                                📥 Confirmar Chegada
                            </button>
                        <?php endif; ?>

                        <?php if ($can_cancelar): ?>
                            <form method="POST" onsubmit="return confirm('Cancelar esta movimentação?');" style="display:inline;">
                                <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="acao" value="cancelar" class="btn btn-cancelar">🗑 Cancelar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <?php else: ?>
    <div class="empty-state">
        <div style="font-size:2.5rem; margin-bottom:8px;">📭</div>
        <p>Nenhuma movimentação encontrada nesta aba.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Modal de Confirmação de Chegada -->
<div class="modal-overlay" id="modalChegada">
    <div class="modal-box">
        <h2>📥 Confirmar Chegada</h2>
        <p id="modalChegadaDesc" style="color:#666; font-size:.9rem; margin:0 0 14px;"></p>
        <form method="POST" id="formChegada">
            <input type="hidden" name="acao" value="confirmar_chegada">
            <input type="hidden" name="mov_id" id="chegadaMovId" value="">
            <?php if ($is_modal): ?><input type="hidden" name="modal" value="1"><?php endif; ?>

            <label>Sala / Local de Destino:</label>
            <select name="local_chegada_id" id="chegadaSelect" required>
                <option value="">Carregando locais...</option>
            </select>

            <div class="modal-actions">
                <button type="button" class="btn-cancel-m" onclick="fecharModalChegada()">Cancelar</button>
                <button type="submit" class="btn-ok">✅ Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalChegada(movId, unidadeId, unidadeNome) {
    document.getElementById('chegadaMovId').value = movId;
    document.getElementById('modalChegadaDesc').textContent =
        'Selecione a sala exata dentro da unidade "' + unidadeNome + '" onde o produto chegou.';

    const sel = document.getElementById('chegadaSelect');
    sel.innerHTML = '<option value="">Carregando...</option>';
    document.getElementById('modalChegada').classList.add('active');

    fetch('gerenciar.php?acao=salas_unidade&unidade_id=' + unidadeId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">— Selecione o local —</option>';
            if (data.sucesso && data.salas.length > 0) {
                data.salas.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    const prefix = s.pai_nome ? s.pai_nome + ' › ' : '';
                    opt.textContent = prefix + s.nome + ' (' + s.tipo_local + ')';
                    sel.appendChild(opt);
                });
            } else {
                sel.innerHTML = '<option value="">Nenhum local encontrado na unidade</option>';
            }
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Erro ao carregar locais</option>';
        });
}

function fecharModalChegada() {
    document.getElementById('modalChegada').classList.remove('active');
}

document.getElementById('modalChegada').addEventListener('click', function(e) {
    if (e.target === this) fecharModalChegada();
});
</script>

</body>
</html>