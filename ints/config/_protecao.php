<?php
// config/_protecao.php

// auth.php já cuida do session_start() com guard (session_status check)
require_once __DIR__ . '/auth.php';

// Conexão com o banco e funções auxiliares
require_once __DIR__ . '/db.php';

// Exige login — redireciona para login.php se não estiver autenticado
exigirLogin();

// ID do usuário logado disponível globalmente nas páginas que incluem este arquivo
$usuario_id_log = getUsuarioId();
?>