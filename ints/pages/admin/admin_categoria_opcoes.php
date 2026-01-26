<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Lógica de Salvamento (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'salvar_vinculos') {
    $categoria_id = (int)$_POST['categoria_id'];
    $atributo_id = (int)$_POST['atributo_id'];
    // Recebe um array de IDs de opções que foram marcadas (ou um array vazio se nada foi marcado)
    $opcoes_selecionadas_ids = $_POST['opcoes'] ?? [];

    if (empty($categoria_id) || empty($atributo_id)) {
        $status_message = "<p style='color: red;'>Erro: Categoria e Atributo devem ser selecionados.</p>";
    } else {
        $conn->begin_transaction();
        try {
            // LIMPAR VÍNCULOS ANTIGOS
            // Deleta todos os vínculos existentes para esta combinação de categoria e atributo.
            $sql_find_opcoes = "SELECT id FROM atributos_opcoes WHERE atributo_id = ?";
            $stmt_find = $conn->prepare($sql_find_opcoes);
            $stmt_find->bind_param("i", $atributo_id);
            $stmt_find->execute();
            $result_find = $stmt_find->get_result();
            $opcoes_mestre_ids = [];
            while ($row = $result_find->fetch_assoc()) {
                $opcoes_mestre_ids[] = $row['id'];
            }
            $stmt_find->close();

            if (!empty($opcoes_mestre_ids)) {
                $in_clause = implode(',', $opcoes_mestre_ids);
                $sql_delete = "
                    DELETE FROM categoria_atributo_opcao 
                    WHERE categoria_id = ? 
                    AND atributo_opcao_id IN ({$in_clause})
                ";
                $stmt_delete = $conn->prepare($sql_delete);
                $stmt_delete->bind_param("i", $categoria_id);
                $stmt_delete->execute();
                $stmt_delete->close();
            }

            // INSERIR NOVOS VÍNCULOS
            // Se o usuário selecionou alguma opção, insere-a.
            if (!empty($opcoes_selecionadas_ids)) {
                $sql_insert = "INSERT INTO categoria_atributo_opcao (categoria_id, atributo_opcao_id) VALUES (?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                
                foreach ($opcoes_selecionadas_ids as $opcao_id) {
                    $opcao_id_int = (int)$opcao_id;
                    $stmt_insert->bind_param("ii", $categoria_id, $opcao_id_int);
                    $stmt_insert->execute();
                }
                $stmt_insert->close();
            }

            $conn->commit();
            $status_message = "<p style='color: green;'>Vínculos de opções atualizados com sucesso!</p>";

        } catch (Exception $e) {
            $conn->rollback();
            $status_message = "<p style='color: red;'>Erro ao salvar vínculos: " . $e->getMessage() . "</p>";
        }
    }
}

// Lógica de Listagem (GET)

