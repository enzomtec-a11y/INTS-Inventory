<?php
require_once '../../config/_protecao.php';

// Apenas admins podem aprovar/rejeitar baixas
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
if ($usuario_nivel !== 'admin' && $usuario_nivel !== 'admin_unidade') {
    die("Acesso negado.");
}

$usuario_id = getUsuarioId();

// Filtros
$filtro_status = $_GET['status'] ?? 'pendente';
$filtro_motivo = $_GET['motivo'] ?? '';
$filtro_data_inicio = $_GET['data_inicio'] ?? '';
$filtro_data_fim = $_GET['data_fim'] ?? '';

// Buscar baixas
$where = ["b.status = ?"];
$types = "s";
$params = [$filtro_status];

if (!empty($filtro_motivo)) {
    $where[] = "b.motivo = ?";
    $types .= "s";
    $params[] = $filtro_motivo;
}

if (!empty($filtro_data_inicio)) {
    $where[] = "b.data_baixa >= ?";
    $types .= "s";
    $params[] = $filtro_data_inicio;
}

if (!empty($filtro_data_fim)) {
    $where[] = "b.data_baixa <= ?";
    $types .= "s";
    $params[] = $filtro_data_fim;
}

$sql = "SELECT b.*, 
               p.nome AS produto_nome,
               p.numero_patrimonio,
               l.nome AS local_nome,
               u.nome AS criado_por_nome,
               pt.numero_patrimonio AS patrimonio_numero
        FROM baixas b
        LEFT JOIN produtos p ON b.produto_id = p.id
        LEFT JOIN locais l ON b.local_id = l.id
        LEFT JOIN usuarios u ON b.criado_por = u.id
        LEFT JOIN patrimonios pt ON b.patrimonio_id = pt.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY b.data_criado DESC
        LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$baixas = [];
while ($row = $result->fetch_assoc()) {
    $baixas[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Baixas</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #4a5568;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-danger { background: #f56565; color: white; }
        
        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .badge-warning { background: #feebc8; color: #7c2d12; }
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-danger { background: #fed7d7; color: #742a2a; }
        
        .actions {
            display: flex;
            gap: 5px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>Gerenciar Baixas de Produtos</h1>
        <p>Aprovar ou rejeitar solicitações de baixa</p>
    </div>
    
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Status</label>
            <select name="status">
                <option value="pendente" <?php echo $filtro_status == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                <option value="aprovada" <?php echo $filtro_status == 'aprovada' ? 'selected' : ''; ?>>Aprovadas</option>
                <option value="rejeitada" <?php echo $filtro_status == 'rejeitada' ? 'selected' : ''; ?>>Rejeitadas</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>Motivo</label>
            <select name="motivo">
                <option value="">Todos</option>
                <option value="perda" <?php echo $filtro_motivo == 'perda' ? 'selected' : ''; ?>>Perda</option>
                <option value="dano" <?php echo $filtro_motivo == 'dano' ? 'selected' : ''; ?>>Dano</option>
                <option value="obsolescencia" <?php echo $filtro_motivo == 'obsolescencia' ? 'selected' : ''; ?>>Obsolescência</option>
                <option value="roubo" <?php echo $filtro_motivo == 'roubo' ? 'selected' : ''; ?>>Roubo</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>Data Início</label>
            <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filtro_data_inicio); ?>">
        </div>
        
        <div class="filter-group">
            <label>Data Fim</label>
            <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filtro_data_fim); ?>">
        </div>
        
        <div class="filter-group" style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>
    
    <?php if (!empty($baixas)): ?>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Produto</th>
                <th>Qtd</th>
                <th>Motivo</th>
                <th>Local</th>
                <th>Solicitante</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($baixas as $baixa): ?>
            <tr>
                <td><?php echo date('d/m/Y', strtotime($baixa['data_baixa'])); ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($baixa['produto_nome']); ?></strong><br>
                    <small style="color: #718096;">
                        <?php echo htmlspecialchars($baixa['numero_patrimonio'] ?? $baixa['patrimonio_numero'] ?? '—'); ?>
                    </small>
                </td>
                <td><?php echo number_format($baixa['quantidade'], 2, ',', '.'); ?></td>
                <td><span class="badge badge-warning"><?php echo ucfirst($baixa['motivo']); ?></span></td>
                <td><?php echo htmlspecialchars($baixa['local_nome'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($baixa['criado_por_nome'] ?? 'Sistema'); ?></td>
                <td>
                    <?php
                    $badge_class = 'badge-warning';
                    switch($baixa['status']) {
                        case 'aprovada': $badge_class = 'badge-success'; break;
                        case 'rejeitada': $badge_class = 'badge-danger'; break;
                    }
                    ?>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($baixa['status']); ?></span>
                </td>
                <td>
                    <?php if ($baixa['status'] == 'pendente'): ?>
                    <div class="actions">
                        <button onclick="aprovarBaixa(<?php echo $baixa['id']; ?>)" class="btn btn-success">✓</button>
                        <button onclick="rejeitarBaixa(<?php echo $baixa['id']; ?>)" class="btn btn-danger">✗</button>
                    </div>
                    <?php else: ?>
                    <button onclick="verDetalhes(<?php echo $baixa['id']; ?>)" class="btn btn-primary">Ver</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align: center; padding: 40px; background: white; border-radius: 8px;">
        <p style="color: #a0aec0;">Nenhuma baixa encontrada com os filtros selecionados.</p>
    </div>
    <?php endif; ?>
</div>

<script>
async function aprovarBaixa(baixaId) {
    if (!confirm('Confirma a aprovação desta baixa? O estoque será atualizado.')) return;
    
    const formData = new FormData();
    formData.append('action', 'aprovar');
    formData.append('baixa_id', baixaId);
    formData.append('aprovador_id', <?php echo $usuario_id; ?>);
    
    try {
        const response = await fetch('../../api/baixas.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.sucesso) {
            alert('Baixa aprovada com sucesso!');
            location.reload();
        } else {
            alert('Erro: ' + result.mensagem);
        }
    } catch (error) {
        alert('Erro ao processar: ' + error.message);
    }
}

async function rejeitarBaixa(baixaId) {
    const motivo = prompt('Informe o motivo da rejeição:');
    if (!motivo) return;
    
    const formData = new FormData();
    formData.append('action', 'rejeitar');
    formData.append('baixa_id', baixaId);
    formData.append('aprovador_id', <?php echo $usuario_id; ?>);
    formData.append('motivo_rejeicao', motivo);
    
    try {
        const response = await fetch('../../api/baixas.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.sucesso) {
            alert('Baixa rejeitada.');
            location.reload();
        } else {
            alert('Erro: ' + result.mensagem);
        }
    } catch (error) {
        alert('Erro ao processar: ' + error.message);
    }
}

function verDetalhes(baixaId) {
    // Implementar visualização detalhada ou redirecionar
    alert('ID da baixa: ' + baixaId);
}
</script>

</body>
</html>