<?php
// admin_cadastro_usuario.php
require_once '../../config/_protecao.php';
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

// Detecta se está em modal (iframe)
$is_modal = isset($_GET['modal']) || isset($_POST['modal']);

// 3. PROCESSAMENTO DO FORMULÁRIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $nivel = $_POST['nivel'] ?? 'comum';

    // unidade_id é salvo para admin_unidade E gestor
    $niveis_com_unidade = ['admin_unidade', 'gestor'];
    $unidade_id = (in_array($nivel, $niveis_com_unidade) && !empty($_POST['unidade_id']))
        ? (int)$_POST['unidade_id']
        : null;

    if (empty($nome) || empty($email)) {
        $status_message = "Erro: Nome e E-mail são obrigatórios.";
    } else {
        $stmt = null;

        if ($editing) {
            // ✅ FIX CRÍTICO: separar queries pra NULL vs valor em unidade_id
            //    Ordem correta de tipos: s=nome, s=email, s=nivel, (i=unidade_id opcional), (s=senha_hash opcional), i=user_id
            if ($unidade_id === null) {
                if (!empty($senha)) {
                    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=NULL, senha_hash=? WHERE id=?");
                    $stmt->bind_param("ssssi", $nome, $email, $nivel, $senha_hash, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=NULL WHERE id=?");
                    $stmt->bind_param("sssi", $nome, $email, $nivel, $user_id);
                }
            } else {
                if (!empty($senha)) {
                    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=?, senha_hash=? WHERE id=?");
                    $stmt->bind_param("sssisi", $nome, $email, $nivel, $unidade_id, $senha_hash, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE usuarios SET nome=?, email=?, nivel=?, unidade_id=? WHERE id=?");
                    $stmt->bind_param("sssii", $nome, $email, $nivel, $unidade_id, $user_id);
                }
            }
        } else {
            if (empty($senha)) {
                $status_message = "Erro: Senha é obrigatória para novos usuários.";
            } else {
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                if ($unidade_id === null) {
                    $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, nivel, unidade_id, senha_hash, ativo) VALUES (?, ?, ?, NULL, ?, 1)");
                    $stmt->bind_param("ssss", $nome, $email, $nivel, $senha_hash);
                } else {
                    $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, nivel, unidade_id, senha_hash, ativo) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->bind_param("ssssi", $nome, $email, $nivel, $unidade_id, $senha_hash);
                }
            }
        }

        if (isset($stmt) && $stmt->execute()) {
            $stmt->close();
            // ✅ FIX MODAL: se está em iframe, fecha modal ao invés de redirecionar
            if ($is_modal) {
                echo "<script>
                    if (window.parent && window.parent.fecharModalDoFilho) {
                        window.parent.fecharModalDoFilho(true);
                    } else {
                        window.location.href = 'listar_usuarios.php?sucesso=1';
                    }
                </script>";
                exit;
            }
            header("Location: listar_usuarios.php?sucesso=1");
            exit;
        } elseif (isset($stmt)) {
            $status_message = "Erro ao salvar: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Editar Usuário' : 'Novo Usuário'; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: <?php echo $is_modal ? '20px' : '30px'; ?>;
        }

        .form-card {
            max-width: 560px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            padding: 28px 32px;
        }

        .form-card h2 {
            margin: 0 0 6px;
            font-size: 1.3rem;
            color: #2c3e50;
        }

        .form-card hr {
            border: none;
            border-top: 1px solid #e9ecef;
            margin-bottom: 22px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.88rem;
            color: #444;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.93rem;
            color: #333;
            background: #fafafa;
            box-sizing: border-box;
            transition: border-color .2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007bff;
            background: #fff;
        }

        .form-group small {
            display: block;
            margin-top: 4px;
            color: #888;
            font-size: 0.8rem;
        }

        .alert-error {
            background: #fff3f3;
            border: 1px solid #f5c6cb;
            color: #721c24;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 0.9rem;
        }

        .nivel-desc {
            font-size: 0.82rem;
            color: #666;
            margin-top: 5px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #dee2e6;
            display: none;
        }

        .btn-box {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }

        .btn {
            padding: 10px 22px;
            border-radius: 6px;
            font-size: 0.93rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-save {
            background: #007bff;
            color: #fff;
            flex: 1;
        }

        .btn-save:hover { background: #0056b3; }

        .btn-cancel {
            background: #e9ecef;
            color: #555;
        }

        .btn-cancel:hover { background: #dee2e6; }

        <?php if ($is_modal): ?>
        /* Modo modal: sem body background, sem padding excessivo */
        body { background: #fff; padding: 16px 20px; }
        .form-card { box-shadow: none; border: none; padding: 0; max-width: 100%; }
        <?php endif; ?>
    </style>
</head>
<body>

<div class="form-card">
    <h2><?php echo $editing ? '✏️ Editar Usuário' : '➕ Novo Usuário'; ?></h2>
    <hr>

    <?php if ($status_message): ?>
        <div class="alert-error"><?php echo htmlspecialchars($status_message); ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php if ($is_modal): ?>
            <input type="hidden" name="modal" value="1">
        <?php endif; ?>

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
                <option value="comum"         <?php echo $user['nivel'] === 'comum'         ? 'selected' : ''; ?>>Comum — apenas consulta</option>
                <option value="gestor"        <?php echo $user['nivel'] === 'gestor'        ? 'selected' : ''; ?>>Gestor — ações de estoque</option>
                <option value="admin_unidade" <?php echo $user['nivel'] === 'admin_unidade' ? 'selected' : ''; ?>>Admin de Unidade — restrito à unidade</option>
                <option value="admin"         <?php echo $user['nivel'] === 'admin'         ? 'selected' : ''; ?>>Admin Geral — acesso total</option>
            </select>
            <div class="nivel-desc" id="nivel_desc"></div>
        </div>

        <div class="form-group" id="group_unidade" style="display: none;">
            <label>Unidade de Responsabilidade <span style="color:red">*</span></label>
            <select name="unidade_id" id="unidade_select">
                <option value="">-- Selecione a Unidade --</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?php echo $u['id']; ?>"
                        <?php echo ((int)$user['unidade_id'] === (int)$u['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Define quais dados este usuário pode acessar/gerenciar.</small>
        </div>

        <div class="btn-box">
            <button type="submit" class="btn btn-save">💾 Salvar</button>
            <?php if ($is_modal): ?>
                <button type="button" class="btn btn-cancel" onclick="if(window.parent && window.parent.fecharModalDoFilho) window.parent.fecharModalDoFilho(false); else window.history.back();">Cancelar</button>
            <?php else: ?>
                <a href="listar_usuarios.php" class="btn btn-cancel">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
const nivelDesc = {
    'comum':         'Pode consultar produtos e movimentações. Não realiza ações.',
    'gestor':        'Pode movimentar estoque. Pode ser vinculado a uma unidade específica.',
    'admin_unidade': 'Administra apenas a unidade vinculada (produtos, movimentações e usuários da unidade).',
    'admin':         'Acesso total ao sistema, sem restrições de unidade.'
};

function toggleUnidade() {
    const sel    = document.getElementById('nivel_select');
    const grp    = document.getElementById('group_unidade');
    const desc   = document.getElementById('nivel_desc');
    const nivel  = sel.value;
    const req    = document.getElementById('unidade_select');

    const mostrar = (nivel === 'admin_unidade' || nivel === 'gestor');
    grp.style.display = mostrar ? 'block' : 'none';

    if (desc) {
        const texto = nivelDesc[nivel] ?? '';
        desc.textContent = texto;
        desc.style.display = texto ? 'block' : 'none';
    }

    // Campo obrigatório apenas quando visível
    if (req) req.required = mostrar;
}

// Executa na carga para respeitar o valor já selecionado (edição)
toggleUnidade();
</script>

</body>
</html>