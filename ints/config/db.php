<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ints_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexão falhou: " . $conn->connect_error);
}

function registrarLog($conn, $usuario_id, $tabela_afetada, $registro_id, $acao, $detalhes = null, $produto_id = null) {
    $sql = "INSERT INTO acoes_log (usuario_id, tabela_afetada, registro_id, acao, detalhes, produto_id) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isissi", $usuario_id, $tabela_afetada, $registro_id, $acao, $detalhes, $produto_id);
    $stmt->execute();
    $stmt->close();
}

function getIdsLocaisDaUnidade($conn, $unidade_id) {
    $ids = [(int)$unidade_id];
    
    // Query recursiva para pegar filhos, netos, etc (salas, andares)
    $sql = "
        WITH RECURSIVE local_tree AS (
            SELECT id, local_pai_id FROM locais WHERE id = ? AND deletado = FALSE
            UNION ALL
            SELECT l.id, l.local_pai_id 
            FROM locais l
            INNER JOIN local_tree lt ON l.local_pai_id = lt.id
            WHERE l.deletado = FALSE
        )
        SELECT id FROM local_tree;
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $unidade_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
    }
    return array_unique($ids);
}

// --- ATUALIZADA: getLocaisFormatados com filtro de unidade opcional ---
function getLocaisFormatados($conn, $apenasSalas = false, $restricaoUnidadeId = null) {
    $locais = [];
    $todos_locais = [];

    // Se houver restrição de unidade, buscamos apenas os locais permitidos
    $idsPermitidos = [];
    if ($restricaoUnidadeId) {
        $idsPermitidos = getIdsLocaisDaUnidade($conn, $restricaoUnidadeId);
        if (empty($idsPermitidos)) return []; // Unidade sem locais
        
        $idsStr = implode(',', $idsPermitidos);
        $sql = "SELECT id, nome, tipo_local, local_pai_id FROM locais WHERE id IN ($idsStr) AND deletado = FALSE";
    } else {
        $sql = "SELECT id, nome, tipo_local, local_pai_id FROM locais WHERE deletado = FALSE";
    }

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $todos_locais[$row['id']] = $row;
    }

    // Função interna para breadcrumb
    $montarCaminho = function($localId) use ($todos_locais, &$montarCaminho) {
        if (!isset($todos_locais[$localId])) return '...';
        $local = $todos_locais[$localId];
        if ($local['local_pai_id'] && isset($todos_locais[$local['local_pai_id']])) {
            return $montarCaminho($local['local_pai_id']) . " > " . $local['nome'];
        }
        return $local['nome'];
    };

    foreach ($todos_locais as $id => $local) {
        if ($apenasSalas && $local['tipo_local'] !== 'sala') continue;
        $locais[$id] = $montarCaminho($id);
    }
    asort($locais);
    return $locais;
}

function processarUploadArquivo($conn, $produtoId, $fileInfo, $tipoEnum) {
    // Verifica se houve upload sem erro
    if (!isset($fileInfo) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
        return; // Se não enviou nada, apenas ignora (opcional)
    }

    // Validação de Segurança (Extensões)
    $extensoesPermitidas = [
        'imagem' => ['jpg', 'jpeg', 'png', 'webp'],
        'manual' => ['pdf', 'doc', 'docx'],
        'nota_fiscal' => ['pdf', 'jpg', 'png', 'xml'],
        'outro' => ['jpg', 'png', 'pdf', 'doc', 'docx', 'txt', 'zip']
    ];

    $extensao = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extensao, $extensoesPermitidas[$tipoEnum] ?? [])) {
        throw new Exception("Extensão de arquivo '{$extensao}' não permitida para o tipo {$tipoEnum}.");
    }

    // Gerar nome único e caminho
    $novoNome = uniqid('file_') . '.' . $extensao;
    $pastaDestino = '../uploads/';
    $caminhoCompleto = $pastaDestino . $novoNome;

    // Mover o arquivo
    if (move_uploaded_file($fileInfo['tmp_name'], $caminhoCompleto)) {
        // Inserir no Banco de Dados
        $sql = "INSERT INTO arquivos (produto_id, tipo, caminho) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $produtoId, $tipoEnum, $caminhoCompleto);
        
        if (!$stmt->execute()) {
            // Se falhar no banco, tenta apagar o arquivo físico para não deixar lixo
            unlink($caminhoCompleto);
            throw new Exception("Erro ao salvar registro do arquivo no banco: " . $stmt->error);
        }
        $stmt->close();
    } else {
        throw new Exception("Falha ao mover o arquivo para a pasta de uploads.");
    }
}

function obterArvoreItensMovimentacao($conn, $produtoId, $quantidadePai) {
    $itensParaMover = [];

    // Adiciona o próprio produto Pai à lista (se ele controla estoque próprio)
    $sql_pai = "SELECT id, nome, controla_estoque_proprio FROM produtos WHERE id = ?";
    $stmt = $conn->prepare($sql_pai);
    $stmt->bind_param("i", $produtoId);
    $stmt->execute();
    $pai = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pai) {
        $itensParaMover[] = [
            'produto_id' => $pai['id'],
            'quantidade' => $quantidadePai,
            'nome' => $pai['nome'],
            'eh_pai' => true // Flag para identificar a origem
        ];
    }

    // Busca recursiva de componentes
    $buscarComponentes = function($paiId, $qtdMultiplicador) use ($conn, &$itensParaMover, &$buscarComponentes) {
        $sql = "
            SELECT pr.subproduto_id, pr.quantidade, p.nome 
            FROM produto_relacionamento pr
            JOIN produtos p ON pr.subproduto_id = p.id
            WHERE pr.produto_principal_id = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $paiId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $qtdTotalComponente = $row['quantidade'] * $qtdMultiplicador;
            
            // Adiciona o componente à lista final
            $itensParaMover[] = [
                'produto_id' => $row['subproduto_id'],
                'quantidade' => $qtdTotalComponente,
                'nome' => $row['nome'],
                'eh_pai' => false
            ];

            // Chama a função novamente para ver se este componente tem sub-componentes (Nível n)
            $buscarComponentes($row['subproduto_id'], $qtdTotalComponente);
        }
        $stmt->close();
    };

    // Inicia a busca recursiva
    $buscarComponentes($produtoId, $quantidadePai);

    return $itensParaMover;
}

$conn->set_charset("utf8");
?>