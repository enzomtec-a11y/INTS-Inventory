<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respondJson($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respondJson(['sucesso'=>false,'mensagem'=>'Conexão com DB não encontrada.'], 500);
}
$conn->set_charset("utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Listar baixas com filtros
        $produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : null;
        $patrimonio_id = isset($_GET['patrimonio_id']) ? intval($_GET['patrimonio_id']) : null;
        $status = isset($_GET['status']) ? trim($_GET['status']) : null;
        $motivo = isset($_GET['motivo']) ? trim($_GET['motivo']) : null;
        $data_inicio = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : null;
        $data_fim = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : null;
        
        $where = [];
        $types = '';
        $params = [];
        
        if (!is_null($produto_id) && $produto_id > 0) { 
            $where[] = 'b.produto_id = ?'; 
            $types .= 'i'; 
            $params[] = $produto_id; 
        }
        if (!is_null($patrimonio_id) && $patrimonio_id > 0) { 
            $where[] = 'b.patrimonio_id = ?'; 
            $types .= 'i'; 
            $params[] = $patrimonio_id; 
        }
        if (!is_null($status) && $status !== '') { 
            $where[] = 'b.status = ?'; 
            $types .= 's'; 
            $params[] = $status; 
        }
        if (!is_null($motivo) && $motivo !== '') { 
            $where[] = 'b.motivo = ?'; 
            $types .= 's'; 
            $params[] = $motivo; 
        }
        if (!is_null($data_inicio) && $data_inicio !== '') { 
            $where[] = 'b.data_baixa >= ?'; 
            $types .= 's'; 
            $params[] = $data_inicio; 
        }
        if (!is_null($data_fim) && $data_fim !== '') { 
            $where[] = 'b.data_baixa <= ?'; 
            $types .= 's'; 
            $params[] = $data_fim; 
        }
        
        $sql = "SELECT b.*, 
                       p.nome AS produto_nome,
                       l.nome AS local_nome,
                       u.nome AS criado_por_nome,
                       r.nome AS responsavel_nome,
                       a.nome AS aprovador_nome,
                       pt.numero_patrimonio
                FROM baixas b
                LEFT JOIN produtos p ON b.produto_id = p.id
                LEFT JOIN locais l ON b.local_id = l.id
                LEFT JOIN usuarios u ON b.criado_por = u.id
                LEFT JOIN usuarios r ON b.responsavel_id = r.id
                LEFT JOIN usuarios a ON b.aprovador_id = a.id
                LEFT JOIN patrimonios pt ON b.patrimonio_id = pt.id";
        
        if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY b.data_criado DESC LIMIT 1000";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) throw new Exception("Erro ao preparar query: " . $conn->error);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        
        respondJson(['sucesso'=>true,'data'=>$rows]);
    }
    
    if ($method === 'POST') {
        $action = isset($_POST['action']) ? strtolower(trim($_POST['action'])) : 'criar';
        
        if ($action === 'criar') {
            // Criar nova baixa
            $produto_id = isset($_POST['produto_id']) ? intval($_POST['produto_id']) : 0;
            $patrimonio_id = isset($_POST['patrimonio_id']) && $_POST['patrimonio_id'] !== '' ? intval($_POST['patrimonio_id']) : null;
            $quantidade = isset($_POST['quantidade']) ? floatval($_POST['quantidade']) : 1;
            $local_id = isset($_POST['local_id']) && $_POST['local_id'] !== '' ? intval($_POST['local_id']) : null;
            $motivo = isset($_POST['motivo']) ? trim($_POST['motivo']) : '';
            $descricao = isset($_POST['descricao']) ? trim($_POST['descricao']) : '';
            $data_baixa = isset($_POST['data_baixa']) ? trim($_POST['data_baixa']) : date('Y-m-d');
            $valor_contabil = isset($_POST['valor_contabil']) && $_POST['valor_contabil'] !== '' ? floatval($_POST['valor_contabil']) : null;
            $responsavel_id = isset($_POST['responsavel_id']) && $_POST['responsavel_id'] !== '' ? intval($_POST['responsavel_id']) : null;
            $criado_por = isset($_POST['criado_por']) ? intval($_POST['criado_por']) : getUsuarioId();
            
            if ($produto_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'produto_id é obrigatório.'], 400);
            if (empty($motivo)) respondJson(['sucesso'=>false,'mensagem'=>'motivo é obrigatório.'], 400);
            if (empty($descricao)) respondJson(['sucesso'=>false,'mensagem'=>'descricao é obrigatória.'], 400);
            if ($quantidade <= 0) respondJson(['sucesso'=>false,'mensagem'=>'quantidade deve ser maior que zero.'], 400);
            
            $conn->begin_transaction();
            
            // Verificar se há estoque suficiente
            if (is_null($patrimonio_id)) {
                $stmt_check = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS total FROM estoques WHERE produto_id = ?" . ($local_id ? " AND local_id = ?" : ""));
                if ($local_id) {
                    $stmt_check->bind_param("ii", $produto_id, $local_id);
                } else {
                    $stmt_check->bind_param("i", $produto_id);
                }
                $stmt_check->execute();
                $check_res = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                
                if ($check_res['total'] < $quantidade) {
                    $conn->rollback();
                    respondJson(['sucesso'=>false,'mensagem'=>'Estoque insuficiente para baixa.','disponivel'=>$check_res['total']], 409);
                }
            }
            
            // Inserir baixa
            $stmt = $conn->prepare("INSERT INTO baixas (produto_id, patrimonio_id, quantidade, local_id, motivo, descricao, data_baixa, valor_contabil, responsavel_id, criado_por, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')");
            $stmt->bind_param("iidisssddii", $produto_id, $patrimonio_id, $quantidade, $local_id, $motivo, $descricao, $data_baixa, $valor_contabil, $responsavel_id, $criado_por);
            $stmt->execute();
            $baixa_id = $stmt->insert_id;
            $stmt->close();
            
            // Registrar log
            if (function_exists('registrarLog')) {
                $det = "Baixa registrada: Motivo: $motivo, Qtd: $quantidade";
                registrarLog($conn, $criado_por, 'baixas', $baixa_id, 'CRIACAO_BAIXA', $det, $produto_id);
            }
            
            $conn->commit();
            respondJson(['sucesso'=>true,'mensagem'=>'Baixa registrada com sucesso (pendente de aprovação).','baixa_id'=>$baixa_id]);
        }
        
        if ($action === 'aprovar') {
            $baixa_id = isset($_POST['baixa_id']) ? intval($_POST['baixa_id']) : 0;
            $aprovador_id = isset($_POST['aprovador_id']) ? intval($_POST['aprovador_id']) : getUsuarioId();
            
            if ($baixa_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'baixa_id é obrigatório.'], 400);
            
            $conn->begin_transaction();
            
            // Buscar dados da baixa
            $stmt = $conn->prepare("SELECT * FROM baixas WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $baixa_id);
            $stmt->execute();
            $baixa = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$baixa) {
                $conn->rollback();
                respondJson(['sucesso'=>false,'mensagem'=>'Baixa não encontrada.'], 404);
            }
            
            if ($baixa['status'] !== 'pendente') {
                $conn->rollback();
                respondJson(['sucesso'=>false,'mensagem'=>'Baixa já foi processada.','status_atual'=>$baixa['status']], 409);
            }
            
            // Dar baixa no estoque
            if (is_null($baixa['patrimonio_id'])) {
                // Baixa por quantidade
                $local_id_baixa = $baixa['local_id'];
                $quantidade_baixa = floatval($baixa['quantidade']);
                
                if ($local_id_baixa) {
                    // Baixa de local específico
                    $stmt_upd = $conn->prepare("UPDATE estoques SET quantidade = quantidade - ? WHERE produto_id = ? AND local_id = ?");
                    $stmt_upd->bind_param("dii", $quantidade_baixa, $baixa['produto_id'], $local_id_baixa);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                } else {
                    // Buscar primeiro local com estoque suficiente
                    $stmt_loc = $conn->prepare("SELECT local_id, quantidade FROM estoques WHERE produto_id = ? AND quantidade >= ? LIMIT 1");
                    $stmt_loc->bind_param("id", $baixa['produto_id'], $quantidade_baixa);
                    $stmt_loc->execute();
                    $loc_res = $stmt_loc->get_result()->fetch_assoc();
                    $stmt_loc->close();
                    
                    if ($loc_res) {
                        $stmt_upd = $conn->prepare("UPDATE estoques SET quantidade = quantidade - ? WHERE produto_id = ? AND local_id = ?");
                        $stmt_upd->bind_param("dii", $quantidade_baixa, $baixa['produto_id'], $loc_res['local_id']);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                    } else {
                        $conn->rollback();
                        respondJson(['sucesso'=>false,'mensagem'=>'Nenhum local com estoque suficiente.'], 409);
                    }
                }
            } else {
                // Baixa de patrimônio específico
                $stmt_patr = $conn->prepare("UPDATE patrimonios SET status = 'desativado' WHERE id = ?");
                $stmt_patr->bind_param("i", $baixa['patrimonio_id']);
                $stmt_patr->execute();
                $stmt_patr->close();
            }
            
            // Atualizar status da baixa
            $stmt_apr = $conn->prepare("UPDATE baixas SET status = 'aprovada', aprovador_id = ? WHERE id = ?");
            $stmt_apr->bind_param("ii", $aprovador_id, $baixa_id);
            $stmt_apr->execute();
            $stmt_apr->close();
            
            // Atualizar status do produto
            $stmt_prod = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS total FROM estoques WHERE produto_id = ?");
            $stmt_prod->bind_param("i", $baixa['produto_id']);
            $stmt_prod->execute();
            $total_res = $stmt_prod->get_result()->fetch_assoc();
            $stmt_prod->close();
            
            $novo_status = 'ativo';
            if ($total_res['total'] == 0) {
                $novo_status = 'baixa_total';
            } else {
                // Verificar se houve redução
                $novo_status = 'baixa_parcial';
            }
            
            $stmt_upd_prod = $conn->prepare("UPDATE produtos SET status_produto = ? WHERE id = ?");
            $stmt_upd_prod->bind_param("si", $novo_status, $baixa['produto_id']);
            $stmt_upd_prod->execute();
            $stmt_upd_prod->close();
            
            // Log
            if (function_exists('registrarLog')) {
                registrarLog($conn, $aprovador_id, 'baixas', $baixa_id, 'APROVACAO_BAIXA', "Baixa aprovada e estoque atualizado", $baixa['produto_id']);
            }
            
            $conn->commit();
            respondJson(['sucesso'=>true,'mensagem'=>'Baixa aprovada e processada com sucesso.']);
        }
        
        if ($action === 'rejeitar') {
            $baixa_id = isset($_POST['baixa_id']) ? intval($_POST['baixa_id']) : 0;
            $aprovador_id = isset($_POST['aprovador_id']) ? intval($_POST['aprovador_id']) : getUsuarioId();
            $motivo_rejeicao = isset($_POST['motivo_rejeicao']) ? trim($_POST['motivo_rejeicao']) : '';
            
            if ($baixa_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'baixa_id é obrigatório.'], 400);
            
            $stmt = $conn->prepare("UPDATE baixas SET status = 'rejeitada', aprovador_id = ?, descricao = CONCAT(descricao, '\n\nREJEITADO: ', ?) WHERE id = ? AND status = 'pendente'");
            $stmt->bind_param("isi", $aprovador_id, $motivo_rejeicao, $baixa_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected > 0) {
                if (function_exists('registrarLog')) {
                    registrarLog($conn, $aprovador_id, 'baixas', $baixa_id, 'REJEICAO_BAIXA', $motivo_rejeicao);
                }
                respondJson(['sucesso'=>true,'mensagem'=>'Baixa rejeitada.']);
            } else {
                respondJson(['sucesso'=>false,'mensagem'=>'Baixa não encontrada ou já processada.'], 404);
            }
        }
        
        respondJson(['sucesso'=>false,'mensagem'=>'Ação desconhecida.'], 400);
    }
    
    respondJson(['sucesso'=>false,'mensagem'=>'Método HTTP não suportado.'], 405);

} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>