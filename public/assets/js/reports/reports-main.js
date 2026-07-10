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
            estudoId: parseInt(app.dataset.estudoId, 10),
            studyUid: app.dataset.studyUid,
            modalidade: app.dataset.modalidade || '',
            readonly: app.dataset.readonly === '1',
            status: app.dataset.status,
            csrf: app.dataset.csrf,
        };
    }

    function wireTopButtons(config) {
        const pdfUrl = `/reports/${encodeURIComponent(config.studyUid)}/pdf`;

        const btnViewPdf = document.getElementById('btn-view-pdf');
        if (btnViewPdf) btnViewPdf.addEventListener('click', () => window.open(pdfUrl, '_blank'));

        const btnPrint = document.getElementById('btn-print');
        if (btnPrint) btnPrint.addEventListener('click', () => window.open(pdfUrl, '_blank'));

        const btnViewer = document.getElementById('btn-open-viewer');
        if (btnViewer) btnViewer.addEventListener('click', () => window.open(`/estudos/${config.estudoId}/abrir`, '_blank'));

        const btnTimeline = document.getElementById('btn-timeline');
        if (btnTimeline) btnTimeline.addEventListener('click', () => alert('Timeline de exames anteriores — em breve.'));

        const btnComparativos = document.getElementById('btn-comparativos');
        if (btnComparativos) btnComparativos.addEventListener('click', () => alert('Comparativos entre exames — em breve.'));

        const btnAiGenerate = document.getElementById('btn-ai-generate');
        if (btnAiGenerate) {
            btnAiGenerate.addEventListener('click', () => {
                fetch('/reports/ai-generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                    body: JSON.stringify({ report_id: config.reportId }),
                })
                    .then((r) => r.json())
                    .then((data) => alert(data.message || 'Recurso ainda não disponível.'))
                    .catch(() => alert('Recurso ainda não disponível.'));
            });
        }
    }

    function init() {
        const config = readConfig();

        window.VoxelReports.editor.init(config);
        window.VoxelReports.autosave.init(config);
        window.VoxelReports.templates.init(config);
        window.VoxelReports.autotext.init(config);
        window.VoxelReports.signature.init(config);
        window.VoxelReports.history.init(config);

        wireTopButtons(config);
    }

    return { init };
})();
