<?php
require_once '../../config/_protecao.php';

// Busca todas as categorias (para montar a árvore)
$categorias = [];
$sql = "SELECT id, nome, categoria_pai_id FROM categorias WHERE deletado = FALSE ORDER BY categoria_pai_id ASC, nome ASC";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $categorias[] = $row;
}

// Busca todos os atributos
$atributos = [];
$sql = "SELECT * FROM atributos_definicao ORDER BY nome";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $atributos[$row['id']] = $row;
}

// Busca todos os vínculos categoria-atributo
$vinculos = [];
$sql = "SELECT ca.*, ad.nome as atributo_nome, ad.tipo as atributo_tipo FROM categoria_atributo ca JOIN atributos_definicao ad ON ca.atributo_id = ad.id";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $vinculos[$row['categoria_id']][] = $row;
}

// Lida com submissão para adicionar novo vínculo
$status_message = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['acao']) && $_POST['acao'] === 'vincular') {
    $categoria_id = (int)$_POST['categoria_id'];
    $atributo_id = (int)$_POST['atributo_id'];
    $obrigatorio = isset($_POST['obrigatorio']) ? 1 : 0;
    if ($categoria_id && $atributo_id) {
        // Verifica se já existe
        $sql_ver = "SELECT COUNT(*) AS qtd FROM categoria_atributo WHERE categoria_id=? AND atributo_id=?";
        $stmt = $conn->prepare($sql_ver);
        $stmt->bind_param("ii", $categoria_id, $atributo_id);
        $stmt->execute();
        $stmt->bind_result($qtd_existente);
        $stmt->fetch();
        $stmt->close();
        if ($qtd_existente == 0) {
            $sql = "INSERT INTO categoria_atributo (categoria_id, atributo_id, obrigatorio) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iii", $categoria_id, $atributo_id, $obrigatorio);
            if ($stmt->execute()) {
                $status_message = "<div class='alert success'>Vínculo criado!</div>";
            } else {
                $status_message = "<div class='alert error'>Erro ao criar vínculo: " . $stmt->error . "</div>";
            }
            $stmt->close();
        } else {
            $status_message = "<div class='alert warn'>O vínculo já existe.</div>";
        }
    } else {
        $status_message = "<div class='alert error'>Categoria e atributo obrigatórios!</div>";
    }
    // Refresh para evitar repost
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Remover vínculo
if (isset($_GET['remover']) && is_numeric($_GET['remover'])) {
    $id = (int)$_GET['remover'];
    $conn->query("DELETE FROM categoria_atributo WHERE id = $id");
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Alternar obrigatoriedade
if (isset($_GET['editar_obrigatorio']) && is_numeric($_GET['editar_obrigatorio'])) {
    $id = (int)$_GET['editar_obrigatorio'];
    $res = $conn->query("SELECT obrigatorio FROM categoria_atributo WHERE id = $id");
    if ($res && $row = $res->fetch_assoc()) {
        $novo = $row['obrigatorio'] ? 0 : 1;
        $conn->query("UPDATE categoria_atributo SET obrigatorio = $novo WHERE id = $id");
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Função recursiva para imprimir as categorias e atributos vinculados
function renderTree($categorias, $vinculos, $atributos, $pai_id = null, $nivel = 0) {
    foreach ($categorias as $cat) {
        if ($cat['categoria_pai_id'] == $pai_id) {
            echo "<div class='categoria-bloco' style='margin-left:".($nivel*24)."px'>";
            echo "<strong>" . htmlspecialchars($cat['nome']) . "</strong>";
            
            // Lista atributos vinculados
            if (!empty($vinculos[$cat['id']])) {
                echo "<ul class='atributos-lista'>";
                foreach ($vinculos[$cat['id']] as $vinc) {
                    echo "<li><span class='atributo-nome'>" 
                        . htmlspecialchars($vinc['atributo_nome']) . "</span> ";
                    echo "<small>(" . htmlspecialchars($vinc['atributo_tipo']) . ")</small> ";
                    echo $vinc['obrigatorio'] ? "<span class='badge obrigatorio'>Obrigatório</span>" : "<span class='badge opcional'>Opcional</span>";
                    echo " <a href='?remover={$vinc['id']}' class='btn-remover' title='Desvincular' onclick='return confirm(\"Remover vínculo?\")'>🗑️</a>";
                    echo " <a href='?editar_obrigatorio={$vinc['id']}' class='btn-obr'>" . ($vinc['obrigatorio'] ? "Tornar Opcional" : "Tornar Obrigatório") . "</a>";
                    echo "</li>";
                }
                echo "</ul>";
            } else {
                echo "<div class='nenhum-atributo'>Nenhum atributo vinculado</div>";
            }

            // Formulário inline para adicionar novo vínculo
            echo "<form method='POST' style='margin: 6px 0'>";
            echo "<input type='hidden' name='acao' value='vincular'>";
            echo "<input type='hidden' name='categoria_id' value='".(int)$cat['id']."'>";
            echo "<select name='atributo_id' required>";
            echo "<option value=''>+ Vincular atributo</option>";
            foreach ($atributos as $att_id => $att) {
                // Não deixa adicionar o mesmo atributo
                $ja = false;
                if (!empty($vinculos[$cat['id']])) {
                    foreach ($vinculos[$cat['id']] as $vinc) {
                        if ($vinc['atributo_id'] == $att_id) { $ja = true; break; }
                    }
                }
                if (!$ja) {
                    echo "<option value='".$att['id']."'>".htmlspecialchars($att['nome']) . " (" . htmlspecialchars($att['tipo']) .")</option>";
                }
            }
            echo "</select> ";
            echo "<label><input type='checkbox' name='obrigatorio'> Obrigatório</label> ";
            echo "<button type='submit' class='btn-adicionar'>Adicionar</button>";
            echo "</form>";

            // Recursão para filhos
            renderTree($categorias, $vinculos, $atributos, $cat['id'], $nivel+1);
            echo "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Vínculo Categoria x Atributo</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f8fafe; margin: 0; padding: 12px;}
        .categoria-bloco { margin-bottom: 10px; padding: 8px; border: 1px solid #dde4eb; border-radius: 6px; background: #fff;}
        .atributos-lista { margin: 0 0 8px 16px; padding: 0;}
        .atributos-lista li { margin-bottom: 3px; list-style: disc;}
        .atributo-nome { font-weight: bold; }
        .badge.obrigatorio { color: #fff; background: #c00; border-radius: 3px; padding: 2px 6px; margin-left:3px;}
        .badge.opcional { color: #555; background: #eee; border-radius: 3px; padding: 2px 6px; margin-left:3px;}
        .btn-remover { color: #c00; text-decoration: none; margin-left: 10px;}
        .btn-obr { font-size: small; color: #035a2d; margin-left: 5px; text-decoration: none;}
        .btn-adicionar { font-size: small; color: #056; background: #e6f3ff; border: 1px solid #b8d6ec; border-radius: 4px; padding:2px 6px;}
        .nenhum-atributo { color: #aaa; font-style:italic; margin: 2px 0 4px 0;}
        form { display: inline-block; }
        select { font-size:small; }
        .alert { padding: 8px 16px; margin: 12px 0; border-radius:4px; font-weight:bold;}
        .alert.success { background: #eaffea; color: #17691b; border:1px solid #b0e6b7;}
        .alert.error { background: #ffecec; color: #a90b0b; border:1px solid #fbc1c1;}
        .alert.warn { background: #fff8e0; color: #856404; border:1px solid #ffeaa6;}
    </style>
</head>
<body>
    <h2>Vínculo Categoria x Atributo (Agrupado)</h2>
    <p>Nesta tela você visualiza os atributos vinculados a cada categoria (e suas filhas) de forma agrupada. Fácil para identificar quais atributos estão onde.</p>
    <?php if ($status_message) echo $status_message; ?>
    <?php renderTree($categorias, $vinculos, $atributos); ?>
</body>
</html>