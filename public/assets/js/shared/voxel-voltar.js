/*
 * VOXEL PACS — Navegação de retorno segura.
 *
 * Usa o histórico somente quando a página anterior pertence ao próprio
 * sistema. Em acesso direto, nova aba ou origem externa, utiliza a rota-pai
 * declarada no botão por data-voxel-voltar.
 *
 * Para Laudário e prévia PDF, data-voxel-return-worklist localiza a aba
 * originária da Worklist aberta pelo sistema, devolve o foco a ela e fecha
 * as abas filhas. Isso impede que retornos sucessivos criem novas Worklists.
 */
(function (global) {
    'use strict';

    function veioDoMesmoSistema() {
        if (!document.referrer) return false;
        try {
            return new URL(document.referrer, global.location.href).origin === global.location.origin;
        } catch (_) {
            return false;
        }
    }

    function destinoValido(fallbackUrl) {
        const destino = String(fallbackUrl || '').trim();
        if (destino === '') {
            console.warn('[VOXEL] Botão de retorno sem fallback explícito.');
            return '';
        }
        return destino;
    }

    function voxelVoltar(fallbackUrl) {
        const destino = destinoValido(fallbackUrl);
        if (destino === '') return;

        if (veioDoMesmoSistema() && global.history.length > 1) {
            global.history.back();
            return;
        }

        global.location.assign(destino);
    }

    function abaWorklistDeOrigem() {
        let candidata = global.opener;
        const visitadas = new Set();

        while (candidata && !candidata.closed && !visitadas.has(candidata)) {
            try {
                visitadas.add(candidata);
                const url = new URL(candidata.location.href, global.location.href);
                if (url.origin === global.location.origin && (url.pathname === '/estudos' || url.pathname === '/gestao-exames')) {
                    return candidata;
                }
                candidata = candidata.opener;
            } catch (_) {
                return null;
            }
        }

        return null;
    }

    function voxelRetornarWorklist(fallbackUrl) {
        const destino = destinoValido(fallbackUrl);
        if (destino === '') return;

        const worklist = abaWorklistDeOrigem();
        if (!worklist) {
            global.location.assign(destino);
            return;
        }

        try {
            const destinoUrl = new URL(destino, global.location.href);
            const worklistUrl = new URL(worklist.location.href, global.location.href);
            if (worklistUrl.pathname !== destinoUrl.pathname) {
                worklist.location.assign(destino);
            }
            worklist.focus();
        } catch (_) {
            global.location.assign(destino);
            return;
        }

        // A prévia PDF é filha do Laudário; o retorno direto à Worklist fecha
        // também a janela intermediária quando ela tiver sido aberta pelo sistema.
        const abaPai = global.opener;
        if (abaPai && abaPai !== worklist && !abaPai.closed) {
            try {
                abaPai.close();
            } catch (_) {
                // O navegador pode impedir fechamento de aba não criada por script.
            }
        }

        global.close();
        global.setTimeout(function () {
            if (!global.closed) global.location.assign(destino);
        }, 180);
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
            const destino = new URL(href, global.location.href);
            if (destino.origin !== global.location.origin) return '';
            return destino.pathname + destino.search + destino.hash;
        } catch (_) {
            return '';
        }
    }

    function tratarClique(event) {
        const origem = event.target instanceof Element ? event.target : null;
        const controle = origem?.closest('a[data-voxel-voltar], a[data-voxel-return-worklist], a:not([data-voxel-voltar-skip])');
        if (!controle || controle.hasAttribute('disabled') || controle.getAttribute('aria-disabled') === 'true') return;
        if (!controle.dataset.voxelVoltar && !controle.hasAttribute('data-voxel-return-worklist') && !textoIndicaRetorno(controle)) return;

        const fallbackUrl = fallbackDoControle(controle);
        if (!fallbackUrl) return;
        event.preventDefault();

        if (controle.hasAttribute('data-voxel-return-worklist')) {
            voxelRetornarWorklist(fallbackUrl);
            return;
        }

        voxelVoltar(fallbackUrl);
    }

    global.voxelVoltar = voxelVoltar;
    global.voxelRetornarWorklist = voxelRetornarWorklist;
    document.addEventListener('click', tratarClique);
})(window);
