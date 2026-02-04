<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Lógica de Processamento (Mantida a sua base sólida, apenas ajuste de fluxo)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['acao']) && ($_POST['acao'] == 'cadastrar' || $_POST['acao'] == 'editar')) {
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $categoria_pai_id = empty($_POST['categoria_pai_id']) ? null : (int)$_POST['categoria_pai_id'];
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

        if (empty($nome)) {
            $status_message = "<div class='alert error'>Erro: Nome da categoria é obrigatório.</div>";
        } else {
            if ($_POST['acao'] == 'cadastrar') {
                $sql = "INSERT INTO categorias (nome, descricao, categoria_pai_id) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $nome, $descricao, $categoria_pai_id);
            } else {
                $sql = "UPDATE categorias SET nome=?, descricao=?, categoria_pai_id=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssii", $nome, $descricao, $categoria_pai_id, $id);
            }
            
            if ($stmt->execute()) {
                $status_message = "<div class='alert success'>Categoria processada com sucesso!</div>";
            } else {
                $status_message = "<div class='alert error'>Erro: " . $stmt->error . "</div>";
            }
            $stmt->close();
        }
    }
}

// Lógica de Exclusão (Soft Delete)
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir' && isset($_GET['id'])) {
    $id_del = (int)$_GET['id'];
    $check_sub = $conn->query("SELECT COUNT(*) as qtd FROM categorias WHERE categoria_pai_id = $id_del AND deletado = FALSE");
    $check_prod = $conn->query("SELECT COUNT(*) as qtd FROM produtos WHERE categoria_id = $id_del AND deletado = FALSE");
    
    if ($check_sub->fetch_assoc()['qtd'] > 0 || $check_prod->fetch_assoc()['qtd'] > 0) {
        $status_message = "<div class='alert error'>Não é possível excluir: existem vínculos ativos.</div>";
    } else {
        $conn->query("UPDATE categorias SET deletado = TRUE WHERE id = $id_del");
        $status_message = "<div class='alert success'>Categoria removida.</div>";
    }
}

// Busca Hierárquica para a View
$sql = "SELECT * FROM categorias WHERE deletado = FALSE ORDER BY categoria_pai_id ASC, nome ASC";
$res = $conn->query($sql);
$todas_categorias = [];
while ($row = $res->fetch_assoc()) {
    $todas_categorias[] = $row;
}

