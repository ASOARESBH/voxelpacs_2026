/*
 * VOXEL PACS — Fábrica compartilhada de editores Quill
 * Centraliza a toolbar clínica, a tabela 2x2 segura e o histórico de edição.
 * Não depende de módulos externos: a tabela é HTML sem atributos proprietários,
 * compatível com a allowlist e o Dompdf do projeto.
 */
window.VoxelQuill = window.VoxelQuill || {};

window.VoxelQuill.factory = (function () {
    function resolveElement(target) {
        if (typeof target === 'string') return document.querySelector(target);
        return target instanceof Element ? target : null;
    }

    const SPACING_VALUES = ['compact', 'normal', 'medium', 'wide'];
    let spacingRegistered = false;

    function registerClinicalSpacingFormat() {
        if (spacingRegistered) return;
        const Parchment = Quill.import('parchment');
        const ClinicalSpacing = new Parchment.Attributor.Class('spacing', 'ql-spacing', {
            scope: Parchment.Scope.BLOCK,
            whitelist: SPACING_VALUES,
        });
        Quill.register(ClinicalSpacing, true);
        spacingRegistered = true;
    }

    function insertBasicTable(quill) {
        const range = quill.getSelection(true);
        const html = '<table><tbody><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table><p><br></p>';
        quill.clipboard.dangerouslyPasteHTML(range ? range.index : quill.getLength(), html, 'user');
    }

    function insertSpacingBlock(quill) {
        const range = quill.getSelection(true);
        const index = range ? range.index + range.length : Math.max(0, quill.getLength() - 1);
        // Duas quebras criam uma linha vazia entre o parágrafo atual e o próximo.
        // A classe é aplicada somente à linha em branco; o texto seguinte permanece normal.
        quill.insertText(index, '\n\n', 'user');
        quill.formatLine(index + 1, 1, 'spacing', 'wide', 'user');
        quill.setSelection(index + 2, 0, 'silent');
    }

    function normalizeHttpsUrl(rawUrl) {
        const value = String(rawUrl || '').trim();
        if (!value) return '';

        try {
            const url = new URL(value);
            return url.protocol === 'https:' ? url.toString() : '';
        } catch (_) {
            return '';
        }
    }

    function insertSecureLink(quill, enabled) {
        const range = quill.getSelection(true);
        if (!range) return;

        if (!enabled) {
            quill.format('link', false, 'user');
            return;
        }

        const typedUrl = window.prompt('Informe um endereço HTTPS para o link:');
        const href = normalizeHttpsUrl(typedUrl);
        if (!href) {
            window.alert('Informe um endereço HTTPS válido.');
            return;
        }

        quill.format('link', href, 'user');
    }

    function create(target, options = {}) {
        if (!window.Quill) throw new Error('Quill não está disponível.');

        const container = resolveElement(target);
        if (!container) throw new Error('Container do editor não encontrado.');

        registerClinicalSpacingFormat();
        const readonly = !!options.readOnly;
        const toolbar = options.toolbar || options.toolbarSelector || false;
        let quill = null;

        quill = new Quill(container, {
            theme: options.theme || 'snow',
            readOnly: readonly,
            placeholder: options.placeholder || '',
            modules: {
                toolbar: readonly || !toolbar ? false : {
                    container: toolbar,
                    handlers: {
                        table: () => insertBasicTable(quill),
                        spacer: () => insertSpacingBlock(quill),
                        link: (enabled) => insertSecureLink(quill, enabled),
                        undo: () => quill.history.undo(),
                        redo: () => quill.history.redo(),
                    },
                },
                history: { delay: 1000, maxStack: 200, userOnly: true },
                table: false,
            },
        });

        return quill;
    }

    return { create, insertBasicTable, insertSpacingBlock, normalizeHttpsUrl };
})();

window.createVoxelQuillEditor = function (target, options) {
    return window.VoxelQuill.factory.create(target, options || {});
};
