<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json;charset=utf-8');

function respond($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Input
$produto_id = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;
$quant = isset($_POST['quantidade']) ? (float)$_POST['quantidade'] : 1.0;
$local_id = isset($_POST['local_id']) ? (int)$_POST['local_id'] : 0;
$action = isset($_POST['action']) ? strtolower($_POST['action']) : 'reserve';
$usuario_id = isset($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : null;
$dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';

if ($produto_id <= 0 || $quant <= 0) respond(['sucesso' => false, 'mensagem' => 'produto_id e quantidade válidos são obrigatórios.'], 400);
if (!in_array($action, ['reserve','assemble'])) respond(['sucesso'=>false,'mensagem'=>'action inválida.'], 400);
if ($action === 'assemble' && $local_id <= 0) respond(['sucesso'=>false,'mensagem'=>'assemble exige local_id.'], 400);

// Ensure connection charset
$conn->set_charset("utf8mb4");

// Try to use existing explodeBomFlat if present; otherwise define local version
if (!function_exists('explodeBomFlat')) {
    function explodeBomFlat($conn, $rootId, $multiplier = 1.0, &$flat = [], $depth = 0, $visited = [], $maxDepth = 12) {
        if ($depth > $maxDepth) throw new Exception("Profundidade máxima ao explodir BOM.");
        if (in_array($rootId, $visited)) throw new Exception("Ciclo detectado na composição (produto $rootId).");
        $visited[] = $rootId;

        $sql = "SELECT pr.subproduto_id, pr.quantidade FROM produto_relacionamento pr WHERE pr.produto_principal_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Erro ao preparar explodeBomFlat: " . $conn->error);
        $stmt->bind_param("i", $rootId);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasChild = false;
        while ($row = $res->fetch_assoc()) {
            $hasChild = true;
            $sid = (int)$row['subproduto_id'];
            $qty = (float)$row['quantidade'] * (float)$multiplier;

            // check if sid has children
            $stmt_check = $conn->prepare("SELECT COUNT(*) AS c FROM produto_relacionamento WHERE produto_principal_id = ?");
            $stmt_check->bind_param("i", $sid);
            $stmt_check->execute();
            $rc = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($rc && (int)$rc['c'] > 0) {
                explodeBomFlat($conn, $sid, $qty, $flat, $depth + 1, $visited, $maxDepth);
            } else {
                if (!isset($flat[$sid])) $flat[$sid] = 0.0;
                $flat[$sid] += $qty;
            }
        }
        $stmt->close();

        if (!$hasChild) {
            if (!isset($flat[$rootId])) $flat[$rootId] = 0.0;
            $flat[$rootId] += $multiplier;
        }
        return $flat;
    }
}

// Helper: compute available stock at local considering existing reservations
function get_available_at_local($conn, $produtoId, $localId) {
    // Lock estoque row
    $stmt = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? FOR UPDATE");
    $stmt->bind_param("ii", $produtoId, $localId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stock = $row ? (float)$row['quantidade'] : 0.0;

    // Sum reservations for that product/local (lock rows)
    $stmt2 = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS reservado FROM reservas WHERE produto_id = ? AND local_id = ? FOR UPDATE");
    $stmt2->bind_param("ii", $produtoId, $localId);
    $stmt2->execute();
    $rrow = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    $reserved = $rrow ? (float)$rrow['reservado'] : 0.0;

    return max(0.0, $stock - $reserved);
}

// Helper: select all estoque rows for product and lock them (for allocation)
function lock_estoque_rows_for_product($conn, $produtoId) {
    $stmt = $conn->prepare("SELECT local_id, quantidade FROM estoques WHERE produto_id = ? FOR UPDATE");
    $stmt->bind_param("i", $produtoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

// Begin transaction
try {
    $conn->begin_transaction();

    // Explode BOM for required quantity
    $flat = [];
    explodeBomFlat($conn, $produto_id, $quant, $flat, 0, []);

    if (empty($flat)) {
        // no components: nothing to allocate/reserve
        $conn->commit();
        respond(['sucesso' => true, 'mensagem' => 'Produto sem componentes. Nada a reservar/consumir.', 'alloc' => []]);
    }

    $allocations = []; // [ ['produto_id'=>, 'local_id'=>, 'qtd'=>], ... ]
    $shortages = [];

    foreach ($flat as $compId => $qtyRequired) {
        $qtyReq = (float)$qtyRequired;

        if ($local_id > 0) {
            // lock estoque row and reservation row
            $avail = get_available_at_local($conn, $compId, $local_id);
            if ($avail < $qtyReq) {
                $shortages[] = ['produto_id' => $compId, 'required' => $qtyReq, 'available' => $avail];
                continue;
            }
            $allocations[] = ['produto_id' => $compId, 'local_id' => $local_id, 'qtd' => $qtyReq];
            continue;
        }

        // No local specified: auto-distribute across available locations
        $rows = lock_estoque_rows_for_product($conn, $compId);
        // compute available per local (subtract reservations)
        $needed = $qtyReq;
        // If no estoque rows, available is zero
        if (empty($rows)) {
            $shortages[] = ['produto_id'=>$compId,'required'=>$qtyReq,'available'=>0];
            continue;
        }

        // sort rows by quantidade desc (greedy)
        usort($rows, function($a,$b){ return ((float)$b['quantidade'] <=> (float)$a['quantidade']); });

        foreach ($rows as $r) {
            if ($needed <= 0) break;
            $lid = (int)$r['local_id'];
            // compute reserved for this product/local
            $stmt = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS reservado FROM reservas WHERE produto_id = ? AND local_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $compId, $lid);
            $stmt->execute();
            $rr = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $reserved = $rr ? (float)$rr['reservado'] : 0.0;
            $available = max(0.0, (float)$r['quantidade'] - $reserved);
            if ($available <= 0) continue;
            $take = min($needed, $available);
            $allocations[] = ['produto_id' => $compId, 'local_id' => $lid, 'qtd' => $take];
            $needed -= $take;
        }

        if ($needed > 0) {
            // shortage
            // compute total available
            $totalAvailable = 0.0;
            foreach ($rows as $r) {
                $lid = (int)$r['local_id'];
                $stmt = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS reservado FROM reservas WHERE produto_id = ? AND local_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $compId, $lid);
                $stmt->execute();
                $rr = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $reserved = $rr ? (float)$rr['reservado'] : 0.0;
                $totalAvailable += max(0.0, (float)$r['quantidade'] - $reserved);
            }
            $shortages[] = ['produto_id'=>$compId,'required'=>$qtyReq,'available'=>$totalAvailable];
        }
    }

    if (!empty($shortages)) {
        $conn->rollback();
        respond(['sucesso'=>false,'mensagem'=>'Estoque insuficiente para alguns componentes.','shortages'=>$shortages], 409);
    }

    // If dry_run, return allocations suggestion (do not write DB)
    if ($dry_run) {
        $conn->rollback();
        respond(['sucesso'=>true,'mensagem'=>'Dry run: alocação sugerida','alloc'=>$allocations]);
    }

    // Perform action
    if ($action === 'reserve') {
        // Insert reservations (ON DUPLICATE KEY UPDATE accumulate)
        $stmtIns = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, criado_por) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
        if (!$stmtIns) throw new Exception("Erro prepare insert reservas: " . $conn->error);
        $refType = 'kit_reserva';
        $refId = $produto_id; // grouping id; can be adjusted to a unique batch id if desired
        foreach ($allocations as $a) {
            $p = $a['produto_id']; $l = $a['local_id']; $q = $a['qtd'];
            $stmtIns->bind_param("iiissi", $p, $l, $q, $refType, $refId, $usuario_id);
            $stmtIns->execute();
        }
        $stmtIns->close();
        $conn->commit();
        respond(['sucesso'=>true,'mensagem'=>'Componentes reservados com sucesso','alloc'=>$allocations]);
    }

    // assemble
    if ($action === 'assemble') {
        // We required local_id earlier; allocations should reflect local_id allocations
        // Decrement estoque for each allocation and create movimentacoes
        $stmtUpd = $conn->prepare("UPDATE estoques SET quantidade = quantidade - ? WHERE produto_id = ? AND local_id = ?");
        if (!$stmtUpd) throw new Exception("Erro prepare update estoques: " . $conn->error);
        $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, local_origem_id, local_destino_id, quantidade, usuario_id, status, tipo_movimentacao) VALUES (?, ?, ?, ?, ?, 'finalizado', 'COMPONENTE')");
        if (!$stmtMov) throw new Exception("Erro prepare insert movimentacoes: " . $conn->error);

        foreach ($allocations as $a) {
            $p = $a['produto_id']; $l = $a['local_id']; $q = $a['qtd'];
            // Update
            $stmtUpd->bind_param("dis", $q, $p, $l);
            $stmtUpd->execute();
            if ($stmtUpd->affected_rows === 0) {
                // This should not happen because we locked earlier, but check safety
                throw new Exception("Falha ao decrementar estoque do produto $p no local $l.");
            }
            // Insert movement (origin = local, destination = null)
            $dest = null;
            $stmtMov->bind_param("iiidi", $p, $l, $dest, $q, $usuario_id);
            $stmtMov->execute();
        }
        $stmtUpd->close();
        $stmtMov->close();

        // If assembled product controls stock, increment its stock at local_id
        $stmtProd = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
        $stmtProd->bind_param("i", $produto_id);
        $stmtProd->execute();
        $prodR = $stmtProd->get_result()->fetch_assoc();
        $stmtProd->close();
        if ((int)($prodR['controla_estoque_proprio'] ?? 0) === 1) {
            // upsert into estoques
            // ensure local_id provided
            if ($local_id <= 0) throw new Exception("local_id é obrigatório para incrementar estoque do produto montado.");
            $stmtUpsert = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)");
            $stmtUpsert->bind_param("iid", $produto_id, $local_id, $quant);
            $stmtUpsert->execute();
            $stmtUpsert->close();
        }

        $conn->commit();
        respond(['sucesso'=>true,'mensagem'=>'Montagem efetuada e estoques atualizados','alloc'=>$allocations]);
    }

    // Shouldn't reach here
    $conn->rollback();
    respond(['sucesso'=>false,'mensagem'=>'Ação não executada.'], 500);

} catch (Exception $e) {
    $conn->rollback();
    respond(['sucesso'=>false,'mensagem' => $e->getMessage()]);
}
?>