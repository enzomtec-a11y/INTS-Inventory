<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Ações válidas para as regras
$acoes_validas = ['bloquear', 'tornar_obrigatorio'];

// Lógica de Cadastro/Regra
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'cadastrar') {
    $atributo_gatilho_id = (int)$_POST['atributo_gatilho_id'];
    $valor_gatilho = $conn->real_escape_string($_POST['valor_gatilho']);
    $atributo_alvo_id = (int)$_POST['atributo_alvo_id'];
    $acao = $conn->real_escape_string($_POST['acao_regra']);
    
    // Validação básica
    if (empty($atributo_gatilho_id) || empty($atributo_alvo_id) || empty($valor_gatilho) || !in_array($acao, $acoes_validas)) {
        $status_message = "<p style='color: red;'>Erro: Todos os campos são obrigatórios ou a Ação é inválida.</p>";
    } else {
        // SQL para inserir a regra
        $sql = "
            INSERT INTO atributo_regra_condicional 
            (atributo_gatilho_id, valor_gatilho, atributo_alvo_id, acao) 
            VALUES (?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isis", $atributo_gatilho_id, $valor_gatilho, $atributo_alvo_id, $acao);
        
        if ($stmt->execute()) {
            $status_message = "<p style='color: green;'>Regra Condicional cadastrada com sucesso!</p>";
        } else {
            $status_message = "<p style='color: red;'>Erro ao cadastrar regra: " . $stmt->error . "</p>";
        }
        $stmt->close();
    }
}

// Lógica de Listagem: Atributos para os formulários
$atributos = [];
$sql_attr = "SELECT id, nome, tipo FROM atributos_definicao ORDER BY nome";
$result_attr = $conn->query($sql_attr);
while ($row = $result_attr->fetch_assoc()) {
    $atributos[] = $row;
}

// Lógica de Listagem: Regras existentes
$regras = [];
$sql_regras = "
    SELECT 
        arc.id,
        ad_gatilho.nome AS gatilho_nome,
        arc.valor_gatilho,
        ad_alvo.nome AS alvo_nome,
        arc.acao
    FROM 
        atributo_regra_condicional arc
    JOIN
        atributos_definicao ad_gatilho ON arc.atributo_gatilho_id = ad_gatilho.id
    JOIN
        atributos_definicao ad_alvo ON arc.atributo_alvo_id = ad_alvo.id
    ORDER BY 
        gatilho_nome
";
$result_regras = $conn->query($sql_regras);
if ($result_regras) {
    while ($row = $result_regras->fetch_assoc()) {
        $regras[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Regras Condicionais EAV</title>
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
        <h1>Administração de Regras Condicionais (EAV)</h1>
        <p>
            <a href="../../index.html">Voltar para Home</a> | 
            <a href="admin_categorias.php">Gerenciar Categorias</a> |
            <a href="admin_atributos.php">Gerenciar Atributos</a>
        </p>
        <?php echo $status_message; ?>

        <h2>Definir Nova Regra (Gatilho → Ação)</h2>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="hidden" name="acao" value="cadastrar">
            
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label>Se o Atributo Gatilho:</label>
                    <select name="atributo_gatilho_id" required>
                        <option value="">Selecione o Atributo</option>
                        <?php foreach ($atributos as $attr): ?>
                            <option value="<?php echo htmlspecialchars($attr['id']); ?>">
                                <?php echo htmlspecialchars($attr['nome']); ?> (<?php echo ucfirst($attr['tipo']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label>Tiver o Valor:</label>
                    <input type="text" name="valor_gatilho" placeholder="Ex: 1, 220, Vermelho" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label>Ação:</label>
                    <select name="acao_regra" required>
                        <option value="">Selecione a Ação</option>
                        <?php foreach ($acoes_validas as $acao): ?>
                            <option value="<?php echo htmlspecialchars($acao); ?>"><?php echo ucfirst(str_replace('_', ' ', $acao)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="flex: 2;">
                    <label>No Atributo Alvo:</label>
                    <select name="atributo_alvo_id" required>
                        <option value="">Selecione o Atributo</option>
                        <?php foreach ($atributos as $attr): ?>
                            <option value="<?php echo htmlspecialchars($attr['id']); ?>">
                                <?php echo htmlspecialchars($attr['nome']); ?> (<?php echo ucfirst($attr['tipo']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit">Cadastrar Regra Condicional</button>
        </form>

        ---

        <h2>Regras Atuais</h2>
        <?php if (!empty($regras)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Gatilho (Atributo)</th>
                        <th>Valor Gatilho</th>
                        <th>Ação</th>
                        <th>Alvo (Atributo)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($regras as $regra): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($regra['id']); ?></td>
                            <td><?php echo htmlspecialchars($regra['gatilho_nome']); ?></td>
                            <td><?php echo htmlspecialchars($regra['valor_gatilho']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $regra['acao']))); ?></td>
                            <td><?php echo htmlspecialchars($regra['alvo_nome']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma regra condicional definida ainda.</p>
        <?php endif; ?>
    </div>
</body>
</html>