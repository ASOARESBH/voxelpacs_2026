(function (window, document) {
    'use strict';

    const VoxelReports = window.VoxelReports = window.VoxelReports || {};

    function init() {
        const app = document.getElementById('reports-app');
        const button = document.getElementById('btn-open-peer-review');
        const motivo = document.getElementById('peer-review-motivo');
        const counter = document.getElementById('peer-review-motivo-count');
        const errorBox = document.getElementById('peer-review-error');
        if (!app || !button || !motivo) return;

        const i18n = VoxelReports.peerReviewI18n || {};
        const reportId = Number(app.dataset.reportId || 0);
        const csrf = app.dataset.csrf || '';
        const minChars = 20;

        const updateCounter = function () {
            if (counter) counter.textContent = String(motivo.value.length);
            button.disabled = motivo.value.trim().length < minChars;
        };

        const showError = function (message) {
            if (!errorBox) return;
            errorBox.textContent = message || i18n.error || 'Não foi possível abrir o Peer Review.';
            errorBox.classList.remove('d-none');
        };

        motivo.addEventListener('input', updateCounter);
        updateCounter();

        button.addEventListener('click', async function () {
            const texto = motivo.value.trim();
            if (texto.length < minChars) {
                showError(i18n.motivoCurto || 'Informe pelo menos 20 caracteres para o motivo.');
                motivo.focus();
                return;
            }

            const confirmacao = window.confirm(i18n.confirmar || 'Deseja liberar o laudo para revisão?');
            if (!confirmacao) return;

            button.disabled = true;
            if (errorBox) errorBox.classList.add('d-none');

            try {
                const response = await fetch('/api/reports/peer-review/open', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({ report_id: reportId, motivo: texto, csrf: csrf })
                });
                const data = await response.json().catch(function () { return {}; });
                if (!response.ok || !data.ok) {
                    throw new Error(data.msg || i18n.error || 'Não foi possível abrir o Peer Review.');
                }

                window.location.reload();
            } catch (error) {
                button.disabled = false;
                showError(error.message);
            }
        });
    }

    VoxelReports.peerReview = { init: init };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})(window, document);
