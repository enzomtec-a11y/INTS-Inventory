<?php
require_once '../../config/_protecao.php';

$status_message = "";
$usuario_id = getUsuarioId();
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id = $_SESSION['unidade_id'] ?? null;
$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id : null;

// 1. CARREGAR LOCAIS FILTRADOS
$locais_salas = getLocaisFormatados($conn, true, $filtro_unidade);

// 2. CARREGAR PRODUTOS (Sem filtro por enquanto, solicitação pode ser global)
$produtos = [];
$res_prod = $conn->query("SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome");
while ($row = $res_prod->fetch_assoc()) $produtos[] = $row;

// 3. PROCESSAR
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'solicitar') {
    $produto_id = (int)$_POST['produto_id'];
    $quantidade = (float)$_POST['quantidade'];
    $origem_id = (int)$_POST['local_origem_id'];
    $destino_id = (int)$_POST['local_destino_id'];
    
    // Validar se o admin_unidade tem permissão sobre esses locais
    if ($filtro_unidade) {
        $ids_perm = getIdsLocaisDaUnidade($conn, $filtro_unidade);
        if (!in_array($origem_id, $ids_perm) || !in_array($destino_id, $ids_perm)) {
            $status_message = "<div class='alert error'>Você só pode movimentar entre locais da sua unidade.</div>";
        }
    }

    if (empty($status_message)) {
        if ($origem_id == $destino_id) {
            $status_message = "<div class='alert error'>Origem e destino iguais.</div>";
        } else {
            // Verificar saldo origem (opcional)
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, quantidade, local_origem_id, local_destino_id, usuario_id, status, tipo_movimentacao) VALUES (?, ?, ?, ?, ?, 'pendente', 'TRANSFERENCIA')");
            $stmt->bind_param("idiii", $produto_id, $quantidade, $origem_id, $destino_id, $usuario_id);
            if ($stmt->execute()) {
                $status_message = "<div class='alert success'>Solicitação enviada com sucesso!</div>";
                if(function_exists('registrarLog')) registrarLog($conn, $usuario_id, 'movimentacoes', $stmt->insert_id, 'SOLICITACAO', "Qtd: $quantidade", $produto_id);
            } else {
                $status_message = "<div class='alert error'>Erro: " . $conn->error . "</div>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Solicitar Movimentação</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container { max-width: 600px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #ddd; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
        button { width: 100%; padding: 12px; background: #28a745; color: white; border: none; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Nova Solicitação</h1>
        <?php echo $status_message; ?>
        <form method="POST">
            <input type="hidden" name="acao" value="solicitar">
            
            <div class="form-group">
                <label>Produto</label>
                <select name="produto_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($produtos as $p) echo "<option value='{$p['id']}'>".htmlspecialchars($p['nome'])."</option>"; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Quantidade</label>
                <input type="number" name="quantidade" min="0.01" step="any" value="1" required>
            </div>
            
            <div class="form-group">
                <label>Origem</label>
                <select name="local_origem_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($locais_salas as $id => $nome) echo "<option value='$id'>".htmlspecialchars($nome)."</option>"; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Destino</label>
                <select name="local_destino_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($locais_salas as $id => $nome) echo "<option value='$id'>".htmlspecialchars($nome)."</option>"; ?>
                </select>
            </div>
            
            <button type="submit">Enviar</button>
        </form>
        <p style="text-align:center"><a href="listar.php">Voltar</a></p>
    </div>
</body>
</html>