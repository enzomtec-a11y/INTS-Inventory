<?php
require_once '../../config/_protecao.php';

// O ID do produto será passado via GET.
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: listar.php?erro=delete_id_invalido");
    exit();
}

$produto_id = (int)$_GET['id'];

// Detecta usuário/unidade
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// Se admin_unidade, verifica se o produto pertence à unidade
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql_check = "SELECT 1 FROM estoques e WHERE e.produto_id = ? AND e.local_id IN ($idsStr) LIMIT 1";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $produto_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    $stmt_check->close();
    if (!($res_check && $res_check->num_rows > 0)) {
        // tenta patrimonios
        $sql_check2 = "SELECT 1 FROM patrimonios pt WHERE pt.produto_id = ? AND pt.local_id IN ($idsStr) LIMIT 1";
        $stmt_check2 = $conn->prepare($sql_check2);
        $stmt_check2->bind_param("i", $produto_id);
        $stmt_check2->execute();
        $res_check2 = $stmt_check2->get_result();
        $stmt_check2->close();
        if (!($res_check2 && $res_check2->num_rows > 0)) {
            header("Location: listar.php?erro=delete_permissao_negada");
            exit();
        }
    }
}

// Inicia transação para garantir atomicidade
$conn->begin_transaction();

try {
    // Buscar informações do produto antes de "deletar" (para log)
    $sql_info = "SELECT nome FROM produtos WHERE id = ? AND deletado = FALSE";
    $stmt_info = $conn->prepare($sql_info);
    $stmt_info->bind_param("i", $produto_id);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();
    
    if ($result_info->num_rows === 0) {
        throw new Exception("Produto não encontrado ou já deletado.");
    }
    
    $produto_nome = $result_info->fetch_assoc()['nome'];
    $stmt_info->close();
    
    // EXCLUSÃO SUAVE (SOFT DELETE)
    // Marca o produto como 'deletado' em vez de removê-lo fisicamente
    $sql_produto = "UPDATE produtos SET deletado = TRUE, data_atualizado = NOW() WHERE id = ?";
    $stmt_produto = $conn->prepare($sql_produto);
    $stmt_produto->bind_param("i", $produto_id);

    if (!$stmt_produto->execute()) {
        throw new Exception("Erro ao marcar produto para exclusão: " . $stmt_produto->error);
    }
    
    $stmt_produto->close();

    // LOG: Registrar a ação de exclusão suave
    // O registro na tabela acoes_log será do tipo 'SOFT_DELETE'
    if (function_exists('registrarLog')) {
        registrarLog($conn, $usuario_id_log, 'produtos', $produto_id, 'SOFT_DELETE', "Produto: {$produto_nome} (ID: {$produto_id}) marcado para exclusão.", $produto_id);
    }

    // Commit da transação
    $conn->commit();

    // Redireciona para a tabela de produtos com mensagem de sucesso
    header("Location: index.php?sucesso=soft_delete");
    exit();

} catch (Exception $e) {
    // Em caso de erro, reverte a transação
    $conn->rollback();
    error_log("Erro ao tentar fazer Soft Delete do produto {$produto_id}: " . $e->getMessage());
    header("Location: index.php?erro=delete_falhou");
    exit();
}
?>