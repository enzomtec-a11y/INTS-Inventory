<?php
// admin_cadastro_usuario.php
require_once '../../config/_protecao.php';
exigirAdmin(); // Apenas admin geral acessa esta página

$status_message = '';
$editing = false;
$user_id = 0;

$user = [
    'id' => 0,
    'nome' => '',
    'email' => '',
    'nivel' => 'comum',
    'unidade_id' => null
];

// 1. CARREGAR UNIDADES PARA O SELECT
$unidades = [];
$resU = $conn->query("SELECT id, nome FROM locais WHERE tipo_local = 'unidade' AND deletado = FALSE ORDER BY nome");
while ($r = $resU->fetch_assoc()) $unidades[] = $r;

// 2. LOGICA DE EDIÇÃO: CARREGA DADOS SE HOUVER ID NA URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editing = true;
    $user_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT id, nome, email, nivel, unidade_id FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $user = $row;
    }
    $stmt->close();
}

// 3. PROCESSAMENTO DO FORMULÁRIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $nivel = $_POST['nivel'] ?? 'comum';
    // Só salva unidade_id se o nível for admin_unidade
    $unidade_id = ($nivel === 'admin_unidade' && !empty($_POST['unidade_id'])) ? (int)$_POST['unidade_id'] : null;

    if (empty($nome) || empty($email)) {
        $status_message = "Erro: Nome e E-mail são obrigatórios.";
    } else {
        if ($editing) {
            // ATUALIZAR USUÁRIO
            if (!empty($senha)) {
                // Atualiza TUDO, incluindo nova senha criptografada
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=?, senha_hash=? WHERE id=?");
                $stmt->bind_param("sssisi", $nome, $email, $nivel, $unidade_id, $senha_hash, $user_id);
            } else {
                // Atualiza sem mexer na senha
                $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=? WHERE id=?");
                $stmt->bind_param("ssisi", $nome, $email, $nivel, $unidade_id, $user_id);
            }
        } else {
            // CRIAR NOVO USUÁRIO
            if (empty($senha)) {
                $status_message = "Erro: Senha é obrigatória para novos usuários.";
            } else {
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, nivel, unidade_id, senha_hash, ativo) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("sssis", $nome, $email, $nivel, $unidade_id, $senha_hash);
            }
        }

        if (isset($stmt) && $stmt->execute()) {
            // Redireciona para a lista após sucesso
            header("Location: listar_usuarios.php?sucesso=1");
            exit;
        } elseif (isset($stmt)) {
            $status_message = "Erro ao salvar no banco: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title><?php echo $editing ? 'Editar' : 'Cadastrar'; ?> Usuário</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .form-container { max-width: 600px; margin: 40px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .btn-box { display: flex; gap: 10px; align-items: center; margin-top: 30px; }
        .btn { padding: 12px 25px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; }
        .btn-save { background: #28a745; color: #fff; }
        .btn-cancel { background: #6c757d; color: #fff; }
        .alert-error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        small { color: #666; display: block; margin-top: 5px; }
    </style>
</head>
<body>

<div class="form-container">
    <h2><?php echo $editing ? ' Editar Usuário' : ' Novo Usuário'; ?></h2>
    <hr>

    <?php if ($status_message): ?>
        <div class="alert-error"><?php echo $status_message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Nome Completo</label>
            <input type="text" name="nome" value="<?php echo htmlspecialchars($user['nome']); ?>" required placeholder="Ex: João Silva">
        </div>

        <div class="form-group">
            <label>E-mail (Login)</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required placeholder="email@empresa.com">
        </div>

        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="senha" <?php echo $editing ? '' : 'required'; ?> placeholder="Digite a senha">
            <?php if ($editing): ?>
                <small>Deixe em branco para manter a senha atual.</small>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Nível de Acesso</label>
            <select name="nivel" id="nivel_select" onchange="toggleUnidade()" required>
                <option value="comum" <?php echo $user['nivel'] == 'comum' ? 'selected' : ''; ?>>Comum (Apenas Consulta)</option>
                <option value="gestor" <?php echo $user['nivel'] == 'gestor' ? 'selected' : ''; ?>>Gestor (Ações de Estoque)</option>
                <option value="admin_unidade" <?php echo $user['nivel'] == 'admin_unidade' ? 'selected' : ''; ?>>Admin de Unidade (Restrito)</option>
                <option value="admin" <?php echo $user['nivel'] == 'admin' ? 'selected' : ''; ?>>Admin Geral (Acesso Total)</option>
            </select>
        </div>

        <div class="form-group" id="group_unidade" style="display: none;">
            <label>Unidade de Responsabilidade</label>
            <select name="unidade_id">
                <option value="">-- Selecione a Unidade --</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $user['unidade_id'] == $u['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Este usuário verá apenas movimentações desta unidade.</small>
        </div>

        <div class="btn-box">
            <button type="submit" class="btn btn-save">Salvar Alterações</button>
            <a href="listar_usuarios.php" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<script>
function toggleUnidade() {
    const nivel = document.getElementById('nivel_select').value;
    const groupUnidade = document.getElementById('group_unidade');
    
    // Mostra o campo de unidade apenas se for Admin de Unidade
    if (nivel === 'admin_unidade') {
        groupUnidade.style.display = 'block';
    } else {
        groupUnidade.style.display = 'none';
        // Opcional: limpa o valor da unidade se trocar de nível
        groupUnidade.querySelector('select').value = '';
    }
}

// Executa ao carregar para o caso de estarmos editando um admin_unidade
window.onload = toggleUnidade;
</script>

</body>
</html>