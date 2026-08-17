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

    function insertBasicTable(quill) {
        const range = quill.getSelection(true);
        const html = '<table><tbody><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table><p><br></p>';
        quill.clipboard.dangerouslyPasteHTML(range ? range.index : quill.getLength(), html, 'user');
    }

    function create(target, options = {}) {
        if (!window.Quill) throw new Error('Quill não está disponível.');

        const container = resolveElement(target);
        if (!container) throw new Error('Container do editor não encontrado.');

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

    return { create, insertBasicTable };
})();

window.createVoxelQuillEditor = function (target, options) {
    return window.VoxelQuill.factory.create(target, options || {});
};
