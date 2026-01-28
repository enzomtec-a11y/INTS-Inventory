<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respondJson($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondJson(['sucesso'=>false,'mensagem'=>'Use POST com JSON payload'], 405);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) respondJson(['sucesso'=>false,'mensagem'=>'JSON inválido ou ausente'], 400);

$allocations = $data['allocations'] ?? null;
if (!is_array($allocations) || empty($allocations)) respondJson(['sucesso'=>false,'mensagem'=>'allocations é obrigatório e deve ser um array'], 400);

$referencia_tipo = isset($data['referencia_tipo']) && $data['referencia_tipo'] !== '' ? $data['referencia_tipo'] : 'manual_alloc';
$referencia_id = isset($data['referencia_id']) ? (is_numeric($data['referencia_id']) ? intval($data['referencia_id']) : null) : null;
$referencia_batch = isset($data['referencia_batch']) && $data['referencia_batch'] !== '' ? $data['referencia_batch'] : null;
$usuario_id = isset($data['usuario_id']) ? (int)$data['usuario_id'] : null;
$format = isset($data['format']) ? strtolower($data['format']) : 'pdf';

$conn->set_charset('utf8mb4');

try {
    $conn->begin_transaction();

    if (empty($referencia_batch)) {
        $referencia_batch = 'batch_' . time() . '_' . random_int(1000,9999);
    }

    $applied = [];

    // Prepare statements
    $stmt_with_local = $conn->prepare(
        "INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)"
    );
    if (!$stmt_with_local) throw new Exception("Erro prepare reservas (with local): " . $conn->error);

    $stmt_null_local = $conn->prepare(
        "INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por)
         VALUES (?, NULL, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)"
    );
    if (!$stmt_null_local) throw new Exception("Erro prepare reservas (null local): " . $conn->error);

    foreach ($allocations as $a) {
        $produto_id = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
        $local_id = array_key_exists('local_id', $a) ? ($a['local_id'] === null ? null : intval($a['local_id'])) : null;
        $qtd = isset($a['qtd']) ? floatval($a['qtd']) : 0.0;
        $patrimonio_id = isset($a['patrimonio_id']) ? (intval($a['patrimonio_id']) ?: null) : null;

        if ($patrimonio_id) {
            // resolve product and force qty = 1
            $stp = $conn->prepare("SELECT produto_id FROM patrimonios WHERE id = ? LIMIT 1");
            $stp->bind_param("i", $patrimonio_id);
            $stp->execute();
            $rp = $stp->get_result()->fetch_assoc();
            $stp->close();
            if (!$rp) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Patrimônio id {$patrimonio_id} não encontrado"], 404); }
            $produto_id = intval($rp['produto_id']);
            $refType = 'patrimonio';
            $refId = $patrimonio_id;
            $qtd = 1.0;
        } else {
            $refType = $referencia_tipo;
            $refId = $referencia_id;
            if ($qtd <= 0) $qtd = 1.0;
        }

        if ($produto_id <= 0) continue;

        if (is_null($local_id)) {
            $stmt_null_local->bind_param("idssis", $produto_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmt_null_local->execute();
            if ($stmt_null_local->error) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Erro insert reserva (null local): '.$stmt_null_local->error],500); }
        } else {
            $stmt_with_local->bind_param("iidsisi", $produto_id, $local_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmt_with_local->execute();
            if ($stmt_with_local->error) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Erro insert reserva (with local): '.$stmt_with_local->error],500); }
        }

        $applied[] = ['produto_id'=>$produto_id,'local_id'=>$local_id,'qtd'=>$qtd,'ref'=>$refType.':'.$refId];
    }

    $stmt_with_local->close();
    $stmt_null_local->close();

    $conn->commit();

    // Build picklist grouping
    $grouped = [];
    foreach ($applied as $it) {
        $key = $it['produto_id'] . '::' . ($it['local_id'] === null ? 'null' : $it['local_id']);
        if (!isset($grouped[$key])) $grouped[$key] = ['produto_id'=>$it['produto_id'],'local_id'=>$it['local_id'],'quantidade'=>0.0];
        $grouped[$key]['quantidade'] += $it['qtd'];
    }

    $items = [];
    // Fetch names
    $prodNames = [];
    $localNames = [];
    foreach ($grouped as $g) {
        $pid = $g['produto_id'];
        $lid = $g['local_id'];
        if (!isset($prodNames[$pid])) {
            $s = $conn->prepare("SELECT nome FROM produtos WHERE id = ? LIMIT 1");
            $s->bind_param("i", $pid);
            $s->execute();
            $r = $s->get_result()->fetch_assoc();
            $s->close();
            $prodNames[$pid] = $r['nome'] ?? "Produto $pid";
        }
        if (!is_null($lid) && !isset($localNames[$lid])) {
            $s2 = $conn->prepare("SELECT nome FROM locais WHERE id = ? LIMIT 1");
            $s2->bind_param("i", $lid);
            $s2->execute();
            $r2 = $s2->get_result()->fetch_assoc();
            $s2->close();
            $localNames[$lid] = $r2['nome'] ?? "Local $lid";
        }
        $items[] = [
            'produto_id' => $pid,
            'nome' => $prodNames[$pid],
            'local_id' => $lid,
            'local_nome' => $lid === null ? 'Sem local' : ($localNames[$lid] ?? "Local $lid"),
            'quantidade' => $g['quantidade']
        ];
    }

    $meta = ['total_items' => count($items), 'total_quantity' => array_sum(array_column($items,'quantidade'))];

    respondJson(['sucesso'=>true,'mensagem'=>'Reservations aplicadas','referencia_batch'=>$referencia_batch,'data'=>['items'=>$items,'meta'=>$meta]]);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}