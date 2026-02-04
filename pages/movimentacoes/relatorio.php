<?php
require_once '../../config/_protecao.php';

$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id = $_SESSION['unidade_id'] ?? null;
$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id : null;
$ids_permitidos = [];

// Carrega mapa de locais para exibir nomes bonitos
$mapa_locais = getLocaisFormatados($conn, false); // Nomes completos

$sql = "
    SELECT 
        m.id, m.data_movimentacao, m.quantidade, m.status,
        p.nome AS produto_nome,
        u.nome AS usuario_nome,
        lo.nome AS origem_simples,
        ld.nome AS destino_simples,
        m.local_origem_id, m.local_destino_id
    FROM movimentacoes m
    JOIN produtos p ON m.produto_id = p.id
    LEFT JOIN locais lo ON m.local_origem_id = lo.id
    LEFT JOIN locais ld ON m.local_destino_id = ld.id
    LEFT JOIN usuarios u ON m.usuario_id = u.id
    WHERE 1=1
";

// Filtro de unidade no relatório
if ($filtro_unidade) {
    $ids_permitidos = getIdsLocaisDaUnidade($conn, $filtro_unidade);
    if (!empty($ids_permitidos)) {
        $ids_str = implode(',', $ids_permitidos);
        // Mostra se a origem OU o destino forem da unidade
        $sql .= " AND (m.local_origem_id IN ($ids_str) OR m.local_destino_id IN ($ids_str)) ";
    } else {
        $sql .= " AND 1=0 "; // Unidade sem locais
    }
}

$sql .= " ORDER BY m.data_movimentacao DESC LIMIT 200";

$movimentacoes = [];
$res = $conn->query($sql);
if ($res) while ($r = $res->fetch_assoc()) $movimentacoes[] = $r;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Movimentações</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; border: 1px solid #ccc; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #eee; }
    </style>
</head>
<body>
    <h1>Relatório de Movimentações <?php echo $filtro_unidade ? '(Unidade)' : ''; ?></h1>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Produto</th>
                <th>Qtd</th>
                <th>Origem</th>
                <th>Destino</th>
                <th>Usuário</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movimentacoes as $mov): 
                $origem = $mapa_locais[$mov['local_origem_id']] ?? $mov['origem_simples'];
                $destino = $mapa_locais[$mov['local_destino_id']] ?? $mov['destino_simples'];
            ?>
            <tr>
                <td><?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?></td>
                <td><?php echo htmlspecialchars($mov['produto_nome']); ?></td>
                <td><?php echo $mov['quantidade']; ?></td>
                <td><?php echo htmlspecialchars($origem); ?></td>
                <td><?php echo htmlspecialchars($destino); ?></td>
                <td><?php echo htmlspecialchars($mov['usuario_nome']); ?></td>
                <td><?php echo htmlspecialchars($mov['status']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>