<?php
require_once '../../config/_protecao.php';

$status_message = "";
$produto_id = null;
$produto_data = null; 
$atributos_valores_atuais = []; 
$arquivos_existentes = []; 
$componentes_atuais = []; 
$categorias_raiz = []; 
$produtos_lista = []; 
$usuario_id_log = getUsuarioId();

// Detecta usuário/unidade
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// --- 1. FUNÇÃO AUXILIAR EAV ---
function get_eav_params_update($valor, $tipo) {
    $coluna_valor = null; $bind_type = null; $valor_tratado = $valor;
    if ($tipo === 'selecao' || $tipo === 'select') $tipo = 'opcao';
    if ($tipo !== 'booleano' && ($valor === '' || $valor === null || (is_array($valor) && empty($valor)))) {
        return ['coluna_valor' => null, 'bind_type' => null, 'valor_tratado' => null];
    }
    
    switch ($tipo) {
        case 'texto':
        case 'opcao':
        case 'multi_opcao':
            $coluna_valor = 'valor_texto'; $bind_type = 's';
            break;
        case 'numero':
            $coluna_valor = 'valor_numero'; $bind_type = 'd'; 
            $valor_tratado = (float)str_replace(',', '.', $valor); 
            break;
        case 'booleano':
            $coluna_valor = 'valor_booleano'; $bind_type = 'i'; 
            $valor_tratado = ($valor === '1' || $valor === 'on' || $valor === 1) ? 1 : 0;
            break;
        case 'data':
            $coluna_valor = 'valor_data'; $bind_type = 's'; 
            break;
        default:
            $coluna_valor = 'valor_texto'; $bind_type = 's';
    }
    return ['coluna_valor' => $coluna_valor, 'bind_type' => $bind_type, 'valor_tratado' => $valor_tratado];
}

function obterOpcaoPorId($conn, $opcao_id) {
    $stmt = $conn->prepare("SELECT id, atributo_id, valor FROM atributos_opcoes WHERE id = ?");
    $stmt->bind_param("i", $opcao_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mapOpcaoParaValorPermitido($conn, $opcao_id) {
    $op = obterOpcaoPorId($conn, $opcao_id);
    if (!$op) return null;
    $atributo_id = (int)$op['atributo_id'];
    $valor_texto = $op['valor'];
    $stmt = $conn->prepare("SELECT id FROM atributos_valores_permitidos WHERE atributo_id = ? AND valor_permitido = ?");
    $stmt->bind_param("is", $atributo_id, $valor_texto);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];
    $stmt = $conn->prepare("INSERT INTO atributos_valores_permitidos (atributo_id, valor_permitido) VALUES (?, ?)");
    $stmt->bind_param("is", $atributo_id, $valor_texto);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    return $newId ? (int)$newId : null;
}

// Validar ID
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $produto_id = (int)$_GET['id'];
} elseif (isset($_POST['produto_id'])) {
    $produto_id = (int)$_POST['produto_id'];
}

if (!$produto_id) {
    header("Location: listar.php"); exit;
}

// --- 2. CARREGAR DADOS PARA DROPDOWNS ---
$res_cat = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
while ($r = $res_cat->fetch_assoc()) $categorias_raiz[] = $r;

// Lista de produtos
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql_prod = "
        SELECT DISTINCT p.id, p.nome
        FROM produtos p
        WHERE p.deletado = FALSE
        AND (
            EXISTS (SELECT 1 FROM estoques e WHERE e.produto_id = p.id AND e.local_id IN ($idsStr))
            OR EXISTS (SELECT 1 FROM patrimonios pt WHERE pt.produto_id = p.id AND pt.local_id IN ($idsStr))
        )
        ORDER BY p.nome
    ";
    $res_prod = $conn->query($sql_prod);
} else {
    $res_prod = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE AND id != $produto_id ORDER BY nome");
}
while ($r = $res_prod->fetch_assoc()) $produtos_lista[] = $r;

