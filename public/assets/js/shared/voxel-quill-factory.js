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

    function normalizeClinicalHtml(rawHtml) {
        const html = String(rawHtml || '').trim();
        if (!html) return '';

        if (typeof document === 'undefined' || !document.createElement) {
            return normalizeClinicalHtmlFallback(html);
        }

        const template = document.createElement('template');
        template.innerHTML = html;
        normalizeDomChildren(template.content);
        return template.innerHTML.trim();
    }

    function normalizeDomChildren(parent) {
        const children = Array.from(parent.childNodes || []);
        let previousBreak = false;

        children.forEach((child) => {
            if (child.nodeType === 3) {
                child.nodeValue = normalizeText(child.nodeValue || '');
                if (child.nodeValue.trim() !== '') previousBreak = false;
                return;
            }
            if (child.nodeType !== 1) return;

            child.removeAttribute('style');
            const allowedClasses = ['ql-align-center', 'ql-align-right', 'ql-align-justify'];
            const classes = Array.from(child.classList || []).filter((className) => allowedClasses.includes(className));
            if (classes.length) child.className = classes.join(' ');
            else child.removeAttribute('class');
            normalizeDomChildren(child);
            const tag = String(child.tagName || '').toLowerCase();
            if (tag === 'br') {
                if (previousBreak) child.remove();
                else previousBreak = true;
                return;
            }

            trimDomBoundaries(child);
            if (tag === 'p' && isEmptyParagraph(child)) {
                const previous = child.previousElementSibling;
                if (previous && String(previous.tagName || '').toLowerCase() === 'p' && isEmptyParagraph(previous)) {
                    child.remove();
                    return;
                }
            }
            previousBreak = false;
        });
    }

    function normalizeText(value) {
        return String(value || '')
            .replace(/[\u00a0\u200b\ufeff]/g, ' ')
            .replace(/\s+/g, ' ');
    }

    function trimDomBoundaries(element) {
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node = walker.nextNode();
        while (node) {
            nodes.push(node);
            node = walker.nextNode();
        }
        if (!nodes.length) return;
        nodes[0].nodeValue = nodes[0].nodeValue.replace(/^\s+/, '');
        nodes[nodes.length - 1].nodeValue = nodes[nodes.length - 1].nodeValue.replace(/\s+$/, '');
    }

    function isEmptyParagraph(element) {
        if (String(element.textContent || '').trim() !== '') return false;
        return Array.from(element.querySelectorAll('*')).every((node) => String(node.tagName || '').toLowerCase() === 'br');
    }

    function normalizeClinicalHtmlFallback(html) {
        let normalized = html
            .replace(/[\u00a0\u200b\ufeff]/g, ' ')
            .replace(/\s+style\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '')
            .replace(/(?:<br\s*\/?>\s*){2,}/gi, '<br>');
        normalized = normalized.replace(/(?:<p\b[^>]*>\s*(?:(?:<br\s*\/?>)\s*)?<\/p>\s*){2,}/gi, '<p><br></p>');
        normalized = normalized
            .replace(/(<(?:p|h[1-6]|li|th|td)\b[^>]*>)\s+/gi, '$1')
            .replace(/\s+(<\/(?:p|h[1-6]|li|th|td)>)/gi, '$1');
        return normalized.trim();
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

    return { create, insertBasicTable, normalizeHttpsUrl, normalizeClinicalHtml };
})();

window.createVoxelQuillEditor = function (target, options) {
    return window.VoxelQuill.factory.create(target, options || {});
};
