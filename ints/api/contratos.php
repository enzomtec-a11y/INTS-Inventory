<?php
require_once '../config/_protecao.php';

header('Content-Type: application/json');

// GET - Buscar contratos
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // Buscar contrato específico por ID
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM contratos_locacao WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode([
                'sucesso' => true,
                'data' => $result->fetch_assoc()
            ]);
        } else {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Contrato não encontrado'
            ]);
        }
        $stmt->close();
        exit;
    }
    
    // Buscar contratos por locador
    if (isset($_GET['locador_id'])) {
        $locador_id = (int)$_GET['locador_id'];
        
        if ($locador_id <= 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Locador inválido']);
            exit;
        }
        
        // Buscar contratos ativos do locador
        $sql = "SELECT id, numero_contrato, descricao, data_inicio, data_fim, status, valor_mensal, valor_total
                FROM contratos_locacao
                WHERE locador_id = ? AND status = 'ativo'
                ORDER BY numero_contrato";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $locador_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $contratos = [];
        while ($row = $result->fetch_assoc()) {
            $contratos[] = $row;
        }
        
        $stmt->close();
        
        echo json_encode([
            'sucesso' => true,
            'contratos' => $contratos
        ]);
        exit;
    }
    
    // Buscar todos os contratos (com filtros opcionais)
    $sql = "SELECT c.*, l.nome AS locador_nome
            FROM contratos_locacao c
            INNER JOIN locadores l ON c.locador_id = l.id
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (isset($_GET['status']) && $_GET['status'] !== 'todos') {
        $sql .= " AND c.status = ?";
        $params[] = $_GET['status'];
        $types .= "s";
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
    
    echo json_encode([
        'sucesso' => true,
        'contratos' => $contratos
    ]);
    exit;
}

// POST - Criar ou atualizar contrato
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'criar' || $action === 'editar') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $locador_id = (int)$_POST['locador_id'];
        $numero_contrato = trim($_POST['numero_contrato']);
        $descricao = trim($_POST['descricao'] ?? '');
        $valor_mensal = !empty($_POST['valor_mensal']) ? (float)$_POST['valor_mensal'] : null;
        $valor_total = !empty($_POST['valor_total']) ? (float)$_POST['valor_total'] : null;
        $data_inicio = trim($_POST['data_inicio']);
        $data_fim = !empty($_POST['data_fim']) ? trim($_POST['data_fim']) : null;
        $data_vencimento_pagamento = !empty($_POST['data_vencimento_pagamento']) ? (int)$_POST['data_vencimento_pagamento'] : null;
        $renovacao_automatica = isset($_POST['renovacao_automatica']) ? 1 : 0;
        $observacoes = trim($_POST['observacoes'] ?? '');
        $status = $_POST['status'] ?? 'ativo';
        $criado_por = getUsuarioId();
        
        if (empty($numero_contrato) || empty($data_inicio) || $locador_id <= 0) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Preencha os campos obrigatórios: locador, número do contrato e data de início'
            ]);
            exit;
        }
        
        if ($action === 'criar') {
            $sql = "INSERT INTO contratos_locacao (locador_id, numero_contrato, descricao, valor_mensal, valor_total, data_inicio, data_fim, data_vencimento_pagamento, renovacao_automatica, observacoes, status, criado_por) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $criado_por);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'sucesso' => true,
                    'mensagem' => 'Contrato cadastrado com sucesso!',
                    'id' => $stmt->insert_id
                ]);
            } else {
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Erro ao cadastrar contrato: ' . $stmt->error
                ]);
            }
            $stmt->close();
        } else {
            $sql = "UPDATE contratos_locacao SET locador_id = ?, numero_contrato = ?, descricao = ?, valor_mensal = ?, valor_total = ?, data_inicio = ?, data_fim = ?, data_vencimento_pagamento = ?, renovacao_automatica = ?, observacoes = ?, status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'sucesso' => true,
                    'mensagem' => 'Contrato atualizado com sucesso!'
                ]);
            } else {
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Erro ao atualizar contrato: ' . $stmt->error
                ]);
            }
            $stmt->close();
        }
    } elseif ($action === 'cancelar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE contratos_locacao SET status = 'cancelado' WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Contrato cancelado com sucesso!'
            ]);
        } else {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Erro ao cancelar contrato'
            ]);
        }
        $stmt->close();
    }
    
    exit;
}

$conn->close();
echo json_encode(['sucesso' => false, 'mensagem' => 'Método não suportado']);