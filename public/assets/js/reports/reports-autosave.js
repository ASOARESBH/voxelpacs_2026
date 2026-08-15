/**
 * VOXEL PACS — Reports / Autosave
 * A cada 30s (e em Ctrl+S / Salvar Rascunho), envia as seções extraídas do
 * editor para /reports/save, sem reload. Mostra "salvo há Xs" no topo.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.autosave = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let templateId = 0;
    let lastPayload = null;
    let lastSavedAt = null;
    let saving = false;
    let savingPromise = null;

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
        if (!config || config.readonly) return Promise.resolve({ ok: false, msg: 'Editor em modo somente leitura.' });
        if (saving) return savingPromise || Promise.resolve({ ok: false, msg: 'Salvamento em andamento.' });

        const secoes = editor.extractSecoes();
        const payload = JSON.stringify(secoes);

        // Autosave silencioso: não repete POST se nada mudou desde o último save.
        if (modo === 'auto' && payload === lastPayload) return Promise.resolve({ ok: true, skipped: true });

        saving = true;
        const request = fetch('/reports/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
            body: JSON.stringify({
                report_id: config.reportId,
                secoes,
                modo,
                template_id: templateId || null,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data && data.ok) {
                    lastPayload = payload;
                    lastSavedAt = Date.now();
                    const el = statusEl();
                    if (el) el.classList.remove('autosave-error');
                    tickStatusText();
                } else {
                    const el = statusEl();
                    if (el) {
                        el.textContent = (data && data.msg) || 'Falha ao salvar';
                        el.classList.add('autosave-error');
                    }
                }
                return data || { ok: false, msg: 'Resposta inválida do servidor.' };
            })
            .catch((error) => {
                const el = statusEl();
                if (el) {
                    el.textContent = 'Falha de comunicação ao salvar';
                    el.classList.add('autosave-error');
                }
                return { ok: false, msg: error && error.message ? error.message : 'Falha de comunicação ao salvar' };
            })
            .finally(() => {
                saving = false;
                savingPromise = null;
            });

        savingPromise = request;
        return request;
    }

    function markSaved() {
        if (!config || !editor) return;
        lastPayload = JSON.stringify(editor.extractSecoes());
        lastSavedAt = Date.now();
        const el = statusEl();
        if (el) el.classList.remove('autosave-error');
        tickStatusText();
    }

    function init(cfg) {
        config = cfg;
        templateId = Number(config.templateId) || 0;
        if (config.readonly) return;

        const status = statusEl();
        if (status) status.classList.remove('autosave-error');

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

    function setTemplateId(id) {
        templateId = Number(id) || 0;
        if (config) config.templateId = templateId;
    }

    return { init, save, markSaved, setTemplateId };
})();
