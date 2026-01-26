<?php
require_once '../../config/_protecao.php';

$usuario_id_logado = getUsuarioId();
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'comum';
$unidade_id_sessao = isset($_SESSION['unidade_id']) ? (int)$_SESSION['unidade_id'] : null;
$filtro_unidade = ($nivel_usuario === 'admin_unidade') ? $unidade_id_sessao : null;

// Helper IDs permitidos (todos os locais da unidade do admin_unidade)
$ids_permitidos = [];
if ($filtro_unidade) {
    $ids_permitidos = getIdsLocaisDaUnidade($conn, $filtro_unidade);
}

$status_message = "";

// --- HELPERS ---
function atualizarEstoque($conn, $produto_id, $local_id, $quantidade) {
    if ($local_id <= 0) return false;
    
    // Verifica se já existe registro para este produto no local
    $stmt = $conn->prepare("SELECT id, quantidade FROM estoques WHERE produto_id = ? AND local_id = ?");
    $stmt->bind_param("ii", $produto_id, $local_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        // Atualiza quantidade existente
        $stmt = $conn->prepare("UPDATE estoques SET quantidade = quantidade + ?, data_atualizado = NOW() WHERE produto_id = ? AND local_id = ?");
        $stmt->bind_param("dii", $quantidade, $produto_id, $local_id);
    } else {
        // Se a quantidade for positiva, insere novo registro
        if ($quantidade > 0) {
            $stmt = $conn->prepare("INSERT INTO estoques (produto_id, local_id, quantidade, data_atualizado) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iid", $produto_id, $local_id, $quantidade);
        } else {
            // Não insere estoque negativo
            return false;
        }
    }
    
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Retorna o id da unidade pai para um local (procura ascendente até encontrar tipo_local='unidade').
 * Retorna null se não encontrar.
 */
function getUnidadeDoLocal($conn, $local_id) {
    $cur = (int)$local_id;
    while ($cur > 0) {
        $stmt = $conn->prepare("SELECT id, tipo_local, local_pai_id FROM locais WHERE id = ? AND deletado = FALSE LIMIT 1");
        $stmt->bind_param("i", $cur);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) break;
        if ($row['tipo_local'] === 'unidade') return (int)$row['id'];
        if (empty($row['local_pai_id'])) break;
        $cur = (int)$row['local_pai_id'];
    }
    return null;
}

/**
 * Permissão para autorizar a saída (transforma pendente -> em_transito)
 * Regras:
 * - Movimentação interna (origem_unidade == destino_unidade): admin_unidade da unidade pode autorizar; admin global também.
 * - Movimentação entre unidades (origem_unidade != destino_unidade): somente admin global pode autorizar.
 */
function podeAutorizar($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade) {
    if ($orig_unidade && $dest_unidade && $orig_unidade === $dest_unidade) {
        // interna: admin_unidade da unidade pode autorizar
        if ($nivel_usuario === 'admin') return true;
        if ($nivel_usuario === 'admin_unidade' && $unidade_id_sessao === $orig_unidade) return true;
        return false;
    } else {
        // entre unidades: apenas admin global
        return ($nivel_usuario === 'admin');
    }
}

/**
 * Permissão para confirmar recebimento (transforma em_transito -> finalizado)
 * Regras:
 * - Movimentação interna (mesma unidade): admin_unidade da unidade pode confirmar; admin global também.
 * - Movimentação entre unidades: admin_unidade da unidade destino pode confirmar; admin global também.
 */
function podeConfirmarRecebimento($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade) {
    if ($orig_unidade && $dest_unidade && $orig_unidade === $dest_unidade) {
        if ($nivel_usuario === 'admin') return true;
        if ($nivel_usuario === 'admin_unidade' && $unidade_id_sessao === $dest_unidade) return true;
        return false;
    } else {
        // entre unidades: somente admin global ou admin_unidade da unidade destino
        if ($nivel_usuario === 'admin') return true;
        if ($nivel_usuario === 'admin_unidade' && $unidade_id_sessao === $dest_unidade) return true;
        return false;
    }
}

