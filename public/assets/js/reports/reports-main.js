/**
 * VOXEL PACS — Reports / Bootstrap
 * Lê a config do #reports-app, inicializa os submódulos na ordem correta
 * (editor primeiro, os demais dependem dele) e liga os botões do topo.
 */
window.VoxelReports = window.VoxelReports || {};
window.VoxelReports.main = (function () {
    function readConfig() {
        const app = document.getElementById('reports-app');
        return {
                        reportId: parseInt(app.dataset.reportId, 10),
            reportToken: app.dataset.reportToken || '',
            templateId: parseInt(app.dataset.templateId || '0', 10) || 0,
            estudoId: parseInt(app.dataset.estudoId, 10),
            studyUid: app.dataset.studyUid,
            modalidade: app.dataset.modalidade || '',
            readonly: app.dataset.readonly === '1',
            status: app.dataset.status,
            chatPending: app.dataset.chatPending === '1',
            csrf: app.dataset.csrf,
        };
    }
    function openSecurePdf(pdfUrl) {
        if (!pdfUrl) {
            alert('Não foi possível preparar a URL segura do PDF. Recarregue o laudo e tente novamente.');
            return;
        }
        window.open(pdfUrl, '_blank');
    }

    function wireTopButtons(config) {
        // PDF e impressão usam exclusivamente o token opaco do report. Nunca
        // reconstituir a URL a partir de reportId, que é identificador interno.
        const pdfUrl = config.reportToken
            ? `/reports/r/${encodeURIComponent(config.reportToken)}/pdf`
            : null;
        const btnViewPdf = document.getElementById('btn-view-pdf');
        if (btnViewPdf) btnViewPdf.addEventListener('click', () => openSecurePdf(pdfUrl));

        const btnPrint = document.getElementById('btn-print');
        if (btnPrint) btnPrint.addEventListener('click', () => openSecurePdf(pdfUrl));

        const btnViewer = document.getElementById('btn-open-viewer');
        if (btnViewer) btnViewer.addEventListener('click', () => window.open(`/estudos/${config.estudoId}/abrir`, '_blank'));
        const btnTimeline = document.getElementById('btn-timeline');
        if (btnTimeline) btnTimeline.addEventListener('click', () => alert('Timeline de exames anteriores — em breve.'));
        const btnComparativos = document.getElementById('btn-comparativos');
        if (btnComparativos) btnComparativos.addEventListener('click', () => alert('Comparativos entre exames — em breve.'));
        const btnLiberar = document.getElementById('btn-liberar');
        if (btnLiberar) {
            btnLiberar.addEventListener('click', () => {
                if (window.VoxelReports.chat?.hasPending()) {
                    alert('Existe uma pendência aberta no CHAT. Conclua a conversa antes de liberar o laudo.');
                    return;
                }
                if (!confirm('Liberar este laudo? Depois da liberação ele ficará pronto para impressão.')) return;
                btnLiberar.disabled = true;
                fetch('/api/reports/liberar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                    body: JSON.stringify({ report_id: config.reportId, csrf: config.csrf }),
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (!data.ok) {
                            btnLiberar.disabled = false;
                            alert(data.msg || 'Não foi possível liberar o laudo.');
                            return;
                        }
                        window.location.href = '/estudos';
                    })
                    .catch(() => {
                        btnLiberar.disabled = false;
                        alert('Falha de comunicação ao liberar o laudo.');
                    });
            });
        }

        const btnAiGenerate = document.getElementById('btn-ai-generate');
        if (btnAiGenerate) {
            btnAiGenerate.addEventListener('click', () => {
                fetch('/reports/ai-generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                    body: JSON.stringify({ report_id: config.reportId }),
                })
                    .then((r) => r.json())
                    .then((data) => alert(data.message || data.msg || 'Recurso ainda não disponível.'))
                    .catch(() => alert('Recurso ainda não disponível.'));
            });
        }
    }
    function init() {
        const config = readConfig();
        window.VoxelReports.editor.init(config);
        if (window.VoxelReports.dictation) {
            window.VoxelReports.dictation.init(config);
        }
        window.VoxelReports.autosave.init(config);
        window.VoxelReports.templates.init(config);
        window.VoxelReports.autotext.init(config);
        window.VoxelReports.signature.init(config);
        window.VoxelReports.history.init(config);
        // O CHAT carrega o estado por HTTP; deve iniciar depois da assinatura
        // para que reports:chat-status não seja perdido.
        window.VoxelReports.chat.init(config);
        if (window.VoxelReports.measurements) {
            window.VoxelReports.measurements.init(config);
        }

        wireTopButtons(config);
    }
    return { init };
})();
