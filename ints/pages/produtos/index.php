<?php
require_once '../../config/_protecao.php';

$usuario_nivel    = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade  = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

// Helper de permissão – reuso em todo o arquivo
$is_admin        = in_array($usuario_nivel, ['admin', 'admin_unidade', 'gestor']);
$is_admin_global = ($usuario_nivel === 'admin');           // pode ver tudo, incluir baixa_total
$can_edit        = in_array($usuario_nivel, ['admin', 'admin_unidade']); // cadastrar/editar/deletar

if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    if (function_exists('getIdsLocaisDaUnidade')) {
        $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
    } else {
        $unidade_locais_ids = [$usuario_unidade];
    }
}

// Filtros Dropdown
$locais_formatados = [];
if (function_exists('getLocaisFormatados')) {
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    $locais_formatados = getLocaisFormatados($conn, false, $restricao);
}

$categorias = [];
$res_cat = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE ORDER BY nome");
while ($r = $res_cat->fetch_assoc()) $categorias[] = $r;

$atributos_disponiveis = [];
$res_attr = $conn->query("SELECT id, nome, tipo FROM atributos_definicao ORDER BY nome");
while ($r = $res_attr->fetch_assoc()) $atributos_disponiveis[] = $r;

// Captura filtros básicos
$filtro_id          = $_GET['busca_id']          ?? '';
$filtro_nome        = $_GET['busca_nome']         ?? '';
$filtro_cat         = $_GET['filtro_categoria']   ?? '';
$filtro_local       = $_GET['filtro_local']       ?? '';
$filtro_tipo_posse  = $_GET['filtro_tipo_posse']  ?? '';
$filtro_patrimonio  = $_GET['busca_patrimonio']   ?? '';
$filtro_status      = $_GET['filtro_status']      ?? '';
$filtro_baixados    = $_GET['filtro_baixados']    ?? '0'; // 0=ocultar baixa_total, 1=mostrar todos

// Filtros avançados
$filtro_locador  = $_GET['filtro_locador']  ?? '';
$filtro_contrato = $_GET['filtro_contrato'] ?? '';
$filtros_atributos = [];
foreach ($_GET as $key => $value) {
    if (strpos($key, 'attr_') === 0 && !empty($value)) {
        $attr_id = str_replace('attr_', '', $key);
        $filtros_atributos[$attr_id] = $value;
    }
}

// Montagem da Query
$sql = "
    SELECT 
        p.id AS produto_id, 
        p.nome AS produto_nome, 
        p.numero_patrimonio, 
        p.tipo_posse, 
        p.locador_nome,
        p.numero_contrato,
        p.status_produto,
        c.nome AS categoria_nome, 
        l.id AS local_id, 
        IFNULL(l.nome, 'N/A') AS local_nome_simples,
        ad.id AS atributo_id,
        ad.nome AS atributo_nome,
        ad.tipo AS atributo_tipo,
        COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) AS atributo_valor
    FROM produtos p
    JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN estoques e ON p.id = e.produto_id AND e.quantidade > 0
    LEFT JOIN locais l ON e.local_id = l.id
    LEFT JOIN atributos_valor av ON p.id = av.produto_id
    LEFT JOIN atributos_definicao ad ON av.atributo_id = ad.id
    WHERE p.deletado = FALSE
";

$params = [];
$types  = "";

// Por padrão oculta baixa_total (a menos que o filtro peça "todos")
if ($filtro_baixados === '0' && empty($filtro_status)) {
    $sql .= " AND p.status_produto != 'baixa_total'";
}

if (!empty($filtro_id))         { $sql .= " AND p.id = ?";                    $params[] = (int)$filtro_id;        $types .= "i"; }
if (!empty($filtro_nome))       { $sql .= " AND p.nome LIKE ?";               $params[] = "%$filtro_nome%";       $types .= "s"; }
if (!empty($filtro_patrimonio)) { $sql .= " AND p.numero_patrimonio LIKE ?";  $params[] = "%$filtro_patrimonio%"; $types .= "s"; }

