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

// Helper EAV (Update)
function get_eav_params_update($valor, $tipo) {
    $col = null; $bind = null; $v = $valor;
    if ($tipo === 'selecao' || $tipo === 'select') $tipo = 'opcao';
    if ($tipo !== 'booleano' && ($valor === '' || $valor === null)) return ['coluna_valor'=>null];
    switch ($tipo) {
        case 'texto': case 'opcao': case 'multi_opcao': $col = 'valor_texto'; $bind = 's'; break;
        case 'numero': $col = 'valor_numero'; $bind = 'd'; $v = (float)str_replace(',', '.', $valor); break;
        case 'booleano': $col = 'valor_booleano'; $bind = 'i'; $v = ($valor == '1' || $valor == 'on') ? 1 : 0; break;
        case 'data': $col = 'valor_data'; $bind = 's'; break;
        default: $col = 'valor_texto'; $bind = 's';
    }
    return ['coluna_valor' => $col, 'bind_type' => $bind, 'valor_tratado' => $v];
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
    $stmt = $conn->prepare("SELECT id FROM atributos_valores_permitidos WHERE atributo_id = ? AND valor_permitido = ?");
    $stmt->bind_param("is", $op['atributo_id'], $op['valor']);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];
    $stmt = $conn->prepare("INSERT INTO atributos_valores_permitidos (atributo_id, valor_permitido) VALUES (?, ?)");
    $stmt->bind_param("is", $op['atributo_id'], $op['valor']);
    $stmt->execute();
    return $stmt->insert_id;
}

// Validar ID
if (isset($_GET['id']) && is_numeric($_GET['id'])) $produto_id = (int)$_GET['id'];
elseif (isset($_POST['produto_id'])) $produto_id = (int)$_POST['produto_id'];

if (!$produto_id) { header("Location: index.php"); exit; }

// Carregar Dropdowns
$res_cat = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
while ($r = $res_cat->fetch_assoc()) $categorias_raiz[] = $r;

// Lista produtos
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql_prod = "SELECT DISTINCT p.id, p.nome FROM produtos p WHERE p.deletado = FALSE AND (EXISTS (SELECT 1 FROM estoques e WHERE e.produto_id = p.id AND e.local_id IN ($idsStr)) OR EXISTS (SELECT 1 FROM patrimonios pt WHERE pt.produto_id = p.id AND pt.local_id IN ($idsStr))) ORDER BY p.nome";
    $res_prod = $conn->query($sql_prod);
} else {
    $res_prod = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE AND id != $produto_id ORDER BY nome");
}
while ($r = $res_prod->fetch_assoc()) $produtos_lista[] = $r;