// --- LÓGICA DE AÇÕES (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao'], $_POST['mov_id'])) {
    $mov_id = (int)$_POST['mov_id'];
    $acao = $_POST['acao'];
    
    $conn->begin_transaction();
    try {
        // Busca movimentação
        $sql = "SELECT * FROM movimentacoes WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $mov_id);
        $stmt->execute();
        $mov = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$mov) throw new Exception("Movimentação não encontrada.");
        
        // Verificar se o produto controla estoque próprio
        $stmt_prod = $conn->prepare("SELECT controla_estoque_proprio FROM produtos WHERE id = ?");
        $stmt_prod->bind_param("i", $mov['produto_id']);
        $stmt_prod->execute();
        $produto = $stmt_prod->get_result()->fetch_assoc();
        $stmt_prod->close();
        
        $controla_estoque = $produto['controla_estoque_proprio'] ?? 1;

        // Determina unidades de origem e destino
        $orig_unidade = getUnidadeDoLocal($conn, $mov['local_origem_id']);
        $dest_unidade = getUnidadeDoLocal($conn, $mov['local_destino_id']);

        // Validação de Permissão de Unidade (visibilidade já garantida pelo filtro de listagem)
        if ($filtro_unidade) {
            $isOrigemOk = in_array($mov['local_origem_id'], $ids_permitidos);
            $isDestinoOk = in_array($mov['local_destino_id'], $ids_permitidos);
            
            // Regra geral: admin_unidade só gerencia movimentos que toquem sua unidade (origem ou destino)
            if (!$isOrigemOk && !$isDestinoOk) {
                throw new Exception("Esta movimentação não pertence à sua unidade.");
            }
        }
        
        // AÇÕES:
        if ($acao == 'autorizar' && $mov['status'] == 'pendente') {
            // Permission check: who can authorize?
            if (!podeAutorizar($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade)) {
                throw new Exception("Você não tem permissão para autorizar esta movimentação.");
            }

            // Autorizar a saída - apenas muda status
            $stmt = $conn->prepare("UPDATE movimentacoes SET status='em_transito', usuario_aprovacao_id=?, data_atualizacao=NOW() WHERE id=?");
            $stmt->bind_param("ii", $usuario_id_logado, $mov_id);
            $stmt->execute();
            $stmt->close();
            $status_message = "<div class='alert success'>Autorizado! O produto pode ser retirado.</div>";
            
            // Log
            if(function_exists('registrarLog')) registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'AUTORIZACAO', "Movimentação autorizada para retirada.", $mov['produto_id']);
        }
        elseif ($acao == 'receber' && $mov['status'] == 'em_transito') {
            // Permission: who can confirm receive?
            if (!podeConfirmarRecebimento($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade)) {
                throw new Exception("Você não tem permissão para confirmar o recebimento desta movimentação.");
            }

            // Receber - atualizar estoque e finalizar
            if ($controla_estoque == 1) {
                // Produto controla estoque próprio
                // 1. Atualizar estoque da origem (reduzir)
                // Antes de reduzir, verifica se existe saldo suficiente na origem
                $stmt_check = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
                $stmt_check->bind_param("ii", $mov['produto_id'], $mov['local_origem_id']);
                $stmt_check->execute();
                $row_check = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                $saldo_origem = $row_check['quantidade'] ?? 0;
                if ($saldo_origem < $mov['quantidade']) {
                    throw new Exception("Saldo insuficiente na origem. Saldo atual: {$saldo_origem}, necessário: {$mov['quantidade']}.");
                }

                $sucesso_origem = atualizarEstoque($conn, $mov['produto_id'], $mov['local_origem_id'], -$mov['quantidade']);
                if (!$sucesso_origem) {
                    throw new Exception("Erro ao atualizar estoque de origem.");
                }
                
                // 2. Atualizar estoque do destino (aumentar)
                $sucesso_destino = atualizarEstoque($conn, $mov['produto_id'], $mov['local_destino_id'], $mov['quantidade']);
                if (!$sucesso_destino) {
                    throw new Exception("Erro ao atualizar estoque de destino.");
                }
            } else {
                // Produto é kit - precisamos atualizar estoque dos componentes
                $sql_comps = "SELECT pr.subproduto_id, pr.quantidade as qtd_componente 
                              FROM produto_relacionamento pr 
                              WHERE pr.produto_principal_id = ?";
                $stmt_comps = $conn->prepare($sql_comps);
                $stmt_comps->bind_param("i", $mov['produto_id']);
                $stmt_comps->execute();
                $result_comps = $stmt_comps->get_result();
                
                while ($comp = $result_comps->fetch_assoc()) {
                    $qtd_total = $comp['qtd_componente'] * $mov['quantidade'];
                    
                    // Verifica saldo do componente na origem
                    $stmt_chk = $conn->prepare("SELECT quantidade FROM estoques WHERE produto_id = ? AND local_id = ? LIMIT 1");
                    $stmt_chk->bind_param("ii", $comp['subproduto_id'], $mov['local_origem_id']);
                    $stmt_chk->execute();
                    $rchk = $stmt_chk->get_result()->fetch_assoc();
                    $stmt_chk->close();
                    $saldo_comp = $rchk['quantidade'] ?? 0;
                    if ($saldo_comp < $qtd_total) {
                        throw new Exception("Saldo insuficiente do componente ID {$comp['subproduto_id']} na origem.");
                    }

                    // Reduzir da origem
                    $sucesso_origem = atualizarEstoque($conn, $comp['subproduto_id'], $mov['local_origem_id'], -$qtd_total);
                    if (!$sucesso_origem) {
                        throw new Exception("Erro ao atualizar estoque do componente ID {$comp['subproduto_id']} na origem.");
                    }
                    
                    // Aumentar no destino
                    $sucesso_destino = atualizarEstoque($conn, $comp['subproduto_id'], $mov['local_destino_id'], $qtd_total);
                    if (!$sucesso_destino) {
                        throw new Exception("Erro ao atualizar estoque do componente ID {$comp['subproduto_id']} no destino.");
                    }
                }
                $stmt_comps->close();
            }
            
            // 3. Atualizar status da movimentação
            $stmt = $conn->prepare("UPDATE movimentacoes SET status='finalizado', usuario_recebimento_id=?, data_atualizacao=NOW() WHERE id=?");
            $stmt->bind_param("ii", $usuario_id_logado, $mov_id);
            $stmt->execute();
            $stmt->close();
            
            $status_message = "<div class='alert success'>Recebido! Estoque atualizado com sucesso.</div>";
            
            // Log
            if(function_exists('registrarLog')) registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'RECEBIMENTO', "Movimentação recebida e estoque atualizado.", $mov['produto_id']);
        }
        elseif ($acao == 'cancelar' && $mov['status'] != 'finalizado') {
            // Cancelar movimentação: permitir se usuário tem visibilidade (origem ou destino na sua unidade) or admin
            if ($nivel_usuario === 'admin' || ($filtro_unidade && (in_array($mov['local_origem_id'], $ids_permitidos) || in_array($mov['local_destino_id'], $ids_permitidos)))) {
                $stmt = $conn->prepare("UPDATE movimentacoes SET status='cancelado', data_atualizacao=NOW() WHERE id=?");
                $stmt->bind_param("i", $mov_id);
                $stmt->execute();
                $stmt->close();
                $status_message = "<div class='alert success'>Movimentação cancelada!</div>";
                
                // Log
                if(function_exists('registrarLog')) registrarLog($conn, $usuario_id_logado, 'movimentacoes', $mov_id, 'CANCELAMENTO', "Movimentação cancelada.", $mov['produto_id']);
            } else {
                throw new Exception("Você não tem permissão para cancelar esta movimentação.");
            }
        } else {
            throw new Exception("Ação não permitida para o status atual.");
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $status_message = "<div class='alert error'>".$e->getMessage()."</div>";
    }
}

