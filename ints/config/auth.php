<?php
// Inicia a sessão apenas se ainda não foi iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
    // Configurações de segurança da sessão (Opcional, mas recomendado)
    // Evita acesso via JavaScript (XSS)
    ini_set('session.cookie_httponly', 1); 
}

/**
 * Verifica se o usuário está logado.
 * Se não estiver, redireciona para o login.
 */
function exigirLogin() {
    if (!isset($_SESSION['usuario_id'])) {
        // Redireciona para o login na raiz
        // Ajuste o caminho conforme necessário dependendo de onde este arquivo é chamado
        // Como auth.php é incluído, o caminho relativo depende do arquivo chamador.
        // O ideal é usar caminho absoluto a partir da raiz do servidor ou uma constante.
        
        // Vamos tentar inferir o caminho para a raiz baseado na profundidade atual
        $profundidade = substr_count($_SERVER['PHP_SELF'], '/') - 1;
        // Ajuste simples: se estamos em /ints/pages/produtos/, precisamos subir 2 niveis
        // Mas o header Location aceita caminhos absolutos do site (ex: /ints/login.php)
        
        // Solução Robusta: Definir BASE_URL no config se possível, senão usamos relativo simples
        header("Location: ../../login.php"); // Tentativa genérica para arquivos em pages/subpasta
        exit();
    }
}

/**
 * Verifica se o usuário é Admin.
 * Se não for, encerra a execução com mensagem de erro.
 */
function exigirAdmin() {
    exigirLogin();
    $nivel = $_SESSION['usuario_nivel'] ?? '';
    if ($nivel !== 'admin' && $nivel !== 'admin_unidade') {
        die("<h1>Acesso Negado</h1><p>Você não tem permissão para acessar esta página.</p>");
    }
}

/**
 * Retorna o ID do usuário logado de forma segura.
 */
function getUsuarioId() {
    return $_SESSION['usuario_id'] ?? 0;
}

/**
 * Retorna o Nome do usuário logado.
 */
function getUsuarioNome() {
    return htmlspecialchars($_SESSION['usuario_nome'] ?? 'Visitante');
}
?>