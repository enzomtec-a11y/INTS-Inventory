<?php
require_once '../../../config/_protecao.php';

// -------------------------------------------------------
// Apenas admins acessam a central de requisições
// -------------------------------------------------------
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
if (!in_array($usuario_nivel, ['admin', 'admin_unidade'])) {
    header("Location: ../../produtos/index.php?erro=sem_permissao");
    exit;
}

$usuario_id_log      = function_exists('getUsuarioId') ? getUsuarioId() : ($_SESSION['usuario_id'] ?? 0);
$usuario_unidade     = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids  = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = function_exists('getIdsLocaisDaUnidade')
        ? getIdsLocaisDaUnidade($conn, $usuario_unidade)
        : [$usuario_unidade];
}

$msg      = '';
$msgClass = '';

// -------------------------------------------------------
// PROCESSAR AÇÃO (aprovar / rejeitar)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao']) && !empty($_POST['baixa_id'])) {
    $acao    = $_POST['acao'];
    $baixa_id= (int)$_POST['baixa_id'];

    if (!in_array($acao, ['aprovar', 'rejeitar', 'cancelar'])) {
        $msg = 'Ação inválida.'; $msgClass = 'msg-error';
    } else {
        // Buscar a baixa
        $stmt_b = $conn->prepare("SELECT b.*, p.nome AS produto_nome, p.status_produto FROM baixas b JOIN produtos p ON b.produto_id = p.id WHERE b.id = ? AND b.status = 'pendente'");
        $stmt_b->bind_param("i", $baixa_id); $stmt_b->execute();
        $baixa = $stmt_b->get_result()->fetch_assoc(); $stmt_b->close();

        if (!$baixa) {
            $msg = 'Requisição não encontrada ou já processada.'; $msgClass = 'msg-error';
        } else {
            $conn->begin_transaction();
            try {
                $novo_status_baixa = ($acao === 'aprovar') ? 'aprovada' : ($acao === 'rejeitar' ? 'rejeitada' : 'cancelada');
                $stmt_up = $conn->prepare("UPDATE baixas SET status=?, aprovador_id=? WHERE id=?");
                $stmt_up->bind_param("sii", $novo_status_baixa, $usuario_id_log, $baixa_id);
                $stmt_up->execute(); $stmt_up->close();

                // Se aprovada: atualizar status do produto e estoque
                if ($acao === 'aprovar') {
                    $produto_id = (int)$baixa['produto_id'];
                    $quantidade_baixa = (float)$baixa['quantidade'];

                    // Deduzir do estoque se houver local definido
                    if (!empty($baixa['local_id'])) {
                        $stmt_est = $conn->prepare("UPDATE estoques SET quantidade = GREATEST(0, quantidade - ?) WHERE produto_id = ? AND local_id = ?");
                        $stmt_est->bind_param("dii", $quantidade_baixa, $produto_id, $baixa['local_id']);
                        $stmt_est->execute(); $stmt_est->close();
                    }

                    // Verificar estoque total restante para definir status
                    $stmt_tot = $conn->prepare("SELECT COALESCE(SUM(quantidade), 0) AS total FROM estoques WHERE produto_id = ?");
                    $stmt_tot->bind_param("i", $produto_id); $stmt_tot->execute();
                    $row_tot  = $stmt_tot->get_result()->fetch_assoc(); $stmt_tot->close();
                    $estoque_restante = (float)$row_tot['total'];

                    $novo_status_produto = ($estoque_restante <= 0) ? 'baixa_total' : 'baixa_parcial';

                    $stmt_prod = $conn->prepare("UPDATE produtos SET status_produto=?, data_atualizado=NOW() WHERE id=?");
                    $stmt_prod->bind_param("si", $novo_status_produto, $produto_id);
                    $stmt_prod->execute(); $stmt_prod->close();

                    // Registrar no baixas_historico
                    $motivo_hist = $baixa['motivo'];
                    $desc_hist   = $baixa['descricao'];
                    $stmt_hist   = $conn->prepare("INSERT INTO baixas_historico (produto_id, quantidade_baixa, local_id, motivo, descricao_motivo, usuario_id, data_baixa) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt_hist->bind_param("ididsi", $produto_id, $quantidade_baixa, $baixa['local_id'], $motivo_hist, $desc_hist, $usuario_id_log);
                    $stmt_hist->execute(); $stmt_hist->close();

                    if (function_exists('registrarLog'))
                        registrarLog($conn, $usuario_id_log, 'baixas', $baixa_id, 'APROVACAO_BAIXA', "Baixa aprovada para produto ID {$produto_id}. Status: {$novo_status_produto}.", $produto_id);
                } else {
                    if (function_exists('registrarLog'))
                        registrarLog($conn, $usuario_id_log, 'baixas', $baixa_id, strtoupper($acao) . '_BAIXA', "Baixa {$acao}da para produto ID {$baixa['produto_id']}.", $baixa['produto_id']);
                }

                $conn->commit();
                $msg      = ($acao === 'aprovar')
                    ? "✅ Baixa aprovada com sucesso! Produto atualizado."
                    : "❌ Baixa {$acao}da.";
                $msgClass = ($acao === 'aprovar') ? 'msg-success' : 'msg-warning';

            } catch (Exception $e) {
                $conn->rollback();
                $msg = 'Erro ao processar: ' . htmlspecialchars($e->getMessage()); $msgClass = 'msg-error';
            }
        }
    }
}

