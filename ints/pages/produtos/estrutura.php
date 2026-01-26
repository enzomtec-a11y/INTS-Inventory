<?php
require_once '../../config/_protecao.php';

$produto_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$produto_nome = "";

if ($produto_id) {
    $res = $conn->query("SELECT nome FROM produtos WHERE id = $produto_id");
    if ($row = $res->fetch_assoc()) $produto_nome = $row['nome'];
}

/**
 * Função Recursiva para exibir a árvore de produtos
 */
function exibirArvoreBOM($conn, $paiId, $nivel = 0) {
    // Limite de segurança para evitar loops infinitos se houver referência circular
    if ($nivel > 10) {
        echo "<li style='color: red;'>Nível máximo de profundidade atingido (possível ciclo).</li>";
        return;
    }

    $sql = "
        SELECT pr.subproduto_id, pr.quantidade, pr.tipo_relacao, p.nome
        FROM produto_relacionamento pr
        JOIN produtos p ON pr.subproduto_id = p.id
        WHERE pr.produto_principal_id = ?
        ORDER BY p.nome
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $paiId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "<ul>"; // Abre lista do nível atual
        while ($row = $result->fetch_assoc()) {
            echo "<li>";
            echo "<strong>" . htmlspecialchars($row['nome']) . "</strong> ";
            echo "(Qtd: " . $row['quantidade'] . ") ";
            echo "<span style='font-size: 0.8em; color: gray;'>[" . ucfirst($row['tipo_relacao']) . "]</span>";
            
            // CHAMADA RECURSIVA: Busca os filhos deste filho
            exibirArvoreBOM($conn, $row['subproduto_id'], $nivel + 1);
            
            echo "</li>";
        }
        echo "</ul>"; // Fecha lista
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>BOM - Estrutura do Produto</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: auto; border: 1px solid #ccc; padding: 20px; border-radius: 5px; }
        ul { list-style-type: none; padding-left: 20px; }
        ul li { margin-bottom: 5px; position: relative; }
        
        ul li::before {
            content: "├─";
            position: absolute;
            left: -20px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Estrutura (BOM): <?php echo htmlspecialchars($produto_nome); ?></h1>
        <p>
            <a href="../admin/admin_produto_relacionamento.php?produto_id=<?php echo $produto_id; ?>">Editar Composição</a> | 
            <a href="listar.php">Lista de Produtos</a>
        </p>
        <hr>

        <?php if ($produto_id): ?>
            <div>
                <strong><?php echo htmlspecialchars($produto_nome); ?></strong> (Produto Raiz)
                <?php exibirArvoreBOM($conn, $produto_id); ?>
            </div>
        <?php else: ?>
            <p>Produto não encontrado.</p>
        <?php endif; ?>
    </div>
</body>
</html>