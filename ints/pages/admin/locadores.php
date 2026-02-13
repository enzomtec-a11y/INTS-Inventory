<?php
require_once '../../config/_protecao.php';
exigirAdmin(); // Apenas admins podem gerenciar locadores

$msg = '';
$msgClass = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'criar' || $action === 'editar') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $nome = trim($_POST['nome']);
        $razao_social = trim($_POST['razao_social'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $cpf = trim($_POST['cpf'] ?? '');
        $tipo_pessoa = $_POST['tipo_pessoa'] ?? 'juridica';
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $contato_responsavel = trim($_POST['contato_responsavel'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $criado_por = getUsuarioId();
        
        if (empty($nome)) {
            $msgClass = 'error';
            $msg = 'Nome do locador é obrigatório.';
        } else {
            if ($action === 'criar') {
                $sql = "INSERT INTO locadores (nome, razao_social, cnpj, cpf, tipo_pessoa, email, telefone, celular, endereco, cidade, estado, cep, contato_responsavel, observacoes, criado_por) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssssssi", $nome, $razao_social, $cnpj, $cpf, $tipo_pessoa, $email, $telefone, $celular, $endereco, $cidade, $estado, $cep, $contato_responsavel, $observacoes, $criado_por);
                
                if ($stmt->execute()) {
                    $msgClass = 'success';
                    $msg = 'Locador cadastrado com sucesso!';
                } else {
                    $msgClass = 'error';
                    $msg = 'Erro ao cadastrar locador: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $sql = "UPDATE locadores SET nome = ?, razao_social = ?, cnpj = ?, cpf = ?, tipo_pessoa = ?, email = ?, telefone = ?, celular = ?, endereco = ?, cidade = ?, estado = ?, cep = ?, contato_responsavel = ?, observacoes = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssssssi", $nome, $razao_social, $cnpj, $cpf, $tipo_pessoa, $email, $telefone, $celular, $endereco, $cidade, $estado, $cep, $contato_responsavel, $observacoes, $id);
                
                if ($stmt->execute()) {
                    $msgClass = 'success';
                    $msg = 'Locador atualizado com sucesso!';
                } else {
                    $msgClass = 'error';
                    $msg = 'Erro ao atualizar locador: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'desativar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE locadores SET ativo = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $msgClass = 'success';
            $msg = 'Locador desativado com sucesso!';
        }
        $stmt->close();
    } elseif ($action === 'ativar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE locadores SET ativo = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $msgClass = 'success';
            $msg = 'Locador reativado com sucesso!';
        }
        $stmt->close();
    }
}

// Buscar todos os locadores
$filtro_status = $_GET['status'] ?? 'todos';
$filtro_busca = $_GET['busca'] ?? '';

$sql = "SELECT l.*, u.nome AS criado_por_nome,
        (SELECT COUNT(*) FROM contratos_locacao c WHERE c.locador_id = l.id) AS total_contratos,
        (SELECT COUNT(*) FROM contratos_locacao c WHERE c.locador_id = l.id AND c.status = 'ativo') AS contratos_ativos
        FROM locadores l
        LEFT JOIN usuarios u ON l.criado_por = u.id
        WHERE 1=1";

$params = [];
$types = "";

if ($filtro_status === 'ativos') {
    $sql .= " AND l.ativo = 1";
} elseif ($filtro_status === 'inativos') {
    $sql .= " AND l.ativo = 0";
}

if (!empty($filtro_busca)) {
    $sql .= " AND (l.nome LIKE ? OR l.razao_social LIKE ? OR l.cnpj LIKE ? OR l.cpf LIKE ?)";
    $busca_like = "%$filtro_busca%";
    $params = [$busca_like, $busca_like, $busca_like, $busca_like];
    $types = "ssss";
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
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Locadores</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-bar input, .filter-bar select {
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9em;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-secondary {
            background: #a0aec0;
            color: white;
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 0.85em;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #f56565;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f7fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        tr:hover {
            background: #f7fafc;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            margin: 20px;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9em;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #a0aec0;
        }
        
        .empty-state-icon {
            font-size: 4em;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏢 Gestão de Locadores</h1>
            <p style="color: #718096; margin-top: 5px;">Cadastro e controle de fornecedores de equipamentos locados</p>
            
            <div class="header-actions">
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 10px;">
                        <input type="text" name="busca" placeholder="Buscar por nome, CNPJ, CPF..." 
                               value="<?php echo htmlspecialchars($filtro_busca); ?>" style="min-width: 300px;">
                        
                        <select name="status">
                            <option value="todos" <?php echo $filtro_status === 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="ativos" <?php echo $filtro_status === 'ativos' ? 'selected' : ''; ?>>Ativos</option>
                            <option value="inativos" <?php echo $filtro_status === 'inativos' ? 'selected' : ''; ?>>Inativos</option>
                        </select>
                        
                        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                        <?php if ($filtro_busca || $filtro_status !== 'todos'): ?>
                            <a href="locadores.php" class="btn btn-secondary btn-sm">Limpar</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div>
                    <button onclick="abrirModal()" class="btn btn-primary">➕ Novo Locador</button>
                </div>
            </div>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msgClass === 'success' ? 'success' : 'error'; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <?php if (!empty($locadores)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome / Razão Social</th>
                            <th>CNPJ / CPF</th>
                            <th>Contato</th>
                            <th>Contratos</th>
                            <th>Status</th>
                            <th style="text-align: center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locadores as $loc): ?>
                            <tr>
                                <td><strong>#<?php echo $loc['id']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($loc['nome']); ?></strong><br>
                                    <?php if ($loc['razao_social']): ?>
                                        <small style="color: #718096;"><?php echo htmlspecialchars($loc['razao_social']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($loc['cnpj']): ?>
                                        <span class="badge badge-info">CNPJ: <?php echo htmlspecialchars($loc['cnpj']); ?></span>
                                    <?php elseif ($loc['cpf']): ?>
                                        <span class="badge badge-info">CPF: <?php echo htmlspecialchars($loc['cpf']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #a0aec0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($loc['email']): ?>
                                        📧 <?php echo htmlspecialchars($loc['email']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($loc['telefone']): ?>
                                        📞 <?php echo htmlspecialchars($loc['telefone']); ?>
                                    <?php elseif ($loc['celular']): ?>
                                        📱 <?php echo htmlspecialchars($loc['celular']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $loc['total_contratos']; ?></strong> total<br>
                                    <small style="color: #48bb78;"><?php echo $loc['contratos_ativos']; ?> ativos</small>
                                </td>
                                <td>
                                    <?php if ($loc['ativo']): ?>
                                        <span class="badge badge-success">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button onclick="abrirModal(<?php echo $loc['id']; ?>)" 
                                            class="btn btn-primary btn-sm">✏️ Editar</button>
                                    
                                    <a href="contratos.php?locador_id=<?php echo $loc['id']; ?>" 
                                       class="btn btn-secondary btn-sm">📄 Contratos</a>
                                    
                                    <?php if ($loc['ativo']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Desativar este locador?');">
                                            <input type="hidden" name="action" value="desativar">
                                            <input type="hidden" name="id" value="<?php echo $loc['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">🚫</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="ativar">
                                            <input type="hidden" name="id" value="<?php echo $loc['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">✓</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🏢</div>
                    <h3>Nenhum locador cadastrado</h3>
                    <p>Clique em "Novo Locador" para começar</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal de Cadastro/Edição -->
    <div id="modalLocador" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Novo Locador</h2>
                <button onclick="fecharModal()" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
            </div>
            <form id="formLocador" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="criar">
                    <input type="hidden" name="id" id="formId" value="">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Tipo de Pessoa *</label>
                            <select name="tipo_pessoa" id="tipo_pessoa" required onchange="togglePessoaFields()">
                                <option value="juridica">Pessoa Jurídica</option>
                                <option value="fisica">Pessoa Física</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Nome / Nome Fantasia *</label>
                        <input type="text" name="nome" id="nome" required>
                    </div>
                    
                    <div id="field_razao_social" class="form-group">
                        <label>Razão Social</label>
                        <input type="text" name="razao_social" id="razao_social">
                    </div>
                    
                    <div class="form-grid">
                        <div id="field_cnpj" class="form-group">
                            <label>CNPJ</label>
                            <input type="text" name="cnpj" id="cnpj" placeholder="00.000.000/0000-00">
                        </div>
                        
                        <div id="field_cpf" class="form-group" style="display: none;">
                            <label>CPF</label>
                            <input type="text" name="cpf" id="cpf" placeholder="000.000.000-00">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" name="email" id="email">
                        </div>
                        
                        <div class="form-group">
                            <label>Telefone</label>
                            <input type="text" name="telefone" id="telefone" placeholder="(00) 0000-0000">
                        </div>
                        
                        <div class="form-group">
                            <label>Celular</label>
                            <input type="text" name="celular" id="celular" placeholder="(00) 00000-0000">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Responsável / Contato</label>
                        <input type="text" name="contato_responsavel" id="contato_responsavel" 
                               placeholder="Nome do responsável ou representante">
                    </div>
                    
                    <div class="form-group">
                        <label>Endereço</label>
                        <input type="text" name="endereco" id="endereco">
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Cidade</label>
                            <input type="text" name="cidade" id="cidade">
                        </div>
                        
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="estado" id="estado">
                                <option value="">Selecione...</option>
                                <option value="AC">AC</option>
                                <option value="AL">AL</option>
                                <option value="AP">AP</option>
                                <option value="AM">AM</option>
                                <option value="BA">BA</option>
                                <option value="CE">CE</option>
                                <option value="DF">DF</option>
                                <option value="ES">ES</option>
                                <option value="GO">GO</option>
                                <option value="MA">MA</option>
                                <option value="MT">MT</option>
                                <option value="MS">MS</option>
                                <option value="MG">MG</option>
                                <option value="PA">PA</option>
                                <option value="PB">PB</option>
                                <option value="PR">PR</option>
                                <option value="PE">PE</option>
                                <option value="PI">PI</option>
                                <option value="RJ">RJ</option>
                                <option value="RN">RN</option>
                                <option value="RS">RS</option>
                                <option value="RO">RO</option>
                                <option value="RR">RR</option>
                                <option value="SC">SC</option>
                                <option value="SP">SP</option>
                                <option value="SE">SE</option>
                                <option value="TO">TO</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>CEP</label>
                            <input type="text" name="cep" id="cep" placeholder="00000-000">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Observações</label>
                        <textarea name="observacoes" id="observacoes"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="fecharModal()" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function abrirModal(id = null) {
            if (id) {
                document.getElementById('modalTitle').textContent = 'Editar Locador';
                document.getElementById('formAction').value = 'editar';
                document.getElementById('formId').value = id;
                
                // Buscar dados via AJAX
                fetch(`../../api/locadores.php?id=${id}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.sucesso && data.data) {
                            const loc = data.data;
                            document.getElementById('tipo_pessoa').value = loc.tipo_pessoa || 'juridica';
                            document.getElementById('nome').value = loc.nome || '';
                            document.getElementById('razao_social').value = loc.razao_social || '';
                            document.getElementById('cnpj').value = loc.cnpj || '';
                            document.getElementById('cpf').value = loc.cpf || '';
                            document.getElementById('email').value = loc.email || '';
                            document.getElementById('telefone').value = loc.telefone || '';
                            document.getElementById('celular').value = loc.celular || '';
                            document.getElementById('endereco').value = loc.endereco || '';
                            document.getElementById('cidade').value = loc.cidade || '';
                            document.getElementById('estado').value = loc.estado || '';
                            document.getElementById('cep').value = loc.cep || '';
                            document.getElementById('contato_responsavel').value = loc.contato_responsavel || '';
                            document.getElementById('observacoes').value = loc.observacoes || '';
                            togglePessoaFields();
                        }
                    });
            } else {
                document.getElementById('modalTitle').textContent = 'Novo Locador';
                document.getElementById('formAction').value = 'criar';
                document.getElementById('formId').value = '';
                document.getElementById('formLocador').reset();
                togglePessoaFields();
            }
            
            document.getElementById('modalLocador').classList.add('active');
        }
        
        function fecharModal() {
            document.getElementById('modalLocador').classList.remove('active');
        }
        
        function togglePessoaFields() {
            const tipo = document.getElementById('tipo_pessoa').value;
            
            if (tipo === 'juridica') {
                document.getElementById('field_razao_social').style.display = 'block';
                document.getElementById('field_cnpj').style.display = 'block';
                document.getElementById('field_cpf').style.display = 'none';
            } else {
                document.getElementById('field_razao_social').style.display = 'none';
                document.getElementById('field_cnpj').style.display = 'none';
                document.getElementById('field_cpf').style.display = 'block';
            }
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('modalLocador').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });
    </script>
</body>
</html>