// -------------------------------------------------------
// BUSCAR REQUISIÇÕES PENDENTES
// -------------------------------------------------------
$filtro_tab = $_GET['tab'] ?? 'pendente'; // pendente | historico
$tabs_validas = ['pendente', 'aprovada', 'rejeitada', 'cancelada'];
if (!in_array($filtro_tab, $tabs_validas)) $filtro_tab = 'pendente';

$sql_baixas = "
    SELECT 
        b.id AS baixa_id,
        b.produto_id,
        b.patrimonio_id,
        b.quantidade,
        b.motivo,
        b.descricao,
        b.data_baixa,
        b.valor_contabil,
        b.status,
        b.data_criado,
        b.local_id,
        p.nome          AS produto_nome,
        p.numero_patrimonio,
        p.status_produto,
        p.tipo_posse,
        l.nome          AS local_nome,
        u_cria.nome     AS criado_por_nome,
        u_apr.nome      AS aprovador_nome
    FROM baixas b
    JOIN produtos p ON b.produto_id = p.id
    LEFT JOIN locais l ON b.local_id = l.id
    LEFT JOIN usuarios u_cria ON b.criado_por   = u_cria.id
    LEFT JOIN usuarios u_apr  ON b.aprovador_id = u_apr.id
    WHERE b.status = ?
";

$params_b = [$filtro_tab]; $types_b = 's';

// Filtro por unidade para admin_unidade
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql_baixas .= " AND (b.local_id IN ($idsStr) OR b.local_id IS NULL)";
}

$sql_baixas .= " ORDER BY b.data_criado DESC";

$stmt_baixas = $conn->prepare($sql_baixas);
$stmt_baixas->bind_param($types_b, ...$params_b);
$stmt_baixas->execute();
$result_baixas = $stmt_baixas->get_result();
$baixas = [];
while ($r = $result_baixas->fetch_assoc()) $baixas[] = $r;
$stmt_baixas->close();

// Contadores por aba
$counts = [];
foreach ($tabs_validas as $tab) {
    $stmt_c = $conn->prepare("SELECT COUNT(*) AS cnt FROM baixas WHERE status = ?");
    $stmt_c->bind_param("s", $tab); $stmt_c->execute();
    $counts[$tab] = (int)$stmt_c->get_result()->fetch_assoc()['cnt'];
    $stmt_c->close();
}

