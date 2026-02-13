<?php
require_once '../../config/_protecao.php';
exigirAdmin(); //Por enquanto somente admins vão cadastrar produtos

$status_message = "";
$usuario_id_log = function_exists('getUsuarioId') ? getUsuarioId() : 0;

// --- 1. DETECÇÃO DE USUÁRIO E UNIDADE ---
$usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
$usuario_unidade = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : 0;
$unidade_locais_ids = [];

if ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) {
    $unidade_locais_ids = getIdsLocaisDaUnidade($conn, $usuario_unidade);
}

// --- 2. CARREGAMENTOS BÁSICOS ---
$categorias_raiz = [];
$res = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE AND categoria_pai_id IS NULL ORDER BY nome");
if ($res) while ($r = $res->fetch_assoc()) $categorias_raiz[] = $r;

// Locais
$locais = [];
if (function_exists('getLocaisFormatados')) {
    $restricao = ($usuario_nivel === 'admin_unidade' && $usuario_unidade > 0) ? $usuario_unidade : null;
    $locais = getLocaisFormatados($conn, true, $restricao);
} else {
    $sql = "SELECT id, nome FROM locais WHERE deletado = FALSE";
    if (!empty($unidade_locais_ids)) {
        $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
        $sql .= " AND id IN ($idsStr)";
    }
    $sql .= " ORDER BY nome";
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $locais[$r['id']] = $r['nome'];
}

// Produtos para Kit
$produtos_lista = [];
if ($usuario_nivel === 'admin_unidade' && !empty($unidade_locais_ids)) {
    $idsStr = implode(',', array_map('intval', $unidade_locais_ids));
    $sql = "SELECT DISTINCT p.id, p.nome FROM produtos p WHERE p.deletado = FALSE AND (EXISTS (SELECT 1 FROM estoques e WHERE e.produto_id = p.id AND e.local_id IN ($idsStr)) OR EXISTS (SELECT 1 FROM patrimonios pt WHERE pt.produto_id = p.id AND pt.local_id IN ($idsStr))) ORDER BY p.nome";
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;
} else {
    $res = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
    if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;
}

// Buscar Locadores Ativos
$locadores = [];
$res_loc = $conn->query("SELECT id, nome, razao_social FROM locadores WHERE ativo = 1 ORDER BY nome");
if ($res_loc) while ($r = $res_loc->fetch_assoc()) $locadores[] = $r;

