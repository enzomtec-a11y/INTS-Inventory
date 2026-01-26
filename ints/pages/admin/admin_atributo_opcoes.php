<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Lógica de Cadastro (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'cadastrar') {
    $atributo_id = (int)$_POST['atributo_id'];
    $valor = trim($_POST['valor']);

    if (empty($atributo_id) || empty($valor)) {
        $status_message = "<p style='color: red;'>Erro: 'Atributo' e 'Valor da Opção' são obrigatórios.</p>";
    } else {
        // SQL para inserir a nova opção na tabela 'atributos_opcoes'
        // 'INSERT IGNORE' para evitar erros de duplicidade
        // adicionar o mesmo valor ao mesmo atributo (UK no futuro)
        $sql_insert = "INSERT IGNORE INTO atributos_opcoes (atributo_id, valor) VALUES (?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        
        if ($stmt_insert) {
            $stmt_insert->bind_param("is", $atributo_id, $valor);
            
            if ($stmt_insert->execute()) {
                if ($stmt_insert->affected_rows > 0) {
                    $status_message = "<p style='color: green;'>Opção '{$valor}' cadastrada com sucesso!</p>";
                } else {
                    $status_message = "<p style='color: blue;'>Esta opção já existe para este atributo.</p>";
                }
            } else {
                $status_message = "<p style='color: red;'>Erro ao cadastrar opção: " . $stmt_insert->error . "</p>";
            }
            $stmt_insert->close();
        } else {
            $status_message = "<p style='color: red;'>Erro na preparação da consulta: " . $conn->error . "</p>";
        }
    }
}

// Lógica de Listagem (GET)

// Buscar todos os atributos para o <select> do formulário
$atributos_definicao = [];
$sql_attr = "SELECT id, nome, tipo FROM atributos_definicao ORDER BY nome";
$result_attr = $conn->query($sql_attr);
if ($result_attr) {
    while ($row = $result_attr->fetch_assoc()) {
        $atributos_definicao[] = $row;
    }
}

// Buscar todas as opções já cadastradas para a tabela de listagem
$opcoes_cadastradas = [];
$sql_opcoes = "
    SELECT 
        ao.id, 
        ao.valor, 
        ad.nome AS atributo_nome
    FROM 
        atributos_opcoes ao
    JOIN 
        atributos_definicao ad ON ao.atributo_id = ad.id
    ORDER BY 
        ad.nome, ao.valor
";
$result_opcoes = $conn->query($sql_opcoes);
if ($result_opcoes) {
    while ($row = $result_opcoes->fetch_assoc()) {
        $opcoes_cadastradas[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Opções de Atributos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type="text"], select { width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        button:hover { background-color: #45a049; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Administração - Opções Mestre de Atributos</h1>
        <p>
            <a href="../../index.html">Voltar para Home</a> | 
            <a href="admin_atributos.php">Gerenciar Atributos</a>
        </p>
        
        <?php echo $status_message; ?>

        <h2>Cadastrar Nova Opção Mestre</h2>
        <p>Use este formulário para criar os valores permitidos (ex: Atributo "Cor", Valor "Vermelho").</p>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="hidden" name="acao" value="cadastrar">
            
            <label for="atributo_id">Para qual Atributo?</label>
            <select id="atributo_id" name="atributo_id" required>
                <option value="">Selecione um Atributo</option>
                <?php foreach ($atributos_definicao as $attr): ?>
                    <option value="<?php echo htmlspecialchars($attr['id']); ?>">
                        <?php echo htmlspecialchars($attr['nome']); ?> (Tipo: <?php echo $attr['tipo']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="valor">Valor da Opção (Ex: Vermelho, 110v, Tamanho P)</label>
            <input type="text" id="valor" name="valor" required>

            <button type="submit">Cadastrar Opção</button>
        </form>

        <hr style="margin-top: 30px;">

        <h2>Opções Mestre Cadastradas</h2>
        
        <?php if (!empty($opcoes_cadastradas)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID da Opção</th>
                        <th>Atributo Associado</th>
                        <th>Valor Permitido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($opcoes_cadastradas as $opcao): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($opcao['id']); ?></td>
                            <td><?php echo htmlspecialchars($opcao['atributo_nome']); ?></td>
                            <td><?php echo htmlspecialchars($opcao['valor']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma opção mestre cadastrada ainda.</p>
        <?php endif; ?>

    </div>
</body>
</html>