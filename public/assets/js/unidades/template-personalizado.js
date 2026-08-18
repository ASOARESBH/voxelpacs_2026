(function () {
    'use strict';

    function init() {
        const form = document.getElementById('templateCustomForm');
        if (!form) return;

        const page = document.querySelector('.template-custom-page');
        const previewUrl = page ? page.dataset.previewUrl : '';
        const frame = document.getElementById('templatePreviewFrame');
        const status = document.getElementById('templatePreviewStatus');
        const editors = {};
        let previewTimer = null;

        function sectionNodes(section) {
            const wrapper = form.querySelector('.template-custom-section[data-section="' + section + '"]');
            return {
                wrapper: wrapper,
                mode: wrapper ? wrapper.querySelector('[name="' + section + '_mode"]') : null,
                toolbar: document.getElementById('templateToolbar-' + section),
                visual: wrapper ? wrapper.querySelector('[data-editor="' + section + '"]') : null,
                html: wrapper ? wrapper.querySelector('[data-html="' + section + '"]') : null,
                content: wrapper ? wrapper.querySelector('[data-content="' + section + '"]') : null
            };
        }

        ['header', 'body', 'footer'].forEach(function (section) {
            const nodes = sectionNodes(section);
            if (!nodes.visual || !nodes.content) return;
            if (!window.VoxelQuill || !window.VoxelQuill.factory) {
                if (status) status.textContent = 'Editor visual indisponível.';
                return;
            }
            try {
                const editor = window.VoxelQuill.factory.create(nodes.visual, {
                    toolbarSelector: '#templateToolbar-' + section,
                    placeholder: 'Monte o ' + section + ' do laudo...'
                });
                editor.clipboard.dangerouslyPasteHTML(0, nodes.content.value || '<p><br></p>', 'silent');
                editor.on('text-change', function () {
                    if (nodes.mode && nodes.mode.value === 'texto') {
                        nodes.content.value = editor.root.innerHTML;
                        nodes.html.value = editor.root.innerHTML;
                        schedulePreview();
                    }
                });
                editors[section] = editor;
            } catch (error) {
                if (status) status.textContent = 'Não foi possível iniciar o editor visual.';
                console.error('[TemplatePersonalizado]', error);
            }
        });

        function currentContent(section) {
            const nodes = sectionNodes(section);
            if (!nodes || !nodes.mode) return '';
            if (nodes.mode.value === 'texto' && editors[section]) {
                return editors[section].root.innerHTML;
            }
            return nodes.html ? nodes.html.value : '';
        }

        function syncAll() {
            ['header', 'body', 'footer'].forEach(function (section) {
                const nodes = sectionNodes(section);
                if (nodes.content) nodes.content.value = currentContent(section);
            });
        }

        function updatePreview() {
            if (!previewUrl || !frame) return;
            syncAll();
            const body = new FormData(form);
            if (status) status.textContent = 'Atualizando…';
            fetch(previewUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (response) {
                return response.ok ? response.json() : Promise.reject(new Error('Preview indisponível'));
            }).then(function (payload) {
                if (!payload || !payload.ok) throw new Error((payload && payload.message) || 'Preview indisponível');
                frame.srcdoc = payload.html;
                if (status) status.textContent = 'Dados fictícios · atualizado agora';
            }).catch(function () {
                if (status) status.textContent = 'Não foi possível atualizar o preview.';
            });
        }

        function schedulePreview() {
            window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(updatePreview, 400);
        }

        form.querySelectorAll('.mode-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const section = button.closest('.template-custom-section').dataset.section;
                const nodes = sectionNodes(section);
                const nextMode = button.dataset.mode;
                if (!nodes.mode || nodes.mode.value === nextMode) return;

                const value = currentContent(section);
                nodes.mode.value = nextMode;
                nodes.content.value = value;
                if (nodes.html) nodes.html.value = value;
                if (nextMode === 'texto' && editors[section]) {
                    editors[section].setText('', 'silent');
                    editors[section].clipboard.dangerouslyPasteHTML(0, value || '<p><br></p>', 'silent');
                }
                nodes.visual.classList.toggle('d-none', nextMode !== 'texto');
                nodes.toolbar.classList.toggle('d-none', nextMode !== 'texto');
                nodes.html.classList.toggle('d-none', nextMode !== 'html');
                nodes.wrapper.querySelectorAll('.mode-toggle').forEach(function (toggle) {
                    const active = toggle.dataset.mode === nextMode;
                    toggle.classList.toggle('btn-primary', active);
                    toggle.classList.toggle('btn-outline-primary', !active);
                    toggle.classList.toggle('active', active);
                });
                schedulePreview();
            });
        });

        form.querySelectorAll('.template-html-editor').forEach(function (textarea) {
            textarea.addEventListener('input', schedulePreview);
        });

        form.querySelectorAll('.insert-placeholder').forEach(function (button) {
            button.addEventListener('click', function () {
                const placeholder = button.dataset.placeholder || '';
                const section = button.closest('.template-custom-section').dataset.section;
                const nodes = sectionNodes(section);
                if (nodes.mode.value === 'texto' && editors[section]) {
                    const range = editors[section].getSelection(true);
                    editors[section].insertText(range ? range.index : editors[section].getLength(), placeholder, 'user');
                    editors[section].setSelection((range ? range.index : editors[section].getLength()) + placeholder.length, 0, 'silent');
                } else if (nodes.html) {
                    const start = nodes.html.selectionStart || 0;
                    const end = nodes.html.selectionEnd || start;
                    nodes.html.setRangeText(placeholder, start, end, 'end');
                    nodes.html.focus();
                }
                schedulePreview();
            });
        });

        form.addEventListener('submit', function () { syncAll(); });
        const publish = document.getElementById('publishTemplate');
        if (publish) {
            publish.addEventListener('click', function () {
                syncAll();
                if (!window.confirm('Publicar esta versão? Novos laudos usarão este layout; versões já assinadas permanecem inalteradas.')) return;
                form.action = form.dataset.publishAction;
                form.submit();
            });
        }

        schedulePreview();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
