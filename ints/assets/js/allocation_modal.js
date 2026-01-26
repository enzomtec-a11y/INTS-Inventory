// Versão atualizada do modal de alocação (substitui a anterior).
// Adiciona botão "Aplicar + Gerar Picklist PDF" que chama allocations_apply_and_picklist.php e
// baixa o PDF retornado (ou abre HTML preview se servidor não gerar PDF).
// Requisitos:
// - endpoints: ../api/componentes.php, ../api/patrimonios.php, ../api/allocations_apply.php, ../api/allocations_apply_and_picklist.php
// - picklist generator: ints/api/picklist.php + ints/pdf/TemplatePicklist.php (opcional TCPDF para PDF direto)
//
// Observação: este arquivo assume que a página tem botões .btn-view-local e linhas com data-produto-id.
// Coloque <script src="../../assets/js/allocation_modal.js"></script> após a renderização da tabela de componentes.

(function(){
  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const k in attrs) {
      if (k === 'class') node.className = attrs[k];
      else if (k === 'html') node.innerHTML = attrs[k];
      else node.setAttribute(k, attrs[k]);
    }
    (Array.isArray(children) ? children : [children]).forEach(c => { if (!c) return; node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return node;
  }
  function fetchJson(url, opts) { return fetch(url, opts).then(r => r.json()); }

  window.openAllocationModal = async function(produtoId, requiredQty = null) {
    const modal = document.createElement('div');
    Object.assign(modal.style, { position:'fixed', left:0, top:0, right:0, bottom:0, background:'rgba(0,0,0,0.45)', display:'flex', alignItems:'center', justifyContent:'center', zIndex:99999 });
    const box = el('div', { class:'alloc-box', style: 'background:#fff;padding:18px;border-radius:6px;max-width:980px;width:95%;max-height:85vh;overflow:auto;' });
    modal.appendChild(box);

    box.appendChild(el('h3', { html: `Alocar componente — produto #${produtoId}` }));

    const headerRow = el('div', { style: 'display:flex; gap:12px; align-items:center; margin-bottom:8px;' });
    headerRow.appendChild(el('div', { html: '<label>Qtd requerida:</label>' }));
    const qtyInput = el('input', { type:'number', value: requiredQty || 1, min:0.0001, step:'0.0001', style:'width:120px;padding:6px;margin-right:8px;' });
    headerRow.appendChild(qtyInput);
    headerRow.appendChild(el('div', { html:'<label>Referência (opcional):</label>' }));
    const refInput = el('input', { type:'text', placeholder:'ex: pedido123 ou batch', style:'width:220px;padding:6px;' });
    headerRow.appendChild(refInput);
    box.appendChild(headerRow);

    const info = el('div', { html:'Carregando dados...' });
    box.appendChild(info);

    const tableWrap = el('div', { style:'margin-top:10px;' });
    box.appendChild(tableWrap);

    const footer = el('div', { style:'display:flex; gap:8px; justify-content:flex-end; margin-top:12px;' });
    const btnClose = el('button', { html:'Fechar', style:'padding:8px 12px;'} );
    const btnPreview = el('button', { html:'Gerar preview (dry-run)', style:'padding:8px 12px;'} );
    const btnApply = el('button', { html:'Aplicar Reservas', style:'padding:8px 12px; background:#28a745;color:#fff; border:none; border-radius:4px;' });
    const btnApplyPdf = el('button', { html:'Aplicar + Gerar Picklist PDF', style:'padding:8px 12px; background:#007bff;color:#fff; border:none; border-radius:4px;' });

    footer.appendChild(btnClose); footer.appendChild(btnPreview); footer.appendChild(btnApply); footer.appendChild(btnApplyPdf);
    box.appendChild(footer);
    document.body.appendChild(modal);

    // load component details
    let compData;
    try {
      compData = await fetchJson(`../../api/componentes.php?produto_id=${encodeURIComponent(produtoId)}&with_locais=1&include_patrimonios=1`);
    } catch (e) {
      info.innerText = 'Erro ao carregar dados do componente: ' + e.message;
      return;
    }
    if (!compData || !compData.sucesso) {
      info.innerText = 'Erro: ' + (compData && compData.mensagem ? compData.mensagem : 'Resposta inválida do servidor');
      return;
    }
    const compList = compData.data.componentes || [];
    let comp = compList.find(c => parseInt(c.produto_id) === parseInt(produtoId));
    if (!comp) comp = { produto_id: produtoId, nome: `Produto ${produtoId}`, quantidade_total: parseFloat(qtyInput.value) || 1, locais: [], patrimonios_available: null };

    if (!requiredQty && comp.quantidade_total) qtyInput.value = comp.quantidade_total;
    info.innerHTML = `<div><strong>${comp.nome}</strong> — Quantidade total requerida: <strong id="req-count">${qtyInput.value}</strong></div>
      <div style="margin-top:6px;color:#555;">Total estoque: ${comp.total_stock ?? 0} — Reservado: ${comp.total_reserved ?? 0} — Disponível: ${comp.available ?? 0} ${comp.patrimonios_available !== null ? ' — Patrimônios disponíveis: ' + comp.patrimonios_available : ''}</div>`;

    const locais = comp.locais || [];
    function buildLocaisTable() {
      tableWrap.innerHTML = '';
      const t = el('table', { style:'width:100%;border-collapse:collapse;border:1px solid #ddd;' });
      const thead = el('thead');
      thead.innerHTML = '<tr><th style="padding:8px;border-bottom:1px solid #ddd">Local</th><th style="width:120px;padding:8px;border-bottom:1px solid #ddd">Estoque</th><th style="width:120px;padding:8px;border-bottom:1px solid #ddd">Reservado</th><th style="width:140px;padding:8px;border-bottom:1px solid #ddd">Disponível</th><th style="width:160px;padding:8px;border-bottom:1px solid #ddd">Qtd a alocar</th></tr>';
      t.appendChild(thead);
      const tbody = el('tbody');
      locais.forEach(l => {
        const tr = el('tr');
        const locName = l.local_nome || ('Local ' + l.local_id);
        const estoque = Number(l.estoque || 0);
        const reservado = Number(l.reservado || 0);
        const available = Number(l.available || Math.max(0, estoque - reservado));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee' }, locName));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, String(estoque)));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, String(reservado)));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, String(available)));
        const inputQty = el('input', { type:'number', min:0, step:'0.0001', value:'0', style:'width:120px;padding:6px;' });
        inputQty.dataset.produtoId = produtoId;
        inputQty.dataset.localId = l.local_id === null ? '' : String(l.local_id);
        inputQty.dataset.available = String(available);
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, inputQty));
        tbody.appendChild(tr);
      });
      if (locais.length === 0) {
        const tr = el('tr');
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee' }, 'Sem registro de estoques (sem locais)'));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, '0'));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, '0'));
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, '0'));
        const inputQty = el('input', { type:'number', min:0, step:'0.0001', value:'0', style:'width:120px;padding:6px;' });
        inputQty.dataset.produtoId = produtoId;
        inputQty.dataset.localId = '';
        inputQty.dataset.available = '0';
        tr.appendChild(el('td', { style:'padding:6px;border-top:1px solid #eee;text-align:center' }, inputQty));
        tbody.appendChild(tr);
      }
      t.appendChild(tbody);
      tableWrap.appendChild(t);
    }
    buildLocaisTable();

    let patrimoniosList = [];
    async function loadPatrimonios() {
      if (comp.patrimonios_available && comp.patrimonios_available > 0) {
        try {
          const pResp = await fetchJson(`../../api/patrimonios.php?produto_id=${encodeURIComponent(produtoId)}&available=1`);
          if (pResp && pResp.sucesso) patrimoniosList = pResp.data || [];
        } catch(e){}
        if (patrimoniosList.length) {
          const div = el('div', { style:'margin-top:10px;' });
          div.appendChild(el('div', { html:'<strong>Patrimônios disponíveis (selecione específicos em vez de quantidades):</strong>' }));
          patrimoniosList.forEach(p => {
            const chk = el('input', { type:'checkbox' });
            chk.dataset.patrimonioId = p.id;
            chk.style.marginRight = '8px';
            div.appendChild(el('div', {}, [chk, document.createTextNode(` ${p.numero_patrimonio || p.numero_serie || ('id:'+p.id)}`)]));
          });
          box.insertBefore(div, footer);
        }
      }
    }
    loadPatrimonios();

    function collectAllocations() {
      const inputs = Array.from(tableWrap.querySelectorAll('input[type="number"]'));
      const allocs = [];
      inputs.forEach(inp => {
        const v = parseFloat(inp.value || '0') || 0;
        if (v <= 0) return;
        allocs.push({
          produto_id: parseInt(inp.dataset.produtoId),
          local_id: inp.dataset.localId === '' ? null : parseInt(inp.dataset.localId),
          qtd: v
        });
      });
      const selectedPatr = Array.from(box.querySelectorAll('input[type="checkbox"]')).filter(c => c.checked).map(c => parseInt(c.dataset.patrimonioId));
      if (selectedPatr.length) {
        const patrAlloc = selectedPatr.map(pid => ({ produto_id: produtoId, local_id: null, qtd: 1, patrimonio_id: pid }));
        return patrAlloc;
      }
      return allocs;
    }

    function validateAllocations() {
      const allocs = collectAllocations();
      const totalAlloc = allocs.reduce((s,a) => s + (parseFloat(a.qtd)||0), 0);
      const req = parseFloat(qtyInput.value || '0') || 0;
      return { ok: Math.abs(totalAlloc - req) <= 0.0001 || totalAlloc === req, totalAlloc, req, allocs };
    }

    btnClose.addEventListener('click', () => modal.remove());

    btnPreview.addEventListener('click', async () => {
      const val = validateAllocations();
      if (!val.ok) { alert(`Total alocado (${val.totalAlloc}) difere do requerido (${val.req}). Ajuste-o antes de requerir.`); return; }
      try {
        const form = new FormData();
        form.append('action','generate');
        form.append('allocations', JSON.stringify(val.allocs));
        form.append('note', refInput.value || '');
        const r = await fetchJson('../../api/picklist.php', { method:'POST', body: form });
        if (r && r.sucesso) {
          if (r.html || r.html_preview) {
            const w = window.open('', '_blank');
            w.document.write(r.html || r.html_preview);
            w.document.close();
          } else {
            alert('Preview gerado (JSON)'); console.log(r);
          }
        } else {
          alert('Erro ao gerar preview: ' + (r.mensagem || 'sem mensagem'));
        }
      } catch (e) {
        alert('Erro ao gerar preview: ' + e.message);
      }
    });

    btnApply.addEventListener('click', async () => {
      const val = validateAllocations();
      if (!val.ok) { alert(`Total alocado (${val.totalAlloc}) difere do requerido (${val.req}). Ajuste antes.`); return; }
      if (!confirm('Aplicar reservas para as alocações definidas?')) return;
      const payload = { referencia_tipo: 'manual_alloc', referencia_id: produtoId, referencia_batch: refInput.value || null, usuario_id: window.LOGGED_USER_ID || null, allocations: val.allocs };
      try {
        const r = await fetchJson('../../api/allocations_apply.php', { method:'POST', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify(payload) });
        if (r && r.sucesso) {
          alert('Reservas aplicadas com sucesso.');
          modal.remove();
        } else {
          alert('Erro ao aplicar reservas: ' + (r && r.mensagem ? r.mensagem : 'resposta inválida'));
        }
      } catch (e) {
        alert('Erro ao aplicar reservas: ' + e.message);
      }
    });

    // New: apply and request picklist PDF
    btnApplyPdf.addEventListener('click', async () => {
      const val = validateAllocations();
      if (!val.ok) { alert(`Total alocado (${val.totalAlloc}) difere do requerido (${val.req}). Ajuste antes.`); return; }
      if (!confirm('Aplicar reservas e gerar Picklist (PDF)?')) return;

      const payload = { referencia_tipo: 'manual_alloc', referencia_id: produtoId, referencia_batch: refInput.value || null, usuario_id: window.LOGGED_USER_ID || null, allocations: val.allocs, format: 'pdf' };

      try {
        const resp = await fetch('../../api/allocations_apply_and_picklist.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const contentType = resp.headers.get('Content-Type') || '';

        if (contentType.indexOf('application/pdf') !== -1) {
          // Download PDF
          const blob = await resp.blob();
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          const filename = 'picklist_' + (payload.referencia_batch || Date.now()) + '.pdf';
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          a.remove();
          window.URL.revokeObjectURL(url);
          modal.remove();
        } else {
          // Expect JSON with html preview or info
          const j = await resp.json();
          if (j && j.sucesso) {
            if (j.html) {
              const w = window.open('', '_blank');
              w.document.write(j.html);
              w.document.close();
              modal.remove();
            } else {
              alert('Picklist gerado (ver console).'); console.log(j);
              modal.remove();
            }
          } else {
            alert('Erro ao gerar picklist: ' + (j && j.mensagem ? j.mensagem : 'resposta inválida'));
          }
        }
      } catch (e) {
        alert('Erro ao aplicar + gerar picklist: ' + e.message);
      }
    });

    qtyInput.addEventListener('input', () => {
      const elReq = box.querySelector('#req-count');
      if (elReq) elReq.textContent = qtyInput.value;
    });
  };

  // Auto-bind existing buttons
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-view-local').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', (e) => {
        const tr = e.target.closest('tr');
        const pid = tr ? tr.getAttribute('data-produto-id') : null;
        const qtyEl = document.getElementById('components_qty_to_make');
        const qty = qtyEl ? parseFloat(qtyEl.value || '1') : 1;
        if (pid) openAllocationModal(pid, qty);
      });
    });
  });

})();