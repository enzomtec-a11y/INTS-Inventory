<?php
// CORREÇÃO 1: Caminho do DB ajustado
require_once '../../config/db.php';

// Garantir sessão para LOGGED_USER_ID (se o projeto usar sessão)
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$produto_id = (int)$_GET['id'];
$produto = null;
$atributos = [];
$arquivos = [];
$estoque = [];
$componentes = [];

// Detecta usuário/unidade
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// Verifica se produto pertence à unidade (se for admin_unidade)
function produtoPertenceUnidade($conn, $produto_id, $locais_ids) {
    if (empty($locais_ids)) return false;
    $idsStr = implode(',', array_map('intval', $locais_ids));
    $sql = "SELECT 1 FROM estoques WHERE produto_id = ? AND local_id IN ($idsStr) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && $r->num_rows > 0) { $stmt->close(); return true; }
    $stmt->close();
    $sql2 = "SELECT 1 FROM patrimonios WHERE produto_id = ? AND local_id IN ($idsStr) LIMIT 1";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("i", $produto_id);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    $stmt2->close();
    return ($r2 && $r2->num_rows > 0);
}

if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    if (!produtoPertenceUnidade($conn, $produto_id, $unidade_locais_ids)) {
        die("Acesso negado: produto não pertence à sua unidade.");
    }
}

// BUSCAR DADOS BÁSICOS
$sql_base = "SELECT p.*, c.nome as categoria_nome 
             FROM produtos p 
             JOIN categorias c ON p.categoria_id = c.id 
             WHERE p.id = ?";
$stmt = $conn->prepare($sql_base);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$produto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$produto) {
    die("Produto não encontrado.");
}

// BUSCAR ATRIBUTOS (EAV)
$sql_attr = "
    SELECT ad.nome, ad.tipo,
    COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) as valor
    FROM atributos_valor av
    JOIN atributos_definicao ad ON av.atributo_id = ad.id
    WHERE av.produto_id = ?
";
$stmt = $conn->prepare($sql_attr);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_attr = $stmt->get_result();
while ($row = $res_attr->fetch_assoc()) {
    // Formatações visuais
    if ($row['tipo'] === 'booleano') {
        $row['valor'] = ($row['valor'] == '1') ? 'Sim' : 'Não';
    }
    $atributos[] = $row;
}
$stmt->close();

// BUSCAR ARQUIVOS
$sql_arq = "SELECT * FROM arquivos WHERE produto_id = ?";
$stmt = $conn->prepare($sql_arq);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_arq = $stmt->get_result();
while ($row = $res_arq->fetch_assoc()) {
    $arquivos[] = $row;
}
$stmt->close();

// BUSCAR ESTOQUE (Usando nomes formatados se possível)
$mapa_locais = [];
if (function_exists('getLocaisFormatados')) {
    $mapa_locais = getLocaisFormatados($conn, false);
}

$sql_est = "SELECT local_id, quantidade, data_atualizado FROM estoques WHERE produto_id = ? AND quantidade > 0";
$stmt = $conn->prepare($sql_est);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_est = $stmt->get_result();
while ($row = $res_est->fetch_assoc()) {
    // Tenta pegar o nome hierárquico, se não, pega o ID mesmo (fallback)
    $nome_local = $mapa_locais[$row['local_id']] ?? "Local ID: " . $row['local_id'];
    $row['nome_local'] = $nome_local;
    $estoque[] = $row;
}
$stmt->close();

// BUSCAR COMPOSIÇÃO (Se for um Kit)
$sql_comp = "
    SELECT p.id as produto_id, p.nome, pr.quantidade, pr.tipo_relacao 
    FROM produto_relacionamento pr
    JOIN produtos p ON pr.subproduto_id = p.id
    WHERE pr.produto_principal_id = ?
