<?php
require_once '../../config/_protecao.php';

$usuario_id_logado  = getUsuarioId();
$nivel_usuario      = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id_sessao  = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;
$filtro_unidade     = in_array($nivel_usuario, ['admin_unidade', 'gestor']) ? $unidade_id_sessao : null;

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
 * Novo fluxo:
 * - AUTORIZAR SAÍDA (pendente → em_transito): apenas admin global.
 * - CONFIRMAR CHEGADA (em_transito → finalizado): gestor/admin_unidade cuja unidade_id == unidade_destino_id da mov.
 */
function podeAutorizarSaida($nivel_usuario) {
    return $nivel_usuario === 'admin';
}

function podeConfirmarChegada($nivel_usuario, $unidade_id_sessao, $unidade_destino_id) {
    if ($nivel_usuario === 'admin') return true;
    if (in_array($nivel_usuario, ['admin_unidade', 'gestor']) && (int)$unidade_id_sessao === (int)$unidade_destino_id) {
        return true;
    }
    return false;
}

// ── AJAX: buscar salas da unidade (para o gestor escolher ao confirmar chegada) ──
if (isset($_GET['acao']) && $_GET['acao'] === 'salas_unidade') {
    header('Content-Type: application/json; charset=utf-8');
    $unid = isset($_GET['unidade_id']) ? (int)$_GET['unidade_id'] : 0;
    if ($unid <= 0) { echo json_encode(['sucesso' => false, 'salas' => []]); exit; }

    // Retorna todos os locais filhos da unidade (andares, salas) que não são a própria unidade
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
        // Busca movimentação
        $stmt = $conn->prepare("SELECT * FROM movimentacoes WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $mov_id);
        $stmt->execute();
        $mov = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mov) throw new Exception("Movimentação não encontrada.");

        // Verificar produto
        $stmt_prod = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
        $stmt_prod->bind_param("i", $mov['produto_id']);
        $stmt_prod->execute();
        $produto = $stmt_prod->get_result()->fetch_assoc();
        $stmt_prod->close();
        $controla_estoque = $produto['controla_estoque_proprio'] ?? 1;

        // ── AÇÃO: AUTORIZAR SAÍDA (pendente → em_transito) ──────────────────
        if ($acao === 'autorizar' && $mov['status'] === 'pendente') {

            if (!podeAutorizarSaida($nivel_usuario)) {
                throw new Exception("Sem permissão para autorizar a saída.");
            }

            // Atualiza status para em_transito
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
            $status_message = "<div class='alert success'>✅ Saída autorizada! Produto em trânsito — aguardando confirmação da unidade destino.</div>";

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

            // Validar que o local_chegada pertence à unidade_destino
            $unidade_do_local = getUnidadeDoLocal($conn, $local_chegada_id);
            if ($unidade_do_local !== $unidade_destino_id) {
                throw new Exception("O local selecionado não pertence à unidade de destino.");
            }

            // 1. Reduzir estoque na origem (produto principal)
            if ($controla_estoque) {
                // Verificar saldo na origem
                $stmt_saldo = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ?");
                $stmt_saldo->bind_param("ii", $mov['produto_id'], $mov['local_origem_id']);
                $stmt_saldo->execute();
                $saldo = $stmt_saldo->get_result()->fetch_assoc();
                $stmt_saldo->close();

                if (!$saldo || $saldo['quantidade'] < $mov['quantidade']) {
                    throw new Exception("Saldo insuficiente na origem para concluir a movimentação.");
                }

                $ok_orig = atualizarEstoque($conn, $mov['produto_id'], $mov['local_origem_id'], -$mov['quantidade']);
                if (!$ok_orig) throw new Exception("Erro ao debitar estoque na origem.");

                $ok_dest = atualizarEstoque($conn, $mov['produto_id'], $local_chegada_id, $mov['quantidade']);
                if (!$ok_dest) throw new Exception("Erro ao creditar estoque no destino.");
            }

            // 2. Mover componentes (se houver)
            $stmt_comps = $conn->prepare("
                SELECT pr.subproduto_id, pr.quantidade AS qtd_comp,
                       p.controla_estoque_proprio AS comp_controla
                FROM produto_relacionamento pr
                JOIN produtos p ON pr.subproduto_id = p.id
                WHERE pr.produto_principal_id = ? AND pr.tipo_relacao IN ('componente', 'kit')
            ");
            $stmt_comps->bind_param("i", $mov['produto_id']);
            $stmt_comps->execute();
            $res_comps = $stmt_comps->get_result();

            while ($comp = $res_comps->fetch_assoc()) {
                if (!$comp['comp_controla']) continue;
                $qtd_total = $comp['qtd_comp'] * $mov['quantidade'];

                $stmt_chk = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ?");
                $stmt_chk->bind_param("ii", $comp['subproduto_id'], $mov['local_origem_id']);
                $stmt_chk->execute();
                $saldo_c = $stmt_chk->get_result()->fetch_assoc();
                $stmt_chk->close();

                if (!$saldo_c || $saldo_c['quantidade'] < $qtd_total) {
                    throw new Exception("Saldo insuficiente do componente ID {$comp['subproduto_id']} na origem.");
                }

                atualizarEstoque($conn, $comp['subproduto_id'], $mov['local_origem_id'], -$qtd_total);
                atualizarEstoque($conn, $comp['subproduto_id'], $local_chegada_id, $qtd_total);
            }
            $stmt_comps->close();

            // 3. Atualizar movimentação: define local_destino_id e finaliza
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
            $status_message = "<div class='alert success'>✅ Chegada confirmada! Estoque atualizado com sucesso.</div>";

            if (function_exists('registrarLog'))
                registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'CONFIRMACAO_CHEGADA',
                    "Chegada confirmada no local #$local_chegada_id.", $mov['produto_id']);
        }

        // ── AÇÃO: CANCELAR ───────────────────────────────────────────────────
        elseif ($acao === 'cancelar' && $mov['status'] !== 'finalizado') {
            $pode_cancelar = false;
            if ($nivel_usuario === 'admin') {
                $pode_cancelar = true;
            } elseif ($filtro_unidade && !empty($ids_permitidos)) {
                // Pode cancelar se a origem for da sua unidade
                $pode_cancelar = in_array($mov['local_origem_id'], $ids_permitidos);
            } elseif ($mov['usuario_id'] == $usuario_id_logado && $mov['status'] === 'pendente') {
                // Solicitante pode cancelar enquanto pendente
                $pode_cancelar = true;
            }

            if (!$pode_cancelar) throw new Exception("Sem permissão para cancelar esta movimentação.");

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

// Filtro de status por aba
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
    LEFT JOIN locais lo ON m.local_origem_id  = lo.id
    LEFT JOIN locais ld ON m.local_destino_id = ld.id
    LEFT JOIN locais ud ON m.unidade_destino_id = ud.id
    LEFT JOIN usuarios u       ON m.usuario_id = u.id
    LEFT JOIN usuarios u_aprov ON m.usuario_aprovacao_id = u_aprov.id
    WHERE m.status IN ($status_sql)
";

// Filtro por unidade (para admin_unidade / gestor)
if ($filtro_unidade && !empty($ids_permitidos)) {
    $idsStr = implode(',', array_map('intval', $ids_permitidos));
    // Vê movimentações onde origem está na sua unidade OU é a unidade destino
    $sql .= " AND (m.local_origem_id IN ($idsStr) OR m.unidade_destino_id = $filtro_unidade)";
}

$sql .= " ORDER BY m.data_movimentacao DESC";
$res = $conn->query($sql);

// Contagem por aba
$counts = [];
foreach (['pendente', 'transito', 'finalizado', 'cancelado'] as $s) {
    $st = $status_map[$s];
    $q = "SELECT COUNT(*) AS c FROM movimentacoes m WHERE m.status IN ($st)";
    if ($filtro_unidade && !empty($ids_permitidos)) {
        $idsStr = implode(',', array_map('intval', $ids_permitidos));
        $q .= " AND (m.local_origem_id IN ($idsStr) OR m.unidade_destino_id = $filtro_unidade)";
    }
    $rc = $conn->query($q);
    $counts[$s] = $rc ? (int)$rc->fetch_assoc()['c'] : 0;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Movimentações</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }

        .page-header { margin-bottom: 20px; }
        .page-header h1 { font-size: 1.5rem; color: #2c3e50; margin: 0 0 6px; }
        .page-header p  { color: #666; font-size: 0.9em; margin: 0; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.95em; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Tabs */
        .tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn {
            padding: 8px 16px;
            border: none; border-radius: 20px;
            background: #e9ecef; color: #555;
            cursor: pointer; font-size: 0.88em; font-weight: 600;
            transition: all .2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .tab-btn:hover { background: #dee2e6; }
        .tab-btn.active { background: #007bff; color: #fff; }
        .badge { background: rgba(255,255,255,.3); border-radius: 10px; padding: 1px 7px; font-size: 0.82em; }
        .tab-btn:not(.active) .badge { background: #ccc; color: #333; }
        .badge.urgente { background: #dc3545 !important; color: #fff !important; }

        /* Tabela */
        .table-wrap { background: #fff; border-radius: 10px; box-shadow: 0 1px 8px rgba(0,0,0,.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #343a40; color: #fff; padding: 12px 14px; text-align: left; font-size: 0.85em; }
        td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: top; font-size: 0.9em; }
        tr:hover td { background: #f8f9ff; }
        tr:last-child td { border-bottom: none; }

        /* Status badges */
        .badge-status { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.82em; font-weight: 600; }
        .badge-pendente   { background: #fff3cd; color: #856404; }
        .badge-em_transito{ background: #cce5ff; color: #004085; }
        .badge-finalizado { background: #d4edda; color: #155724; }
        .badge-cancelado  { background: #f8d7da; color: #721c24; }

        /* Botões de ação */
        .btn { padding: 6px 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.82em; font-weight: 600; margin: 2px 0; width: 100%; text-align: center; }
        .btn-autorizar  { background: #28a745; color: #fff; }
        .btn-confirmar  { background: #007bff; color: #fff; }
        .btn-cancelar   { background: #dc3545; color: #fff; }
        .btn:hover { opacity: .88; }

        .empty-state { text-align: center; padding: 48px 20px; color: #aaa; }
        .empty-state .icon { font-size: 2.5em; }

        /* Modal de confirmação de chegada */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff; border-radius: 12px;
            padding: 30px 36px; width: 100%; max-width: 500px;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
        }
        .modal-box h2 { margin: 0 0 18px; font-size: 1.2rem; color: #2c3e50; }
        .modal-box label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; }
        .modal-box select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; margin-bottom: 18px; }
        .modal-actions { display: flex; gap: 10px; }
        .modal-actions button { flex: 1; padding: 10px; border: none; border-radius: 6px; font-size: 0.95em; font-weight: 600; cursor: pointer; }
        .btn-modal-ok  { background: #007bff; color: #fff; }
        .btn-modal-cancel { background: #e9ecef; color: #333; }

        .info-destino { font-size: 0.8em; color: #666; margin-top: 4px; }
    </style>
</head>
<body>

<div class="page-header">
    <h1>⚙️ Gerenciar Movimentações</h1>
    <p>
        <?php if ($nivel_usuario === 'admin'): ?>
            Você pode <strong>autorizar saídas</strong> de movimentações pendentes.
        <?php elseif (in_array($nivel_usuario, ['admin_unidade', 'gestor'])): ?>
            Você pode <strong>confirmar chegadas</strong> na sua unidade e escolher o local exato.
        <?php endif; ?>
    </p>
</div>

<?php echo $status_message; ?>

<!-- Tabs -->
<div class="tabs">
    <?php
    $tab_labels = [
        'pendente'   => ['emoji' => '⏳', 'label' => 'Aguardando Autorização'],
        'transito'   => ['emoji' => '🚚', 'label' => 'Em Trânsito'],
        'finalizado' => ['emoji' => '✅', 'label' => 'Finalizados'],
        'cancelado'  => ['emoji' => '❌', 'label' => 'Cancelados'],
    ];
    foreach ($tab_labels as $slug => $info):
        $active = ($aba === $slug) ? 'active' : '';
        $badgeClass = ($slug === 'pendente' && $counts[$slug] > 0) ? 'urgente' : '';
    ?>
        <a href="?aba=<?php echo $slug; ?>" class="tab-btn <?php echo $active; ?>">
            <?php echo $info['emoji']; ?> <?php echo $info['label']; ?>
            <span class="badge <?php echo $badgeClass; ?>"><?php echo $counts[$slug]; ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Tabela -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Produto</th>
                <th>Qtd</th>
                <th>Origem → Destino</th>
                <th>Solicitante</th>
                <th>Status</th>
                <th style="width:160px;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$res || $res->num_rows == 0): ?>
            <tr>
                <td colspan="8">
                    <div class="empty-state">
                        <div class="icon">📭</div>
                        <p>Nenhuma movimentação encontrada nesta categoria.</p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
        <?php while ($row = $res->fetch_assoc()):
            $can_autorizar = ($row['status'] === 'pendente') && podeAutorizarSaida($nivel_usuario);
            $can_confirmar = ($row['status'] === 'em_transito') && podeConfirmarChegada($nivel_usuario, $unidade_id_sessao, $row['unidade_destino_id']);

            // Pode cancelar: admin sempre, solicitante enquanto pendente, admin_unidade/gestor da origem
            $can_cancelar = ($row['status'] !== 'finalizado') && (
                $nivel_usuario === 'admin'
                || ($row['usuario_id'] == $usuario_id_logado && $row['status'] === 'pendente')
                || ($filtro_unidade && in_array($row['local_origem_id'], $ids_permitidos))
            );
        ?>
            <tr>
                <td><strong>#<?php echo $row['id']; ?></strong></td>
                <td><?php echo date('d/m/Y H:i', strtotime($row['data_movimentacao'])); ?></td>
                <td><?php echo htmlspecialchars($row['prod_nome']); ?></td>
                <td><strong><?php echo $row['quantidade']; ?></strong></td>
                <td>
                    <strong>De:</strong> <?php echo htmlspecialchars($row['orig_nome'] ?? '—'); ?><br>
                    <strong>Para:</strong>
                    <?php if ($row['status'] === 'finalizado' && $row['dest_nome']): ?>
                        <?php echo htmlspecialchars($row['dest_nome']); ?>
                    <?php else: ?>
                        <em style="color:#888;">Unidade: <?php echo htmlspecialchars($row['unidade_dest_nome'] ?? '—'); ?></em>
                        <?php if ($row['status'] === 'em_transito'): ?>
                            <div class="info-destino">⏳ Sala a definir pelo gestor</div>
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
                    <span class="badge-status badge-<?php echo $row['status']; ?>">
                        <?php
                        $label_status = [
                            'pendente'    => '⏳ Pendente',
                            'em_transito' => '🚚 Em Trânsito',
                            'finalizado'  => '✅ Finalizado',
                            'cancelado'   => '❌ Cancelado',
                        ];
                        echo $label_status[$row['status']] ?? $row['status'];
                        ?>
                    </span>
                </td>
                <td>
                    <!-- Autorizar Saída -->
                    <?php if ($can_autorizar): ?>
                        <form method="POST" onsubmit="return confirm('Confirmar autorização da saída?');">
                            <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="acao" value="autorizar" class="btn btn-autorizar">✅ Autorizar Saída</button>
                        </form>
                    <?php endif; ?>

                    <!-- Confirmar Chegada (gestor) -->
                    <?php if ($can_confirmar): ?>
                        <button
                            class="btn btn-confirmar"
                            onclick="abrirModalChegada(<?php echo $row['id']; ?>, <?php echo (int)$row['unidade_destino_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['unidade_dest_nome'])); ?>')"
                        >📥 Confirmar Chegada</button>
                    <?php endif; ?>

                    <!-- Cancelar -->
                    <?php if ($can_cancelar): ?>
                        <form method="POST" onsubmit="return confirm('Cancelar esta movimentação?');">
                            <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="acao" value="cancelar" class="btn btn-cancelar">🚫 Cancelar</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$can_autorizar && !$can_confirmar && !$can_cancelar): ?>
                        <span style="color:#bbb; font-size:0.82em;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Confirmar Chegada -->
<div class="modal-overlay" id="modalChegada">
    <div class="modal-box">
        <h2>📥 Confirmar Chegada</h2>
        <p id="modal-desc" style="font-size:0.9em; color:#555; margin-bottom:16px;"></p>

        <form method="POST" id="formChegada">
            <input type="hidden" name="acao" value="confirmar_chegada">
            <input type="hidden" name="mov_id" id="modal-mov-id" value="">

            <label for="local_chegada_id">Selecione a Sala / Local de Chegada <span style="color:red">*</span></label>
            <select name="local_chegada_id" id="local_chegada_id" required>
                <option value="">Carregando salas...</option>
            </select>

            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="fecharModalChegada()">Cancelar</button>
                <button type="submit" class="btn-modal-ok">✅ Confirmar e Atualizar Estoque</button>
            </div>
        </form>
    </div>
</div>

<script>
async function abrirModalChegada(movId, unidadeId, unidadeNome) {
    document.getElementById('modalChegada').classList.add('active');
    document.getElementById('modal-mov-id').value = movId;
    document.getElementById('modal-desc').textContent =
        'Confirmar chegada na unidade "' + unidadeNome + '". Escolha em qual sala o produto será alocado.';

    const sel = document.getElementById('local_chegada_id');
    sel.innerHTML = '<option value="">Carregando...</option>';

    try {
        const res  = await fetch(`?acao=salas_unidade&unidade_id=${encodeURIComponent(unidadeId)}`);
        const data = await res.json();

        if (data.sucesso && data.salas.length > 0) {
            sel.innerHTML = '<option value="">Selecione a sala...</option>';
            data.salas.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = (s.pai_nome ? s.pai_nome + ' > ' : '') + s.nome + ' (' + s.tipo_local + ')';
                sel.appendChild(opt);
            });
        } else {
            sel.innerHTML = '<option value="">Nenhuma sala encontrada para esta unidade</option>';
        }
    } catch (e) {
        sel.innerHTML = '<option value="">Erro ao carregar salas</option>';
        console.error(e);
    }
}

function fecharModalChegada() {
    document.getElementById('modalChegada').classList.remove('active');
}

// Fechar modal clicando fora
document.getElementById('modalChegada').addEventListener('click', function (e) {
    if (e.target === this) fecharModalChegada();
});
</script>

</body>
</html>