$motivos_label = [
    'perda'              => '📦 Perda',
    'dano'               => '💥 Dano',
    'obsolescencia'      => '🕰️ Obsolescência',
    'devolucao_locacao'  => '🔄 Devolução Locação',
    'descarte'           => '🗑️ Descarte',
    'doacao'             => '🎁 Doação',
    'roubo'              => '🔒 Roubo',
    'outro'              => '❓ Outro',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central de Requisições</title>
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: #f4f6f9; display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .sidebar .divider { border-top: 1px solid #4b545c; margin: 10px 0; }

        .main-content { flex: 1; padding: 20px; overflow-y: auto; }
        h1 { margin-top: 0; color: #333; }

        .msg-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:12px 16px; border-radius:4px; margin-bottom:16px; }
        .msg-warning { background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:12px 16px; border-radius:4px; margin-bottom:16px; }
        .msg-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:12px 16px; border-radius:4px; margin-bottom:16px; }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #dee2e6; }
        .tab-btn { padding: 10px 18px; border: none; background: none; cursor: pointer; font-size: 0.9em; color: #6c757d; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s; }
        .tab-btn:hover { color: #343a40; }
        .tab-btn.active { color: #007bff; border-bottom-color: #007bff; font-weight: 600; }
        .tab-count { background: #e9ecef; color: #495057; border-radius: 10px; padding: 1px 7px; font-size: 0.8em; margin-left: 5px; }
        .tab-count.pendente-badge { background: #ffc107; color: #333; }

        /* Cards de requisição */
        .req-list { display: flex; flex-direction: column; gap: 12px; }
        .req-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); border-left: 4px solid #dee2e6; overflow: hidden; }
        .req-card.status-pendente   { border-left-color: #ffc107; }
        .req-card.status-aprovada   { border-left-color: #28a745; }
        .req-card.status-rejeitada  { border-left-color: #dc3545; }
        .req-card.status-cancelada  { border-left-color: #6c757d; }

        .req-card-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 14px 16px 10px; border-bottom: 1px solid #f0f0f0; }
        .req-produto { font-size: 1.05em; font-weight: 700; color: #333; }
        .req-produto small { font-weight: normal; color: #888; font-size: 0.8em; margin-left: 6px; }
        .req-status { padding: 3px 10px; border-radius: 10px; font-size: 0.8em; font-weight: 600; }
        .badge-pendente  { background: #fff3cd; color: #856404; }
        .badge-aprovada  { background: #d4edda; color: #155724; }
        .badge-rejeitada { background: #f8d7da; color: #721c24; }
        .badge-cancelada { background: #e2e3e5; color: #383d41; }

        .req-card-body { padding: 12px 16px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
        .req-field { font-size: 0.88em; }
        .req-field-label { color: #888; font-size: 0.82em; margin-bottom: 2px; }
        .req-field-value { color: #333; font-weight: 500; }

        .req-card-footer { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; background: #f8f9fa; border-top: 1px solid #f0f0f0; }
        .req-meta { font-size: 0.82em; color: #888; }

        .btn-aprovar  { background: #28a745; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; cursor: pointer; font-size: 0.88em; font-weight: 600; }
        .btn-rejeitar { background: #dc3545; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; cursor: pointer; font-size: 0.88em; font-weight: 600; margin-left: 6px; }
        .btn-cancelar { background: #6c757d; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; cursor: pointer; font-size: 0.88em; }
        .btn-ver      { background: #17a2b8; color: #fff; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 0.88em; text-decoration: none; }

        .empty-state { text-align: center; padding: 60px 20px; color: #888; }
        .empty-state .icon { font-size: 3em; margin-bottom: 12px; }

        .descricao-text { background: #f8f9fa; border-radius: 4px; padding: 8px 12px; font-size: 0.88em; color: #555; margin: 0 16px 12px; line-height: 1.4; }

        /* Confirmação inline */
        .confirm-actions { display: none; gap: 8px; align-items: center; }
        .confirm-actions.visible { display: flex; }
        .confirm-text { font-size: 0.85em; color: #333; font-weight: 600; }

        .info-banner { background: #cce5ff; color: #004085; border: 1px solid #b8daff; padding: 10px 15px; border-radius: 4px; margin-bottom: 16px; font-size: 0.88em; }
    </style>
</head>
<body>

<aside class="sidebar">
    <h2>INTS Inventário</h2>
    <a href="../../../index.html">🏠 Home</a>
    <a href="../../produtos/index.php">📦 Produtos</a>
    <a href="../../movimentacoes/index.php">🔄 Movimentações</a>
    <div class="divider"></div>
    <a href="../index.php">⚙️ Administração</a>
    <a href="index.php" class="active">📋 Requisições</a>
</aside>

<main class="main-content">
    <h1>📋 Central de Requisições</h1>

    <?php if ($msg): ?>
        <div class="<?php echo $msgClass; ?>"><?php echo $msg; ?></div>
    <?php endif; ?>

    <div class="info-banner">
        Esta central lista as requisições de <strong>Baixa de Produtos</strong> para aprovação ou rejeição.
        Ao aprovar, o estoque é atualizado e o status do produto é alterado automaticamente.
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <?php foreach ($tabs_validas as $tab): 
            $labels = ['pendente'=>'⏳ Pendentes','aprovada'=>'✅ Aprovadas','rejeitada'=>'❌ Rejeitadas','cancelada'=>'🚫 Canceladas'];
        ?>
            <a href="?tab=<?php echo $tab; ?>" style="text-decoration:none;">
                <button class="tab-btn <?php echo $filtro_tab === $tab ? 'active' : ''; ?>">
                    <?php echo $labels[$tab]; ?>
                    <span class="tab-count <?php echo ($tab === 'pendente' && $counts[$tab] > 0) ? 'pendente-badge' : ''; ?>">
                        <?php echo $counts[$tab]; ?>
                    </span>
                </button>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($baixas)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>Nenhuma requisição com status <strong><?php echo $filtro_tab; ?></strong> encontrada.</p>
        </div>
    <?php else: ?>
        <div class="req-list">
            <?php foreach ($baixas as $b):
                $motivo_txt = $motivos_label[$b['motivo']] ?? $b['motivo'];
                $status_cls = 'badge-' . $b['status'];
                $card_cls   = 'status-' . $b['status'];
                $data_baixa_fmt = $b['data_baixa'] ? date('d/m/Y', strtotime($b['data_baixa'])) : '-';
                $data_criado_fmt= $b['data_criado'] ? date('d/m/Y H:i', strtotime($b['data_criado'])) : '-';
            ?>
            <div class="req-card <?php echo $card_cls; ?>">
                <div class="req-card-header">
                    <div class="req-produto">
                        <?php echo htmlspecialchars($b['produto_nome']); ?>
                        <?php if ($b['numero_patrimonio']): ?>
                            <small>#<?php echo htmlspecialchars($b['numero_patrimonio']); ?></small>
                        <?php endif; ?>
                        <?php if ($b['tipo_posse'] === 'locado'): ?>
                            <small style="background:#ffc107; padding:2px 6px; border-radius:3px; margin-left:4px;">Locado</small>
                        <?php endif; ?>
                    </div>
                    <span class="req-status <?php echo $status_cls; ?>">
                        <?php echo ucfirst($b['status']); ?>
                    </span>
                </div>

                <div class="req-card-body">
                    <div class="req-field">
                        <div class="req-field-label">ID Produto</div>
                        <div class="req-field-value">#<?php echo $b['produto_id']; ?></div>
                    </div>
                    <div class="req-field">
                        <div class="req-field-label">Quantidade</div>
                        <div class="req-field-value"><?php echo number_format($b['quantidade'], 0, ',', '.'); ?> un.</div>
                    </div>
                    <div class="req-field">
                        <div class="req-field-label">Motivo</div>
                        <div class="req-field-value"><?php echo $motivo_txt; ?></div>
                    </div>
                    <div class="req-field">
                        <div class="req-field-label">Data da Baixa</div>
                        <div class="req-field-value"><?php echo $data_baixa_fmt; ?></div>
                    </div>
                    <div class="req-field">
                        <div class="req-field-label">Local</div>
                        <div class="req-field-value"><?php echo $b['local_nome'] ? htmlspecialchars($b['local_nome']) : '—'; ?></div>
                    </div>
                    <?php if ($b['valor_contabil']): ?>
                    <div class="req-field">
                        <div class="req-field-label">Valor Contábil</div>
                        <div class="req-field-value">R$ <?php echo number_format($b['valor_contabil'], 2, ',', '.'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($b['aprovador_nome']): ?>
                    <div class="req-field">
                        <div class="req-field-label">Processado por</div>
                        <div class="req-field-value"><?php echo htmlspecialchars($b['aprovador_nome']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($b['descricao'])): ?>
                    <div class="descricao-text">
                        💬 <?php echo htmlspecialchars($b['descricao']); ?>
                    </div>
                <?php endif; ?>

                <div class="req-card-footer">
                    <div class="req-meta">
                        Criado em <?php echo $data_criado_fmt; ?>
                        <?php if ($b['criado_por_nome']): ?> por <strong><?php echo htmlspecialchars($b['criado_por_nome']); ?></strong><?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <a href="../../produtos/detalhes.php?id=<?php echo $b['produto_id']; ?>" target="_blank" class="btn-ver" title="Ver produto">👁️ Produto</a>

                        <?php if ($b['status'] === 'pendente'): ?>
                            <!-- Botões de ação com confirmação JS -->
                            <button class="btn-aprovar"
                                    onclick="confirmarAcao(<?php echo $b['baixa_id']; ?>, 'aprovar', '<?php echo addslashes($b['produto_nome']); ?>')">
                                ✅ Aprovar
                            </button>
                            <button class="btn-rejeitar"
                                    onclick="confirmarAcao(<?php echo $b['baixa_id']; ?>, 'rejeitar', '<?php echo addslashes($b['produto_nome']); ?>')">
                                ❌ Rejeitar
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Form oculto para submissão -->
<form id="formAcao" method="POST" style="display:none;">
    <input type="hidden" name="acao"     id="fa_acao">
    <input type="hidden" name="baixa_id" id="fa_baixa_id">
</form>

<script>
    function confirmarAcao(baixaId, acao, produtoNome) {
        const labels = {
            aprovar:  'APROVAR',
            rejeitar: 'REJEITAR',
            cancelar: 'CANCELAR'
        };
        const msgs = {
            aprovar:  `Tem certeza que deseja APROVAR a baixa de "${produtoNome}"?\n\nEsta ação deduzirá o estoque e poderá marcar o produto como Baixa Total.`,
            rejeitar: `Tem certeza que deseja REJEITAR a baixa de "${produtoNome}"?`,
            cancelar: `Tem certeza que deseja CANCELAR esta requisição?`
        };
        if (!confirm(msgs[acao])) return;
        document.getElementById('fa_acao').value    = acao;
        document.getElementById('fa_baixa_id').value= baixaId;
        document.getElementById('formAcao').submit();
    }
</script>
</body>
</html>