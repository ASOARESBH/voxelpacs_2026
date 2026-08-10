/**
 * VOXEL PACS — Reports / Assinatura
 * Autenticação 100% por sessão (sem senha/CRM manual — decisão de negócio, ver
 * diagnostics/pendencias-conhecidas.md). Modal é só confirmação, com dois modos
 * de finalização → POST /reports/sign. Ctrl+Enter abre o modal.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.signature = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let modal = null;
    let enviando = false;

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

    /** Item 2 — não abre o modal se todas as seções do laudo estiverem vazias. */
    function laudoEstaVazio() {
        const secoes = editor.extractSecoes();
        return Object.values(secoes).every((html) => {
            const texto = String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
            return texto === '';
        });
    }

    function chatPendente() {
        return !!(window.VoxelReports.chat && window.VoxelReports.chat.hasPending());
    }

    function open() {
        if (chatPendente()) {
            alert('Existe uma pendência aberta no CHAT. Conclua a conversa antes de assinar ou finalizar o laudo.');
            return;
        }
        if (laudoEstaVazio()) {
            alert('Não é possível assinar um laudo em branco. Salve o conteúdo antes de assinar.');
            return;
        }
        limparErro();
        modal.show();
    }

    function confirmar(modo) {
        if (enviando) return;
        if (chatPendente()) {
            mostrarErro('Existe uma pendência aberta no CHAT. Conclua a conversa antes de assinar ou finalizar o laudo.');
            return;
        }
        limparErro();
        enviando = true;

        // O conteúdo atual precisa estar persistido antes da assinatura. Não
        // use finally(): ele chamava /reports/sign mesmo após SQL/CSRF falhar.
        window.VoxelReports.autosave.save('rascunho')
            .then((saveData) => {
                if (!saveData || !saveData.ok) {
                    enviando = false;
                    mostrarErro((saveData && saveData.msg) || 'Não foi possível salvar o conteúdo antes de assinar.');
                    return null;
                }
                return fetch('/reports/sign', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                    body: JSON.stringify({ report_id: config.reportId, modo }),
                });
            })
            .then((response) => response ? response.json() : null)
            .then((data) => {
                if (!data) return;
                enviando = false;
                if (!data.ok) {
                    mostrarErro(data.msg || 'Não foi possível assinar o laudo.');
                    return;
                }
                modal.hide();
                if (modo === 'fechar') {
                    window.location.href = '/estudos';
                    return;
                }
                window.location.reload();
            })
            .catch(() => { enviando = false; mostrarErro('Falha de comunicação ao assinar o laudo.'); });
    }

    function init(cfg) {
        config = cfg;
        if (config.readonly) return;

        const modalEl = document.getElementById('modalAssinatura');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);

                const btnSign = document.getElementById('btn-sign');
        if (btnSign) btnSign.addEventListener('click', open);
        document.addEventListener('reports:chat-status', (event) => {
            const pending = !!event.detail?.pending;
            if (btnSign) btnSign.disabled = pending;
        });

        const btnSomente = document.getElementById('btn-assinar-somente');
        if (btnSomente) btnSomente.addEventListener('click', () => confirmar('somente'));

        const btnFechar = document.getElementById('btn-assinar-fechar');
        if (btnFechar) btnFechar.addEventListener('click', () => confirmar('fechar'));

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                open();
            }
        });
    }

    return { init };
})();