// Função auxiliar para montar árvore no HTML
function renderArvore($categorias, $pai_id = null, $nivel = 0) {
    foreach ($categorias as $cat) {
        if ($cat['categoria_pai_id'] == $pai_id) {
            echo "<div class='tree-item' style='margin-left: " . ($nivel * 25) . "px;'>";
            echo "<span>📁 <strong>" . htmlspecialchars($cat['nome']) . "</strong> <small>(" . htmlspecialchars($cat['descricao']) . ")</small></span>";
            echo "<div class='tree-actions'>";
            echo "<a href='#' onclick='setParent(" . $cat['id'] . ", \"" . addslashes($cat['nome']) . "\")' title='Adicionar Subcategoria'>➕</a>";
            echo "<a href='?acao=editar&id=" . $cat['id'] . "' class='edit-icon'>✏️</a>";
            echo "<a href='?acao=excluir&id=" . $cat['id'] . "' class='delete-icon' onclick='return confirm(\"Excluir?\")'>🗑️</a>";
            echo "</div>";
            echo "</div>";
            renderArvore($categorias, $cat['id'], $nivel + 1);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Categorias - Smart Inventory</title>
    <style>
        :root { --primary: #2c3e50; --success: #27ae60; --error: #e74c3c; --accent: #3498db; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; color: #333; margin: 0; display: flex; }
        
        .sidebar-form { width: 350px; background: white; height: 100vh; padding: 25px; box-shadow: 2px 0 10px rgba(0,0,0,0.1); position: sticky; top: 0; }
        .main-content { flex: 1; padding: 40px; }
        
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        h1, h2 { color: var(--primary); margin-top: 0; }
        label { display: block; margin-top: 15px; font-size: 0.9em; font-weight: bold; color: #666; }
        input, textarea, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px; }
        
        button { width: 100%; padding: 12px; background: var(--success); color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 20px; transition: 0.3s; }
        button:hover { background: #219150; }
        
        .tree-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .tree-item { display: flex; align-items: center; justify-content: space-between; padding: 10px; border-bottom: 1px solid #eee; transition: 0.2s; }
        .tree-item:hover { background: #f9f9f9; }
        .tree-actions a { text-decoration: none; margin-left: 15px; font-size: 1.2em; }
        
        .parent-badge { background: #e1f5fe; color: #0288d1; padding: 5px 10px; border-radius: 20px; font-size: 0.8em; display: inline-block; margin-top: 5px; cursor: pointer; }
    </style>
</head>
<body>

<div class="sidebar-form">
    <h2><?php echo isset($_GET['id']) ? '✏️ Editar' : 'Nova Categoria'; ?></h2>
    <?php echo $status_message; ?>
    
    <form method="POST" id="catForm">
        <input type="hidden" name="acao" value="<?php echo isset($_GET['id']) ? 'editar' : 'cadastrar'; ?>">
        <?php if(isset($_GET['id'])): ?> <input type="hidden" name="id" value="<?php echo (int)$_GET['id']; ?>"> <?php endif; ?>

        <label>Nome da Categoria:</label>
        <input type="text" name="nome" placeholder="Ex: Cadeiras, Mesas..." required>

        <label>Descrição:</label>
        <textarea name="descricao" rows="3"></textarea>
        
        <label>Hierarquia (Pai):</label>
        <div id="parentDisplay" class="parent-badge" onclick="clearParent()">📍 Raiz (Clique para limpar)</div>
        <input type="hidden" name="categoria_pai_id" id="parentIdField" value="">

        <button type="submit">Salvar Categoria</button>
        <?php if(isset($_GET['id'])): ?> <a href="admin_categorias.php" style="display:block; text-align:center; margin-top:10px; color:#999;">Cancelar Edição</a> <?php endif; ?>
    </form>
</div>

<div class="main-content">
    <h1>Estrutura de Patrimônio</h1>
    <p>Utilize o botão ➕ para adicionar uma subcategoria diretamente ao item desejado.</p>
    
    <div class="tree-container">
        <?php renderArvore($todas_categorias); ?>
    </div>
</div>

<script>
    // Função para definir o Pai automaticamente ao clicar no +
    function setParent(id, nome) {
        document.getElementById('parentIdField').value = id;
        document.getElementById('parentDisplay').innerText = "📂 Subcategoria de: " + nome + " (clique para limpar)";
        document.getElementsByName('nome')[0].focus();
        // Feedback visual
        document.getElementById('parentDisplay').style.background = "#fff3e0";
        document.getElementById('parentDisplay').style.color = "#ef6c00";
    }

    function clearParent() {
        document.getElementById('parentIdField').value = "";
        document.getElementById('parentDisplay').innerText = "📍 Raiz (Clique para limpar)";
        document.getElementById('parentDisplay').style.background = "#e1f5fe";
        document.getElementById('parentDisplay').style.color = "#0288d1";
    }

    // Preenche dados se estiver em modo edição (simplificado para o exemplo)
    <?php if(isset($_GET['id'])): 
        $id_edit = (int)$_GET['id'];
        $find = $conn->query("SELECT * FROM categorias WHERE id = $id_edit")->fetch_assoc();
    ?>
        document.getElementsByName('nome')[0].value = "<?php echo addslashes($find['nome']); ?>";
        document.getElementsByName('descricao')[0].value = "<?php echo addslashes($find['descricao']); ?>";
        <?php if($find['categoria_pai_id']): 
            $idPai = $find['categoria_pai_id'];
            $nomePai = $conn->query("SELECT nome FROM categorias WHERE id = $idPai")->fetch_assoc()['nome'];
        ?>
            setParent(<?php echo $idPai; ?>, "<?php echo addslashes($nomePai); ?>");
        <?php endif; ?>
    <?php endif; ?>
</script>

</body>
</html>