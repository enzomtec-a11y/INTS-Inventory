<?php
// config/auth.php

// Inicia a sessão apenas se ainda não foi iniciada
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_start();
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
 * Exige que o usuário seja Admin Geral (nivel = 'admin').
 * Bloqueia admin_unidade, gestor e comum.
 */
function exigirAdminGeral() {
    exigirLogin();
    $nivel = $_SESSION['usuario_nivel'] ?? '';
    if ($nivel !== 'admin') {
        die("
            <div style='font-family:sans-serif; text-align:center; padding:50px;'>
                <h1 style='color:#dc3545;'>🚫 Acesso Negado</h1>
                <p>Esta área é exclusiva para Administradores Gerais do sistema.</p>
                <a href='../../index.html' style='padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;'>Voltar ao Início</a>
            </div>
        ");
    }
}

/**
 * Exige que o usuário seja Admin Geral OU Admin de Unidade.
 * Bloqueia gestor e comum.
 * (Antigo exigirAdmin — mantido com nome mais descritivo)
 */
function exigirAdmin() {
    exigirLogin();
    $nivel = $_SESSION['usuario_nivel'] ?? '';
    if ($nivel !== 'admin' && $nivel !== 'admin_unidade') {
        die("
            <div style='font-family:sans-serif; text-align:center; padding:50px;'>
                <h1 style='color:#dc3545;'>🚫 Acesso Negado</h1>
                <p>Você não tem permissão para acessar esta página.</p>
                <a href='../../index.html' style='padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;'>Voltar ao Início</a>
            </div>
        ");
    }
}

/**
 * Exige que o usuário seja Admin Geral, Admin de Unidade ou Gestor.
 * Bloqueia apenas 'comum'.
 */
function exigirGestor() {
    exigirLogin();
    $nivel = $_SESSION['usuario_nivel'] ?? '';
    if (!in_array($nivel, ['admin', 'admin_unidade', 'gestor'])) {
        die("
            <div style='font-family:sans-serif; text-align:center; padding:50px;'>
                <h1 style='color:#dc3545;'>🚫 Acesso Negado</h1>
                <p>Você não tem permissão para acessar esta página.</p>
                <a href='../../index.html' style='padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;'>Voltar ao Início</a>
            </div>
        ");
    }
}

/**
 * Retorna o ID do usuário logado de forma segura.
 */
function getUsuarioId() {
    return isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
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
    return isset($_SESSION['unidade_id']) && $_SESSION['unidade_id'] !== null
        ? (int)$_SESSION['unidade_id']
        : 0;
}

/**
 * Indica se o usuário é admin geral.
 */
function isAdmin() {
    return getUsuarioNivel() === 'admin';
}

/**
 * Indica se o usuário é admin de unidade.
 */
function isAdminUnidade() {
    return getUsuarioNivel() === 'admin_unidade';
}

/**
 * Indica se o usuário é gestor.
 */
function isGestor() {
    return getUsuarioNivel() === 'gestor';
}

/**
 * Indica se o usuário pode editar (admin ou admin_unidade).
 */
function podeEditar() {
    return in_array(getUsuarioNivel(), ['admin', 'admin_unidade']);
}

/**
 * Indica se o usuário pode realizar movimentações (admin, admin_unidade ou gestor).
 */
function podeMover() {
    return in_array(getUsuarioNivel(), ['admin', 'admin_unidade', 'gestor']);
}
?>