if (!empty($filtro_cat)) {
    $ids_hierarquia_cat = function_exists('getIdsCategoriasDaHierarquia')
        ? getIdsCategoriasDaHierarquia($conn, (int)$filtro_cat)
        : [(int)$filtro_cat];
    if (!empty($ids_hierarquia_cat)) {
        $idsStrCat = implode(',', array_map('intval', $ids_hierarquia_cat));
        $sql .= " AND p.categoria_id IN ($idsStrCat)";
    }
}

if (!empty($filtro_local)) {
    $ids_hierarquia_filtro = function_exists('getIdsLocaisDaUnidade')
        ? getIdsLocaisDaUnidade($conn, (int)$filtro_local)
        : [(int)$filtro_local];
    if (!empty($ids_hierarquia_filtro)) {
        $idsStrFiltro = implode(',', array_map('intval', $ids_hierarquia_filtro));
        $sql .= " AND e.local_id IN ($idsStrFiltro)";
    }
}

if (!empty($filtro_tipo_posse)) { $sql .= " AND p.tipo_posse = ?";       $params[] = $filtro_tipo_posse; $types .= "s"; }
if (!empty($filtro_status))     { $sql .= " AND p.status_produto = ?";   $params[] = $filtro_status;     $types .= "s"; }
if (!empty($filtro_locador))    { $sql .= " AND p.locador_nome LIKE ?";  $params[] = "%$filtro_locador%"; $types .= "s"; }
if (!empty($filtro_contrato))   { $sql .= " AND p.numero_contrato LIKE ?"; $params[] = "%$filtro_contrato%"; $types .= "s"; }

if (!empty($filtros_atributos)) {
    foreach ($filtros_atributos as $attr_id => $valor_filtro) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM atributos_valor av2 
            WHERE av2.produto_id = p.id 
            AND av2.atributo_id = ?
            AND (
                av2.valor_texto LIKE ? OR 
                CAST(av2.valor_numero AS CHAR) LIKE ? OR 
                CAST(av2.valor_booleano AS CHAR) LIKE ? OR 
                av2.valor_data LIKE ?
            )
        )";
        $params[] = (int)$attr_id;
        $types .= "i";
        $valor_like = "%$valor_filtro%";
        $params[] = $valor_like; $params[] = $valor_like;
        $params[] = $valor_like; $params[] = $valor_like;
        $types .= "ssss";
    }
}

if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStrUnidade = implode(',', array_map('intval', $unidade_locais_ids));
    $sql .= " AND EXISTS (SELECT 1 FROM estoques e2 WHERE e2.produto_id = p.id AND e2.local_id IN ($idsStrUnidade) AND e2.quantidade > 0)";
}

$sql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$produtos_agregados = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pid = $row['produto_id'];
        if (!isset($produtos_agregados[$pid])) {
            $produtos_agregados[$pid] = [
                'id'               => $pid,
                'nome'             => $row['produto_nome'],
                'numero_patrimonio'=> $row['numero_patrimonio'],
                'categoria'        => $row['categoria_nome'],
                'tipo_posse'       => $row['tipo_posse'],
                'locador_nome'     => $row['locador_nome'],
                'numero_contrato'  => $row['numero_contrato'],
                'status_produto'   => $row['status_produto'] ?? 'ativo',
                'atributos'        => [],
                'estoques'         => []
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
            $exists = false;
            foreach ($produtos_agregados[$pid]['estoques'] as $est) if ($est['id'] == $lid) $exists = true;
            if (!$exists) $produtos_agregados[$pid]['estoques'][] = ['id' => $lid, 'nome' => $nome_local];
        }
    }
}
$stmt->close();

