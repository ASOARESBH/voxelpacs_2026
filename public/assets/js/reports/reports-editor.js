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

    function normalizarTitulo(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function secaoPorTitulo(texto) {
        const titulo = normalizarTitulo(texto);
        return Object.keys(TITULOS).find((chave) => normalizarTitulo(TITULOS[chave]) === titulo) || null;
    }

    /** Extrai o conteúdo atual do editor de volta para {exame, tecnica, achados, conclusao, recomendacao}. */
    function extractSecoes() {
        const secoes = { exame: '', tecnica: '', achados: '', conclusao: '', recomendacao: '' };
        let atual = null;
        let marcadoresEncontrados = 0;
        const nodes = Array.from(quill?.root?.children || []);

        nodes.forEach((node) => {
            if (!node || node.nodeType !== 1) return;

            // Caminho ideal: o marcador foi preservado pelo Clipboard do Quill.
            // Fallback obrigatório: alguns builds removem atributos data-* ao
            // hidratar HTML; nesse caso o título visual H4 continua confiável.
            const marcado = node.dataset && node.dataset.secao;
            const porTitulo = node.tagName === 'H4' ? secaoPorTitulo(node.textContent) : null;
            const proximaSecao = marcado && Object.prototype.hasOwnProperty.call(secoes, marcado)
                ? marcado
                : porTitulo;

            if (proximaSecao) {
                atual = proximaSecao;
                marcadoresEncontrados += 1;
                return;
            }

            if (atual && Object.prototype.hasOwnProperty.call(secoes, atual)) {
                secoes[atual] += node.outerHTML || '';
            }
        });

        // Diagnóstico sem registrar conteúdo clínico: permite identificar no
        // console quando o editor tem texto, mas não recebeu nenhum marcador.
        const temTextoVisivel = String(quill?.root?.textContent || '').trim() !== '';
        if (temTextoVisivel && marcadoresEncontrados === 0) {
            console.warn('[VOXEL Reports] editor sem marcadores de seção', {
                childTags: nodes.map((node) => node.tagName),
                editorChars: String(quill.root.textContent || '').length,
            });
        }

        return secoes;
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }

    return { init, loadSecoes, extractSecoes, getQuill, setReadOnly };
})();
