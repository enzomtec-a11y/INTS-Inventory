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
    // Sanitização básica
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha']; // Senha não se sanitiza, pois pode ter caracteres especiais

    if (empty($email) || empty($senha)) {
        $erro = "Por favor, preencha todos os campos.";
    } else {
        // Proteção contra SQL Injection
        $sql = "SELECT id, nome, email, senha_hash, nivel, ativo FROM usuarios WHERE email = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $usuario = $result->fetch_assoc();
                
                // Verifica se o usuário está ativo
                if ($usuario['ativo'] == 0) {
                    $erro = "Usuário desativado. Contate o administrador.";
                } 
                // Verifica a Senha (Hash)
                elseif (password_verify($senha, $usuario['senha_hash'])) {
                    // Regenera ID da sessão
                    session_regenerate_id(true);
                    
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nome'] = $usuario['nome'];
                    $_SESSION['usuario_email'] = $usuario['email'];
                    $_SESSION['usuario_nivel'] = $usuario['nivel'];
                    $_SESSION['unidade_id'] = $usuario['unidade_id'];
                    
                    // Redireciona para o index
                    header("Location: index.html");
                    exit();
                } else {
                    // Senha errada
                    $erro = "E-mail ou senha incorretos.";
                }
            } else {
                // Usuário não encontrado
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
    <title>Login - Inventário INTS</title>
    <link rel="stylesheet" href="assets/css/style.css"> <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f0f2f5; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-card h2 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn-login { width: 100%; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; transition: background 0.3s; }
        .btn-login:hover { background-color: #0056b3; }
        .erro-msg { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; text-align: center; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Acesso ao Sistema</h2>
        
        <?php if ($erro): ?>
            <div class="erro-msg"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="form-group">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha" required>
            </div>
            
            <button type="submit" class="btn-login">Entrar</button>
        </form>
    </div>
</body>
</html>