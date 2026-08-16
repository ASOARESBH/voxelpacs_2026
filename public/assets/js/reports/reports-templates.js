/**
 * VOXEL PACS — Reports / Templates
 *
 * Lista Máscaras compatíveis com todas as modalidades DICOM do estudo. Templates
 * vinculados à TAG Study Description (0008,1030) são priorizados; quando o
 * editor está vazio, o primeiro vínculo é aplicado automaticamente sem apagar
 * conteúdo clínico já iniciado pelo médico.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.templates = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let modal = null;
    let lastPayload = null;

    function escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function isEditorVazio() {
        const secoes = editor.extractSecoes();
        return Object.values(secoes).every((html) => !html || html.replace(/<[^>]+>/g, '').trim() === '');
    }

    function atualizarTituloDocumento(titulo) {
        const elemento = document.getElementById('reports-modern-document-title');
        const texto = String(titulo || '').trim();
        if (elemento && texto !== '') elemento.textContent = texto;
    }

    function parseSecoes(template) {
        if (template && typeof template.secoes === 'object' && template.secoes) return template.secoes;
        if (typeof template?.conteudo !== 'string') return {};
        try {
            const conteudo = JSON.parse(template.conteudo);
            return conteudo?.secoes || {};
        } catch (_) {
            return {};
        }
    }

    function applyTemplate(template, options = {}) {
        const confirmar = options.confirmar !== false;
        const fecharModal = options.fecharModal !== false;
        if (!template?.id) return false;
        if (confirmar && !isEditorVazio()
            && !confirm('Substituir o conteúdo atual do laudo por este template?')) return false;

        const secoes = parseSecoes(template);
        // A criação de Máscara disponibiliza somente TÉCNICA, ACHADOS e
        // IMPRESSÃO. Não inserir marcadores legados no editor clínico.
        editor.loadSecoes(secoes, ['tecnica', 'achados', 'conclusao']);
        atualizarTituloDocumento(template.titulo);
        config.templateId = Number(template.id) || 0;
        window.VoxelReports.autosave.setTemplateId(config.templateId);
        window.VoxelReports.autosave.save('rascunho');
        if (fecharModal) modal?.hide();
        return true;
    }

    function renderTemplate(template, suggested) {
        const title = escapeHtml(template.titulo);
        const modality = escapeHtml(template.modalidade || 'Todas as modalidades');
        const tag = escapeHtml(template.study_description_tag || '');
        const badge = suggested
            ? '<span class="badge bg-primary-subtle text-primary-emphasis ms-2">Sugerido pela TAG DICOM</span>'
            : '';
        const detail = suggested && tag
            ? `<div class="text-pacs-muted" style="font-size:.7rem;">(0008,1030) ${tag}</div>`
            : `<div class="text-pacs-muted" style="font-size:.7rem;">${modality}</div>`;
        return `<div class="template-item${suggested ? ' template-item-suggested' : ''}" data-id="${Number(template.id) || 0}">
            <strong>${title}${badge}</strong>${detail}
        </div>`;
    }

    function bindTemplateActions(templates) {
        document.querySelectorAll('#templates-list .template-item').forEach((element) => {
            element.addEventListener('click', () => {
                const template = templates.find((item) => String(item.id) === element.dataset.id);
                if (template) applyTemplate(template);
            });
        });
    }

    function render(payload) {
        const list = document.getElementById('templates-list');
        const templates = Array.isArray(payload?.templates) ? payload.templates : [];
        const suggested = Array.isArray(payload?.sugeridos) ? payload.sugeridos : [];
        const suggestedIds = new Set(suggested.map((template) => String(template.id)));
        const remaining = templates.filter((template) => !suggestedIds.has(String(template.id)));

        if (!templates.length) {
            const modalities = (config.modalidades || []).join(', ') || config.modalidade || '—';
            list.innerHTML = `<p class="text-pacs-muted">Nenhum template cadastrado para as modalidades "${escapeHtml(modalities)}".</p>`;
            return;
        }

        let html = '';
        if (suggested.length) {
            const description = escapeHtml(payload.study_description || config.studyDescription || '—');
            html += `<div class="small text-primary fw-semibold mb-2"><i class="fa fa-wand-magic-sparkles me-1"></i>Vinculados a este estudo: ${description}</div>`;
            html += suggested.map((template) => renderTemplate(template, true)).join('');
        }
        if (remaining.length) {
            html += suggested.length ? '<div class="small text-pacs-muted mt-3 mb-2">Outros templates compatíveis</div>' : '';
            html += remaining.map((template) => renderTemplate(template, false)).join('');
        }
        list.innerHTML = html;
        bindTemplateActions(templates);
    }

    async function carregarTemplates() {
        const modalidades = (config.modalidades || []).join(',');
        const params = new URLSearchParams();
        if (modalidades) params.set('modalidades', modalidades);
        if (config.studyDescription) params.set('study_description', config.studyDescription);

        const response = await fetch(`/reports/templates?${params.toString()}`, { credentials: 'same-origin' });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.msg || 'Falha ao carregar templates.');
        lastPayload = payload;
        return payload;
    }

    async function open() {
        const list = document.getElementById('templates-list');
        list.innerHTML = '<p class="text-pacs-muted">Carregando templates...</p>';
        modal.show();
        try {
            render(await carregarTemplates());
        } catch (error) {
            list.innerHTML = `<p class="text-danger">${escapeHtml(error.message || 'Falha ao carregar templates.')}</p>`;
        }
    }

    async function sugerirAutomaticamente() {
        if (config.readonly || config.templateId > 0 || !isEditorVazio() || !config.studyDescription) return;
        try {
            const payload = await carregarTemplates();
            const suggested = Array.isArray(payload.sugeridos) ? payload.sugeridos : [];
            if (!suggested.length) return;

            // Aplica somente a primeira sugestão ordenada pelo backend (médico
            // proprietário antes de compartilhada/global). Se houver alternativas,
            // apresenta-as imediatamente para decisão do médico.
            if (!applyTemplate(suggested[0], { confirmar: false, fecharModal: false })) return;
            if (suggested.length > 1) {
                render(payload);
                modal.show();
            }
        } catch (_) {
            // A indisponibilidade de sugestão nunca impede a abertura do Laudário.
        }
    }

    function init(cfg) {
        config = cfg;
        const modalEl = document.getElementById('modalTemplates');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);
        const btn = document.getElementById('btn-template');
        if (btn) btn.addEventListener('click', open);
        sugerirAutomaticamente();
    }

    return { init, open, applyTemplate, lastPayload: () => lastPayload };
})();
