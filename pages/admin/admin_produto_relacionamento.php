<?php
require_once '../../config/_protecao.php';
exigirAdmin(); 

$status_message = "";
$usuario_id_log = getUsuarioId();

$produto_pai_id = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;

// Função para checar se $candidate é ancestral de $node (se candidate aparece em árvore acima de node)
function isAncestor($conn, $candidate, $node, $depth = 0, $maxDepth = 20) {
    if ($depth > $maxDepth) return false;
    if ($candidate == $node) return true;
    $sql = "SELECT produto_principal_id FROM produto_relacionamento WHERE subproduto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $node);
    $stmt->execute();
    $res = $stmt->get_result();
    $parents = [];
    while ($r = $res->fetch_assoc()) $parents[] = (int)$r['produto_principal_id'];
    $stmt->close();
    foreach ($parents as $p) {
        if ($p == $candidate) return true;
        if (isAncestor($conn, $candidate, $p, $depth + 1, $maxDepth)) return true;
    }
    return false;
}

// LÓGICA DE ADICIONAR COMPONENTE (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'])) {
    $produto_pai_id = (int)$_POST['produto_pai_id'];
    
    if ($_POST['acao'] == 'adicionar') {
        $subproduto_id = (int)$_POST['subproduto_id'];
        $quantidade = (float)$_POST['quantidade'];
        $tipo_relacao = $_POST['tipo_relacao']; // componente, kit, acessorio

        // Validação básica: Pai não pode ser igual ao Filho
        if ($produto_pai_id === $subproduto_id) {
            $status_message = "<p style='color: red;'>Erro: Um produto não pode ser componente de si mesmo.</p>";
        } elseif ($quantidade <= 0) {
            $status_message = "<p style='color: red;'>Erro: Quantidade deve ser maior que zero.</p>";
        } else {
            // Nova validação: previne ciclos (se subproduto já é ancestral do pai)
            if (isAncestor($conn, $produto_pai_id, $subproduto_id)) {
                $status_message = "<p style='color: red;'>Erro: Inserção cancelada — ciclo detectado (o subproduto é ancestral do produto pai).</p>";
            } else {
                // Insere o relacionamento
                $sql_ins = "INSERT INTO produto_relacionamento (produto_principal_id, subproduto_id, quantidade, tipo_relacao) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql_ins);
                $stmt->bind_param("iids", $produto_pai_id, $subproduto_id, $quantidade, $tipo_relacao);
                
                if ($stmt->execute()) {
                    $status_message = "<p style='color: green;'>Componente adicionado com sucesso!</p>";
                } else {
                    $status_message = "<p style='color: red;'>Erro ao adicionar (possível duplicidade): " . $stmt->error . "</p>";
                }
                $stmt->close();
            }
        }
    } 
    elseif ($_POST['acao'] == 'remover') {
        $relacionamento_id = (int)$_POST['relacionamento_id'];
        $conn->query("DELETE FROM produto_relacionamento WHERE id = $relacionamento_id");
        $status_message = "<p style='color: green;'>Componente removido.</p>";
    }
}

// CARREGAR DADOS

// Lista de todos os produtos (para os dropdowns)
$produtos_lista = [];
$res_prod = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
while ($r = $res_prod->fetch_assoc()) {
    $produtos_lista[] = $r;
}

// Se um pai estiver selecionado, busca seus componentes atuais
$componentes_atuais = [];
$nome_produto_pai = "";

if ($produto_pai_id > 0) {
    // Nome do Pai
    $stmt_nome = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt_nome->bind_param("i", $produto_pai_id);
    $stmt_nome->execute();
    $res_nome = $stmt_nome->get_result();
    if ($row = $res_nome->fetch_assoc()) $nome_produto_pai = $row['nome'];

    // Componentes
    $sql_comp = "
        SELECT pr.id, pr.quantidade, pr.tipo_relacao, p.nome, p.id as sub_id
        FROM produto_relacionamento pr
        JOIN produtos p ON pr.subproduto_id = p.id
        WHERE pr.produto_principal_id = ?
        ORDER BY pr.tipo_relacao, p.nome
    ";
    $stmt_comp = $conn->prepare($sql_comp);
    $stmt_comp->bind_param("i", $produto_pai_id);
    $stmt_comp->execute();
    $res_comp = $stmt_comp->get_result();
    while ($row = $res_comp->fetch_assoc()) {
        $componentes_atuais[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Composição (Kits)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 900px; margin: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .form-inline { display: flex; gap: 10px; align-items: flex-end; background: #f9f9f9; padding: 15px; border-radius: 5px; }
        .form-group { display: flex; flex-direction: column; }
        button { padding: 8px 15px; cursor: pointer; }
        .btn-remove { background-color: #dc3545; color: white; border: none; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gerenciar Composição de Produtos</h1>
        <p><a href="../produtos/listar.php">Voltar</a> | <a href="../produtos/estrutura.php?id=<?php echo $produto_pai_id; ?>">Visualizar Estrutura (BOM)</a></p>
        <?php echo $status_message; ?>

        <div style="margin-bottom: 30px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
            <form method="GET">
                <label><strong>Selecione o Produto Principal (Pai):</strong></label>
                <select name="produto_id" onchange="this.form.submit()">
                    <option value="">Selecione...</option>
                    <?php foreach ($produtos_lista as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo ($produto_pai_id == $p['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($produto_pai_id): ?>
            <h2>Composição de: <span style="color: #007bff;"><?php echo htmlspecialchars($nome_produto_pai); ?></span></h2>

            <form method="POST" class="form-inline">
                <input type="hidden" name="acao" value="adicionar">
                <input type="hidden" name="produto_pai_id" value="<?php echo $produto_pai_id; ?>">

                <div class="form-group" style="flex: 2;">
                    <label>Componente / Subproduto:</label>
                    <select name="subproduto_id" required style="width: 100%;">
                        <option value="">Selecione...</option>
                        <?php foreach ($produtos_lista as $p): ?>
                            <?php if ($p['id'] != $produto_pai_id): // Não mostra o próprio pai ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="width: 100px;">
                    <label>Qtd:</label>
                    <input type="number" name="quantidade" step="0.01" min="0.01" value="1" required style="width: 100%;">
                </div>

                <div class="form-group" style="width: 150px;">
                    <label>Tipo Relação:</label>
                    <select name="tipo_relacao">
                        <option value="componente">Componente</option>
                        <option value="kit">Kit (Venda)</option>
                        <option value="acessorio">Acessório</option>
                    </select>
                </div>

                <button type="submit" style="background-color: #28a745; color: white; border: none; border-radius: 4px;">+ Adicionar</button>
            </form>

            <h3>Lista de Componentes</h3>
            <?php if (count($componentes_atuais) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th>Quantidade</th>
                            <th>Tipo</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($componentes_atuais as $comp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($comp['nome']); ?></td>
                                <td><?php echo $comp['quantidade']; ?></td>
                                <td><?php echo ucfirst($comp['tipo_relacao']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este componente?');">
                                        <input type="hidden" name="acao" value="remover">
                                        <input type="hidden" name="produto_pai_id" value="<?php echo $produto_pai_id; ?>">
                                        <input type="hidden" name="relacionamento_id" value="<?php echo $comp['id']; ?>">
                                        <button type="submit" class="btn-remove">X</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Este produto ainda não possui componentes.</p>
            <?php endif; ?>

        <?php else: ?>
            <p>Selecione um produto acima para gerenciar sua composição.</p>
        <?php endif; ?>
    </div>
</body>
</html>