// Buscar apenas as categorias para o <select> inicial
$categorias = [];
$sql_cat = "SELECT id, nome FROM categorias ORDER BY nome";
$result_cat = $conn->query($sql_cat);
if ($result_cat) {
    while ($row = $result_cat->fetch_assoc()) {
        $categorias[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Vincular Opções à Categoria</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        select { width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        button:hover { background-color: #0056b3; }
        h1 { text-align: center; }
        #opcoes-container { margin-top: 20px; border: 1px dashed #ccc; padding: 15px; border-radius: 5px; }
        .checkbox-item { display: block; margin-bottom: 8px; }
        .loader { font-style: italic; color: gray; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Vincular Opções Permitidas à Categoria</h1>
        <p>
            <a href="../../index.html">Voltar para Home</a> | 
            <a href="admin_atributo_opcoes.php">Gerenciar Opções Mestre</a>
        </p>
        
        <?php echo $status_message; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="hidden" name="acao" value="salvar_vinculos">
            
            <label for="categoria_id">1. Selecione a Categoria:</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Selecione...</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['id']); ?>">
                        <?php echo htmlspecialchars($cat['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="atributo_id">2. Selecione o Atributo:</label>
            <select id="atributo_id" name="atributo_id" required disabled>
                <option value="">(Selecione uma categoria primeiro)</option>
            </select>

            <label>3. Marque as Opções Permitidas para esta Categoria:</label>
            <div id="opcoes-container">
                <p class="loader">(Selecione um atributo)</p>
            </div>

            <button type="submit" id="save-button" disabled>Salvar Vínculos</button>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const selCategoria = document.getElementById('categoria_id');
        const selAtributo = document.getElementById('atributo_id');
        const divOpcoes = document.getElementById('opcoes-container');
        const saveButton = document.getElementById('save-button');

        let categoriaId = null;
        let atributoId = null;

        // MUDAR A CATEGORIA
        selCategoria.addEventListener('change', function() {
            categoriaId = this.value;
            atributoId = null; // Reseta o atributo
            
            selAtributo.innerHTML = '<option value="">(Carregando...)</option>';
            selAtributo.disabled = true;
            divOpcoes.innerHTML = '<p class="loader">(Selecione um atributo)</p>';
            saveButton.disabled = true;

            if (!categoriaId) {
                selAtributo.innerHTML = '<option value="">(Selecione uma categoria primeiro)</option>';
                return;
            }

            // Busca os atributos desta categoria na API assistente
            fetch(`../../api/opcoes.php?acao=getAtributos&categoria_id=${categoriaId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso && data.atributos.length > 0) {
                        selAtributo.innerHTML = '<option value="">Selecione um atributo</option>';
                        data.atributos.forEach(attr => {
                            selAtributo.innerHTML += `<option value="${attr.id}">${attr.nome}</option>`;
                        });
                        selAtributo.disabled = false;
                    } else {
                        selAtributo.innerHTML = '<option value="">(Nenhum atributo vinculado a esta categoria)</option>';
                    }
                })
                .catch(err => {
                    selAtributo.innerHTML = '<option value="">(Erro ao carregar atributos)</option>';
                });
        });

        // MUDAR O ATRIBUTO
        selAtributo.addEventListener('change', function() {
            atributoId = this.value;
            divOpcoes.innerHTML = '<p class="loader">(Carregando opções...)</p>';
            saveButton.disabled = true;

            if (!atributoId || !categoriaId) {
                divOpcoes.innerHTML = '<p class="loader">(Selecione um atributo)</p>';
                return;
            }

            // Busca as opções mestre E as opções já vinculadas
            fetch(`../../api/opcoes.php?acao=getOpcoes&categoria_id=${categoriaId}&atributo_id=${atributoId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.sucesso) {
                        divOpcoes.innerHTML = `<p style="color:red;">${data.mensagem || 'Erro ao carregar opções.'}</p>`;
                        return;
                    }

                    // Constrói lista mestre e conjunto de vinculadas
                    const mestre = Array.isArray(data.opcoes_mestre) ? data.opcoes_mestre : [];
                    const vinculadasArr = Array.isArray(data.opcoes_vinculadas) ? data.opcoes_vinculadas : [];
                    const vinculadas = new Set(vinculadasArr.map(op => op.id));

                    // Decide a lista a mostrar: preferir mestre (toda lista), fallback para vinculadas se mestre vazio
                    const listaParaMostrar = mestre.length ? mestre : vinculadasArr;

                    if (listaParaMostrar.length === 0) {
                        divOpcoes.innerHTML = '<p>Nenhuma opção mestre cadastrada para este atributo. <a href="admin_atributo_opcoes.php">Cadastre uma</a>.</p>';
                        saveButton.disabled = true;
                        return;
                    }

                    divOpcoes.innerHTML = '';
                    listaParaMostrar.forEach(opcao => {
                        const checked = vinculadas.has(opcao.id) ? 'checked' : '';
                        divOpcoes.innerHTML += `
                            <label class="checkbox-item">
                                <input type="checkbox" name="opcoes[]" value="${opcao.id}" ${checked}>
                                ${opcao.valor}
                            </label>
                        `;
                    });

                    saveButton.disabled = false;
                })
                .catch(err => {
                    divOpcoes.innerHTML = '<p style="color:red;">Erro de comunicação com a API.</p>';
                });
        });

    });
    </script>
</body>
</html>