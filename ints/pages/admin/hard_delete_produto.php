<?php
require_once '../../config/_protecao.php';
require_once '../../config/db.php'; 

exigirAdmin(); 

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: admin_produtos_deletados.php?erro=" . urlencode("ID do produto inválido para Hard Delete."));
    exit();
}

$produto_id = (int)$_GET['id'];
$usuario_id_log = getUsuarioId(); 

$conn->begin_transaction();

try {
    // Verificar e obter nome do produto
    $sql_info = "SELECT nome FROM produtos WHERE id = ?";
    $stmt_info = $conn->prepare($sql_info);
    $stmt_info->bind_param("i", $produto_id);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();
    
    if ($result_info->num_rows === 0) {
        throw new Exception("Produto ID: {$produto_id} não encontrado ou já excluído.");
    }
    
    $produto_nome = $result_info->fetch_assoc()['nome'];
    $stmt_info->close();
    
    // EXCLUSÃO DAS TABELAS RELACIONADAS
    $tabelas_a_limpar = [
        'arquivos' => "DELETE FROM arquivos WHERE produto_id = ?",
        'atributos_valor' => "DELETE FROM atributos_valor WHERE produto_id = ?",
        'estoques' => "DELETE FROM estoques WHERE produto_id = ?",
        // O produto pode ser principal ou subproduto em 'produto_relacionamento'
        'relacionamento_principal' => "DELETE FROM produto_relacionamento WHERE produto_principal_id = ?",
        'relacionamento_subproduto' => "DELETE FROM produto_relacionamento WHERE subproduto_id = ?",
        'movimentacoes' => "DELETE FROM movimentacoes WHERE produto_id = ?",
        'acoes_log' => "DELETE FROM acoes_log WHERE produto_id = ?" // Limpeza dos logs
    ];

    foreach ($tabelas_a_limpar as $tabela_nome => $sql_delete) {
        $stmt_rel = $conn->prepare($sql_delete);
        $stmt_rel->bind_param("i", $produto_id);
        $stmt_rel->execute();
        $stmt_rel->close();
    }
    
    // EXCLUSÃO PERMANENTE DO PRODUTO PRINCIPAL
    $sql_produto = "DELETE FROM produtos WHERE id = ?";
    $stmt_produto = $conn->prepare($sql_produto);
    $stmt_produto->bind_param("i", $produto_id);

    if (!$stmt_produto->execute()) {
        throw new Exception("Erro ao excluir produto principal: " . $stmt_produto->error);
    }
    $stmt_produto->close();
    
    // Commit da transação
    $conn->commit();
    
    $mensagem_sucesso = urlencode("Produto '{$produto_nome}' (ID: {$produto_id}) e todos os seus registros relacionados foram EXCLUÍDOS PERMANENTEMENTE.");

    header("Location: admin_produtos_deletados.php?sucesso={$mensagem_sucesso}");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $mensagem_erro = urlencode("Erro no Hard Delete: " . $e->getMessage());
    header("Location: admin_produtos_deletados.php?erro={$mensagem_erro}");
    exit();
}

$conn->close();