<?php
require_once '../../config/_protecao.php';

// --- 1. PREPARAÇÃO DOS FILTROS (Dropdowns) ---

// Carregar Categorias
$categorias = [];
$sql_cat = "SELECT id, nome FROM categorias WHERE deletado = FALSE ORDER BY nome";
$res_cat = $conn->query($sql_cat);
while ($r = $res_cat->fetch_assoc()) $categorias[] = $r;

// Carregar Locais (Hierárquicos)
$locais_formatados = [];
if (function_exists('getLocaisFormatados')) {
    $locais_formatados = getLocaisFormatados($conn, false); // false = traz todos
}

// --- 2. CAPTURA DOS FILTROS DO GET ---
$filtro_nome = $_GET['busca_nome'] ?? '';
$filtro_cat = $_GET['filtro_categoria'] ?? '';
$filtro_local = $_GET['filtro_local'] ?? '';
$filtro_tipo_posse = $_GET['filtro_tipo_posse'] ?? '';

// --- 3. CONSTRUÇÃO DA CONSULTA SQL DINÂMICA ---

// Base da Query
$sql = "
    SELECT 
        p.id AS produto_id,
        p.nome AS produto_nome,
        p.controla_estoque_proprio,
        p.tipo_posse,
        p.locador_nome,
        c.nome AS categoria_nome,
        
        l.id AS local_id,
        IFNULL(l.nome, 'N/A') AS local_nome_simples,
        IFNULL(e.quantidade, 0) AS quantidade_em_estoque,
        
        ad.nome AS atributo_nome,
        COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) AS atributo_valor
        
    FROM produtos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN estoques e ON p.id = e.produto_id
    LEFT JOIN locais l ON e.local_id = l.id
    LEFT JOIN atributos_valor av ON p.id = av.produto_id
    LEFT JOIN atributos_definicao ad ON av.atributo_id = ad.id
    WHERE p.deletado = FALSE
";

// Aplicação dos Filtros
$params = [];
$types = "";

if (!empty($filtro_nome)) {
    $sql .= " AND p.nome LIKE ?";
    $params[] = "%" . $filtro_nome . "%";
    $types .= "s";
}

if (!empty($filtro_cat)) {
    $sql .= " AND p.categoria_id = ?";
    $params[] = (int)$filtro_cat;
    $types .= "i";
}

if (!empty($filtro_local)) {
    // Filtra pelo local específico OU seus filhos (opcional, aqui filtra exato)
    $sql .= " AND e.local_id = ?";
    $params[] = (int)$filtro_local;
    $types .= "i";
}

if (!empty($filtro_tipo_posse)) {
    $sql .= " AND p.tipo_posse = ?";
    $params[] = $filtro_tipo_posse;
    $types .= "s";
}

$sql .= " ORDER BY p.nome, l.nome";

// Execução da Query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- 4. AGREGAÇÃO DE DADOS E BUSCA DE COMPONENTES DE KITS ---

$produtos_agregados = [];
$produtos_ids_encontrados = []; // Para buscar componentes em lote depois

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pid = $row['produto_id'];
        $produtos_ids_encontrados[$pid] = true; // Marca ID para busca de componentes

        if (!isset($produtos_agregados[$pid])) {
            $produtos_agregados[$pid] = [
                'id' => $pid,
                'nome' => $row['produto_nome'],
                'categoria' => $row['categoria_nome'],
                'tipo_posse' => $row['tipo_posse'],
                'locador_nome' => $row['locador_nome'],
                'e_kit' => ($row['controla_estoque_proprio'] == 0), // Flag de Kit
                'atributos' => [],
                'estoques' => [],
                'componentes' => [] // Será preenchido depois
            ];
        }

        // Atributos
        if (!empty($row['atributo_nome'])) {
            $attr = $row['atributo_nome'];
            // Formatação visual de booleanos
            $val = $row['atributo_valor'];
            if ($val === '1') $val = 'Sim';
            if ($val === '0') $val = 'Não';
            
            $produtos_agregados[$pid]['atributos'][$attr] = $val;
        }

        // Estoque
        $lid = $row['local_id'];
        // Usa mapa de hierarquia se disponível
        $nome_local = ($lid && isset($locais_formatados[$lid])) ? $locais_formatados[$lid] : $row['local_nome_simples'];
        
        // Evita duplicar local se vier múltiplas linhas de atributos
        $estoque_existe = false;
        foreach ($produtos_agregados[$pid]['estoques'] as $est) {
            if ($est['id'] == $lid) { $estoque_existe = true; break; }
        }
        
        if (!$estoque_existe) {
            // Se for kit virtual, o estoque é calculado (pode mostrar "-" ou calc)
            // Aqui mostramos o físico se houver, ou 0.
            $produtos_agregados[$pid]['estoques'][] = [
                'id' => $lid,
                'nome' => $nome_local,
                'qtd' => $row['quantidade_em_estoque']
            ];
        }
    }
}
$stmt->close();

