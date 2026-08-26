/* Worklist: atualização parcial configurável por tenant. Nunca recarrega a página inteira. */
(function () {
  'use strict';
  var config = document.getElementById('wl-refresh-config');
  if (!config || config.dataset.enabled !== '1') return;

  var seconds = Math.max(15, Math.min(600, parseInt(config.dataset.seconds || '60', 10) || 60));
  var refreshing = false;
  var controller = null;

  function interactionInProgress() {
    if (document.querySelector('#formFiltros input:focus, #formFiltros select:focus, #formFiltros textarea:focus')) return true;
    if (document.querySelector('.modal.show, .offcanvas.show, .dropdown-menu.show, .wl-viewer-menu.show')) return true;
    if (document.querySelector('.row-check:checked, #checkAll:checked')) return true;
    if (window.__worklistActionInProgress === true) return true;
    return false;
  }

  function extractAndReplace(html) {
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    var nextBody = parsed.getElementById('wl-table-body');
    var currentBody = document.getElementById('wl-table-body');
    if (!nextBody || !currentBody) return false;

    currentBody.innerHTML = nextBody.innerHTML;
    var nextPagination = parsed.getElementById('wl-pagination');
    var currentPagination = document.getElementById('wl-pagination');
    if (nextPagination && currentPagination) currentPagination.outerHTML = nextPagination.outerHTML;
    if (nextPagination && !currentPagination) {
      var tableBody = document.getElementById('wl-worklist-body');
      if (tableBody) tableBody.insertAdjacentHTML('beforeend', nextPagination.outerHTML);
    }
    return true;
  }

  function refresh() {
    if (refreshing || interactionInProgress() || document.hidden) return;
    refreshing = true;
    if (controller) controller.abort();
    controller = new AbortController();
    var query = window.location.search || '';
    fetch('/api/estudos/worklist-fragmento' + query, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin', signal: controller.signal
    }).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (payload) {
      if (payload && payload.html && extractAndReplace(payload.html) && typeof window.atualizarBadgesTopbar === 'function') {
        window.atualizarBadgesTopbar();
      }
    }).catch(function () {
      // Falhas transitórias não removem dados já exibidos e serão tentadas no próximo ciclo.
    }).finally(function () {
      refreshing = false;
      controller = null;
    });
  }

  window.voxelWorklistRefresh = { refresh: refresh, paused: interactionInProgress };
  window.setInterval(refresh, seconds * 1000);
})();
