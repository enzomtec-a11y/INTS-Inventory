<?php
require_once '../../config/_protecao.php';
exigirAdmin();

$msg = '';
$msgClass = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
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
            $msgClass = 'error';
            $msg = 'Preencha os campos obrigatórios.';
        } else {
            if ($action === 'criar') {
                $sql = "INSERT INTO contratos_locacao (locador_id, numero_contrato, descricao, valor_mensal, valor_total, data_inicio, data_fim, data_vencimento_pagamento, renovacao_automatica, observacoes, status, criado_por) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $criado_por);
                
                if ($stmt->execute()) {
                    $msgClass = 'success';
                    $msg = 'Contrato cadastrado com sucesso!';
                } else {
                    $msgClass = 'error';
                    $msg = 'Erro ao cadastrar contrato: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $sql = "UPDATE contratos_locacao SET locador_id = ?, numero_contrato = ?, descricao = ?, valor_mensal = ?, valor_total = ?, data_inicio = ?, data_fim = ?, data_vencimento_pagamento = ?, renovacao_automatica = ?, observacoes = ?, status = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issddssiissi", $locador_id, $numero_contrato, $descricao, $valor_mensal, $valor_total, $data_inicio, $data_fim, $data_vencimento_pagamento, $renovacao_automatica, $observacoes, $status, $id);
                
                if ($stmt->execute()) {
                    $msgClass = 'success';
                    $msg = 'Contrato atualizado com sucesso!';
                } else {
                    $msgClass = 'error';
                    $msg = 'Erro ao atualizar contrato: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'cancelar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE contratos_locacao SET status = 'cancelado' WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $msgClass = 'success';
            $msg = 'Contrato cancelado com sucesso!';
        }
        $stmt->close();
    }
}

// Buscar contratos
$filtro_status = $_GET['status'] ?? 'todos';
$filtro_locador = $_GET['locador_id'] ?? '';
$filtro_busca = $_GET['busca'] ?? '';

$sql = "SELECT c.*, l.nome AS locador_nome,
        (SELECT COUNT(*) FROM produtos p WHERE p.contrato_locacao_id = c.id) AS total_produtos,
        DATEDIFF(c.data_fim, CURDATE()) AS dias_vencimento
        FROM contratos_locacao c
        INNER JOIN locadores l ON c.locador_id = l.id
        WHERE 1=1";

$params = [];
$types = "";

if ($filtro_status !== 'todos') {
    $sql .= " AND c.status = ?";
    $params[] = $filtro_status;
    $types .= "s";
}

if (!empty($filtro_locador)) {
    $sql .= " AND c.locador_id = ?";
    $params[] = (int)$filtro_locador;
    $types .= "i";
}

if (!empty($filtro_busca)) {
    $sql .= " AND (c.numero_contrato LIKE ? OR c.descricao LIKE ?)";
    $busca_like = "%$filtro_busca%";
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

// Buscar locadores para o dropdown
$locadores = [];
$sql_loc = "SELECT id, nome FROM locadores WHERE ativo = 1 ORDER BY nome";
$result_loc = $conn->query($sql_loc);
while ($row = $result_loc->fetch_assoc()) {
    $locadores[] = $row;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Contratos de Locação</title>
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
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
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
        
        .badge-warning {
            background: #feebc8;
            color: #7c2d12;
        }
        
        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .badge-secondary {
            background: #e2e8f0;
            color: #2d3748;
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
        
        .alert-box {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 0.9em;
        }
        
        .alert-box-warning {
            background: #fef3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .alert-box-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📄 Gestão de Contratos de Locação</h1>
            <p style="color: #718096; margin-top: 5px;">Controle de contratos ativos, vencidos e histórico</p>
            
            <div class="header-actions">
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <input type="text" name="busca" placeholder="Buscar nº contrato ou descrição..." 
                               value="<?php echo htmlspecialchars($filtro_busca); ?>" style="min-width: 300px;">
                        
                        <select name="locador_id">
                            <option value="">Todos os locadores</option>
                            <?php foreach ($locadores as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>" <?php echo $filtro_locador == $loc['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($loc['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="status">
                            <option value="todos" <?php echo $filtro_status === 'todos' ? 'selected' : ''; ?>>Todos os status</option>
                            <option value="ativo" <?php echo $filtro_status === 'ativo' ? 'selected' : ''; ?>>Ativos</option>
                            <option value="vencido" <?php echo $filtro_status === 'vencido' ? 'selected' : ''; ?>>Vencidos</option>
                            <option value="cancelado" <?php echo $filtro_status === 'cancelado' ? 'selected' : ''; ?>>Cancelados</option>
                            <option value="suspenso" <?php echo $filtro_status === 'suspenso' ? 'selected' : ''; ?>>Suspensos</option>
                        </select>
                        
                        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                        <?php if ($filtro_busca || $filtro_status !== 'todos' || $filtro_locador): ?>
                            <a href="contratos.php" class="btn btn-secondary btn-sm">Limpar</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div>
                    <button onclick="abrirModal()" class="btn btn-primary">➕ Novo Contrato</button>
                    <a href="locadores.php" class="btn btn-secondary">Locadores</a>
                </div>
            </div>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msgClass === 'success' ? 'success' : 'error'; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <?php if (!empty($contratos)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Contrato</th>
                            <th>Locador</th>
                            <th>Vigência</th>
                            <th>Valor Mensal</th>
                            <th>Produtos</th>
                            <th>Status</th>
                            <th style="text-align: center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contratos as $c): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['numero_contrato']); ?></strong><br>
                                    <?php if ($c['descricao']): ?>
                                        <small style="color: #718096;"><?php echo htmlspecialchars(substr($c['descricao'], 0, 50)) . (strlen($c['descricao']) > 50 ? '...' : ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($c['locador_nome']); ?></td>
                                <td>
                                    <strong><?php echo date('d/m/Y', strtotime($c['data_inicio'])); ?></strong><br>
                                    <?php if ($c['data_fim']): ?>
                                        <small style="color: #718096;">até <?php echo date('d/m/Y', strtotime($c['data_fim'])); ?></small><br>
                                        <?php if ($c['dias_vencimento'] !== null): ?>
                                            <?php if ($c['dias_vencimento'] < 0): ?>
                                                <small style="color: #e53e3e;">⚠️ Vencido há <?php echo abs($c['dias_vencimento']); ?> dias</small>
                                            <?php elseif ($c['dias_vencimento'] <= 30): ?>
                                                <small style="color: #dd6b20;">⚠️ Vence em <?php echo $c['dias_vencimento']; ?> dias</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small style="color: #718096;">Indeterminado</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['valor_mensal']): ?>
                                        <strong>R$ <?php echo number_format($c['valor_mensal'], 2, ',', '.'); ?></strong>
                                    <?php else: ?>
                                        <span style="color: #a0aec0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $c['total_produtos']; ?></strong> 
                                    <?php echo $c['total_produtos'] == 1 ? 'produto' : 'produtos'; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badge = 'badge-success';
                                    $status_text = ucfirst($c['status']);
                                    
                                    switch($c['status']) {
                                        case 'vencido':
                                            $status_badge = 'badge-danger';
                                            break;
                                        case 'cancelado':
                                            $status_badge = 'badge-secondary';
                                            break;
                                        case 'suspenso':
                                            $status_badge = 'badge-warning';
                                            break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span>
                                    <?php if ($c['renovacao_automatica']): ?>
                                        <br><small style="color: #48bb78;">🔄 Renov. Auto</small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button onclick="abrirModal(<?php echo $c['id']; ?>)" 
                                            class="btn btn-primary btn-sm">✏️ Editar</button>
                                    
                                    <a href="../produtos/index.php?contrato_id=<?php echo $c['id']; ?>" 
                                       class="btn btn-secondary btn-sm" title="Ver produtos deste contrato">
                                        📦 Produtos
                                    </a>
                                    
                                    <?php if ($c['status'] === 'ativo'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Cancelar este contrato?');">
                                            <input type="hidden" name="action" value="cancelar">
                                            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">🚫</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📄</div>
                    <h3>Nenhum contrato cadastrado</h3>
                    <p>Clique em "Novo Contrato" para começar</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal de Cadastro/Edição -->
    <div id="modalContrato" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Novo Contrato</h2>
                <button onclick="fecharModal()" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
            </div>
            <form id="formContrato" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="criar">
                    <input type="hidden" name="id" id="formId" value="">
                    
                    <div class="form-group">
                        <label>Locador *</label>
                        <select name="locador_id" id="locador_id" required>
                            <option value="">Selecione um locador...</option>
                            <?php foreach ($locadores as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Número do Contrato *</label>
                            <input type="text" name="numero_contrato" id="numero_contrato" required 
                                   placeholder="Ex: CONT-2026-001">
                        </div>
                        
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" id="status">
                                <option value="ativo">Ativo</option>
                                <option value="vencido">Vencido</option>
                                <option value="cancelado">Cancelado</option>
                                <option value="suspenso">Suspenso</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Descrição / O que está sendo locado</label>
                        <textarea name="descricao" id="descricao" 
                                  placeholder="Ex: Locação de 50 notebooks Dell Latitude"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Valor Mensal (R$)</label>
                            <input type="number" name="valor_mensal" id="valor_mensal" 
                                   step="0.01" min="0" placeholder="0,00">
                        </div>
                        
                        <div class="form-group">
                            <label>Valor Total (R$)</label>
                            <input type="number" name="valor_total" id="valor_total" 
                                   step="0.01" min="0" placeholder="0,00">
                        </div>
                        
                        <div class="form-group">
                            <label>Dia Vencimento (1-31)</label>
                            <input type="number" name="data_vencimento_pagamento" id="data_vencimento_pagamento" 
                                   min="1" max="31" placeholder="Ex: 10">
                            <small style="color: #718096;">Dia do mês para vencimento da mensalidade</small>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Data de Início *</label>
                            <input type="date" name="data_inicio" id="data_inicio" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Data de Término</label>
                            <input type="date" name="data_fim" id="data_fim">
                            <small style="color: #718096;">Deixe em branco para contrato indeterminado</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="renovacao_automatica" id="renovacao_automatica" 
                                   style="width: auto;">
                            Renovação Automática
                        </label>
                        <small style="color: #718096;">O contrato se renova automaticamente ao fim da vigência</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Observações</label>
                        <textarea name="observacoes" id="observacoes" 
                                  placeholder="Informações adicionais sobre o contrato..."></textarea>
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
                document.getElementById('modalTitle').textContent = 'Editar Contrato';
                document.getElementById('formAction').value = 'editar';
                document.getElementById('formId').value = id;
                
                // Buscar dados via AJAX
                fetch(`../../api/contratos.php?id=${id}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.sucesso && data.data) {
                            const c = data.data;
                            document.getElementById('locador_id').value = c.locador_id || '';
                            document.getElementById('numero_contrato').value = c.numero_contrato || '';
                            document.getElementById('descricao').value = c.descricao || '';
                            document.getElementById('valor_mensal').value = c.valor_mensal || '';
                            document.getElementById('valor_total').value = c.valor_total || '';
                            document.getElementById('data_inicio').value = c.data_inicio || '';
                            document.getElementById('data_fim').value = c.data_fim || '';
                            document.getElementById('data_vencimento_pagamento').value = c.data_vencimento_pagamento || '';
                            document.getElementById('renovacao_automatica').checked = c.renovacao_automatica == 1;
                            document.getElementById('observacoes').value = c.observacoes || '';
                            document.getElementById('status').value = c.status || 'ativo';
                        }
                    });
            } else {
                document.getElementById('modalTitle').textContent = 'Novo Contrato';
                document.getElementById('formAction').value = 'criar';
                document.getElementById('formId').value = '';
                document.getElementById('formContrato').reset();
            }
            
            document.getElementById('modalContrato').classList.add('active');
        }
        
        function fecharModal() {
            document.getElementById('modalContrato').classList.remove('active');
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('modalContrato').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });
    </script>
</body>
</html>