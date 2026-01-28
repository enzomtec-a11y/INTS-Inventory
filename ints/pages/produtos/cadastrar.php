<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id_log = function_exists('getUsuarioId') ? getUsuarioId() : 0;

// Detecta usuário/unidade
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// Carregamentos básicos - APENAS CATEGORIAS RAIZ INICIALMENTE
$categorias_raiz = [];
$res = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
if ($res) while ($r = $res->fetch_assoc()) $categorias_raiz[] = $r;

// Use a função getLocaisFormatados para obter breadcrumb (Unidade > Andar > Sala).
$locais = [];
if (function_exists('getLocaisFormatados')) {
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    $locais = getLocaisFormatados($conn, true, $restricao);
}
if (empty($locais)) {
    $sql = "SELECT id, nome FROM locais WHERE deletado = FALSE";
    if (!empty($unidade_locais_ids)) {
        $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
        $sql .= " AND id IN ($idsStr)";
    }
    $sql .= " ORDER BY nome";
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $locais[$r['id']] = $r['nome'];
}

// Lista de produtos
$produtos_lista = [];
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql = "
        SELECT DISTINCT p.id, p.nome
        FROM produtos p
        WHERE p.deletado = FALSE
        AND (
            EXISTS (SELECT 1 FROM estoques e WHERE e.produto_id = p.id AND e.local_id IN ($idsStr))
            OR EXISTS (SELECT 1 FROM patrimonios pt WHERE pt.produto_id = p.id AND pt.local_id IN ($idsStr))
        )
        ORDER BY p.nome
    ";
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;
} else {
    $res = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
    if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;
}

