<?php
require_once '../../config/_protecao.php';
require_once '../../config/db.php';

exigirAdmin(); 

$produtos_deletados = [];

// Função para buscar os produtos em soft delete
$sql = "
    SELECT 
        p.id, 
        p.nome, 
        c.nome AS categoria_nome,
        p.data_atualizado
    FROM 
        produtos p
    JOIN 
        categorias c ON p.categoria_id = c.id
    WHERE 
        p.deletado = TRUE
    ORDER BY 
        p.data_atualizado DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $produtos_deletados[] = $row;
    }
}

// Lógica de Restaurar
if (isset($_GET['acao']) && $_GET['acao'] == 'restaurar' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $restaurar_id = (int)$_GET['id'];
    $usuario_id_log = getUsuarioId(); 
    
    $conn->begin_transaction();
    try {
        $sql_r = "UPDATE produtos SET deletado = FALSE, data_atualizado = NOW() WHERE id = ?";
        $stmt_r = $conn->prepare($sql_r);
        $stmt_r->bind_param("i", $restaurar_id);
        
        if (!$stmt_r->execute()) {
             throw new Exception("Erro ao restaurar produto: " . $stmt_r->error);
        }
        $stmt_r->close();
        
        // Log de restauração
        registrarLog($conn, $usuario_id_log, 'produtos', $restaurar_id, 'RESTORE', "Produto ID: {$restaurar_id} restaurado da exclusão.", $restaurar_id);

        $conn->commit();
        header("Location: admin_produtos_deletados.php?sucesso=" . urlencode("Produto ID: {$restaurar_id} restaurado com sucesso."));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: admin_produtos_deletados.php?erro=" . urlencode("Erro ao restaurar: " . $e->getMessage()));
        exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciamento de Produtos Deletados</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .action-links a { margin-right: 10px; text-decoration: none; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Produtos excluidos</h1>
        
        <?php 
        if (isset($_GET['sucesso'])) {
            echo "<p class='success'>" . htmlspecialchars($_GET['sucesso']) . "</p>";
        }
        if (isset($_GET['erro'])) {
            echo "<p class='error'>" . htmlspecialchars($_GET['erro']) . "</p>";
        }
        ?>

        <?php if (!empty($produtos_deletados)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome do Produto</th>
                        <th>Categoria</th>
                        <th>Data de exclusão</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produtos_deletados as $produto): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($produto['id']); ?></td>
                            <td><?php echo htmlspecialchars($produto['nome']); ?></td>
                            <td><?php echo htmlspecialchars($produto['categoria_nome']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($produto['data_atualizado'])); ?></td>
                            <td class="action-links">
                                <a href="admin_produtos_deletados.php?acao=restaurar&id=<?php echo $produto['id']; ?>"
                                   onclick="return confirm('Tem certeza que deseja RESTAURAR o produto <?php echo htmlspecialchars($produto['nome']); ?>?');"
                                   style="color: #27ae60;">Restaurar</a>
                                
                                | 
                                
                                <a href="hard_delete_produto.php?id=<?php echo $produto['id']; ?>"
                                   onclick="return confirm('ATENÇÃO: Este item e SEUS LOGS serão EXCLUÍDOS PERMANENTEMENTE. Continuar?');"
                                   style="color: #c0392b; font-weight: bold;">Excluir Permanentemente</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="margin-top: 20px;">Nenhum produto está atualmente marcado para exclusão suave.</p>
        <?php endif; ?>
    </div>
</body>
</html>