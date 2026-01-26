<?php
require_once '../config/_protecao.php';

header('Content-Type: application/json');

if (isset($_GET['categoria_id']) && is_numeric($_GET['categoria_id'])) {
    $categoria_id = (int)$_GET['categoria_id'];
    $atributos = [];
    $categoria_ids_a_buscar = [];
    $regras = [];

    // BUSCAR HIERARQUIA DE CATEGORIAS
    $sql_hierarquia = "
        WITH RECURSIVE categoria_hierarquia AS (
            SELECT id, categoria_pai_id, 0 AS nivel
            FROM categorias
            WHERE id = ? AND deletado = FALSE
            
            UNION ALL
            
            SELECT c.id, c.categoria_pai_id, ch.nivel + 1
            FROM categorias c
            INNER JOIN categoria_hierarquia ch ON c.id = ch.categoria_pai_id
            WHERE ch.nivel < 10 AND c.deletado = FALSE
        )
        SELECT id FROM categoria_hierarquia ORDER BY nivel DESC
    "; 
    // ORDER BY nivel DESC: Importante para que a categoria (nível 0) tenha prioridade na sobrescrita se necessário

    $stmt_hier = $conn->prepare($sql_hierarquia);
    if (!$stmt_hier) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao preparar consulta de hierarquia: ' . $conn->error]);
        $conn->close();
        exit;
    }
    
    $stmt_hier->bind_param("i", $categoria_id);
    $stmt_hier->execute();
    $result_hier = $stmt_hier->get_result();
    
    while ($row = $result_hier->fetch_assoc()) {
        $categoria_ids_a_buscar[] = (int)$row['id'];
    }
    $stmt_hier->close();

    if (empty($categoria_ids_a_buscar)) {
        echo json_encode(['sucesso' => true, 'atributos' => [], 'regras' => []]);
        $conn->close();
        exit;
    }

    // BUSCAR ATRIBUTOS (DEFINIÇÕES)
    $in_clause_cats = implode(',', array_fill(0, count($categoria_ids_a_buscar), '?'));
    
    // Busca apenas as definições primeiro
    $sql_attr_def = "
        SELECT DISTINCT
            ad.id,
            ad.nome,
            ad.tipo,
            ca.obrigatorio 
        FROM 
            atributos_definicao ad
        INNER JOIN 
            categoria_atributo ca ON ad.id = ca.atributo_id
        WHERE 
            ca.categoria_id IN ({$in_clause_cats})
    ";

    $stmt_attr = $conn->prepare($sql_attr_def);
    $types = str_repeat('i', count($categoria_ids_a_buscar));
    $stmt_attr->bind_param($types, ...$categoria_ids_a_buscar);
    $stmt_attr->execute();
    $result_attr = $stmt_attr->get_result();

    while ($row = $result_attr->fetch_assoc()) {
        $atributos[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'nome' => $row['nome'],
            'tipo' => $row['tipo'],
            'obrigatorio' => $row['obrigatorio'] ? '1' : '0',
            'opcoes' => [] // Inicializa array de opções vazio (será preenchido abaixo)
        ];
    }
    $stmt_attr->close();

    $atributo_ids = array_keys($atributos);

    // BUSCAR OPÇÕES FILTRADAS POR CATEGORIA (INCLUINDO ID E VALOR)
    if (!empty($atributo_ids)) {
        $in_clause_attrs = implode(',', array_fill(0, count($atributo_ids), '?'));
        // Reutilizamos $in_clause_cats
        
        // Busca opções que estão na tabela mestre (atributos_opcoes)
        // MAS APENAS SE estiverem vinculadas na tabela (categoria_atributo_opcao)
        $sql_opcoes = "
            SELECT DISTINCT
                ao.atributo_id,
                ao.id AS opcao_id,
                ao.valor
            FROM 
                atributos_opcoes ao
            INNER JOIN 
                categoria_atributo_opcao cao ON ao.id = cao.atributo_opcao_id
            WHERE 
                ao.atributo_id IN ({$in_clause_attrs})
                AND cao.categoria_id IN ({$in_clause_cats})
            ORDER BY 
                ao.valor
        ";

        $stmt_op = $conn->prepare($sql_opcoes);
        
        // Bind: IDs dos Atributos + IDs das Categorias
        $types_op = str_repeat('i', count($atributo_ids) + count($categoria_ids_a_buscar));
        $params_op = array_merge($atributo_ids, $categoria_ids_a_buscar);
        
        $stmt_op->bind_param($types_op, ...$params_op);
        $stmt_op->execute();
        $result_op = $stmt_op->get_result();
        
        while ($row = $result_op->fetch_assoc()) {
            $attr_id = (int)$row['atributo_id'];
            // Adiciona a opção ao atributo correspondente (id + valor)
            if (isset($atributos[$attr_id])) {
                $atributos[$attr_id]['opcoes'][] = [
                    'id' => (int)$row['opcao_id'],
                    'valor' => $row['valor']
                ];
            }
        }
        
        $stmt_op->close();

    }

    // BUSCAR REGRAS
    if (!empty($atributo_ids)) {
        $in_clause_attr = implode(',', array_fill(0, count($atributo_ids), '?'));
        $sql_regras = "SELECT atributo_gatilho_id, valor_gatilho, atributo_alvo_id, acao 
                       FROM atributo_regra_condicional 
                       WHERE atributo_gatilho_id IN ({$in_clause_attr}) OR atributo_alvo_id IN ({$in_clause_attr})";
        
        $all_attr_ids = array_merge($atributo_ids, $atributo_ids);
        $types_regras = str_repeat('i', count($all_attr_ids));
        
        $stmt_regras = $conn->prepare($sql_regras);
        $stmt_regras->bind_param($types_regras, ...$all_attr_ids);
        $stmt_regras->execute();
        $result_regras = $stmt_regras->get_result();
        while ($row = $result_regras->fetch_assoc()) {
            $regras[] = $row;
        }
        $stmt_regras->close();
    }

    echo json_encode([
        'sucesso' => true, 
        'atributos' => array_values($atributos), 
        'regras' => $regras
    ], JSON_UNESCAPED_UNICODE);

} else {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID inválido.']);
}
$conn->close();
?>