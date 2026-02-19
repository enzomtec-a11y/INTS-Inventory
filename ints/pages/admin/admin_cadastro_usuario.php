<?php
// admin_cadastro_usuario.php
require_once '../../config/_protecao.php';

// ✅ CORREÇÃO: exigirAdminGeral() — bloqueia admin_unidade, gestor e comum
exigirAdminGeral();

$status_message = '';
$editing = false;
$user_id = 0;

$user = [
    'id'         => 0,
    'nome'       => '',
    'email'      => '',
    'nivel'      => 'comum',
    'unidade_id' => null
];

// 1. CARREGAR UNIDADES PARA O SELECT
$unidades = [];
$resU = $conn->query("SELECT id, nome FROM locais WHERE tipo_local = 'unidade' AND deletado = FALSE ORDER BY nome");
while ($r = $resU->fetch_assoc()) $unidades[] = $r;

// 2. LÓGICA DE EDIÇÃO: CARREGA DADOS SE HOUVER ID NA URL
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
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $nivel = $_POST['nivel'] ?? 'comum';

    // ✅ unidade_id é salvo para admin_unidade E gestor
    $niveis_com_unidade = ['admin_unidade', 'gestor'];
    $unidade_id = (in_array($nivel, $niveis_com_unidade) && !empty($_POST['unidade_id']))
        ? (int)$_POST['unidade_id']
        : null;

    if (empty($nome) || empty($email)) {
        $status_message = "Erro: Nome e E-mail são obrigatórios.";
    } else {
        if ($editing) {
            if (!empty($senha)) {
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=?, senha_hash=? WHERE id=?");
                $stmt->bind_param("sssisi", $nome, $email, $nivel, $unidade_id, $senha_hash, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=? WHERE id=?");
                $stmt->bind_param("ssisi", $nome, $email, $nivel, $unidade_id, $user_id);
            }
        } else {
            if (empty($senha)) {
                $status_message = "Erro: Senha é obrigatória para novos usuários.";
            } else {
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, nivel, unidade_id, senha_hash, ativo) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("sssis", $nome, $email, $nivel, $unidade_id, $senha_hash);
            }
        }

        if (isset($stmt) && $stmt->execute()) {
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
    <title><?php echo $editing ? 'Editar' : 'Cadastrar'; ?> Usuário - INTS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .form-container {
            max-width: 620px;
            margin: 40px auto;
            background: #fff;
            padding: 30px 35px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .form-container h2 { margin-top: 0; color: #2c3e50; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 600; color: #444; }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
            transition: border-color .2s;
        }
        .form-group input:focus,
        .form-group select:focus { outline: none; border-color: #4CAF50; }
        small { color: #888; display: block; margin-top: 5px; font-size: 0.85em; }
        .badge-nivel {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75em;
            vertical-align: middle;
        }
        .btn-box { display: flex; gap: 12px; align-items: center; margin-top: 28px; }
        .btn { padding: 11px 22px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 15px; }
        .btn-save   { background: #28a745; color: #fff; }
        .btn-cancel { background: #6c757d; color: #fff; }
        .btn:hover { opacity: .88; }
        .alert-error {
            color: #721c24;
            background: #f8d7da;
            padding: 13px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        hr { border: none; border-top: 1px solid #eee; margin: 20px 0; }
        #group_unidade { transition: all .2s; }
    </style>
</head>
<body>

<div class="form-container">
    <h2><?php echo $editing ? '✏️ Editar Usuário' : '➕ Novo Usuário'; ?></h2>
    <hr>

    <?php if ($status_message): ?>
        <div class="alert-error"><?php echo htmlspecialchars($status_message); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Nome Completo</label>
            <input type="text" name="nome"
                   value="<?php echo htmlspecialchars($user['nome']); ?>"
                   required placeholder="Ex: João Silva">
        </div>

        <div class="form-group">
            <label>E-mail (Login)</label>
            <input type="email" name="email"
                   value="<?php echo htmlspecialchars($user['email']); ?>"
                   required placeholder="email@empresa.com">
        </div>

        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="senha"
                   <?php echo $editing ? '' : 'required'; ?>
                   placeholder="<?php echo $editing ? 'Deixe em branco para manter a atual' : 'Digite a senha'; ?>">
            <?php if ($editing): ?>
                <small>Deixe em branco para manter a senha atual.</small>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Nível de Acesso</label>
            <select name="nivel" id="nivel_select" onchange="toggleUnidade()" required>
                <option value="comum"        <?php echo $user['nivel'] === 'comum'        ? 'selected' : ''; ?>>Comum — apenas consulta</option>
                <option value="gestor"       <?php echo $user['nivel'] === 'gestor'       ? 'selected' : ''; ?>>Gestor — ações de estoque</option>
                <option value="admin_unidade"<?php echo $user['nivel'] === 'admin_unidade'? 'selected' : ''; ?>>Admin de Unidade — restrito à unidade</option>
                <option value="admin"        <?php echo $user['nivel'] === 'admin'        ? 'selected' : ''; ?>>Admin Geral — acesso total</option>
            </select>
            <small id="nivel_desc" style="color:#555;"></small>
        </div>

        <!-- ✅ Visível para admin_unidade E gestor -->
        <div class="form-group" id="group_unidade" style="display: none;">
            <label>Unidade de Responsabilidade</label>
            <select name="unidade_id">
                <option value="">-- Selecione a Unidade --</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?php echo $u['id']; ?>"
                        <?php echo ($user['unidade_id'] == $u['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Define quais dados este usuário pode acessar/gerenciar.</small>
        </div>

        <div class="btn-box">
            <button type="submit" class="btn btn-save">💾 Salvar</button>
            <a href="listar_usuarios.php" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<script>
const nivelDesc = {
    'comum':         'Pode consultar produtos e movimentações. Não realiza ações.',
    'gestor':        'Pode movimentar estoque. Pode ser vinculado a uma unidade.',
    'admin_unidade': 'Administra apenas a unidade vinculada (produtos, movimentações e usuários da unidade).',
    'admin':         'Acesso total ao sistema, sem restrições.'
};

function toggleUnidade() {
    const sel  = document.getElementById('nivel_select');
    const grp  = document.getElementById('group_unidade');
    const desc = document.getElementById('nivel_desc');
    const nivel = sel.value;

    // ✅ Mostra o campo de unidade para admin_unidade E gestor
    grp.style.display = (nivel === 'admin_unidade' || nivel === 'gestor') ? 'block' : 'none';
    desc.textContent  = nivelDesc[nivel] ?? '';
}

// Executa na carga para respeitar o valor já selecionado (edição)
toggleUnidade();
</script>

</body>
</html>