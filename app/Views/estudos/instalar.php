<!-- VOXEL PACS — Página de Instalação do App (PWA) -->
<style>
.pwa-install-wrap {
    max-width: 640px;
    margin: 2rem auto;
    padding: 0 1rem;
}
.pwa-install-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 4px 24px rgba(15,23,42,.07);
}
.pwa-install-icon {
    width: 80px;
    height: 80px;
    border-radius: 18px;
    margin-bottom: 1.25rem;
    box-shadow: 0 4px 16px rgba(29,78,216,.25);
}
.pwa-install-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: .25rem;
}
.pwa-install-sub {
    font-size: .875rem;
    color: #64748b;
    margin-bottom: 1.75rem;
}
.pwa-install-btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    background: #1d4ed8;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: .75rem 1.5rem;
    font-size: .95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    text-decoration: none;
}
.pwa-install-btn:hover { background: #1e40af; color: #fff; }
.pwa-install-btn:disabled { background: #94a3b8; cursor: default; }
.pwa-install-steps {
    margin-top: 2rem;
    border-top: 1px solid #f1f5f9;
    padding-top: 1.5rem;
}
.pwa-install-steps h3 {
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #94a3b8;
    margin-bottom: 1rem;
}
.pwa-step {
    display: flex;
    gap: .75rem;
    align-items: flex-start;
    margin-bottom: .875rem;
}
.pwa-step-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: .75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}
.pwa-step-text {
    font-size: .875rem;
    color: #334155;
    line-height: 1.5;
}
.pwa-step-text strong { color: #0f172a; }
.pwa-browser-tabs {
    display: flex;
    gap: .5rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.pwa-browser-tab {
    padding: .35rem .85rem;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    border: 1.5px solid #e2e8f0;
    color: #64748b;
    background: #f8fafc;
    transition: all .15s;
}
.pwa-browser-tab.active {
    border-color: #1d4ed8;
    color: #1d4ed8;
    background: #eff6ff;
}
.pwa-instructions { display: none; }
.pwa-instructions.active { display: block; }
.pwa-badge-installed {
    display: none;
    align-items: center;
    gap: .5rem;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: .75rem 1rem;
    font-size: .875rem;
    color: #15803d;
    font-weight: 600;
    margin-top: 1rem;
}
</style>

<div class="pwa-install-wrap">
    <div class="pwa-install-card">
        <img src="/assets/img/pwa-icon-192.png" alt="VOXEL PACS" class="pwa-install-icon">
        <div class="pwa-install-title">VOXEL PACS — Worklist</div>
        <div class="pwa-install-sub">Instale o app no seu computador ou celular para acessar a worklist diretamente, sem precisar abrir o browser.</div>

        <!-- Botão de instalação automática (Chrome/Edge) -->
        <button class="pwa-install-btn" id="btnInstalarPwa" style="display:none;">
            <i class="fa fa-download"></i> Instalar App Agora
        </button>

        <div class="pwa-badge-installed" id="badgeInstalado">
            <i class="fa fa-circle-check"></i> App instalado com sucesso! Procure por "VOXEL PACS" na sua área de trabalho.
        </div>

        <!-- Instruções manuais por browser -->
        <div class="pwa-install-steps">
            <h3>Instalação manual por navegador</h3>

            <div class="pwa-browser-tabs">
                <div class="pwa-browser-tab active" onclick="showBrowser('chrome')">Chrome</div>
                <div class="pwa-browser-tab" onclick="showBrowser('edge')">Edge</div>
                <div class="pwa-browser-tab" onclick="showBrowser('safari')">Safari (Mac/iOS)</div>
                <div class="pwa-browser-tab" onclick="showBrowser('firefox')">Firefox</div>
            </div>

            <div class="pwa-instructions active" id="inst-chrome">
                <div class="pwa-step">
                    <div class="pwa-step-num">1</div>
                    <div class="pwa-step-text">Na barra de endereço, clique no ícone <strong>⊕ Instalar</strong> (ou no ícone de computador com seta para baixo) que aparece à direita da URL.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">2</div>
                    <div class="pwa-step-text">Clique em <strong>"Instalar"</strong> na janela de confirmação.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">3</div>
                    <div class="pwa-step-text">O app <strong>VOXEL PACS</strong> será adicionado à sua área de trabalho e ao menu Iniciar. Abra-o como qualquer outro programa.</div>
                </div>
            </div>

            <div class="pwa-instructions" id="inst-edge">
                <div class="pwa-step">
                    <div class="pwa-step-num">1</div>
                    <div class="pwa-step-text">Clique no menu <strong>⋯</strong> (três pontos) no canto superior direito.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">2</div>
                    <div class="pwa-step-text">Selecione <strong>"Aplicativos"</strong> → <strong>"Instalar este site como um aplicativo"</strong>.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">3</div>
                    <div class="pwa-step-text">Confirme clicando em <strong>"Instalar"</strong>. O app aparecerá na área de trabalho.</div>
                </div>
            </div>

            <div class="pwa-instructions" id="inst-safari">
                <div class="pwa-step">
                    <div class="pwa-step-num">1</div>
                    <div class="pwa-step-text"><strong>Mac:</strong> Clique em <strong>Arquivo</strong> → <strong>"Adicionar ao Dock"</strong>.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">2</div>
                    <div class="pwa-step-text"><strong>iPhone/iPad:</strong> Toque no ícone de <strong>compartilhar</strong> (quadrado com seta) → <strong>"Adicionar à Tela de Início"</strong>.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">3</div>
                    <div class="pwa-step-text">O ícone <strong>VOXEL PACS</strong> aparecerá na tela inicial ou no Dock.</div>
                </div>
            </div>

            <div class="pwa-instructions" id="inst-firefox">
                <div class="pwa-step">
                    <div class="pwa-step-num">1</div>
                    <div class="pwa-step-text">Firefox não suporta instalação de PWA no desktop. Use <strong>Chrome</strong> ou <strong>Edge</strong> para instalar o app.</div>
                </div>
                <div class="pwa-step">
                    <div class="pwa-step-num">2</div>
                    <div class="pwa-step-text">Alternativa: crie um atalho manual para <strong><?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'server.voxelpacs.com.br') . '/estudos') ?></strong> na sua área de trabalho.</div>
                </div>
            </div>
        </div>

        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #f1f5f9;">
            <a href="/estudos" data-voxel-voltar="/estudos" class="pwa-install-btn" style="background:#f1f5f9;color:#334155;">
                <i class="fa fa-arrow-left"></i> Voltar para a Worklist
            </a>
        </div>
    </div>
</div>

<script>
// Botão de instalação automática (Chrome/Edge via beforeinstallprompt)
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('btnInstalarPwa').style.display = 'inline-flex';
});

