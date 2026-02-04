<?php
require_once '../../config/_protecao.php';

$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

// Se for Admin de Unidade, carrega todos os IDs da hierarquia dessa unidade
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    if (function_exists('getIdsLocaisDaUnidade')) {
        $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
    } else {
        // Fallback caso a função não exista no db.php (embora deva existir)
        $unidade_locais_ids = [$usuario_unidade];
    }
}

// Carregar Filtros (Dropdown de Locais formatados)
$locais_formatados = [];
if (function_exists('getLocaisFormatados')) {
    // Se for admin de unidade, restringe o dropdown apenas à sua unidade
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    // O segundo parâmetro 'false' indica que queremos todos os locais (não apenas salas finais)
    $locais_formatados = getLocaisFormatados($conn, false, $restricao);
}

// Carregar Categorias
$categorias = [];
$sql_cat = "SELECT id, nome FROM categorias WHERE deletado = FALSE ORDER BY nome";
$res_cat = $conn->query($sql_cat);
while ($r = $res_cat->fetch_assoc()) $categorias[] = $r;

// Captura filtros do GET
$filtro_nome = $_GET['busca_nome'] ?? '';
$filtro_cat = $_GET['filtro_categoria'] ?? '';
$filtro_local = $_GET['filtro_local'] ?? '';
$filtro_tipo_posse = $_GET['filtro_tipo_posse'] ?? '';
$filtro_patrimonio = $_GET['busca_patrimonio'] ?? '';

// --- MONTAGEM DA QUERY ---

$sql = "
    SELECT 
        p.id AS produto_id,
        p.nome AS produto_nome,
        p.numero_patrimonio,
        p.tipo_posse,
        p.locador_nome,
        c.nome AS categoria_nome,
        l.id AS local_id,
        IFNULL(l.nome, 'N/A') AS local_nome_simples,
        ad.nome AS atributo_nome,
        COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), 
                 CAST(av.valor_booleano AS CHAR), av.valor_data) AS atributo_valor
    FROM produtos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN estoques e ON p.id = e.produto_id
    LEFT JOIN locais l ON e.local_id = l.id
    LEFT JOIN atributos_valor av ON p.id = av.produto_id
    LEFT JOIN atributos_definicao ad ON av.atributo_id = ad.id
    WHERE p.deletado = FALSE
";

$params = [];
$types = "";

// 1. Filtro por Nome
if (!empty($filtro_nome)) {
    $sql .= " AND p.nome LIKE ?";
    $params[] = "%" . $filtro_nome . "%";
    $types .= "s";
}

// 2. Filtro por Patrimônio
if (!empty($filtro_patrimonio)) {
    $sql .= " AND p.numero_patrimonio LIKE ?";
    $params[] = "%" . $filtro_patrimonio . "%";
    $types .= "s";
}

// 3. Filtro por Categoria
if (!empty($filtro_cat)) {
    $sql .= " AND p.categoria_id = ?";
    $params[] = (int)$filtro_cat;
    $types .= "i";
}

// 4. Filtro por Local (CORREÇÃO HIERÁRQUICA)
if (!empty($filtro_local)) {
    // Aqui está a mágica: Pegamos a árvore completa do local selecionado
    // Ex: Se selecionou "Unidade A", pega IDs da Unidade A + Andares + Salas
    $ids_hierarquia_filtro = [];
    if (function_exists('getIdsLocaisDaUnidade')) {
        $ids_hierarquia_filtro = getIdsLocaisDaUnidade($conn, (int)$filtro_local);
    } else {
        $ids_hierarquia_filtro = [(int)$filtro_local];
    }

    if (!empty($ids_hierarquia_filtro)) {
        $idsStrFiltro = implode(',', array_map('intval', $ids_hierarquia_filtro));
        // Filtra onde o estoque está em QUALQUER um desses locais
        $sql .= " AND e.local_id IN ($idsStrFiltro)";
    }
}

// 5. Filtro por Tipo de Posse
if (!empty($filtro_tipo_posse)) {
    $sql .= " AND p.tipo_posse = ?";
    $params[] = $filtro_tipo_posse;
    $types .= "s";
}

