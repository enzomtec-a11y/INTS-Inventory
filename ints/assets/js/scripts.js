// Variáveis globais
let atributosDefinicao = {}; 
let regrasCondicionais = []; 

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

function carregarAtributos(categoriaId) {
    const divAttrs = document.getElementById('atributos-dinamicos');
    // Valores antigos (se existirem no dataset)
    const el = document.getElementById('categoria_id');
    let valoresAtuais = {};
    if(el && el.dataset.valoresAtuais) {
        try { valoresAtuais = JSON.parse(el.dataset.valoresAtuais); } catch(e){}
    }

    if (!categoriaId) { divAttrs.innerHTML = ''; return; }

    divAttrs.innerHTML = 'Carregando atributos...';

    fetch(`../../api/opcoes.php?acao=getAtributos&categoria_id=${categoriaId}`)
        .then(r => r.json())
        .then(async data => {
            if (data.sucesso) {
                atributosDefinicao = {};
                regrasCondicionais = data.regras || [];
                let html = '';

                if(data.atributos.length === 0) {
                    divAttrs.innerHTML = '<p>Nenhum atributo.</p>';
                    return;
                }

                for (const attr of data.atributos) {
                    atributosDefinicao[attr.id] = attr;
                    const req = (attr.obrigatorio == 1) ? 'required' : '';
                    const star = (attr.obrigatorio == 1) ? '<span style="color:red">*</span>' : '';
                    const val = valoresAtuais[attr.id] || '';

                    // Decide se vamos renderizar como select:
                    const temOpcoesNoPayload = Array.isArray(attr.opcoes) && attr.opcoes.length > 0;
                    const tipoOriginal = String(attr.tipo || '').toLowerCase();
                    const tipoEhSelectPorDefinicao = ['selecao','select','opcao','multi_opcao'].includes(tipoOriginal);
                    const renderSelect = temOpcoesNoPayload || tipoEhSelectPorDefinicao;

                    // IMPORTANT: hidden tipo será 'opcao' quando renderSelect == true
                    const hiddenTipo = renderSelect ? 'opcao' : tipoOriginal || 'texto';
                    let input = `<input type="hidden" name="tipo_attr_${attr.id}" value="${escapeHtml(hiddenTipo)}">`;

                    if (renderSelect) {
                        let ops = '<option value="">Selecione...</option>';
                        if (temOpcoesNoPayload) {
                            attr.opcoes.forEach(o => {
                                const sel = (String(o.id) === String(val)) ? 'selected' : '';
                                ops += `<option value="${escapeHtml(o.id)}" ${sel}>${escapeHtml(o.valor)}</option>`;
                            });
                        } else {
                            // fallback: buscar via endpoint getOpcoes
                            try {
                                const resOp = await fetch(`../../api/opcoes.php?acao=getOpcoes&categoria_id=${categoriaId}&atributo_id=${attr.id}`);
                                const dataOp = await resOp.json();
                                const lista = (dataOp.opcoes_mestre && dataOp.opcoes_mestre.length) ? dataOp.opcoes_mestre : (dataOp.opcoes_vinculadas || []);
                                lista.forEach(o => {
                                    const sel = (String(o.id) === String(val)) ? 'selected' : '';
                                    ops += `<option value="${escapeHtml(o.id)}" ${sel}>${escapeHtml(o.valor)}</option>`;
                                });
                            } catch (e) { console.error(e); }
                        }
                        input += `<select id="attr_${attr.id}" name="atributo_valor[${attr.id}]" ${req} onchange="aplicarRegras()">${ops}</select>`;
                    } 
                    else if (hiddenTipo === 'booleano') {
                        input += `<select id="attr_${attr.id}" name="atributo_valor[${attr.id}]" ${req} onchange="aplicarRegras()">
                                    <option value="">Selecione</option>
                                    <option value="1" ${String(val) === '1' ? 'selected' : ''}>Sim</option>
                                    <option value="0" ${String(val) === '0' ? 'selected' : ''}>Não</option>
                                 </select>`;
                    } 
                    else if (hiddenTipo === 'numero') {
                        input += `<input type="number" step="any" id="attr_${attr.id}" name="atributo_valor[${attr.id}]" value="${escapeHtml(val)}" ${req} onkeyup="aplicarRegras()">`;
                    }
                    else { // texto / data / fallback
                        const type = hiddenTipo === 'data' ? 'date' : 'text';
                        input += `<input type="${type}" id="attr_${attr.id}" name="atributo_valor[${attr.id}]" value="${escapeHtml(val)}" ${req} onkeyup="aplicarRegras()">`;
                    }

                    html += `<div class="form-group" id="group_attr_${attr.id}"><label>${escapeHtml(attr.nome)} ${star}</label>${input}</div>`;
                }

                divAttrs.innerHTML = html;
                aplicarRegras();

            } else {
                divAttrs.innerHTML = `<p style="color:red">Erro: ${data.mensagem}</p>`;
            }
        })
        .catch(e => {
            console.error(e);
            divAttrs.innerHTML = '<p style="color:red">Erro de conexão com API.</p>';
        });
}

function aplicarRegras() {
    regrasCondicionais.forEach(r => {
        const gatilho = document.getElementById(`attr_${r.atributo_gatilho_id}`);
        const alvoDiv = document.getElementById(`group_attr_${r.atributo_alvo_id}`);
        const alvoInput = document.getElementById(`attr_${r.atributo_alvo_id}`);

        if (gatilho && alvoDiv && alvoInput) {
            const val = String(gatilho.value);
            const gatilhoEsperado = String(r.valor_gatilho);

            if (val === gatilhoEsperado) {
                if (r.acao === 'bloquear') {
                    alvoDiv.style.display = 'none';
                    alvoInput.removeAttribute('required');
                    alvoInput.value = '';
                } else if (r.acao === 'tornar_obrigatorio') {
                    alvoDiv.style.display = 'block';
                    alvoInput.setAttribute('required', 'true');
                }
            } else {
                alvoDiv.style.display = 'block';
                const def = atributosDefinicao[r.atributo_alvo_id];
                if (def && def.obrigatorio == 1) alvoInput.setAttribute('required', 'true');
                else alvoInput.removeAttribute('required');
            }
        }
    });
}
