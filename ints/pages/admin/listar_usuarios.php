<?php
require_once '../../config/_protecao.php';
exigirAdminGeral();

$is_modal = isset($_GET['modal']);

// ATIVAR/DESATIVAR USUÁRIO
if (isset($_GET['toggle_id'])) {
    $id = (int)$_GET['toggle_id'];
    $conn->query("UPDATE usuarios SET ativo = NOT ativo WHERE id = $id");
    $redirect = "listar_usuarios.php?atualizado=1" . ($is_modal ? "&modal=1" : "");
    header("Location: $redirect");
    exit;
}

// BUSCAR TODOS OS USUÁRIOS
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: <?php echo $is_modal ? '#fff' : '#f4f6f9'; ?>;
            margin: 0;
            padding: <?php echo $is_modal ? '16px' : '24px'; ?>;
        }

        .container { max-width: 1100px; margin: 0 auto; }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .header-actions h2 { margin: 0; font-size: 1.3rem; color: #2c3e50; }

        .msg-sucesso {
            background: #d4edda;
            color: #155724;
            padding: 10px 14px;
            margin-bottom: 16px;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
            font-size: 0.9rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        th {
            background: #f8f9fa;
            color: #555;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
        }

        td {
            padding: 11px 14px;
            font-size: 0.9rem;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fbff; }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.77rem;
            font-weight: 700;
        }

        .badge-admin         { background: #d1ecf1; color: #0c5460; }
        .badge-admin_unidade { background: #fff3cd; color: #856404; }
        .badge-gestor        { background: #d4edda; color: #155724; }
        .badge-comum         { background: #e2e3e5; color: #383d41; }

        .status-ativo   { color: #28a745; font-weight: 600; }
        .status-inativo { color: #dc3545; font-weight: 600; }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.82rem;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-edit   { background: #007bff; color: #fff; display: inline-block; }
        .btn-edit:hover { background: #0056b3; }
        .btn-toggle { background: #6c757d; color: #fff; }
        .btn-toggle:hover { background: #545b62; }

        .btn-novo {
            background: #28a745;
            color: #fff;
            padding: 9px 18px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .btn-novo:hover { background: #1e7e34; }

        .unidade-tag {
            background: #f0f4ff;
            color: #3552b0;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <h2>👥 Gerenciar Usuários</h2>
        <?php
        $link_novo = "admin_cadastro_usuario.php" . ($is_modal ? "?modal=1" : "");
        ?>
        <?php if ($is_modal): ?>
            <button onclick="window.location.href='<?php echo $link_novo; ?>'" class="btn-novo">+ Novo Usuário</button>
        <?php else: ?>
            <a href="<?php echo $link_novo; ?>" class="btn-novo">+ Novo Usuário</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['atualizado']) || isset($_GET['sucesso'])): ?>
        <div class="msg-sucesso">✅ Operação realizada com sucesso!</div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Nível</th>
                <th>Unidade</th>
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
                        $nivel_label = [
                            'admin'         => 'Admin Geral',
                            'admin_unidade' => 'Admin Unidade',
                            'gestor'        => 'Gestor',
                            'comum'         => 'Comum',
                        ];
                        $badge_class = 'badge-' . $u['nivel'];
                        ?>
                        <span class="badge <?php echo $badge_class; ?>">
                            <?php echo $nivel_label[$u['nivel']] ?? ucfirst($u['nivel']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['unidade_nome']): ?>
                            <span class="unidade-tag">🏢 <?php echo htmlspecialchars($u['unidade_nome']); ?></span>
                        <?php else: ?>
                            <span style="color:#bbb; font-size:.85rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['ativo']): ?>
                            <span class="status-ativo">● Ativo</span>
                        <?php else: ?>
                            <span class="status-inativo">● Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $edit_url = "admin_cadastro_usuario.php?id={$u['id']}" . ($is_modal ? "&modal=1" : "");
                        $toggle_url = "listar_usuarios.php?toggle_id={$u['id']}" . ($is_modal ? "&modal=1" : "");
                        ?>
                        <a href="<?php echo $edit_url; ?>" class="btn-sm btn-edit">✏️ Editar</a>
                        <a href="<?php echo $toggle_url; ?>"
                           class="btn-sm btn-toggle"
                           onclick="return confirm('<?php echo $u['ativo'] ? 'Desativar' : 'Ativar'; ?> este usuário?');">
                            <?php echo $u['ativo'] ? '🔒 Desativar' : '🔓 Ativar'; ?>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>