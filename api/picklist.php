<?php
// API: Gerador de Picklist (ATUALIZADO para usar TemplatePicklist + TCPDF se disponível)
// - Pode gerar picklist a partir de reservas existentes (referencia_tipo + referencia_id OR referencia_batch)
// - Ou aceitar uma lista de allocations via POST (allocations JSON array)
// - Suporta saída JSON (padrão) ou PDF (se TCPDF estiver disponível).
//
// Arquivos relacionados:
// - ints/pdf/TemplatePicklist.php  (HTML template generator)
// - Se desejar PDF direto: instale TCPDF no servidor (composer ou lib).
//
// Exemplos:
// GET  /ints/api/picklist.php?referencia_tipo=kit_reserva&referencia_id=12&format=pdf
// POST /ints/api/picklist.php  action=generate  allocations='[{"produto_id":1,"local_id":2,"qtd":3},...]' format=pdf
//
// Dependências: ../config/_protecao.php que define $conn (mysqli)
//

require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respondJson($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function respondHtmlPreview($html) {
    // Return HTML preview wrapped in JSON field (clients can open in new tab)
    respondJson(['sucesso'=>true, 'html_preview' => $html]);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respondJson(['sucesso'=>false,'mensagem'=>'Conexão com DB não encontrada.'], 500);
}
$conn->set_charset('utf8mb4');

$method = $_SERVER['REQUEST_METHOD'];
$format = isset($_REQUEST['format']) ? strtolower($_REQUEST['format']) : 'json';

try {
    // Helper functions (same as previous implementation)
    function build_from_reservas($conn, $whereClause, $paramsTypes = "", $params = []) {
        $sql = "SELECT r.produto_id, COALESCE(p.nome, CONCAT('Produto ', r.produto_id)) AS produto_nome, "
             . "COALESCE(l.nome, 'Sem local') AS local_nome, r.local_id, SUM(r.quantidade) AS quantidade "
             . "FROM reservas r "
             . "LEFT JOIN produtos p ON p.id = r.produto_id "
             . "LEFT JOIN locais l ON l.id = r.local_id "
             . "WHERE $whereClause "
             . "GROUP BY r.produto_id, r.local_id "
             . "ORDER BY l.nome ASC, p.nome ASC";
        $stmt = $conn->prepare($sql);
        if ($paramsTypes !== "") {
            $stmt->bind_param($paramsTypes, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'produto_id' => (int)$row['produto_id'],
                'nome' => $row['produto_nome'],
                'local_id' => ($row['local_id'] !== null) ? (int)$row['local_id'] : null,
                'local_nome' => $row['local_nome'],
                'quantidade' => (float)$row['quantidade']
            ];
        }
        $stmt->close();
        return $items;
    }

    function build_from_allocations_array($conn, $allocArr) {
        $items = [];
        foreach ($allocArr as $a) {
            $pid = isset($a['produto_id']) ? intval($a['produto_id']) : 0;
            $lid = isset($a['local_id']) && $a['local_id'] !== '' ? intval($a['local_id']) : null;
            $q = isset($a['qtd']) ? floatval($a['qtd']) : (isset($a['quantidade']) ? floatval($a['quantidade']) : 0.0);
            $pname = null; $lname = null;
            if ($pid > 0) {
                $st = $conn->prepare("SELECT nome FROM produtos WHERE id = ? LIMIT 1");
                $st->bind_param("i", $pid);
                $st->execute();
                $p = $st->get_result()->fetch_assoc();
                $st->close();
                $pname = $p['nome'] ?? ("Produto $pid");
            } else {
                $pname = "Produto $pid";
            }
            if (!is_null($lid)) {
                $st2 = $conn->prepare("SELECT nome FROM locais WHERE id = ? LIMIT 1");
                $st2->bind_param("i", $lid);
                $st2->execute();
                $l = $st2->get_result()->fetch_assoc();
                $st2->close();
                $lname = $l['nome'] ?? "Local $lid";
            } else {
                $lname = "Sem local";
            }
            $items[] = [
                'produto_id' => $pid,
                'nome' => $pname,
                'local_id' => $lid,
                'local_nome' => $lname,
                'quantidade' => $q
            ];
        }
        $grouped = [];
        foreach ($items as $it) {
            $key = $it['produto_id'] . '::' . ($it['local_id'] ?? 'null');
            if (!isset($grouped[$key])) $grouped[$key] = $it;
            else $grouped[$key]['quantidade'] += $it['quantidade'];
        }
        return array_values($grouped);
    }

    // Build items based on request
    $items = [];
    $refNote = '';

    if ($method === 'GET') {
        if (isset($_GET['referencia_batch']) && $_GET['referencia_batch'] !== '') {
            $batch = $_GET['referencia_batch'];
            $items = build_from_reservas($conn, "r.referencia_batch = ?", "s", [$batch]);
            $refNote = "Batch: " . $batch;
        } elseif (isset($_GET['referencia_tipo']) && isset($_GET['referencia_id'])) {
            $rt = $_GET['referencia_tipo'];
            $rid = intval($_GET['referencia_id']);
            $items = build_from_reservas($conn, "r.referencia_tipo = ? AND r.referencia_id = ?", "si", [$rt, $rid]);
            $refNote = "Referência: {$rt} #{$rid}";
        } elseif (isset($_GET['referencia_tipo'])) {
            $rt = $_GET['referencia_tipo'];
            $items = build_from_reservas($conn, "r.referencia_tipo = ?", "s", [$rt]);
            $refNote = "Referência: {$rt}";
        } else {
            respondJson(['sucesso'=>false,'mensagem'=>'Parâmetros referencia_tipo+referencia_id ou referencia_batch ou allocations são necessários.'], 400);
        }
    } elseif ($method === 'POST') {
        $action = isset($_POST['action']) ? strtolower($_POST['action']) : null;
        if ($action !== 'generate') respondJson(['sucesso'=>false,'mensagem'=>'POST action inválida. Use action=generate.'], 400);

        if (isset($_POST['allocations']) && $_POST['allocations'] !== '') {
            $allocJson = $_POST['allocations'];
            $allocArr = json_decode($allocJson, true);
            if (!is_array($allocArr)) respondJson(['sucesso'=>false,'mensagem'=>'allocations deve ser um JSON array válido.'], 400);
            $items = build_from_allocations_array($conn, $allocArr);
            $refNote = isset($_POST['note']) ? trim($_POST['note']) : 'Allocations';
        } elseif (isset($_POST['referencia_batch']) && $_POST['referencia_batch'] !== '') {
            $batch = $_POST['referencia_batch'];
            $items = build_from_reservas($conn, "r.referencia_batch = ?", "s", [$batch]);
            $refNote = "Batch: " . $batch;
        } elseif (isset($_POST['referencia_type']) && isset($_POST['referencia_id'])) {
            $rt = $_POST['referencia_type']; $rid = intval($_POST['referencia_id']);
            $items = build_from_reservas($conn, "r.referencia_tipo = ? AND r.referencia_id = ?", "si", [$rt, $rid]);
            $refNote = "Referência: {$rt} #{$rid}";
        } else {
            respondJson(['sucesso'=>false,'mensagem'=>'Para POST action=generate informe allocations (JSON) ou referencia_batch ou referencia_type+referencia_id.'], 400);
        }
    } else {
        respondJson(['sucesso'=>false,'mensagem'=>'Método HTTP não suportado.'], 405);
    }

    if (empty($items)) {
        respondJson(['sucesso'=>false,'mensagem'=>'Nenhum item encontrado para os critérios informados.'], 404);
    }

    $meta = ['total_items' => count($items), 'total_quantity' => 0];
    foreach ($items as $it) $meta['total_quantity'] += $it['quantidade'];

    // Use TemplatePicklist if available
    $templatePath = __DIR__ . '/../pdf/TemplatePicklist.php';
    if (file_exists($templatePath)) require_once $templatePath;

    $options = ['show_qr' => true, 'company' => 'Minha Empresa'];

    // If PDF requested and TCPDF available, render PDF using template
    if ($format === 'pdf' && class_exists('TCPDF') && class_exists('TemplatePicklist')) {
        $html = TemplatePicklist::render($items, $meta, $refNote, $options);

        // create TCPDF and write HTML
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('INTS');
        $pdf->SetAuthor('INTS System');
        $pdf->SetTitle('Picklist');
        $pdf->SetMargins(10, 10, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        // if want to render 2D barcode, TCPDF has write2DBarcode, but it's optional
        $pdf->writeHTML($html, true, false, true, false, '');
        // output PDF to browser (attachment)
        $pdf->Output('picklist.pdf', 'D'); // 'I' to inline view, 'D' to force download
        exit;
    }

    // If PDF requested but TCPDF not available, return HTML inside JSON so client can convert externally
    if ($format === 'pdf') {
        if (class_exists('TemplatePicklist')) {
            $html = TemplatePicklist::render($items, $meta, $refNote, $options);
            respondJson(['sucesso'=>true, 'mensagem'=>'TCPDF não disponível. Retornando HTML para conversão externa.', 'html' => $html, 'data' => ['items'=>$items,'meta'=>$meta]]);
        } else {
            respondJson(['sucesso'=>true,'mensagem'=>'TCPDF não disponível e template ausente. Retornando data JSON.','data'=>['items'=>$items,'meta'=>$meta]]);
        }
    }

    // Default JSON return with HTML preview if template exists
    if (class_exists('TemplatePicklist')) {
        $html = TemplatePicklist::render($items, $meta, $refNote, $options);
        respondJson(['sucesso'=>true,'data'=>['items'=>$items,'meta'=>$meta],'html_preview'=>$html]);
    }

    respondJson(['sucesso'=>true,'data'=>['items'=>$items,'meta'=>$meta]]);

} catch (Exception $e) {
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>