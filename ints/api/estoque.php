<?php
require_once '../config/_protecao.php';

header('Content-Type: application/json');

$produto_id = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
$local_id = isset($_GET['local_id']) ? (int)$_GET['local_id'] : 0;

if ($produto_id <= 0) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'IDs inválidos']);
    $conn->close();
    exit;
}

// Util: total de estoque (por produto, por local se informado)
function getTotalStock($conn, $produtoId, $localId = null) {
    if ($localId && $localId > 0) {
        $sql = "SELECT COALESCE(SUM(quantidade),0) as qtd FROM estoques WHERE produto_id = ? AND local_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $produtoId, $localId);
    } else {
        $sql = "SELECT COALESCE(SUM(quantidade),0) as qtd FROM estoques WHERE produto_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $produtoId);
    }
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return isset($res['qtd']) ? (float)$res['qtd'] : 0.0;
}

// Função que explode BOM e retorna mapa flat: produto_id => quantidade_total requerida para 'multiplier' unidades do root
function explodeBomFlat($conn, $rootId, $multiplier = 1.0, &$flat = [], $depth = 0, $visited = [], $max_depth = 12) {
    if ($depth > $max_depth) throw new Exception("Profundidade máxima ao explodir BOM.");
    if (in_array($rootId, $visited)) throw new Exception("Ciclo detectado na composição.");
    $visited[] = $rootId;

    $sql = "SELECT pr.subproduto_id, pr.quantidade FROM produto_relacionamento pr WHERE pr.produto_principal_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $rootId);
    $stmt->execute();
    $res = $stmt->get_result();
    $hasChild = false;
    while ($row = $res->fetch_assoc()) {
        $hasChild = true;
        $sid = (int)$row['subproduto_id'];
        $qty = (float)$row['quantidade'] * (float)$multiplier;

        // verifica se sid tem filhos
        $stmt_check = $conn->prepare("SELECT COUNT(*) AS c FROM produto_relacionamento WHERE produto_principal_id = ?");
        $stmt_check->bind_param("i", $sid);
        $stmt_check->execute();
        $rc = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if ($rc && (int)$rc['c'] > 0) {
            explodeBomFlat($conn, $sid, $qty, $flat, $depth + 1, $visited, $max_depth);
        } else {
            if (!isset($flat[$sid])) $flat[$sid] = 0.0;
            $flat[$sid] += $qty;
        }
    }
    $stmt->close();

    if (!$hasChild) {
        // Leaf: the product itself is required
        if (!isset($flat[$rootId])) $flat[$rootId] = 0.0;
        $flat[$rootId] += $multiplier;
    }
    return $flat;
}

// 1) Se o produto controla estoque próprio, comportamento normal por local
$stmt_prod = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
$stmt_prod->bind_param("i", $produto_id);
$stmt_prod->execute();
$prod_row = $stmt_prod->get_result()->fetch_assoc();
$stmt_prod->close();

$controla = isset($prod_row['controla_estoque_proprio']) ? (int)$prod_row['controla_estoque_proprio'] : 1;

if ($controla === 1) {
    // Mantém comportamento atual: retorna quantidade do produto no local (ou 0)
    if ($local_id > 0) {
        $sql = "SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $produto_id, $local_id);
    } else {
        // soma em todos locais
        $sql = "SELECT COALESCE(SUM(quantidade),0) AS quantidade FROM estoques WHERE produto_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $produto_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    $qtd = isset($row['quantidade']) ? (int)$row['quantidade'] : 0;
    echo json_encode(['sucesso' => true, 'quantidade' => (int)$qtd]);
    $conn->close();
    exit;
}

// 2) Produto é kit/BOM — calcula disponibilidade com base nos componentes
try {
    $flat = [];
    explodeBomFlat($conn, $produto_id, 1.0, $flat, 0, []);
    if (empty($flat)) {
        echo json_encode(['sucesso' => true, 'quantidade' => 0, 'mensagem' => 'Sem componentes encontrados.']);
        $conn->close();
        exit;
    }

    $availabilities = [];
    foreach ($flat as $compId => $qtyRequired) {
        // Para cada componente, calcular estoque disponível (se o componente também for kit, exploração recursiva se necessário)
        // Checar se comp controla estoque próprio
        $stmt = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $compId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $comp_controla = isset($r['controla_estoque_proprio']) ? (int)$r['controla_estoque_proprio'] : 1;

        if ($comp_controla === 1) {
            $stock = getTotalStock($conn, $compId, $local_id > 0 ? $local_id : null);
        } else {
            // Componente também é kit: explodir seu BOM e calcular disponibilidade recursivamente
            $nested_flat = [];
            explodeBomFlat($conn, $compId, 1.0, $nested_flat, 0, []);
            // calcular disponibilidade do nested_flat (sum across nested components)
            $minUnits = PHP_INT_MAX;
            foreach ($nested_flat as $nId => $nQty) {
                $nStock = getTotalStock($conn, $nId, $local_id > 0 ? $local_id : null);
                $possible = floor($nStock / $nQty);
                if ($possible < $minUnits) $minUnits = $possible;
            }
            $stock = ($minUnits === PHP_INT_MAX) ? 0 : (float)$minUnits;
            // Note: estoque retornado aqui é em UNIDADES do subproduto (quantidade de subproduto que pode ser produzido)
        }

        if ($qtyRequired <= 0) {
            $possibleUnits = 0;
        } else {
            // quantidade total de unidades do produto raiz que o estoque desse componente permite:
            $possibleUnits = floor($stock / $qtyRequired);
        }
        $availabilities[] = $possibleUnits;
    }

    if (empty($availabilities)) {
        echo json_encode(['sucesso' => true, 'quantidade' => 0]);
    } else {
        $allowed = min($availabilities);
        echo json_encode(['sucesso' => true, 'quantidade' => (int)$allowed]);
    }
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao calcular disponibilidade: ' . $e->getMessage()]);
}

$conn->close();
?>