// --- 3. LÓGICA DE ATUALIZAÇÃO (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome_produto'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    
    // NOVA LÓGICA: Categoria final do último select
    $categoria_id = 0;
    if (!empty($_POST['categoria_final'])) {
        $categoria_id = (int)$_POST['categoria_final'];
    }
    
    $is_kit = isset($_POST['is_kit']);
    $controla_estoque = isset($_POST['controla_estoque_proprio']) ? (int)$_POST['controla_estoque_proprio'] : 1;
    
    $tipo_posse = $_POST['tipo_posse'] ?? 'proprio';
    $locador_nome = trim($_POST['locador_nome'] ?? '');

    if (empty($nome) || $categoria_id <= 0) {
        $status_message = "<p style='color: red;'>Nome e Categoria são obrigatórios.</p>";
    } elseif ($tipo_posse == 'locado' && empty($locador_nome)) {
        $status_message = "<p style='color:red'>Para produtos locados, o nome do locador é obrigatório.</p>";
    } else {
        if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
            $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
            $sql_check = "SELECT 1 FROM estoques e WHERE e.produto_id = ? AND e.local_id IN ($idsStr) LIMIT 1";
            $stc = $conn->prepare($sql_check);
            $stc->bind_param("i", $produto_id);
            $stc->execute();
            $rc = $stc->get_result();
            $stc->close();
            if (!($rc && $rc->num_rows > 0)) {
                $sql_check2 = "SELECT 1 FROM patrimonios pt WHERE pt.produto_id = ? AND pt.local_id IN ($idsStr) LIMIT 1";
                $stc2 = $conn->prepare($sql_check2);
                $stc2->bind_param("i", $produto_id);
                $stc2->execute();
                $rc2 = $stc2->get_result();
                $stc2->close();
                if (!($rc2 && $rc2->num_rows > 0)) {
                    $status_message = "<p style='color:red'>Você não tem permissão para editar este produto (fora da sua unidade).</p>";
                }
            }
        }

        if (empty($status_message)) {
            $conn->begin_transaction();
            try {
                $sql_up = "UPDATE produtos SET nome=?, descricao=?, categoria_id=?, controla_estoque_proprio=?, tipo_posse=?, locador_nome=?, data_atualizado=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql_up);
                $stmt->bind_param("ssiissi", $nome, $descricao, $categoria_id, $controla_estoque, $tipo_posse, $locador_nome, $produto_id);
                $stmt->execute();
                $stmt->close();

                $conn->query("DELETE FROM produto_relacionamento WHERE produto_principal_id = $produto_id AND tipo_relacao = 'kit'");
                
                if ($is_kit && !empty($_POST['componente_id'])) {
                    $stmt_k = $conn->prepare("INSERT INTO produto_relacionamento (produto_principal_id, subproduto_id, quantidade, tipo_relacao) VALUES (?, ?, ?, 'kit')");
                    foreach ($_POST['componente_id'] as $idx => $sub_id) {
                        $qtd = (float)($_POST['componente_qtd'][$idx] ?? 1);
                        if ($sub_id > 0 && $qtd > 0) {
                            $stmt_k->bind_param("iid", $produto_id, $sub_id, $qtd);
                            $stmt_k->execute();
                        }
                    }
                    $stmt_k->close();
                }

                $conn->query("DELETE FROM atributos_valor WHERE produto_id = $produto_id");
                
                if (!empty($_POST['atributo_valor']) && is_array($_POST['atributo_valor'])) {
                    foreach ($_POST['atributo_valor'] as $aid => $val) {
                        $attr_id = (int)$aid;
                        $tipo = strtolower($_POST["tipo_attr_" . $attr_id] ?? 'texto');
                        $val_salvar = is_array($val) ? implode(',', $val) : $val;

                        if (in_array($tipo, ['selecao','select','opcao','multi_opcao'])) {
                            if (is_numeric($val_salvar) && (int)$val_salvar > 0) {
                                $op = obterOpcaoPorId($conn, (int)$val_salvar);
                                if ($op) {
                                    $vp_id = mapOpcaoParaValorPermitido($conn, (int)$val_salvar);
                                    $texto = $op['valor'];
                                    if ($vp_id) {
                                        $stmt_a = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto, valor_permitido_id) VALUES (?, ?, ?, ?)");
                                        $stmt_a->bind_param("iisi", $produto_id, $attr_id, $texto, $vp_id);
                                        $stmt_a->execute();
                                        $stmt_a->close();
                                        continue;
                                    } else {
                                        $stmt_a = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                                        $stmt_a->bind_param("iis", $produto_id, $attr_id, $texto);
                                        $stmt_a->execute();
                                        $stmt_a->close();
                                        continue;
                                    }
                                }
                            } else {
                                $stmt_a = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                                $stmt_a->bind_param("iis", $produto_id, $attr_id, $val_salvar);
                                $stmt_a->execute();
                                $stmt_a->close();
                                continue;
                            }
                        }

                        $params = get_eav_params_update($val_salvar, $tipo);
                        if ($params['coluna_valor']) {
                            $sql_ins = "INSERT INTO atributos_valor (produto_id, atributo_id, {$params['coluna_valor']}) VALUES (?, ?, ?)";
                            $stmt_a = $conn->prepare($sql_ins);
                            $bind = "ii" . $params['bind_type'];
                            $stmt_a->bind_param($bind, $produto_id, $attr_id, $params['valor_tratado']);
                            $stmt_a->execute();
                            $stmt_a->close();
                        }
                    }
                }

                if (function_exists('processarUploadArquivo')) {
                    if (!empty($_FILES['arq_imagem']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_imagem'], 'imagem');
                    if (!empty($_FILES['arq_nota']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_nota'], 'nota_fiscal');
                    if (!empty($_FILES['arq_manual']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_manual'], 'manual');
                }
                
                if(function_exists('registrarLog')) registrarLog($conn, $usuario_id_log, 'produtos', $produto_id, 'EDICAO', "Produto editado.", $produto_id);

                $conn->commit();
                if (isset($_GET['modal'])) {
                    echo "<script>
                        alert('Operação realizada com sucesso!');
                        parent.fecharModalDoFilho(true);
                    </script>";
                    exit;
                } else {
                    // Comportamento normal se acessar direto
                    header("Location: index.php?sucesso=1");
                    exit;
                }

            } catch (Exception $e) {
                $conn->rollback();
                $status_message = "<p style='color:red'>Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
}

// --- 4. CARREGAR DADOS ATUAIS (GET) ---
$stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$produto_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$produto_data) die("Produto não encontrado.");

if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql_check = "SELECT 1 FROM estoques e WHERE e.produto_id = ? AND e.local_id IN ($idsStr) LIMIT 1";
    $stc = $conn->prepare($sql_check);
    $stc->bind_param("i", $produto_id);
    $stc->execute();
    $rc = $stc->get_result();
    $stc->close();
    if (!($rc && $rc->num_rows > 0)) {
        $sql_check2 = "SELECT 1 FROM patrimonios pt WHERE pt.produto_id = ? AND pt.local_id IN ($idsStr) LIMIT 1";
        $stc2 = $conn->prepare($sql_check2);
        $stc2->bind_param("i", $produto_id);
        $stc2->execute();
        $rc2 = $stc2->get_result();
        $stc2->close();
        if (!($rc2 && $rc2->num_rows > 0)) {
            die("Acesso negado: produto fora da sua unidade.");
        }
    }
}

// Carregar Componentes
$sql_k = "SELECT subproduto_id, quantidade FROM produto_relacionamento WHERE produto_principal_id = $produto_id AND tipo_relacao = 'kit'";
$res_k = $conn->query($sql_k);
while($r = $res_k->fetch_assoc()) $componentes_atuais[] = $r;

$is_kit_atual = (count($componentes_atuais) > 0) || ($produto_data['controla_estoque_proprio'] == 0);

// Carregar Atributos
$sql_av = "
    SELECT av.atributo_id, av.valor_permitido_id,
    COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) as val 
    FROM atributos_valor av WHERE produto_id = $produto_id
";
$res_av = $conn->query($sql_av);
while($r = $res_av->fetch_assoc()) {
    if (!empty($r['valor_permitido_id'])) $atributos_valores_atuais[$r['atributo_id']] = $r['valor_permitido_id'];
    else $atributos_valores_atuais[$r['atributo_id']] = $r['val'];
}

$valores_json = json_encode($atributos_valores_atuais);
$produtos_lista_json = json_encode($produtos_lista);

// Arquivos
$res_arq = $conn->query("SELECT id, tipo, caminho FROM arquivos WHERE produto_id = $produto_id");
while($r = $res_arq->fetch_assoc()) $arquivos_existentes[$r['tipo']][] = $r;

// BUSCAR CAMINHO DA CATEGORIA (HIERARQUIA COMPLETA)
function getCaminhoCategoria($conn, $categoria_id) {
    $caminho = [];
    $atual = $categoria_id;
    $profundidade = 0;
    $max_profundidade = 20;
    
    while ($atual && $profundidade < $max_profundidade) {
        $stmt = $conn->prepare("SELECT id, nome, categoria_pai_id FROM categorias WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $atual);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            array_unshift($caminho, ['id' => $row['id'], 'nome' => $row['nome']]);
            $atual = $row['categoria_pai_id'];
        } else {
            break;
        }
        $profundidade++;
    }
    
    return $caminho;
}

$caminho_categoria = getCaminhoCategoria($conn, $produto_data['categoria_id']);
$caminho_categoria_json = json_encode($caminho_categoria);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Editar Produto</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 8px; box-sizing: border-box; }
        .required-star { color: red; }
        
        #area-kit { background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-top: 10px; display: none; }
        .linha-comp { display: flex; gap: 10px; margin-bottom: 5px; align-items: center; }
        .btn-rmv { background: #e74c3c; color: white; border: none; padding: 5px 10px; cursor: pointer; }
        .file-list img { max-height: 50px; vertical-align: middle; margin-right: 10px; }
        .file-list { margin-bottom: 10px; }
        
        /* Estilos para hierarquia de categorias */
        #categoria-hierarchy-container { 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            margin-bottom: 15px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .categoria-level { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        .categoria-level label {
            min-width: 100px;
            margin: 0;
        }
        .categoria-level select {
            flex: 1;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .breadcrumb-display {
            font-size: 0.9em;
            color: #666;
            padding: 8px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            min-height: 20px;
        }
        .breadcrumb-display strong {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Editar Produto</h1>
        <?php echo $status_message; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="produto_id" value="<?php echo $produto_id; ?>">
            
            <div class="form-group">
                <label>Nome <span class="required-star">*</span></label>
                <input type="text" name="nome_produto" value="<?php echo htmlspecialchars($produto_data['nome']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="descricao"><?php echo htmlspecialchars($produto_data['descricao']); ?></textarea>
            </div>

            <div class="form-group">
                <label>Tipo de Posse <span class="required-star">*</span></label>
                <select name="tipo_posse" id="tipo_posse" required onchange="toggleLocadorField()">
                    <option value="proprio" <?php echo ($produto_data['tipo_posse'] ?? 'proprio') == 'proprio' ? 'selected' : ''; ?>>Próprio</option>
                    <option value="locado" <?php echo ($produto_data['tipo_posse'] ?? 'proprio') == 'locado' ? 'selected' : ''; ?>>Locado</option>
                </select>
            </div>

            <div class="form-group" id="locador-group" style="display: <?php echo (($produto_data['tipo_posse'] ?? 'proprio') == 'locado') ? 'block' : 'none'; ?>;">
                <label>Nome do Locador <span class="required-star">*</span></label>
                <input type="text" name="locador_nome" id="locador_nome" value="<?php echo htmlspecialchars($produto_data['locador_nome'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label style="display:inline; cursor:pointer;">
                    <input type="checkbox" id="is_kit" name="is_kit" value="1" <?php echo $is_kit_atual ? 'checked' : ''; ?>> 
                    Este produto é um Kit?
                </label>
                <input type="hidden" name="controla_estoque_proprio" id="controla_estoque_hidden" value="<?php echo $produto_data['controla_estoque_proprio']; ?>">
            </div>

            <div id="area-kit">
                <h3>Componentes</h3>
                <div id="lista-comps">
                    <?php foreach($componentes_atuais as $comp): ?>
                        <div class="linha-comp">
                            <select name="componente_id[]" required style="flex:2">
                                <option value="">Selecione...</option>
                                <?php foreach($produtos_lista as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] == $comp['subproduto_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="componente_qtd[]" value="<?php echo $comp['quantidade']; ?>" min="0.01" step="any" style="flex:1">
                            <button type="button" class="btn-rmv" onclick="this.parentElement.remove()">X</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addComp()" style="margin-top:10px;">+ Componente</button>
            </div>

            <!-- NOVA SEÇÃO DE CATEGORIA HIERÁRQUICA -->
            <div class="form-group">
                <label>Categoria <span class="required-star">*</span></label>
                <div class="breadcrumb-display" id="categoria-breadcrumb">
                    Carregando categoria atual...
                </div>
                <div id="categoria-hierarchy-container">
                    <!-- Será preenchido dinamicamente pelo JavaScript -->
                </div>
                <input type="hidden" name="categoria_final" id="categoria_final" required>
            </div>

            <div id="atributos-dinamicos"><p>Carregando atributos...</p></div>

            <h3>Arquivos</h3>
            <div class="file-group">
                <label>Imagem:</label>
                <?php if(!empty($arquivos_existentes['imagem'])): ?>
                    <div class="file-list">
                        <img src="../../<?php echo $arquivos_existentes['imagem'][0]['caminho']; ?>"> 
                        <a href="../../<?php echo $arquivos_existentes['imagem'][0]['caminho']; ?>" target="_blank">Atual</a>
                    </div>
                <?php endif; ?>
                <input type="file" name="arq_imagem">
            </div>

            <button type="submit" style="margin-top:20px; padding:10px 20px; background:#f39c12; color:white; border:none; cursor:pointer;">Salvar Alterações</button>
        </form>
    </div>

    <script>
        const prods = <?php echo $produtos_lista_json; ?>;
        const caminhoCategoria = <?php echo $caminho_categoria_json; ?>;
        
        const ckKit = document.getElementById('is_kit');
        const areaKit = document.getElementById('area-kit');
        const lsComp = document.getElementById('lista-comps');
        const hiddenEstoque = document.getElementById('controla_estoque_hidden');

        function toggleLocadorField() {
            const tipoPosse = document.getElementById('tipo_posse').value;
            const locadorGroup = document.getElementById('locador-group');
            const locadorInput = document.getElementById('locador_nome');
            
            if (tipoPosse === 'locado') {
                locadorGroup.style.display = 'block';
                locadorInput.required = true;
            } else {
                locadorGroup.style.display = 'none';
                locadorInput.required = false;
                locadorInput.value = '';
            }
        }

        toggleLocadorField();

        function toggleKit() {
            const isChecked = ckKit.checked;
            areaKit.style.display = isChecked ? 'block' : 'none';
            hiddenEstoque.value = isChecked ? '0' : '1';
            
            const inputsKit = areaKit.querySelectorAll('input, select');
            inputsKit.forEach(el => {
                el.disabled = !isChecked;
            });

            if(isChecked && lsComp.innerHTML.trim() == '') addComp();
        }
        ckKit.addEventListener('change', toggleKit);
        toggleKit(); 

        function addComp() {
            let div = document.createElement('div');
            div.className = 'linha-comp';
            let ops = '<option value="">Produto...</option>';
            prods.forEach(p => ops+=`<option value="${p.id}">${p.nome}</option>`);
            div.innerHTML = `<select name="componente_id[]" required style="flex:2">${ops}</select>
                             <input type="number" name="componente_qtd[]" value="1" min="0.01" step="any" style="flex:1">
                             <button type="button" class="btn-rmv" onclick="this.parentElement.remove()">X</button>`;
            lsComp.appendChild(div);
        }
    </script>

    <!-- SCRIPT PARA HIERARQUIA DE CATEGORIAS NA EDIÇÃO -->
    <script>
        (function() {
            const container = document.getElementById('categoria-hierarchy-container');
            const hiddenInput = document.getElementById('categoria_final');
            const breadcrumbDiv = document.getElementById('categoria-breadcrumb');
            let nivelAtual = 0;
            let categoriasPath = [];

            // Inicializar com o caminho da categoria atual
            inicializarCategoriaAtual();

            container.addEventListener('change', function(e) {
                if (e.target.classList.contains('categoria-select')) {
                    const nivel = parseInt(e.target.dataset.nivel);
                    const categoriaId = e.target.value;
                    
                    removerNiveisPosteriores(nivel);
                    
                    if (categoriaId) {
                        const categoriaTexto = e.target.options[e.target.selectedIndex].text;
                        categoriasPath = categoriasPath.slice(0, nivel);
                        categoriasPath.push({id: categoriaId, nome: categoriaTexto});
                        hiddenInput.value = categoriaId;
                        atualizarBreadcrumb();
                        buscarSubcategorias(categoriaId, nivel + 1);
                        
                        if (typeof carregarAtributos === 'function') {
                            carregarAtributos(categoriaId);
                        }
                    } else {
                        hiddenInput.value = '';
                        categoriasPath = categoriasPath.slice(0, nivel);
                        atualizarBreadcrumb();
                    }
                }
            });

            function inicializarCategoriaAtual() {
                if (caminhoCategoria && caminhoCategoria.length > 0) {
                    categoriasPath = caminhoCategoria;
                    hiddenInput.value = caminhoCategoria[caminhoCategoria.length - 1].id;
                    atualizarBreadcrumb();
                    
                    // Construir os selects para cada nível
                    construirNiveisIniciais();
                }
            }

            async function construirNiveisIniciais() {
                // Buscar categorias raiz
                const categorias_raiz = <?php echo json_encode($categorias_raiz); ?>;
                
                // Criar primeiro nível
                const primeiroNivelDiv = document.createElement('div');
                primeiroNivelDiv.className = 'categoria-level';
                primeiroNivelDiv.id = 'nivel-0';
                
                const label = document.createElement('label');
                label.textContent = 'Nível 1:';
                
                const select = document.createElement('select');
                select.className = 'categoria-select';
                select.dataset.nivel = '0';
                select.required = true;
                
                const optionDefault = document.createElement('option');
                optionDefault.value = '';
                optionDefault.textContent = 'Selecione a categoria principal...';
                select.appendChild(optionDefault);
                
                categorias_raiz.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat.id;
                    option.textContent = cat.nome;
                    if (categoriasPath[0] && categoriasPath[0].id == cat.id) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                
                primeiroNivelDiv.appendChild(label);
                primeiroNivelDiv.appendChild(select);
                container.appendChild(primeiroNivelDiv);
                
                // Construir níveis subsequentes
                for (let i = 0; i < categoriasPath.length - 1; i++) {
                    const catAtual = categoriasPath[i];
                    const catProxima = categoriasPath[i + 1];
                    
                    try {
                        const response = await fetch(`../../api/categorias_filhos.php?categoria_id=${catAtual.id}`);
                        const data = await response.json();
                        
                        if (data.sucesso && data.categorias && data.categorias.length > 0) {
                            adicionarNivelCategoria(data.categorias, i + 1, catProxima.id);
                        }
                    } catch (err) {
                        console.error('Erro ao buscar subcategorias:', err);
                    }
                }
            }

            function removerNiveisPosteriores(nivel) {
                const niveis = container.querySelectorAll('.categoria-level');
                niveis.forEach((nivelDiv, idx) => {
                    if (idx > nivel) {
                        nivelDiv.remove();
                    }
                });
                nivelAtual = nivel;
            }

            function buscarSubcategorias(categoriaId, proximoNivel) {
                fetch(`../../api/categorias_filhos.php?categoria_id=${categoriaId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.sucesso && data.categorias && data.categorias.length > 0) {
                            adicionarNivelCategoria(data.categorias, proximoNivel);
                        }
                    })
                    .catch(err => console.error('Erro ao buscar subcategorias:', err));
            }

            function adicionarNivelCategoria(categorias, nivel, selectedId = null) {
                const nivelDiv = document.createElement('div');
                nivelDiv.className = 'categoria-level';
                nivelDiv.id = `nivel-${nivel}`;
                
                const label = document.createElement('label');
                label.textContent = `Nível ${nivel + 1}:`;
                
                const select = document.createElement('select');
                select.className = 'categoria-select';
                select.dataset.nivel = nivel;
                
                const optionDefault = document.createElement('option');
                optionDefault.value = '';
                optionDefault.textContent = '(Opcional - selecione uma subcategoria)';
                select.appendChild(optionDefault);
                
                categorias.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat.id;
                    option.textContent = cat.nome;
                    if (selectedId && cat.id == selectedId) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                
                nivelDiv.appendChild(label);
                nivelDiv.appendChild(select);
                container.appendChild(nivelDiv);
                
                nivelAtual = nivel;
            }

            function atualizarBreadcrumb() {
                if (categoriasPath.length === 0) {
                    breadcrumbDiv.innerHTML = 'Selecione uma categoria abaixo...';
                } else {
                    const nomes = categoriasPath.map(c => c.nome);
                    breadcrumbDiv.innerHTML = '<strong>Categoria selecionada:</strong> ' + nomes.join(' → ');
                }
            }
        })();
    </script>

    <script src="../../assets/js/scripts.js"></script>
    <script>
        // Carregar atributos da categoria atual ao iniciar
        const categoriaId = <?php echo $produto_data['categoria_id']; ?>;
        if (categoriaId && typeof carregarAtributos === 'function') {
            carregarAtributos(categoriaId);
        }
    </script>
</body>
</html>