<?php
require_once '../../config/_protecao.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$produto_id = (int)$_GET['id'];
$produto = null;
$atributos = [];
$arquivos = [];
$estoque = [];
$componentes = [];
$movimentacoes = [];
$patrimonios = [];
$baixas = [];
$reservas = [];
$kits_onde_usado = [];

// Detecta usuário/unidade
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// Validação de acesso
function produtoPertenceUnidade($conn, $produto_id, $locais_ids) {
    if (empty($locais_ids)) return false;
    $idsStr = implode(',', array_map('intval', $locais_ids));
    $sql = "SELECT 1 FROM estoques WHERE produto_id = ? AND local_id IN ($idsStr) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && $r->num_rows > 0) { $stmt->close(); return true; }
    $stmt->close();
    $sql2 = "SELECT 1 FROM patrimonios WHERE produto_id = ? AND local_id IN ($idsStr) LIMIT 1";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("i", $produto_id);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    $stmt2->close();
    return ($r2 && $r2->num_rows > 0);
}

if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    if (!produtoPertenceUnidade($conn, $produto_id, $unidade_locais_ids)) {
        die("Acesso negado: produto não pertence à sua unidade.");
    }
}

// BUSCAR DADOS BÁSICOS
$sql_base = "SELECT p.*, c.nome as categoria_nome 
             FROM produtos p 
             JOIN categorias c ON p.categoria_id = c.id 
             WHERE p.id = ?";
$stmt = $conn->prepare($sql_base);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$produto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$produto) {
    die("Produto não encontrado.");
}

// BUSCAR ATRIBUTOS (EAV)
$sql_attr = "
    SELECT ad.nome, ad.tipo,
    COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) as valor
    FROM atributos_valor av
    JOIN atributos_definicao ad ON av.atributo_id = ad.id
    WHERE av.produto_id = ?
";
$stmt = $conn->prepare($sql_attr);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_attr = $stmt->get_result();
while ($row = $res_attr->fetch_assoc()) {
    if ($row['tipo'] === 'booleano') {
        $row['valor'] = ($row['valor'] == '1') ? 'Sim' : 'Não';
    }
    $atributos[] = $row;
}
$stmt->close();

// BUSCAR ARQUIVOS
$sql_arq = "SELECT * FROM arquivos WHERE produto_id = ? ORDER BY data_criado DESC";
$stmt = $conn->prepare($sql_arq);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_arq = $stmt->get_result();
while ($row = $res_arq->fetch_assoc()) {
    $arquivos[] = $row;
}
$stmt->close();

// BUSCAR ESTOQUE
$mapa_locais = [];
if (function_exists('getLocaisFormatados')) {
    $mapa_locais = getLocaisFormatados($conn, false);
}

$sql_est = "SELECT local_id, quantidade, data_atualizado FROM estoques WHERE produto_id = ? AND quantidade > 0 ORDER BY quantidade DESC";
$stmt = $conn->prepare($sql_est);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_est = $stmt->get_result();
while ($row = $res_est->fetch_assoc()) {
    $nome_local = $mapa_locais[$row['local_id']] ?? "Local ID: " . $row['local_id'];
    $row['nome_local'] = $nome_local;
    $estoque[] = $row;
}
$stmt->close();

// BUSCAR COMPOSIÇÃO (BOM - Se for um Kit)
$sql_comp = "
    SELECT p.id as produto_id, p.nome, pr.quantidade, pr.tipo_relacao 
    FROM produto_relacionamento pr
    JOIN produtos p ON pr.subproduto_id = p.id
    WHERE pr.produto_principal_id = ?
    ORDER BY p.nome
";
$stmt = $conn->prepare($sql_comp);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_comp = $stmt->get_result();
while ($row = $res_comp->fetch_assoc()) {
    $componentes[] = $row;
}
$stmt->close();

