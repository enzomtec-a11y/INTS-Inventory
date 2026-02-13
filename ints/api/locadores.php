<?php
require_once '../config/_protecao.php';
header('Content-Type: application/json; charset=utf-8');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset('utf8mb4');

// GET - Buscar locador(es)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Buscar um locador específico
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM locadores WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $locador = $result->fetch_assoc();
        $stmt->close();
        
        if ($locador) {
            respond(['sucesso' => true, 'data' => $locador]);
        } else {
            respond(['sucesso' => false, 'mensagem' => 'Locador não encontrado'], 404);
        }
    }
    
    // Listar todos os locadores (com filtros opcionais)
    $ativo = isset($_GET['ativo']) ? (int)$_GET['ativo'] : null;
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    
    $sql = "SELECT l.*, 
            (SELECT COUNT(*) FROM contratos_locacao c WHERE c.locador_id = l.id) AS total_contratos,
            (SELECT COUNT(*) FROM contratos_locacao c WHERE c.locador_id = l.id AND c.status = 'ativo') AS contratos_ativos
            FROM locadores l
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (!is_null($ativo)) {
        $sql .= " AND l.ativo = ?";
        $params[] = $ativo;
        $types .= "i";
    }
    
    if ($busca) {
        $sql .= " AND (l.nome LIKE ? OR l.razao_social LIKE ? OR l.cnpj LIKE ? OR l.cpf LIKE ?)";
        $busca_like = "%$busca%";
        $params = array_merge($params, [$busca_like, $busca_like, $busca_like, $busca_like]);
        $types .= "ssss";
    }
    
    $sql .= " ORDER BY l.nome ASC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $locadores = [];
    while ($row = $result->fetch_assoc()) {
        $locadores[] = $row;
    }
    $stmt->close();
    
    respond(['sucesso' => true, 'data' => $locadores]);
}

// POST - Criar ou atualizar locador
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        $data = $_POST; // fallback para form-data
    }
    
    $action = $data['action'] ?? 'criar';
    
    if ($action === 'criar' || $action === 'atualizar') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $nome = trim($data['nome'] ?? '');
        $razao_social = trim($data['razao_social'] ?? '');
        $cnpj = trim($data['cnpj'] ?? '');
        $cpf = trim($data['cpf'] ?? '');
        $tipo_pessoa = $data['tipo_pessoa'] ?? 'juridica';
        $email = trim($data['email'] ?? '');
        $telefone = trim($data['telefone'] ?? '');
        $celular = trim($data['celular'] ?? '');
        $endereco = trim($data['endereco'] ?? '');
        $cidade = trim($data['cidade'] ?? '');
        $estado = trim($data['estado'] ?? '');
        $cep = trim($data['cep'] ?? '');
        $contato_responsavel = trim($data['contato_responsavel'] ?? '');
        $observacoes = trim($data['observacoes'] ?? '');
        $criado_por = getUsuarioId();
        
        if (empty($nome)) {
            respond(['sucesso' => false, 'mensagem' => 'Nome é obrigatório'], 400);
        }
        
        if ($action === 'criar') {
            $sql = "INSERT INTO locadores (nome, razao_social, cnpj, cpf, tipo_pessoa, email, telefone, celular, endereco, cidade, estado, cep, contato_responsavel, observacoes, criado_por) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssssssssi", $nome, $razao_social, $cnpj, $cpf, $tipo_pessoa, $email, $telefone, $celular, $endereco, $cidade, $estado, $cep, $contato_responsavel, $observacoes, $criado_por);
            
            if ($stmt->execute()) {
                $novo_id = $stmt->insert_id;
                $stmt->close();
                respond(['sucesso' => true, 'mensagem' => 'Locador criado com sucesso', 'id' => $novo_id]);
            } else {
                respond(['sucesso' => false, 'mensagem' => 'Erro ao criar locador: ' . $stmt->error], 500);
            }
        } else {
            if ($id <= 0) {
                respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
            }
            
            $sql = "UPDATE locadores SET nome = ?, razao_social = ?, cnpj = ?, cpf = ?, tipo_pessoa = ?, email = ?, telefone = ?, celular = ?, endereco = ?, cidade = ?, estado = ?, cep = ?, contato_responsavel = ?, observacoes = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssssssssi", $nome, $razao_social, $cnpj, $cpf, $tipo_pessoa, $email, $telefone, $celular, $endereco, $cidade, $estado, $cep, $contato_responsavel, $observacoes, $id);
            
            if ($stmt->execute()) {
                $stmt->close();
                respond(['sucesso' => true, 'mensagem' => 'Locador atualizado com sucesso']);
            } else {
                respond(['sucesso' => false, 'mensagem' => 'Erro ao atualizar locador: ' . $stmt->error], 500);
            }
        }
    }
    
    if ($action === 'desativar' || $action === 'ativar') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
        }
        
        $ativo = ($action === 'ativar') ? 1 : 0;
        $stmt = $conn->prepare("UPDATE locadores SET ativo = ? WHERE id = ?");
        $stmt->bind_param("ii", $ativo, $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            respond(['sucesso' => true, 'mensagem' => 'Status atualizado com sucesso']);
        } else {
            respond(['sucesso' => false, 'mensagem' => 'Erro ao atualizar status'], 500);
        }
    }
}

// DELETE - Deletar locador
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents("php://input"), $data);
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    
    if ($id <= 0) {
        respond(['sucesso' => false, 'mensagem' => 'ID inválido'], 400);
    }
    
    // Verificar se há contratos vinculados
    $stmt_check = $conn->prepare("SELECT COUNT(*) AS total FROM contratos_locacao WHERE locador_id = ?");
    $stmt_check->bind_param("i", $id);
    $stmt_check->execute();
    $result = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if ($result['total'] > 0) {
        respond(['sucesso' => false, 'mensagem' => 'Não é possível excluir locador com contratos vinculados. Desative-o em vez de excluir.'], 409);
    }
    
    $stmt = $conn->prepare("DELETE FROM locadores WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $stmt->close();
        respond(['sucesso' => true, 'mensagem' => 'Locador excluído com sucesso']);
    } else {
        respond(['sucesso' => false, 'mensagem' => 'Erro ao excluir locador'], 500);
    }
}

respond(['sucesso' => false, 'mensagem' => 'Método não suportado'], 405);
?>