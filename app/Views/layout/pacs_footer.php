        </div><!-- /pacs-page -->
    </div><!-- /pacs-content -->
</div><!-- /pacs-wrapper -->

<?php $v = defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($includeQuill)): ?>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script src="/assets/js/shared/voxel-quill-factory.js?v=<?= $v ?>"></script>
<?php endif; ?>
<?php if (!empty($includeTemplateCustomEditor)): ?>
<script src="/assets/js/unidades/template-personalizado.js?v=<?= $v ?>"></script>
<?php endif; ?>
<script src="/assets/js/shared/voxel-voltar.js?v=<?= $v ?>"></script>
<script>
// ── SIDEBAR TOGGLE ──────────────────────────────────────────
(function() {
    const sidebar  = document.getElementById('pacs-sidebar');
    const content  = document.getElementById('pacs-content');
    const toggle   = document.getElementById('sidebar-toggle');
    const logoImg  = document.getElementById('sidebar-logo-img');

    // Restaurar estado salvo
    const collapsed = localStorage.getItem('pacs_sidebar_collapsed') === '1';
    if (collapsed) {
        sidebar.classList.add('collapsed');
        content.classList.add('sidebar-collapsed');
    }

    if (toggle) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            content.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('pacs_sidebar_collapsed', isCollapsed ? '1' : '0');
        });
    }
})();

// ── SUBMENU TOGGLE ──────────────────────────────────────────
function toggleSubmenu(link, id) {
    const sub = document.getElementById(id);
    if (!sub) return;
    const isOpen = sub.classList.contains('show');
    // Fecha todos
    document.querySelectorAll('.sidebar-submenu.show').forEach(el => {
        el.classList.remove('show');
    });
    document.querySelectorAll('.nav-link.has-submenu.open').forEach(el => {
        el.classList.remove('open');
    });
    // Abre o clicado (se estava fechado)
    if (!isOpen) {
        sub.classList.add('show');
        link.classList.add('open');
    }
}

// ── MOBILE SIDEBAR ──────────────────────────────────────────
function toggleMobileSidebar() {
    const sidebar = document.getElementById('pacs-sidebar');
    sidebar.classList.toggle('mobile-open');
}

// ── TOOLTIPS (ex: descrição de modalidade DICOM — dicom-modality) ───────
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

// ── CONTADORES TOPBAR ──────────────────────────────────────
(function loadCounters() {
    fetch((function () { const url = new URL(window.location.href); const query = new URLSearchParams(); ['periodo', 'dt_inicio', 'dt_fim'].forEach((key) => { if (url.searchParams.has(key)) query.set(key, url.searchParams.get(key)); }); return '/api/estudos/contadores' + (query.toString() ? '?' + query.toString() : ''); })(), { signal: AbortSignal.timeout(5000) })
        .then(r => r.json())
        .then(data => {
            if (data) {
                const set = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val ?? '0';
                };
                set('cnt-novo',     data.novo);
                set('cnt-aberto',   data.aberto);
                set('cnt-rascunho', data.rascunho);
                set('cnt-assinado', data.assinado);
            }
        })
        .catch(() => {
            // Silencioso — contadores ficam com "—"
        });
})();
</script>
</body>
</html>