document.getElementById('btnInstalarPwa').addEventListener('click', async function() {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') {
        document.getElementById('badgeInstalado').style.display = 'flex';
        document.getElementById('btnInstalarPwa').style.display = 'none';
    }
    deferredPrompt = null;
});

window.addEventListener('appinstalled', function() {
    document.getElementById('badgeInstalado').style.display = 'flex';
    document.getElementById('btnInstalarPwa').style.display = 'none';
});

// Tabs de browser
function showBrowser(browser) {
    document.querySelectorAll('.pwa-browser-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pwa-instructions').forEach(i => i.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('inst-' + browser).classList.add('active');
}

// Detectar browser e pré-selecionar aba
(function() {
    const ua = navigator.userAgent.toLowerCase();
    let browser = 'chrome';
    if (ua.includes('edg/')) browser = 'edge';
    else if (ua.includes('safari') && !ua.includes('chrome')) browser = 'safari';
    else if (ua.includes('firefox')) browser = 'firefox';
    document.querySelectorAll('.pwa-browser-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pwa-instructions').forEach(i => i.classList.remove('active'));
    const tabs = document.querySelectorAll('.pwa-browser-tab');
    const browsers = ['chrome','edge','safari','firefox'];
    const idx = browsers.indexOf(browser);
    if (idx >= 0) tabs[idx].classList.add('active');
    document.getElementById('inst-' + browser).classList.add('active');
})();
</script>
