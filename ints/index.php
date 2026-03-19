<?php
// ============================================================
// index.php — Dashboard Principal do INTS Inventário
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_start();
}

// Proteção: redireciona para login se não autenticado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/db.php';

$usuario_nome  = htmlspecialchars($_SESSION['usuario_nome']  ?? 'Usuário');
$usuario_nivel = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id    = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;

$is_admin        = ($usuario_nivel === 'admin');
$is_admin_any    = in_array($usuario_nivel, ['admin', 'admin_unidade']);
$pode_mover      = in_array($usuario_nivel, ['admin', 'admin_unidade', 'gestor']);

// ============================================================
// KPIs — Queries adaptadas ao nível do usuário
// ============================================================

// --- Filtro de locais por unidade (para admin_unidade) ---
$ids_unidade_str = '';
if ($usuario_nivel === 'admin_unidade' && $unidade_id > 0 && function_exists('getIdsLocaisDaUnidade')) {
    $ids_unidade = getIdsLocaisDaUnidade($conn, $unidade_id);
    $ids_unidade_str = implode(',', array_map('intval', $ids_unidade));
}

// 1. Total de produtos ativos
$sql_total = "SELECT COUNT(*) AS total FROM produtos WHERE deletado = FALSE AND status_produto != 'baixa_total'";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_total = "SELECT COUNT(DISTINCT p.id) AS total FROM produtos p
                  LEFT JOIN estoques e ON p.id = e.produto_id
                  LEFT JOIN patrimonios pt ON p.id = pt.produto_id
                  WHERE p.deletado = FALSE AND p.status_produto != 'baixa_total'
                  AND (e.local_id IN ($ids_unidade_str) OR pt.local_id IN ($ids_unidade_str))";
}
$res = $conn->query($sql_total);
$total_produtos = $res ? (int)$res->fetch_assoc()['total'] : 0;

// 2. Movimentações pendentes
$sql_mov_pend = "SELECT COUNT(*) AS total FROM movimentacoes WHERE status = 'pendente'";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_mov_pend = "SELECT COUNT(*) AS total FROM movimentacoes
                     WHERE status = 'pendente'
                     AND (local_origem_id IN ($ids_unidade_str) OR unidade_destino_id = $unidade_id)";
}
$res = $conn->query($sql_mov_pend);
$total_mov_pendentes = $res ? (int)$res->fetch_assoc()['total'] : 0;

// 3. Movimentações em trânsito
$sql_mov_trans = "SELECT COUNT(*) AS total FROM movimentacoes WHERE status = 'em_transito'";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_mov_trans = "SELECT COUNT(*) AS total FROM movimentacoes
                      WHERE status = 'em_transito'
                      AND (local_origem_id IN ($ids_unidade_str) OR unidade_destino_id = $unidade_id)";
}
$res = $conn->query($sql_mov_trans);
$total_em_transito = $res ? (int)$res->fetch_assoc()['total'] : 0;

// 4. Baixas pendentes de aprovação
$sql_baixas_pend = "SELECT COUNT(*) AS total FROM baixas WHERE status = 'pendente'";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_baixas_pend = "SELECT COUNT(*) AS total FROM baixas b
                        WHERE b.status = 'pendente'
                        AND (b.local_id IN ($ids_unidade_str) OR b.local_id IS NULL)";
}
$res = $conn->query($sql_baixas_pend);
$total_baixas_pend = $res ? (int)$res->fetch_assoc()['total'] : 0;

// 5. Inconsistências: produtos com status 'ativo' mas sem estoque e sem patrimônio ativo
$sql_incons = "
    SELECT COUNT(*) AS total FROM produtos p
    WHERE p.deletado = FALSE
      AND p.status_produto = 'ativo'
      AND p.controla_estoque_proprio = 1
      AND (SELECT COALESCE(SUM(e.quantidade),0) FROM estoques e WHERE e.produto_id = p.id) = 0
      AND (SELECT COUNT(*) FROM patrimonios pt WHERE pt.produto_id = p.id AND pt.status != 'desativado') = 0
";
$res = $conn->query($sql_incons);
$total_inconsistencias = $is_admin ? ($res ? (int)$res->fetch_assoc()['total'] : 0) : 0;

