<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id_log = function_exists('getUsuarioId') ? getUsuarioId() : 0;

// --- 1. DETECÇÃO DE USUÁRIO E UNIDADE ---
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

// Se for admin de unidade, busca os IDs dos locais permitidos (hierarquia)
if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    // Função helper definida em db.php
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// --- 2. CARREGAMENTOS BÁSICOS ---

// Categorias Raiz (para o seletor hierárquico)
$categorias_raiz = [];
$res = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
if ($res) while ($r = $res->fetch_assoc()) $categorias_raiz[] = $r;

// Locais (Dropdown)
$locais = [];
if (function_exists('getLocaisFormatados')) {
    // Se for admin de unidade, passa o ID da unidade como restrição
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    // O segundo parâmetro 'true' indica que queremos apenas locais finais (salas), se preferir todos, mude para false
    // O terceiro parâmetro aplica o filtro da unidade
    $locais = getLocaisFormatados($conn, true, $restricao);
} else {
    // Fallback caso a função não exista (embora deva existir no seu db.php)
    $sql = "SELECT id, nome FROM locais WHERE deletado = FALSE";
    if (!empty($unidade_locais_ids)) {
        $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
        $sql .= " AND id IN ($idsStr)";
    }
    $sql .= " ORDER BY nome";
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $locais[$r['id']] = $r['nome'];
}

// Lista de produtos (para seleção de componentes/kits)
$produtos_lista = [];
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    // Admin de unidade só vê produtos que existem na sua unidade (para compor kits)
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
    // Admin geral vê tudo
    $res = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
    if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;
}

// --- 3. FUNÇÕES AUXILIARES EAV ---
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

