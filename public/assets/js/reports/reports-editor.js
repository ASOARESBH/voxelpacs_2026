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
    let presentationOptions = { removeEmptyParagraphs: false };

    const SECOES = ['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'];
    const EMPTY_HTML = '<p><br></p>';
    const TITULOS = {
        exame: 'Exame',
        tecnica: 'Técnica',
        achados: 'Achados',
        conclusao: 'Impressão',
        recomendacao: 'Recomendação',
    };

    function init(config) {
        presentationOptions = {
            removeEmptyParagraphs: config?.removeEmptyParagraphs === true,
        };
        const container = typeof document !== 'undefined'
            ? document.getElementById('editor-container')
            : null;
        // O Quill pode reprocessar o DOM do container durante o construtor.
        // Capturamos a fonte server-side antes disso e a reinserimos pela API
        // oficial para que a hidratação não dependa de detalhes internos do Quill.
        const initialHtml = container?.innerHTML || '';
        quill = window.createVoxelQuillEditor('#editor-container', {
            readOnly: !!config.readonly,
            toolbarSelector: '#editor-toolbar',
        });

        setContent(initialHtml);
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
        setContent(html);
    }

    /**
     * Fonte única de verdade: captura o HTML atual do Quill sem reinterpretar
     * headings, seções ou conteúdo importado pela máscara. Isso preserva todo
     * texto e toda formatação adicionados pelo médico até o PDF.
     */
    function extractSecoes() {
        const html = normalizeClinicalHtml(quill?.root?.innerHTML || '');
        return { corpo: contentDiagnostics(html).hasClinicalContent ? html : EMPTY_HTML };
    }

    function loadConteudoLivre(html) {
        setContent(html || '');
    }

    function setContent(html) {
        if (!quill) return;
        const normalized = normalizeClinicalHtml(html || '') || EMPTY_HTML;
        if (typeof quill.setText === 'function') quill.setText('', 'silent');
        if (quill.clipboard?.dangerouslyPasteHTML) {
            quill.clipboard.dangerouslyPasteHTML(0, normalized, 'silent');
        } else if (quill.root) {
            quill.root.innerHTML = normalized;
        }
    }

    function normalizeClinicalHtml(html) {
        const normalizer = window.VoxelQuill?.factory?.normalizeClinicalHtml;
        return typeof normalizer === 'function'
            ? normalizer(html, presentationOptions)
            : String(html || '').trim();
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

    function textFromHtml(html) {
        if (typeof document !== 'undefined' && document.createElement) {
            const holder = document.createElement('div');
            holder.innerHTML = html;
            return holder.textContent || '';
        }
        return String(html || '')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]*>/g, ' ')
            .replace(/&nbsp;|&#160;|&#xA0;/gi, ' ');
    }

    /**
     * Retorna somente métricas seguras para telemetria local. Nunca inclui o
     * HTML ou o texto clínico, apenas tamanhos e o estado efetivo do editor.
     */
    function contentDiagnostics(html = null) {
        const normalizedHtml = html === null
            ? normalizeClinicalHtml(quill?.root?.innerHTML || '')
            : String(html || '');
        const quillText = typeof quill?.getText === 'function'
            ? String(quill.getText() || '')
            : textFromHtml(normalizedHtml);
        const textLength = quillText
            .replace(/[\u00a0\u200b\ufeff]/g, ' ')
            .trim()
            .length;

        return {
            htmlLength: normalizedHtml.length,
            textLength,
            hasClinicalContent: textLength > 0,
        };
    }

    function getQuill() { return quill; }
    function setReadOnly(readonly) { if (quill) quill.enable(!readonly); }
    function isDocumentoLivre() { return true; }

    return {
        init,
        loadSecoes,
        loadConteudoLivre,
        extractSecoes,
        getContentDiagnostics: contentDiagnostics,
        getQuill,
        setReadOnly,
        isDocumentoLivre,
    };
})();