// Contar filtros ativos
$filtros_ativos = 0;
if ($filtro_id)          $filtros_ativos++;
if ($filtro_nome)        $filtros_ativos++;
if ($filtro_cat)         $filtros_ativos++;
if ($filtro_local)       $filtros_ativos++;
if ($filtro_patrimonio)  $filtros_ativos++;
if ($filtro_status)      $filtros_ativos++;
if ($filtro_tipo_posse)  $filtros_ativos++;
if ($filtro_locador)     $filtros_ativos++;
if ($filtro_contrato)    $filtros_ativos++;
$filtros_ativos += count($filtros_atributos);
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
        
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; }
        .sidebar a:hover { background: #495057; color: white; }
        .sidebar .sidebar-divider { border-top: 1px solid #4b545c; margin: 10px 0; }
        
        .main-content { flex: 1; padding: 20px; overflow-y: auto; position: relative; }

        .top-bar { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .btn-novo { background: #28a745; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; cursor: pointer; border: none; }
        
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end; }
        .filter-grid input, .filter-grid select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .filter-grid label { font-size: 0.85em; color: #555; margin-bottom: 3px; display: block; }
        .btn-filter { background: #007bff; color: white; border: none; padding: 9px 15px; border-radius: 4px; cursor: pointer; }
        .btn-filter-advanced { background: #6c757d; color: white; border: none; padding: 9px 15px; border-radius: 4px; cursor: pointer; position: relative; }
        .filter-badge { position: absolute; top: -8px; right: -8px; background: #dc3545; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.7em; font-weight: bold; }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th { background-color: #343a40; color: #fff; padding: 12px; text-align: left; font-size: 0.85em; white-space: nowrap; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 0.9em; vertical-align: middle; }
        tr:hover { background-color: #f1f1f1; }
        tr.row-baixa-total { opacity: 0.65; background: #fef9f9 !important; }
        tr.row-baixa-total:hover { opacity: 0.8; background: #fef0f0 !important; }

        .id-badge { background: #6c757d; color: white; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; font-family: monospace; }
        .patrimonio-badge { background: #17a2b8; color: white; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; font-family: monospace; }
        
        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 600; display: inline-block; }
        .status-ativo        { background: #d4edda; color: #155724; }
        .status-baixa-parcial{ background: #fff3cd; color: #856404; }
        .status-baixa-total  { background: #f8d7da; color: #721c24; }
        .status-inativo      { background: #e2e3e5; color: #383d41; }
        
        .atributos-list { font-size: 0.8em; color: #666; margin-top: 4px; }
        .atributo-item { display: inline-block; margin-right: 10px; background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        .atributo-label { font-weight: 600; color: #333; }
        
        .locacao-info { font-size: 0.8em; color: #856404; background: #fff3cd; padding: 4px 8px; border-radius: 4px; margin-top: 4px; display: inline-block; }

        .baixa-lock-info { font-size: 0.78em; color: #721c24; background: #f8d7da; padding: 3px 7px; border-radius: 4px; margin-top: 3px; display: inline-block; }
        
        .btn-action { margin-right: 4px; text-decoration: none; padding: 4px 8px; border-radius: 3px; font-size: 0.85em; cursor: pointer; display: inline-block; border: none; }
        .btn-view { background: #17a2b8; color: white; }
        .btn-edit { background: #ffc107; color: #333; }
        .btn-del  { background: #dc3545; color: white; }
        .btn-baixa { background: #6f42c1; color: white; }
        .btn-disabled { background: #dee2e6; color: #aaa; cursor: not-allowed; pointer-events: none; }

        .badge-acesso { font-size: 0.75em; padding: 2px 7px; border-radius: 10px; margin-left: 8px; }
        .badge-admin      { background:#d4edda; color:#155724; }
        .badge-visualizador{ background:#cce5ff; color:#004085; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-window { background: #fff; width: 90%; max-width: 900px; height: 90%; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; animation: slideIn 0.3s ease; }
        .modal-header { padding: 15px; background: #f8f9fa; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-weight: bold; font-size: 1.1em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; }
        .modal-content-frame { flex: 1; border: none; width: 100%; background: #fff; }
        
        .advanced-filter-modal { background: #fff; width: 90%; max-width: 600px; max-height: 80%; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; animation: slideIn 0.3s ease; }
        .advanced-filter-content { flex: 1; overflow-y: auto; padding: 20px; }
        .filter-section { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .filter-section:last-child { border-bottom: none; }
        .filter-section h3 { margin-top: 0; margin-bottom: 15px; color: #333; font-size: 1em; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9em; color: #555; }
        .form-row input, .form-row select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .modal-footer { padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-secondary { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }

        .toggle-baixados { display: flex; align-items: center; gap: 6px; font-size: 0.85em; color: #555; }
        .toggle-baixados input[type=checkbox] { width: auto; }

        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .success-message { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .error-message   { background: #f8d7da; color: #721c24;  padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .info-message    { background: #cce5ff; color: #004085;  padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #b8daff; font-size:0.9em; }
    </style>
</head>
<body>

<aside class="sidebar">
    <h2>INTS Inventário</h2>
    <a href="../../index.html">🏠 Home</a>
    <a href="index.php" style="background:#495057; color:#fff;">📦 Produtos</a>
    <a href="../movimentacoes/index.php">🔄 Movimentações</a>
    <div class="sidebar-divider"></div>
    <?php if ($can_edit): ?>
        <a href="../admin/index.php">⚙️ Administração</a>
        <a href="../admin/requisicoes/index.php">📋 Requisições</a>
    <?php endif; ?>
</aside>

<main class="main-content">
    <div class="top-bar">
        <div class="top-header">
            <h2 style="margin:0; color:#333;">
                Gerenciar Produtos
                <?php if ($can_edit): ?>
                    <span class="badge-acesso badge-admin">Admin</span>
                <?php else: ?>
                    <span class="badge-acesso badge-visualizador">Visualização</span>
                <?php endif; ?>
            </h2>
            <?php if ($can_edit): ?>
                <button onclick="abrirModal('cadastrar.php', 'Novo Cadastro')" class="btn-novo">+ Novo Item</button>
            <?php endif; ?>
        </div>

        <?php if (!$can_edit): ?>
            <div class="info-message">
                ℹ️ Você está no modo <strong>visualização</strong>. Para cadastrar, editar ou excluir itens, solicite ao administrador.
            </div>
        <?php endif; ?>

        <?php 
        $msg = ''; $msgClass = '';
        if (isset($_GET['sucesso'])) {
            $msgClass = 'success-message';
            switch($_GET['sucesso']) {
                case 'cadastro':
                    $pat = isset($_GET['patrimonio']) ? htmlspecialchars($_GET['patrimonio']) : '';
                    $msg = "Item cadastrado com sucesso! " . ($pat ? "<strong>Patrimônio: $pat</strong>" : "");
                    break;
                case 'soft_delete': $msg = "Produto movido para lixeira com sucesso."; break;
                case '1':           $msg = "Operação realizada com sucesso."; break;
            }
        } elseif (isset($_GET['erro'])) {
            $msgClass = 'error-message';
            switch($_GET['erro']) {
                case 'delete_falhou':          $msg = "Falha ao tentar excluir o produto. Tente novamente."; break;
                case 'delete_permissao_negada':$msg = "Permissão negada: Você não pode excluir este produto."; break;
                case 'delete_id_invalido':     $msg = "ID do produto inválido ou não fornecido."; break;
                case 'delete_baixa_total':     $msg = "Produto com Baixa Total não pode ser excluído — mantido para auditoria."; break;
                case 'editar_baixa_total':     $msg = "Produto com Baixa Total não pode ser editado."; break;
                case 'sem_permissao':          $msg = "Você não tem permissão para esta operação."; break;
                default:                       $msg = "Ocorreu um erro desconhecido.";
            }
        }
        if ($msg): ?>
            <div class="<?php echo $msgClass; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <form method="GET" id="formFiltrosPrincipal" class="filter-grid">
            <input type="hidden" name="filtro_baixados" value="<?php echo htmlspecialchars($filtro_baixados); ?>" id="hiddenFiltroBaixados">

            <div>
                <label>ID:</label>
                <input type="number" name="busca_id" value="<?php echo htmlspecialchars($filtro_id); ?>" placeholder="ID...">
            </div>
            <div>
                <label>Nome:</label>
                <input type="text" name="busca_nome" value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Nome...">
            </div>
            <div>
                <label>Patrimônio:</label>
                <input type="text" name="busca_patrimonio" value="<?php echo htmlspecialchars($filtro_patrimonio); ?>" placeholder="Nº Patr...">
            </div>
            <div>
                <label>Categoria:</label>
                <select name="filtro_categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($filtro_cat == $cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Local:</label>
                <select name="filtro_local">
                    <option value="">Todos</option>
                    <?php foreach ($locais_formatados as $id => $nome): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($filtro_local == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($nome); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Status:</label>
                <select name="filtro_status">
                    <option value="">Ativos (padrão)</option>
                    <option value="ativo"         <?php echo ($filtro_status == 'ativo')         ? 'selected' : ''; ?>>Ativo</option>
                    <option value="baixa_parcial" <?php echo ($filtro_status == 'baixa_parcial') ? 'selected' : ''; ?>>Baixa Parcial</option>
                    <option value="baixa_total"   <?php echo ($filtro_status == 'baixa_total')   ? 'selected' : ''; ?>>Baixa Total</option>
                    <option value="inativo"       <?php echo ($filtro_status == 'inativo')       ? 'selected' : ''; ?>>Inativo</option>
                </select>
            </div>
            <div style="align-self:end; padding-bottom:4px;">
                <label class="toggle-baixados">
                    <input type="checkbox" id="chkBaixados" onchange="toggleBaixados(this)" <?php echo $filtro_baixados === '1' ? 'checked' : ''; ?>>
                    Exibir baixa total
                </label>
            </div>

            <button type="submit" class="btn-filter">Filtrar</button>
            <button type="button" onclick="abrirFiltrosAvancados()" class="btn-filter-advanced">
                ⚙️ Avançado
                <?php if ($filtros_ativos > 7): ?>
                    <span class="filter-badge"><?php echo $filtros_ativos - 7; ?></span>
                <?php endif; ?>
            </button>
            <?php if ($filtros_ativos > 0): ?>
                <a href="index.php" style="color:#666; font-size:0.9em; padding:8px;">Limpar (<?php echo $filtros_ativos; ?>)</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!empty($produtos_agregados)): ?>
        <table>
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th style="width:130px;">Patrimônio</th>
                    <th>Nome / Atributos</th>
                    <th style="width:140px;">Categoria</th>
                    <th style="width:150px;">Localização</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:<?php echo $can_edit ? '170px' : '90px'; ?>; text-align:right;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos_agregados as $prod):
                    $estoques = $prod['estoques'];
                    if (empty($estoques)) $estoques[] = ['id' => null, 'nome' => '-'];
                    $est = $estoques[0];

                    $status = $prod['status_produto'];
                    $status_class = 'status-ativo'; $status_text = 'Ativo';
                    switch($status) {
                        case 'baixa_parcial': $status_class = 'status-baixa-parcial'; $status_text = 'Baixa Parcial'; break;
                        case 'baixa_total':   $status_class = 'status-baixa-total';   $status_text = 'Baixa Total';   break;
                        case 'inativo':       $status_class = 'status-inativo';       $status_text = 'Inativo';       break;
                    }
                    $is_baixa_total = ($status === 'baixa_total');
                ?>
                <tr <?php echo $is_baixa_total ? 'class="row-baixa-total"' : ''; ?>>
                    <td><span class="id-badge">#<?php echo $prod['id']; ?></span></td>
                    <td>
                        <?php if ($prod['numero_patrimonio']): ?>
                            <span class="patrimonio-badge"><?php echo htmlspecialchars($prod['numero_patrimonio']); ?></span>
                        <?php else: ?>
                            <span style="color:#ccc; font-size:0.85em;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div>
                            <strong><?php echo htmlspecialchars($prod['nome']); ?></strong>
                            <?php if ($prod['tipo_posse'] == 'locado'): ?>
                                <small style="background:#ffc107; padding:2px 6px; border-radius:3px; margin-left:5px; font-size:0.75em;">Locado</small>
                            <?php endif; ?>
                            <?php if ($is_baixa_total): ?>
                                <small style="background:#f8d7da; color:#721c24; padding:2px 6px; border-radius:3px; margin-left:5px; font-size:0.75em;">🔒 Baixado</small>
                            <?php endif; ?>
                        </div>
                        <?php if ($prod['tipo_posse'] == 'locado' && ($prod['locador_nome'] || $prod['numero_contrato'])): ?>
                            <div class="locacao-info">
                                <?php if ($prod['locador_nome']): ?>📋 <strong><?php echo htmlspecialchars($prod['locador_nome']); ?></strong><?php endif; ?>
                                <?php if ($prod['numero_contrato']): ?><?php if ($prod['locador_nome']) echo ' • '; ?>Contrato: <strong><?php echo htmlspecialchars($prod['numero_contrato']); ?></strong><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($prod['atributos'])): ?>
                            <div class="atributos-list">
                                <?php $count = 0; foreach ($prod['atributos'] as $nome_attr => $valor_attr): if ($count >= 3) break; ?>
                                    <span class="atributo-item"><span class="atributo-label"><?php echo htmlspecialchars($nome_attr); ?>:</span> <?php echo htmlspecialchars($valor_attr); ?></span>
                                <?php $count++; endforeach;
                                if (count($prod['atributos']) > 3): ?>
                                    <span style="color:#999;">+<?php echo count($prod['atributos']) - 3; ?> mais</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($prod['categoria']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($est['nome']); ?>
                        <?php if (count($estoques) > 1): ?>
                            <small style="color:#666; display:block; margin-top:2px;">+<?php echo count($estoques) - 1; ?> local(is)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                    <td style="text-align:right;">
                        <!-- Detalhes: sempre visível -->
                        <button onclick="abrirModal('detalhes.php?id=<?php echo $prod['id']; ?>', 'Detalhes do Item')" class="btn-action btn-view" title="Ver detalhes">👁️</button>

                        <?php if ($can_edit): ?>
                            <?php if ($is_baixa_total): ?>
                                <!-- Baixa total: edição e exclusão bloqueadas, apenas leitura -->
                                <button class="btn-action btn-disabled" title="Produto baixado — edição bloqueada" disabled>✏️</button>
                                <button class="btn-action btn-disabled" title="Produto baixado — exclusão bloqueada" disabled>🗑️</button>
                            <?php else: ?>
                                <button onclick="abrirModal('editar.php?id=<?php echo $prod['id']; ?>', 'Editar Item')" class="btn-action btn-edit" title="Editar">✏️</button>
                                <a href="deletar.php?id=<?php echo $prod['id']; ?>" onclick="return confirm('Mover este item para a lixeira?')" class="btn-action btn-del" title="Excluir">🗑️</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:10px; font-size:0.85em; color:#888;">
            <?php echo count($produtos_agregados); ?> item(s) encontrado(s).
            <?php if ($filtro_baixados === '0'): ?>
                Itens com <em>Baixa Total</em> estão ocultos por padrão — use "Exibir baixa total" para mostrá-los.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div style="text-align:center; padding:40px; color:#888;">
            <div style="font-size:3em; margin-bottom:15px;">📭</div>
            <p>Nenhum produto encontrado com os filtros aplicados.</p>
        </div>
    <?php endif; ?>
</main>

<!-- Modal Principal -->
<div id="modalContainer" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <span id="modalTitle" class="modal-title">Título</span>
            <button onclick="fecharModal(false)" class="modal-close">&times;</button>
        </div>
        <iframe id="modalFrame" class="modal-content-frame" src=""></iframe>
    </div>
</div>

<!-- Modal Filtros Avançados -->
<div id="modalFiltrosAvancados" class="modal-overlay">
    <div class="advanced-filter-modal">
        <div class="modal-header">
            <span class="modal-title">🔍 Filtros Avançados</span>
            <button onclick="fecharFiltrosAvancados()" class="modal-close">&times;</button>
        </div>
        <form id="formFiltrosAvancados" method="GET" class="advanced-filter-content">
            <!-- Manter filtros básicos como hidden -->
            <input type="hidden" name="busca_id"        value="<?php echo htmlspecialchars($filtro_id); ?>">
            <input type="hidden" name="busca_nome"      value="<?php echo htmlspecialchars($filtro_nome); ?>">
            <input type="hidden" name="busca_patrimonio"value="<?php echo htmlspecialchars($filtro_patrimonio); ?>">
            <input type="hidden" name="filtro_categoria"value="<?php echo htmlspecialchars($filtro_cat); ?>">
            <input type="hidden" name="filtro_local"    value="<?php echo htmlspecialchars($filtro_local); ?>">
            <input type="hidden" name="filtro_status"   value="<?php echo htmlspecialchars($filtro_status); ?>">
            <input type="hidden" name="filtro_baixados" value="<?php echo htmlspecialchars($filtro_baixados); ?>">

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

            <?php if (!empty($atributos_disponiveis)): ?>
            <div class="filter-section">
                <h3>🏷️ Filtrar por Atributos</h3>
                <?php foreach ($atributos_disponiveis as $attr): ?>
                    <div class="form-row">
                        <label><?php echo htmlspecialchars($attr['nome']); ?>:</label>
                        <input type="text"
                               name="attr_<?php echo $attr['id']; ?>"
                               value="<?php echo htmlspecialchars($filtros_atributos[$attr['id']] ?? ''); ?>"
                               placeholder="Filtrar por <?php echo htmlspecialchars($attr['nome']); ?>...">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>
        <div class="modal-footer">
            <button type="button" onclick="limparFiltrosAvancados()" class="btn-secondary">Limpar Filtros</button>
            <button type="submit" form="formFiltrosAvancados" class="btn-filter">Aplicar Filtros</button>
        </div>
    </div>
</div>

<script>
    function abrirModal(url, titulo) {
        document.getElementById('modalTitle').innerText = titulo;
        let separator = url.includes('?') ? '&' : '?';
        document.getElementById('modalFrame').src = url + separator + "modal=1";
        document.getElementById('modalContainer').classList.add('active');
    }
    function fecharModal(recaregar = false) {
        document.getElementById('modalContainer').classList.remove('active');
        document.getElementById('modalFrame').src = "";
        if (recaregar) window.location.reload();
    }
    window.fecharModalDoFilho = function(recarregar) { fecharModal(recarregar); }

    function abrirFiltrosAvancados()  { document.getElementById('modalFiltrosAvancados').classList.add('active'); }
    function fecharFiltrosAvancados() { document.getElementById('modalFiltrosAvancados').classList.remove('active'); }

    function limparFiltrosAvancados() {
        const form = document.getElementById('formFiltrosAvancados');
        form.querySelectorAll('input[type="text"], select').forEach(input => {
            if (input.type === 'hidden') return;
            input.value = '';
        });
    }

    function toggleBaixados(chk) {
        document.getElementById('hiddenFiltroBaixados').value = chk.checked ? '1' : '0';
    }
</script>
</body>
</html>