<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Lógica de Cadastro/Vinculação
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'vincular') {
    $categoria_id = (int)$_POST['categoria_id'];
    $atributo_id = (int)$_POST['atributo_id'];
    // O checkbox retorna 'on' ou nada. Convertido para booleano.
    $obrigatorio = isset($_POST['obrigatorio']) ? 1 : 0;

    // Verificação de ID nulo
    if (empty($categoria_id) || empty($atributo_id)) {
        $status_message = "<p style='color: red;'>Erro: Categoria e Atributo são obrigatórios.</p>";
    } else {
        // Tenta atualizar a obrigatoriedade se a relação já existir
        $sql_update = "
            UPDATE categoria_atributo 
            SET obrigatorio = ? 
            WHERE categoria_id = ? AND atributo_id = ?
        ";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("iii", $obrigatorio, $categoria_id, $atributo_id);
        $stmt_update->execute();
        
        // Se nenhuma linha foi afetada, a relação não existe, então insere
        if ($stmt_update->affected_rows === 0) {
            $sql_insert = "
                INSERT INTO categoria_atributo (categoria_id, atributo_id, obrigatorio) 
                VALUES (?, ?, ?)
            ";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("iii", $categoria_id, $atributo_id, $obrigatorio);

            if ($stmt_insert->execute()) {
                $status_message = "<p style='color: green;'>Relação Categoria-Atributo vinculada com sucesso!</p>";
            } else {
                // Caso falhe por duplicidade (em caso de UNIQUE KEY) ou outro erro
                $status_message = "<p style='color: red;'>Erro ao vincular: " . $stmt_insert->error . "</p>";
            }
            $stmt_insert->close();
        } else {
            // Se affected_rows > 0, foi um UPDATE (a relação já existia)
            $status_message = "<p style='color: blue;'>Relação Categoria-Atributo atualizada com sucesso!</p>";
        }
        
        $stmt_update->close();
    }
}

// Lógica de Listagem: Buscar Categorias e Atributos para os formulários
$categorias = [];
$sql_cat = "SELECT id, nome FROM categorias ORDER BY nome";
$result_cat = $conn->query($sql_cat);
while ($row = $result_cat->fetch_assoc()) {
    $categorias[] = $row;
}

$atributos = [];
$sql_attr = "SELECT id, nome, tipo FROM atributos_definicao ORDER BY nome";
$result_attr = $conn->query($sql_attr);
while ($row = $result_attr->fetch_assoc()) {
    $atributos[] = $row;
}

// Lógica de Listagem: Relações Categoria-Atributo existentes
$relacoes = [];
$sql_relacoes = "
    SELECT 
        ca.id AS relacao_id,
        c.nome AS categoria_nome,
        ad.nome AS atributo_nome,
        ad.tipo AS atributo_tipo,
        ca.obrigatorio AS obrigatorio
    FROM 
        categoria_atributo ca
    JOIN 
        categorias c ON ca.categoria_id = c.id
    JOIN 
        atributos_definicao ad ON ca.atributo_id = ad.id
    ORDER BY 
        c.nome, ad.nome
";
$result_relacoes = $conn->query($sql_relacoes);
if ($result_relacoes) {
    while ($row = $result_relacoes->fetch_assoc()) {
        $relacoes[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Vínculo Categoria-Atributo</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type="text"], textarea, select, input[type="number"], input[type="date"] { width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        button:hover { background-color: #45a049; }
        .container {max-width: 1200px; margin: 0 auto;}
        h1 {text-align: center; color: black; font-size: 3em; margin-bottom: 10px;}
    </style>
</head>
<body>
    <div class="container">
        <h1>Administração de Vínculos Categoria-Atributo</h1>
        <p>
            <a href="../../index.html">Voltar para Home</a> | 
            <a href="admin_categorias.php">Gerenciar Categorias</a> |
            <a href="admin_atributos.php">Gerenciar Definições de Atributos</a>
        </p>
        <?php echo $status_message; ?>

        <h2>Vincular Atributo a Categoria</h2>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="hidden" name="acao" value="vincular">
            
            <div class="form-group">
                <label for="categoria_id">Categoria:</label>
                <select id="categoria_id" name="categoria_id" required>
                    <option value="">Selecione uma Categoria</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['id']); ?>">
                            <?php echo htmlspecialchars($cat['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="atributo_id">Atributo:</label>
                <select id="atributo_id" name="atributo_id" required>
                    <option value="">Selecione um Atributo</option>
                    <?php foreach ($atributos as $attr): ?>
                        <option value="<?php echo htmlspecialchars($attr['id']); ?>">
                            <?php echo htmlspecialchars($attr['nome']); ?> (Tipo: <?php echo ucfirst($attr['tipo']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="obrigatorio" name="obrigatorio">
                <label for="obrigatorio">Tornar este atributo **Obrigatório** para esta Categoria?</label>
            </div>

            <button type="submit">Vincular/Atualizar Relação</button>
        </form>

        ---

        <h2>Relações Atuais</h2>
        <?php if (!empty($relacoes)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID da Relação</th>
                        <th>Categoria</th>
                        <th>Atributo</th>
                        <th>Tipo do Atributo</th>
                        <th>Obrigatório</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($relacoes as $rel): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rel['relacao_id']); ?></td>
                            <td><?php echo htmlspecialchars($rel['categoria_nome']); ?></td>
                            <td><?php echo htmlspecialchars($rel['atributo_nome']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($rel['atributo_tipo'])); ?></td>
                            <td>
                                **<?php echo $rel['obrigatorio'] ? 'SIM' : 'NÃO'; ?>**
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma relação Categoria-Atributo definida ainda.</p>
        <?php endif; ?>

    </div>
</body>
</html>