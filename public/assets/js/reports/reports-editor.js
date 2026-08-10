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

    // Qualquer n\u00edvel de heading conta como candidato a marcador \u2014 a toolbar
    // (#editor-toolbar, select.ql-header) deixa o m\u00e9dico formatar um par\u00e1grafo
    // como H1/H2/H3, n\u00e3o s\u00f3 H4; restringir a checagem a H4 deixava esses casos
    // sempre cair como "conte\u00fado comum" mesmo quando o texto batia com um dos
    // 5 t\u00edtulos can\u00f4nicos.
    const HEADING_TAGS = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6'];

    function normalizarTitulo(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim()
            // Tolera pontua\u00e7\u00e3o de fechamento comum em cabe\u00e7alhos digitados \u00e0
            // m\u00e3o (ex.: "T\u00e9cnica:") \u2014 o backend espera a chave sem pontua\u00e7\u00e3o.
            .replace(/[:\-\u2013\u2014]+$/g, '')
            .trim()
            .replace(/\s+/g, ' ');
    }

    function secaoPorTitulo(texto) {
        const titulo = normalizarTitulo(texto);
        return Object.keys(TITULOS).find((chave) => normalizarTitulo(TITULOS[chave]) === titulo) || null;
    }

    /**
     * Extrai o conteúdo atual do editor de volta para
     * {exame, tecnica, achados, conclusao, recomendacao}.
     *
     * Garantia inegociável: NUNCA retornar as 5 seções vazias quando há texto
     * visível no editor. A versão anterior descartava o documento inteiro
     * quando o primeiro heading não batia com data-secao nem com um dos 5
     * títulos canônicos (ex.: médico renomeou "Técnica"/"Achados" para
     * "Método"/"Análise", ou colou um laudo vindo de outro sistema) — o
     * autosave então sobrescrevia o banco com strings vazias a cada 30s.
     * Ver diagnostics/pendencias-conhecidas.md.
     */
    function extractSecoes() {
        const secoes = { exame: '', tecnica: '', achados: '', conclusao: '', recomendacao: '' };
        let atual = null;
        let marcadoresEncontrados = 0;
        let preambulo = '';
        const nodes = Array.from(quill?.root?.children || []);

        nodes.forEach((node) => {
            if (!node || node.nodeType !== 1) return;

            // Caminho ideal: o marcador foi preservado pelo Clipboard do Quill.
            // Fallback obrigatório: alguns builds removem atributos data-* ao
            // hidratar HTML; nesse caso o título visual do heading continua
            // confiável, desde que o texto bata com um dos 5 nomes canônicos.
            const marcado = node.dataset && node.dataset.secao;
            const porTitulo = HEADING_TAGS.includes(node.tagName) ? secaoPorTitulo(node.textContent) : null;
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
            } else {
                // Conteúdo antes do primeiro marcador reconhecido (ou, no pior
                // caso, o documento inteiro, se nenhum marcador bater). Nunca
                // descartar — vira o "preâmbulo" tratado abaixo.
                preambulo += node.outerHTML || '';
            }
        });

        const temTextoVisivel = String(quill?.root?.textContent || '').trim() !== '';

        if (marcadoresEncontrados === 0 && temTextoVisivel) {
            // Nenhum heading do documento bateu com um marcador — todo o
            // conteúdo caiu em "preambulo". Preserva tudo em "achados" em vez
            // de perder o laudo inteiro; o médico reorganiza manualmente.
            secoes.achados = preambulo;
            console.warn('[VOXEL Reports] editor sem marcadores de seção — conteúdo preservado em "achados" para não perder o laudo', {
                childTags: nodes.map((node) => node.tagName),
                editorChars: String(quill.root.textContent || '').length,
            });
        } else if (preambulo) {
            // Houve marcador(es) reconhecido(s), mas sobrou conteúdo antes do
            // primeiro — anexa à seção "exame" em vez de descartar.
            secoes.exame = preambulo + secoes.exame;
        }

        return secoes;
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }

    return { init, loadSecoes, extractSecoes, getQuill, setReadOnly };
})();
