/**
 * VOXEL PACS — Reports / Editor
 *
 * O editor é um documento clínico único. A máscara apenas importa um conteúdo
 * inicial; a fonte de verdade passa a ser sempre o HTML atual do Quill, incluindo
 * palavras, medidas, formatação e espaçamento inseridos pelo médico.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.editor = (function () {
    let quill = null;

    const SECOES = ['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'];
    const TITULOS = {
        exame: 'Exame',
        tecnica: 'Técnica',
        achados: 'Achados',
        conclusao: 'Impressão',
        recomendacao: 'Recomendação',
    };

    const SPACING_VALUES = new Set(['compact', 'normal', 'medium', 'wide']);
    const A4_CSS_PX = 1122.52;

    function bindSpacingControls(config) {
        const select = document.getElementById('editor-spacing-select');
        if (!select || config.readonly) return;

        select.addEventListener('change', () => {
            const value = SPACING_VALUES.has(select.value) ? select.value : 'normal';
            const range = quill.getSelection(true);
            if (!range) return;
            quill.formatLine(range.index, Math.max(1, range.length), 'spacing', value, 'user');
        });

        quill.on('selection-change', (range) => {
            if (!range) return;
            const format = quill.getFormat(range.index, 1);
            select.value = SPACING_VALUES.has(format.spacing) ? format.spacing : 'normal';
        });
    }

    function bindPageGuide(config) {
        const toggle = document.getElementById('editor-page-guide');
        const container = document.getElementById('editor-container');
        const card = container ? container.closest('.reports-editor-card') : null;
        if (!toggle || !container || !card || config.readonly) return;

        const label = container.dataset.pageGuideLabel || '';
        let guides = [];
        const clear = () => {
            guides.forEach((guide) => guide.remove());
            guides = [];
        };
        const render = () => {
            clear();
            if (!toggle.checked) return;

            const cardRect = card.getBoundingClientRect();
            const editorRect = quill.root.getBoundingClientRect();
            const contentBottom = (editorRect.top - cardRect.top) + quill.root.scrollHeight;
            const guideCount = Math.max(0, Math.ceil(contentBottom / A4_CSS_PX) - 1);
            for (let page = 1; page <= guideCount; page += 1) {
                const guide = document.createElement('div');
                guide.className = 'reports-editor-page-guide';
                guide.dataset.label = label;
                guide.style.top = `${page * A4_CSS_PX}px`;
                guide.setAttribute('aria-hidden', 'true');
                card.appendChild(guide);
                guides.push(guide);
            }
        };

        toggle.addEventListener('change', render);
        quill.on('text-change', () => window.requestAnimationFrame(render));
        window.addEventListener('resize', () => window.requestAnimationFrame(render));
        if (window.ResizeObserver) {
            new ResizeObserver(() => window.requestAnimationFrame(render)).observe(quill.root);
        }
    }

    function init(config) {
        quill = window.createVoxelQuillEditor('#editor-container', {
            readOnly: !!config.readonly,
            toolbarSelector: '#editor-toolbar',
        });

        if (config.readonly) {
            const toolbar = document.getElementById('editor-toolbar');
            if (toolbar) toolbar.style.display = 'none';
        } else {
            bindSpacingControls(config);
            bindPageGuide(config);
        }

        return quill;
    }

    /**
     * Mantém compatibilidade visual com máscaras estruturadas legadas, mas o
     * conteúdo exibido continua sendo salvo como um único documento clínico.
     */
    function loadSecoes(secoes, chaves = SECOES) {
        let html = '';
        chaves.forEach((chave) => {
            const conteudo = String(secoes[chave] || '');
            if (conteudo.trim() === '') return;
            html += `<h4 data-secao="${chave}">${TITULOS[chave]}</h4>`;
            html += conteudo;
        });
        if (html === '') html = '<p><br></p>';
        quill.setText('');
        quill.clipboard.dangerouslyPasteHTML(0, html, 'silent');
    }

    /**
     * Fonte única de verdade: captura o HTML atual do Quill sem reinterpretar
     * headings, seções ou conteúdo importado pela máscara. Isso preserva todo
     * texto e toda formatação adicionados pelo médico até o PDF.
     */
    function extractSecoes() {
        return { corpo: quill?.root?.innerHTML || '<p><br></p>' };
    }

    function loadConteudoLivre(html) {
        quill.setText('');
        quill.clipboard.dangerouslyPasteHTML(0, html || '<p><br></p>', 'silent');
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }
    function isDocumentoLivre() { return true; }

    return { init, loadSecoes, loadConteudoLivre, extractSecoes, getQuill, setReadOnly, isDocumentoLivre };
})();
