<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function resp($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') resp(['sucesso'=>false,'mensagem'=>'Método HTTP inválido, use POST'], 405);

$raw = file_get_contents('php://input');
if (empty($raw)) resp(['sucesso'=>false,'mensagem'=>'Payload vazio'], 400);

$data = json_decode($raw, true);
if (!is_array($data)) resp(['sucesso'=>false,'mensagem'=>'JSON inválido'], 400);

$allocations = $data['allocations'] ?? null;
if (!is_array($allocations) || empty($allocations)) resp(['sucesso'=>false,'mensagem'=>'allocations é obrigatório e deve ser um array.'], 400);

$referencia_tipo = isset($data['referencia_tipo']) && $data['referencia_tipo'] !== '' ? $data['referencia_tipo'] : 'manual_alloc';
$referencia_id = isset($data['referencia_id']) ? intval($data['referencia_id']) : null;
$referencia_batch = isset($data['referencia_batch']) && $data['referencia_batch'] !== '' ? $data['referencia_batch'] : null;
$usuario_id = isset($data['usuario_id']) ? (int)$data['usuario_id'] : null;

$conn->set_charset('utf8mb4');

try {
    $conn->begin_transaction();

    $applied = [];

    // We'll prepare two statements: one for local_id NOT NULL, another for local_id IS NULL
    $stmt_with_local = $conn->prepare(
        "INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)"
    );
    if (!$stmt_with_local) throw new Exception("Erro prepare (with local): " . $conn->error);

    $stmt_null_local = $conn->prepare(
        "INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por)
         VALUES (?, NULL, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)"
    );
    if (!$stmt_null_local) throw new Exception("Erro prepare (null local): " . $conn->error);

    foreach ($allocations as $a) {
        $produto_id = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
        $local_id = array_key_exists('local_id', $a) ? ($a['local_id'] === null ? null : intval($a['local_id'])) : null;
        $qtd = isset($a['qtd']) ? floatval($a['qtd']) : 0.0;
        $patrimonio_id = isset($a['patrimonio_id']) ? intval($a['patrimonio_id']) : null;

        // If there's a patrimonio_id, resolve product and force qty = 1
        if ($patrimonio_id) {
            $stp = $conn->prepare("SELECT produto_id FROM patrimonios WHERE id = ? LIMIT 1");
            $stp->bind_param("i", $patrimonio_id);
            $stp->execute();
            $rp = $stp->get_result()->fetch_assoc();
            $stp->close();
            if (!$rp) {
                $conn->rollback();
                resp(['sucesso'=>false,'mensagem'=>"Patrimônio id {$patrimonio_id} não encontrado"], 404);
            }
            $produto_id = intval($rp['produto_id']);
            $refType = 'patrimonio';
            $refId = $patrimonio_id;
            $qtd = 1.0; // force 1 per patrimony
        } else {
            $refType = $referencia_tipo;
            $refId = $referencia_id;
            // default qty for non-patrimonio allocations
            if ($qtd <= 0) $qtd = 1.0;
        }

        if ($produto_id <= 0) continue;

        if (is_null($local_id)) {
            $stmt_null_local->bind_param("dsssi", $produto_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            // bind_param types: produto_id (i) but we used "d" mistakenly above; fix types proper:
            // switch to correct type string:
            $stmt_null_local->bind_param("idssis", $produto_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmt_null_local->execute();
            if ($stmt_null_local->error) {
                $conn->rollback();
                resp(['sucesso'=>false,'mensagem'=>'Erro ao inserir reserva (null local): ' . $stmt_null_local->error], 500);
            }
        } else {
            $stmt_with_local->bind_param("iidsisi", $produto_id, $local_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            // The bind types above must match: i (produto), i (local), d (qtd), s (refType), i (refId), s (batch), i (usuario)
            // So correct types string is "iidsisi"
            $stmt_with_local->execute();
            if ($stmt_with_local->error) {
                $conn->rollback();
                resp(['sucesso'=>false,'mensagem'=>'Erro ao inserir reserva (with local): ' . $stmt_with_local->error], 500);
            }
        }

        $applied[] = ['produto_id'=>$produto_id,'local_id'=>$local_id,'qtd'=>$qtd,'ref'=>$refType.':'.$refId];
    }

    $stmt_with_local->close();
    $stmt_null_local->close();

    $conn->commit();
    resp(['sucesso'=>true,'mensagem'=>'Allocations aplicadas','applied'=>$applied]);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    resp(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}