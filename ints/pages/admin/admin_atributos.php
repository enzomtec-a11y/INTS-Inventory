<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();
$tipos_validos = ['texto', 'numero', 'booleano', 'data', 'selecao', 'multi_opcao'];

$id_edicao = null;
$nome_form = "";
$tipo_form = "";
$modo_edicao = false;

// --- 1. LÓGICA POST ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // CADASTRAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'cadastrar') {
        $nome = trim($_POST['nome']);
        $tipo = $_POST['tipo'];

        if (empty($nome) || !in_array($tipo, $tipos_validos)) {
            $status_message = "<p style='color: red;'>Dados inválidos.</p>";
        } else {
            $stmt = $conn->prepare("INSERT INTO atributos_definicao (nome, tipo) VALUES (?, ?)");
            $stmt->bind_param("ss", $nome, $tipo);
            if ($stmt->execute()) {
                $status_message = "<p style='color: green;'>Atributo criado!</p>";
            } else {
                $status_message = "<p style='color: red;'>Erro: " . $stmt->error . "</p>";
            }
            $stmt->close();
        }
    }
    
    // EDITAR
    elseif (isset($_POST['acao']) && $_POST['acao'] == 'editar') {
        $id = (int)$_POST['id'];
        $nome = trim($_POST['nome']);
        // O tipo geralmente não deve ser mudado se já houver dados, mas aqui permitimos renomear
        
        if (empty($nome)) {
            $status_message = "<p style='color: red;'>Nome obrigatório.</p>";
        } else {
            $stmt = $conn->prepare("UPDATE atributos_definicao SET nome=? WHERE id=?");
            $stmt->bind_param("si", $nome, $id);
            if ($stmt->execute()) {
                $status_message = "<p style='color: green;'>Atributo atualizado!</p>";
                $modo_edicao = false; $nome_form = "";
            } else {
                $status_message = "<p style='color: red;'>Erro: " . $stmt->error . "</p>";
            }
            $stmt->close();
        }
    }
}

// --- 2. LÓGICA GET ---
if (isset($_GET['acao'])) {
    
    // CARREGAR EDIÇÃO
    if ($_GET['acao'] == 'editar' && isset($_GET['id'])) {
        $id_edicao = (int)$_GET['id'];
        $res = $conn->query("SELECT * FROM atributos_definicao WHERE id=$id_edicao");
        if ($r = $res->fetch_assoc()) {
            $modo_edicao = true;
            $nome_form = $r['nome'];
            $tipo_form = $r['tipo'];
        }
    }
    
    // EXCLUIR (HARD DELETE COM CHECAGEM DE USO)
    elseif ($_GET['acao'] == 'excluir' && isset($_GET['id'])) {
        $id_del = (int)$_GET['id'];
        
        // Verifica se algum produto usa este atributo
        $check = $conn->query("SELECT COUNT(*) as qtd FROM atributos_valor WHERE atributo_id = $id_del");
        $qtd_uso = $check->fetch_assoc()['qtd'];
        
        if ($qtd_uso > 0) {
            $status_message = "<p style='color: red;'>Não é possível excluir: Existem {$qtd_uso} produtos usando este atributo.</p>";
        } else {
            // Remove de tabelas de ligação primeiro (regras, categorias, opcoes) se não houver CASCADE configurado no DB
            $conn->query("DELETE FROM categoria_atributo WHERE atributo_id = $id_del");
            $conn->query("DELETE FROM atributos_opcoes WHERE atributo_id = $id_del");
            // Remove definição
            if ($conn->query("DELETE FROM atributos_definicao WHERE id = $id_del")) {
                $status_message = "<p style='color: green;'>Atributo excluído (e seus vínculos limpos).</p>";
            } else {
                $status_message = "<p style='color: red;'>Erro ao excluir: " . $conn->error . "</p>";
            }
        }
    }
}

// Listagem
$atributos = [];
$res = $conn->query("SELECT id, nome, tipo FROM atributos_definicao ORDER BY nome");
if($res) while($r = $res->fetch_assoc()) $atributos[] = $r;

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Administração - Atributos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
        button { margin-top: 20px; padding: 10px 15px; cursor: pointer; border: none; color: white; border-radius: 4px;}
        .btn-green { background-color: #28a745; }
        .btn-blue { background-color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin-top: 30px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .actions a { margin-right: 10px; text-decoration: none; font-weight: bold; }
        .edit { color: #f39c12; }
        .delete { color: #c0392b; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gerenciar Atributos (EAV)</h1>
        <?php echo $status_message; ?>

        <h2><?php echo $modo_edicao ? 'Editar Atributo' : 'Novo Atributo'; ?></h2>
        
        <form method="POST" action="admin_atributos.php">
            <input type="hidden" name="acao" value="<?php echo $modo_edicao ? 'editar' : 'cadastrar'; ?>">
            <?php if($modo_edicao): ?>
                <input type="hidden" name="id" value="<?php echo $id_edicao; ?>">
            <?php endif; ?>
            
            <label>Nome (Ex: Voltagem, Cor):</label>
            <input type="text" name="nome" value="<?php echo htmlspecialchars($nome_form); ?>" required>

            <label>Tipo de Dado:</label>
            <select name="tipo" <?php echo $modo_edicao ? 'disabled title="Não é possível alterar o tipo após a criação para evitar perda de dados."' : 'required'; ?>>
                <option value="">Selecione...</option>
                <?php foreach ($tipos_validos as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo ($tipo_form == $t) ? 'selected' : ''; ?>>
                        <?php echo ucfirst($t); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if($modo_edicao): ?>
                <p style="font-size:0.8em; color:#666;">Nota: O tipo não pode ser alterado na edição.</p>
            <?php endif; ?>

            <button type="submit" class="<?php echo $modo_edicao ? 'btn-blue' : 'btn-green'; ?>">
                <?php echo $modo_edicao ? 'Salvar Alterações' : 'Criar Atributo'; ?>
            </button>
            
            <?php if($modo_edicao): ?>
                <a href="admin_atributos.php" style="margin-left:10px; color:#666;">Cancelar</a>
            <?php endif; ?>
        </form>

        <hr>

        <h2>Atributos Existentes</h2>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($atributos as $attr): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($attr['nome']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($attr['tipo'])); ?></td>
                        <td class="actions">
                            <a href="?acao=editar&id=<?php echo $attr['id']; ?>" class="edit">Editar</a>
                            <a href="?acao=excluir&id=<?php echo $attr['id']; ?>" class="delete" 
                               onclick="return confirm('Tem certeza? Se este atributo estiver em uso, a exclusão será bloqueada.');">
                               Excluir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>