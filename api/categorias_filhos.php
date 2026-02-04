<?php
require_once '../config/_protecao.php';

header('Content-Type: application/json; charset=utf-8');

$categoria_id = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : 0;

if ($categoria_id <= 0) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID de categoria inválido']);
    exit;
}

$categorias = [];
$sql = "SELECT id, nome FROM categorias WHERE categoria_pai_id = ? AND deletado = FALSE ORDER BY nome";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $categoria_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $categorias[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'sucesso' => true,
    'categorias' => $categorias
], JSON_UNESCAPED_UNICODE);