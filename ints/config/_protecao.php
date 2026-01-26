<?php
session_start();
// config/_protecao.php

// 1. Inclui o módulo de funções de autenticação e inicia a sessão
require_once __DIR__ . '/auth.php'; 

// 2. Inclui a conexão com o banco de dados e funções auxiliares (como registrarLog)
// Nota: '__DIR__ . '/db.php' é a forma mais robusta de referenciar arquivos internos.
require_once __DIR__ . '/db.php'; 

// 3. EXIGE O LOGIN na primeira linha de código (redireciona se não estiver logado)
exigirLogin(); 

// 4. Configura o ID do usuário logado globalmente
$usuario_id_log = getUsuarioId();
?>