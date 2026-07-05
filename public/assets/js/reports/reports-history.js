/**
 * VOXEL PACS — Reports / Histórico
 * Timeline de versões (report_versions) com restauração sem reload.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.history = (function () {
    const editor = window.VoxelReports.editor;
    let config = null;
    let modal = null;

    const ACAO_LABEL = { salvo: 'Salvo', rascunho: 'Rascunho', restaurado: 'Restaurado', assinado: 'Assinado' };

    function render(versions) {
        const el = document.getElementById('history-timeline');
        if (!versions.length) {
            el.innerHTML = '<p class="text-pacs-muted">Nenhuma versão salva ainda.</p>';
            return;
        }

        el.innerHTML = versions.map((v) => `
            <div class="history-item" data-version-id="${v.id}">
                <div><strong>v${v.versao_numero}</strong> — ${ACAO_LABEL[v.acao] || v.acao}</div>
                <div class="text-pacs-muted" style="font-size:.7rem;">${v.user_nome || '—'} em ${v.criado_em}</div>
                ${!config.readonly ? '<button type="button" class="btn-pacs-outline btn-restaurar" style="margin-top:.4rem;font-size:.7rem;"><i class="fa fa-clock-rotate-left"></i> Restaurar esta versão</button>' : ''}
            </div>
        `).join('');

        if (config.readonly) return;

        el.querySelectorAll('.btn-restaurar').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const versionId = btn.closest('.history-item').dataset.versionId;
                restaurar(versionId);
            });
        });
    }

    function restaurar(versionId) {
        if (!confirm('Restaurar esta versão? O conteúdo atual do editor será substituído.')) return;

        fetch('/reports/history/restore', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
            body: JSON.stringify({ report_id: config.reportId, version_id: versionId }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.ok) { alert('Não foi possível restaurar esta versão.'); return; }
                editor.loadSecoes(data.secoes || {});
                modal.hide();
            })
            .catch(() => alert('Falha de comunicação ao restaurar a versão.'));
    }

    function open() {
        const el = document.getElementById('history-timeline');
        el.innerHTML = '<p class="text-pacs-muted">Carregando histórico...</p>';
        modal.show();

        fetch(`/reports/history?report_id=${config.reportId}`)
            .then((r) => r.json())
            .then((data) => render(data.versions || []))
            .catch(() => { el.innerHTML = '<p class="text-pacs-muted">Falha ao carregar histórico.</p>'; });
    }

    function init(cfg) {
        config = cfg;
        const modalEl = document.getElementById('modalHistorico');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);

        const btn = document.getElementById('btn-history');
        if (btn) btn.addEventListener('click', open);
    }

    return { init };
})();
