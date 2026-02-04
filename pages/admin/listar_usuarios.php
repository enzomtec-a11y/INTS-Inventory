<?php
// listar_usuarios.php
require_once '../../config/_protecao.php';
exigirAdmin(); // Segurança: Apenas admin geral acessa

// 1. LÓGICA PARA ATIVAR/DESATIVAR USUÁRIO
if (isset($_GET['toggle_id'])) {
    $id = (int)$_GET['toggle_id'];
    // Inverte o status atual (1 para 0, 0 para 1)
    $conn->query("UPDATE usuarios SET ativo = NOT ativo WHERE id = $id");
    header("Location: listar_usuarios.php?atualizado=1");
    exit;
}

// 2. BUSCAR TODOS OS USUÁRIOS (com JOIN para ver o nome da unidade)
$sql = "SELECT u.id, u.nome, u.email, u.nivel, u.ativo, l.nome as unidade_nome 
        FROM usuarios u
        LEFT JOIN locais l ON u.unidade_id = l.id
        ORDER BY u.nome ASC";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Usuários</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container { max-width: 1000px; margin: 30px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f8f9fa; color: #333; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-admin { background: #d1ecf1; color: #0c5460; }
        .badge-admin-unidade { background: #fff3cd; color: #856404; }
        .badge-gestor { background: #d4edda; color: #155724; }
        .badge-comum { background: #e2e3e5; color: #383d41; }
        .status-ativo { color: #28a745; }
        .status-inativo { color: #dc3545; }
        .btn-sm { padding: 5px 10px; font-size: 13px; text-decoration: none; border-radius: 4px; margin-right: 5px; }
        .btn-edit { background: #007bff; color: #fff; }
        .btn-toggle { background: #6c757d; color: #fff; }
        .msg-sucesso { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <h2>Gerenciar Usuários</h2>
        <a href="admin_cadastro_usuario.php" class="btn btn-save" style="text-decoration: none;">+ Novo Usuário</a>
    </div>

    <?php if (isset($_GET['sucesso'])): ?>
        <div class="msg-sucesso">Operação realizada com sucesso!</div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Nível</th>
                <th>Unidade Responsável</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($u = $res->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['nome']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <?php 
                        $classe = 'badge-' . str_replace('_', '-', $u['nivel']);
                        $label = ($u['nivel'] == 'admin_unidade') ? 'Admin Unidade' : ucfirst($u['nivel']);
                        echo "<span class='badge $classe'>$label</span>";
                        ?>
                    </td>
                    <td>
                        <?php echo $u['unidade_nome'] ? htmlspecialchars($u['unidade_nome']) : '<span style="color:#ccc">---</span>'; ?>
                    </td>
                    <td class="<?php echo $u['ativo'] ? 'status-ativo' : 'status-inativo'; ?>">
                        <?php echo $u['ativo'] ? 'Ativo' : '○ Inativo'; ?>
                    </td>
                    <td>
                        <a href="admin_cadastro_usuario.php?id=<?php echo $u['id']; ?>" class="btn-sm btn-edit">Editar</a>
                        
                        <a href="listar_usuarios.php?toggle_id=<?php echo $u['id']; ?>" 
                           class="btn-sm btn-toggle" 
                           onclick="return confirm('Deseja alterar o status de acesso deste usuário?')">
                           <?php echo $u['ativo'] ? 'Desativar' : 'Ativar'; ?>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>