// ---------- HELPERS EAV ----------
function get_eav_params_insert($valor, $tipo) {
    if ($tipo !== 'booleano' && ($valor === '' || $valor === null)) return null;
    $col = 'valor_texto'; $bind = 's'; $v = $valor;
    
    switch ($tipo) {
        case 'numero': $col = 'valor_numero'; $bind = 'd'; $v = (float)str_replace(',', '.', $valor); break;
        case 'booleano': $col = 'valor_booleano'; $bind = 'i'; $v = ($valor == '1' || $valor == 'on') ? 1 : 0; break;
        case 'data': $col = 'valor_data'; $bind = 's'; break;
        case 'selecao':
        case 'select':
        case 'opcao':
            $col = 'valor_texto'; $bind = 's'; break;
    }
    return ['col' => $col, 'bind' => $bind, 'val' => $v];
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

// ---------- UTILS ----------
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

// ---------- PROCESSAMENTO DO POST ----------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    
    // NOVA LÓGICA: Categoria final é o último select preenchido
    $categoria_id = 0;
    if (!empty($_POST['categoria_final'])) {
        $categoria_id = (int)$_POST['categoria_final'];
    }
    
    $local_id = (int)($_POST['local_id'] ?? 0);
    $quantidade_inicial = isset($_POST['quantidade_inicial']) ? (float)$_POST['quantidade_inicial'] : 0;
    $tipo_posse = $_POST['tipo_posse'] ?? 'proprio';
    $locador_nome = trim($_POST['locador_nome'] ?? '');
    $componentes_ids = $_POST['componente_id'] ?? [];
    $componentes_qtds = $_POST['componente_qtd'] ?? [];
    $componentes_tipo = $_POST['componente_tipo'] ?? [];
    $controla_estoque = 1;

    if (!empty($componentes_ids) && is_array($componentes_ids)) {
        $controla_estoque = 0;
    } else {
        $controla_estoque = isset($_POST['controla_estoque_proprio']) ? (int)$_POST['controla_estoque_proprio'] : 1;
    }

    if (empty($nome) || $categoria_id <= 0) {
        $status_message = "<p style='color:red'>Nome e Categoria são obrigatórios.</p>";
    } elseif ($tipo_posse == 'locado' && empty($locador_nome)) {
        $status_message = "<p style='color:red'>Para produtos locados, o nome do locador é obrigatório.</p>";
    } else {
        if ($usuario_nivel === 'admin_unidade' && $local_id > 0 && !in_array($local_id, $unidade_locais_ids)) {
            $status_message = "<p style='color:red'>Local inválido para sua unidade.</p>";
        } else {
            $conn->begin_transaction();
            try {
                $sql = "INSERT INTO produtos (nome, descricao, categoria_id, controla_estoque_proprio, tipo_posse, locador_nome, data_criado) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssiiss", $nome, $descricao, $categoria_id, $controla_estoque, $tipo_posse, $locador_nome);
                $stmt->execute();
                $produto_id = $stmt->insert_id;
                $stmt->close();

                if (function_exists('registrarLog')) registrarLog($conn, $usuario_id_log, 'produtos', $produto_id, 'CRIACAO', "Produto criado via formulário (composição inline).", $produto_id);

                if ($controla_estoque && $local_id && $quantidade_inicial > 0) {
                    $stmt = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade) VALUES (?, ?, ?)");
                    $stmt->bind_param("iid", $produto_id, $local_id, $quantidade_inicial);
                    $stmt->execute();
                    $stmt->close();
                }

                if (!empty($componentes_ids) && is_array($componentes_ids)) {
                    $stmt_rel = $conn->prepare("INSERT INTO produto_relacionamento (produto_principal_id, subproduto_id, quantidade, tipo_relacao) VALUES (?, ?, ?, ?)");
                    foreach ($componentes_ids as $idx => $sub_id_raw) {
                        $sub_id = (int)$sub_id_raw;
                        if ($sub_id <= 0) continue;
                        $qtd = isset($componentes_qtds[$idx]) ? (float)$componentes_qtds[$idx] : 1;
                        if ($qtd <= 0) continue;
                        $tipo_rel = isset($componentes_tipo[$idx]) ? $componentes_tipo[$idx] : 'componente';
                        $stmt_rel->bind_param("iids", $produto_id, $sub_id, $qtd, $tipo_rel);
                        $stmt_rel->execute();
                    }
                    $stmt_rel->close();
                }

                if (!empty($_POST['atributo_valor']) && is_array($_POST['atributo_valor'])) {
                    foreach ($_POST['atributo_valor'] as $aid => $val) {
                        $attr_id = (int)$aid;
                        $tipo = strtolower($_POST["tipo_attr_$aid"] ?? 'texto');
                        $val_salvar = is_array($val) ? implode(',', $val) : $val;

                        if (in_array($tipo, ['selecao','select','opcao','multi_opcao'])) {
                            if (is_numeric($val_salvar) && (int)$val_salvar > 0) {
                                $op = obterOpcaoPorId($conn, (int)$val_salvar);
                                if ($op) {
                                    $vp_id = mapOpcaoParaValorPermitido($conn, (int)$val_salvar);
                                    $texto = $op['valor'];
                                    if ($vp_id) {
                                        $stmt = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto, valor_permitido_id) VALUES (?, ?, ?, ?)");
                                        $stmt->bind_param("iisi", $produto_id, $attr_id, $texto, $vp_id);
                                        $stmt->execute();
                                        $stmt->close();
                                        continue;
                                    } else {
                                        $stmt = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                                        $stmt->bind_param("iis", $produto_id, $attr_id, $texto);
                                        $stmt->execute();
                                        $stmt->close();
                                        continue;
                                    }
                                }
                            } else {
                                $texto = $val_salvar;
                                $stmt = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                                $stmt->bind_param("iis", $produto_id, $attr_id, $texto);
                                $stmt->execute();
                                $stmt->close();
                                continue;
                            }
                        }

                        $p = get_eav_params_insert($val_salvar, $tipo);
                        if ($p) {
                            $sql_ins = sprintf("INSERT INTO atributos_valor (produto_id, atributo_id, %s) VALUES (?, ?, ?)", $p['col']);
                            $stmt = $conn->prepare($sql_ins);
                            $bind = "ii" . $p['bind'];
                            $stmt->bind_param($bind, $produto_id, $attr_id, $p['val']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }

                if (function_exists('processarUploadArquivo')) {
                    if (!empty($_FILES['arq_imagem']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_imagem'], 'imagem');
                    if (!empty($_FILES['arq_nota']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_nota'], 'nota_fiscal');
                    if (!empty($_FILES['arq_manual']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_manual'], 'manual');
                }

                $conn->commit();
                header("Location: listar.php?sucesso=cadastro");
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $status_message = "<p style='color:red'>Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Cadastrar Produto</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .form-group { margin-bottom: 12px; }
        label { font-weight: bold; display:block; margin-bottom:4px; }
        .linha-comp { display:flex; gap:8px; margin-bottom:6px; align-items:center; }
        .btn-rmv { background:#e74c3c; color:#fff; border:none; padding:6px 8px; cursor:pointer; border-radius:3px; }
        .required-star { color: red; }
        
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
        <h1>Cadastrar Produto</h1>
        <p><a href="listar.php">Voltar</a></p>
        <?php echo $status_message; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Nome <span class="required-star">*</span></label>
                <input type="text" name="nome" required>
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="descricao"></textarea>
            </div>

            <div class="form-group">
                <label>Tipo de Posse <span class="required-star">*</span></label>
                <select name="tipo_posse" id="tipo_posse" required onchange="toggleLocadorField()">
                    <option value="proprio">Próprio</option>
                    <option value="locado">Locado</option>
                </select>
            </div>

            <div class="form-group" id="locador-group" style="display: none;">
                <label>Nome do Locador <span class="required-star">*</span></label>
                <input type="text" name="locador_nome" id="locador_nome">
            </div>

            <!-- NOVA SEÇÃO DE CATEGORIA HIERÁRQUICA -->
            <div class="form-group">
                <label>Categoria <span class="required-star">*</span></label>
                <div class="breadcrumb-display" id="categoria-breadcrumb">
                    Selecione uma categoria abaixo...
                </div>
                <div id="categoria-hierarchy-container">
                    <div class="categoria-level" id="nivel-0">
                        <label>Nível 1:</label>
                        <select class="categoria-select" data-nivel="0" required>
                            <option value="">Selecione a categoria principal...</option>
                            <?php foreach($categorias_raiz as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- Campo hidden que guardará o ID da categoria final selecionada -->
                <input type="hidden" name="categoria_final" id="categoria_final" required>
            </div>

            <h3>Componentes / Composição (opcional)</h3>
            <p>Adicione subprodutos que compõem este produto. Se houver componentes, por padrão o produto será tratado como kit (sem estoque próprio).</p>

            <div id="lista-comps"></div>

            <button type="button" onclick="addComp()">+ Adicionar Componente</button>

            <div style="margin-top:15px;">
                <label>Controla estoque próprio?</label>
                <select name="controla_estoque_proprio">
                    <option value="1">Sim</option>
                    <option value="0">Não (este produto é um kit)</option>
                </select>
            </div>

            <h3>Opções de Estoque Inicial</h3>
            <div class="form-group">
                <label>Local</label>
                <select name="local_id">
                    <option value="">(Nenhum)</option>
                    <?php foreach ($locais as $id => $path): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($path); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Quantidade Inicial</label>
                <input type="number" name="quantidade_inicial" step="any" min="0" value="0">
            </div>

            <h3>Atributos</h3>
            <div id="atributos-dinamicos"><p>Selecione uma categoria para carregar os atributos.</p></div>

            <h3>Arquivos</h3>
            <div class="form-group">
                <label>Imagem</label>
                <input type="file" name="arq_imagem">
            </div>

            <button type="submit" style="margin-top:12px; padding:10px 18px; background:#28a745; color:white; border:none; border-radius:4px;">Cadastrar Produto</button>
        </form>
    </div>

    <script>
        const prods = <?php echo json_encode($produtos_lista); ?>;

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

        document.addEventListener('DOMContentLoaded', function() {
            toggleLocadorField();
        });

        function addComp(pref = {}) {
            const container = document.getElementById('lista-comps');
            const div = document.createElement('div');
            div.className = 'linha-comp';
            const ops = ['<option value="">Selecione...</option>'].concat(prods.map(p => `<option value="${p.id}">${p.nome}</option>`)).join('');
            div.innerHTML = `<select name="componente_id[]" required style="flex:2">${ops}</select>
                             <input type="number" name="componente_qtd[]" value="${pref.qtd || 1}" step="any" min="0.01" style="width:120px">
                             <select name="componente_tipo[]" style="width:140px">
                                <option value="componente">Componente</option>
                                <option value="kit">Kit</option>
                                <option value="acessorio">Acessório</option>
                             </select>
                             <button type="button" class="btn-rmv" onclick="this.parentElement.remove()">X</button>`;
            container.appendChild(div);
        }
    </script>

    <!-- SCRIPT PARA HIERARQUIA DE CATEGORIAS -->
    <script>
        (function() {
            const container = document.getElementById('categoria-hierarchy-container');
            const hiddenInput = document.getElementById('categoria_final');
            const breadcrumbDiv = document.getElementById('categoria-breadcrumb');
            let nivelAtual = 0;
            let categoriasPath = []; // Array de {id, nome} para breadcrumb

            // Listener para os selects de categoria
            container.addEventListener('change', function(e) {
                if (e.target.classList.contains('categoria-select')) {
                    const nivel = parseInt(e.target.dataset.nivel);
                    const categoriaId = e.target.value;
                    
                    // Remove níveis posteriores
                    removerNiveisPosteriores(nivel);
                    
                    if (categoriaId) {
                        const categoriaTexto = e.target.options[e.target.selectedIndex].text;
                        
                        // Atualiza path
                        categoriasPath = categoriasPath.slice(0, nivel);
                        categoriasPath.push({id: categoriaId, nome: categoriaTexto});
                        
                        // Atualiza hidden input
                        hiddenInput.value = categoriaId;
                        
                        // Atualiza breadcrumb
                        atualizarBreadcrumb();
                        
                        // Busca subcategorias
                        buscarSubcategorias(categoriaId, nivel + 1);
                        
                        // Carrega atributos da categoria final
                        if (typeof carregarAtributos === 'function') {
                            carregarAtributos(categoriaId);
                        }
                    } else {
                        hiddenInput.value = '';
                        categoriasPath = categoriasPath.slice(0, nivel);
                        atualizarBreadcrumb();
                        document.getElementById('atributos-dinamicos').innerHTML = '<p>Selecione uma categoria para carregar os atributos.</p>';
                    }
                }
            });

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
                // Busca via AJAX subcategorias da categoria selecionada
                fetch(`../../api/categorias_filhos.php?categoria_id=${categoriaId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.sucesso && data.categorias && data.categorias.length > 0) {
                            adicionarNivelCategoria(data.categorias, proximoNivel);
                        }
                    })
                    .catch(err => console.error('Erro ao buscar subcategorias:', err));
            }

            function adicionarNivelCategoria(categorias, nivel) {
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
</body>
</html>