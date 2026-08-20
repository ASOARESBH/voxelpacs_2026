/**
 * VOXEL PACS — Reports / Máscaras de Laudo
 *
 * Carrega Máscaras compatíveis com as modalidades DICOM do estudo e oferece
 * aplicação automática por Study Description, além da busca inline do painel
 * lateral. A mesma tabela report_templates é usada pelo cadastro de Máscaras.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.templates = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let lastPayload = null;
    let templatesRequest = null;
    let search = null;
    let visibleTemplates = [];
    let activeIndex = -1;

    function escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function normalizarBusca(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase('pt-BR')
            .trim();
    }

    function isEditorVazio() {
        const secoes = editor.extractSecoes();
        return Object.values(secoes).every((html) => !html || html.replace(/<[^>]+>/g, '').trim() === '');
    }

    function conteudoLivre(template) {
        if (typeof template?.conteudo_livre === 'string' && template.conteudo_livre.trim() !== '') {
            return template.conteudo_livre;
        }
        try {
            const conteudo = typeof template?.conteudo === 'string' ? JSON.parse(template.conteudo) : null;
            return typeof conteudo?.corpo === 'string' ? conteudo.corpo : '';
        } catch (_) {
            return '';
        }
    }

    function atualizarTituloDocumento(titulo) {
        const elemento = document.getElementById('reports-editor-document-title');
        if (!elemento) return;

        const texto = String(titulo || '').trim();
        elemento.textContent = texto;
        elemento.hidden = texto === '';
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
        if (!template?.id) return false;
        if (confirmar && !isEditorVazio()
            && !confirm('Substituir o conteúdo atual do laudo por esta máscara?')) return false;

        const livre = conteudoLivre(template);
        if (livre !== '') {
            editor.loadConteudoLivre(livre);
        } else {
            // Máscaras legadas continuam disponíveis sem conversão destrutiva.
            editor.loadSecoes(parseSecoes(template), ['tecnica', 'achados', 'conclusao']);
        }
        atualizarTituloDocumento(template.titulo);
        config.templateId = Number(template.id) || 0;
        window.VoxelReports.autosave.setTemplateId(config.templateId);
        window.VoxelReports.autosave.save('rascunho');
        return true;
    }

    async function carregarTemplates() {
        if (lastPayload) return lastPayload;
        if (templatesRequest) return templatesRequest;

        const modalidades = (config.modalidades || []).join(',');
        const params = new URLSearchParams();
        if (modalidades) params.set('modalidades', modalidades);
        if (config.studyDescription) params.set('study_description', config.studyDescription);

        templatesRequest = fetch(`/reports/templates?${params.toString()}`, { credentials: 'same-origin' })
            .then(async (response) => {
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.msg || 'Falha ao carregar máscaras.');
                lastPayload = payload;
                return payload;
            })
            .catch((error) => {
                templatesRequest = null;
                throw error;
            });
        return templatesRequest;
    }

    function sugeridosIds() {
        return new Set((lastPayload?.sugeridos || []).map((template) => String(template.id)));
    }

    function filtrarTemplates(consulta) {
        const templates = Array.isArray(lastPayload?.templates) ? lastPayload.templates : [];
        const termo = normalizarBusca(consulta);
        if (!termo) return templates;
        return templates.filter((template) => {
            const nome = normalizarBusca(template.titulo || template.nome);
            const modalidade = normalizarBusca(template.modalidade);
            const descricao = normalizarBusca(template.study_description_tag);
            return nome.includes(termo) || modalidade.includes(termo) || descricao.includes(termo);
        });
    }

    function atualizarEstadoAtivo() {
        if (!search) return;
        const options = search.dropdown.querySelectorAll('.reports-mascara-search-option');
        options.forEach((option, index) => {
            const ativo = index === activeIndex;
            option.classList.toggle('is-active', ativo);
            option.setAttribute('aria-selected', ativo ? 'true' : 'false');
            if (ativo) {
                search.input.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            }
        });
        if (activeIndex < 0) search.input.setAttribute('aria-activedescendant', '');
    }

    function renderizarDropdown() {
        if (!search || !lastPayload) return;
        visibleTemplates = filtrarTemplates(search.input.value);
        activeIndex = visibleTemplates.length ? Math.min(Math.max(activeIndex, 0), visibleTemplates.length - 1) : -1;

        if (!visibleTemplates.length) {
            const temBusca = normalizarBusca(search.input.value) !== '';
            const modalidades = (config.modalidades || []).join(', ') || config.modalidade || '—';
            search.dropdown.innerHTML = `<span class="reports-mascara-search-message">${temBusca
                ? 'Nenhuma máscara encontrada para a busca informada.'
                : `Nenhuma máscara cadastrada para as modalidades "${escapeHtml(modalidades)}".`}</span>`;
            return;
        }

        const sugeridos = sugeridosIds();
        search.dropdown.innerHTML = visibleTemplates.map((template, index) => {
            const titulo = escapeHtml(template.titulo || template.nome || 'Máscara sem título');
            const modalidade = escapeHtml(template.modalidade || 'Todas as modalidades');
            const tag = escapeHtml(template.study_description_tag || '');
            const sugerida = sugeridos.has(String(template.id));
            const meta = sugerida && tag ? `(0008,1030) ${tag}` : modalidade;
            return `<button type="button"
                            id="mascara-search-option-${Number(template.id) || index}"
                            class="reports-mascara-search-option"
                            role="option"
                            aria-selected="false"
                            data-template-id="${Number(template.id) || 0}">
                        <span class="reports-mascara-search-option-title">${titulo}${sugerida ? '<span class="reports-mascara-search-option-suggested">Sugerida</span>' : ''}</span>
                        <span class="reports-mascara-search-option-meta">${meta}</span>
                    </button>`;
        }).join('');
        atualizarEstadoAtivo();
    }

    function mostrarMensagem(carregando, erro) {
        if (!search) return;
        search.dropdown.innerHTML = `<span class="reports-mascara-search-message${erro ? ' is-error' : ''}">${escapeHtml(carregando)}</span>`;
    }

    function atualizarLimpar() {
        if (!search) return;
        search.clear.hidden = search.input.value === '';
    }

    function fecharBusca({ limpar = true } = {}) {
        if (!search) return;
        search.dropdown.hidden = true;
        search.input.setAttribute('aria-expanded', 'false');
        search.input.setAttribute('aria-activedescendant', '');
        activeIndex = -1;
        if (limpar) search.input.value = '';
        atualizarLimpar();
    }

    async function abrirBusca() {
        if (!search) return;
        search.dropdown.hidden = false;
        search.input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
        atualizarLimpar();
        if (lastPayload) {
            renderizarDropdown();
            return;
        }

        mostrarMensagem('Carregando máscaras...');
        try {
            await carregarTemplates();
            renderizarDropdown();
        } catch (error) {
            mostrarMensagem(error.message || 'Falha ao carregar máscaras.', true);
        }
    }

    function selecionarAtiva() {
        const template = visibleTemplates[activeIndex];
        if (template && applyTemplate(template)) fecharBusca();
    }

    function vincularBuscaInline() {
        const input = document.getElementById('mascara-search-input');
        const dropdown = document.getElementById('mascara-search-dropdown');
        const clear = document.getElementById('mascara-search-clear');
        const card = document.getElementById('mascara-search-card');
        if (!input || !dropdown || !clear || !card) return;

        search = { input, dropdown, clear, card };
        input.addEventListener('focus', abrirBusca);
        input.addEventListener('input', () => {
            atualizarLimpar();
            activeIndex = -1;
            if (dropdown.hidden) abrirBusca();
            else if (lastPayload) renderizarDropdown();
        });
        input.addEventListener('keydown', async (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                fecharBusca();
                input.blur();
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (dropdown.hidden) await abrirBusca();
                if (!visibleTemplates.length) return;
                activeIndex = event.key === 'ArrowDown'
                    ? (activeIndex + 1) % visibleTemplates.length
                    : (activeIndex - 1 + visibleTemplates.length) % visibleTemplates.length;
                atualizarEstadoAtivo();
                return;
            }
            if (event.key === 'Enter' && !dropdown.hidden && activeIndex >= 0) {
                event.preventDefault();
                selecionarAtiva();
            }
        });
        clear.addEventListener('click', () => {
            input.value = '';
            activeIndex = -1;
            atualizarLimpar();
            input.focus();
            if (lastPayload) renderizarDropdown();
        });
        dropdown.addEventListener('click', (event) => {
            const option = event.target.closest('.reports-mascara-search-option');
            if (!option) return;
            const template = visibleTemplates.find((item) => String(item.id) === option.dataset.templateId);
            if (template && applyTemplate(template)) fecharBusca();
        });
        document.addEventListener('mousedown', (event) => {
            if (!card.contains(event.target)) fecharBusca();
        });
    }

    async function sugerirAutomaticamente() {
        if (config.readonly || config.templateId > 0 || !isEditorVazio() || !config.studyDescription) return;
        try {
            const payload = await carregarTemplates();
            const suggested = Array.isArray(payload.sugeridos) ? payload.sugeridos : [];
            if (suggested.length) applyTemplate(suggested[0], { confirmar: false });
        } catch (_) {
            // A indisponibilidade de sugestão nunca impede a abertura do Laudário.
        }
    }

    function init(cfg) {
        config = cfg;
        vincularBuscaInline();
        sugerirAutomaticamente();
    }

    return {
        init,
        open: abrirBusca,
        applyTemplate,
        lastPayload: () => lastPayload,
    };
})();
