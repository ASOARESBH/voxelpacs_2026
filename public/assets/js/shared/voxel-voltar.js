/*
 * VOXEL PACS — Navegação de retorno segura.
 *
 * Usa o histórico somente quando a página anterior pertence ao próprio
 * sistema. Em acesso direto, nova aba ou origem externa, utiliza a rota-pai
 * declarada no botão por data-voxel-voltar.
 */
(function (global) {
    'use strict';

    function veioDoMesmoSistema() {
        if (!document.referrer) return false;
        try {
            return new URL(document.referrer, window.location.href).origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function voxelVoltar(fallbackUrl) {
        const destino = String(fallbackUrl || '').trim();
        if (destino === '') {
            console.warn('[VOXEL] Botão de retorno sem fallback explícito.');
            return;
        }

        if (veioDoMesmoSistema() && window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.assign(destino);
    }

    function textoIndicaRetorno(controle) {
        const texto = [controle.textContent, controle.getAttribute('title'), controle.getAttribute('aria-label')]
            .filter(Boolean)
            .join(' ')
            .replace(/\s+/g, ' ')
            .trim();
        return /\bvoltar\b/i.test(texto) || /^cancelar\b/i.test(texto);
    }

    function fallbackDoControle(controle) {
        const declarado = String(controle.dataset.voxelVoltar || '').trim();
        if (declarado !== '') return declarado;
        if (!(controle instanceof HTMLAnchorElement)) return '';

        const href = controle.getAttribute('href');
        if (!href || href.startsWith('#')) return '';
        try {
            const destino = new URL(href, window.location.href);
            if (destino.origin !== window.location.origin) return '';
            return destino.pathname + destino.search + destino.hash;
        } catch (_) {
            return '';
        }
    }

    function tratarClique(event) {
        const origem = event.target instanceof Element ? event.target : null;
        const controle = origem?.closest('a[data-voxel-voltar], a:not([data-voxel-voltar-skip])');
        if (!controle || controle.hasAttribute('disabled') || controle.getAttribute('aria-disabled') === 'true') return;
        if (!controle.dataset.voxelVoltar && !textoIndicaRetorno(controle)) return;

        const fallbackUrl = fallbackDoControle(controle);
        if (!fallbackUrl) return;
        event.preventDefault();
        voxelVoltar(fallbackUrl);
    }

    global.voxelVoltar = voxelVoltar;
    document.addEventListener('click', tratarClique);
})(window);