// 6. Restrição de Segurança: Admin de Unidade
// Ele só pode ver produtos que tenham estoque/registro dentro da sua árvore de locais
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStrUnidade = implode(',', array_map('intval', $unidade_locais_ids));
    
    // A lógica aqui é: Mostre o produto SE ele tiver estoque na unidade do admin
    // OU se o admin não estiver filtrando local específico, mostre apenas as linhas de estoque da unidade dele.
    
    // Como estamos fazendo um LEFT JOIN com estoques, filtramos para que as linhas retornadas 
    // sejam pertinentes à unidade.
    $sql .= " AND (e.local_id IS NULL OR e.local_id IN ($idsStrUnidade))";
    
    // Adicionalmente, se quisermos esconder produtos que estão TOTALMENTE em outras unidades:
    $sql .= " AND EXISTS (
        SELECT 1 FROM estoques e2 
        WHERE e2.produto_id = p.id 
        AND e2.local_id IN ($idsStrUnidade)
    )";
}

$sql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Processamento dos dados para agrupar (devido ao JOIN com atributos e locais)
$produtos_agregados = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pid = $row['produto_id'];

        if (!isset($produtos_agregados[$pid])) {
            $produtos_agregados[$pid] = [
                'id' => $pid,
                'nome' => $row['produto_nome'],
                'numero_patrimonio' => $row['numero_patrimonio'],
                'categoria' => $row['categoria_nome'],
                'tipo_posse' => $row['tipo_posse'],
                'locador_nome' => $row['locador_nome'],
                'atributos' => [],
                'estoques' => []
            ];
        }

        // Agrupa atributos
        if (!empty($row['atributo_nome'])) {
            $attr = $row['atributo_nome'];
            $val = $row['atributo_valor'];
            if ($val === '1') $val = 'Sim';
            if ($val === '0') $val = 'Não';
            
            $produtos_agregados[$pid]['atributos'][$attr] = $val;
        }

        // Agrupa estoques/locais
        $lid = $row['local_id'];
        
        // Se estamos filtrando por local (hierárquico), só mostramos estoques dentro desse filtro
        // (A query SQL já filtra, mas verificamos para garantir a exibição correta do nome)
        if ($lid) {
            $nome_local = isset($locais_formatados[$lid]) ? $locais_formatados[$lid] : $row['local_nome_simples'];
            
            // Verifica duplicidade no array de estoques (pode acontecer por causa do JOIN de múltiplos atributos)
            $estoque_existe = false;
            foreach ($produtos_agregados[$pid]['estoques'] as $est) {
                if ($est['id'] == $lid) { $estoque_existe = true; break; }
            }
            
            if (!$estoque_existe) {
                $produtos_agregados[$pid]['estoques'][] = [
                    'id' => $lid,
                    'nome' => $nome_local
                ];
            }
        }
    }
}
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventário - Listagem</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f8f9fa; }
        h1 { color: #333; margin-bottom: 20px; }
        a { text-decoration: none; color: #007bff; }
        a:hover { text-decoration: underline; }
        
        .filter-bar { 
            background: #fff; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
            margin-bottom: 25px; 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: bold; font-size: 0.9em; margin-bottom: 5px; color: #555; }
        .filter-group input, .filter-group select { 
            padding: 8px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
        }
        .btn-filter { 
            padding: 8px 20px; 
            background-color: #28a745; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: bold; 
            align-self: flex-end;
        }
        .btn-filter:hover { background-color: #218838; }
        .btn-clear { 
            padding: 8px 15px; 
            background-color: #6c757d; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            margin-left: 5px; 
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: #fff; 
            border-radius: 8px; 
            overflow: hidden; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        }
        th { 
            background-color: #343a40; 
            color: #fff; 
            padding: 12px; 
            text-align: left; 
            text-transform: uppercase; 
            font-size: 0.85em; 
            letter-spacing: 0.05em; 
        }
        td { 
            padding: 12px; 
            border-bottom: 1px solid #eee; 
            vertical-align: top; 
            color: #333; 
        }
        tr:hover { background-color: #f1f1f1; }
        
        .patrimonio-badge { 
            background-color: #17a2b8; 
            color: white; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.85em; 
            font-weight: bold; 
            font-family: 'Courier New', monospace;
        }
        .locado-badge { 
            background-color: #ffc107; 
            color: #333; 
            padding: 2px 6px; 
            border-radius: 4px; 
            font-size: 0.75em; 
            font-weight: bold; 
            margin-left: 5px; 
        }
        .attr-list { font-size: 0.85em; line-height: 1.4; }
        .no-data { text-align: center; padding: 40px; color: #777; font-style: italic; }
        .action-links a { margin-right: 10px; font-size: 0.9em; }
        .locador-info { font-size: 0.8em; color: #666; margin-top: 2px; }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>

    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Inventário - Lista de Produtos</h1>
        <div>
            <a href="../../index.html">Página Inicial</a> | 
            <a href="cadastrar.php">Cadastrar Novo Item</a>
        </div>
    </div>

    <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] == 'cadastro'): ?>
        <div class="success-message">
            Item cadastrado com sucesso! 
            <?php if (isset($_GET['patrimonio'])): ?>
                <strong>Número de Patrimônio: <?php echo htmlspecialchars($_GET['patrimonio']); ?></strong>
            <?php endif; ?>
        </div>
    <?php elseif (isset($_GET['sucesso']) && $_GET['sucesso'] == 'soft_delete'): ?>
        <div class="success-message">Produto movido para lixeira com sucesso.</div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label>Buscar por Nome:</label>
            <input type="text" name="busca_nome" value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Digite o nome...">
        </div>

        <div class="filter-group">
            <label>Nº Patrimônio:</label>
            <input type="text" name="busca_patrimonio" value="<?php echo htmlspecialchars($filtro_patrimonio); ?>" placeholder="Ex: 001-002...">
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
            <label>Local (Captura Unidade inteira):</label>
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
            <?php if ($filtro_nome || $filtro_cat || $filtro_local || $filtro_tipo_posse || $filtro_patrimonio): ?>
                <a href="listar.php" class="btn-clear">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($produtos_agregados)): ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 140px;">Nº Patrimônio</th>
                    <th style="width: 20%;">Nome do Item</th>
                    <th style="width: 15%;">Categoria</th>
                    <th style="width: 15%;">Atributos</th>
                    <th style="width: 15%;">Localização</th>
                    <th style="width: 15%;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos_agregados as $prod): 
                    $estoques = $prod['estoques'];
                    if (empty($estoques)) $estoques[] = ['id' => null, 'nome' => 'Sem Local Definido'];
                    $rowspan = count($estoques);
                ?>
                    <?php foreach ($estoques as $i => $est): ?>
                    <tr>
                        <?php if ($i === 0): ?>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <?php if (!empty($prod['numero_patrimonio'])): ?>
                                    <span class="patrimonio-badge">
                                        <?php echo htmlspecialchars($prod['numero_patrimonio']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#aaa; font-size:0.8em;">S/N</span>
                                <?php endif; ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <div style="font-weight:bold; font-size:1.05em;">
                                    <a href="detalhes.php?id=<?php echo $prod['id']; ?>">
                                        <?php echo htmlspecialchars($prod['nome']); ?>
                                    </a>
                                    <?php if ($prod['tipo_posse'] == 'locado'): ?>
                                        <span class="locado-badge">LOCADO</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($prod['tipo_posse'] == 'locado' && !empty($prod['locador_nome'])): ?>
                                    <div class="locador-info">
                                        <strong>Locador:</strong> <?php echo htmlspecialchars($prod['locador_nome']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <?php echo htmlspecialchars($prod['categoria']); ?>
                            </td>
                            <td rowspan="<?php echo $rowspan; ?>">
                                <div class="attr-list">
                                    <?php if (empty($prod['atributos'])): ?>
                                        <span style="color:#aaa;">—</span>
                                    <?php else: ?>
                                        <?php foreach ($prod['atributos'] as $nome => $valor): ?>
                                            <strong><?php echo htmlspecialchars($nome); ?>:</strong> 
                                            <?php echo htmlspecialchars($valor); ?><br>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>

                        <td><?php echo htmlspecialchars($est['nome']); ?></td>

                        <?php if ($i === 0): ?>
                            <td rowspan="<?php echo $rowspan; ?>" class="action-links">
                                <a href="detalhes.php?id=<?php echo $prod['id']; ?>">Detalhes</a>
                                <a href="editar.php?id=<?php echo $prod['id']; ?>">Editar</a>
                                <a href="deletar.php?id=<?php echo $prod['id']; ?>" 
                                   onclick="return confirm('Confirmar exclusão?');" 
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
            <p>Nenhum item encontrado com os filtros selecionados.</p>
        </div>
    <?php endif; ?>

</body>
</html>