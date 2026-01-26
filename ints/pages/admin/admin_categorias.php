<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Inicializa variáveis do formulário
$id_edicao = null;
$nome_form = "";
$descricao_form = "";
$pai_form = "";
$modo_edicao = false;

// --- 1. LÓGICA DE POST (Cadastrar ou Editar) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // AÇÃO: CADASTRAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'cadastrar') {
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $categoria_pai_id = empty($_POST['categoria_pai_id']) ? null : (int)$_POST['categoria_pai_id'];

        if (empty($nome)) {
            $status_message = "<p style='color: red;'>Erro: Nome da categoria é obrigatório.</p>";
        } else {
            $sql = "INSERT INTO categorias (nome, descricao, categoria_pai_id) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $nome, $descricao, $categoria_pai_id);
            
            if ($stmt->execute()) {
                $status_message = "<p style='color: green;'>Categoria '{$nome}' cadastrada com sucesso!</p>";
            } else {
                $status_message = "<p style='color: red;'>Erro ao cadastrar: " . $stmt->error . "</p>";
            }
            $stmt->close();
        }
    }
    
    // AÇÃO: EDITAR (UPDATE)
    elseif (isset($_POST['acao']) && $_POST['acao'] == 'editar') {
        $id = (int)$_POST['id'];
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $categoria_pai_id = empty($_POST['categoria_pai_id']) ? null : (int)$_POST['categoria_pai_id'];

        if ($id == $categoria_pai_id) {
            $status_message = "<p style='color: red;'>Erro: Uma categoria não pode ser pai de si mesma.</p>";
        } elseif (empty($nome)) {
            $status_message = "<p style='color: red;'>Erro: Nome é obrigatório.</p>";
        } else {
            $sql = "UPDATE categorias SET nome=?, descricao=?, categoria_pai_id=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssii", $nome, $descricao, $categoria_pai_id, $id);
            
            if ($stmt->execute()) {
                $status_message = "<p style='color: green;'>Categoria atualizada com sucesso!</p>";
                // Limpa modo de edição para voltar ao cadastro
                $modo_edicao = false; 
                $nome_form = "";
            } else {
                $status_message = "<p style='color: red;'>Erro ao atualizar: " . $stmt->error . "</p>";
            }
            $stmt->close();
        }
    }
}

// --- 2. LÓGICA DE GET (Excluir ou Carregar Edição) ---
if (isset($_GET['acao'])) {
    
    // AÇÃO: CARREGAR DADOS PARA EDIÇÃO
    if ($_GET['acao'] == 'editar' && isset($_GET['id'])) {
        $id_edicao = (int)$_GET['id'];
        $res = $conn->query("SELECT * FROM categorias WHERE id = $id_edicao");
        if ($row = $res->fetch_assoc()) {
            $modo_edicao = true;
            $nome_form = $row['nome'];
            $descricao_form = $row['descricao'];
            $pai_form = $row['categoria_pai_id'];
        }
    }
    
    // AÇÃO: EXCLUIR (SOFT DELETE COM VALIDAÇÃO)
    elseif ($_GET['acao'] == 'excluir' && isset($_GET['id'])) {
        $id_del = (int)$_GET['id'];
        
        // Validação 1: Tem subcategorias?
        $check_sub = $conn->query("SELECT COUNT(*) as qtd FROM categorias WHERE categoria_pai_id = $id_del AND deletado = FALSE");
        $qtd_sub = $check_sub->fetch_assoc()['qtd'];
        
        // Validação 2: Tem produtos ativos?
        $check_prod = $conn->query("SELECT COUNT(*) as qtd FROM produtos WHERE categoria_id = $id_del AND deletado = FALSE");
        $qtd_prod = $check_prod->fetch_assoc()['qtd'];
        
        if ($qtd_sub > 0) {
            $status_message = "<p style='color: red;'>Não é possível excluir: Esta categoria possui subcategorias ativas.</p>";
        } elseif ($qtd_prod > 0) {
            $status_message = "<p style='color: red;'>Não é possível excluir: Existem produtos vinculados a esta categoria.</p>";
        } else {
            // Soft Delete
            $conn->query("UPDATE categorias SET deletado = TRUE WHERE id = $id_del");
            $status_message = "<p style='color: green;'>Categoria excluída com sucesso.</p>";
        }
    }
}

// --- 3. CARREGAMENTOS PARA A VIEW ---

// Dropdown de Pai
$categorias_pai = [];
$sql_pai = "SELECT id, nome FROM categorias WHERE deletado = FALSE ORDER BY nome";
$result_pai = $conn->query($sql_pai);
if ($result_pai) while ($row = $result_pai->fetch_assoc()) $categorias_pai[] = $row;

// Listagem Principal
$categorias_listagem = [];
$sql_select = "
    SELECT c1.id, c1.nome, c1.descricao, c2.nome AS categoria_pai_nome
    FROM categorias c1
    LEFT JOIN categorias c2 ON c1.categoria_pai_id = c2.id
    WHERE c1.deletado = FALSE
    ORDER BY c1.nome
";
$result_select = $conn->query($sql_select);
if ($result_select) while ($row = $result_select->fetch_assoc()) $categorias_listagem[] = $row;

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Administração - Categorias</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
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
        <h1>Administração de Categorias</h1>
        <p><a href="../../index.html">Voltar para Home</a></p>
        <?php echo $status_message; ?>

        <h2><?php echo $modo_edicao ? 'Editar Categoria' : 'Cadastrar Nova Categoria'; ?></h2>
        
        <form method="POST" action="admin_categorias.php">
            <input type="hidden" name="acao" value="<?php echo $modo_edicao ? 'editar' : 'cadastrar'; ?>">
            <?php if($modo_edicao): ?>
                <input type="hidden" name="id" value="<?php echo $id_edicao; ?>">
            <?php endif; ?>
            
            <label>Nome:</label>
            <input type="text" name="nome" value="<?php echo htmlspecialchars($nome_form); ?>" required>

            <label>Descrição:</label>
            <textarea name="descricao"><?php echo htmlspecialchars($descricao_form); ?></textarea>
            
            <label>Categoria Pai:</label>
            <select name="categoria_pai_id">
                <option value="">(Nenhuma - Categoria Raiz)</option>
                <?php foreach ($categorias_pai as $cat): ?>
                    <?php if ($modo_edicao && $cat['id'] == $id_edicao) continue; ?>
                    
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($pai_form == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="<?php echo $modo_edicao ? 'btn-blue' : 'btn-green'; ?>">
                <?php echo $modo_edicao ? 'Salvar Alterações' : 'Cadastrar Categoria'; ?>
            </button>
            
            <?php if($modo_edicao): ?>
                <a href="admin_categorias.php" style="margin-left:10px; color:#666;">Cancelar</a>
            <?php endif; ?>
        </form>

        <hr>

        <h2>Lista de Categorias</h2>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Descrição</th>
                    <th>Pai</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categorias_listagem as $cat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cat['nome']); ?></td>
                        <td><?php echo htmlspecialchars($cat['descricao']); ?></td>
                        <td><?php echo htmlspecialchars($cat['categoria_pai_nome'] ?? '-'); ?></td>
                        <td class="actions">
                            <a href="?acao=editar&id=<?php echo $cat['id']; ?>" class="edit">Editar</a>
                            <a href="?acao=excluir&id=<?php echo $cat['id']; ?>" class="delete" 
                               onclick="return confirm('Tem certeza? Isso só funcionará se a categoria estiver vazia.');">
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