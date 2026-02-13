<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset('utf8mb4');

// GET - Buscar contrato(s)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Buscar um contrato específico
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $sql = "SELECT c.*, l.nome AS locador_nome, l.cnpj, l.telefone AS locador_telefone
                FROM contratos_locacao c
                INNER JOIN locadores l ON c.locador_id = l.id
                WHERE c.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $contrato = $result->fetch_assoc();
        $stmt->close();
        
        if ($contrato) {
            respond(['sucesso' => true, 'data' => $contrato]);
        } else {
            respond(['sucesso' => false, 'mensagem' => 'Contrato não encontrado'], 404);
        }
    }
    
    // Listar contratos (com filtros opcionais)
    $locador_id = isset($_GET['locador_id']) ? (int)$_GET['locador_id'] : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $ativos_apenas = isset($_GET['ativos']) && $_GET['ativos'] === '1';
    
    $sql = "SELECT c.*, l.nome AS locador_nome,
            (SELECT COUNT(*) FROM produtos p WHERE p.contrato_locacao_id = c.id) AS total_produtos,
            DATEDIFF(c.data_fim, CURDATE()) AS dias_vencimento
            FROM contratos_locacao c
            INNER JOIN locadores l ON c.locador_id = l.id
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (!is_null($locador_id) && $locador_id > 0) {
        $sql .= " AND c.locador_id = ?";
        $params[] = $locador_id;
        $types .= "i";
    }
    
    if ($ativos_apenas) {
        $sql .= " AND c.status = 'ativo'";
    } elseif (!is_null($status)) {
        $sql .= " AND c.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($busca) {
        $sql .= " AND (c.numero_contrato LIKE ? OR c.descricao LIKE ?)";
        $busca_like = "%$busca%";
        $params[] = $busca_like;
        $params[] = $busca_like;
        $types .= "ss";
    }
    
    $sql .= " ORDER BY c.data_inicio DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $contratos = [];
    while ($row = $result->fetch_assoc()) {
        $contratos[] = $row;
    }
    $stmt->close();
    
    respond(['sucesso' => true, 'data' => $contratos]);
}

// POST - Criar ou atualizar contrato
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        $data = $_POST; // fallback para form-data
    }
    
    $action = $data['action'] ?? 'criar';
    
    if ($action === 'criar' || $action === 'atualizar') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $locador_id = (int)($data['locador_id'] ?? 0);
        $numero_contrato = trim($data['numero_contrato'] ?? '');
        $descricao = trim($data['descricao'] ?? '');
        $valor_mensal = !empty($data['valor_mensal']) ? (float)$data['valor_mensal'] : null;
        $valor_total = !empty($data['valor_total']) ? (float)$data['valor_total'] : null;
        $data_inicio = trim($data['data_inicio'] ?? '');
        $data_fim = !empty($data['data_fim']) ? trim($data['data_fim']) : null;
        $data_vencimento_pagamento = !empty($data['data_vencimento_pagamento']) ? (int)$data['data_vencimento_pagamento'] : null;
        $renovacao_automatica = isset($data['renovacao_automatica']) && ($data['renovacao_automatica'] == 1 || $data['renovacao_automatica'] === true) ? 1 : 0;
        $observacoes = trim($data['observacoes'] ?? '');
        $status = $data['status'] ?? 'ativo';
        $criado_por = getUsuarioId();
        
        if (empty($numero_contrato) || empty($data_inicio) || $locador_id <= 0) {
            respond(['sucesso' => false, 'mensagem' => 'Preencha os campos obrigatórios: locador, número do contrato e data de início'], 400);
        }
        
        if ($action === 'criar') {
            // Verificar se número do contrato já existe
            $stmt_check = $conn->prepare("SELECT id FROM contratos_locacao WHERE numero_contrato = ?");
            $stmt_check->bind_param("s", $numero_contrato);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $stmt_check->close();
                respond(['sucesso' => false, 'mensagem' => 'Já existe um contrato com este número'], 409);
            }
            $stmt_check->close();
            
            $sql = "INSERT INTO contratos_locacao (locador_id, numero_contrato, descricao, valor_mensal, valor_total, data_inicio, data_fim, data_vencimento_pagamento, renovacao_automatica, observacoes, status, criado_por) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $criado_por);
            
            if ($stmt->execute()) {
                $novo_id = $stmt->insert_id;
                $stmt->close();
                respond(['sucesso' => true, 'mensagem' => 'Contrato criado com sucesso', 'id' => $novo_id]);
            } else {
                respond(['sucesso' => false, 'mensagem' => 'Erro ao criar contrato: ' . $stmt->error], 500);
            }
        } else {
            if ($id <= 0) {
                respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
            }
            
            $sql = "UPDATE contratos_locacao SET locador_id = ?, numero_contrato = ?, descricao = ?, valor_mensal = ?, valor_total = ?, data_inicio = ?, data_fim = ?, data_vencimento_pagamento = ?, renovacao_automatica = ?, observacoes = ?, status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $id);
            
            if ($stmt->execute()) {
                $stmt->close();
                respond(['sucesso' => true, 'mensagem' => 'Contrato atualizado com sucesso']);
            } else {
                respond(['sucesso' => false, 'mensagem' => 'Erro ao atualizar contrato: ' . $stmt->error], 500);
            }
        }
    }
    
    if ($action === 'cancelar' || $action === 'suspender' || $action === 'reativar') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
        }
        
        $novo_status = 'ativo';
        if ($action === 'cancelar') $novo_status = 'cancelado';
        if ($action === 'suspender') $novo_status = 'suspenso';
        
        $stmt = $conn->prepare("UPDATE contratos_locacao SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $novo_status, $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            respond(['sucesso' => true, 'mensagem' => 'Status atualizado com sucesso']);
        } else {
            respond(['sucesso' => false, 'mensagem' => 'Erro ao atualizar status'], 500);
        }
    }
}

// DELETE - Deletar contrato
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents("php://input"), $data);
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    
    if ($id <= 0) {
        respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
    }
    
    // Verificar se há produtos vinculados
    $stmt_check = $conn->prepare("SELECT COUNT(*) AS total FROM produtos WHERE contrato_locacao_id = ?");
    $stmt_check->bind_param("i", $id);
    $stmt_check->execute();
    $result = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if ($result['total'] > 0) {
        respond(['sucesso' => false, 'mensagem' => 'Não é possível excluir contrato com produtos vinculados. Cancele-o em vez de excluir.'], 409);
    }
    
    $stmt = $conn->prepare("DELETE FROM contratos_locacao WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $stmt->close();
        respond(['sucesso' => true, 'mensagem' => 'Contrato excluído com sucesso']);
    } else {
        respond(['sucesso' => false, 'mensagem' => 'Erro ao excluir contrato'], 500);
    }
}

respond(['sucesso' => false, 'mensagem' => 'Método não suportado'], 405);
?>