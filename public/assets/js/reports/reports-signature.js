/**
 * VOXEL PACS — Reports / Assinatura
 * Modal de senha + CRM → POST /reports/sign. Ctrl+Enter abre o modal.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.signature = (function () {
    let config = null;
    let modal = null;

    function mostrarErro(msg) {
        const el = document.getElementById('assinatura-erro');
        if (!el) return;
        el.textContent = msg;
        el.style.display = 'block';
    }

    function limparErro() {
        const el = document.getElementById('assinatura-erro');
        if (el) el.style.display = 'none';
    }

    function open() {
        limparErro();
        document.getElementById('assinatura-senha').value = '';
        modal.show();
    }

    function confirmar() {
        const senha = document.getElementById('assinatura-senha').value;
        const crm = document.getElementById('assinatura-crm').value.trim();
        limparErro();

        if (!senha) { mostrarErro('Informe sua senha para assinar.'); return; }

        // Garante que o conteúdo atual está salvo antes de assinar.
        window.VoxelReports.autosave.save('rascunho').finally(() => {
            fetch('/reports/sign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                body: JSON.stringify({ report_id: config.reportId, senha, crm }),
            })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.ok) {
                        mostrarErro(data.error === 'senha_invalida' ? 'Senha incorreta.' : 'Não foi possível assinar o laudo.');
                        return;
                    }
                    modal.hide();
                    window.location.reload();
                })
                .catch(() => mostrarErro('Falha de comunicação ao assinar o laudo.'));
        });
    }

    function init(cfg) {
        config = cfg;
        if (config.readonly) return;

        const modalEl = document.getElementById('modalAssinatura');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);

        const btnSign = document.getElementById('btn-sign');
        if (btnSign) btnSign.addEventListener('click', open);

        const btnConfirmar = document.getElementById('btn-confirmar-assinatura');
        if (btnConfirmar) btnConfirmar.addEventListener('click', confirmar);

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                open();
            }
        });
    }

    return { init };
})();
