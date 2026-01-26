<?php
// Endpoint: allocations_apply.php
// Recebe POST (JSON) com payload:
// {
//   referencia_tipo: string (ex: 'manual_alloc'),
//   referencia_id: int,
//   referencia_batch: string|null,
//   usuario_id: int|null,
//   allocations: [
//     { produto_id: int, local_id: int|null, qtd: float, patrimonio_id: int|null }
//   ]
// }
// Cria entradas em reservas para cada allocation. Se patrimonio_id for informado, cria reserva com referencia_tipo='patrimonio' e referencia_id=patrimonio_id.
// Retorna JSON { sucesso: bool, mensagem: string, details: ... }
//
// Observações:
// - Usa INSERT ... ON DUPLICATE KEY UPDATE para acumular quantidades.
// - Transações + validações mínimas.

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

    // prepare insert statement - we will set referencia_type/id per allocation (patrimonio allocations override)
    $stmt = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
    if (!$stmt) throw new Exception("Erro prepare: " . $conn->error);

    $applied = [];
    foreach ($allocations as $a) {
        $produto_id = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
        $local_id = array_key_exists('local_id', $a) ? ($a['local_id'] === null ? null : intval($a['local_id'])) : null;
        $qtd = isset($a['qtd']) ? floatval($a['qtd']) : 0;
        $patrimonio_id = isset($a['patrimonio_id']) ? intval($a['patrimonio_id']) : null;

        if ($qtd <= 0 && !$patrimonio_id) continue; // nothing to do

        // if patrimonio_id present, override referencia and product (fetch produto_id if needed)
        $refType = $referencia_tipo;
        $refId = $referencia_id;
        if ($patrimonio_id) {
            // fetch produto_id for this patrimonio to ensure correct product
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
            // qty default to 1 for patrimônios if qtd omitted or zero
            if ($qtd <= 0) $qtd = 1;
        }

        // Bind parameters (handle nullable local_id)
        if (is_null($local_id)) {
            // prepare statement without local (set NULL) - easier to bind as NULL via passing null value
            $l = null;
            $stmt->bind_param("iisdsis", $produto_id, $l, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            // Note: mysqli bind_param requires variables and type matching; here we keep concise but some drivers may need explicit handling.
        } else {
            $stmt->bind_param("ii d s i s i", $produto_id, $local_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
        }
        // Because dynamic binding with nullable values in mysqli is error-prone in some setups,
        // we'll use a safer path: build explicit insert with placeholders and execute per-row.
        // So instead, release the previous prepared and run per-row prepared statements below.
    }

    // close earlier prepared statement and use per-row insertion to handle NULLs cleanly
    $stmt->close();

    $stmtRow = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
    if (!$stmtRow) throw new Exception("Erro prepare per-row: " . $conn->error);

    foreach ($allocations as $a) {
        $produto_id = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
        $local_id = array_key_exists('local_id', $a) ? ($a['local_id'] === null ? null : intval($a['local_id'])) : null;
        $qtd = isset($a['qtd']) ? floatval($a['qtd']) : 0;
        $patrimonio_id = isset($a['patrimonio_id']) ? intval($a['patrimonio_id']) : null;

        if ($qtd <= 0 && !$patrimonio_id) continue;

        $refType = $referencia_tipo;
        $refId = $referencia_id;
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
            if ($qtd <= 0) $qtd = 1;
        }

        // Bind with explicit types, set nulls as null variables
        if (is_null($local_id)) {
            $nullLocal = null;
            // bind_param requires variables; use 'i' for produto_id, 'd' for qtd, 's' for strings
            $stmtRow->bind_param("isdsiis", $produto_id, $nullLocal, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            // The above mixed types may cause issues; better to use mysqli_stmt::bind_param with correct types:
            // We'll run a manual prepared statement via query concatenation but escaping — but to keep safe, let's use prepared with explicit handling:
            $stmtRow->close();
            $stmtRow = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, NULL, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
            if (!$stmtRow) { $conn->rollback(); resp(['sucesso'=>false,'mensagem'=>'Erro prepare (null local): '.$conn->error]); }
            $stmtRow->bind_param("idssis", $produto_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmtRow->execute();
            $applied[] = ['produto_id'=>$produto_id,'local_id'=>null,'qtd'=>$qtd,'ref'=>$refType.':'.$refId];
            // restore stmtRow to general per-row version for next iterations
            $stmtRow->close();
            $stmtRow = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
            if (!$stmtRow) { $conn->rollback(); resp(['sucesso'=>false,'mensagem'=>'Erro prepare (per-row restore): '.$conn->error]); }
            continue;
        } else {
            $stmtRow->bind_param("iisisis", $produto_id, $local_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmtRow->execute();
            $applied[] = ['produto_id'=>$produto_id,'local_id'=>$local_id,'qtd'=>$qtd,'ref'=>$refType.':'.$refId];
        }
    }

    $stmtRow->close();
    $conn->commit();
    resp(['sucesso'=>true,'mensagem'=>'Allocations aplicadas','applied'=>$applied]);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    resp(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}