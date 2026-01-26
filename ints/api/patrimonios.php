<?php
// API aprimorada para gestão de patrimônios (listar / detalhe / reservar / reserve_batch / release / transfer / export CSV)
// Respostas em JSON por padrão; suporte CSV via GET &format=csv
//
// Requer:
// - ../config/_protecao.php que exponha $conn (mysqli)
// - tabela `patrimonios` e `reservas` já existentes
//
// GET params (filtros):
// - id, produto_id, status, local_id
// - available=1 (filtra apenas patrimonios não reservados e status='ativo')
// - referencia_batch (filtrar reservas pela batch se desejar)
// - format=csv (retorna CSV em vez de JSON)
//
// POST actions:
// - action=reserve            -> reserva single patrimonio (param: patrimonio_id) ; opcional referencia_batch, usuario_id, local_id, change_status_to
// - action=reserve_batch      -> reservar em lote; fornecer patrimonio_ids (JSON array) OU produto_id + quantity; opcional referencia_batch, usuario_id, to_local_id, change_status_to
// - action=release            -> libera reserva(s); fornecer patrimonio_id OU referencia_batch (optional) ; optional revert_status_to
// - action=transfer           -> transfere / atribui patrimônio (como antes)
//
// Security / comportamento:
// - Escritas em transação; SELECT ... FOR UPDATE para bloquear registros
// - Reservas para patrimônios são inseridas em `reservas` com referencia_tipo='patrimonio' e referencia_id = patrimonios.id
// - Opcional: alterar patrimonios.status no ato da reserva (param change_status_to) e reverter ao liberar (param revert_status_to)
//

