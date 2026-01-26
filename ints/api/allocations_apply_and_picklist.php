<?php
// allocations_apply_and_picklist.php
// Aplica allocations (cria/atualiza reservas) e gera picklist imediatamente.
// Recebe POST JSON (mesmo formato do allocations_apply.php):
// {
//   referencia_tipo: string (opcional, default 'manual_alloc'),
//   referencia_id: int|null,
//   referencia_batch: string|null,
//   usuario_id: int|null,
//   format: 'pdf'|'html'|'json' (opcional, default 'pdf'),
//   allocations: [{produto_id:int, local_id:int|null, qtd:float, patrimonio_id:int|null}, ...]
// }
//
// Se format=pdf e TCPDF estiver disponível, retorna application/pdf com o PDF gerado.
// Caso contrário retorna JSON com html_preview (campo html) e data.
//
// Requisitos:
// - config/_protecao.php que defina $conn (mysqli).
// - opcional: ints/pdf/TemplatePicklist.php (recomendada).
// - opcional: TCPDF (se quiser PDF direto).
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

    // If no batch provided, generate one to group these reservations
    if (empty($referencia_batch)) {
        $referencia_batch = 'batch_' . time() . '_' . random_int(1000,9999);
    }

    $applied = [];

    // Prepare general per-row stmt (for non-null local)
    $stmtRow = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
    if (!$stmtRow) throw new Exception("Erro prepare reservas: " . $conn->error);

    foreach ($allocations as $a) {
        $produto_id = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
        $local_id = array_key_exists('local_id', $a) ? ($a['local_id'] === null ? null : intval($a['local_id'])) : null;
        $qtd = isset($a['qtd']) ? floatval($a['qtd']) : 0;
        $patrimonio_id = isset($a['patrimonio_id']) ? (intval($a['patrimonio_id']) ?: null) : null;

        if ($qtd <= 0 && !$patrimonio_id) continue;

        $refType = $referencia_tipo;
        $refId = $referencia_id;

        if ($patrimonio_id) {
            // If patrimony, ensure it exists and set product accordingly, and override reference
            $stp = $conn->prepare("SELECT produto_id FROM patrimonios WHERE id = ? LIMIT 1");
            $stp->bind_param("i", $patrimonio_id);
            $stp->execute();
            $rp = $stp->get_result()->fetch_assoc();
            $stp->close();
            if (!$rp) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>"Patrimônio id {$patrimonio_id} não encontrado"], 404); }
            $produto_id = intval($rp['produto_id']);
            $refType = 'patrimonio';
            $refId = $patrimonio_id;
            if ($qtd <= 0) $qtd = 1;
        }

        if (is_null($local_id)) {
            // insert with NULL local_id (explicit query)
            $stmtNull = $conn->prepare("INSERT INTO reservas (produto_id, local_id, quantidade, referencia_tipo, referencia_id, referencia_batch, criado_por) VALUES (?, NULL, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), data_criado = NOW(), criado_por = VALUES(criado_por)");
            if (!$stmtNull) { $conn->rollback(); respondJson(['sucesso'=>false,'mensagem'=>'Erro prepare insert null local: '.$conn->error],500); }
            $stmtNull->bind_param("idssis", $produto_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmtNull->execute();
            $stmtNull->close();
        } else {
            $stmtRow->bind_param("iisisis", $produto_id, $local_id, $qtd, $refType, $refId, $referencia_batch, $usuario_id);
            $stmtRow->execute();
        }

        $applied[] = ['produto_id'=>$produto_id,'local_id'=>$local_id,'qtd'=>$qtd,'ref'=>$refType.':'.$refId];
    }

    $stmtRow->close();
    $conn->commit();

    // Build picklist items grouped by produto_id + local_id from $applied
    $grouped = [];
    foreach ($applied as $it) {
        $key = $it['produto_id'] . '::' . ($it['local_id'] === null ? 'null' : $it['local_id']);
        if (!isset($grouped[$key])) $grouped[$key] = ['produto_id'=>$it['produto_id'],'local_id'=>$it['local_id'],'quantidade'=>0.0];
        $grouped[$key]['quantidade'] += $it['qtd'];
    }
    $items = [];
    // Fetch product and local names
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
    $refNote = "Batch: " . $referencia_batch;

    // Try to use TemplatePicklist if present
    $templatePath = __DIR__ . '/../pdf/TemplatePicklist.php';
    if (file_exists($templatePath)) require_once $templatePath;

    // If PDF requested and TCPDF available and TemplatePicklist available, generate PDF and return directly
    if ($format === 'pdf' && class_exists('TCPDF') && class_exists('TemplatePicklist')) {
        $html = TemplatePicklist::render($items, $meta, $refNote, ['company'=>'Minha Empresa', 'show_qr'=>true]);
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('INTS');
        $pdf->SetAuthor('INTS System');
        $pdf->SetTitle('Picklist ' . $referencia_batch);
        $pdf->SetMargins(10, 10, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdfData = $pdf->output('', 'S'); // return as string
        // output headers for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="picklist_'.$referencia_batch.'.pdf"');
        header('Content-Length: '.strlen($pdfData));
        echo $pdfData;
        exit;
    }

    // If PDF requested but no TCPDF, return HTML preview in JSON for client to open/convert
    if ($format === 'pdf') {
        if (class_exists('TemplatePicklist')) {
            $html = TemplatePicklist::render($items, $meta, $refNote, ['company'=>'Minha Empresa', 'show_qr'=>true]);
            respondJson(['sucesso'=>true,'mensagem'=>'PDF não gerado (TCPDF ausente). Retornando HTML preview.','html'=>$html,'data'=>['items'=>$items,'meta'=>$meta,'referencia_batch'=>$referencia_batch]]);
        } else {
            respondJson(['sucesso'=>true,'mensagem'=>'PDF não gerado e template ausente. Retornando data JSON.','data'=>['items'=>$items,'meta'=>$meta,'referencia_batch'=>$referencia_batch]]);
        }
    }

    // Default: return JSON with items and preview HTML if available
    if (class_exists('TemplatePicklist')) {
        $html = TemplatePicklist::render($items, $meta, $refNote, ['company'=>'Minha Empresa', 'show_qr'=>true]);
        respondJson(['sucesso'=>true,'mensagem'=>'Reservations aplicadas','referencia_batch'=>$referencia_batch,'data'=>['items'=>$items,'meta'=>$meta],'html_preview'=>$html]);
    }

    respondJson(['sucesso'=>true,'mensagem'=>'Reservations aplicadas','referencia_batch'=>$referencia_batch,'data'=>['items'=>$items,'meta'=>$meta]]);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>