// --- 5. BUSCAR COMPONENTES PARA OS PRODUTOS LISTADOS ---
if (!empty($produtos_ids_encontrados)) {
    $ids_str = implode(',', array_keys($produtos_ids_encontrados));
    $sql_comp = "
        SELECT pr.produto_principal_id, p.nome AS componente_nome, pr.quantidade, pr.tipo_relacao
        FROM produto_relacionamento pr
        JOIN produtos p ON pr.subproduto_id = p.id
        WHERE pr.produto_principal_id IN ($ids_str)
    ";
    $res_comp = $conn->query($sql_comp);
    while ($row = $res_comp->fetch_assoc()) {
        $pai_id = $row['produto_principal_id'];
        if (isset($produtos_agregados[$pai_id])) {
            $produtos_agregados[$pai_id]['componentes'][] = $row;
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Produtos e Estoques</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f8f9fa; }
        h1 { color: #333; margin-bottom: 20px; }
        a { text-decoration: none; color: #007bff; }
        a:hover { text-decoration: underline; }
        
        /* Estilos do Filtro */
        .filter-bar { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: bold; font-size: 0.9em; margin-bottom: 5px; color: #555; }
        .filter-group input, .filter-group select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 200px; }
        .btn-filter { padding: 8px 20px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-filter:hover { background-color: #218838; }
        .btn-clear { padding: 8px 15px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 5px; }

        /* Tabela */
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th { background-color: #343a40; color: #fff; padding: 12px; text-align: left; text-transform: uppercase; font-size: 0.85em; letter-spacing: 0.05em; }
        td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: top; color: #333; }
        tr:hover { background-color: #f1f1f1; }
        
        .kit-badge { background-color: #ffc107; color: #333; padding: 2px 6px; border-radius: 4px; font-size: 0.75em; font-weight: bold; margin-left: 5px; }
        .locado-badge { background-color: #17a2b8; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.75em; font-weight: bold; margin-left: 5px; }
        .component-list { font-size: 0.85em; color: #666; margin-top: 5px; padding-left: 10px; border-left: 2px solid #ddd; }
        .estoque-positivo { color: #28a745; font-weight: bold; }
        .estoque-zero { color: #dc3545; font-weight: bold; }
        .attr-list { font-size: 0.85em; line-height: 1.4; }
        .no-data { text-align: center; padding: 40px; color: #777; font-style: italic; }
        .action-links a { margin-right: 10px; font-size: 0.9em; }
        .locador-info { font-size: 0.8em; color: #666; margin-top: 2px; }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Relatório de Produtos e Estoques</h1>
        <div>
            <a href="../../index.html">Página Inicial</a> | 
            <a href="cadastrar.php">Cadastrar Novo Produto</a>
        </div>
    </div>

    <?php if (isset($_GET['sucesso'])): ?>
        <div style="background:#d4edda; color:#155724; padding:10px; border-radius:4px; margin-bottom:20px;">Ação realizada com sucesso!</div>
    <?php endif; ?>
    <?php if (isset($_GET['erro'])): ?>
        <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:4px; margin-bottom:20px;">Erro na operação.</div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label>Buscar por Nome:</label>
            <input type="text" name="busca_nome" value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Digite o nome...">
        </div>

        <div class="filter-group">
            <label>Categoria:</label>
            <select name="filtro_categoria">
                <option value="">Todas as Categorias</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($filtro_cat == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Tipo de Posse:</label>
            <select name="filtro_tipo_posse">
                <option value="">Todos os Tipos</option>
                <option value="proprio" <?php echo ($filtro_tipo_posse == 'proprio') ? 'selected' : ''; ?>>Próprio</option>
                <option value="locado" <?php echo ($filtro_tipo_posse == 'locado') ? 'selected' : ''; ?>>Locado</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Local:</label>
            <select name="filtro_local">
                <option value="">Todos os Locais</option>
                <?php foreach ($locais_formatados as $id => $nome): ?>
                    <option value="<?php echo $id; ?>" <?php echo ($filtro_local == $id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nome); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="btn-filter">Filtrar</button>
            <?php if ($filtro_nome || $filtro_cat || $filtro_local || $filtro_tipo_posse): ?>
                <a href="listar.php" class="btn-clear">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($produtos_agregados)): ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 20%;">Nome do Produto</th>
                    <th style="width: 15%;">Categoria</th>
                    <th style="width: 10%;">Tipo de Posse</th>
                    <th style="width: 15%;">Atributos</th>
                    <th style="width: 15%;">Local de Estoque</th>
                    <th style="width: 5%;">Qtd.</th>
                    <th style="width: 15%;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos_agregados as $prod): 
                    $estoques = $prod['estoques'];
                    // Se vazio (caso de produto sem estoque lançado), cria linha dummy
                    if (empty($estoques)) $estoques[] = ['id' => null, 'nome' => 'N/A', 'qtd' => 0];
                    
                    $rowspan = count($estoques);
                ?>
                    <?php foreach ($estoques as $i => $est): ?>
                    <tr>
                        <?php if ($i === 0): ?>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <strong><?php echo $prod['id']; ?></strong>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <div style="font-weight:bold; font-size:1.1em;">
                                    <a href="detalhes.php?id=<?php echo $prod['id']; ?>">
                                        <?php echo htmlspecialchars($prod['nome']); ?>
                                    </a>
                                    <?php if ($prod['e_kit']): ?>
                                        <span class="kit-badge">KIT</span>
                                    <?php endif; ?>
                                    <?php if ($prod['tipo_posse'] == 'locado'): ?>
                                        <span class="locado-badge">LOCADO</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($prod['tipo_posse'] == 'locado' && !empty($prod['locador_nome'])): ?>
                                    <div class="locador-info">
                                        <strong>Locador:</strong> <?php echo htmlspecialchars($prod['locador_nome']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($prod['componentes'])): ?>
                                    <div class="component-list">
                                        <strong>Composição:</strong><br>
                                        <?php foreach ($prod['componentes'] as $comp): ?>
                                            • <?php echo htmlspecialchars($comp['componente_nome']); ?> (<?php echo $comp['quantidade']; ?>x)<br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <?php echo htmlspecialchars($prod['categoria']); ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <?php 
                                $tipo_posse = $prod['tipo_posse'] ?? 'proprio';
                                echo $tipo_posse == 'locado' ? 'Locado' : 'Próprio';
                                ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <div class="attr-list">
                                    <?php if (empty($prod['atributos'])): ?>
                                        <span style="color:#aaa;">Sem atributos</span>
                                    <?php else: ?>
                                        <?php foreach ($prod['atributos'] as $nome => $valor): ?>
                                            <strong><?php echo htmlspecialchars($nome); ?>:</strong> <?php echo htmlspecialchars($valor); ?><br>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>

                        <td><?php echo htmlspecialchars($est['nome']); ?></td>
                        <td class="<?php echo ($est['qtd'] > 0 ? 'estoque-positivo' : 'estoque-zero'); ?>">
                            <?php echo $est['qtd']; ?>
                        </td>

                        <?php if ($i === 0): ?>
                            <td rowspan="<?php echo $rowspan; ?>" class="action-links">
                                <a href="detalhes.php?id=<?php echo $prod['id']; ?>">Detalhes</a>
                                <a href="editar.php?id=<?php echo $prod['id']; ?>">Editar</a>
                                <a href="deletar.php?id=<?php echo $prod['id']; ?>" 
                                   onclick="return confirm('Tem certeza que deseja EXCLUIR este produto?');" 
                                   style="color:#dc3545;">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <p>Nenhum produto encontrado com os filtros selecionados.</p>
        </div>
    <?php endif; ?>

</body>
</html>