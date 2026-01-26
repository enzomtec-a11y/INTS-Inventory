<?php
// API: componentes.php
// Retorna a lista de componentes (BOM) de um produto com informações de disponibilidade por local e flags de patrimônio.
//
// GET params:
// - produto_id (required)          -> produto pai cuja BOM será retornada
// - unidades (optional, default=1) -> quantas unidades do produto pai se quer fabricar (multiplica qtd componente)
// - with_locais (0|1)              -> incluir breakdown por local (default 0)
// - include_patrimonios (0|1)      -> incluir contagem de patrimônios disponíveis por componente (default 0)
// - locais_limit (int)             -> limite de linhas por componente para lista de locais (opcional)
//
// Response JSON:
// { sucesso:true, data: { produto_id, unidades, componentes: [ { produto_id, nome, quantidade_por_unidade, quantidade_total, tipo_relacao, controla_estoque_proprio, total_stock, total_reserved, available, ok, locais:[{local_id, local_nome, estoque, reservado, available}], patrimonios_available } ] } }
//
// Requer: ../config/_protecao.php que descreve $conn (mysqli)

require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respond($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(['sucesso'=>false,'mensagem'=>'Conexão com DB não encontrada.'], 500);
}
$conn->set_charset("utf8mb4");

$produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : 0;
$unidades = isset($_GET['unidades']) ? max(1, intval($_GET['unidades'])) : 1;
$with_locais = isset($_GET['with_locais']) && ($_GET['with_locais'] === '1' || strtolower($_GET['with_locais']) === 'true');
$include_patrimonios = isset($_GET['include_patrimonios']) && ($_GET['include_patrimonios'] === '1' || strtolower($_GET['include_patrimonios']) === 'true');
$locais_limit = isset($_GET['locais_limit']) ? intval($_GET['locais_limit']) : null;

if ($produto_id <= 0) respond(['sucesso'=>false,'mensagem'=>'Parâmetro produto_id é obrigatório e deve ser > 0.'], 400);

try {
    // 1) Buscar componentes (produto_relacionamento)
    $sql = "SELECT pr.subproduto_id AS produto_id, pr.quantidade AS quantidade_por_unidade, pr.tipo_relacao, p.nome, p.controla_estoque_proprio
            FROM produto_relacionamento pr
            LEFT JOIN produtos p ON p.id = pr.subproduto_id
            WHERE pr.produto_principal_id = ?
            ORDER BY p.nome ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $components = [];
    $subIds = [];
    while ($row = $res->fetch_assoc()) {
        $subId = (int)$row['produto_id'];
        $subIds[] = $subId;
        $components[$subId] = [
            'produto_id' => $subId,
            'nome' => $row['nome'] ?? ("Produto $subId"),
            'quantidade_por_unidade' => (float)$row['quantidade_por_unidade'],
            'quantidade_total' => (float)$row['quantidade_por_unidade'] * $unidades,
            'tipo_relacao' => $row['tipo_relacao'],
            'controla_estoque_proprio' => (int)($row['controla_estoque_proprio'] ?? 0),
            'total_stock' => 0.0,
            'total_reserved' => 0.0,
            'available' => 0.0,
            'ok' => false,
            'locais' => [],
            'patrimonios_available' => null
        ];
    }
    $stmt->close();

    // If no components, return empty list
    if (empty($components)) {
        respond(['sucesso'=>true,'data'=>['produto_id'=>$produto_id,'unidades'=>$unidades,'componentes'=>[]]]);
    }

    // Prepare statements reused
    $stmt_total_stock = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS total FROM estoques WHERE produto_id = ?");
    $stmt_total_reserved = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS reservado FROM reservas WHERE produto_id = ?");
    $stmt_locais = $conn->prepare("SELECT e.local_id, COALESCE(l.nome,'Sem local') AS local_nome, e.quantidade
                                   FROM estoques e LEFT JOIN locais l ON l.id = e.local_id
                                   WHERE e.produto_id = ? ORDER BY e.quantidade DESC" . (is_int($locais_limit) && $locais_limit>0 ? " LIMIT ".$locais_limit : ""));
    // patrimonios available count
    if ($include_patrimonios) {
        $stmt_patr_avail = $conn->prepare("
            SELECT COUNT(*) AS c FROM patrimonios p
            WHERE p.produto_id = ? AND p.status = 'ativo'
              AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.referencia_tipo = 'patrimonio' AND r.referencia_id = p.id)
        ");
    }

    // For each component compute stocks, reservations and optionally locais/patrimonios
    foreach ($components as $subId => &$comp) {
        // total stock
        $stmt_total_stock->bind_param("i", $subId);
        $stmt_total_stock->execute();
        $r1 = $stmt_total_stock->get_result()->fetch_assoc();
        $stmt_total_stock->free_result();
        $totalStock = isset($r1['total']) ? (float)$r1['total'] : 0.0;

        // total reserved
        $stmt_total_reserved->bind_param("i", $subId);
        $stmt_total_reserved->execute();
        $r2 = $stmt_total_reserved->get_result()->fetch_assoc();
        $stmt_total_reserved->free_result();
        $totalReserved = isset($r2['reservado']) ? (float)$r2['reservado'] : 0.0;

        $available = max(0.0, $totalStock - $totalReserved);

        $comp['total_stock'] = $totalStock;
        $comp['total_reserved'] = $totalReserved;
        $comp['available'] = $available;
        $comp['ok'] = ($available >= $comp['quantidade_total']);

        if ($with_locais) {
            $stmt_locais->bind_param("i", $subId);
            $stmt_locais->execute();
            $rLoc = $stmt_locais->get_result();
            $locs = [];
            while ($lr = $rLoc->fetch_assoc()) {
                $lid = $lr['local_id'] !== null ? (int)$lr['local_id'] : null;
                $estoque_local = (float)$lr['quantidade'];

                // reserved at this local
                $stmt_reserved_local = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS reservado FROM reservas WHERE produto_id = ? AND local_id = ?");
                $stmt_reserved_local->bind_param("ii", $subId, $lid);
                $stmt_reserved_local->execute();
                $rr = $stmt_reserved_local->get_result()->fetch_assoc();
                $stmt_reserved_local->close();
                $reserved_local = isset($rr['reservado']) ? (float)$rr['reservado'] : 0.0;

                $available_local = max(0.0, $estoque_local - $reserved_local);
                $locs[] = [
                    'local_id' => $lid,
                    'local_nome' => $lr['local_nome'],
                    'estoque' => $estoque_local,
                    'reservado' => $reserved_local,
                    'available' => $available_local
                ];
            }
            $comp['locais'] = $locs;
            $stmt_locais->free_result();
        }

        if ($include_patrimonios) {
            $stmt_patr_avail->bind_param("i", $subId);
            $stmt_patr_avail->execute();
            $rpa = $stmt_patr_avail->get_result()->fetch_assoc();
            $stmt_patr_avail->free_result();
            $comp['patrimonios_available'] = isset($rpa['c']) ? (int)$rpa['c'] : 0;
        }
    }
    unset($comp); // break reference

    // Close prepared stmts
    $stmt_total_stock->close();
    $stmt_total_reserved->close();
    $stmt_locais->close();
    if ($include_patrimonios) $stmt_patr_avail->close();

    // Build response array preserving order
    $out = array_values($components);

    respond(['sucesso'=>true, 'data'=>['produto_id'=>$produto_id, 'unidades'=>$unidades, 'componentes'=>$out]]);

} catch (Exception $e) {
    respond(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>