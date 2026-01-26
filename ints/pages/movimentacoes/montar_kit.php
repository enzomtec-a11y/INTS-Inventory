<?php
require_once '../../config/_protecao.php';

// Apenas usuários logados podem acessar
$usuario_id = getUsuarioId();

// 1. Carregar Produtos (que podem ser Kits)
$produtos = [];
$sql_prod = "SELECT id, nome FROM produtos WHERE deletado = FALSE ORDER BY nome";
$res_prod = $conn->query($sql_prod);
while ($row = $res_prod->fetch_assoc()) {
    $produtos[] = $row;
}

// 2. Carregar Locais (Onde o Kit montado será guardado)
$locais = [];
if (function_exists('getLocaisFormatados')) {
    $locais = getLocaisFormatados($conn, true);
} else {
    // Fallback simples
    $res_loc = $conn->query("SELECT id, nome FROM locais WHERE deletado = FALSE ORDER BY nome");
    while ($r = $res_loc->fetch_assoc()) $locais[$r['id']] = $r['nome'];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ordem de Produção (Montagem de Kit)</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container { max-width: 900px; margin: 20px auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { border-bottom: 2px solid #27ae60; padding-bottom: 10px; color: #2c3e50; }
        
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; align-items: flex-end; }
        .form-group { flex: 1; }
        label { font-weight: bold; display: block; margin-bottom: 5px; color: #555; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        
        .btn-action { padding: 12px 25px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; color: white; transition: background 0.3s; }
        .btn-check { background-color: #3498db; }
        .btn-check:hover { background-color: #2980b9; }
        .btn-assemble { background-color: #27ae60; display: none; }
        .btn-assemble:hover { background-color: #219150; }

        #preview-area { margin-top: 30px; display: none; background: #f8f9fa; padding: 20px; border-radius: 5px; border: 1px solid #e9ecef; }
        #preview-area h3 { margin-top: 0; color: #333; }
        
        table.preview-table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        table.preview-table th { background: #eee; text-align: left; padding: 10px; border-bottom: 2px solid #ddd; }
        table.preview-table td { padding: 10px; border-bottom: 1px solid #eee; }
        
        .status-ok { color: green; font-weight: bold; }
        .status-erro { color: red; font-weight: bold; }
        .summary-box { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #ffeeba; }
    </style>
</head>
<body>

<div class="container">
    <h1>Montagem de Kit / Produção</h1>
    <p>Utilize esta tela para transformar componentes do estoque em um produto final (Kit).</p>
    <p><a href="../../index.html">Voltar para Home</a></p>

    <div class="form-row">
        <div class="form-group" style="flex: 2;">
            <label>Produto a Montar (Kit):</label>
            <select id="produto_id">
                <option value="">Selecione...</option>
                <?php foreach ($produtos as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Quantidade a Produzir:</label>
            <input type="number" id="quantidade" value="1" min="1">
        </div>

        <div class="form-group" style="flex: 2;">
            <label>Local de Destino (Onde guardar o Kit):</label>
            <select id="local_id">
                <option value="">Selecione...</option>
                <?php foreach ($locais as $id => $nome): ?>
                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($nome); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div style="text-align: right;">
        <button type="button" class="btn-action btn-check" onclick="simularMontagem()">1. Verificar Componentes</button>
        <button type="button" class="btn-action btn-assemble" id="btn-montar" onclick="executarMontagem()">2. Confirmar Montagem</button>
    </div>

    <div id="preview-area">
        <h3>Resumo da Produção</h3>
        <div id="feedback-msg"></div>
        
        <h4>Lista de Materiais (BOM) Necessária:</h4>
        <table class="preview-table">
            <thead>
                <tr>
                    <th>Componente</th>
                    <th>Qtd. Unitária</th>
                    <th>Total Necessário</th>
                    <th>Estoque Disponível</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="preview-body">
                </tbody>
        </table>
    </div>

</div>

<script>
    const usuarioId = <?php echo $usuarioId ?? 0; ?>;
    const btnMontar = document.getElementById('btn-montar');
    const previewArea = document.getElementById('preview-area');
    const previewBody = document.getElementById('preview-body');
    const feedbackMsg = document.getElementById('feedback-msg');

    async function simularMontagem() {
        const prodId = document.getElementById('produto_id').value;
        const qtd = parseFloat(document.getElementById('quantidade').value);

        if (!prodId || qtd <= 0) {
            alert("Selecione um produto e uma quantidade válida.");
            return;
        }

        // Reset visual
        previewArea.style.display = 'block';
        previewBody.innerHTML = '<tr><td colspan="5">Carregando estrutura...</td></tr>';
        feedbackMsg.innerHTML = '';
        btnMontar.style.display = 'none';

        try {
            // 1. Buscar a estrutura do Kit (Componentes)
            const resComp = await fetch(`../../api/componentes.php?id=${prodId}`);
            const dataComp = await resComp.json();

            if (!dataComp.sucesso) {
                previewBody.innerHTML = `<tr><td colspan="5" style="color:red">Erro ao buscar componentes: ${dataComp.mensagem}</td></tr>`;
                return;
            }

            if (!dataComp.explosao_flat || dataComp.explosao_flat.length === 0) {
                previewBody.innerHTML = `<tr><td colspan="5" style="color:orange">Este produto não possui componentes cadastrados (Não é um kit).</td></tr>`;
                return;
            }

            // 2. Renderizar Tabela e Verificar Saldos
            let html = '';
            let podeMontar = true;

            // explosao_flat traz: produto_id, nome, quantidade_total (para 1un do pai), estoque_total
            for (const comp of dataComp.explosao_flat) {
                // Cálculo: Qtd Necessária = (Qtd por unidade * Qtd Produção)
                // A API componentes.php retorna 'quantidade_total' baseada no multiplier=1.
                // Se o flat da API já vier multiplicado, usamos direto.
                // Vamos recalcular para garantir:
                
                // Nota: A API componentes.php retorna 'quantidade_total' já calculada se passássemos multiplier, 
                // mas a chamada padrão é multiplier=1.
                // Entretanto, o flat['quantidade_total'] da API é baseado na chamada da API.
                // A API `componentes.php` foi feita para visualizar a estrutura.
                
                // Vamos usar a lógica: (comp.quantidade_total / 1) * qtd_desejada
                const qtdNecessaria = (parseFloat(comp.quantidade_total) * qtd).toFixed(2);
                const estoqueAtual = parseFloat(comp.estoque_total);
                
                let statusClass = 'status-ok';
                let statusTexto = 'OK';

                if (estoqueAtual < qtdNecessaria) {
                    statusClass = 'status-erro';
                    statusTexto = `Falta ${(qtdNecessaria - estoqueAtual).toFixed(2)}`;
                    podeMontar = false;
                }

                html += `
                    <tr>
                        <td>${comp.nome}</td>
                        <td>${parseFloat(comp.quantidade_total).toFixed(2)}</td>
                        <td><strong>${qtdNecessaria}</strong></td>
                        <td>${estoqueAtual}</td>
                        <td class="${statusClass}">${statusTexto}</td>
                    </tr>
                `;
            }

            previewBody.innerHTML = html;

            if (podeMontar) {
                feedbackMsg.innerHTML = '<div class="summary-box" style="background:#d4edda; color:#155724; border-color:#c3e6cb">Tudo pronto! Estoque suficiente para a produção.</div>';
                btnMontar.style.display = 'inline-block';
            } else {
                feedbackMsg.innerHTML = '<div class="summary-box" style="background:#f8d7da; color:#721c24; border-color:#f5c6cb">Estoque insuficiente de componentes. Não é possível montar.</div>';
            }

        } catch (error) {
            console.error(error);
            previewBody.innerHTML = `<tr><td colspan="5" style="color:red">Erro de comunicação com o servidor.</td></tr>`;
        }
    }

    async function executarMontagem() {
        const prodId = document.getElementById('produto_id').value;
        const qtd = document.getElementById('quantidade').value;
        const localId = document.getElementById('local_id').value;

        if (!confirm(`Confirma a produção de ${qtd} unidade(s)? Isso descontará os componentes do estoque.`)) return;

        // Feedback visual de carregamento
        btnMontar.disabled = true;
        btnMontar.innerText = "Processando...";

        const formData = new FormData();
        formData.append('produto_id', prodId);
        formData.append('quantidade', qtd);
        formData.append('local_id', localId); // Onde o Kit será guardado
        formData.append('action', 'assemble'); // Ação de montagem
        formData.append('usuario_id', usuarioId);

        try {
            const res = await fetch('../../api/kit_allocate.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.sucesso) {
                alert("Sucesso! " + data.mensagem);
                window.location.href = '../produtos/listar.php?sucesso=montagem'; // Redireciona para ver o estoque
            } else {
                alert("Erro: " + data.mensagem);
                btnMontar.disabled = false;
                btnMontar.innerText = "2. Confirmar Montagem";
            }
        } catch (error) {
            alert("Erro fatal ao processar montagem.");
            console.error(error);
            btnMontar.disabled = false;
            btnMontar.innerText = "2. Confirmar Montagem";
        }
    }
</script>

</body>
</html>