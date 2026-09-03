<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Criar Senha') ?> — VOXEL PACS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1e3a5f 0%, #0f2540 100%); min-height: 100vh; }
        .card { border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .password-strength { height: 4px; border-radius: 2px; transition: all 0.3s; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="container" style="max-width: 440px;">
        <div class="text-center mb-4">
            <img src="/public/assets/img/logo.png" alt="VOXEL PACS" style="max-height: 50px;" onerror="this.style.display='none'">
            <h4 class="text-white mt-3 fw-bold">VOXEL PACS</h4>
        </div>
        
        <div class="card">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1">Criar Senha de Acesso</h5>
                <p class="text-muted small mb-4">
                    <?= htmlspecialchars(t('auth.reset.criar_senha_subtitulo')) ?>
                </p>
                
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger py-2 small">
                        <i class="fa fa-exclamation-circle me-1"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <form method="POST" action="/acesso/criar-senha/<?= htmlspecialchars($token) ?>" id="formSenha">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nova Senha</label>
                        <div class="input-group">
                            <input type="password" name="senha" id="senha" class="form-control" 
                                   placeholder="Mínimo 8 caracteres" required minlength="8"
                                   oninput="verificarForca(this.value)">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha('senha')">
                                <i class="fa fa-eye" id="icon-senha"></i>
                            </button>
                        </div>
                        <div class="password-strength mt-2 bg-secondary" id="strength-bar" style="width: 0%;"></div>
                        <small class="text-muted" id="strength-text"></small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirmar Senha</label>
                        <div class="input-group">
                            <input type="password" name="confirma" id="confirma" class="form-control" 
                                   placeholder="Repita a senha" required minlength="8">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha('confirma')">
                                <i class="fa fa-eye" id="icon-confirma"></i>
                            </button>
                        </div>
                        <small class="text-danger d-none" id="senha-mismatch">As senhas não conferem.</small>
                    </div>
                    
                    <div class="mb-3 p-3 bg-light rounded small">
                        <strong>Requisitos:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            <li id="req-len" class="text-muted">Mínimo de 8 caracteres</li>
                            <li id="req-upper" class="text-muted">Pelo menos uma letra maiúscula</li>
                            <li id="req-num" class="text-muted">Pelo menos um número</li>
                        </ul>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" id="btnSalvar">
                        <i class="fa fa-lock me-2"></i> Criar Senha e Acessar
                    </button>
                </form>
                
                <p class="text-center text-muted small mt-3">
                    <i class="fa fa-shield-alt me-1"></i> Este link é de uso único e expira em 24h.
                </p>
            </div>
        </div>
    </div>
    
    <script>
    function toggleSenha(id) {
        const input = document.getElementById(id);
        const icon  = document.getElementById('icon-' + id);
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fa fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fa fa-eye';
        }
    }
    
    function verificarForca(senha) {
        const bar  = document.getElementById('strength-bar');
        const text = document.getElementById('strength-text');
        const reqLen   = document.getElementById('req-len');
        const reqUpper = document.getElementById('req-upper');
        const reqNum   = document.getElementById('req-num');
        
        let score = 0;
        
        if (senha.length >= 8)  { score++; reqLen.className = 'text-success'; }   else { reqLen.className = 'text-muted'; }
        if (/[A-Z]/.test(senha)) { score++; reqUpper.className = 'text-success'; } else { reqUpper.className = 'text-muted'; }
        if (/[0-9]/.test(senha)) { score++; reqNum.className = 'text-success'; }   else { reqNum.className = 'text-muted'; }
        if (/[^A-Za-z0-9]/.test(senha)) score++;
        
        const pct    = (score / 4) * 100;
        const colors = ['', 'bg-danger', 'bg-warning', 'bg-info', 'bg-success'];
        const labels = ['', 'Fraca', 'Razoável', 'Boa', 'Forte'];
        
        bar.style.width = pct + '%';
        bar.className   = 'password-strength mt-2 ' + (colors[score] || 'bg-secondary');
        text.textContent = score > 0 ? 'Força: ' + labels[score] : '';
    }
    
    document.getElementById('formSenha').addEventListener('submit', function(e) {
        const s = document.getElementById('senha').value;
        const c = document.getElementById('confirma').value;
        const mismatch = document.getElementById('senha-mismatch');
        
        if (s !== c) {
            e.preventDefault();
            mismatch.classList.remove('d-none');
            return;
        }
        mismatch.classList.add('d-none');
        document.getElementById('btnSalvar').innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i> Salvando...';
        document.getElementById('btnSalvar').disabled = true;
    });
    
    document.getElementById('confirma').addEventListener('input', function() {
        const s = document.getElementById('senha').value;
        const mismatch = document.getElementById('senha-mismatch');
        if (this.value && this.value !== s) {
            mismatch.classList.remove('d-none');
        } else {
            mismatch.classList.add('d-none');
        }
    });
    </script>
</body>
</html>