// --- FUNÇÕES AUXILIARES ---
function get_eav_params_insert($valor, $tipo) {
    if ($tipo !== 'booleano' && ($valor === '' || $valor === null)) return null;
    $col = 'valor_texto'; $bind = 's'; $v = $valor;
    switch ($tipo) {
        case 'numero': $col = 'valor_numero'; $bind = 'd'; $v = (float)str_replace(',', '.', $valor); break;
        case 'booleano': $col = 'valor_booleano'; $bind = 'i'; $v = ($valor == '1' || $valor == 'on') ? 1 : 0; break;
        case 'data': $col = 'valor_data'; $bind = 's'; break;
        case 'selecao': case 'select': case 'opcao': $col = 'valor_texto'; $bind = 's'; break;
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
    
    $categoria_id = !empty($_POST['categoria_final']) ? (int)$_POST['categoria_final'] : 0;
    
    $local_id = (int)($_POST['local_id'] ?? 0);
    $tipo_posse = $_POST['tipo_posse'] ?? 'proprio';
    
    // Campos de locação com integração
    $locador_id = null;
    $contrato_id = null;
    if ($tipo_posse == 'locado') {
        $locador_id = !empty($_POST['locador_id']) ? (int)$_POST['locador_id'] : null;
        $contrato_id = !empty($_POST['contrato_id']) ? (int)$_POST['contrato_id'] : null;
    }
    
    // Tratamento do Patrimônio Manual
    $tem_patrimonio = isset($_POST['tem_patrimonio']);
    $numero_patrimonio = null;
    if ($tem_patrimonio) {
        $numero_patrimonio = trim($_POST['numero_patrimonio'] ?? '');
    }

    // Componentes do Kit
    $componentes_ids = $_POST['componente_id'] ?? [];
    $componentes_qtds = $_POST['componente_qtd'] ?? [];
    $componentes_tipo = $_POST['componente_tipo'] ?? [];
    
    $controla_estoque = 1;
    if (!empty($componentes_ids) && is_array($componentes_ids)) {
        $controla_estoque = 0;
    } else {
        $controla_estoque = isset($_POST['controla_estoque_proprio']) ? (int)$_POST['controla_estoque_proprio'] : 1;
    }

    // VALIDAÇÕES
    if (empty($nome) || $categoria_id <= 0) {
        $status_message = "<div class='alert error'>Nome e Categoria são obrigatórios.</div>";
    } elseif ($tipo_posse == 'locado' && (!$locador_id || !$contrato_id)) {
        $status_message = "<div class='alert error'>Para produtos locados, selecione o locador e o contrato.</div>";
    } elseif ($tem_patrimonio && empty($numero_patrimonio)) {
        $status_message = "<div class='alert error'>Se o item possui patrimônio, o número é obrigatório.</div>";
    } else {
        // Validação de Segurança
        if ($usuario_nivel === 'admin_unidade' && $local_id > 0 && !in_array($local_id, $unidade_locais_ids)) {
            $status_message = "<div class='alert error'>Você não tem permissão para cadastrar itens neste local.</div>";
        } else {
            // Verificar Duplicidade de Patrimônio
            $duplicado = false;
            if ($numero_patrimonio) {
                $stmt_dup = $conn->prepare("SELECT id FROM produtos WHERE numero_patrimonio = ? LIMIT 1");
                $stmt_dup->bind_param("s", $numero_patrimonio);
                $stmt_dup->execute();
                if ($stmt_dup->get_result()->num_rows > 0) $duplicado = true;
                $stmt_dup->close();
            }

            if ($duplicado) {
                $status_message = "<div class='alert error'>O número de patrimônio <b>$numero_patrimonio</b> já está cadastrado no sistema.</div>";
            } else {
                // INÍCIO DA TRANSAÇÃO
                $conn->begin_transaction();
                try {
                    // 1. Inserir Produto
                    $sql = "INSERT INTO produtos (nome, descricao, categoria_id, controla_estoque_proprio, tipo_posse, locador_id, contrato_locacao_id, numero_patrimonio, data_criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    
                    // Tipos: s(nome), s(desc), i(cat), i(ctrl), s(tipo), i(locador_id), i(contrato_id), s(patrimonio)
                    $stmt->bind_param("ssiisiis", $nome, $descricao, $categoria_id, $controla_estoque, $tipo_posse, $locador_id, $contrato_id, $numero_patrimonio);
                    
                    $stmt->execute();
                    $produto_id = $stmt->insert_id;
                    $stmt->close();

                    // 2. Criar Estoque Inicial
                    if ($controla_estoque && $local_id) {
                        $stmt = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade) VALUES (?, ?, 1)");
                        $stmt->bind_param("ii", $produto_id, $local_id);
                        $stmt->execute();
                        $stmt->close();
                        
                        if (function_exists('registrarLog')) {
                            $logMsg = $numero_patrimonio ? "Novo item: $numero_patrimonio" : "Novo item sem patrimônio";
                            registrarLog($conn, $usuario_id_log, 'produtos', $produto_id, 'CRIACAO', $logMsg, $produto_id);
                        }
                    }

                    // 3. Componentes
                    if (!empty($componentes_ids) && is_array($componentes_ids)) {
                        $stmt_rel = $conn->prepare("INSERT INTO produto_relacionamento (produto_principal_id, subproduto_id, quantidade, tipo_relacao) VALUES (?, ?, ?, ?)");
                        foreach ($componentes_ids as $idx => $sub_id_raw) {
                            $sub_id = (int)$sub_id_raw;
                            if ($sub_id <= 0) continue;
                            $qtd = isset($componentes_qtds[$idx]) ? (float)$componentes_qtds[$idx] : 1;
                            $tipo_rel = isset($componentes_tipo[$idx]) ? $componentes_tipo[$idx] : 'componente';
                            $stmt_rel->bind_param("iids", $produto_id, $sub_id, $qtd, $tipo_rel);
                            $stmt_rel->execute();
                        }
                        $stmt_rel->close();
                    }

                    // 4. Atributos
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
                                        $stmt = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto, valor_permitido_id) VALUES (?, ?, ?, ?)");
                                        $stmt->bind_param("iisi", $produto_id, $attr_id, $texto, $vp_id);
                                        $stmt->execute();
                                        $stmt->close();
                                        continue;
                                    }
                                }
                                // Fallback texto
                                $stmt = $conn->prepare("INSERT INTO atributos_valor (produto_id, atributo_id, valor_texto) VALUES (?, ?, ?)");
                                $stmt->bind_param("iis", $produto_id, $attr_id, $val_salvar);
                                $stmt->execute();
                                $stmt->close();
                                continue;
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

                    // 5. Uploads
                    if (function_exists('processarUploadArquivo')) {
                        if (!empty($_FILES['arq_imagem']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_imagem'], 'imagem');
                        if (!empty($_FILES['arq_nota']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_nota'], 'nota_fiscal');
                        if (!empty($_FILES['arq_manual']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_manual'], 'manual');
                        if (!empty($_FILES['arq_outro']['name'])) processarUploadArquivo($conn, $produto_id, $_FILES['arq_outro'], 'outro');
                    }

                    $conn->commit();
                    
                    if (isset($_GET['modal'])) {
                        echo "<script>alert('Sucesso!'); parent.fecharModalDoFilho(true);</script>";
                        exit;
                    } else {
                        header("Location: index.php?sucesso=cadastro&patrimonio=" . urlencode($numero_patrimonio));
                        exit;
                    }

                } catch (Exception $e) {
                    $conn->rollback();
                    $status_message = "<div class='alert error'>Erro ao cadastrar: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
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
        .alert { padding:15px; margin-bottom:20px; border-radius:4px; }
        .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        
        #categoria-hierarchy-container { display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
        .categoria-level { display: flex; align-items: center; gap: 10px; }
        .categoria-level label { min-width: 100px; margin: 0; }
        .categoria-level select { flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .breadcrumb-display { font-size: 0.9em; color: #666; padding: 8px; background: #fff; border: 1px solid #e0e0e0; border-radius: 4px; min-height: 20px; }
        
        /* Containers opcionais */
        #locacao-container, #patrimonio-container { background: #fff3cd; padding: 15px; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 15px; }
        #patrimonio-container { background: #e3f2fd; border-color: #bbdefb; }
        
        .info-badge { background: #e3f2fd; color: #1976d2; padding: 8px 12px; border-radius: 4px; font-size: 0.9em; margin-top: 5px; display: inline-block; }
        .loading-indicator { color: #666; font-style: italic; padding: 5px; }
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
                <label style="display:inline; cursor:pointer; font-size:1.1em;">
                    <input type="checkbox" id="tem_patrimonio" name="tem_patrimonio" onchange="togglePatrimonioField()"> 
                    <strong>Possui etiqueta/número de patrimônio?</strong>
                </label>
            </div>

            <div id="patrimonio-container" style="display: none;">
                <div class="form-group">
                    <label>Número do Patrimônio <span class="required-star">*</span></label>
                    <input type="text" name="numero_patrimonio" id="numero_patrimonio" placeholder="Digite o código da etiqueta">
                </div>
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
                    <label>Locador / Empresa <span class="required-star">*</span></label>
                    <select name="locador_id" id="locador_id" onchange="carregarContratos()">
                        <option value="">Selecione o locador...</option>
                        <?php foreach ($locadores as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>">
                                <?php echo htmlspecialchars($loc['nome']); ?>
                                <?php if ($loc['razao_social']): ?>
                                    - <?php echo htmlspecialchars($loc['razao_social']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #666;">
                        <a href="../admin/locadores.php" target="_blank" style="color: #1976d2;">+ Cadastrar novo locador</a>
                    </small>
                </div>
                
                <div class="form-group">
                    <label>Contrato de Locação <span class="required-star">*</span></label>
                    <select name="contrato_id" id="contrato_id" disabled>
                        <option value="">Primeiro selecione um locador...</option>
                    </select>
                    <div id="contrato-info" style="margin-top: 8px;"></div>
                    <small style="color: #666;">
                        <a href="../admin/contratos.php" target="_blank" style="color: #1976d2;">+ Cadastrar novo contrato</a>
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label>Categoria <span class="required-star">*</span></label>
                <div class="breadcrumb-display" id="categoria-breadcrumb">Selecione uma categoria abaixo...</div>
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
            <div id="lista-comps"></div>
            <button type="button" onclick="addComp()" style="background:#3498db; color:white; border:none; padding:6px 12px; border-radius:4px; margin-bottom:15px;">+ Adicionar Componente</button>
            <input type="hidden" name="controla_estoque_proprio" value="1">

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

        function toggleLocadorField() {
            const tipoPosse = document.getElementById('tipo_posse').value;
            const container = document.getElementById('locacao-container');
            const locadorSelect = document.getElementById('locador_id');
            const contratoSelect = document.getElementById('contrato_id');
            
            if (tipoPosse === 'locado') {
                container.style.display = 'block';
                locadorSelect.required = true;
                contratoSelect.required = true;
            } else {
                container.style.display = 'none';
                locadorSelect.required = false;
                contratoSelect.required = false;
                locadorSelect.value = '';
                contratoSelect.value = '';
                contratoSelect.disabled = true;
                contratoSelect.innerHTML = '<option value="">Primeiro selecione um locador...</option>';
                document.getElementById('contrato-info').innerHTML = '';
            }
        }

        function togglePatrimonioField() {
            const isChecked = document.getElementById('tem_patrimonio').checked;
            const container = document.getElementById('patrimonio-container');
            const input = document.getElementById('numero_patrimonio');
            
            if (isChecked) {
                container.style.display = 'block';
                input.required = true;
            } else {
                container.style.display = 'none';
                input.required = false;
                input.value = '';
            }
        }

        async function carregarContratos() {
            const locadorId = document.getElementById('locador_id').value;
            const contratoSelect = document.getElementById('contrato_id');
            const contratoInfo = document.getElementById('contrato-info');
            
            // Resetar
            contratoSelect.innerHTML = '<option value="">Carregando contratos...</option>';
            contratoSelect.disabled = true;
            contratoInfo.innerHTML = '';
            
            if (!locadorId) {
                contratoSelect.innerHTML = '<option value="">Primeiro selecione um locador...</option>';
                return;
            }
            
            try {
                const response = await fetch(`../../api/contratos.php?locador_id=${locadorId}`);
                const data = await response.json();
                
                if (data.sucesso && data.contratos && data.contratos.length > 0) {
                    contratoSelect.innerHTML = '<option value="">Selecione o contrato...</option>';
                    
                    data.contratos.forEach(contrato => {
                        const option = document.createElement('option');
                        option.value = contrato.id;
                        
                        let texto = contrato.numero_contrato;
                        if (contrato.descricao) {
                            texto += ' - ' + contrato.descricao.substring(0, 50);
                            if (contrato.descricao.length > 50) texto += '...';
                        }
                        
                        option.textContent = texto;
                        option.dataset.dataInicio = contrato.data_inicio;
                        option.dataset.dataFim = contrato.data_fim;
                        option.dataset.valorMensal = contrato.valor_mensal;
                        
                        contratoSelect.appendChild(option);
                    });
                    
                    contratoSelect.disabled = false;
                    
                    // Adicionar evento para mostrar informações do contrato
                    contratoSelect.addEventListener('change', function() {
                        const selected = this.options[this.selectedIndex];
                        if (selected.value) {
                            let info = '<div class="info-badge">';
                            info += '<strong>📄 Vigência:</strong> ' + formatarData(selected.dataset.dataInicio);
                            if (selected.dataset.dataFim) {
                                info += ' até ' + formatarData(selected.dataset.dataFim);
                            } else {
                                info += ' (indeterminado)';
                            }
                            if (selected.dataset.valorMensal && selected.dataset.valorMensal !== 'null') {
                                info += ' | <strong>💰 Valor:</strong> R$ ' + parseFloat(selected.dataset.valorMensal).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                            }
                            info += '</div>';
                            contratoInfo.innerHTML = info;
                        } else {
                            contratoInfo.innerHTML = '';
                        }
                    });
                    
                } else {
                    contratoSelect.innerHTML = '<option value="">Nenhum contrato ativo encontrado</option>';
                    contratoInfo.innerHTML = '<small style="color: #f56565;">⚠️ Este locador não possui contratos ativos. <a href="../admin/contratos.php" target="_blank" style="color: #1976d2;">Cadastre um contrato</a></small>';
                }
            } catch (error) {
                console.error('Erro ao carregar contratos:', error);
                contratoSelect.innerHTML = '<option value="">Erro ao carregar contratos</option>';
                contratoInfo.innerHTML = '<small style="color: #f56565;">⚠️ Erro ao buscar contratos. Tente novamente.</small>';
            }
        }
        
        function formatarData(dataStr) {
            if (!dataStr) return '';
            const partes = dataStr.split('-');
            if (partes.length === 3) {
                return `${partes[2]}/${partes[1]}/${partes[0]}`;
            }
            return dataStr;
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleLocadorField();
            togglePatrimonioField();
        });

        function addComp(pref = {}) {
            const container = document.getElementById('lista-comps');
            const div = document.createElement('div');
            div.className = 'linha-comp';
            let ops = '<option value="">Selecione...</option>';
            prods.forEach(p => { ops += `<option value="${p.id}">${p.nome}</option>`; });
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
                    const niveis = container.querySelectorAll('.categoria-level');
                    niveis.forEach((div, idx) => {
                        const idParts = div.id.split('-');
                        if (parseInt(idParts[1]) > nivel) div.remove();
                    });
                    
                    if (categoriaId) {
                        const categoriaTexto = e.target.options[e.target.selectedIndex].text;
                        categoriasPath = categoriasPath.slice(0, nivel);
                        categoriasPath.push({id: categoriaId, nome: categoriaTexto});
                        hiddenInput.value = categoriaId;
                        
                        // Atualiza Breadcrumb
                        const nomes = categoriasPath.map(c => c.nome);
                        breadcrumbDiv.innerHTML = '<strong>Categoria selecionada:</strong> ' + nomes.join(' → ');
                        
                        // Busca Filhos
                        fetch(`../../api/categorias_filhos.php?categoria_id=${categoriaId}`)
                            .then(r => r.json())
                            .then(data => {
                                if (data.sucesso && data.categorias && data.categorias.length > 0) {
                                    const nextLevel = nivel + 1;
                                    const div = document.createElement('div');
                                    div.className = 'categoria-level';
                                    div.id = `nivel-${nextLevel}`;
                                    div.innerHTML = `<label>Nível ${nextLevel+1}:</label><select class="categoria-select" data-nivel="${nextLevel}"><option value="">(Selecione uma subcategoria)</option></select>`;
                                    const sel = div.querySelector('select');
                                    data.categorias.forEach(c => {
                                        const op = document.createElement('option');
                                        op.value = c.id; op.text = c.nome; sel.add(op);
                                    });
                                    container.appendChild(div);
                                }
                            });
                        
                        if (typeof carregarAtributos === 'function') carregarAtributos(categoriaId);
                    } else {
                        hiddenInput.value = '';
                        categoriasPath = categoriasPath.slice(0, nivel);
                        breadcrumbDiv.innerHTML = 'Selecione uma categoria abaixo...';
                        document.getElementById('atributos-dinamicos').innerHTML = '<p style="color:#777;">Selecione uma categoria...</p>';
                    }
                }
            });
        })();
    </script>
    <script src="../../assets/js/scripts.js"></script>
</body>
</html>