// --- 4. PROCESSAMENTO DO FORMULÁRIO (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    
    // Categoria final vem do campo hidden preenchido pelo JS
    $categoria_id = 0;
    if (!empty($_POST['categoria_final'])) {
        $categoria_id = (int)$_POST['categoria_final'];
    }
    
    $local_id = (int)($_POST['local_id'] ?? 0);
    $tipo_posse = $_POST['tipo_posse'] ?? 'proprio';
    $locador_nome = trim($_POST['locador_nome'] ?? '');
    $locacao_contrato = trim($_POST['locacao_contrato'] ?? ''); // Novo campo
    
    // Componentes do Kit
    $componentes_ids = $_POST['componente_id'] ?? [];
    $componentes_qtds = $_POST['componente_qtd'] ?? [];
    $componentes_tipo = $_POST['componente_tipo'] ?? [];
    
    // Lógica: Se tem componentes, não controla estoque próprio (é virtual/kit), caso contrário controla
    $controla_estoque = 1;
    if (!empty($componentes_ids) && is_array($componentes_ids)) {
        $controla_estoque = 0;
    } else {
        $controla_estoque = isset($_POST['controla_estoque_proprio']) ? (int)$_POST['controla_estoque_proprio'] : 1;
    }

    // VALIDAÇÕES
    if (empty($nome) || $categoria_id <= 0) {
        $status_message = "<div class='alert error'>Nome e Categoria são obrigatórios.</div>";
    } elseif ($tipo_posse == 'locado' && (empty($locador_nome) || empty($locacao_contrato))) {
        $status_message = "<div class='alert error'>Para produtos locados, o nome do locador e o número do contrato são obrigatórios.</div>";
    } else {
        // Validação de Segurança: Admin de Unidade tentando salvar em local proibido
        if ($usuario_nivel === 'admin_unidade' && $local_id > 0 && !in_array($local_id, $unidade_locais_ids)) {
            $status_message = "<div class='alert error'>Você não tem permissão para cadastrar itens neste local (fora da sua unidade).</div>";
        } else {
            // INÍCIO DA TRANSAÇÃO
            $conn->begin_transaction();
            try {
                // 1. Inserir Produto (Dados básicos) - Atualizado com locacao_contrato
                $sql = "INSERT INTO produtos (nome, descricao, categoria_id, controla_estoque_proprio, tipo_posse, locador_nome, locacao_contrato, data_criado) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                // Tipos: s (nome), s (desc), i (cat_id), i (ctrl_est), s (tipo_posse), s (locador), s (contrato)
                $stmt->bind_param("ssiisss", $nome, $descricao, $categoria_id, $controla_estoque, $tipo_posse, $locador_nome, $locacao_contrato);
                $stmt->execute();
                $produto_id = $stmt->insert_id;
                $stmt->close();

                // 2. Gerar Número de Patrimônio (Function SQL)
                $unidade_para_patrimonio = ($usuario_unidade > 0) ? $usuario_unidade : 0;
                
                $stmt_patrimonio = $conn->prepare("SELECT gerar_numero_patrimonio(?, ?, ?) AS numero");
                $stmt_patrimonio->bind_param("iii", $unidade_para_patrimonio, $categoria_id, $produto_id);
                $stmt_patrimonio->execute();
                $result_patrimonio = $stmt_patrimonio->get_result();
                $row_patrimonio = $result_patrimonio->fetch_assoc();
                $numero_patrimonio = $row_patrimonio['numero'];
                $stmt_patrimonio->close();

                // 3. Atualizar Produto com o Nº Patrimônio
                $stmt_update = $conn->prepare("UPDATE produtos SET numero_patrimonio = ? WHERE id = ?");
                $stmt_update->bind_param("si", $numero_patrimonio, $produto_id);
                $stmt_update->execute();
                $stmt_update->close();

                // 4. Criar Estoque Inicial (Quantidade 1) se houver local definido
                if ($controla_estoque && $local_id) {
                    $quantidade_inicial = 1; 
                    $stmt = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade) VALUES (?, ?, ?)");
                    $stmt->bind_param("iid", $produto_id, $local_id, $quantidade_inicial);
                    $stmt->execute();
                    $stmt->close();
                    
                    if (function_exists('registrarLog')) {
                        registrarLog($conn, $usuario_id_log, 'produtos', $produto_id, 'CRIACAO', "Novo item: $numero_patrimonio", $produto_id);
                    }
                }

                // 5. Inserir Componentes (se for Kit)
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

                // 6. Inserir Atributos (EAV)
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

                // 7. Upload de Arquivos
                if (function_exists('processarUploadArquivo')) {
                    if (!empty($_FILES['arq_imagem']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_imagem'], 'imagem');
                    if (!empty($_FILES['arq_nota']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_nota'], 'nota_fiscal');
                    if (!empty($_FILES['arq_manual']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_manual'], 'manual');
                    if (!empty($_FILES['arq_outro']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_outro'], 'outro');
                }

                $conn->commit();
                
                if (isset($_GET['modal'])) {
                    echo "<script>
                        alert('Operação realizada com sucesso!');
                        parent.fecharModalDoFilho(true);
                    </script>";
                    exit;
                } else {
                    header("Location: index.php?sucesso=1");
                    exit;
                }

            } catch (Exception $e) {
                $conn->rollback();
                $status_message = "<div class='alert error'>Erro ao cadastrar: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Cadastrar Produto / Patrimônio</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .form-group { margin-bottom: 12px; }
        label { font-weight: bold; display:block; margin-bottom:4px; }
        input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 8px; box-sizing: border-box; border:1px solid #ccc; border-radius:4px; }
        .linha-comp { display:flex; gap:8px; margin-bottom:6px; align-items:center; }
        .btn-rmv { background:#e74c3c; color:#fff; border:none; padding:6px 8px; cursor:pointer; border-radius:3px; }
        .required-star { color: red; }
        
        /* Estilos alertas */
        .alert { padding:15px; margin-bottom:20px; border-radius:4px; }
        .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        
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
        /* Estilo para destacar os campos de locação */
        #locacao-container {
            background: #fff3cd;
            padding: 15px;
            border: 1px solid #ffeeba;
            border-radius: 4px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Cadastrar Novo Item</h1>
        <?php echo $status_message; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Nome do Item <span class="required-star">*</span></label>
                <input type="text" name="nome" required placeholder="Ex: Notebook Dell Latitude">
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="descricao" placeholder="Detalhes adicionais..."></textarea>
            </div>

            <div class="form-group">
                <label>Tipo de Posse <span class="required-star">*</span></label>
                <select name="tipo_posse" id="tipo_posse" required onchange="toggleLocadorField()">
                    <option value="proprio">Próprio (Patrimônio da Empresa)</option>
                    <option value="locado">Locado (Terceiros)</option>
                </select>
            </div>

            <div id="locacao-container" style="display: none;">
                <div class="form-group">
                    <label>Nome do Locador / Empresa <span class="required-star">*</span></label>
                    <input type="text" name="locador_nome" id="locador_nome">
                </div>
                <div class="form-group">
                    <label>Número do Contrato de Locação <span class="required-star">*</span></label>
                    <input type="text" name="locacao_contrato" id="locacao_contrato" placeholder="Ex: CTR-2023-001">
                </div>
            </div>

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
                <input type="hidden" name="categoria_final" id="categoria_final" required>
            </div>

            <h3>Componentes / Composição (opcional)</h3>
            <p style="font-size:0.9em; color:#666;">Adicione subprodutos se este item for um KIT.</p>

            <div id="lista-comps"></div>

            <button type="button" onclick="addComp()" style="background:#3498db; color:white; border:none; padding:6px 12px; border-radius:4px; margin-bottom:15px;">+ Adicionar Componente</button>

            <div style="margin-top:5px; margin-bottom:15px;">
                <input type="hidden" name="controla_estoque_proprio" value="1">
            </div>

            <h3>Localização Inicial</h3>
            <div class="form-group">
                <label>Local de Armazenamento</label>
                <select name="local_id">
                    <option value="">(Nenhum - Apenas cadastro)</option>
                    <?php foreach ($locais as $id => $path): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($path); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($usuario_nivel === 'admin_unidade'): ?>
                    <small style="color:blue;">* Exibindo apenas locais da sua unidade.</small>
                <?php endif; ?>
            </div>

            <h3>Atributos Específicos</h3>
            <div id="atributos-dinamicos" style="background:#fcfcfc; padding:10px; border:1px dashed #ccc;">
                <p style="color:#777;">Selecione uma categoria acima para carregar os atributos técnicos.</p>
            </div>

            <h3>Documentação e Imagens</h3>
            <div class="form-group">
                <label>Imagem do Produto</label>
                <input type="file" name="arq_imagem" accept="image/*">
            </div>

            <div class="form-group">
                <label>Nota Fiscal</label>
                <input type="file" name="arq_nota" accept=".pdf,.jpg,.jpeg,.png">
            </div>

            <div class="form-group">
                <label>Manual</label>
                <input type="file" name="arq_manual" accept=".pdf,.doc,.docx">
            </div>

            <div class="form-group">
                <label>Outros Documentos</label>
                <input type="file" name="arq_outro" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
            </div>

            <button type="submit" style="margin-top:12px; padding:12px 24px; background:#28a745; color:white; border:none; border-radius:4px; font-size:16px; cursor:pointer;">Concluir Cadastro</button>
        </form>
    </div>

    <script>
        const prods = <?php echo json_encode($produtos_lista); ?>;

        // Toggle Locador e Contrato
        function toggleLocadorField() {
            const tipoPosse = document.getElementById('tipo_posse').value;
            const containerLocacao = document.getElementById('locacao-container');
            const locadorInput = document.getElementById('locador_nome');
            const contratoInput = document.getElementById('locacao_contrato');
            
            if (tipoPosse === 'locado') {
                containerLocacao.style.display = 'block';
                locadorInput.required = true;
                contratoInput.required = true;
            } else {
                containerLocacao.style.display = 'none';
                locadorInput.required = false;
                contratoInput.required = false;
                locadorInput.value = '';
                contratoInput.value = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleLocadorField();
        });

        function addComp(pref = {}) {
            const container = document.getElementById('lista-comps');
            const div = document.createElement('div');
            div.className = 'linha-comp';
            
            let ops = '<option value="">Selecione...</option>';
            prods.forEach(p => {
                ops += `<option value="${p.id}">${p.nome}</option>`;
            });

            div.innerHTML = `
                <select name="componente_id[]" required style="flex:2; padding:6px;">${ops}</select>
                <input type="number" name="componente_qtd[]" value="${pref.qtd || 1}" step="any" min="0.01" style="width:100px; padding:6px;" placeholder="Qtd">
                <select name="componente_tipo[]" style="width:120px; padding:6px;">
                    <option value="componente">Componente</option>
                    <option value="kit">Sub-Kit</option>
                    <option value="acessorio">Acessório</option>
                </select>
                <button type="button" class="btn-rmv" onclick="this.parentElement.remove()">X</button>
            `;
            container.appendChild(div);
        }
    </script>

    <script>
        (function() {
            const container = document.getElementById('categoria-hierarchy-container');
            const hiddenInput = document.getElementById('categoria_final');
            const breadcrumbDiv = document.getElementById('categoria-breadcrumb');
            let nivelAtual = 0;
            let categoriasPath = []; 

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
                        document.getElementById('atributos-dinamicos').innerHTML = '<p style="color:#777;">Selecione uma categoria para carregar os atributos.</p>';
                    }
                }
            });

            function removerNiveisPosteriores(nivel) {
                const niveis = container.querySelectorAll('.categoria-level');
                niveis.forEach((nivelDiv, idx) => {
                    const idParts = nivelDiv.id.split('-');
                    const divNivel = parseInt(idParts[1]);
                    if (divNivel > nivel) {
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
                optionDefault.textContent = '(Selecione uma subcategoria)';
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