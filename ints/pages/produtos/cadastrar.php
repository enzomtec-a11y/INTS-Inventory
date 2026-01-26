<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id_log = function_exists('getUsuarioId') ? getUsuarioId() : 0; 

// Carregamentos básicos
$categorias = [];
$res = $conn->query("SELECT id, nome FROM categorias WHERE deletado = FALSE ORDER BY nome");
if ($res) while ($r = $res->fetch_assoc()) $categorias[] = $r;

// Use a função getLocaisFormatados para obter breadcrumb (Unidade > Andar > Sala).
// Passamos true para retornar apenas salas (apenasSalas = true).
$locais = [];
if (function_exists('getLocaisFormatados')) {
    $locais = getLocaisFormatados($conn, true); // retorna array id => 'Raiz > ... > Sala'
}
// Fallback caso a função não exista ou retorne vazio
if (empty($locais)) {
    $res = $conn->query("SELECT id, nome FROM locais WHERE deletado = FALSE ORDER BY nome");
    if ($res) while ($r = $res->fetch_assoc()) $locais[$r['id']] = $r['nome'];
}

$produtos_lista = [];
$res = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
if ($res) while ($r = $res->fetch_assoc()) $produtos_lista[] = $r;

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

// ---------- PROCESSAMENTO DO POST ----------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria_id = (int)($_POST['categoria_id'] ?? 0);
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

            <div class="form-group">
                <label>Categoria <span class="required-star">*</span></label>
                <select name="categoria_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <h3>Componentes / Composição (opcional)</h3>
            <p>Adicione subprodutos que compõem este produto. Se houver componentes, por padrão o produto será tratado como kit (sem estoque próprio).</p>

            <div id="lista-comps">
                <!-- Linhas serão adicionadas por JS -->
            </div>

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
            <div id="atributos-dinamicos"><p>Selecione uma categoria primeiro.</p></div>

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

        // Inicializar ao carregar a página
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

    <script src="../../assets/js/scripts.js"></script>
    <script>
        const catSel = document.querySelector('select[name="categoria_id"]');
        if (catSel) {
            catSel.addEventListener('change', function() {
                if (this.value) carregarAtributos(this.value);
            });
        }
    </script>
</body>
</html>