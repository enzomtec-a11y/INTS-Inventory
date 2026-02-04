<?php
require_once '../../config/_protecao.php';

// --- 1. DETECÇÃO DE USUÁRIO E UNIDADE ---
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    if (function_exists('getIdsLocaisDaUnidade')) {
        $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
    } else {
        $unidade_locais_ids = [$usuario_unidade];
    }
}

// --- 2. CAPTURA DE FILTROS ---
$busca_termo    = $_GET['busca_termo'] ?? ''; // Busca por Nome ou Patrimônio
$filtro_status  = $_GET['filtro_status'] ?? '';
$data_inicio    = $_GET['data_inicio'] ?? '';
$data_fim       = $_GET['data_fim'] ?? '';

// --- 3. MONTAGEM DA QUERY ---
// Removemos m.quantidade da visualização, focando no patrimônio
$sql = "
    SELECT 
        m.id, 
        m.data_movimentacao, 
        m.status,
        m.tipo_movimentacao,
        p.nome AS produto_nome,
        p.numero_patrimonio,
        u.nome AS usuario_nome,
        lo.nome AS origem_nome,
        ld.nome AS destino_nome,
        m.local_origem_id, 
        m.local_destino_id
    FROM movimentacoes m
    JOIN produtos p ON m.produto_id = p.id
    LEFT JOIN locais lo ON m.local_origem_id = lo.id
    LEFT JOIN locais ld ON m.local_destino_id = ld.id
    LEFT JOIN usuarios u ON m.usuario_id = u.id
    WHERE 1=1
";

$params = [];
$types = "";

// Filtro Híbrido: Nome do Produto OU Número do Patrimônio
if (!empty($busca_termo)) {
    $sql .= " AND (p.nome LIKE ? OR p.numero_patrimonio LIKE ?)";
    $term = "%" . $busca_termo . "%";
    $params[] = $term;
    $params[] = $term;
    $types .= "ss";
}

// Filtro por Status
if (!empty($filtro_status)) {
    $sql .= " AND m.status = ?";
    $params[] = $filtro_status;
    $types .= "s";
}

// Filtro por Data (Início)
if (!empty($data_inicio)) {
    $sql .= " AND m.data_movimentacao >= ?";
    $params[] = $data_inicio . " 00:00:00";
    $types .= "s";
}

// Filtro por Data (Fim)
if (!empty($data_fim)) {
    $sql .= " AND m.data_movimentacao <= ?";
    $params[] = $data_fim . " 23:59:59";
    $types .= "s";
}

// RESTRIÇÃO DE UNIDADE (Admin de Unidade)
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql .= " AND (m.local_origem_id IN ($idsStr) OR m.local_destino_id IN ($idsStr)) ";
}

