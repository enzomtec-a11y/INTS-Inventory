<?php
require_once '../../config/_protecao.php';

$usuario_nivel    = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade  = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

$is_admin        = in_array($usuario_nivel, ['admin', 'admin_unidade', 'gestor']);
$is_admin_global = ($usuario_nivel === 'admin');
$can_edit        = in_array($usuario_nivel, ['admin', 'admin_unidade']);

if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    if (function_exists('getIdsLocaisDaUnidade')) {
        $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
    } else {
        $unidade_locais_ids = [$usuario_unidade];
    }
}

// Dados para dropdowns
$locais_formatados = [];
if (function_exists('getLocaisFormatados')) {
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    $locais_formatados = getLocaisFormatados($conn, false, $restricao);
}

$categorias_raiz = [];
$res_raiz = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
if ($res_raiz) while ($r = $res_raiz->fetch_assoc()) $categorias_raiz[] = $r;

// Captura filtros básicos
$filtro_id          = $_GET['busca_id']          ?? '';
$filtro_nome        = $_GET['busca_nome']         ?? '';
$filtro_cat         = $_GET['filtro_categoria']   ?? '';
$filtro_local       = $_GET['filtro_local']       ?? '';
$filtro_tipo_posse  = $_GET['filtro_tipo_posse']  ?? '';
$filtro_patrimonio  = $_GET['busca_patrimonio']   ?? '';
$filtro_status      = $_GET['filtro_status']      ?? '';
$filtro_baixados    = $_GET['filtro_baixados']    ?? '0';

// Filtros avançados
$filtro_locador  = $_GET['filtro_locador']  ?? '';
$filtro_contrato = $_GET['filtro_contrato'] ?? '';

// Paginação
$pagina_atual     = max(1, (int)($_GET['pagina'] ?? 1));
$itens_por_pagina = 50;

// Contagem de filtros ativos (total e somente avançados para badge)
$filtros_ativos = 0;
if (!empty($filtro_id))         $filtros_ativos++;
if (!empty($filtro_nome))       $filtros_ativos++;
if (!empty($filtro_patrimonio)) $filtros_ativos++;
if (!empty($filtro_local))      $filtros_ativos++;
if (!empty($filtro_status))     $filtros_ativos++;
if ($filtro_baixados === '1')   $filtros_ativos++;
if (!empty($filtro_tipo_posse)) $filtros_ativos++;
if (!empty($filtro_locador))    $filtros_ativos++;
if (!empty($filtro_contrato))   $filtros_ativos++;
if (!empty($filtro_cat))        $filtros_ativos++;

// Filtros avançados ativos (para badge no botão)
$filtros_avancados_ativos = 0;
if (!empty($filtro_tipo_posse)) $filtros_avancados_ativos++;
if (!empty($filtro_locador))    $filtros_avancados_ativos++;
if (!empty($filtro_contrato))   $filtros_avancados_ativos++;
if (!empty($filtro_cat))        $filtros_avancados_ativos++;

// =============================
// MONTAGEM DO WHERE
// =============================
$where_conditions = ["p.deletado = FALSE"];
$params = [];
$types  = "";

if ($filtro_baixados === '0' && empty($filtro_status)) {
    $where_conditions[] = "p.status_produto != 'baixa_total'";
}

if (!empty($filtro_id)) {
    $where_conditions[] = "p.id = ?";
    $params[] = (int)$filtro_id;
    $types .= "i";
}
if (!empty($filtro_nome)) {
    $where_conditions[] = "p.nome LIKE ?";
    $params[] = "%$filtro_nome%";
    $types .= "s";
}
if (!empty($filtro_patrimonio)) {
    $where_conditions[] = "p.numero_patrimonio LIKE ?";
    $params[] = "%$filtro_patrimonio%";
    $types .= "s";
}
if (!empty($filtro_cat)) {
    $ids_hierarquia_cat = function_exists('getIdsCategoriasDaHierarquia')
        ? getIdsCategoriasDaHierarquia($conn, (int)$filtro_cat)
        : [(int)$filtro_cat];
    if (!empty($ids_hierarquia_cat)) {
        $idsStrCat = implode(',', array_map('intval', $ids_hierarquia_cat));
        $where_conditions[] = "p.categoria_id IN ($idsStrCat)";
    }
}
if (!empty($filtro_local)) {
    $ids_hierarquia_filtro = function_exists('getIdsLocaisDaUnidade')
        ? getIdsLocaisDaUnidade($conn, (int)$filtro_local)
        : [(int)$filtro_local];
    if (!empty($ids_hierarquia_filtro)) {
        $idsStrFiltro = implode(',', array_map('intval', $ids_hierarquia_filtro));
        $where_conditions[] = "e.local_id IN ($idsStrFiltro)";
    }
}
if (!empty($filtro_tipo_posse)) {
    $where_conditions[] = "p.tipo_posse = ?";
    $params[] = $filtro_tipo_posse;
    $types .= "s";
}
if (!empty($filtro_status)) {
    $where_conditions[] = "p.status_produto = ?";
    $params[] = $filtro_status;
    $types .= "s";
}
if (!empty($filtro_locador)) {
    $where_conditions[] = "p.locador_nome LIKE ?";
    $params[] = "%$filtro_locador%";
    $types .= "s";
}
if (!empty($filtro_contrato)) {
    $where_conditions[] = "p.numero_contrato LIKE ?";
    $params[] = "%$filtro_contrato%";
    $types .= "s";
}
// Restrição de unidade
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsUnidade = implode(',', array_map('intval', $unidade_locais_ids));
    $where_conditions[] = "e.local_id IN ($idsUnidade)";
}