// --- LISTAGEM ---
// Aba: pendentes / transito / historico
$aba = $_GET['aba'] ?? 'pendentes';
$sql_lista = "
    SELECT m.*, p.nome as prod_nome, u.nome as user_nome, lo.nome as orig_nome, ld.nome as dest_nome
    FROM movimentacoes m
    JOIN produtos p ON m.produto_id = p.id
    JOIN usuarios u ON m.usuario_id = u.id
    JOIN locais lo ON m.local_origem_id = lo.id
    JOIN locais ld ON m.local_destino_id = ld.id
    WHERE 1=1
";

if ($aba == 'pendentes') $sql_lista .= " AND m.status = 'pendente'";
elseif ($aba == 'transito') $sql_lista .= " AND m.status = 'em_transito'";
else $sql_lista .= " AND m.status IN ('finalizado', 'cancelado')";

// Filtro SQL para listagem: admin_unidade só vê movimentações que toquem sua unidade (origem ou destino)
if ($filtro_unidade && !empty($ids_permitidos)) {
    $ids_str = implode(',', array_map('intval', $ids_permitidos));
    $sql_lista .= " AND (m.local_origem_id IN ($ids_str) OR m.local_destino_id IN ($ids_str)) ";
}

$sql_lista .= " ORDER BY m.data_movimentacao DESC";
$res = $conn->query($sql_lista);
?>
<!DOCTYPE html>
<html>
<head>
<title>Gerenciar Movimentações</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
    .container { max-width: 1200px; margin: 20px auto; padding: 20px; }
    .tabs { margin-bottom: 20px; border-bottom: 1px solid #ddd; }
    .tabs a { 
        padding: 10px 20px; 
        display: inline-block; 
        border:1px solid #ddd; 
        margin-right:5px; 
        text-decoration:none; 
        color:#333; 
        background: #f5f5f5;
        border-bottom: none;
    }
    .tabs a.active { 
        background:#fff; 
        font-weight:bold; 
        border-bottom: 2px solid #007bff;
    }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    td, th { border:1px solid #ccc; padding:8px; text-align: left; }
    .alert { padding:10px; margin-bottom:10px; border-radius:4px; } 
    .success{background:#d4edda; color:#155724; border:1px solid #c3e6cb;} 
    .error{background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;}
    .status-pendente { color: #ffc107; font-weight: bold; }
    .status-em_transito { color: #17a2b8; font-weight: bold; }
    .status-finalizado { color: #28a745; font-weight: bold; }
    .status-cancelado { color: #dc3545; font-weight: bold; }
    .btn { 
        padding: 6px 12px; 
        margin: 2px; 
        border: none; 
        border-radius: 4px; 
        cursor: pointer; 
        text-decoration: none;
        display: inline-block;
    }
    .btn-success { background: #28a745; color: white; }
    .btn-primary { background: #007bff; color: white; }
    .btn-danger { background: #dc3545; color: white; }
    form { display: inline; }
</style>
</head>
<body>
<div class="container">
    <h1>Gerenciar Movimentações</h1>
    <?php echo $status_message; ?>
    <div class="tabs">
        <a href="?aba=pendentes" class="<?php echo $aba=='pendentes'?'active':'';?>">Pendentes de Aprovação</a>
        <a href="?aba=transito" class="<?php echo $aba=='transito'?'active':'';?>">Em Trânsito</a>
        <a href="?aba=historico" class="<?php echo $aba=='historico'?'active':'';?>">Histórico</a>
    </div>
    <table>
        <tr>
            <th>ID</th>
            <th>Data</th>
            <th>Produto</th>
            <th>Quantidade</th>
            <th>Origem → Destino</th>
            <th>Solicitante</th>
            <th>Status</th>
            <th>Ações</th>
        </tr>
        <?php if ($res->num_rows == 0): ?>
        <tr>
            <td colspan="8" style="text-align:center; padding:20px;">
                Nenhuma movimentação encontrada nesta categoria.
            </td>
        </tr>
        <?php else: ?>
        <?php while($row = $res->fetch_assoc()):
            // Determina unidades desta movimentação (para renderizar quais ações estão disponíveis)
            $orig_unidade = getUnidadeDoLocal($conn, $row['local_origem_id']);
            $dest_unidade = getUnidadeDoLocal($conn, $row['local_destino_id']);
            $can_authorize = ($row['status'] == 'pendente') && podeAutorizar($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade);
            $can_receive = ($row['status'] == 'em_transito') && podeConfirmarRecebimento($nivel_usuario, $unidade_id_sessao, $orig_unidade, $dest_unidade);
            $can_cancel = ($row['status'] != 'finalizado') && ($nivel_usuario === 'admin' || ($filtro_unidade && (in_array($row['local_origem_id'], $ids_permitidos) || in_array($row['local_destino_id'], $ids_permitidos))));
            $status_class = 'status-' . $row['status'];
        ?>
        <tr>
            <td><?php echo $row['id']; ?></td>
            <td><?php echo date('d/m/Y H:i', strtotime($row['data_movimentacao'])); ?></td>
            <td><?php echo htmlspecialchars($row['prod_nome']); ?></td>
            <td><strong><?php echo $row['quantidade']; ?></strong></td>
            <td>
                <strong>De:</strong> <?php echo htmlspecialchars($row['orig_nome']); ?><br>
                <strong>Para:</strong> <?php echo htmlspecialchars($row['dest_nome']); ?>
                <div style="font-size:0.85em; color:#666;">
                    <?php
                        // Indica se é entre unidades diferentes (útil para o usuário entender)
                        if ($orig_unidade && $dest_unidade && $orig_unidade !== $dest_unidade) {
                            echo "<small>Movimento entre unidades</small>";
                        } elseif ($orig_unidade) {
                            echo "<small>Unidade: " . htmlspecialchars($orig_unidade) . "</small>";
                        }
                    ?>
                </div>
            </td>
            <td><?php echo htmlspecialchars($row['user_nome']); ?></td>
            <td class="<?php echo $status_class; ?>">
                <?php 
                $status_text = '';
                switch($row['status']) {
                    case 'pendente': $status_text = 'Pendente'; break;
                    case 'em_transito': $status_text = 'Em Trânsito'; break;
                    case 'finalizado': $status_text = 'Finalizado'; break;
                    case 'cancelado': $status_text = 'Cancelado'; break;
                    default: $status_text = $row['status'];
                }
                echo $status_text;
                
                if ($row['usuario_aprovacao_id'] && $row['status'] != 'pendente') {
                    echo '<br><small>Aprovado por: ' . $row['usuario_aprovacao_id'] . '</small>';
                }
                if ($row['usuario_recebimento_id'] && $row['status'] == 'finalizado') {
                    echo '<br><small>Recebido por: ' . $row['usuario_recebimento_id'] . '</small>';
                }
                ?>
            </td>
            <td>
                <?php if ($can_authorize): ?>
                    <form method="POST" onsubmit="return confirm('Autorizar a saída deste item?');">
                        <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                        <button type="submit" name="acao" value="autorizar" class="btn btn-success">Autorizar Saída</button>
                    </form>
                <?php endif; ?>

                <?php if ($can_receive): ?>
                    <form method="POST" onsubmit="return confirm('Confirmar recebimento deste item? O estoque será atualizado.');">
                        <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                        <button type="submit" name="acao" value="receber" class="btn btn-primary">Confirmar Recebimento</button>
                    </form>
                <?php endif; ?>

                <?php if ($can_cancel): ?>
                    <form method="POST" onsubmit="return confirm('Cancelar esta movimentação?');">
                        <input type="hidden" name="mov_id" value="<?php echo $row['id']; ?>">
                        <button type="submit" name="acao" value="cancelar" class="btn btn-danger">Cancelar</button>
                    </form>
                <?php endif; ?>

                <?php if (!$can_authorize && !$can_receive && !$can_cancel): ?>
                    <span style="color: #777;">Nenhuma ação disponível</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
        <?php endif; ?>
    </table>
    <div style="margin-top: 20px;">
        <a href="../../index.html" class="btn">Página Inicial</a>
        <a href="solicitar.php" class="btn btn-success">Nova Solicitação</a>
    </div>
</div>
</body>
</html>