(function(){
  // helper to fetch components
  async function fetchComponents(produtoId) {
    const res = await fetch(`../api/componentes.php?produto_id=${encodeURIComponent(produtoId)}`);
    return await res.json();
  }

  // render components table
  async function renderComponentsTab(produtoId) {
    const container = document.getElementById('components-tab');
    if (!container) return;
    container.innerHTML = '<div>Carregando componentes...</div>';
    const data = await fetchComponents(produtoId);
    if (!data || !Array.isArray(data)) {
      container.innerHTML = '<div>Erro ao carregar components</div>';
      return;
    }

    let html = '';
    html += `<div style="margin-bottom:8px;">
      <label>Quantidade a montar:</label> <input id="components_qty_to_make" type="number" value="1" min="1" style="width:80px;">
      <button id="btn-components-check">Verificar (dry-run)</button>
      <button id="btn-components-reserve">Reservar</button>
      <button id="btn-components-assemble">Montar (consumir)</button>
    </div>`;

    html += '<table class="components-table" style="width:100%;border-collapse:collapse">';
    html += '<thead><tr><th>Componente</th><th>Qtd/Un</th><th>Qtd Total</th><th>Disponível</th><th>Locais</th></tr></thead><tbody>';
    data.forEach(c => {
      const total = (parseFloat(c.quantidade_por_unidade) || 0) * 1;
      html += `<tr data-produto-id="${c.produto_id}">
        <td>${escapeHtml(c.nome)}</td>
        <td style="text-align:center">${c.quantidade_por_unidade}</td>
        <td style="text-align:center"><span class="comp-total">${total}</span></td>
        <td style="text-align:center"><span class="comp-available">--</span></td>
        <td style="text-align:center"><button class="btn-view-local">Ver/Alocar</button></td>
      </tr>`;
    });
    html += '</tbody></table>';
    html += '<pre id="components-output" style="margin-top:8px;background:#fff;padding:8px;border:1px solid #ddd;max-height:220px;overflow:auto"></pre>';
    container.innerHTML = html;

    // attach handlers
    document.getElementById('btn-components-check').addEventListener('click', async () => {
      await runAction('reserve', true);
    });
    document.getElementById('btn-components-reserve').addEventListener('click', async () => {
      if (!confirm('Criar reservas para os componentes?')) return;
      await runAction('reserve', false);
    });
    document.getElementById('btn-components-assemble').addEventListener('click', async () => {
      if (!confirm('Montar e consumir os componentes? (requer local selecionado)')) return;
      await runAction('assemble', false);
    });

    // view local / alocar modal
    container.querySelectorAll('.btn-view-local').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const tr = e.target.closest('tr');
        const pid = tr.getAttribute('data-produto-id');
        openAllocationModal(pid);
      });
    });
  }

  // run kit_allocate with product id on page and quantity
  async function runAction(action, dry_run) {
    const produtoIdEl = document.querySelector('input[name="produto_id_for_kit"]');
    if (!produtoIdEl) return alert('produto_id_for_kit não encontrado na página');
    const produtoId = produtoIdEl.value;
    const qty = document.getElementById('components_qty_to_make').value || 1;

    // use kitApiCall from kit_allocate_ui.js
    const form = new FormData();
    form.append('produto_id', produtoId);
    form.append('quantidade', qty);
    form.append('action', action);
    if (dry_run) form.append('dry_run', '1');

    const res = await fetch('../../api/kit_allocate.php', { method: 'POST', body: form });
    const json = await res.json();
    document.getElementById('components-output').textContent = JSON.stringify(json, null, 2);
  }

  // Allocation modal (simple)
  async function openAllocationModal(produtoId) {
    // create modal
    const modal = document.createElement('div');
    modal.style.position='fixed'; modal.style.left='0'; modal.style.top='0'; modal.style.width='100%'; modal.style.height='100%';
    modal.style.background='rgba(0,0,0,0.4)'; modal.style.display='flex'; modal.style.alignItems='center'; modal.style.justifyContent='center';
    modal.innerHTML = `<div style="background:#fff;padding:16px;max-width:900px;width:90%;border-radius:6px;">
      <h3>Alocar produto #${escapeHtml(produtoId)}</h3>
      <div id="alloc-body">Carregando...</div>
      <div style="margin-top:12px;text-align:right;">
        <button id="alloc-cancel">Fechar</button>
        <button id="alloc-dry">Ver alocação sugerida (dry-run)</button>
      </div>
    </div>`;
    document.body.appendChild(modal);

    document.getElementById('alloc-cancel').addEventListener('click', ()=> modal.remove());
    document.getElementById('alloc-dry').addEventListener('click', async () => {
      // call kit_allocate as dry_run for this product only (we simulate by calling the endpoint with produto_id)
      const prodIdInput = document.querySelector('input[name="produto_id_for_kit"]');
      const qtyInput = document.getElementById('components_qty_to_make');
      const payload = new FormData();
      payload.append('produto_id', prodIdInput.value);
      payload.append('quantidade', qtyInput.value || 1);
      payload.append('action', 'reserve');
      payload.append('dry_run', '1');
      const r = await fetch('../../api/kit_allocate.php', { method:'POST', body: payload });
      const json = await r.json();
      document.getElementById('alloc-body').textContent = JSON.stringify(json, null, 2);
    });

    // Try to show available patrimonios for this produto (if endpoint exists)
    try {
      const r = await fetch(`../../api/patrimonios.php?produto_id=${encodeURIComponent(produtoId)}&available=1`);
      if (r.ok) {
        const arr = await r.json();
        let html = '<div><strong>Patrimônios disponíveis:</strong><ul>';
        arr.forEach(p => html += `<li>${escapeHtml(p.numero_patrimonio || p.numero_serie || '—')} (id:${p.id})</li>`);
        html += '</ul></div>';
        document.getElementById('alloc-body').innerHTML = html;
      }
    } catch (e) {
      // ignore if endpoint missing
    }
  }

  function escapeHtml(str){ return String(str||'').replace(/[&<>"']/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); }

  // Init: when DOM ready, try to find produto_id and render
  document.addEventListener('DOMContentLoaded', () => {
    const pidEl = document.querySelector('input[name="produto_id_for_kit"]');
    if (!pidEl) return;
    const pid = pidEl.value;
    renderComponentsTab(pid);
  });

})();