$where_sql = "WHERE " . implode(" AND ", $where_conditions);

$base_joins = "
    FROM produtos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN estoques e ON p.id = e.produto_id AND e.quantidade > 0
    LEFT JOIN locais l ON e.local_id = l.id
";

// =============================
// COUNT TOTAL
// =============================
$total_items = 0;
$stmt_count = $conn->prepare("SELECT COUNT(DISTINCT p.id) AS total $base_joins $where_sql");
if ($stmt_count) {
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_items = (int)$stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
}

$total_paginas = max(1, (int)ceil($total_items / $itens_por_pagina));
$pagina_atual  = min($pagina_atual, $total_paginas);
$offset        = ($pagina_atual - 1) * $itens_por_pagina;

// =============================
// IDs DA PÁGINA ATUAL
// =============================
$page_ids = [];
$params_ids = array_merge($params, [$itens_por_pagina, $offset]);
$types_ids  = $types . "ii";

$stmt_ids = $conn->prepare("SELECT DISTINCT p.id $base_joins $where_sql ORDER BY p.nome ASC, p.id ASC LIMIT ? OFFSET ?");
if ($stmt_ids) {
    $stmt_ids->bind_param($types_ids, ...$params_ids);
    $stmt_ids->execute();
    $res_ids = $stmt_ids->get_result();
    while ($r = $res_ids->fetch_assoc()) $page_ids[] = (int)$r['id'];
    $stmt_ids->close();
}

// =============================
// QUERY PRINCIPAL (dados completos da página)
// =============================
$produtos_agregados = [];

if (!empty($page_ids)) {
    $ids_str = implode(',', $page_ids);

    $sql_main = "
        SELECT
            p.id AS produto_id,
            p.nome AS produto_nome,
            p.numero_patrimonio,
            p.tipo_posse,
            p.locador_nome,
            p.numero_contrato,
            p.status_produto,
            c.nome AS categoria_nome,
            l.id   AS local_id,
            IFNULL(l.nome, 'N/A') AS local_nome_simples,
            ad.id   AS atributo_id,
            ad.nome AS atributo_nome,
            ad.tipo AS atributo_tipo,
            COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) AS atributo_valor
        FROM produtos p
        JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN estoques e ON p.id = e.produto_id AND e.quantidade > 0
        LEFT JOIN locais l ON e.local_id = l.id
        LEFT JOIN atributos_valor av ON p.id = av.produto_id
        LEFT JOIN atributos_definicao ad ON av.atributo_id = ad.id
        WHERE p.id IN ($ids_str)
        ORDER BY p.nome ASC, p.id ASC, ad.id ASC
    ";

    $result = $conn->query($sql_main);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pid = $row['produto_id'];
            if (!isset($produtos_agregados[$pid])) {
                $produtos_agregados[$pid] = [
                    'produto_id'        => $pid,
                    'produto_nome'      => $row['produto_nome'],
                    'numero_patrimonio' => $row['numero_patrimonio'],
                    'tipo_posse'        => $row['tipo_posse'],
                    'locador_nome'      => $row['locador_nome'],
                    'numero_contrato'   => $row['numero_contrato'],
                    'status_produto'    => $row['status_produto'] ?? 'ativo',
                    'categoria_nome'    => $row['categoria_nome'],
                    'atributos'         => [],
                    'estoques'          => [],
                ];
            }
            if (!empty($row['atributo_nome'])) {
                $val = $row['atributo_valor'];
                if ($val === '1') $val = 'Sim';
                if ($val === '0') $val = 'Não';
                $produtos_agregados[$pid]['atributos'][$row['atributo_nome']] = $val;
            }
            $lid = $row['local_id'];
            if ($lid) {
                $nome_local = isset($locais_formatados[$lid]) ? $locais_formatados[$lid] : $row['local_nome_simples'];
                $ja_existe  = false;
                foreach ($produtos_agregados[$pid]['estoques'] as $est) {
                    if ($est['id'] == $lid) { $ja_existe = true; break; }
                }
                if (!$ja_existe) $produtos_agregados[$pid]['estoques'][] = ['id' => $lid, 'nome' => $nome_local];
            }
        }
    }
}

