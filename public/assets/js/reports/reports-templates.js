/**
 * VOXEL PACS — Reports / Templates
 * Modal com modelos de laudo por modalidade; ao aplicar, substitui o
 * conteúdo do editor (com confirmação se já houver texto).
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.templates = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let modal = null;

    function isEditorVazio() {
        const secoes = editor.extractSecoes();
        return Object.values(secoes).every((html) => !html || html.replace(/<[^>]+>/g, '').trim() === '');
    }

    function render(templates) {
        const list = document.getElementById('templates-list');
        if (!templates.length) {
            list.innerHTML = `<p class="text-pacs-muted">Nenhum template cadastrado para a modalidade "${config.modalidade || '—'}".</p>`;
            return;
        }
        list.innerHTML = templates.map((t) => `
            <div class="template-item" data-id="${t.id}">
                <strong>${t.titulo}</strong>
                <div class="text-pacs-muted" style="font-size:.7rem;">${t.modalidade}</div>
            </div>
        `).join('');

        list.querySelectorAll('.template-item').forEach((el) => {
            el.addEventListener('click', () => {
                const template = templates.find((t) => String(t.id) === el.dataset.id);
                if (!template) return;
                if (!isEditorVazio() && !confirm('Substituir o conteúdo atual do laudo por este template?')) return;

                const conteudo = typeof template.conteudo === 'string' ? JSON.parse(template.conteudo) : template.conteudo;
                editor.loadSecoes(conteudo.secoes || {});
                window.VoxelReports.autosave.save('rascunho');
                modal.hide();
            });
        });
    }

    function open() {
        const list = document.getElementById('templates-list');
        list.innerHTML = '<p class="text-pacs-muted">Carregando templates...</p>';
        modal.show();

        fetch(`/reports/templates?modalidade=${encodeURIComponent(config.modalidade || '')}`)
            .then((r) => r.json())
            .then((data) => render(data.templates || []))
            .catch(() => { list.innerHTML = '<p class="text-pacs-muted">Falha ao carregar templates.</p>'; });
    }

    function init(cfg) {
        config = cfg;
        const modalEl = document.getElementById('modalTemplates');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);

        const btn = document.getElementById('btn-template');
        if (btn) btn.addEventListener('click', open);
    }

    return { init, open };
})();