$sql .= " ORDER BY m.data_movimentacao DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$movimentacoes = [];
while ($row = $result->fetch_assoc()) {
    $movimentacoes[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movimentações - Histórico</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* Estilos Globais e Layout (Idêntico ao de Produtos) */
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background-color: #f4f6f9; display: flex; height: 100vh; overflow: hidden; }
        
        /* Sidebar */
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; }
        .sidebar a:hover { background: #495057; color: white; }
        
        .main-content { flex: 1; padding: 20px; overflow-y: auto; position: relative; }

        /* Top Bar */
        .top-bar { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        
        .action-buttons { display: flex; gap: 10px; }
        .btn-novo { background: #28a745; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; cursor: pointer; border: none; font-size: 0.9rem; }
        .btn-check { background: #17a2b8; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; cursor: pointer; border: none; font-size: 0.9rem; }

        /* Filtros */
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end; }
        .filter-grid input, .filter-grid select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;}
        .filter-grid label { font-size: 0.85em; font-weight: bold; color: #555; display: block; margin-bottom: 2px; }
        .btn-filter { background: #007bff; color: white; border: none; padding: 9px 15px; border-radius: 4px; cursor: pointer; height: 35px; }

        /* Tabela */
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th { background-color: #343a40; color: #fff; padding: 12px; text-align: left; font-size: 0.9em; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 0.9em; vertical-align: middle; }
        tr:hover { background-color: #f1f1f1; }

        /* Badges e Estilos Específicos */
        .patrimonio-badge { background: #6f42c1; color: white; padding: 3px 6px; border-radius: 1px; font-size: 0.9em; font-family: monospace; letter-spacing: 0.9px; }
        
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; text-transform: uppercase; }
        .status-pendente { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status-em_transito { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .status-finalizado { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-cancelado { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .local-arrow { color: #888; margin: 0 8px; font-weight: bold; }
        .no-data { text-align: center; padding: 40px; color: #888; font-style: italic; }

        /* Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1000;
            display: none; justify-content: center; align-items: center;
        }
        .modal-overlay.active { display: flex; }
        
        .modal-window {
            background: #fff; width: 90%; max-width: 1000px; height: 90%;
            border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            display: flex; flex-direction: column; overflow: hidden;
            animation: slideIn 0.3s ease;
        }
        .modal-header {
            padding: 15px; background: #f8f9fa; border-bottom: 1px solid #ddd;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-title { font-weight: bold; font-size: 1.1em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; }
        .modal-content-frame { flex: 1; border: none; width: 100%; background: #fff; }

        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <h2>INTS Inventário</h2>
        <a href="../../index.html">🏠 Home</a>
        <a href="../produtos/index.php">📦 Produtos</a>
        <a href="index.php" style="background:#495057; color:#fff;">🔄 Movimentações</a>
        <div style="border-top:1px solid #4b545c; margin:10px 0;"></div>
        <a href="../admin/index.php">⚙️ Administração</a>
    </aside>

    <main class="main-content">
        <div class="top-bar">
            <div class="top-header">
                <h2 style="margin:0; color:#333;">Movimentações de Estoque</h2>
                <div class="action-buttons">
                    <button onclick="abrirModal('gerenciar.php', 'Aprovar / Receber')" class="btn-check">📋 Gerenciar Aprovações</button>
                    <button onclick="abrirModal('solicitar.php', 'Nova Solicitação')" class="btn-novo">+ Nova Solicitação</button>
                </div>
            </div>

            <form method="GET" class="filter-grid">
                <div>
                    <label>Produto / Patrimônio</label>
                    <input type="text" name="busca_termo" value="<?php echo htmlspecialchars($busca_termo); ?>" placeholder="Nome ou Nº Patrimônio...">
                </div>
                
                <div>
                    <label>Status</label>
                    <select name="filtro_status">
                        <option value="">Todos</option>
                        <option value="pendente" <?php echo ($filtro_status == 'pendente') ? 'selected' : ''; ?>>Pendente</option>
                        <option value="em_transito" <?php echo ($filtro_status == 'em_transito') ? 'selected' : ''; ?>>Em Trânsito</option>
                        <option value="finalizado" <?php echo ($filtro_status == 'finalizado') ? 'selected' : ''; ?>>Finalizado</option>
                        <option value="cancelado" <?php echo ($filtro_status == 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>

                <div>
                    <label>De (Data)</label>
                    <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>">
                </div>

                <div>
                    <label>Até (Data)</label>
                    <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>">
                </div>

                <div>
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-filter">Filtrar</button>
                    <?php if ($busca_termo || $filtro_status || $data_inicio): ?>
                        <a href="index.php" style="color:#666; font-size:0.85em; margin-left:10px; text-decoration:none;">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 140px;">Data</th>
                    <th style="width: 120px;">Patrimônio</th>
                    <th>Produto</th>
                    <th>Fluxo (Origem → Destino)</th>
                    <th>Solicitante</th>
                    <th style="width: 120px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movimentacoes)): ?>
                    <tr>
                        <td colspan="6" class="no-data">
                            Nenhuma movimentação encontrada com os filtros atuais.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movimentacoes as $mov): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?></td>
                            
                            <td>
                                <?php if (!empty($mov['numero_patrimonio'])): ?>
                                    <span class="patrimonio-badge"><?php echo htmlspecialchars($mov['numero_patrimonio']); ?></span>
                                <?php else: ?>
                                    <span style="color:#ccc;">S/N</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong><?php echo htmlspecialchars($mov['produto_nome']); ?></strong>
                                <?php if($mov['tipo_movimentacao'] == 'AJUSTE') echo '<br><small style="color:blue">Ajuste de Sistema</small>'; ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($mov['origem_nome']); ?> 
                                <span class="local-arrow">>></span> 
                                <?php echo htmlspecialchars($mov['destino_nome']); ?>
                            </td>

                            <td><?php echo htmlspecialchars($mov['usuario_nome']); ?></td>
                            
                            <td>
                                <span class="status-badge status-<?php echo $mov['status']; ?>">
                                    <?php 
                                    switch($mov['status']) {
                                        case 'pendente': echo 'Pendente'; break;
                                        case 'em_transito': echo 'Em Trânsito'; break;
                                        case 'finalizado': echo 'Concluído'; break;
                                        default: echo ucfirst($mov['status']); 
                                    }
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </main>

    <div id="modalContainer" class="modal-overlay">
        <div class="modal-window">
            <div class="modal-header">
                <span id="modalTitle" class="modal-title">Título</span>
                <button onclick="fecharModal(false)" class="modal-close">&times;</button>
            </div>
            <iframe id="modalFrame" class="modal-content-frame" src=""></iframe>
        </div>
    </div>

    <script>
        function abrirModal(url, titulo) {
            document.getElementById('modalTitle').innerText = titulo;
            let separator = url.includes('?') ? '&' : '?';
            document.getElementById('modalFrame').src = url + separator + "modal=1";
            document.getElementById('modalContainer').classList.add('active');
        }

        function fecharModal(recaregar = false) {
            document.getElementById('modalContainer').classList.remove('active');
            document.getElementById('modalFrame').src = "";
            if(recaregar) {
                window.location.reload();
            }
        }

        window.fecharModalDoFilho = function(recarregar) {
            fecharModal(recarregar);
        }
    </script>
</body>
</html>