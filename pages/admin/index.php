<?php
require_once '../../config/_protecao.php';

// --- VERIFICAÇÃO DE SEGURANÇA ESTRITA ---
// Apenas 'admin' passa. 'admin_unidade', 'gestor' ou 'comum' são barrados.
$nivel = $_SESSION['usuario_nivel'] ?? '';

if ($nivel !== 'admin') {
    // Pode redirecionar ou matar o script com mensagem
    die("
        <div style='font-family:sans-serif; text-align:center; padding:50px;'>
            <h1 style='color:#dc3545;'>🚫 Acesso Negado</h1>
            <p>Este painel é exclusivo para Administradores Gerais do Sistema.</p>
            <a href='../../index.html' style='padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;'>Voltar ao Início</a>
        </div>
    ");
}

// Configuração dos Cards de Administração
// Agrupamos para ficar visualmente organizado
$grupos_admin = [
    'Usuários e Acesso' => [
        [
            'titulo' => 'Gerenciar Usuários',
            'desc' => 'Listar, editar, ativar/desativar usuários.',
            'link' => 'listar_usuarios.php',
            'icone' => '👥',
            'cor' => '#007bff'
        ],
        [
            'titulo' => 'Novo Usuário',
            'desc' => 'Cadastrar um novo acesso manualmente.',
            'link' => 'admin_cadastro_usuario.php',
            'icone' => '➕',
            'cor' => '#28a745'
        ]
    ],
    'Estrutura Física e Lógica' => [
        [
            'titulo' => 'Locais / Unidades',
            'desc' => 'Gerenciar Unidades, Andares e Salas.',
            'link' => 'admin_locais.php',
            'icone' => '🏢',
            'cor' => '#17a2b8'
        ],
        [
            'titulo' => 'Categorias',
            'desc' => 'Criar e organizar a árvore de categorias.',
            'link' => 'admin_categorias.php',
            'icone' => '📂',
            'cor' => '#ffc107',
            'texto_cor' => '#333'
        ]
    ],
    'Sistema de Atributos (EAV)' => [
        [
            'titulo' => 'Definição de Atributos',
            'desc' => 'Criar campos extras (Voltagem, Cor, Marca).',
            'link' => 'admin_atributos.php',
            'icone' => '🔧',
            'cor' => '#6610f2'
        ],
        [
            'titulo' => 'Opções de Atributos',
            'desc' => 'Gerenciar listas de valores (Selects).',
            'link' => 'admin_atributo_opcoes.php',
            'icone' => 'list_alt', // Material icon name fallback ou emoji 📋
            'icone_emoji' => '📋',
            'cor' => '#6f42c1'
        ],
        [
            'titulo' => 'Vincular Attr > Categoria',
            'desc' => 'Quais atributos aparecem em qual categoria.',
            'link' => 'admin_categoria_atributo.php',
            'icone' => '🔗',
            'cor' => '#e83e8c'
        ],
        [
            'titulo' => 'Vincular Opções > Categoria',
            'desc' => 'Restringir opções específicas por categoria.',
            'link' => 'admin_categoria_opcoes.php',
            'icone' => '⚙️',
            'cor' => '#fd7e14'
        ]
    ],
    'Manutenção' => [
        [
            'titulo' => 'Baixas em Estoque',
            'desc' => 'Gerenciar baixas de produtos nos locais',
            'link' => 'gerenciar_baixas.php',
            'icone' => '⚠️',
            'cor' => '#fbff29'
        ],
        [
            'titulo' => 'Lixeira de Produtos',
            'desc' => 'Restaurar ou excluir permanentemente itens.',
            'link' => 'admin_produtos_deletados.php',
            'icone' => '🗑️',
            'cor' => '#dc3545'
        ]
    ]
];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - INTS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* Layout Básico */
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background-color: #f4f6f9; display: flex; height: 100vh; overflow: hidden; }
        
        .sidebar { width: 200px; background: #343a40; color: #fff; display: flex; flex-direction: column; padding: 20px; }
        .sidebar h2 { font-size: 1.2rem; margin-bottom: 20px; color: #f8f9fa; }
        .sidebar a { color: #ccc; text-decoration: none; padding: 10px; border-radius: 4px; display: block; margin-bottom: 5px; }
        .sidebar a:hover { background: #495057; color: white; }
        
        .main-content { flex: 1; padding: 20px; overflow-y: auto; position: relative; }

        /* Grid de Cards */
        .admin-section { margin-bottom: 40px; }
        .section-title { font-size: 1.1rem; font-weight: bold; color: #555; margin-bottom: 15px; border-left: 4px solid #333; padding-left: 10px; }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .admin-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            border-top: 4px solid #ccc; /* Cor padrão, sobrescrita via inline style */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }

        .admin-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .card-header { display: flex; align-items: center; margin-bottom: 10px; }
        .card-icon { font-size: 2rem; margin-right: 15px; }
        .card-title { font-size: 1.1rem; font-weight: bold; color: #333; margin: 0; }
        
        .card-desc { font-size: 0.9rem; color: #666; margin-bottom: 20px; line-height: 1.4; flex-grow: 1; }
        
        .btn-access {
            display: block;
            text-align: center;
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            padding: 10px;
            border-radius: 4px;
            font-weight: bold;
            border: 1px solid #ddd;
            transition: 0.2s;
        }
        .btn-access:hover {
            background: #e2e6ea;
            border-color: #ccc;
        }

        /* Modal Styles (caso queira abrir em modal) */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1000;
            display: none; justify-content: center; align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-window {
            background: #fff; width: 95%; max-width: 1100px; height: 90%;
            border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            display: flex; flex-direction: column; overflow: hidden;
            animation: slideIn 0.3s ease;
        }
        .modal-header {
            padding: 15px; background: #f8f9fa; border-bottom: 1px solid #ddd;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-title { font-weight: bold; font-size: 1.1em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; }
        .modal-content-frame { flex: 1; border: none; width: 100%; background: #fff; }
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <h2>INTS Inventário</h2>
        <a href="../../index.html">🏠 Home</a>
        <a href="../produtos/index.php">📦 Produtos</a>
        <a href="../movimentacoes/index.php">🔄 Movimentações</a>
        <div style="border-top:1px solid #4b545c; margin:10px 0;"></div>
        <a href="index.php" style="background:#495057; color:#fff;">⚙️ Administração</a>
    </aside>

    <main class="main-content">
        <h1 style="margin-top:0;">Administração Geral</h1>
        <p style="color:#666; margin-bottom:30px;">
            Bem-vindo, <strong><?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin'); ?></strong>. 
            Aqui você tem controle total sobre as configurações do sistema.
        </p>

        <?php foreach ($grupos_admin as $nome_grupo => $cards): ?>
            <div class="admin-section">
                <div class="section-title"><?php echo htmlspecialchars($nome_grupo); ?></div>
                <div class="cards-grid">
                    <?php foreach ($cards as $card): ?>
                        <div class="admin-card" style="border-top-color: <?php echo $card['cor']; ?>;">
                            <div class="card-header">
                                <span class="card-icon"><?php echo $card['icone_emoji'] ?? $card['icone']; ?></span>
                                <h3 class="card-title"><?php echo htmlspecialchars($card['titulo']); ?></h3>
                            </div>
                            <div class="card-desc">
                                <?php echo htmlspecialchars($card['desc']); ?>
                            </div>
                            <button onclick="abrirModal('<?php echo $card['link']; ?>', '<?php echo htmlspecialchars($card['titulo']); ?>')" class="btn-access" style="cursor:pointer;">
                                Acessar Painel
                            </button>
                            </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </main>

    <div id="modalContainer" class="modal-overlay">
        <div class="modal-window">
            <div class="modal-header">
                <span id="modalTitle" class="modal-title">Título</span>
                <button onclick="fecharModal(true)" class="modal-close">&times;</button>
            </div>
            <iframe id="modalFrame" class="modal-content-frame" src=""></iframe>
        </div>
    </div>

    <script>
        function abrirModal(url, titulo) {
            document.getElementById('modalTitle').innerText = titulo;
            let separator = url.includes('?') ? '&' : '?';
            // Adiciona modal=1 para tentar esconder menus internos se a página suportar
            document.getElementById('modalFrame').src = url + separator + "modal=1";
            document.getElementById('modalContainer').classList.add('active');
        }

        function fecharModal(recarregar = false) {
            document.getElementById('modalContainer').classList.remove('active');
            document.getElementById('modalFrame').src = "";
            if(recarregar) {
                // Opcional: recarregar a página pai se necessário
                // window.location.reload(); 
            }
        }
        
        // Helper para o iframe fechar o modal
        window.fecharModalDoFilho = function(recarregar) {
            fecharModal(recarregar);
        }
    </script>

</body>
</html>