// BUSCAR KITS ONDE É USADO (Reverso)
$sql_usado = "
    SELECT p.id, p.nome, pr.quantidade, pr.tipo_relacao
    FROM produto_relacionamento pr
    JOIN produtos p ON pr.produto_principal_id = p.id
    WHERE pr.subproduto_id = ?
    ORDER BY p.nome
";
$stmt = $conn->prepare($sql_usado);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_usado = $stmt->get_result();
while ($row = $res_usado->fetch_assoc()) {
    $kits_onde_usado[] = $row;
}
$stmt->close();

// BUSCAR MOVIMENTAÇÕES (Histórico)
$sql_mov = "
    SELECT m.*, 
           lo.nome AS origem_nome, 
           ld.nome AS destino_nome,
           u.nome AS usuario_nome
    FROM movimentacoes m
    LEFT JOIN locais lo ON m.local_origem_id = lo.id
    LEFT JOIN locais ld ON m.local_destino_id = ld.id
    LEFT JOIN usuarios u ON m.usuario_id = u.id
    WHERE m.produto_id = ?
    ORDER BY m.data_movimentacao DESC
    LIMIT 50
";
$stmt = $conn->prepare($sql_mov);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_mov = $stmt->get_result();
while ($row = $res_mov->fetch_assoc()) {
    $movimentacoes[] = $row;
}
$stmt->close();

// BUSCAR PATRIMÔNIOS (se houver)
$sql_patr = "
    SELECT pt.*, l.nome AS local_nome
    FROM patrimonios pt
    LEFT JOIN locais l ON pt.local_id = l.id
    WHERE pt.produto_id = ?
    ORDER BY pt.data_criado DESC
";
$stmt = $conn->prepare($sql_patr);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_patr = $stmt->get_result();
while ($row = $res_patr->fetch_assoc()) {
    $patrimonios[] = $row;
}
$stmt->close();

// BUSCAR BAIXAS
$sql_baixas = "
    SELECT b.*, 
           u.nome AS criado_por_nome,
           a.nome AS aprovador_nome,
           l.nome AS local_nome
    FROM baixas b
    LEFT JOIN usuarios u ON b.criado_por = u.id
    LEFT JOIN usuarios a ON b.aprovador_id = a.id
    LEFT JOIN locais l ON b.local_id = l.id
    WHERE b.produto_id = ?
    ORDER BY b.data_criado DESC
";
$stmt = $conn->prepare($sql_baixas);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_baixas = $stmt->get_result();
while ($row = $res_baixas->fetch_assoc()) {
    $baixas[] = $row;
}
$stmt->close();

// BUSCAR RESERVAS ATIVAS
$sql_reservas = "
    SELECT r.*, l.nome AS local_nome
    FROM reservas r
    LEFT JOIN locais l ON r.local_id = l.id
    WHERE r.produto_id = ?
    ORDER BY r.data_criado DESC
    LIMIT 20
