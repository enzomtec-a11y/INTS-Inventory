<?php
session_start();
require_once 'config/db.php';

$erro = "";

// Se já estiver logado, manda para o index
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.html");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha'];

    if (empty($email) || empty($senha)) {
        $erro = "Por favor, preencha todos os campos.";
    } else {
        // ✅ CORREÇÃO CRÍTICA: unidade_id agora é selecionado do banco
        $sql = "SELECT id, nome, email, senha_hash, nivel, unidade_id, ativo FROM usuarios WHERE email = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $usuario = $result->fetch_assoc();

                if ($usuario['ativo'] == 0) {
                    $erro = "Usuário desativado. Contate o administrador.";
                } elseif (password_verify($senha, $usuario['senha_hash'])) {
                    session_regenerate_id(true);

                    $_SESSION['usuario_id']    = $usuario['id'];
                    $_SESSION['usuario_nome']  = $usuario['nome'];
                    $_SESSION['usuario_email'] = $usuario['email'];
                    $_SESSION['usuario_nivel'] = $usuario['nivel'];
                    // ✅ unidade_id agora virá corretamente do banco (null para admin/gestor/comum)
                    $_SESSION['unidade_id']    = $usuario['unidade_id'];

                    header("Location: index.html");
                    exit();
                } else {
                    $erro = "E-mail ou senha incorretos.";
                }
            } else {
                $erro = "E-mail ou senha incorretos.";
            }
            $stmt->close();
        } else {
            $erro = "Erro no sistema de login.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - INTS</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 2em;
        }
        .subtitle {
            text-align: center;
            color: #888;
            margin-bottom: 30px;
            font-size: 0.9em;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #444;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
            margin-bottom: 20px;
            transition: border-color .2s;
        }
        input:focus {
            outline: none;
            border-color: #4CAF50;
        }
        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background .2s;
        }
        button[type="submit"]:hover { background: #45a049; }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>INTS</h1>
        <p class="subtitle">Sistema de Inventário</p>

        <?php if ($erro): ?>
            <div class="alert-error"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email"
                   value="<?php echo htmlspecialchars($email ?? ''); ?>"
                   placeholder="seu@email.com" required autofocus>

            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha"
                   placeholder="••••••••" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>