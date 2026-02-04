<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

// Tipos de locais permitidos (Enum no banco)
$tipos_locais = ['unidade', 'andar', 'sala', 'outro'];

// Lógica de Cadastro
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'cadastrar') {
    $nome = trim($_POST['nome']);
    $endereco = trim($_POST['endereco']);
    $tipo_local = $_POST['tipo_local'];
    // Se for vazio, salva como NULL no banco
    $local_pai_id = !empty($_POST['local_pai_id']) ? (int)$_POST['local_pai_id'] : null;

    if (empty($nome) || empty($tipo_local)) {
        $status_message = "<p style='color: red;'>Erro: Nome e Tipo do Local são obrigatórios.</p>";
    } else {
        // Validação de consistência: verifica compatibilidade entre tipo_local e tipo do local_pai (se informado)
        $isValidParent = true;
        $parentTipo = null;
        if (!is_null($local_pai_id)) {
            $stmtP = $conn->prepare("SELECT tipo_local FROM locais WHERE id = ? LIMIT 1");
            $stmtP->bind_param("i", $local_pai_id);
            $stmtP->execute();
            $rP = $stmtP->get_result()->fetch_assoc();
            $stmtP->close();
            if ($rP) $parentTipo = $rP['tipo_local'];
            else {
                $isValidParent = false;
                $status_message = "<p style='color: red;'>Erro: Local pai não encontrado.</p>";
            }
        }

        // Regras de negócio (mesmas usadas no JS):
        // unidade  -> normalmente raiz ou pode ter pai 'outro'
        // andar    -> deve ter pai 'unidade'
        // sala     -> pode ter pai 'andar' ou 'unidade' (ajuste para maior flexibilidade)
        // outro    -> livre
        if ($isValidParent && !is_null($parentTipo)) {
            switch ($tipo_local) {
                case 'unidade':
                    // permita raiz ou pai 'outro' (se existir)
                    if (!in_array($parentTipo, ['outro'])) {
                        // allow root only; parent not valid
                        // we still allow root (local_pai_id == null)
                        if (!is_null($local_pai_id)) {
                            $isValidParent = false;
                            $status_message = "<p style='color: red;'>Erro: Unidade só pode ter como pai 'outro' ou ser raiz.</p>";
                        }
                    }
                    break;
                case 'andar':
                    if ($parentTipo !== 'unidade') {
                        $isValidParent = false;
                        $status_message = "<p style='color: red;'>Erro: Andar deve estar dentro de uma Unidade.</p>";
                    }
                    break;
                case 'sala':
                    if (!in_array($parentTipo, ['andar','unidade'])) {
                        $isValidParent = false;
                        $status_message = "<p style='color: red;'>Erro: Sala deve estar dentro de Andar ou diretamente em uma Unidade.</p>";
                    }
                    break;
                case 'outro':
                    // sem restrição
                    break;
                default:
                    $isValidParent = false;
                    $status_message = "<p style='color: red;'>Tipo de local inválido.</p>";
                    break;
            }
        }

        if ($isValidParent) {
            $sql = "INSERT INTO locais (nome, endereco, tipo_local, local_pai_id) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                // Atenção: se local_pai_id for NULL, bind_param exige variável; vamos usar uma variável que possa ser null
                // usei 's' para strings e 'i' para int; porém mysqli não aceita null em bind_param de inteiros diretamente - 
                // a conversão costuma resultar em 0, por isso usamos uma query preparada com NULL via atribuição condicional
                if (is_null($local_pai_id)) {
                    // bind with NULL: pass null as NULL in query via explicit SQL fragment
                    $stmt = $conn->prepare("INSERT INTO locais (nome, endereco, tipo_local, local_pai_id) VALUES (?, ?, ?, NULL)");
                    $stmt->bind_param("sss", $nome, $endereco, $tipo_local);
                } else {
                    $stmt->bind_param("sssi", $nome, $endereco, $tipo_local, $local_pai_id);
                }

                if ($stmt->execute()) {
                    $status_message = "<p style='color: green;'>Local '{$nome}' cadastrado com sucesso!</p>";
                } else {
                    $status_message = "<p style='color: red;'>Erro ao cadastrar local: " . $stmt->error . "</p>";
                }
                $stmt->close();
            } else {
                $status_message = "<p style='color: red;'>Erro na preparação da consulta: " . $conn->error . "</p>";
            }
        }
    }
}

// Lógica de Listagem

// Buscar Locais para o Dropdown de "Local Pai" (Qualquer local pode ser pai)
$locais_para_pai = [];
$sql_pais = "SELECT id, nome, tipo_local FROM locais WHERE deletado = FALSE ORDER BY nome";
$result_pais = $conn->query($sql_pais);
if ($result_pais) {
    while ($row = $result_pais->fetch_assoc()) {
        $locais_para_pai[] = $row;
    }
}

// Buscar Locais para a Tabela (Listagem Hierárquica Visual Simples)
$locais_listagem = [];
$sql_listagem = "
    SELECT 
        l.id, 
        l.nome, 
        l.endereco, 
        l.tipo_local,
        p.nome AS nome_pai
    FROM 
        locais l
    LEFT JOIN 
        locais p ON l.local_pai_id = p.id
    WHERE 
        l.deletado = FALSE
    ORDER BY 
        l.tipo_local, l.nome
