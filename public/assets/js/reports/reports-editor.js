/**
 * VOXEL PACS — Reports / Editor
 *
 * O editor é um documento clínico único. A máscara apenas importa um conteúdo
 * inicial; a fonte de verdade passa a ser sempre o HTML atual do Quill, incluindo
 * palavras, medidas e formatação inseridos pelo médico; o espaçamento
 * estrutural redundante é compactado antes do autosave e da geração de PDF.
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

    function init(config) {
        quill = window.createVoxelQuillEditor('#editor-container', {
            readOnly: !!config.readonly,
            toolbarSelector: '#editor-toolbar',
        });

        normalizeCurrentContent();
        if (quill?.root?.addEventListener) {
            quill.root.addEventListener('paste', () => window.setTimeout(normalizeCurrentContent, 0));
        }

        if (config.readonly) {
            const toolbar = document.getElementById('editor-toolbar');
            if (toolbar) toolbar.style.display = 'none';
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
        html = normalizeClinicalHtml(html) || '<p><br></p>';
        quill.setText('');
        quill.clipboard.dangerouslyPasteHTML(0, html, 'silent');
    }

    /**
     * Fonte única de verdade: captura o HTML atual do Quill sem reinterpretar
     * headings, seções ou conteúdo importado pela máscara. Isso preserva todo
     * texto e toda formatação adicionados pelo médico até o PDF.
     */
    function extractSecoes() {
        const html = normalizeClinicalHtml(quill?.root?.innerHTML || '');
        return { corpo: html || '<p><br></p>' };
    }

    function loadConteudoLivre(html) {
        html = normalizeClinicalHtml(html || '') || '<p><br></p>';
        quill.setText('');
        quill.clipboard.dangerouslyPasteHTML(0, html, 'silent');
    }

    function normalizeClinicalHtml(html) {
        const normalizer = window.VoxelQuill?.factory?.normalizeClinicalHtml;
        return typeof normalizer === 'function' ? normalizer(html) : String(html || '').trim();
    }

    function normalizeCurrentContent() {
        if (!quill?.root) return;
        const current = quill.root.innerHTML || '';
        const normalized = normalizeClinicalHtml(current);
        if (!normalized || normalized === current || !quill.clipboard?.dangerouslyPasteHTML) return;
        const selection = typeof quill.getSelection === 'function' ? quill.getSelection() : null;
        quill.clipboard.dangerouslyPasteHTML(0, normalized, 'silent');
        if (selection && typeof quill.setSelection === 'function') {
            quill.setSelection(Math.min(selection.index, Math.max(0, quill.getLength() - 1)), 0, 'silent');
        }
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }
    function isDocumentoLivre() { return true; }

    return { init, loadSecoes, loadConteudoLivre, extractSecoes, getQuill, setReadOnly, isDocumentoLivre };
})();
