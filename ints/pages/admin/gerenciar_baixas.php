<?php
require_once '../../config/_protecao.php';

$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
if ($usuario_nivel !== 'admin' && $usuario_nivel !== 'admin_unidade') {
    die("Acesso negado.");
}

$usuario_id      = getUsuarioId();
$unidade_sessao  = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$is_admin_global = ($usuario_nivel === 'admin');

// IDs de locais da unidade do admin_unidade (para filtrar baixas por local)
$ids_locais_unidade = [];
if (!$is_admin_global && $unidade_sessao > 0) {
    if (function_exists('getIdsLocaisDaUnidade')) {
        $ids_locais_unidade = getIdsLocaisDaUnidade($conn, $unidade_sessao);
    } else {
        $ids_locais_unidade = [$unidade_sessao];
    }
}

// Detecta se está em modal
$is_modal = isset($_GET['modal']);

// ── FILTROS ──────────────────────────────────────────────────────────────────
$filtro_status      = $_GET['status']       ?? 'pendente';
$filtro_motivo      = $_GET['motivo']       ?? '';
$filtro_data_inicio = $_GET['data_inicio']  ?? '';
$filtro_data_fim    = $_GET['data_fim']     ?? '';
$filtro_busca       = $_GET['busca']        ?? '';

// ── QUERY ────────────────────────────────────────────────────────────────────
$where  = ["b.status = ?"];
$types  = "s";
$params = [$filtro_status];

if (!empty($filtro_motivo)) {
    $where[] = "b.motivo = ?";
    $types  .= "s";
    $params[] = $filtro_motivo;
}

if (!empty($filtro_data_inicio)) {
    $where[] = "b.data_baixa >= ?";
    $types  .= "s";
    $params[] = $filtro_data_inicio;
}

if (!empty($filtro_data_fim)) {
    $where[] = "b.data_baixa <= ?";
    $types  .= "s";
    $params[] = $filtro_data_fim;
}

if (!empty($filtro_busca)) {
    $where[] = "(p.nome LIKE ? OR p.numero_patrimonio LIKE ?)";
    $types  .= "ss";
    $term    = "%$filtro_busca%";
    $params[] = $term;
    $params[] = $term;
}

// ✅ FIX: admin_unidade só vê baixas de locais da sua unidade
if (!$is_admin_global && !empty($ids_locais_unidade)) {
    $idsStr  = implode(',', array_map('intval', $ids_locais_unidade));
    $where[] = "(b.local_id IN ($idsStr) OR b.local_id IS NULL)";
    // local_id NULL = sem local definido, exibimos para não esconder pendentes
}

$sql = "SELECT 
            b.*,
            p.nome           AS produto_nome,
            p.numero_patrimonio,
            l.nome           AS local_nome,
            u_cria.nome      AS criado_por_nome,
            u_aprov.nome     AS aprovador_nome,
            pt.numero_patrimonio AS patrimonio_numero
        FROM baixas b
        LEFT JOIN produtos    p       ON b.produto_id     = p.id
        LEFT JOIN locais      l       ON b.local_id       = l.id
        LEFT JOIN usuarios    u_cria  ON b.criado_por     = u_cria.id
        LEFT JOIN usuarios    u_aprov ON b.aprovador_id   = u_aprov.id
        LEFT JOIN patrimonios pt      ON b.patrimonio_id  = pt.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY b.data_criado DESC
        LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$baixas = [];
while ($row = $result->fetch_assoc()) {
    $baixas[] = $row;
}
$stmt->close();

