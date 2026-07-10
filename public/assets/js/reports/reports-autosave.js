/**
 * VOXEL PACS — Reports / Autosave
 * A cada 30s (e em Ctrl+S / Salvar Rascunho), envia as seções extraídas do
 * editor para /reports/save, sem reload. Mostra "salvo há Xs" no topo.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.autosave = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let lastPayload = null;
    let lastSavedAt = null;
    let saving = false;

    function statusEl() { return document.getElementById('autosave-status'); }

    function tickStatusText() {
        const el = statusEl();
        if (!el) return;
        if (!lastSavedAt) { el.textContent = ''; return; }
        const segundos = Math.max(0, Math.round((Date.now() - lastSavedAt) / 1000));
        el.textContent = segundos < 5 ? 'salvo agora' :
            segundos < 60 ? `salvo há ${segundos}s` :
                `salvo há ${Math.round(segundos / 60)}min`;
    }

    function save(modo) {
        if (config.readonly || saving) return Promise.resolve(null);

        const secoes = editor.extractSecoes();
        const payload = JSON.stringify(secoes);

        // Autosave silencioso: não repete POST se nada mudou desde o último save.
        if (modo === 'auto' && payload === lastPayload) return Promise.resolve(null);

        saving = true;
        return fetch('/reports/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
            body: JSON.stringify({ report_id: config.reportId, secoes, modo }),
        })
            .then((r) => r.json())
            .then((data) => {
                saving = false;
                if (data && data.ok) {
                    lastPayload = payload;
                    lastSavedAt = Date.now();
                    tickStatusText();
                }
                return data;
            })
            .catch(() => { saving = false; return null; });
    }

    function init(cfg) {
        config = cfg;
        if (config.readonly) return;

        setInterval(() => save('auto'), 30000);
        setInterval(tickStatusText, 1000);

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                save('salvar');
            }
        });

        const btnDraft = document.getElementById('btn-save-draft');
        if (btnDraft) btnDraft.addEventListener('click', () => save('rascunho'));
    }

    return { init, save };
})();
