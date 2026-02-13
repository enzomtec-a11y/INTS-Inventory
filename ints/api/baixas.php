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
        $local_id = isset($_GET['local_id']) ? intval($_GET['local_id']) : null;
        
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
        if (!is_null($local_id) && $local_id > 0) {
            $where[] = 'b.local_id = ?';
            $types .= 'i';
            $params[] = $local_id;
        }
        
        $sql = "SELECT b.*, 
                       p.nome AS produto_nome,
                       p.codigo AS produto_codigo,
                       p.categoria AS produto_categoria,
                       p.status_produto,
                       l.nome AS local_nome,
                       u.nome AS criado_por_nome,
                       r.nome AS responsavel_nome,
                       a.nome AS aprovador_nome,
                       pt.numero_patrimonio,
                       pt.status AS patrimonio_status,
                       loc.nome AS locador_nome,
                       loc.cpf_cnpj AS locador_documento
                FROM baixas b
                LEFT JOIN produtos p ON b.produto_id = p.id
                LEFT JOIN locais l ON b.local_id = l.id
                LEFT JOIN usuarios u ON b.criado_por = u.id
                LEFT JOIN usuarios r ON b.responsavel_id = r.id
                LEFT JOIN usuarios a ON b.aprovador_id = a.id
                LEFT JOIN patrimonios pt ON b.patrimonio_id = pt.id
                LEFT JOIN locadores loc ON p.locador_id = loc.id";
        
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
            $valor_contabil = isset($_POST['valor_contabil']) && $_POST['valor_contabil'] !== '' ? floatval($_POST['valor_contabil']) : 0;
            $responsavel_id = isset($_POST['responsavel_id']) && $_POST['responsavel_id'] !== '' ? intval($_POST['responsavel_id']) : null;
            $criado_por = isset($_POST['criado_por']) ? intval($_POST['criado_por']) : getUsuarioId();
            
            // Validações
            if ($produto_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'produto_id é obrigatório.'], 400);
            if (empty($motivo)) respondJson(['sucesso'=>false,'mensagem'=>'motivo é obrigatório.'], 400);
            if (empty($descricao)) respondJson(['sucesso'=>false,'mensagem'=>'descrição é obrigatória.'], 400);
            if ($quantidade <= 0) respondJson(['sucesso'=>false,'mensagem'=>'quantidade deve ser maior que zero.'], 400);
            
            $conn->begin_transaction();
            
            // Verificar se há estoque suficiente (apenas se não for patrimônio específico)
            if (is_null($patrimonio_id)) {
                $where_estoque = "produto_id = ?";
                $types_estoque = "i";
                $params_estoque = [$produto_id];
                
                if ($local_id) {
                    $where_estoque .= " AND local_id = ?";
                    $types_estoque .= "i";
                    $params_estoque[] = $local_id;
                }
                
                $stmt_check = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS total FROM estoques WHERE " . $where_estoque);
                $stmt_check->bind_param($types_estoque, ...$params_estoque);
                $stmt_check->execute();
                $check_res = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                
                if ($check_res['total'] < $quantidade) {
                    $conn->rollback();
                    respondJson(['sucesso'=>false,'mensagem'=>'Estoque insuficiente para baixa.','disponivel'=>$check_res['total']], 409);
                }
            } else {
                // Verificar se o patrimônio existe e está ativo
                $stmt_patr = $conn->prepare("SELECT id, status FROM patrimonios WHERE id = ?");
                $stmt_patr->bind_param("i", $patrimonio_id);
                $stmt_patr->execute();
                $patr_res = $stmt_patr->get_result()->fetch_assoc();
                $stmt_patr->close();
                
                if (!$patr_res) {
                    $conn->rollback();
                    respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não encontrado.'], 404);
                }
                
                if ($patr_res['status'] !== 'ativo') {
                    $conn->rollback();
                    respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não está ativo. Status atual: ' . $patr_res['status']], 409);
                }
            }
            
            // Inserir baixa - CORREÇÃO: ajustar bind_param para lidar com NULLs corretamente
            $sql_insert = "INSERT INTO baixas (
                produto_id, patrimonio_id, quantidade, local_id, motivo, descricao, 
                data_baixa, valor_contabil, responsavel_id, criado_por, status, data_criado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', NOW())";
            
            $stmt = $conn->prepare($sql_insert);
            if (!$stmt) {
                $conn->rollback();
                throw new Exception("Erro ao preparar INSERT: " . $conn->error);
            }
            
            // Bind correto: i=integer, d=double, s=string
            // produto_id, patrimonio_id, quantidade, local_id, motivo, descricao, data_baixa, valor_contabil, responsavel_id, criado_por
            $stmt->bind_param(
                "iidisssdii", 
                $produto_id, 
                $patrimonio_id, 
                $quantidade, 
                $local_id, 
                $motivo, 
                $descricao, 
                $data_baixa, 
                $valor_contabil, 
                $responsavel_id, 
                $criado_por
            );
            
            if (!$stmt->execute()) {
                $conn->rollback();
                throw new Exception("Erro ao executar INSERT: " . $stmt->error);
            }
            
            $baixa_id = $stmt->insert_id;
            $stmt->close();
            
            // Registrar log
            if (function_exists('registrarLog')) {
                $det = "Baixa registrada: Motivo: $motivo, Qtd: $quantidade" . 
                       ($patrimonio_id ? ", Patrimônio: $patrimonio_id" : "") .
                       ($local_id ? ", Local: $local_id" : "");
                registrarLog($conn, $criado_por, 'baixas', $baixa_id, 'CRIACAO_BAIXA', $det, $produto_id);
            }
            
            $conn->commit();
            respondJson([
                'sucesso'=>true,
                'mensagem'=>'Baixa registrada com sucesso (pendente de aprovação).',
                'baixa_id'=>$baixa_id
            ]);
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
                    
                    if ($stmt_upd->affected_rows === 0) {
                        $conn->rollback();
                        $stmt_upd->close();
                        respondJson(['sucesso'=>false,'mensagem'=>'Nenhum estoque encontrado no local especificado.'], 404);
                    }
                    $stmt_upd->close();
                    
                    // Remover registros com quantidade zero ou negativa
                    $stmt_clean = $conn->prepare("DELETE FROM estoques WHERE produto_id = ? AND local_id = ? AND quantidade <= 0");
                    $stmt_clean->bind_param("ii", $baixa['produto_id'], $local_id_baixa);
                    $stmt_clean->execute();
                    $stmt_clean->close();
                    
                } else {
                    // Buscar primeiro local com estoque suficiente
                    $stmt_loc = $conn->prepare("SELECT local_id, quantidade FROM estoques WHERE produto_id = ? AND quantidade >= ? ORDER BY quantidade DESC LIMIT 1");
                    $stmt_loc->bind_param("id", $baixa['produto_id'], $quantidade_baixa);
                    $stmt_loc->execute();
                    $loc_res = $stmt_loc->get_result()->fetch_assoc();
                    $stmt_loc->close();
                    
                    if ($loc_res) {
                        $stmt_upd = $conn->prepare("UPDATE estoques SET quantidade = quantidade - ? WHERE produto_id = ? AND local_id = ?");
                        $stmt_upd->bind_param("dii", $quantidade_baixa, $baixa['produto_id'], $loc_res['local_id']);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                        
                        // Atualizar local_id na baixa
                        $stmt_upd_local = $conn->prepare("UPDATE baixas SET local_id = ? WHERE id = ?");
                        $stmt_upd_local->bind_param("ii", $loc_res['local_id'], $baixa_id);
                        $stmt_upd_local->execute();
                        $stmt_upd_local->close();
                        
                        // Remover registros com quantidade zero ou negativa
                        $stmt_clean = $conn->prepare("DELETE FROM estoques WHERE produto_id = ? AND local_id = ? AND quantidade <= 0");
                        $stmt_clean->bind_param("ii", $baixa['produto_id'], $loc_res['local_id']);
                        $stmt_clean->execute();
                        $stmt_clean->close();
                    } else {
                        $conn->rollback();
                        respondJson(['sucesso'=>false,'mensagem'=>'Nenhum local com estoque suficiente.'], 409);
                    }
                }
            } else {
                // Baixa de patrimônio específico
                $data_baixa_patr = $baixa['data_baixa'];
                $stmt_patr = $conn->prepare("UPDATE patrimonios SET status = 'baixado', data_baixa = ? WHERE id = ?");
                $stmt_patr->bind_param("si", $data_baixa_patr, $baixa['patrimonio_id']);
                $stmt_patr->execute();
                
                if ($stmt_patr->affected_rows === 0) {
                    $conn->rollback();
                    $stmt_patr->close();
                    respondJson(['sucesso'=>false,'mensagem'=>'Patrimônio não encontrado ou já baixado.'], 404);
                }
                $stmt_patr->close();
                
                // Registrar movimentação de patrimônio
                if (function_exists('registrarMovimentacaoPatrimonio')) {
                    registrarMovimentacaoPatrimonio(
                        $conn, 
                        $baixa['patrimonio_id'], 
                        'baixa', 
                        null, 
                        null, 
                        $aprovador_id, 
                        "Baixa aprovada - " . $baixa['motivo']
                    );
                }
            }
            
            // Atualizar status da baixa
            $data_aprovacao = date('Y-m-d H:i:s');
            $stmt_apr = $conn->prepare("UPDATE baixas SET status = 'aprovada', aprovador_id = ?, data_aprovacao = ? WHERE id = ?");
            $stmt_apr->bind_param("isi", $aprovador_id, $data_aprovacao, $baixa_id);
            $stmt_apr->execute();
            $stmt_apr->close();
            
            // Atualizar status do produto
            $stmt_prod = $conn->prepare("SELECT COALESCE(SUM(quantidade),0) AS total FROM estoques WHERE produto_id = ?");
            $stmt_prod->bind_param("i", $baixa['produto_id']);
            $stmt_prod->execute();
            $total_res = $stmt_prod->get_result()->fetch_assoc();
            $stmt_prod->close();
            
            // Verificar patrimônios ativos
            $stmt_patr_count = $conn->prepare("SELECT COUNT(*) AS total FROM patrimonios WHERE produto_id = ? AND status = 'ativo'");
            $stmt_patr_count->bind_param("i", $baixa['produto_id']);
            $stmt_patr_count->execute();
            $patr_count = $stmt_patr_count->get_result()->fetch_assoc();
            $stmt_patr_count->close();
            
            $novo_status = 'ativo';
            if ($total_res['total'] == 0 && $patr_count['total'] == 0) {
                $novo_status = 'baixa_total';
            } else if ($total_res['total'] > 0 || $patr_count['total'] > 0) {
                // Verificar se houve alguma baixa
                $stmt_verif = $conn->prepare("SELECT COUNT(*) AS total FROM baixas WHERE produto_id = ? AND status = 'aprovada'");
                $stmt_verif->bind_param("i", $baixa['produto_id']);
                $stmt_verif->execute();
                $verif_res = $stmt_verif->get_result()->fetch_assoc();
                $stmt_verif->close();
                
                if ($verif_res['total'] > 0) {
                    $novo_status = 'baixa_parcial';
                }
            }
            
            $stmt_upd_prod = $conn->prepare("UPDATE produtos SET status_produto = ? WHERE id = ?");
            $stmt_upd_prod->bind_param("si", $novo_status, $baixa['produto_id']);
            $stmt_upd_prod->execute();
            $stmt_upd_prod->close();
            
            // Log
            if (function_exists('registrarLog')) {
                $det_log = "Baixa aprovada e estoque atualizado. Status do produto: $novo_status";
                registrarLog($conn, $aprovador_id, 'baixas', $baixa_id, 'APROVACAO_BAIXA', $det_log, $baixa['produto_id']);
            }
            
            $conn->commit();
            respondJson([
                'sucesso'=>true,
                'mensagem'=>'Baixa aprovada e processada com sucesso.',
                'novo_status_produto'=>$novo_status
            ]);
        }
        
        if ($action === 'rejeitar') {
            $baixa_id = isset($_POST['baixa_id']) ? intval($_POST['baixa_id']) : 0;
            $aprovador_id = isset($_POST['aprovador_id']) ? intval($_POST['aprovador_id']) : getUsuarioId();
            $motivo_rejeicao = isset($_POST['motivo_rejeicao']) ? trim($_POST['motivo_rejeicao']) : '';
            
            if ($baixa_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'baixa_id é obrigatório.'], 400);
            if (empty($motivo_rejeicao)) respondJson(['sucesso'=>false,'mensagem'=>'motivo_rejeicao é obrigatório.'], 400);
            
            $data_rejeicao = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE baixas SET status = 'rejeitada', aprovador_id = ?, data_aprovacao = ?, observacoes = CONCAT(COALESCE(observacoes,''), '\n\nREJEITADO em ', ?, ': ', ?) WHERE id = ? AND status = 'pendente'");
            $stmt->bind_param("isssi", $aprovador_id, $data_rejeicao, $data_rejeicao, $motivo_rejeicao, $baixa_id);
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
        
        if ($action === 'cancelar') {
            // Cancelar baixa pendente (apenas quem criou ou admin)
            $baixa_id = isset($_POST['baixa_id']) ? intval($_POST['baixa_id']) : 0;
            $usuario_id = getUsuarioId();
            
            if ($baixa_id <= 0) respondJson(['sucesso'=>false,'mensagem'=>'baixa_id é obrigatório.'], 400);
            
            // Verificar permissão
            $stmt_verif = $conn->prepare("SELECT criado_por FROM baixas WHERE id = ? AND status = 'pendente'");
            $stmt_verif->bind_param("i", $baixa_id);
            $stmt_verif->execute();
            $verif = $stmt_verif->get_result()->fetch_assoc();
            $stmt_verif->close();
            
            if (!$verif) {
                respondJson(['sucesso'=>false,'mensagem'=>'Baixa não encontrada ou já processada.'], 404);
            }
            
            $usuario_nivel = $_SESSION['usuario_nivel'] ?? '';
            if ($verif['criado_por'] != $usuario_id && !in_array($usuario_nivel, ['admin', 'admin_unidade'])) {
                respondJson(['sucesso'=>false,'mensagem'=>'Sem permissão para cancelar esta baixa.'], 403);
            }
            
            $stmt = $conn->prepare("UPDATE baixas SET status = 'cancelada' WHERE id = ?");
            $stmt->bind_param("i", $baixa_id);
            $stmt->execute();
            $stmt->close();
            
            if (function_exists('registrarLog')) {
                registrarLog($conn, $usuario_id, 'baixas', $baixa_id, 'CANCELAMENTO_BAIXA', 'Baixa cancelada pelo usuário');
            }
            
            respondJson(['sucesso'=>true,'mensagem'=>'Baixa cancelada.']);
        }
        
        respondJson(['sucesso'=>false,'mensagem'=>'Ação desconhecida.'], 400);
    }
    
    respondJson(['sucesso'=>false,'mensagem'=>'Método HTTP não suportado.'], 405);

} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    
    // Log do erro para debug
    error_log("Erro em baixas.php: " . $e->getMessage() . " | Linha: " . $e->getLine());
    
    respondJson(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()], 500);
}
?>