";
$stmt = $conn->prepare($sql_comp);
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$res_comp = $stmt->get_result();
while ($row = $res_comp->fetch_assoc()) {
    $componentes[] = $row;
}
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Detalhes do Produto: <?php echo htmlspecialchars($produto['nome']); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f9f9f9; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 20px; }
        h1 { margin: 0; color: #333; }
        .actions a { text-decoration: none; padding: 8px 15px; border-radius: 4px; color: white; margin-left: 5px; font-size: 0.9em; }
        .btn-back { background-color: #7f8c8d; }
        .btn-edit { background-color: #f39c12; }
        .btn-del { background-color: #c0392b; }
        
        .section { margin-bottom: 30px; }
        h2 { font-size: 1.2em; color: #555; border-left: 4px solid #3498db; padding-left: 10px; margin-bottom: 15px; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
        .info-item label { font-weight: bold; color: #777; display: block; font-size: 0.85em; text-transform: uppercase; }
        .info-item span { font-size: 1.1em; color: #333; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #eee; padding: 10px; text-align: left; }
        th { background-color: #f8f9fa; color: #555; }
        
        .file-card { display: inline-block; border: 1px solid #ddd; padding: 10px; margin-right: 10px; border-radius: 4px; text-align: center; background: #fafafa; }
        .file-card img { max-width: 100px; max-height: 100px; display: block; margin: 0 auto 5px; }
        .file-card a { text-decoration: none; color: #3498db; font-size: 0.9em; }

        /* small styling for components-tab container */
        #components-tab-wrapper { margin-top: 12px; padding: 12px; border: 1px solid #eee; background:#fafafa; border-radius:6px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars($produto['nome']); ?></h1>
            <span style="color: #777;">ID: <?php echo $produto['id']; ?></span>
        </div>
    </div>

    <div class="section">
        <h2>Informações Gerais</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Categoria</label>
                <span><?php echo htmlspecialchars($produto['categoria_nome']); ?></span>
            </div>
            <div class="info-item">
                <label>Data de Criação</label>
                <span><?php echo date('d/m/Y H:i', strtotime($produto['data_criado'])); ?></span>
            </div>
            <?php if ($produto['controla_estoque_proprio'] == 0): ?>
            <div class="info-item">
                <label>Tipo</label>
                <span style="color: #e67e22;">Kit / Virtual</span>
            </div>
            <?php endif; ?>
        </div>
        <div style="margin-top: 15px;">
            <label style="font-weight: bold; color: #777;">Descrição:</label>
            <p style="margin-top: 5px; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($produto['descricao'])); ?></p>
        </div>
    </div>

    <?php if (!empty($atributos)): ?>
    <div class="section">
        <h2>Especificações Técnicas</h2>
        <div class="info-grid">
            <?php foreach ($atributos as $attr): ?>
            <div class="info-item">
                <label><?php echo htmlspecialchars($attr['nome']); ?></label>
                <span><?php echo htmlspecialchars($attr['valor']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($componentes)): ?>
    <div class="section">
        <h2>Composição do Produto (BOM)</h2>
        <table>
            <thead>
                <tr>
                    <th>Componente</th>
                    <th>Quantidade</th>
                    <th>Tipo de Relação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($componentes as $comp): ?>
                <tr data-produto-id="<?php echo (int)$comp['produto_id']; ?>">
                    <td><?php echo htmlspecialchars($comp['nome']); ?></td>
                    <td><?php echo htmlspecialchars($comp['quantidade']); ?></td>
                    <td><?php echo ucfirst($comp['tipo_relacao']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- components tab UI: container + hidden input -->
        <div id="components-tab-wrapper">
            <!-- Hidden product id for JS -->
            <input type="hidden" name="produto_id_for_kit" id="produto_id_for_kit" value="<?php echo (int)$produto['id']; ?>">

            <!-- Components UI container (kit_components_ui.js will fill this) -->
            <div id="components-tab"></div>

            <!-- Output area (fallback) -->
            <pre id="components-output" style="margin-top:8px;background:#fff;padding:8px;border:1px solid #ddd;max-height:240px;overflow:auto;"></pre>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Posição de Estoque</h2>
        <?php if (!empty($estoque)): ?>
        <table>
            <thead>
                <tr>
                    <th>Localização</th>
                    <th>Quantidade</th>
                    <th>Última Atualização</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                foreach ($estoque as $est): 
                    $total += $est['quantidade'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($est['nome_local']); ?></td>
                    <td style="font-weight: bold; color: green;"><?php echo $est['quantidade']; ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($est['data_atualizado'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color: #eee;">
                    <td style="text-align: right; font-weight: bold;">TOTAL GERAL:</td>
                    <td style="font-weight: bold;"><?php echo $total; ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color: #888; font-style: italic;">Produto sem saldo em estoque no momento.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($arquivos)): ?>
    <div class="section">
        <h2>Arquivos Anexados</h2>
        <div>
            <?php foreach ($arquivos as $arq): 
                // CORREÇÃO 3: Caminho relativo para subir até a raiz (../../)
                $caminho_relativo = "../../" . $arq['caminho'];
            ?>
                <div class="file-card">
                    <?php if ($arq['tipo'] == 'imagem'): ?>
                        <a href="<?php echo htmlspecialchars($caminho_relativo); ?>" target="_blank">
                            <img src="<?php echo htmlspecialchars($caminho_relativo); ?>" alt="Imagem">
                        </a>
                    <?php else: ?>
                        <div style="font-size: 3em; color: #ccc;">📄</div>
                    <?php endif; ?>
                    
                    <div>
                        <strong><?php echo ucfirst($arq['tipo']); ?></strong><br>
                        <a href="<?php echo htmlspecialchars($caminho_relativo); ?>" target="_blank">Visualizar / Baixar</a>
                        <div style="font-size: 0.8em; color: #aaa; margin-top: 5px;">
                            <?php echo date('d/m/Y', strtotime($arq['data_criado'])); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="info-item">
    <label>Tipo de Posse</label>
    <span>
        <?php 
        $tipo_posse = $produto['tipo_posse'] ?? 'proprio';
        echo $tipo_posse == 'locado' ? 'Locado' : 'Próprio';
        if ($tipo_posse == 'locado' && !empty($produto['locador_nome'])) {
            echo '<br><small>Locador: ' . htmlspecialchars($produto['locador_nome']) . '</small>';
        }
        ?>
    </span>
</div>

<!-- Expose logged user id to JS (optional) -->
<script>
    window.LOGGED_USER_ID = <?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>;
</script>

<!-- Include components UI scripts (paths relative to this file) -->
<script src="../../assets/js/kit_allocate_ui.js"></script>
<script src="../../assets/js/kit_components_ui.js"></script>
<script src="../../assets/js/allocation_modal.js"></script>

</body>
</html>