// 6. Total de patrimônios ativos
$sql_patr = "SELECT COUNT(*) AS total FROM patrimonios WHERE status != 'desativado'";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_patr = "SELECT COUNT(*) AS total FROM patrimonios WHERE status != 'desativado' AND local_id IN ($ids_unidade_str)";
}
$res = $conn->query($sql_patr);
$total_patrimonios = $res ? (int)$res->fetch_assoc()['total'] : 0;

// 7. Últimas movimentações recentes (5)
$sql_recentes = "
    SELECT m.id, m.data_movimentacao, m.status, m.quantidade,
           p.nome AS produto_nome,
           lo.nome AS origem_nome,
           u.nome AS usuario_nome
    FROM movimentacoes m
    JOIN produtos p ON m.produto_id = p.id
    LEFT JOIN locais lo ON m.local_origem_id = lo.id
    LEFT JOIN usuarios u ON m.usuario_id = u.id
    WHERE 1=1
";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_recentes .= " AND (m.local_origem_id IN ($ids_unidade_str) OR m.unidade_destino_id = $unidade_id)";
}
$sql_recentes .= " ORDER BY m.data_movimentacao DESC LIMIT 5";
$movs_recentes = [];
$res = $conn->query($sql_recentes);
if ($res) while ($r = $res->fetch_assoc()) $movs_recentes[] = $r;

// 8. Baixas recentes (5)
$sql_baixas_rec = "
    SELECT b.id, b.data_baixa, b.status, b.quantidade, b.motivo,
           p.nome AS produto_nome,
           u.nome AS usuario_nome
    FROM baixas b
    JOIN produtos p ON b.produto_id = p.id
    LEFT JOIN usuarios u ON b.criado_por = u.id
";
if ($usuario_nivel === 'admin_unidade' && $ids_unidade_str) {
    $sql_baixas_rec .= " WHERE (b.local_id IN ($ids_unidade_str) OR b.local_id IS NULL)";
}
$sql_baixas_rec .= " ORDER BY b.data_baixa DESC LIMIT 5";
$baixas_recentes = [];
$res = $conn->query($sql_baixas_rec);
if ($res) while ($r = $res->fetch_assoc()) $baixas_recentes[] = $r;

// Label legível do nível
$nivel_labels = [
    'admin'        => 'Administrador Geral',
    'admin_unidade'=> 'Admin de Unidade',
    'gestor'       => 'Gestor',
    'comum'        => 'Visualizador',
];
$nivel_label = $nivel_labels[$usuario_nivel] ?? $usuario_nivel;

