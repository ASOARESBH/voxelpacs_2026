/* ============================================================
   VOXEL B.I — JavaScript Principal
   ============================================================ */

// Configuração global do Chart.js
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;

// Formatação de números BR
const fmtNum = (n) => new Intl.NumberFormat('pt-BR').format(n);
const fmtBRL = (n) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n);

// Auto-dismiss de alertas após 8 segundos
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert-dismissible').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            bsAlert.close();
        }, 8000);
    });

    // Tooltips Bootstrap
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });

    // Confirmação de ações destrutivas
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!confirm(btn.dataset.confirm || 'Confirmar ação?')) {
                e.preventDefault();
            }
        });
    });
});