// =============================
// PATH DA CATEGORIA SELECIONADA (para pré-popular o seletor no modal)
// =============================
$categoria_path_json = '[]';
$categoria_breadcrumb_display = '';
if (!empty($filtro_cat)) {
    $cat_path = [];
    $id_tmp   = (int)$filtro_cat;
    $visited  = [];
    while ($id_tmp > 0 && !in_array($id_tmp, $visited)) {
        $visited[] = $id_tmp;
        $res_tmp = $conn->query("SELECT id, nome, categoria_pai_id FROM categorias WHERE id = $id_tmp AND deletado = FALSE");
        if (!$res_tmp || $res_tmp->num_rows === 0) break;
        $row_tmp = $res_tmp->fetch_assoc();
        array_unshift($cat_path, ['id' => (int)$row_tmp['id'], 'nome' => $row_tmp['nome']]);
        $id_tmp = (int)($row_tmp['categoria_pai_id'] ?? 0);
    }
    $categoria_path_json      = json_encode($cat_path, JSON_UNESCAPED_UNICODE);
    $categoria_breadcrumb_display = implode(' › ', array_column($cat_path, 'nome'));
}

// URL base sem paginação (para links de paginação)
$query_params_pg = $_GET;
unset($query_params_pg['pagina']);
$url_base_pg = http_build_query($query_params_pg);