";
$result_listagem = $conn->query($sql_listagem);

if ($result_listagem) {
    while ($row = $result_listagem->fetch_assoc()) {
        $locais_listagem[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Administração - Locais Hierárquicos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type="text"], select { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        button:hover { background-color: #45a049; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .badge { padding: 3px 8px; border-radius: 10px; color: white; font-size: 0.8em; }
        .badge-unidade { background-color: #007bff; }
        .badge-andar { background-color: #17a2b8; }
        .badge-sala { background-color: #28a745; }
        .badge-outro { background-color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Administração de Locais</h1>
        <p><a href="../../index.html">Voltar para Home</a></p>
        <?php echo $status_message; ?>

        <h2>Cadastrar Novo Local</h2>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="hidden" name="acao" value="cadastrar">
            
            <label for="nome">Nome do Local (Ex: Prédio A, 2º Andar, Sala 101):</label>
            <input type="text" id="nome" name="nome" required>

            <label for="endereco">Endereço / Descrição:</label>
            <input type="text" id="endereco" name="endereco">

            <div style="display: flex; gap: 20px;">
                <div style="flex: 1;">
                    <label for="tipo_local">Tipo de Local:</label>
                    <select id="tipo_local" name="tipo_local" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($tipos_locais as $tipo): ?>
                            <option value="<?php echo $tipo; ?>"><?php echo ucfirst($tipo); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="flex: 1;">
                    <label for="local_pai_id">Local Pai (Hierarquia):</label>
                    <select id="local_pai_id" name="local_pai_id">
                        <option value="" data-tipo="raiz">(Nenhum - É um local raiz)</option>
                        <?php foreach ($locais_para_pai as $pai): ?>
                            <option value="<?php echo $pai['id']; ?>" data-tipo="<?php echo $pai['tipo_local']; ?>">
                                <?php echo htmlspecialchars($pai['nome']); ?> (<?php echo ucfirst($pai['tipo_local']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit">Cadastrar Local</button>
        </form>

        <hr>

        <h2>Locais Cadastrados</h2>
        <?php if (!empty($locais_listagem)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Pertence a (Pai)</th>
                        <th>Endereço</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locais_listagem as $local): 
                        $classe_badge = 'badge-' . $local['tipo_local'];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($local['id']); ?></td>
                            <td><?php echo htmlspecialchars($local['nome']); ?></td>
                            <td><span class="badge <?php echo $classe_badge; ?>"><?php echo ucfirst($local['tipo_local']); ?></span></td>
                            <td>
                                <?php echo $local['nome_pai'] ? htmlspecialchars($local['nome_pai']) : '-'; ?>
                            </td>
                            <td><?php echo htmlspecialchars($local['endereco']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhum local cadastrado ainda.</p>
        <?php endif; ?>
    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectTipo = document.getElementById('tipo_local');
        const selectPai = document.getElementById('local_pai_id');
        const optionsPai = Array.from(selectPai.options); // Transforma em array para facilitar filtro

        function atualizarOpcoesPai() {
            const tipoSelecionado = selectTipo.value;
            
            // Reseta a seleção atual para evitar enviar um pai inválido oculto
            selectPai.value = ""; 

            optionsPai.forEach(option => {
                const tipoPai = option.getAttribute('data-tipo');
                
                // Lógica de Visibilidade (Regras de Negócio)
                let visivel = false;

                if (option.value === "") {
                    visivel = true; // A opção "Nenhum/Raiz" sempre aparece
                } else if (!tipoSelecionado) {
                    // Se o tipo não foi selecionado ainda, mostramos todos os pais possíveis
                    visivel = true;
                } else {
                    switch (tipoSelecionado) {
                        case 'unidade':
                            // Unidade só pode ter pai 'outro' (Complexo) ou ser Raiz
                            if (tipoPai === 'outro') visivel = true;
                            break;
                        case 'andar':
                            // Andar só pode estar dentro de 'unidade'
                            if (tipoPai === 'unidade') visivel = true;
                            break;
                        case 'sala':
                            // Sala pode estar dentro de 'andar' ou diretamente dentro de 'unidade' (flexibilidade)
                            if (tipoPai === 'andar' || tipoPai === 'unidade') visivel = true;
                            break;
                        case 'outro':
                            // 'Outro' (Complexo) geralmente é raiz, não tem pai específico restrito
                            visivel = true; 
                            break;
                    }
                }

                // Aplica a visibilidade
                if (visivel) {
                    option.style.display = ''; // Mostra
                    option.disabled = false;
                } else {
                    option.style.display = 'none'; // Esconde
                    option.disabled = true; // Desabilita para garantir que não seja enviado
                }
            });
        }

        // Escuta a mudança no tipo
        selectTipo.addEventListener('change', atualizarOpcoesPai);

        // Roda uma vez ao carregar para aplicar o estado inicial
        atualizarOpcoesPai();
    });
</script>
</html>