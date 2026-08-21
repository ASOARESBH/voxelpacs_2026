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

    function init(config) {
        quill = window.createVoxelQuillEditor('#editor-container', {
            readOnly: !!config.readonly,
            toolbarSelector: '#editor-toolbar',
        });

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