require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respondJson($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function respondCsv($rows, $headers = []) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="patrimonios_export.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($headers)) fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respondJson(['sucesso'=>false,'mensagem'=>'Conexão com DB não encontrada.'], 500);
}
$conn->set_charset("utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Filters
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        $produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : null;
        $status = isset($_GET['status']) ? trim($_GET['status']) : null;
        $local_id = isset($_GET['local_id']) ? intval($_GET['local_id']) : null;
        $available = isset($_GET['available']) && ($_GET['available'] === '1' || strtolower($_GET['available']) === 'true');
        $referencia_batch = isset($_GET['referencia_batch']) ? trim($_GET['referencia_batch']) : null;
        $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';

        // Detail by id
        if (!is_null($id) && $id > 0) {
            $stmt = $conn->prepare("SELECT * FROM patrimonios WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não encontrado.'], 404);

            // Attach reservation info if any
            $stmt2 = $conn->prepare("SELECT id, quantidade, criado_por, data_criado, referencia_tipo, referencia_id, referencia_batch FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_id = ? LIMIT 1");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $reserve = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            $row['reserva'] = $reserve ?? null;

            respondJson(['sucesso'=>true,'data'=>$row]);
        }

        // Build list query with filters
        $where = [];
        $types = '';
        $params = [];

        if (!is_null($produto_id) && $produto_id > 0) { $where[] = 'p.produto_id = ?'; $types .= 'i'; $params[] = $produto_id; }
        if (!is_null($status) && $status !== '') { $where[] = 'p.status = ?'; $types .= 's'; $params[] = $status; }
        if (!is_null($local_id) && $local_id > 0) { $where[] = 'p.local_id = ?'; $types .= 'i'; $params[] = $local_id; }

        $sql = "SELECT p.* FROM patrimonios p";
        if ($available) {
            // only active and not reserved
            $where[] = "p.status = 'ativo' AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.referencia_tipo = 'patrimonio' AND r.referencia_id = p.id)";
        }
        if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY p.data_criado DESC LIMIT 1000"; // safety limit

        $stmt = $conn->prepare($sql);
        if ($stmt === false) throw new Exception("Erro ao preparar query: " . $conn->error);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        // If referencia_batch filter requested: join reservation info
        if ($referencia_batch) {
            // fetch reservations that match batch and attach
            $batchStmt = $conn->prepare("SELECT referencia_id FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_batch = ?");
            $batchStmt->bind_param("s", $referencia_batch);
            $batchStmt->execute();
            $batchRes = $batchStmt->get_result();
            $batchIds = [];
            while ($b = $batchRes->fetch_assoc()) $batchIds[] = (int)$b['referencia_id'];
            $batchStmt->close();
            // filter rows to only those in batch
            $rows = array_values(array_filter($rows, function($r) use ($batchIds){ return in_array((int)$r['id'], $batchIds); }));
        }

        if ($format === 'csv') {
            // build CSV rows
            $csvRows = [];
            foreach ($rows as $r) {
                $csvRows[] = [
                    $r['id'],
                    $r['produto_id'],
                    $r['numero_patrimonio'],
                    $r['numero_serie'],
                    $r['local_id'],
                    $r['status'],
                    $r['data_aquisicao'],
                    $r['data_criado']
                ];
            }
            $headers = ['id','produto_id','numero_patrimonio','numero_serie','local_id','status','data_aquisicao','data_criado'];
            respondCsv($csvRows, $headers);
        }

        respondJson(['sucesso'=>true,'data'=>$rows]);
    }

    if ($method === 'POST') {
        $action = isset($_POST['action']) ? strtolower(trim($_POST['action'])) : null;
        if (!$action) respondJson(['sucesso'=>false,'mensagem'=>'Parâmetro action é obrigatório.'], 400);

        // Helper to generate batch id
        $gen_batch = function() {
            return 'batch_' . time() . '_' . random_int(1000,9999);
        };

        // Reserve single patrimônio (improved)
        if ($action === 'reserve') {
            $patrimonio_id = isset($_POST['patrimonio_id']) ? intval($_POST['patrimonio_id']) : 0;
            $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
            $local_id = isset($_POST['local_id']) ? (intval($_POST['local_id']) ?: null) : null;
            $referencia_batch = isset($_POST['referencia_batch']) && $_POST['referencia_batch'] !== '' ? trim($_POST['referencia_batch']) : $gen_batch();
            $change_status_to = isset($_POST['change_status_to']) ? trim($_POST['change_status_to']) : null;

            if ($patrimonio_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'patrimonio_id é obrigatório.'], 400);

            $conn->begin_transaction();

            // Lock patrimony
            $stmt = $conn->prepare("SELECT id, produto_id, numero_patrimonio, numero_serie, local_id, status FROM patrimonios WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $patrimonio_id);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$p) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não encontrado.'], 404); }

            if ($p['status'] !== 'ativo') {
                $conn->rollback();
                respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não está em estado ativo e não pode ser reservado.','status'=>$p['status']], 409);
            }

            // Check if already reserved
            $stmtChk = $conn->prepare("SELECT COUNT(*) AS c FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_id = ?");
            $stmtChk->bind_param("i", $patrimonio_id);
            $stmtChk->execute();
            $c = $stmtChk->get_result()->fetch_assoc();
            $stmtChk->close();
            if ($c && intval($c['c']) > 0) {
                $conn->rollback();
                respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio já reservado.'], 409);
            }

            // Insert reservation
            $res_local = $local_id ?? ($p['local_id'] ?? null);
            $produto_for_reserva = $p['produto_id'];
            $stmtIns = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, 1, 'patrimonio', ?, ?, ?)");
            $stmtIns->bind_param("iiiss", $produto_for_reserva, $res_local, $patrimonio_id, $referencia_batch, $usuario_id);
            $stmtIns->execute();
            if ($stmtIns->affected_rows <= 0) {
                $stmtIns->close();
                $conn->rollback();
                respondJson(['sucesso'=>false,'mensagem'=>'Falha ao criar reserva.'], 500);
            }
            $stmtIns->close();

            // Optionally change status
            if (!is_null($change_status_to) && $change_status_to !== '') {
                $stmtUpd = $conn->prepare("UPDATE patrimonios SET status = ? WHERE id = ?");
                $stmtUpd->bind_param("si", $change_status_to, $patrimonio_id);
                $stmtUpd->execute();
                $stmtUpd->close();
            }

            $conn->commit();
            respondJson(['sucesso'=>true,'mensagem'=>'Patrimônio reservado com sucesso.','patrimonio_id'=>$patrimonio_id,'referencia_batch'=>$referencia_batch]);
        }

        // Reserve batch: either list of patrimonio_ids OR produto_id+quantity
        if ($action === 'reserve_batch') {
            $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
            $referencia_batch = isset($_POST['referencia_batch']) && $_POST['referencia_batch'] !== '' ? trim($_POST['referencia_batch']) : $gen_batch();
            $change_status_to = isset($_POST['change_status_to']) ? trim($_POST['change_status_to']) : null;
            $patrimonio_ids_json = isset($_POST['patrimonio_ids']) ? $_POST['patrimonio_ids'] : null;
            $produto_id = isset($_POST['produto_id']) ? intval($_POST['produto_id']) : null;
            $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : null;
            $to_local_id = isset($_POST['to_local_id']) ? (intval($_POST['to_local_id']) ?: null) : null;

            if (empty($patrimonio_ids_json) && (is_null($produto_id) || is_null($quantity))) {
                respondJson(['sucesso'=>false,'mensagem'=>'Forneça patrimonio_ids (JSON array) ou produto_id + quantity.'], 400);
            }

            $reserved_ids = [];

            $conn->begin_transaction();

            // If patrimonio_ids provided, decode and reserve each (locking each)
            if (!empty($patrimonio_ids_json)) {
                $arr = json_decode($patrimonio_ids_json, true);
                if (!is_array($arr)) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'patrimonio_ids deve ser um JSON array válido.'], 400); }
                foreach ($arr as $pid) {
                    $pid = intval($pid);
                    if ($pid <= 0) continue;
                    // lock row
                    $stmt = $conn->prepare("SELECT id, produto_id, local_id, status FROM patrimonios WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $pid);
                    $stmt->execute();
                    $p = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$p) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Patrimônio id $pid não encontrado."], 404); }
                    if ($p['status'] !== 'ativo') { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Patrimônio id $pid não ativo."], 409); }

                    // check existing reservation
                    $stmtChk = $conn->prepare("SELECT COUNT(*) AS c FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_id = ?");
                    $stmtChk->bind_param("i", $pid);
                    $stmtChk->execute();
                    $c = $stmtChk->get_result()->fetch_assoc();
                    $stmtChk->close();
                    if ($c && intval($c['c']) > 0) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Patrimônio id $pid já reservado."], 409); }

                    $res_local = $to_local_id ?? ($p['local_id'] ?? null);
                    $produto_for_reserva = $p['produto_id'];
                    $stmtIns = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, 1, 'patrimonio', ?, ?, ?)");
                    $stmtIns->bind_param("iiiss", $produto_for_reserva, $res_local, $pid, $referencia_batch, $usuario_id);
                    $stmtIns->execute();
                    if ($stmtIns->affected_rows <= 0) { $stmtIns->close(); $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Falha ao reservar patrimônio id $pid"],500); }
                    $stmtIns->close();

                    if (!is_null($change_status_to) && $change_status_to !== '') {
                        $stmtUpd = $conn->prepare("UPDATE patrimonios SET status = ? WHERE id = ?");
                        $stmtUpd->bind_param("si", $change_status_to, $pid);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }

                    $reserved_ids[] = $pid;
                }

                $conn->commit();
                respondJson(['sucesso'=>true,'mensagem'=>'Reservas em lote criadas','reserved_ids'=>$reserved_ids,'referencia_batch'=>$referencia_batch]);
            }

            // Else: select available patrimonios for produto_id up to quantity
            if (!is_null($produto_id) && !is_null($quantity) && $quantity > 0) {
                // find available patrimonios (status='ativo' and not reserved)
                $stmtSel = $conn->prepare("SELECT p.id FROM patrimonios p WHERE p.produto_id = ? AND p.status = 'ativo' AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.referencia_tipo = 'patrimonio' AND r.referencia_id = p.id) LIMIT ? FOR UPDATE");
                $stmtSel->bind_param("ii", $produto_id, $quantity);
                $stmtSel->execute();
                $resSel = $stmtSel->get_result();
                $found = [];
                while ($row = $resSel->fetch_assoc()) $found[] = (int)$row['id'];
                $stmtSel->close();

                if (count($found) < $quantity) {
                    $conn->rollback();
                    respondJson(['sucesso'=>false,'mensagem'=>'Quantidade de patrimônios disponíveis insuficiente','found'=>count($found),'requested'=>$quantity], 409);
                }

                foreach ($found as $pid) {
                    // lock row already held by FOR UPDATE, but we will insert reservation
                    $stmtP = $conn->prepare("SELECT produto_id, local_id FROM patrimonios WHERE id = ? FOR UPDATE");
                    $stmtP->bind_param("i", $pid);
                    $stmtP->execute();
                    $pr = $stmtP->get_result()->fetch_assoc();
                    $stmtP->close();

                    $res_local = $to_local_id ?? ($pr['local_id'] ?? null);
                    $produto_for_reserva = $pr['produto_id'];
                    $stmtIns = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, 1, 'patrimonio', ?, ?, ?)");
                    $stmtIns->bind_param("iiiss", $produto_for_reserva, $res_local, $pid, $referencia_batch, $usuario_id);
                    $stmtIns->execute();
                    if ($stmtIns->affected_rows <= 0) { $stmtIns->close(); $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Falha ao reservar patrimônio id $pid"],500); }
                    $stmtIns->close();

                    if (!is_null($change_status_to) && $change_status_to !== '') {
                        $stmtUpd = $conn->prepare("UPDATE patrimonios SET status = ? WHERE id = ?");
                        $stmtUpd->bind_param("si", $change_status_to, $pid);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }

                    $reserved_ids[] = $pid;
                }

                $conn->commit();
                respondJson(['sucesso'=>true,'mensagem'=>'Reservas em lote criadas','reserved_ids'=>$reserved_ids,'referencia_batch'=>$referencia_batch]);
            }

            // fallback
            $conn->rollback();
            respondJson(['sucesso'=>false,'mensagem'=>'Parâmetros inválidos para reserve_batch.'], 400);
        }

        // Release: by patrimonio_id OR by referencia_batch
        if ($action === 'release') {
            $patrimonio_id = isset($_POST['patrimonio_id']) ? intval($_POST['patrimonio_id']) : null;
            $referencia_batch = isset($_POST['referencia_batch']) ? trim($_POST['referencia_batch']) : null;
            $revert_status_to = isset($_POST['revert_status_to']) ? trim($_POST['revert_status_to']) : null;

            if (is_null($patrimonio_id) && (!$referencia_batch || $referencia_batch === '')) {
                respondJson(['sucesso'=>false,'mensagem'=>'Forneça patrimonio_id ou referencia_batch para release.'], 400);
            }

            $conn->begin_transaction();

            if (!is_null($patrimonio_id)) {
                // delete reservation for this patrimonio
                $stmtDel = $conn->prepare("DELETE FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_id = ?");
                $stmtDel->bind_param("i", $patrimonio_id);
                $stmtDel->execute();
                $delCount = $stmtDel->affected_rows;
                $stmtDel->close();

                // optionally revert status
                if (!is_null($revert_status_to) && $revert_status_to !== '') {
                    $stmtUpd = $conn->prepare("UPDATE patrimonios SET status = ? WHERE id = ?");
                    $stmtUpd->bind_param("si", $revert_status_to, $patrimonio_id);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }

                $conn->commit();
                respondJson(['sucesso'=>true,'mensagem'=>"Reservas removidas para patrimônio {$patrimonio_id}", 'deleted'=>$delCount]);
            }

            // else batch
            $stmtDelBatch = $conn->prepare("DELETE FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_batch = ?");
            $stmtDelBatch->bind_param("s", $referencia_batch);
            $stmtDelBatch->execute();
            $deleted = $stmtDelBatch->affected_rows;
            $stmtDelBatch->close();

            // if revert_status provided, we may want to find all patrimonios that had that batch and revert them
            if (!is_null($revert_status_to) && $revert_status_to !== '') {
                // find patrimonios in that batch
                $stmtFind = $conn->prepare("SELECT referencia_id FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_batch = ?");
                $stmtFind->bind_param("s", $referencia_batch);
                $stmtFind->execute();
                $found = $stmtFind->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtFind->close();
                foreach ($found as $f) {
                    $pid = intval($f['referencia_id']);
                    $stmtUpd = $conn->prepare("UPDATE patrimonios SET status = ? WHERE id = ?");
                    $stmtUpd->bind_param("si", $revert_status_to, $pid);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }
            }

            $conn->commit();
            respondJson(['sucesso'=>true,'mensagem'=>"Reservas removidas da batch {$referencia_batch}", 'deleted'=>$deleted]);
        }

        // Transfer (mantém o comportamento anterior)
        if ($action === 'transfer') {
            $patrimonio_id = isset($_POST['patrimonio_id']) ? intval($_POST['patrimonio_id']) : 0;
            $to_produto_id = isset($_POST['to_produto_id']) ? (intval($_POST['to_produto_id']) ?: null) : null;
            $to_local_id = isset($_POST['to_local_id']) ? (intval($_POST['to_local_id']) ?: null) : null;
            $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
            $note = isset($_POST['note']) ? trim($_POST['note']) : null;

            if ($patrimonio_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'patrimonio_id é obrigatório.'], 400);

            $conn->begin_transaction();

            // lock patrimony
            $stmt = $conn->prepare("SELECT id, produto_id, local_id, status FROM patrimonios WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $patrimonio_id);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$p) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não encontrado.'], 404); }
            if ($p['status'] === 'desativado') { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio desativado não pode ser transferido.'], 409); }

            $old_produto_id = $p['produto_id'];
            $old_local = $p['local_id'];

            $fields = [];
            $params = [];
            $types = '';

            if (!is_null($to_produto_id)) { $fields[] = "produto_id = ?"; $params[] = $to_produto_id; $types .= 'i'; }
            if (!is_null($to_local_id)) { $fields[] = "local_id = ?"; $params[] = $to_local_id; $types .= 'i'; }
            if (empty($fields)) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Nenhuma alteração informada (to_produto_id ou to_local_id).'], 400); }

            $sql = "UPDATE patrimonios SET " . implode(',', $fields) . " WHERE id = ?";
            $params[] = $patrimonio_id;
            $types .= 'i';

            $stmtUpd = $conn->prepare($sql);
            $stmtUpd->bind_param($types, ...$params);
            $stmtUpd->execute();
            $stmtUpd->close();

            // create movimentacao record for transfer (quantity=1)
            $tipo = 'TRANSFERENCIA';
            $status = 'finalizado';
            $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, local_origem_id, local_destino_id, quantidade, usuario_id, status, tipo_movimentacao) VALUES (?, ?, ?, 1, ?, ?, ?)");
            $dest_local = $to_local_id ?: null;
            $stmtMov->bind_param("iiisss", $old_produto_id, $old_local, $dest_local, $usuario_id, $status, $tipo);
            $stmtMov->execute();
            $stmtMov->close();

            // remove any reserva referencing this patrimonio
            $stmtDel = $conn->prepare("DELETE FROM reservas WHERE referencia_tipo = 'patrimonio' AND referencia_id = ?");
            $stmtDel->bind_param("i", $patrimonio_id);
            $stmtDel->execute();
            $stmtDel->close();

            $conn->commit();
            respondJson(['sucesso'=>true,'mensagem'=>'Patrimônio transferido com sucesso.','patrimonio_id'=>$patrimonio_id]);
        }

        respondJson(['sucesso'=>false,'mensagem'=>'Ação desconhecida.'], 400);
    }

    respondJson(['sucesso'=>false,'mensagem'=>'Método HTTP não suportado.'], 405);

} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>