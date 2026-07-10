/**
 * VOXEL PACS — Reports / Autotexto
 * Cacheia os gatilhos de report_autotext no load e, ao digitar, sugere o
 * texto correspondente com um popup ("Tab para inserir").
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.autotext = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let cache = [];
    let popupEl = null;
    let pendingMatch = null;
    let debounceTimer = null;

    function normalizar(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function criarPopup() {
        popupEl = document.createElement('div');
        popupEl.className = 'reports-autotext-popup';
        popupEl.style.display = 'none';
        document.body.appendChild(popupEl);
    }

    function esconderPopup() {
        if (popupEl) popupEl.style.display = 'none';
        pendingMatch = null;
    }

    function mostrarPopup(item, bounds) {
        pendingMatch = item;
        const preview = item.texto_sugerido.length > 140 ? item.texto_sugerido.substring(0, 140) + '…' : item.texto_sugerido;
        popupEl.innerHTML = `<div>${preview}</div><div class="hint">Tab para inserir</div>`;

        const container = document.getElementById('editor-container');
        const rect = container.getBoundingClientRect();
        popupEl.style.left = (rect.left + bounds.left + window.scrollX) + 'px';
        popupEl.style.top = (rect.top + bounds.top + bounds.height + window.scrollY + 6) + 'px';
        popupEl.style.display = 'block';
    }

    function palavraAtual(quill, range) {
        const textoAntes = quill.getText(0, range.index);
        const match = textoAntes.match(/(\S+)$/);
        return match ? match[1] : null;
    }

    function verificarPalavraAtual() {
        const quill = editor.getQuill();
        const range = quill.getSelection();
        if (!range) { esconderPopup(); return; }

        const palavra = palavraAtual(quill, range);
        if (!palavra) { esconderPopup(); return; }

        const item = cache.find((i) => i.gatilho === normalizar(palavra));
        if (!item) { esconderPopup(); return; }

        mostrarPopup(item, quill.getBounds(range.index));
    }

    function aceitarSugestao() {
        if (!pendingMatch) return false;
        const quill = editor.getQuill();
        const range = quill.getSelection();
        if (!range) return false;

        const palavra = palavraAtual(quill, range);
        if (!palavra) return false;

        const inicio = range.index - palavra.length;
        quill.deleteText(inicio, palavra.length, 'user');
        quill.clipboard.dangerouslyPasteHTML(inicio, pendingMatch.texto_sugerido + ' ', 'user');
        quill.setSelection(inicio + pendingMatch.texto_sugerido.replace(/<[^>]+>/g, '').length + 1, 'silent');
        esconderPopup();
        return true;
    }

    function init(cfg) {
        config = cfg;
        if (config.readonly) return;

        criarPopup();

        fetch(`/reports/autotext?modalidade=${encodeURIComponent(config.modalidade || '')}`)
            .then((r) => r.json())
            .then((data) => {
                cache = (data.items || []).map((i) => ({ ...i, gatilho: normalizar(i.gatilho) }));
            })
            .catch(() => { cache = []; });

        editor.getQuill().on('text-change', (delta, oldDelta, source) => {
            if (source !== 'user') return;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(verificarPalavraAtual, 250);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && pendingMatch) {
                e.preventDefault();
                aceitarSugestao();
            } else if (e.key === 'Escape') {
                esconderPopup();
            }
        });
    }

    return { init };
})();