// Função de paginação
function renderPaginacao(int $pagina_atual, int $total_paginas, string $url_base): string {
    if ($total_paginas <= 1) return '';

    $sep = $url_base ? '&' : '';
    $mk  = fn($p) => "?{$url_base}{$sep}pagina={$p}";

    $html  = '<div class="pagination-container">';

    // ← Anterior
    if ($pagina_atual > 1) {
        $html .= '<a href="' . $mk($pagina_atual - 1) . '" class="pg-btn pg-arrow">‹</a>';
    } else {
        $html .= '<span class="pg-btn pg-arrow disabled">‹</span>';
    }

    // Janela de páginas: sempre mostra 1ª, última e ±2 ao redor da atual
    $pages = [];
    for ($i = 1; $i <= $total_paginas; $i++) {
        if ($i === 1 || $i === $total_paginas || ($i >= $pagina_atual - 2 && $i <= $pagina_atual + 2)) {
            $pages[] = $i;
        }
    }
    $pages = array_unique($pages);
    sort($pages);

    $last = 0;
    foreach ($pages as $p) {
        if ($last > 0 && $p - $last > 1) {
            $html .= '<span class="pg-ellipsis">…</span>';
        }
        if ($p === $pagina_atual) {
            $html .= '<span class="pg-btn active">' . $p . '</span>';
        } else {
            $html .= '<a href="' . $mk($p) . '" class="pg-btn">' . $p . '</a>';
        }
        $last = $p;
    }

    // → Próxima
    if ($pagina_atual < $total_paginas) {
        $html .= '<a href="' . $mk($pagina_atual + 1) . '" class="pg-btn pg-arrow">›</a>';
    } else {
        $html .= '<span class="pg-btn pg-arrow disabled">›</span>';
    }

    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciador de Produtos</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background-color: #f4f6f9; display: flex; height: 100vh; overflow: hidden; }

        /* ── Sidebar ── */
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; flex-shrink: 0; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; font-size: 0.9em; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .sidebar .sidebar-divider { border-top: 1px solid #4b545c; margin: 10px 0; }

        /* ── Layout principal ── */
        .main-content { flex: 1; padding: 20px; overflow-y: auto; position: relative; }
        .top-bar { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .top-header h1 { margin: 0; font-size: 1.5rem; color: #2c3e50; }
        .action-buttons { display: flex; gap: 10px; }
        .btn-novo { background: #28a745; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; font-size: 0.9rem; }
        .btn-novo:hover { background: #218838; }

        /* ── Filtros básicos ── */
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; align-items: end; }
        .filter-grid input, .filter-grid select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 0.9em; }
        .filter-grid label { font-size: 0.82em; font-weight: bold; color: #555; display: block; margin-bottom: 3px; }
        .btn-filter { background: #007bff; color: white; border: none; padding: 9px 16px; border-radius: 4px; cursor: pointer; font-size: 0.9em; white-space: nowrap; }
        .btn-filter:hover { background: #0056b3; }
        .btn-filter-advanced { background: #6f42c1; color: white; border: none; padding: 9px 16px; border-radius: 4px; cursor: pointer; font-size: 0.9em; position: relative; white-space: nowrap; }
        .btn-filter-advanced:hover { background: #5a32a3; }
        .filter-badge { background: #ff4757; color: white; border-radius: 50%; padding: 1px 6px; font-size: 0.75em; position: absolute; top: -6px; right: -6px; font-weight: bold; }
        .toggle-baixados { display: flex; align-items: center; gap: 6px; font-size: 0.85em; color: #555; cursor: pointer; }
        .cat-active-badge { background: #e8f4fd; color: #1976d2; border: 1px solid #90caf9; border-radius: 4px; padding: 4px 10px; font-size: 0.82em; display: inline-flex; align-items: center; gap: 6px; }
        .cat-active-badge a { color: #999; text-decoration: none; font-weight: bold; }
        .cat-active-badge a:hover { color: #e74c3c; }

        /* ── Tabela ── */
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th { background-color: #343a40; color: #fff; padding: 12px; text-align: left; font-size: 0.88em; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 0.88em; vertical-align: middle; }
        tr:hover { background-color: #f8f9fa; }
        .patrimonio-badge { background: #6f42c1; color: white; padding: 3px 7px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .atrib-list { margin: 4px 0 0; padding: 0; list-style: none; font-size: 0.82em; color: #555; }
        .atrib-list li { margin-bottom: 2px; }
        .atrib-list li span { font-weight: 600; color: #333; }

        /* Status badges */
        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.82em; font-weight: 600; display: inline-block; }
        .status-ativo        { background: #d4edda; color: #155724; }
        .status-inativo      { background: #f8d7da; color: #721c24; }
        .status-baixa_total  { background: #e2e3e5; color: #383d41; }
        .status-baixa_parcial{ background: #fff3cd; color: #856404; }

        /* Botões de ação */
        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 4px; text-decoration: none; font-size: 1em; margin-right: 3px; }
        .btn-view { background: #17a2b8; }
        .btn-view:hover { background: #138496; }
        .btn-edit { background: #ffc107; }
        .btn-edit:hover { background: #e0a800; }
        .btn-del  { background: #dc3545; }
        .btn-del:hover { background: #bd2130; }

        /* ── Paginação ── */
        .pagination-wrapper { display: flex; flex-direction: column; align-items: center; margin-top: 20px; gap: 6px; }
        .pagination-info { font-size: 0.85em; color: #888; }
        .pagination-container { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; justify-content: center; }
        .pg-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border: 1px solid #dee2e6; border-radius: 4px; text-decoration: none; color: #495057; font-size: 0.9em; transition: all 0.15s; background: #fff; }
        .pg-btn:hover:not(.disabled):not(.active) { background: #e9ecef; border-color: #adb5bd; color: #212529; }
        .pg-btn.active { background: #007bff; border-color: #007bff; color: #fff; font-weight: 600; cursor: default; }
        .pg-btn.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
        .pg-btn.pg-arrow { font-size: 1.1em; }
        .pg-ellipsis { padding: 0 4px; color: #888; line-height: 36px; }

        /* ── Modais ── */
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: none; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-window { background: #fff; width: 90%; max-width: 900px; height: 90%; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; animation: slideIn 0.25s ease; }
        .modal-header { padding: 15px 20px; background: #f8f9fa; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-weight: bold; font-size: 1.05em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; line-height: 1; }
        .modal-close:hover { color: #333; }
        .modal-content-frame { flex: 1; border: none; width: 100%; background: #fff; }

        /* Modal Filtros Avançados */
        .advanced-filter-modal { background: #fff; width: 90%; max-width: 560px; max-height: 85vh; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; animation: slideIn 0.25s ease; }
        .advanced-filter-content { flex: 1; overflow-y: auto; padding: 20px; }
        .filter-section { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .filter-section:last-child { border-bottom: none; margin-bottom: 0; }
        .filter-section h3 { margin: 0 0 14px; color: #2c3e50; font-size: 0.95em; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.88em; color: #555; }
        .form-row input, .form-row select { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 0.9em; }
        .modal-footer { padding: 14px 20px; background: #f8f9fa; border-top: 1px solid #ddd; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-secondary { background: #6c757d; color: white; border: none; padding: 9px 18px; border-radius: 4px; cursor: pointer; font-size: 0.9em; }
        .btn-secondary:hover { background: #5a6268; }

        /* Seletor hierárquico de categorias no modal */
        .cat-breadcrumb { background: #f0f4ff; border: 1px solid #c5d5f0; border-radius: 5px; padding: 8px 12px; font-size: 0.88em; color: #2c3e50; margin-bottom: 12px; min-height: 32px; }
        .modal-categoria-level { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .modal-categoria-level label { min-width: 60px; font-size: 0.82em; font-weight: 600; color: #666; }
        .modal-categoria-level select { flex: 1; padding: 7px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.88em; }
        .btn-clear-cat { background: none; border: 1px solid #ddd; border-radius: 4px; padding: 4px 10px; font-size: 0.8em; color: #666; cursor: pointer; margin-top: 6px; }
        .btn-clear-cat:hover { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
    </style>
</head>
<body>

<!-- ========== SIDEBAR ========== -->

<aside class="sidebar">
    <h2>INTS Inventário</h2>
    <a href="../../index.php">🏠 Home</a>
    <a href="index.php" style="background:#495057; color:#fff;">📦 Produtos</a>
    <a href="../movimentacoes/index.php">🔄 Movimentações</a>
    <div class="sidebar-divider"></div>
    <?php if ($is_admin): ?>
        <a href="../admin/index.php">⚙️ Administração</a>
    <?php endif; ?>
    <div style="margin-top:auto;">  <a href="../../logout.php">🚪 Sair</a>
    </div>
</aside>

<!-- ========== CONTEÚDO PRINCIPAL ========== -->
<main class="main-content">
    <div class="top-bar">
        <div class="top-header">
            <h1>📋 Produtos</h1>
            <div class="action-buttons">
                <?php if ($can_edit): ?>
                    <a href="cadastrar.php" class="btn-novo">+ Novo Produto</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['sucesso'])): ?>
            <div style="background:#d4edda; color:#155724; padding:10px; border-radius:4px; margin-bottom:12px; font-size:0.9em;">
                ✅ <?php echo $_GET['sucesso'] === 'soft_delete' ? 'Produto excluído com sucesso.' : 'Operação realizada com sucesso.'; ?>
            </div>
        <?php endif; ?>

        <!-- Filtros básicos -->
        <form method="GET" class="filter-grid">
            <!-- Preservar categoria selecionada no modal avançado -->
            <input type="hidden" name="filtro_categoria" value="<?php echo htmlspecialchars($filtro_cat); ?>">
            <input type="hidden" name="filtro_tipo_posse" value="<?php echo htmlspecialchars($filtro_tipo_posse); ?>">
            <input type="hidden" name="filtro_locador"   value="<?php echo htmlspecialchars($filtro_locador); ?>">
            <input type="hidden" name="filtro_contrato"  value="<?php echo htmlspecialchars($filtro_contrato); ?>">
            <input type="hidden" name="filtro_baixados"  id="hdnBaixados" value="<?php echo htmlspecialchars($filtro_baixados); ?>">

            <div>
                <label>ID</label>
                <input type="number" name="busca_id" value="<?php echo htmlspecialchars($filtro_id); ?>" placeholder="ID...">
            </div>
            <div>
                <label>Nome do Produto</label>
                <input type="text" name="busca_nome" value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Buscar por nome...">
            </div>
            <div>
                <label>Nº Patrimônio</label>
                <input type="text" name="busca_patrimonio" value="<?php echo htmlspecialchars($filtro_patrimonio); ?>" placeholder="Patrimônio...">
            </div>
            <div>
                <label>Local</label>
                <select name="filtro_local">
                    <option value="">Todos os locais</option>
                    <?php foreach ($locais_formatados as $id => $nome): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($filtro_local == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($nome); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="filtro_status">
                    <option value="">Ativos (padrão)</option>
                    <option value="ativo"          <?php echo ($filtro_status == 'ativo')         ? 'selected' : ''; ?>>Ativo</option>
                    <option value="baixa_parcial"  <?php echo ($filtro_status == 'baixa_parcial') ? 'selected' : ''; ?>>Baixa Parcial</option>
                    <option value="baixa_total"    <?php echo ($filtro_status == 'baixa_total')   ? 'selected' : ''; ?>>Baixa Total</option>
                    <option value="inativo"        <?php echo ($filtro_status == 'inativo')       ? 'selected' : ''; ?>>Inativo</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; justify-content:flex-end; gap:6px;">
                <label class="toggle-baixados">
                    <input type="checkbox" id="chkBaixados" onchange="toggleBaixados(this)" <?php echo $filtro_baixados === '1' ? 'checked' : ''; ?>>
                    Exibir baixa total
                </label>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <button type="submit" class="btn-filter">Filtrar</button>
                    <button type="button" onclick="abrirFiltrosAvancados()" class="btn-filter-advanced">
                        ⚙️ Avançado
                        <?php if ($filtros_avancados_ativos > 0): ?>
                            <span class="filter-badge"><?php echo $filtros_avancados_ativos; ?></span>
                        <?php endif; ?>
                    </button>
                    <?php if ($filtros_ativos > 0): ?>
                        <a href="index.php" style="color:#666; font-size:0.85em; white-space:nowrap;">✕ Limpar (<?php echo $filtros_ativos; ?>)</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Indicador de categoria ativa -->
        <?php if (!empty($filtro_cat) && !empty($categoria_breadcrumb_display)): ?>
            <div style="margin-top: 10px;">
                <span class="cat-active-badge">
                    📁 <?php echo htmlspecialchars($categoria_breadcrumb_display); ?>
                    <a href="?<?php
                        $p = $_GET; unset($p['filtro_categoria']); unset($p['pagina']);
                        echo http_build_query($p);
                    ?>" title="Remover filtro de categoria">✕</a>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========== TABELA ========== -->
    <?php if (!empty($produtos_agregados)): ?>
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th style="width:125px;">Patrimônio</th>
                    <th>Nome / Atributos</th>
                    <th style="width:135px;">Categoria</th>
                    <th style="width:145px;">Localização</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:<?php echo $can_edit ? '100px' : '60px'; ?>; text-align:center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos_agregados as $prod): ?>
                <tr>
                    <td style="font-size:0.85em; color:#888;">#<?php echo $prod['produto_id']; ?></td>
                    <td>
                        <?php if (!empty($prod['numero_patrimonio'])): ?>
                            <span class="patrimonio-badge"><?php echo htmlspecialchars($prod['numero_patrimonio']); ?></span>
                        <?php else: ?>
                            <span style="color:#bbb; font-size:0.85em;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($prod['produto_nome']); ?></strong>
                        <?php if (!empty($prod['atributos'])): ?>
                            <ul class="atrib-list">
                                <?php foreach ($prod['atributos'] as $nome => $val): ?>
                                    <li><span><?php echo htmlspecialchars($nome); ?>:</span> <?php echo htmlspecialchars((string)$val); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($prod['tipo_posse'] === 'locado'): ?>
                            <div style="font-size:0.8em; color:#e67e22; margin-top:4px;">🔑 Locado<?php echo !empty($prod['locador_nome']) ? ' — ' . htmlspecialchars($prod['locador_nome']) : ''; ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($prod['categoria_nome']); ?></td>
                    <td>
                        <?php if (!empty($prod['estoques'])): ?>
                            <?php foreach ($prod['estoques'] as $est): ?>
                                <div style="font-size:0.85em;">📍 <?php echo htmlspecialchars($est['nome']); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color:#bbb; font-size:0.85em;">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $s = $prod['status_produto'];
                        $label = match($s) {
                            'ativo'         => 'Ativo',
                            'inativo'       => 'Inativo',
                            'baixa_total'   => 'Baixa Total',
                            'baixa_parcial' => 'Baixa Parcial',
                            default         => ucfirst($s)
                        };
                        ?>
                        <span class="status-badge status-<?php echo htmlspecialchars($s); ?>"><?php echo $label; ?></span>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <a href="detalhes.php?id=<?php echo $prod['produto_id']; ?>" onclick="abrirModal('detalhes.php?id=<?php echo $prod['produto_id']; ?>', '<?php echo addslashes(htmlspecialchars($prod['produto_nome'])); ?>'); return false;" class="btn-action btn-view" title="Visualizar">👁️</a>
                        <?php if ($can_edit): ?>
                            <a href="deletar.php?id=<?php echo $prod['produto_id']; ?>" onclick="return confirm('Excluir o produto \'<?php echo addslashes(htmlspecialchars($prod['produto_nome'])); ?>\'?')" class="btn-action btn-del" title="Excluir">🗑️</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ========== PAGINAÇÃO ========== -->
        <div class="pagination-wrapper">
            <div class="pagination-info">
                <?php
                $inicio_exib = $total_items > 0 ? $offset + 1 : 0;
                $fim_exib    = min($offset + $itens_por_pagina, $total_items);
                echo "Exibindo {$inicio_exib}–{$fim_exib} de {$total_items} produto(s)";
                if ($filtro_baixados === '0' && empty($filtro_status)) {
                    echo ' <span style="font-size:0.9em;">(baixa total oculta)</span>';
                }
                ?>
            </div>
            <?php echo renderPaginacao($pagina_atual, $total_paginas, $url_base_pg); ?>
        </div>

    <?php else: ?>
        <div style="text-align:center; padding:60px 20px; color:#888; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
            <div style="font-size:3.5em; margin-bottom:15px;">📭</div>
            <p style="font-size:1.1em; margin-bottom:8px;">Nenhum produto encontrado com os filtros aplicados.</p>
            <?php if ($filtros_ativos > 0): ?>
                <a href="index.php" style="color:#007bff; font-size:0.9em;">Limpar todos os filtros</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<!-- ========== MODAL PRINCIPAL (detalhes/editar) ========== -->
<div id="modalContainer" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <span id="modalTitle" class="modal-title">Título</span>
            <button onclick="fecharModal(false)" class="modal-close">&times;</button>
        </div>
        <iframe id="modalFrame" class="modal-content-frame" src=""></iframe>
    </div>
</div>

<!-- ========== MODAL FILTROS AVANÇADOS ========== -->
<div id="modalFiltrosAvancados" class="modal-overlay">
    <div class="advanced-filter-modal">
        <div class="modal-header">
            <span class="modal-title">🔍 Filtros Avançados</span>
            <button onclick="fecharFiltrosAvancados()" class="modal-close">&times;</button>
        </div>

        <form id="formFiltrosAvancados" method="GET" class="advanced-filter-content">
            <!-- Preservar filtros básicos -->
            <input type="hidden" name="busca_id"         value="<?php echo htmlspecialchars($filtro_id); ?>">
            <input type="hidden" name="busca_nome"       value="<?php echo htmlspecialchars($filtro_nome); ?>">
            <input type="hidden" name="busca_patrimonio" value="<?php echo htmlspecialchars($filtro_patrimonio); ?>">
            <input type="hidden" name="filtro_local"     value="<?php echo htmlspecialchars($filtro_local); ?>">
            <input type="hidden" name="filtro_status"    value="<?php echo htmlspecialchars($filtro_status); ?>">
            <input type="hidden" name="filtro_baixados"  value="<?php echo htmlspecialchars($filtro_baixados); ?>">

            <!-- ── Seção: Categorias ── -->
            <div class="filter-section">
                <h3>📁 Filtrar por Categoria</h3>
                <div class="cat-breadcrumb" id="modal-cat-breadcrumb">
                    <?php echo !empty($categoria_breadcrumb_display)
                        ? '<strong>Selecionada:</strong> ' . htmlspecialchars($categoria_breadcrumb_display)
                        : 'Selecione uma categoria abaixo...'; ?>
                </div>
                <div id="modal-cat-hierarchy">
                    <div class="modal-categoria-level" id="modal-nivel-0">
                        <label>Nível 1:</label>
                        <select class="modal-cat-select" data-nivel="0">
                            <option value="">Selecione a categoria principal...</option>
                            <?php foreach ($categorias_raiz as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- O valor final da categoria vai neste hidden -->
                <input type="hidden" name="filtro_categoria" id="modal_filtro_categoria" value="<?php echo htmlspecialchars($filtro_cat); ?>">
                <button type="button" class="btn-clear-cat" id="btnLimparCat" onclick="limparCategoriaModal()" style="<?php echo empty($filtro_cat) ? 'display:none' : ''; ?>">✕ Limpar categoria</button>
            </div>

            <!-- ── Seção: Locação ── -->
            <div class="filter-section">
                <h3>📦 Informações de Locação</h3>
                <div class="form-row">
                    <label>Tipo de Posse:</label>
                    <select name="filtro_tipo_posse">
                        <option value="">Todos</option>
                        <option value="proprio" <?php echo ($filtro_tipo_posse == 'proprio') ? 'selected' : ''; ?>>Próprio</option>
                        <option value="locado"  <?php echo ($filtro_tipo_posse == 'locado')  ? 'selected' : ''; ?>>Locado</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Nome do Locador:</label>
                    <input type="text" name="filtro_locador" value="<?php echo htmlspecialchars($filtro_locador); ?>" placeholder="Digite o nome do locador...">
                </div>
                <div class="form-row">
                    <label>Número do Contrato:</label>
                    <input type="text" name="filtro_contrato" value="<?php echo htmlspecialchars($filtro_contrato); ?>" placeholder="Digite o número do contrato...">
                </div>
            </div>
        </form>

        <div class="modal-footer">
            <button type="button" onclick="limparFiltrosAvancados()" class="btn-secondary">Limpar Filtros</button>
            <button type="submit" form="formFiltrosAvancados" class="btn-filter">Aplicar Filtros</button>
        </div>
    </div>
</div>

<!-- ========== SCRIPTS ========== -->
<script>
// ──────────────────────────────────────────────
// Modal principal (detalhes / editar)
// ──────────────────────────────────────────────
function abrirModal(url, titulo) {
    document.getElementById('modalTitle').innerText = titulo;
    const sep = url.includes('?') ? '&' : '?';
    document.getElementById('modalFrame').src = url + sep + 'modal=1';
    document.getElementById('modalContainer').classList.add('active');
}
function fecharModal(recarregar) {
    document.getElementById('modalContainer').classList.remove('active');
    document.getElementById('modalFrame').src = '';
    if (recarregar) location.reload();
}
document.getElementById('modalContainer').addEventListener('click', function(e) {
    if (e.target === this) fecharModal(false);
});
window.fecharERecarregar = function() { fecharModal(true); };

// ──────────────────────────────────────────────
// Modal de filtros avançados
// ──────────────────────────────────────────────
function abrirFiltrosAvancados() {
    document.getElementById('modalFiltrosAvancados').classList.add('active');
}
function fecharFiltrosAvancados() {
    document.getElementById('modalFiltrosAvancados').classList.remove('active');
}
document.getElementById('modalFiltrosAvancados').addEventListener('click', function(e) {
    if (e.target === this) fecharFiltrosAvancados();
});
function limparFiltrosAvancados() {
    document.getElementById('modal_filtro_categoria').value = '';
    document.getElementById('formFiltrosAvancados').querySelectorAll('select, input[type=text]').forEach(el => el.value = '');
    limparCategoriaModal();
}

// ──────────────────────────────────────────────
// Toggle "Exibir baixa total"
// ──────────────────────────────────────────────
function toggleBaixados(chk) {
    document.getElementById('hdnBaixados').value = chk.checked ? '1' : '0';
}

// ──────────────────────────────────────────────
// Seletor hierárquico de categorias no modal avançado
// ──────────────────────────────────────────────
(function() {
    const container    = document.getElementById('modal-cat-hierarchy');
    const hiddenInput  = document.getElementById('modal_filtro_categoria');
    const breadcrumb   = document.getElementById('modal-cat-breadcrumb');
    const btnLimpar    = document.getElementById('btnLimparCat');
    const pathInicial  = <?php echo $categoria_path_json; ?>;

    let categoriaPath = [];

    // Pré-popular se já existe categoria selecionada
    if (pathInicial && pathInicial.length > 0) {
        categoriaPath = [...pathInicial];
        atualizarBreadcrumb();

        // Pré-selecionar nível 0
        const sel0 = container.querySelector('select[data-nivel="0"]');
        if (sel0 && pathInicial[0]) {
            sel0.value = pathInicial[0].id;
        }

        // Carregar subníveis encadeados
        (async function preencherNiveis() {
            for (let i = 0; i < pathInicial.length - 1; i++) {
                const catId = pathInicial[i].id;
                const selId = pathInicial[i + 1].id;
                try {
                    const r = await fetch(`../../api/categorias_filhos.php?categoria_id=${catId}`);
                    const d = await r.json();
                    if (d.sucesso && d.categorias.length > 0) {
                        adicionarNivel(d.categorias, i + 1, selId);
                    }
                } catch(e) {}
            }
            // Carregar filhos da última selecionada (se existirem)
            const ultimoId = pathInicial[pathInicial.length - 1].id;
            try {
                const r = await fetch(`../../api/categorias_filhos.php?categoria_id=${ultimoId}`);
                const d = await r.json();
                if (d.sucesso && d.categorias.length > 0) {
                    adicionarNivel(d.categorias, pathInicial.length, null);
                }
            } catch(e) {}
        })();
    }

    // Delegação de eventos para todos os selects hierárquicos
    container.addEventListener('change', function(e) {
        const sel = e.target;
        if (!sel.classList.contains('modal-cat-select')) return;

        const nivel     = parseInt(sel.dataset.nivel);
        const catId     = sel.value;

        // Remove todos os níveis abaixo do atual
        container.querySelectorAll('.modal-categoria-level').forEach(div => {
            if (parseInt(div.dataset.nivel) > nivel) div.remove();
        });

        if (catId) {
            const nomeSelecionado = sel.options[sel.selectedIndex].text;
            categoriaPath = categoriaPath.slice(0, nivel);
            categoriaPath.push({ id: parseInt(catId), nome: nomeSelecionado });
            hiddenInput.value = catId;

            // Tentar carregar subcategorias
            fetch(`../../api/categorias_filhos.php?categoria_id=${catId}`)
                .then(r => r.json())
                .then(d => {
                    if (d.sucesso && d.categorias.length > 0) {
                        adicionarNivel(d.categorias, nivel + 1, null);
                    }
                })
                .catch(() => {});
        } else {
            categoriaPath = categoriaPath.slice(0, nivel);
            // Se limpar o nível 0, reset do hidden
            if (nivel === 0) hiddenInput.value = '';
            else hiddenInput.value = categoriaPath.length > 0 ? categoriaPath[categoriaPath.length - 1].id : '';
        }

        atualizarBreadcrumb();
    });

    function adicionarNivel(cats, nivel, selId) {
        // Remove nível existente se houver
        const existente = document.getElementById(`modal-nivel-${nivel}`);
        if (existente) existente.remove();

        const div = document.createElement('div');
        div.className  = 'modal-categoria-level';
        div.id         = `modal-nivel-${nivel}`;
        div.dataset.nivel = nivel;
        div.innerHTML  = `<label>Nível ${nivel + 1}:</label><select class="modal-cat-select" data-nivel="${nivel}"><option value="">(subcategoria opcional)</option></select>`;

        const sel = div.querySelector('select');
        cats.forEach(c => {
            const op = document.createElement('option');
            op.value = c.id;
            op.text  = c.nome;
            if (selId && c.id == selId) op.selected = true;
            sel.add(op);
        });
        container.appendChild(div);
    }

    function atualizarBreadcrumb() {
        if (categoriaPath.length > 0) {
            breadcrumb.innerHTML = '<strong>Selecionada:</strong> ' + categoriaPath.map(c => escapeHtml(c.nome)).join(' › ');
            if (btnLimpar) btnLimpar.style.display = '';
        } else {
            breadcrumb.textContent = 'Selecione uma categoria abaixo...';
            if (btnLimpar) btnLimpar.style.display = 'none';
        }
    }

    window.limparCategoriaModal = function() {
        categoriaPath         = [];
        hiddenInput.value     = '';
        // Mantém apenas o nível 0 e reseta
        container.querySelectorAll('.modal-categoria-level').forEach((div, i) => {
            if (i > 0) div.remove();
        });
        const sel0 = container.querySelector('select[data-nivel="0"]');
        if (sel0) sel0.value = '';
        atualizarBreadcrumb();
    };

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
})();
</script>
</body>
</html>