// Contar pendentes para badge
$count_pendentes = 0;
if ($filtro_status !== 'pendente') {
    $sq = "SELECT COUNT(*) AS c FROM baixas b";
    if (!$is_admin_global && !empty($ids_locais_unidade)) {
        $idsStr2 = implode(',', array_map('intval', $ids_locais_unidade));
        $sq .= " WHERE b.status = 'pendente' AND (b.local_id IN ($idsStr2) OR b.local_id IS NULL)";
    } else {
        $sq .= " WHERE b.status = 'pendente'";
    }
    $r_cnt = $conn->query($sq);
    if ($r_cnt) $count_pendentes = (int)($r_cnt->fetch_assoc()['c'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Baixas</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            background: #f4f6f9;
            <?php echo $is_modal ? '' : 'display:flex; height:100vh; overflow:hidden;'; ?>
        }

        <?php if (!$is_modal): ?>
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; flex-shrink: 0; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; font-size: 0.9em; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .sidebar .sidebar-divider { border-top: 1px solid #4b545c; margin: 10px 0; }
        <?php endif; ?>

        .main-content { flex: 1; padding: 20px; overflow-y: auto; }

        .page-header {
            background: #fff;
            padding: 18px 24px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 { margin: 0; font-size: 1.4rem; color: #2c3e50; }

        /* Tabs de status */
        .tabs-bar {
            display: flex;
            gap: 6px;
            background: #fff;
            padding: 14px 20px 0;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 5px rgba(0,0,0,.05);
            border-bottom: 2px solid #e9ecef;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 8px 18px;
            border: none;
            background: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            text-decoration: none;
            border-radius: 4px 4px 0 0;
            transition: .15s;
            position: relative;
        }

        .tab-btn:hover { color: #343a40; background: #f8f9fa; }

        .tab-btn.active {
            color: #007bff;
            border-bottom-color: #007bff;
            background: none;
        }

        .badge-tab {
            display: inline-block;
            background: #dc3545;
            color: #fff;
            border-radius: 10px;
            font-size: 0.7rem;
            padding: 1px 6px;
            margin-left: 4px;
            vertical-align: middle;
            font-weight: 700;
        }

        /* Filtros */
        .filters-bar {
            background: #fff;
            padding: 16px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,.05);
            margin-bottom: 16px;
            border-radius: 0 0 8px 8px;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; min-width: 160px; }
        .filter-group label { font-size: 0.8rem; font-weight: 600; color: #555; margin-bottom: 4px; }
        .filter-group input,
        .filter-group select {
            padding: 7px 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 0.88rem;
            background: #fafafa;
        }

        .btn-filter {
            padding: 8px 20px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            align-self: flex-end;
        }

        .btn-filter:hover { background: #0056b3; }

        .btn-limpar {
            color: #666;
            font-size: 0.83rem;
            text-decoration: none;
            align-self: flex-end;
            padding: 8px 4px;
        }

        /* Tabela */
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,.05);
        }

        th {
            background: #f8f9fa;
            color: #555;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
        }

        td {
            padding: 12px 14px;
            font-size: 0.9rem;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        tr:hover td { background: #f8fbff; }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .badge-pendente   { background: #fff3cd; color: #856404; }
        .badge-aprovada   { background: #d4edda; color: #155724; }
        .badge-rejeitada  { background: #f8d7da; color: #721c24; }
        .badge-cancelada  { background: #e2e3e5; color: #383d41; }
        .badge-motivo     { background: #e8f4f8; color: #0c6481; }

        .btn {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }

        .btn-approve { background: #28a745; color: #fff; }
        .btn-approve:hover { background: #1e7e34; }
        .btn-reject  { background: #dc3545; color: #fff; }
        .btn-reject:hover  { background: #bd2130; }
        .btn-detail  { background: #6c757d; color: #fff; }
        .btn-detail:hover  { background: #545b62; }

        .actions { display: flex; gap: 5px; flex-wrap: wrap; }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #999;
        }

        /* Modal de detalhe / rejeição */
        .modal-overlay {
            position: fixed; top:0; left:0; width:100%; height:100%;
            background: rgba(0,0,0,.5); z-index: 999;
            display: none; justify-content: center; align-items: center;
        }

        .modal-overlay.active { display: flex; }

        .modal-box {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,.2);
            padding: 28px;
            max-width: 520px;
            width: 94%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-box h3 { margin: 0 0 16px; color: #2c3e50; }

        .modal-box textarea {
            width: 100%;
            min-height: 90px;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.92rem;
            box-sizing: border-box;
            resize: vertical;
        }

        .modal-box .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            justify-content: flex-end;
        }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .detail-item label { font-size: 0.76rem; font-weight: 700; color: #888; display: block; margin-bottom: 2px; }
        .detail-item span  { font-size: 0.9rem; color: #333; }

        .info-msg {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 0.88rem;
        }
    </style>
</head>
<body>

<?php if (!$is_modal): ?>
<aside class="sidebar">
    <h2>INTS Inventário</h2>
    <a href="../../index.php">🏠 Home</a>
    <a href="../produtos/index.php">📦 Produtos</a>
    <a href="../movimentacoes/index.php">🔄 Movimentações</a>
    <div class="sidebar-divider"></div>
    <?php if ($is_admin_global): ?>
    <a href="index.php">⚙️ Administração</a>
    <?php endif; ?>
    <a href="gerenciar_baixas.php" style="background:#495057; color:#fff;">📉 Baixas</a>
    <div style="margin-top:auto;">
        <a href="../../logout.php">🚪 Sair</a>
    </div>
</aside>
<?php endif; ?>

<div class="main-content">

    <div class="page-header">
        <h1>📉 Gerenciar Baixas de Estoque</h1>
        <?php if (!$is_admin_global && $unidade_sessao): ?>
            <small style="color:#888;">Exibindo baixas da sua unidade</small>
        <?php endif; ?>
    </div>

    <?php if ($count_pendentes > 0): ?>
    <div class="info-msg">
        ⚠️ Há <strong><?php echo $count_pendentes; ?></strong> baixa(s) pendente(s) aguardando aprovação.
        <a href="?status=pendente" style="color:#856404; font-weight:700; margin-left:8px;">Ver agora →</a>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs-bar">
        <a href="?status=pendente<?php echo $is_modal ? '&modal=1' : ''; ?>"
           class="tab-btn <?php echo $filtro_status === 'pendente' ? 'active' : ''; ?>">
            ⏳ Pendentes
        </a>
        <a href="?status=aprovada<?php echo $is_modal ? '&modal=1' : ''; ?>"
           class="tab-btn <?php echo $filtro_status === 'aprovada' ? 'active' : ''; ?>">
            ✅ Aprovadas
        </a>
        <a href="?status=rejeitada<?php echo $is_modal ? '&modal=1' : ''; ?>"
           class="tab-btn <?php echo $filtro_status === 'rejeitada' ? 'active' : ''; ?>">
            ❌ Rejeitadas
        </a>
        <a href="?status=cancelada<?php echo $is_modal ? '&modal=1' : ''; ?>"
           class="tab-btn <?php echo $filtro_status === 'cancelada' ? 'active' : ''; ?>">
            🚫 Canceladas
        </a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filters-bar">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>">
        <?php if ($is_modal): ?><input type="hidden" name="modal" value="1"><?php endif; ?>

        <div class="filter-group">
            <label>Produto / Patrimônio</label>
            <input type="text" name="busca" value="<?php echo htmlspecialchars($filtro_busca); ?>" placeholder="Buscar...">
        </div>

        <div class="filter-group">
            <label>Motivo</label>
            <select name="motivo">
                <option value="">Todos</option>
                <?php
                $motivos = ['perda'=>'Perda','dano'=>'Dano','obsolescencia'=>'Obsolescência',
                            'roubo'=>'Roubo','descarte'=>'Descarte','doacao'=>'Doação',
                            'devolucao_locacao'=>'Devolução Locação','outro'=>'Outro'];
                foreach ($motivos as $k => $v):
                ?>
                    <option value="<?php echo $k; ?>" <?php echo $filtro_motivo == $k ? 'selected' : ''; ?>>
                        <?php echo $v; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>De</label>
            <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filtro_data_inicio); ?>">
        </div>

        <div class="filter-group">
            <label>Até</label>
            <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filtro_data_fim); ?>">
        </div>

        <button type="submit" class="btn-filter">Filtrar</button>
        <?php if ($filtro_busca || $filtro_motivo || $filtro_data_inicio || $filtro_data_fim): ?>
            <a href="?status=<?php echo $filtro_status; ?>" class="btn-limpar">✕ Limpar</a>
        <?php endif; ?>
    </form>

    <?php if (!empty($baixas)): ?>
    <table>
        <thead>
            <tr>
                <th>Data Baixa</th>
                <th>Produto</th>
                <th>Qtd</th>
                <th>Motivo</th>
                <th>Local</th>
                <th>Solicitante</th>
                <?php if ($filtro_status !== 'pendente'): ?>
                <th>Aprovador</th>
                <?php endif; ?>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($baixas as $baixa): ?>
            <tr>
                <td><?php echo date('d/m/Y', strtotime($baixa['data_baixa'])); ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($baixa['produto_nome'] ?? '—'); ?></strong>
                    <?php
                    $pat = $baixa['patrimonio_numero'] ?? $baixa['numero_patrimonio'] ?? null;
                    if ($pat): ?>
                        <br><small style="color:#888;">Patrimônio: <?php echo htmlspecialchars($pat); ?></small>
                    <?php endif; ?>
                </td>
                <td><?php echo number_format((float)$baixa['quantidade'], 2, ',', '.'); ?></td>
                <td>
                    <span class="badge badge-motivo">
                        <?php echo htmlspecialchars($motivos[$baixa['motivo']] ?? ucfirst($baixa['motivo'])); ?>
                    </span>
                </td>
                <td><?php echo htmlspecialchars($baixa['local_nome'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($baixa['criado_por_nome'] ?? '—'); ?></td>
                <?php if ($filtro_status !== 'pendente'): ?>
                <td><?php echo htmlspecialchars($baixa['aprovador_nome'] ?? '—'); ?></td>
                <?php endif; ?>
                <td>
                    <?php
                    $badge_map = [
                        'pendente'  => 'badge-pendente',
                        'aprovada'  => 'badge-aprovada',
                        'rejeitada' => 'badge-rejeitada',
                        'cancelada' => 'badge-cancelada',
                    ];
                    $bc = $badge_map[$baixa['status']] ?? 'badge-pendente';
                    ?>
                    <span class="badge <?php echo $bc; ?>">
                        <?php echo ucfirst($baixa['status']); ?>
                    </span>
                </td>
                <td>
                    <div class="actions">
                        <?php if ($baixa['status'] === 'pendente'): ?>
                            <button class="btn btn-approve" onclick="aprovarBaixa(<?php echo $baixa['id']; ?>)">✓ Aprovar</button>
                            <button class="btn btn-reject"  onclick="abrirRejeicao(<?php echo $baixa['id']; ?>)">✗ Rejeitar</button>
                        <?php endif; ?>
                        <button class="btn btn-detail" onclick="verDetalhes(<?php echo $baixa['id']; ?>, <?php echo htmlspecialchars(json_encode($baixa)); ?>)">🔍</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php else: ?>
    <div class="empty-state">
        <div style="font-size:3rem; margin-bottom:10px;">📭</div>
        <p>Nenhuma baixa encontrada com os filtros selecionados.</p>
    </div>
    <?php endif; ?>

</div><!-- /main-content -->

<!-- Modal de Rejeição -->
<div class="modal-overlay" id="modalRejeicao">
    <div class="modal-box">
        <h3>❌ Rejeitar Baixa</h3>
        <p style="color:#666; font-size:0.9rem; margin:0 0 12px;">Informe o motivo da rejeição para o solicitante.</p>
        <textarea id="motivoRejeicao" placeholder="Descreva o motivo da rejeição..."></textarea>
        <div class="btn-row">
            <button class="btn btn-detail" onclick="fecharModal('modalRejeicao')">Cancelar</button>
            <button class="btn btn-reject" onclick="confirmarRejeicao()">Confirmar Rejeição</button>
        </div>
    </div>
</div>

<!-- Modal de Detalhes -->
<div class="modal-overlay" id="modalDetalhes">
    <div class="modal-box">
        <h3>🔍 Detalhes da Baixa</h3>
        <div id="detalhesConteudo"></div>
        <div class="btn-row">
            <button class="btn btn-detail" onclick="fecharModal('modalDetalhes')">Fechar</button>
        </div>
    </div>
</div>

<script>
let baixaIdAtual = 0;

function fecharModal(id) {
    document.getElementById(id).classList.remove('active');
}

async function aprovarBaixa(baixaId) {
    if (!confirm('Confirmar a aprovação desta baixa? O estoque será atualizado.')) return;

    const fd = new FormData();
    fd.append('action', 'aprovar');
    fd.append('baixa_id', baixaId);
    fd.append('aprovador_id', <?php echo $usuario_id; ?>);

    try {
        const res  = await fetch('../../api/baixas.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.sucesso) {
            alert('✅ Baixa aprovada com sucesso!');
            location.reload();
        } else {
            alert('Erro: ' + (data.mensagem || 'Falha ao aprovar.'));
        }
    } catch (e) {
        alert('Erro de comunicação com o servidor.');
    }
}

function abrirRejeicao(baixaId) {
    baixaIdAtual = baixaId;
    document.getElementById('motivoRejeicao').value = '';
    document.getElementById('modalRejeicao').classList.add('active');
}

async function confirmarRejeicao() {
    const motivo = document.getElementById('motivoRejeicao').value.trim();
    if (!motivo) { alert('Informe o motivo da rejeição.'); return; }

    const fd = new FormData();
    fd.append('action', 'rejeitar');
    fd.append('baixa_id', baixaIdAtual);
    fd.append('aprovador_id', <?php echo $usuario_id; ?>);
    fd.append('motivo_rejeicao', motivo);

    try {
        const res  = await fetch('../../api/baixas.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.sucesso) {
            alert('Baixa rejeitada.');
            location.reload();
        } else {
            alert('Erro: ' + (data.mensagem || 'Falha ao rejeitar.'));
        }
    } catch (e) {
        alert('Erro de comunicação com o servidor.');
    }
}

function verDetalhes(baixaId, dados) {
    const motivos = {
        perda:'Perda', dano:'Dano', obsolescencia:'Obsolescência',
        roubo:'Roubo', descarte:'Descarte', doacao:'Doação',
        devolucao_locacao:'Devolução Locação', outro:'Outro'
    };

    const fmt = (v) => v || '—';
    const fmtData = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('pt-BR') : '—';

    document.getElementById('detalhesConteudo').innerHTML = `
        <div class="detail-grid">
            <div class="detail-item">
                <label>PRODUTO</label>
                <span>${fmt(dados.produto_nome)}</span>
            </div>
            <div class="detail-item">
                <label>PATRIMÔNIO</label>
                <span>${fmt(dados.patrimonio_numero || dados.numero_patrimonio)}</span>
            </div>
            <div class="detail-item">
                <label>QUANTIDADE</label>
                <span>${parseFloat(dados.quantidade).toLocaleString('pt-BR', {minimumFractionDigits:2})}</span>
            </div>
            <div class="detail-item">
                <label>MOTIVO</label>
                <span>${motivos[dados.motivo] || dados.motivo}</span>
            </div>
            <div class="detail-item">
                <label>LOCAL</label>
                <span>${fmt(dados.local_nome)}</span>
            </div>
            <div class="detail-item">
                <label>DATA BAIXA</label>
                <span>${fmtData(dados.data_baixa)}</span>
            </div>
            <div class="detail-item">
                <label>SOLICITANTE</label>
                <span>${fmt(dados.criado_por_nome)}</span>
            </div>
            <div class="detail-item">
                <label>APROVADOR</label>
                <span>${fmt(dados.aprovador_nome)}</span>
            </div>
            ${dados.valor_contabil ? `
            <div class="detail-item">
                <label>VALOR CONTÁBIL</label>
                <span>R$ ${parseFloat(dados.valor_contabil).toLocaleString('pt-BR', {minimumFractionDigits:2})}</span>
            </div>` : ''}
        </div>
        <div style="margin-top:12px;">
            <label style="font-size:.76rem; font-weight:700; color:#888; display:block; margin-bottom:4px;">DESCRIÇÃO</label>
            <div style="background:#f8f9fa; border-radius:6px; padding:10px 14px; font-size:.9rem; color:#333; white-space:pre-wrap;">${fmt(dados.descricao)}</div>
        </div>
    `;
    document.getElementById('modalDetalhes').classList.add('active');
}

// Fechar modal ao clicar fora
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});
</script>

</body>
</html>