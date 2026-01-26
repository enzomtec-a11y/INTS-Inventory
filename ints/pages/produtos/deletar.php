<?php
require_once '../../config/_protecao.php';

// O ID do produto será passado via GET.
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: listar.php?erro=delete_id_invalido");
    exit();
}

$produto_id = (int)$_GET['id'];
// Simulação do ID do usuário logado

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
    header("Location: listar.php?sucesso=soft_delete");
    exit();

} catch (Exception $e) {
    // Em caso de erro, reverte a transação
    $conn->rollback();
    error_log("Erro ao tentar fazer Soft Delete do produto {$produto_id}: " . $e->getMessage());
    header("Location: listar.php?erro=delete_falhou");
    exit();
}
?>