// --- 3. LÓGICA DE ATUALIZAÇÃO (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome_produto'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria_id = !empty($_POST['categoria_final']) ? (int)$_POST['categoria_final'] : 0;
    
    $is_kit = isset($_POST['is_kit']);
    $controla_estoque = isset($_POST['controla_estoque_proprio']) ? (int)$_POST['controla_estoque_proprio'] : 1;
    
    $tipo_posse = $_POST['tipo_posse'] ?? 'proprio';
    $locador_nome = trim($_POST['locador_nome'] ?? '');
    $locacao_contrato = trim($_POST['locacao_contrato'] ?? '');

    // Patrimônio Manual
    $tem_patrimonio = isset($_POST['tem_patrimonio']);
    $numero_patrimonio = null;
    if ($tem_patrimonio) {
        $numero_patrimonio = trim($_POST['numero_patrimonio'] ?? '');
    }

    if (empty($nome) || $categoria_id <= 0) {
        $status_message = "<p style='color: red;'>Nome e Categoria são obrigatórios.</p>";
    } elseif ($tipo_posse == 'locado' && (empty($locador_nome) || empty($locacao_contrato))) {
        $status_message = "<p style='color:red'>Para produtos locados, locador e contrato são obrigatórios.</p>";
    } elseif ($tem_patrimonio && empty($numero_patrimonio)) {
        $status_message = "<p style='color:red'>Se possui patrimônio, o número é obrigatório.</p>";
    } else {
        // Validação de unidade
        if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
            $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
            $sql_check = "SELECT 1 FROM estoques e WHERE e.produto_id = ? AND e.local_id IN ($idsStr) LIMIT 1";
            $stc = $conn->prepare($sql_check); $stc->bind_param("i", $produto_id); $stc->execute();
            if ($stc->get_result()->num_rows == 0) {
                // Checa patrimonios
                $stc->close();
                $sql_check2 = "SELECT 1 FROM patrimonios pt WHERE pt.produto_id = ? AND pt.local_id IN ($idsStr) LIMIT 1";
                $stc2 = $conn->prepare($sql_check2); $stc2->bind_param("i", $produto_id); $stc2->execute();
                if ($stc2->get_result()->num_rows == 0) $status_message = "<p style='color:red'>Acesso negado.</p>";
                $stc2->close();
            } else {
                $stc->close();
            }
        }

        // Checar duplicidade de patrimônio (excluindo o próprio produto)
        if (empty($status_message) && $numero_patrimonio) {
            $stmt_dup = $conn->prepare("SELECT id FROM produtos WHERE numero_patrimonio = ? AND id != ? LIMIT 1");
            $stmt_dup->bind_param("si", $numero_patrimonio, $produto_id);
            $stmt_dup->execute();
            if ($stmt_dup->get_result()->num_rows > 0) {
                $status_message = "<p style='color:red'>Número de patrimônio <b>$numero_patrimonio</b> já existe em outro produto.</p>";
            }
            $stmt_dup->close();
        }

        if (empty($status_message)) {
            $conn->begin_transaction();
            try {
                $sql_up = "UPDATE produtos SET nome=?, descricao=?, categoria_id=?, controla_estoque_proprio=?, tipo_posse=?, locador_nome=?, locacao_contrato=?, numero_patrimonio=?, data_atualizado=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql_up);
                // s, s, i, i, s, s, s, s, i
                $stmt->bind_param("ssiissssi", $nome, $descricao, $categoria_id, $controla_estoque, $tipo_posse, $locador_nome, $locacao_contrato, $numero_patrimonio, $produto_id);
                $stmt->execute();
                $stmt->close();

                // Componentes
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

                // Atributos
                $conn->query("DELETE FROM atributos_valor WHERE produto_id = $produto_id");
                if (!empty($_POST['atributo_valor']) && is_array($_POST['atributo_valor'])) {
                    foreach ($_POST['atributo_valor'] as $aid => $val) {
                        $attr_id = (int)$aid;
                        $tipo = strtolower($_POST["tipo_attr_$aid"] ?? 'texto');
                        $val_salvar = is_array($val) ? implode(',', $val) : $val;

                        if (in_array($tipo, ['selecao','select','opcao','multi_opcao'])) {
                            if (is_numeric($val_salvar) && (int)$val_salvar > 0) {
                                $vp_id = mapOpcaoParaValorPermitido($conn, (int)$val_salvar);
                                $op = obterOpcaoPorId($conn, (int)$val_salvar);
                                $texto = $op ? $op['valor'] : '';
                                if ($vp_id) {
                                    $stmt_a = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto, valor_permitido_id) VALUES (?, ?, ?, ?)");
                                    $stmt_a->bind_param("iisi", $produto_id, $attr_id, $texto, $vp_id);
                                    $stmt_a->execute(); $stmt_a->close(); continue;
                                }
                            }
                            $stmt_a = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                            $stmt_a->bind_param("iis", $produto_id, $attr_id, $val_salvar);
                            $stmt_a->execute(); $stmt_a->close(); continue;
                        }

                        $p = get_eav_params_update($val_salvar, $tipo);
                        if ($p['coluna_valor']) {
                            $sql_ins = "INSERT INTO atributos_valor (produto_id, atributo_id, {$p['coluna_valor']}) VALUES (?, ?, ?)";
                            $stmt_a = $conn->prepare($sql_ins);
                            $bind = "ii" . $p['bind_type'];
                            $stmt_a->bind_param($bind, $produto_id, $attr_id, $p['valor_tratado']);
                            $stmt_a->execute(); $stmt_a->close();
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
                    echo "<script>alert('Sucesso!'); parent.fecharModalDoFilho(true);</script>"; exit;
                } else {
                    header("Location: index.php?sucesso=1"); exit;
                }

            } catch (Exception $e) {
                $conn->rollback();
                $status_message = "<p style='color:red'>Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
}

// --- 4. CARREGAR DADOS ATUAIS ---
$stmt = $conn->prepare("SELECT * FROM produtos WHERE id = ?");
$stmt->bind_param("i", $produto_id);
$stmt->execute();
$produto_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$produto_data) die("Produto não encontrado.");

// Componentes
$sql_k = "SELECT subproduto_id, quantidade FROM produto_relacionamento WHERE produto_principal_id = $produto_id AND tipo_relacao = 'kit'";
$res_k = $conn->query($sql_k);
while($r = $res_k->fetch_assoc()) $componentes_atuais[] = $r;
$is_kit_atual = (count($componentes_atuais) > 0) || ($produto_data['controla_estoque_proprio'] == 0);

// Atributos
$sql_av = "SELECT av.atributo_id, av.valor_permitido_id, COALESCE(av.valor_texto, CAST(av.valor_numero AS CHAR), CAST(av.valor_booleano AS CHAR), av.valor_data) as val FROM atributos_valor av WHERE produto_id = $produto_id";
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

// Hierarquia
function getCaminhoCategoria($conn, $categoria_id) {
    $caminho = []; $atual = $categoria_id; $prof = 0;
    while ($atual && $prof < 20) {
        $stmt = $conn->prepare("SELECT id, nome, categoria_pai_id FROM categorias WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $atual); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) { array_unshift($caminho, ['id' => $row['id'], 'nome' => $row['nome']]); $atual = $row['categoria_pai_id']; } 
        else break;
        $prof++;
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
        
        #categoria-hierarchy-container { display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
        .categoria-level { display: flex; align-items: center; gap: 10px; }
        .categoria-level select { flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        
        #locacao-container, #patrimonio-container { background: #fff3cd; padding: 15px; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 15px; }
        #patrimonio-container { background: #e3f2fd; border-color: #bbdefb; }
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
                <label style="display:inline; cursor:pointer; font-size:1.1em;">
                    <input type="checkbox" id="tem_patrimonio" name="tem_patrimonio" onchange="togglePatrimonioField()" <?php echo !empty($produto_data['numero_patrimonio']) ? 'checked' : ''; ?>> 
                    <strong>Possui etiqueta/número de patrimônio?</strong>
                </label>
            </div>

            <div id="patrimonio-container" style="display: none;">
                <div class="form-group">
                    <label>Número do Patrimônio <span class="required-star">*</span></label>
                    <input type="text" name="numero_patrimonio" id="numero_patrimonio" value="<?php echo htmlspecialchars($produto_data['numero_patrimonio'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Tipo de Posse <span class="required-star">*</span></label>
                <select name="tipo_posse" id="tipo_posse" required onchange="toggleLocadorField()">
                    <option value="proprio" <?php echo ($produto_data['tipo_posse'] ?? 'proprio') == 'proprio' ? 'selected' : ''; ?>>Próprio</option>
                    <option value="locado" <?php echo ($produto_data['tipo_posse'] ?? 'proprio') == 'locado' ? 'selected' : ''; ?>>Locado</option>
                </select>
            </div>

            <div id="locacao-container" style="display: none;">
                <div class="form-group">
                    <label>Nome do Locador <span class="required-star">*</span></label>
                    <input type="text" name="locador_nome" id="locador_nome" value="<?php echo htmlspecialchars($produto_data['locador_nome'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Contrato de Locação <span class="required-star">*</span></label>
                    <input type="text" name="locacao_contrato" id="locacao_contrato" value="<?php echo htmlspecialchars($produto_data['locacao_contrato'] ?? ''); ?>">
                </div>
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

            <div class="form-group">
                <label>Categoria <span class="required-star">*</span></label>
                <div id="categoria-breadcrumb" style="font-size:0.9em;color:#666;padding:8px;background:#fff;border:1px solid #e0e0e0;margin-bottom:10px;">Carregando...</div>
                <div id="categoria-hierarchy-container"></div>
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
            const tipo = document.getElementById('tipo_posse').value;
            const container = document.getElementById('locacao-container');
            const inputs = container.querySelectorAll('input');
            if (tipo === 'locado') {
                container.style.display = 'block';
                inputs.forEach(i => i.required = true);
            } else {
                container.style.display = 'none';
                inputs.forEach(i => { i.required = false; i.value = ''; });
            }
        }

        function togglePatrimonioField() {
            const checked = document.getElementById('tem_patrimonio').checked;
            const container = document.getElementById('patrimonio-container');
            const input = document.getElementById('numero_patrimonio');
            if (checked) {
                container.style.display = 'block';
                input.required = true;
            } else {
                container.style.display = 'none';
                input.required = false;
                input.value = '';
            }
        }

        function toggleKit() {
            const isChecked = ckKit.checked;
            areaKit.style.display = isChecked ? 'block' : 'none';
            hiddenEstoque.value = isChecked ? '0' : '1';
            const inputsKit = areaKit.querySelectorAll('input, select');
            inputsKit.forEach(el => el.disabled = !isChecked);
            if(isChecked && lsComp.innerHTML.trim() == '') addComp();
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            toggleLocadorField();
            togglePatrimonioField();
            toggleKit();
            ckKit.addEventListener('change', toggleKit);
        });

        // HIERARQUIA CATEGORIAS
        (function() {
            const container = document.getElementById('categoria-hierarchy-container');
            const hiddenInput = document.getElementById('categoria_final');
            const breadcrumbDiv = document.getElementById('categoria-breadcrumb');
            let categoriasPath = [];

            inicializarCategoriaAtual();

            container.addEventListener('change', function(e) {
                if (e.target.classList.contains('categoria-select')) {
                    const nivel = parseInt(e.target.dataset.nivel);
                    const categoriaId = e.target.value;
                    const niveis = container.querySelectorAll('.categoria-level');
                    niveis.forEach((div, idx) => { if (idx > nivel) div.remove(); });
                    
                    if (categoriaId) {
                        const txt = e.target.options[e.target.selectedIndex].text;
                        categoriasPath = categoriasPath.slice(0, nivel);
                        categoriasPath.push({id: categoriaId, nome: txt});
                        hiddenInput.value = categoriaId;
                        atualizarBreadcrumb();
                        buscarSubcategorias(categoriaId, nivel + 1);
                        if (typeof carregarAtributos === 'function') carregarAtributos(categoriaId);
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
                    construirNiveisIniciais();
                }
            }

            async function construirNiveisIniciais() {
                const categorias_raiz = <?php echo json_encode($categorias_raiz); ?>;
                addLevel(categorias_raiz, 0, categoriasPath[0]?.id);
                for (let i = 0; i < categoriasPath.length - 1; i++) {
                    const atual = categoriasPath[i];
                    const prox = categoriasPath[i + 1];
                    try {
                        const r = await fetch(`../../api/categorias_filhos.php?categoria_id=${atual.id}`);
                        const d = await r.json();
                        if (d.sucesso && d.categorias.length > 0) addLevel(d.categorias, i + 1, prox.id);
                    } catch (e) {}
                }
            }

            function buscarSubcategorias(cid, nivel) {
                fetch(`../../api/categorias_filhos.php?categoria_id=${cid}`).then(r=>r.json()).then(d=>{
                    if(d.sucesso && d.categorias.length > 0) addLevel(d.categorias, nivel);
                });
            }

            function addLevel(cats, nivel, selId = null) {
                const div = document.createElement('div');
                div.className = 'categoria-level';
                div.innerHTML = `<label>Nível ${nivel+1}:</label><select class="categoria-select" data-nivel="${nivel}"><option value="">Selecione...</option></select>`;
                const sel = div.querySelector('select');
                if(nivel===0) sel.required=true;
                cats.forEach(c => {
                    const op = document.createElement('option');
                    op.value = c.id; op.text = c.nome;
                    if(selId && c.id == selId) op.selected = true;
                    sel.add(op);
                });
                container.appendChild(div);
            }

            function atualizarBreadcrumb() {
                breadcrumbDiv.innerHTML = categoriasPath.length ? '<strong>Categoria:</strong> ' + categoriasPath.map(c=>c.nome).join(' → ') : 'Selecione...';
            }
        })();
    </script>
    <script src="../../assets/js/scripts.js"></script>
    <script>
        const catId = <?php echo $produto_data['categoria_id']; ?>;
        if (catId && typeof carregarAtributos === 'function') carregarAtributos(catId);
    </script>
</body>
</html>