";
$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_reservas = $stmt->get_result();
while ($row = $res_reservas->fetch_assoc()) {
    $reservas[] = $row;
}
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Prontuário: <?php echo htmlspecialchars($produto['nome']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #f5f5f5;
            line-height: 1.6;
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 { 
            font-size: 2em;
            margin-bottom: 10px;
        }
        
        .header-meta {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 0.9em;
        }
        
        .btn-primary { background: #fff; color: #667eea; }
        .btn-primary:hover { background: #f0f0f0; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #e53e3e; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-warning:hover { background: #dd6b20; }
        
        /* Tabs de navegação */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 12px 24px;
            background: #fff;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #4a5568;
            transition: all 0.3s;
            border-radius: 8px 8px 0 0;
            position: relative;
        }
        
        .tab:hover {
            background: #f7fafc;
            color: #2d3748;
        }
        
        .tab.active {
            background: #667eea;
            color: white;
        }
        
        .tab-badge {
            background: #fc8181;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            margin-left: 5px;
        }
        
        /* Conteúdo das tabs */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card-title {
            font-size: 1.3em;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        /* Grid de informações */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .info-label {
            font-size: 0.85em;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 1.1em;
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Badges de status */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        .badge-danger { background: #fed7d7; color: #742a2a; }
        .badge-info { background: #bee3f8; color: #2c5282; }
        .badge-secondary { background: #e2e8f0; color: #2d3748; }
        
        /* Tabelas */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .data-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-table tr:hover {
            background: #f7fafc;
        }
        
        /* Timeline de histórico */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #e2e8f0;
        }
        
        .timeline-content {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }
        
        .timeline-date {
            font-size: 0.85em;
            color: #718096;
            margin-bottom: 5px;
        }
        
        .timeline-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .timeline-desc {
            font-size: 0.9em;
            color: #4a5568;
        }
        
        /* Grid de arquivos */
        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .file-card {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .file-card:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .file-icon {
            font-size: 3em;
            margin-bottom: 10px;
        }
        
        .file-card img {
            max-width: 100%;
            max-height: 100px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .file-name {
            font-size: 0.85em;
            color: #4a5568;
            margin-bottom: 5px;
        }
        
        .file-link {
            color: #667eea;
            text-decoration: none;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .file-link:hover {
            text-decoration: underline;
        }
        
        /* Alertas */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-info { background: #bee3f8; color: #2c5282; border-left: 4px solid #3182ce; }
        .alert-warning { background: #feebc8; color: #7c2d12; border-left: 4px solid #dd6b20; }
        .alert-danger { background: #fed7d7; color: #742a2a; border-left: 4px solid #e53e3e; }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #a0aec0;
        }
        
        .empty-state-icon {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Responsividade */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="header-content">
            <div>
                <h1><?php echo htmlspecialchars($produto['nome']); ?></h1>
                <div class="header-meta">
                    <span>ID: <?php echo $produto['id']; ?></span>
                    <?php if ($produto['numero_patrimonio']): ?>
                        | <span>Patrimônio: <strong><?php echo htmlspecialchars($produto['numero_patrimonio']); ?></strong></span>
                    <?php endif; ?>
                    | <span>Criado em: <?php echo date('d/m/Y', strtotime($produto['data_criado'])); ?></span>
                </div>
            </div>
            <div class="header-actions">
                <button onclick="abrirModalBaixa()" class="btn btn-warning">📉 Registrar Baixa</button>
                <a href="editar.php?id=<?php echo $produto['id']; ?>" class="btn btn-success">✏️ Editar</a>
            </div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab active" onclick="abrirTab('geral')">📋 Informações Gerais</button>
        <button class="tab" onclick="abrirTab('estoque')">📦 Estoque & Locais</button>
        <button class="tab" onclick="abrirTab('composicao')">🔧 Composição (BOM)</button>
        <button class="tab" onclick="abrirTab('movimentacoes')">
            🔄 Movimentações
            <?php if(count($movimentacoes) > 0): ?>
                <span class="tab-badge"><?php echo count($movimentacoes); ?></span>
            <?php endif; ?>
        </button>
        <button class="tab" onclick="abrirTab('patrimonios')">
            🏷️ Patrimônios
            <?php if(count($patrimonios) > 0): ?>
                <span class="tab-badge"><?php echo count($patrimonios); ?></span>
            <?php endif; ?>
        </button>
        <button class="tab" onclick="abrirTab('baixas')">
            📉 Baixas
            <?php if(count($baixas) > 0): ?>
                <span class="tab-badge"><?php echo count($baixas); ?></span>
            <?php endif; ?>
        </button>
        <button class="tab" onclick="abrirTab('arquivos')">
            📎 Documentos
            <?php if(count($arquivos) > 0): ?>
                <span class="tab-badge"><?php echo count($arquivos); ?></span>
            <?php endif; ?>
        </button>
    </div>
    
    <!-- TAB: Informações Gerais -->
    <div id="tab-geral" class="tab-content active">
        <div class="card">
            <h2 class="card-title">Dados Cadastrais</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Categoria</div>
                    <div class="info-value"><?php echo htmlspecialchars($produto['categoria_nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <?php
                        $status = $produto['status_produto'] ?? 'ativo';
                        $badge_class = 'badge-success';
                        $status_text = 'Ativo';
                        
                        switch($status) {
                            case 'baixa_parcial':
                                $badge_class = 'badge-warning';
                                $status_text = 'Baixa Parcial';
                                break;
                            case 'baixa_total':
                                $badge_class = 'badge-danger';
                                $status_text = 'Baixa Total';
                                break;
                            case 'inativo':
                                $badge_class = 'badge-secondary';
                                $status_text = 'Inativo';
                                break;
                        }
                        ?>
                        <span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Tipo de Posse</div>
                    <div class="info-value">
                        <?php 
                        echo ($produto['tipo_posse'] == 'locado') ? 
                            '<span class="badge badge-warning">Locado</span>' : 
                            '<span class="badge badge-info">Próprio</span>';
                        ?>
                    </div>
                </div>
                <?php if ($produto['tipo_posse'] == 'locado' && !empty($produto['locador_nome'])): ?>
                <div class="info-item">
                    <div class="info-label">Locador</div>
                    <div class="info-value"><?php echo htmlspecialchars($produto['locador_nome']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($produto['tipo_posse'] == 'locado' && !empty($produto['locacao_contrato'])): ?>
                <div class="info-item">
                    <div class="info-label">Contrato de Locação</div>
                    <div class="info-value"><?php echo htmlspecialchars($produto['locacao_contrato']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-label">Controla Estoque</div>
                    <div class="info-value">
                        <?php echo ($produto['controla_estoque_proprio'] == 1) ? 'Sim' : 'Não (Kit/Virtual)'; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($produto['descricao'])): ?>
            <div style="margin-top: 25px;">
                <div class="info-label">Descrição</div>
                <div style="margin-top: 10px; padding: 15px; background: #f7fafc; border-radius: 8px; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($produto['descricao'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($atributos)): ?>
        <div class="card">
            <h2 class="card-title">Especificações Técnicas</h2>
            <div class="info-grid">
                <?php foreach ($atributos as $attr): ?>
                <div class="info-item">
                    <div class="info-label"><?php echo htmlspecialchars($attr['nome']); ?></div>
                    <div class="info-value"><?php echo htmlspecialchars($attr['valor']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB: Estoque & Locais -->
    <div id="tab-estoque" class="tab-content">
        <div class="card">
            <h2 class="card-title">Posição de Estoque por Local</h2>
            <?php if (!empty($estoque)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Localização</th>
                            <th style="text-align: right;">Quantidade</th>
                            <th>Última Atualização</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total = 0;
                        foreach ($estoque as $est): 
                            $total += $est['quantidade'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($est['nome_local']); ?></td>
                            <td style="text-align: right; font-weight: 600; color: #48bb78;">
                                <?php echo number_format($est['quantidade'], 2, ',', '.'); ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($est['data_atualizado'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f7fafc; font-weight: 600;">
                            <td>TOTAL GERAL</td>
                            <td style="text-align: right; color: #2d3748;">
                                <?php echo number_format($total, 2, ',', '.'); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>Sem saldo em estoque no momento.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($reservas)): ?>
        <div class="card">
            <h2 class="card-title">Reservas Ativas</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Local</th>
                        <th>Quantidade</th>
                        <th>Tipo</th>
                        <th>Referência</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservas as $res): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($res['local_nome'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($res['quantidade'], 2, ',', '.'); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($res['referencia_tipo'] ?? '—'); ?></span></td>
                        <td><?php echo htmlspecialchars($res['referencia_batch'] ?? $res['referencia_id'] ?? '—'); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($res['data_criado'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB: Composição (BOM) -->
    <div id="tab-composicao" class="tab-content">
        <?php if (!empty($componentes)): ?>
        <div class="card">
            <h2 class="card-title">Componentes deste Produto (BOM)</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>Quantidade por Unidade</th>
                        <th>Tipo de Relação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($componentes as $comp): ?>
                    <tr>
                        <td>
                            <a href="detalhes.php?id=<?php echo $comp['produto_id']; ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                                <?php echo htmlspecialchars($comp['nome']); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($comp['quantidade'], 2, ',', '.'); ?></td>
                        <td><span class="badge badge-secondary"><?php echo ucfirst($comp['tipo_relacao']); ?></span></td>
                        <td>
                            <a href="detalhes.php?id=<?php echo $comp['produto_id']; ?>" class="btn" style="padding: 5px 10px; font-size: 0.85em;">
                                Ver Detalhes
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($kits_onde_usado)): ?>
        <div class="card">
            <h2 class="card-title">Utilizado nos seguintes Kits/Produtos</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kit/Produto</th>
                        <th>Quantidade Utilizada</th>
                        <th>Tipo</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kits_onde_usado as $kit): ?>
                    <tr>
                        <td>
                            <a href="detalhes.php?id=<?php echo $kit['id']; ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                                <?php echo htmlspecialchars($kit['nome']); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($kit['quantidade'], 2, ',', '.'); ?></td>
                        <td><span class="badge badge-info"><?php echo ucfirst($kit['tipo_relacao']); ?></span></td>
                        <td>
                            <a href="detalhes.php?id=<?php echo $kit['id']; ?>" class="btn" style="padding: 5px 10px; font-size: 0.85em;">
                                Ver Kit
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (empty($componentes) && empty($kits_onde_usado)): ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon">🔧</div>
                <p>Este produto não possui composição e não é usado em nenhum kit.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- TAB: Movimentações -->
    <div id="tab-movimentacoes" class="tab-content">
        <div class="card">
            <h2 class="card-title">Histórico de Movimentações</h2>
            <?php if (!empty($movimentacoes)): ?>
                <div class="timeline">
                    <?php foreach ($movimentacoes as $mov): ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <div class="timeline-date">
                                <?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?>
                            </div>
                            <div class="timeline-title">
                                <?php 
                                $tipo_mov = $mov['tipo_movimentacao'] ?? 'TRANSFERENCIA';
                                $status_mov = $mov['status'];
                                
                                $badge_class = 'badge-info';
                                switch($status_mov) {
                                    case 'finalizado':
                                        $badge_class = 'badge-success';
                                        break;
                                    case 'em_transito':
                                        $badge_class = 'badge-warning';
                                        break;
                                    case 'cancelado':
                                        $badge_class = 'badge-danger';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($status_mov); ?></span>
                                <?php echo htmlspecialchars($tipo_mov); ?>
                            </div>
                            <div class="timeline-desc">
                                <strong>De:</strong> <?php echo htmlspecialchars($mov['origem_nome'] ?? 'N/A'); ?> 
                                → <strong>Para:</strong> <?php echo htmlspecialchars($mov['destino_nome'] ?? 'N/A'); ?><br>
                                <strong>Quantidade:</strong> <?php echo $mov['quantidade']; ?> | 
                                <strong>Responsável:</strong> <?php echo htmlspecialchars($mov['usuario_nome'] ?? 'Sistema'); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🔄</div>
                    <p>Nenhuma movimentação registrada para este produto.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- TAB: Patrimônios -->
    <div id="tab-patrimonios" class="tab-content">
        <div class="card">
            <h2 class="card-title">Patrimônios Individualizados</h2>
            <?php if (!empty($patrimonios)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nº Patrimônio</th>
                            <th>Número de Série</th>
                            <th>Local</th>
                            <th>Status</th>
                            <th>Data Aquisição</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patrimonios as $patr): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($patr['numero_patrimonio'] ?? '—'); ?></strong></td>
                            <td><?php echo htmlspecialchars($patr['numero_serie'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($patr['local_nome'] ?? 'N/A'); ?></td>
                            <td>
                                <?php
                                $status_patr = $patr['status'];
                                $badge_class = 'badge-success';
                                
                                switch($status_patr) {
                                    case 'emprestado':
                                        $badge_class = 'badge-warning';
                                        break;
                                    case 'manutencao':
                                        $badge_class = 'badge-info';
                                        break;
                                    case 'desativado':
                                        $badge_class = 'badge-danger';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($status_patr); ?></span>
                            </td>
                            <td><?php echo $patr['data_aquisicao'] ? date('d/m/Y', strtotime($patr['data_aquisicao'])) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🏷️</div>
                    <p>Este produto não possui patrimônios individualizados cadastrados.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- TAB: Baixas -->
    <div id="tab-baixas" class="tab-content">
        <div class="card">
            <h2 class="card-title">Histórico de Baixas</h2>
            <?php if (!empty($baixas)): ?>
                <div class="timeline">
                    <?php foreach ($baixas as $baixa): ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <div class="timeline-date">
                                <?php echo date('d/m/Y', strtotime($baixa['data_baixa'])); ?>
                            </div>
                            <div class="timeline-title">
                                <?php
                                $status_baixa = $baixa['status'];
                                $badge_class = 'badge-warning';
                                
                                switch($status_baixa) {
                                    case 'aprovada':
                                        $badge_class = 'badge-success';
                                        break;
                                    case 'rejeitada':
                                        $badge_class = 'badge-danger';
                                        break;
                                    case 'cancelada':
                                        $badge_class = 'badge-secondary';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($status_baixa); ?></span>
                                Baixa por: <strong><?php echo htmlspecialchars(ucfirst($baixa['motivo'])); ?></strong>
                            </div>
                            <div class="timeline-desc">
                                <strong>Quantidade:</strong> <?php echo number_format($baixa['quantidade'], 2, ',', '.'); ?><br>
                                <?php if ($baixa['local_nome']): ?>
                                <strong>Local:</strong> <?php echo htmlspecialchars($baixa['local_nome']); ?><br>
                                <?php endif; ?>
                                <strong>Solicitante:</strong> <?php echo htmlspecialchars($baixa['criado_por_nome'] ?? 'Sistema'); ?><br>
                                <?php if ($baixa['aprovador_nome']): ?>
                                <strong>Aprovador:</strong> <?php echo htmlspecialchars($baixa['aprovador_nome']); ?><br>
                                <?php endif; ?>
                                <?php if ($baixa['valor_contabil']): ?>
                                <strong>Valor Contábil:</strong> R$ <?php echo number_format($baixa['valor_contabil'], 2, ',', '.'); ?><br>
                                <?php endif; ?>
                                <strong>Descrição:</strong> <?php echo nl2br(htmlspecialchars($baixa['descricao'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📉</div>
                    <p>Nenhuma baixa registrada para este produto.</p>
                    <button onclick="abrirModalBaixa()" class="btn btn-warning" style="margin-top: 15px;">
                        Registrar Nova Baixa
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- TAB: Documentos -->
    <div id="tab-arquivos" class="tab-content">
        <div class="card">
            <h2 class="card-title">Documentos e Arquivos Anexados</h2>
            <?php if (!empty($arquivos)): ?>
                <div class="files-grid">
                    <?php foreach ($arquivos as $arq): 
                        $caminho_relativo = "../../" . $arq['caminho'];
                        $tipo_arquivo = $arq['tipo'];
                        
                        $icone = '📄';
                        switch($tipo_arquivo) {
                            case 'imagem': $icone = '🖼️'; break;
                            case 'nota_fiscal': $icone = '🧾'; break;
                            case 'manual': $icone = '📖'; break;
                            case 'outro': $icone = '📎'; break;
                        }
                    ?>
                        <div class="file-card">
                            <?php if ($tipo_arquivo == 'imagem'): ?>
                                <a href="<?php echo htmlspecialchars($caminho_relativo); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($caminho_relativo); ?>" alt="Imagem">
                                </a>
                            <?php else: ?>
                                <div class="file-icon"><?php echo $icone; ?></div>
                            <?php endif; ?>
                            
                            <div class="file-name">
                                <strong><?php echo ucfirst(str_replace('_', ' ', $tipo_arquivo)); ?></strong>
                            </div>
                            <a href="<?php echo htmlspecialchars($caminho_relativo); ?>" target="_blank" class="file-link">
                                Visualizar / Baixar
                            </a>
                            <div style="font-size: 0.75em; color: #a0aec0; margin-top: 5px;">
                                <?php echo date('d/m/Y', strtotime($arq['data_criado'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📎</div>
                    <p>Nenhum documento anexado a este produto.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de Baixa -->
<div id="modalBaixa" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: white; padding: 30px; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-bottom: 20px;">Registrar Baixa de Produto</h2>
        <form id="formBaixa" method="POST" action="../../api/baixas.php">
            <input type="hidden" name="action" value="criar">
            <input type="hidden" name="produto_id" value="<?php echo $produto_id; ?>">
            <input type="hidden" name="criado_por" value="<?php echo getUsuarioId(); ?>">
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Motivo da Baixa *</label>
                <select name="motivo" required style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="">Selecione...</option>
                    <option value="perda">Perda</option>
                    <option value="dano">Dano/Avaria</option>
                    <option value="obsolescencia">Obsolescência</option>
                    <option value="devolucao_locacao">Devolução de Locação</option>
                    <option value="descarte">Descarte</option>
                    <option value="doacao">Doação</option>
                    <option value="roubo">Roubo/Furto</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Quantidade *</label>
                <input type="number" name="quantidade" step="0.01" min="0.01" value="1" required style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Local (opcional)</label>
                <select name="local_id" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="">Não especificado</option>
                    <?php foreach ($estoque as $est): ?>
                        <option value="<?php echo $est['local_id']; ?>"><?php echo htmlspecialchars($est['nome_local']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Data da Baixa *</label>
                <input type="date" name="data_baixa" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Descrição/Justificativa *</label>
                <textarea name="descricao" rows="4" required placeholder="Descreva detalhadamente o motivo da baixa..." style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;"></textarea>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Valor Contábil (R$)</label>
                <input type="number" name="valor_contabil" step="0.01" min="0" placeholder="0,00" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="fecharModalBaixa()" class="btn" style="background: #e2e8f0; color: #2d3748;">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-danger">
                    Registrar Baixa
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.LOGGED_USER_ID = <?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>;

function abrirTab(tabName) {
    // Esconde todas as tabs
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(c => c.classList.remove('active'));
    
    // Remove active de todos os botões
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(t => t.classList.remove('active'));
    
    // Ativa a tab selecionada
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}

function abrirModalBaixa() {
    document.getElementById('modalBaixa').style.display = 'flex';
}

function fecharModalBaixa() {
    document.getElementById('modalBaixa').style.display = 'none';
}

// Envio do formulário de baixa via AJAX
document.getElementById('formBaixa').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('../../api/baixas.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.sucesso) {
            alert('Baixa registrada com sucesso! Aguardando aprovação.');
            location.reload();
        } else {
            alert('Erro: ' + result.mensagem);
        }
    } catch (error) {
        alert('Erro ao processar solicitação: ' + error.message);
    }
});

// Fechar modal ao clicar fora
document.getElementById('modalBaixa').addEventListener('click', function(e) {
    if (e.target === this) {
        fecharModalBaixa();
    }
});
</script>

<!-- Include components UI scripts -->
<script src="../../assets/js/kit_allocate_ui.js"></script>
<script src="../../assets/js/kit_components_ui.js"></script>
<script src="../../assets/js/allocation_modal.js"></script>

</body>
</html>