// Nome da unidade (para admin_unidade)
$nome_unidade = '';
if ($usuario_nivel === 'admin_unidade' && $unidade_id > 0) {
    $res_u = $conn->query("SELECT nome FROM locais WHERE id = $unidade_id LIMIT 1");
    if ($res_u && $row_u = $res_u->fetch_assoc()) $nome_unidade = $row_u['nome'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/style.css">
    <title>INTS Inventário — Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background-color: #f4f6f9; display: flex; height: 100vh; overflow: hidden; }

        /* ── Sidebar ── */
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; flex-shrink: 0; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; font-size: 0.9em; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .sidebar .sidebar-divider { border-top: 1px solid #4b545c; margin: 10px 0; }

        /* Badges nos links da sidebar */
        .nav-badge {
            margin-left: auto;
            background: #dc3545;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }

        .main-content { flex: 1; padding: 20px; overflow-y: auto; position: relative; }

        /* Cabeçalho da página */
        .page-header { margin-bottom: 24px; }
        .page-header h1 { margin: 0 0 4px; font-size: 1.5rem; color: #212529; }
        .page-header p  { margin: 0; color: #6c757d; font-size: 0.92rem; }
        .page-header .unidade-tag {
            display: inline-block;
            background: #e7f1ff;
            color: #0d6efd;
            border-radius: 4px;
            padding: 2px 10px;
            font-size: 0.8rem;
            margin-top: 6px;
        }

        /* ── Alerta de Inconsistência ── */
        .alert-inconsistencia {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #fff8ec;
            border: 1px solid #ffc107;
            border-left: 4px solid #e67e00;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 22px;
        }
        .alert-inconsistencia strong { color: #7c4a00; }
        .alert-inconsistencia em { color: #a0600a; }
        .btn-alerta-modal {
            margin-left: auto;
            flex-shrink: 0;
            background: #e67e00;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 7px 16px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }
        .btn-alerta-modal:hover { background: #c96a00; }

        /* ── KPI Cards ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .kpi-card {
            background: #fff;
            border-radius: 10px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border-left: 4px solid #dee2e6;
            display: flex;
            flex-direction: column;
            gap: 4px;
            text-decoration: none;
            color: inherit;
            transition: box-shadow 0.15s, transform 0.15s;
        }
        .kpi-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.12); transform: translateY(-2px); }
        .kpi-card .kpi-label { font-size: 0.76rem; color: #868e96; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .kpi-card .kpi-value { font-size: 2rem; font-weight: 700; color: #212529; line-height: 1.1; }
        .kpi-card .kpi-icon  { font-size: 1.4rem; margin-bottom: 4px; }
        .kpi-card.blue   { border-left-color: #0d6efd; }
        .kpi-card.green  { border-left-color: #198754; }
        .kpi-card.orange { border-left-color: #fd7e14; }
        .kpi-card.red    { border-left-color: #dc3545; }
        .kpi-card.purple { border-left-color: #6f42c1; }
        .kpi-card.teal   { border-left-color: #0dcaf0; }
        .kpi-card.warn   { border-left-color: #ffc107; }

        /* ── Acesso Rápido ── */
        .section-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #868e96;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin: 0 0 12px;
        }
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }
        .quick-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            text-decoration: none;
            color: #212529;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: box-shadow 0.15s, transform 0.15s;
            border: 1px solid #f0f0f0;
        }
        .quick-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.11); transform: translateY(-2px); }
        .quick-card .qc-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .quick-card .qc-text strong { display: block; font-size: 0.92rem; margin-bottom: 2px; }
        .quick-card .qc-text span   { font-size: 0.78rem; color: #868e96; }

        /* ── Tabelas recentes ── */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

        .panel { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden; }
        .panel-header {
            padding: 14px 18px; border-bottom: 1px solid #f0f0f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .panel-header h3 { margin: 0; font-size: 0.95rem; color: #343a40; }
        .panel-header a  { font-size: 0.8rem; color: #0d6efd; text-decoration: none; }
        .panel-header a:hover { text-decoration: underline; }
        .panel table { width: 100%; border-collapse: collapse; }
        .panel td, .panel th { padding: 10px 18px; text-align: left; font-size: 0.83rem; border-bottom: 1px solid #f8f9fa; }
        .panel th { color: #868e96; font-weight: 600; background: #fafafa; }
        .panel tr:last-child td { border-bottom: none; }
        .panel tr:hover td { background: #f8f9fa; }
        .panel .empty-row td { text-align: center; color: #adb5bd; padding: 24px; }

        /* Status badges */
        .badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 0.73rem; font-weight: 600; white-space: nowrap; }
        .badge-pendente   { background: #fff3cd; color: #856404; }
        .badge-transito   { background: #cff4fc; color: #055160; }
        .badge-finalizado { background: #d1e7dd; color: #0a3622; }
        .badge-cancelado  { background: #f8d7da; color: #842029; }
        .badge-aprovada   { background: #d1e7dd; color: #0a3622; }
        .badge-reprovada  { background: #f8d7da; color: #842029; }

        /* ── Modal (iframe) ── */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1000;
            display: none; justify-content: center; align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-window {
            background: #fff;
            width: 95%; max-width: 1100px; height: 90%;
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.25);
            display: flex; flex-direction: column; overflow: hidden;
            animation: slideIn 0.25s ease;
        }
        .modal-header {
            padding: 14px 20px; background: #f8f9fa; border-bottom: 1px solid #dee2e6;
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0;
        }
        .modal-title { font-weight: 700; font-size: 1rem; color: #343a40; }
        .modal-close {
            background: none; border: none; font-size: 1.4rem;
            cursor: pointer; color: #6c757d; line-height: 1;
            padding: 0 4px; transition: color .15s;
        }
        .modal-close:hover { color: #212529; }
        .modal-content-frame { flex: 1; border: none; width: 100%; background: #fff; }
        @keyframes slideIn {
            from { transform: translateY(-18px); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════ SIDEBAR ═══════════════════════════ -->
<aside class="sidebar">
    <h2>INTS Inventário</h2>
    <a href="index.php" style="background:#495057; color:#fff;">🏠 Home</a>
    <a href="pages/produtos/index.php">📦 Produtos</a>
    <a href="pages/movimentacoes/index.php" style="display:flex; align-items:center;">
        🔄 Movimentações
        <?php if ($total_mov_pendentes > 0): ?>
            <span class="nav-badge"><?php echo $total_mov_pendentes; ?></span>
        <?php endif; ?>
    </a>
    <div class="sidebar-divider"></div>
    <?php if ($is_admin): ?>
        <a href="pages/admin/index.php">⚙️ Administração</a>
    <?php endif; ?>
    <div style="margin-top:auto;">
        <a href="logout.php">🚪 Sair</a>
    </div>
</aside>

<!-- ══════════════════════════ CONTEÚDO ══════════════════════════ -->
<main class="main-content">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1>Olá, <?php echo $usuario_nome; ?> 👋</h1>
        <p>Bem-vindo ao painel de inventário. Veja o resumo geral do sistema.</p>
        <?php if ($nome_unidade): ?>
            <span class="unidade-tag">📍 Você está vendo dados da unidade: <strong><?php echo htmlspecialchars($nome_unidade); ?></strong></span>
        <?php endif; ?>
    </div>

    <!-- Alerta de inconsistências (somente admin) -->
    <?php if ($is_admin && $total_inconsistencias > 0): ?>
    <div class="alert-inconsistencia">
        <span style="font-size:1.4rem;">⚠️</span>
        <div>
            <strong><?php echo $total_inconsistencias; ?> produto(s) com inconsistência de estoque</strong> —
            itens marcados como <em>ativo</em> mas sem estoque e sem patrimônio ativo.
        </div>
        <button class="btn-alerta-modal"
            onclick="abrirModal('pages/admin/corrigir_inconsistencias.php', '⚠️ Corrigir Inconsistências de Estoque')">
            Verificar e corrigir →
        </button>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="kpi-grid">

        <a href="pages/produtos/index.php" class="kpi-card blue">
            <div class="kpi-icon">📦</div>
            <div class="kpi-label">Produtos Ativos</div>
            <div class="kpi-value"><?php echo number_format($total_produtos); ?></div>
        </a>

        <a href="pages/movimentacoes/index.php?filtro_status=pendente" class="kpi-card orange">
            <div class="kpi-icon">⏳</div>
            <div class="kpi-label">Mov. Pendentes</div>
            <div class="kpi-value"><?php echo $total_mov_pendentes; ?></div>
        </a>

        <a href="pages/movimentacoes/index.php?filtro_status=em_transito" class="kpi-card teal">
            <div class="kpi-icon">🚚</div>
            <div class="kpi-label">Em Trânsito</div>
            <div class="kpi-value"><?php echo $total_em_transito; ?></div>
        </a>

        <a href="pages/produtos/index.php" class="kpi-card purple">
            <div class="kpi-icon">🏷️</div>
            <div class="kpi-label">Patrimônios Ativos</div>
            <div class="kpi-value"><?php echo number_format($total_patrimonios); ?></div>
        </a>

    </div>

    <!-- Acesso Rápido -->
    <div class="section-title">Acesso Rápido</div>
    <div class="quick-grid">

        <a href="pages/produtos/index.php" class="quick-card">
            <div class="qc-icon" style="background:#e7f1ff;">📦</div>
            <div class="qc-text">
                <strong>Gerenciar Produtos</strong>
                <span>Listar, editar e cadastrar</span>
            </div>
        </a>

        <?php if ($pode_mover): ?>
        <a href="pages/movimentacoes/index.php" class="quick-card">
            <div class="qc-icon" style="background:#e8f9f5;">🔄</div>
            <div class="qc-text">
                <strong>Movimentações</strong>
                <span>Solicitar e aprovar</span>
            </div>
        </a>
        <?php endif; ?>

        <?php if ($is_admin_any): ?>
        <a href="pages/admin/gerenciar_baixas.php" class="quick-card">
            <div class="qc-icon" style="background:#fde8e8;">📉</div>
            <div class="qc-text">
                <strong>Baixas</strong>
                <span>Aprovar e registrar</span>
            </div>
        </a>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <a href="pages/admin/index.php" class="quick-card">
            <div class="qc-icon" style="background:#f0e8ff;">⚙️</div>
            <div class="qc-text">
                <strong>Administração</strong>
                <span>Usuários, locais, categorias</span>
            </div>
        </a>
        <?php endif; ?>

    </div>

    <!-- Tabelas recentes -->
    <div class="two-col">

        <!-- Movimentações recentes -->
        <div class="panel">
            <div class="panel-header">
                <h3>🔄 Últimas Movimentações</h3>
                <a href="pages/movimentacoes/index.php">Ver todas →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Origem</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movs_recentes)): ?>
                        <tr class="empty-row"><td colspan="3">Nenhuma movimentação recente.</td></tr>
                    <?php else: ?>
                        <?php foreach ($movs_recentes as $mov): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars(mb_strimwidth($mov['produto_nome'], 0, 30, '…')); ?>
                                <br><small style="color:#adb5bd;"><?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?></small>
                            </td>
                            <td style="color:#6c757d; font-size:0.8rem;"><?php echo htmlspecialchars($mov['origem_nome'] ?? '—'); ?></td>
                            <td>
                                <?php
                                $st = $mov['status'];
                                $cls = match($st) {
                                    'pendente'    => 'badge-pendente',
                                    'em_transito' => 'badge-transito',
                                    'finalizado'  => 'badge-finalizado',
                                    'cancelado'   => 'badge-cancelado',
                                    default       => ''
                                };
                                $lbls = ['pendente'=>'Pendente','em_transito'=>'Em Trânsito','finalizado'=>'Finalizado','cancelado'=>'Cancelado'];
                                ?>
                                <span class="badge <?php echo $cls; ?>"><?php echo $lbls[$st] ?? $st; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Baixas recentes -->
        <div class="panel">
            <div class="panel-header">
                <h3>📉 Últimas Baixas</h3>
                <a href="pages/admin/gerenciar_baixas.php">Ver todas →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Motivo</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($baixas_recentes)): ?>
                        <tr class="empty-row"><td colspan="3">Nenhuma baixa recente.</td></tr>
                    <?php else: ?>
                        <?php foreach ($baixas_recentes as $b): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars(mb_strimwidth($b['produto_nome'], 0, 30, '…')); ?>
                                <br><small style="color:#adb5bd;"><?php echo date('d/m/Y', strtotime($b['data_baixa'])); ?></small>
                            </td>
                            <td style="color:#6c757d; font-size:0.8rem;"><?php echo htmlspecialchars(mb_strimwidth($b['motivo'] ?? '—', 0, 20, '…')); ?></td>
                            <td>
                                <?php
                                $sb = $b['status'];
                                $clsb = match($sb) {
                                    'pendente'  => 'badge-pendente',
                                    'aprovada'  => 'badge-aprovada',
                                    'rejeitada' => 'badge-reprovada',
                                    'cancelada' => 'badge-cancelado',
                                    default     => ''
                                };
                                $lblsb = ['pendente'=>'Pendente','aprovada'=>'Aprovada','rejeitada'=>'Rejeitada','cancelada'=>'Cancelada'];
                                ?>
                                <span class="badge <?php echo $clsb; ?>"><?php echo $lblsb[$sb] ?? $sb; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /.two-col -->

</main>

<!-- ══════════════════════════ MODAL (iframe) ══════════════════════════ -->
<div id="modalContainer" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <span id="modalTitle" class="modal-title">Carregando…</span>
            <button onclick="fecharModal(false)" class="modal-close" title="Fechar">&times;</button>
        </div>
        <iframe id="modalFrame" class="modal-content-frame" src=""></iframe>
    </div>
</div>

<script>
    function abrirModal(url, titulo) {
        document.getElementById('modalTitle').innerText = titulo;
        const sep = url.includes('?') ? '&' : '?';
        document.getElementById('modalFrame').src = url + sep + 'modal=1';
        document.getElementById('modalContainer').classList.add('active');
    }

    function fecharModal(recarregar = false) {
        document.getElementById('modalContainer').classList.remove('active');
        document.getElementById('modalFrame').src = '';
        if (recarregar) window.location.reload();
    }

    // Fechar ao clicar no overlay
    document.getElementById('modalContainer').addEventListener('click', function(e) {
        if (e.target === this) fecharModal(false);
    });

    // Helper para o iframe filho fechar o modal (e opcionalmente recarregar o pai)
    window.fecharModalDoFilho = function(recarregar) {
        fecharModal(recarregar);
    };
</script>

</body>
</html>