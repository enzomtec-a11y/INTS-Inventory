<?php
require_once '../config/_protecao.php'; // Ajuste o caminho se necessário

header('Content-Type: application/json');
$conn->set_charset("utf8");

$acao = $_GET['acao'] ?? null;

// --- CASO 1: Buscar Atributos de uma Categoria (com opções incluídas) ---
if ($acao === 'getAtributos' && isset($_GET['categoria_id'])) {
    $categoria_id = (int)$_GET['categoria_id'];
    $atributos = [];
    $categoria_ids_a_buscar = [];
    $regras = [];

    // 1) Buscar hierarquia de categorias (recursivo)
    $sql_hierarquia = "
        WITH RECURSIVE categoria_hierarquia AS (
            SELECT id, categoria_pai_id, 0 AS nivel
            FROM categorias
            WHERE id = ? AND deletado = FALSE
            UNION ALL
            SELECT c.id, c.categoria_pai_id, ch.nivel + 1
            FROM categorias c
            INNER JOIN categoria_hierarquia ch ON c.id = ch.categoria_pai_id
            WHERE ch.nivel < 20 AND c.deletado = FALSE
        )
        SELECT id FROM categoria_hierarquia ORDER BY nivel DESC
    ";
    $stmt_hier = $conn->prepare($sql_hierarquia);
    if (!$stmt_hier) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao preparar consulta de hierarquia: ' . $conn->error]);
        $conn->close();
        exit;
    }
    $stmt_hier->bind_param("i", $categoria_id);
    $stmt_hier->execute();
    $res_hier = $stmt_hier->get_result();
    while ($r = $res_hier->fetch_assoc()) $categoria_ids_a_buscar[] = (int)$r['id'];
    $stmt_hier->close();

    if (empty($categoria_ids_a_buscar)) {
        echo json_encode(['sucesso' => true, 'atributos' => [], 'regras' => []]);
        $conn->close();
        exit;
    }

    // Criar IN clauses seguros (valores já são inteiros)
    $in_clause_cats = implode(',', array_map('intval', $categoria_ids_a_buscar));

    // 2) Buscar definições de atributos vinculados a estas categorias
    $sql_attr_def = "
        SELECT DISTINCT
            ad.id,
            ad.nome,
            ad.tipo,
            ca.obrigatorio
        FROM atributos_definicao ad
        INNER JOIN categoria_atributo ca ON ad.id = ca.atributo_id
        WHERE ca.categoria_id IN ({$in_clause_cats})
        ORDER BY ad.nome
    ";
    $res_attr = $conn->query($sql_attr_def);
    if ($res_attr) {
        while ($row = $res_attr->fetch_assoc()) {
            $atributos[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'tipo' => $row['tipo'],
                'obrigatorio' => $row['obrigatorio'] ? '1' : '0',
                'opcoes' => []
            ];
        }
    }

    // Se não há atributos, retorna já
    if (empty($atributos)) {
        echo json_encode(['sucesso' => true, 'atributos' => [], 'regras' => []], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    // 3) Buscar opções vinculadas (para os atributos encontrados) e anexar por atributo
    $atributo_ids = array_keys($atributos);
    $in_clause_attrs = implode(',', array_map('intval', $atributo_ids));

    // Seleciona opções de atributos que estão vinculadas via categoria_atributo_opcao
    $sql_opcoes = "
        SELECT ao.atributo_id, ao.id AS opcao_id, ao.valor
        FROM atributos_opcoes ao
        INNER JOIN categoria_atributo_opcao cao ON ao.id = cao.atributo_opcao_id
        WHERE ao.atributo_id IN ({$in_clause_attrs})
          AND cao.categoria_id IN ({$in_clause_cats})
        ORDER BY ao.valor
    ";
    $res_op = $conn->query($sql_opcoes);
    if ($res_op) {
        while ($row = $res_op->fetch_assoc()) {
            $aid = (int)$row['atributo_id'];
            if (isset($atributos[$aid])) {
                $atributos[$aid]['opcoes'][] = [
                    'id' => (int)$row['opcao_id'],
                    'valor' => $row['valor']
                ];
            }
        }
    }

    // 4) Buscar regras condicionais envolvendo esses atributos (gatilho/ alvo)
    if (!empty($atributo_ids)) {
        $in_clause_attr = implode(',', array_map('intval', $atributo_ids));
        $sql_regras = "
            SELECT atributo_gatilho_id, valor_gatilho, atributo_alvo_id, acao
            FROM atributo_regra_condicional
            WHERE atributo_gatilho_id IN ({$in_clause_attr})
               OR atributo_alvo_id IN ({$in_clause_attr})
        ";
        $res_regras = $conn->query($sql_regras);
        if ($res_regras) {
            while ($row = $res_regras->fetch_assoc()) {
                $regras[] = $row;
            }
        }
    }

    echo json_encode([
        'sucesso' => true,
        'atributos' => array_values($atributos),
        'regras' => $regras
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();
    exit;
}

// --- CASO 2: Buscar Opções (mantido para compatibilidade) ---
if ($acao == 'getOpcoes' && isset($_GET['categoria_id'], $_GET['atributo_id'])) {
    $categoria_id = (int)$_GET['categoria_id'];
    $atributo_id = (int)$_GET['atributo_id'];

    $opcoes_vinculadas = [];
    $opcoes_mestre = [];

    $sql_vinc = "SELECT ao.id, ao.valor FROM atributos_opcoes ao 
                 JOIN categoria_atributo_opcao cao ON ao.id = cao.atributo_opcao_id 
                 WHERE cao.categoria_id = ? AND ao.atributo_id = ? ORDER BY ao.valor";
    $stmt = $conn->prepare($sql_vinc);
    $stmt->bind_param("ii", $categoria_id, $atributo_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $opcoes_vinculadas[] = $r;
    $stmt->close();

    // Sempre retorna também o mestre (fallback)
    $sql_mest = "SELECT id, valor FROM atributos_opcoes WHERE atributo_id = ? ORDER BY valor";
    $stmt = $conn->prepare($sql_mest);
    $stmt->bind_param("i", $atributo_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $opcoes_mestre[] = $r;
    $stmt->close();

    echo json_encode([
        'sucesso' => true,
        'opcoes_vinculadas' => $opcoes_vinculadas,
        'opcoes_mestre' => $opcoes_mestre
    ]);
    $conn->close();
    exit;
}

echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida.']);
$conn->close();
?>