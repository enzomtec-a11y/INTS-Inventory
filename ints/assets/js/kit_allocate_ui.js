async function kitApiCall(action, dry_run = false) {
    const produtoId = document.querySelector('input[name="produto_id_for_kit"]').value;
    const quantidade = document.querySelector('input[name="kit_quantidade"]').value || 1;
    const localId = document.querySelector('select[name="kit_local_id"]').value || 0;
    const usuarioId = window.LOGGED_USER_ID || null; // optional

    const form = new FormData();
    form.append('produto_id', produtoId);
    form.append('quantidade', quantidade);
    if (localId) form.append('local_id', localId);
    form.append('action', action);
    if (usuarioId) form.append('usuario_id', usuarioId);
    if (dry_run) form.append('dry_run', '1');

    const resp = await fetch('../../api/kit_allocate.php', { method: 'POST', body: form });
    const json = await resp.json();
    return json;
}

document.addEventListener('DOMContentLoaded', function() {
    const btnCheck = document.getElementById('btn-check-kit');
    const btnReserve = document.getElementById('btn-reserve-kit');
    const btnAssemble = document.getElementById('btn-assemble-kit');
    const out = document.getElementById('kit-allocate-output');

    if (btnCheck) {
        btnCheck.addEventListener('click', async () => {
            out.textContent = 'Verificando...';
            const r = await kitApiCall('reserve', true); // dry run
            out.textContent = JSON.stringify(r, null, 2);
        });
    }
    if (btnReserve) {
        btnReserve.addEventListener('click', async () => {
            out.textContent = 'Reservando...';
            const r = await kitApiCall('reserve', false);
            out.textContent = JSON.stringify(r, null, 2);
        });
    }
    if (btnAssemble) {
        btnAssemble.addEventListener('click', async () => {
            if (!confirm('Deseja montar o kit agora e consumir os componentes?')) return;
            out.textContent = 'Processando montagem...';
            const r = await kitApiCall('assemble', false);
            out.textContent = JSON.stringify(r, null, 2);
        });
    }
});

//É necessário declarar produto_id no campo correspondente ao id que deseja inserir de produto/componente