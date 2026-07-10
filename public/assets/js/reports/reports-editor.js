/**
 * VOXEL PACS — Reports / Editor
 * Um único documento Quill contínuo, com headings <h4 data-secao="..."> marcando
 * cada seção do laudo. extractSecoes()/loadSecoes() fazem o round-trip com o backend.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.editor = (function () {
    let quill = null;

    const SECOES = ['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'];
    const TITULOS = {
        exame: 'Exame',
        tecnica: 'Técnica',
        achados: 'Achados',
        conclusao: 'Conclusão',
        recomendacao: 'Recomendação',
    };

    function insertBasicTable() {
        const range = quill.getSelection(true);
        const html = '<table><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table><p><br></p>';
        quill.clipboard.dangerouslyPasteHTML(range ? range.index : quill.getLength(), html, 'user');
    }

    function init(config) {
        quill = new Quill('#editor-container', {
            theme: 'snow',
            readOnly: !!config.readonly,
            modules: {
                toolbar: config.readonly ? false : {
                    container: '#editor-toolbar',
                    handlers: {
                        table: insertBasicTable,
                        undo: () => quill.history.undo(),
                        redo: () => quill.history.redo(),
                    },
                },
                history: { delay: 1000, maxStack: 200, userOnly: true },
                table: false,
            },
        });

        if (config.readonly) {
            const toolbar = document.getElementById('editor-toolbar');
            if (toolbar) toolbar.style.display = 'none';
        }

        return quill;
    }

    /** Reconstrói o documento inteiro a partir de {exame, tecnica, achados, conclusao, recomendacao} */
    function loadSecoes(secoes) {
        let html = '';
        SECOES.forEach((chave) => {
            html += `<h4 data-secao="${chave}">${TITULOS[chave]}</h4>`;
            html += secoes[chave] && secoes[chave].trim() !== '' ? secoes[chave] : '<p><br></p>';
        });
        quill.setText('');
        quill.clipboard.dangerouslyPasteHTML(0, html, 'silent');
    }

    /** Extrai o conteúdo atual do editor de volta para {exame, tecnica, achados, conclusao, recomendacao} */
    function extractSecoes() {
        const secoes = { exame: '', tecnica: '', achados: '', conclusao: '', recomendacao: '' };
        let atual = null;

        Array.from(quill.root.childNodes).forEach((node) => {
            if (node.nodeType !== 1) return;
            if (node.tagName === 'H4' && node.dataset && node.dataset.secao) {
                atual = node.dataset.secao;
                return;
            }
            if (atual && secoes.hasOwnProperty(atual)) {
                secoes[atual] += node.outerHTML || '';
            }
        });

        return secoes;
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }

    return { init, loadSecoes, extractSecoes, getQuill, setReadOnly };
})();
