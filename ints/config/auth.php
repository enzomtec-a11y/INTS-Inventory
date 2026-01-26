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
        header("Location: ../../login.php");
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

/**
 * Retorna o nível do usuário logado.
 */
function getUsuarioNivel() {
    return $_SESSION['usuario_nivel'] ?? '';
}

/**
 * Retorna a unidade_id do usuário logado (se houver).
 */
function getUsuarioUnidadeId() {
    return isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
}

/**
 * Indica se o usuário é admin de unidade.
 */
function isAdminUnidade() {
    $nivel = getUsuarioNivel();
    return $nivel === 'admin_unidade';
}
?>