<?php
/**
 * VOXEL PACS — Viewer DICOM
 * Abre o visualizador de imagens em tela cheia
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Viewer — VOXEL PACS') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #000;
            color: #e0e6f0;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .viewer-header {
            background: #0d1b2a;
            border-bottom: 1px solid #2a3a5c;
            padding: .5rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
            min-height: 48px;
        }
        .viewer-logo {
            font-size: .85rem;
            font-weight: 700;
            color: #4fc3f7;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .viewer-info {
            display: flex;
            gap: 1.5rem;
            flex: 1;
        }
        .viewer-info-item {
            display: flex;
            flex-direction: column;
        }
        .viewer-info-label {
            font-size: .62rem;
            color: #8899bb;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .viewer-info-value {
            font-size: .82rem;
            font-weight: 600;
            color: #e0e6f0;
        }
        .viewer-actions {
            display: flex;
            gap: .4rem;
        }
        .viewer-btn {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .3rem .75rem;
            border-radius: 5px;
            border: 1px solid #2a3a5c;
            background: transparent;
            color: #8899bb;
            font-size: .75rem;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
        }
        .viewer-btn:hover {
            border-color: #4fc3f7;
            color: #4fc3f7;
        }
        .viewer-btn.primary {
            background: #1565c0;
            border-color: #1976d2;
            color: #fff;
        }
        .viewer-btn.primary:hover {
            background: #1976d2;
            color: #fff;
        }
        .viewer-body {
            flex: 1;
            position: relative;
            overflow: hidden;
        }
        .viewer-iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #000;
        }
        .viewer-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            color: #8899bb;
            background: #0a0f1a;
        }
        .viewer-placeholder i {
            font-size: 4rem;
            color: #2a3a5c;
        }
        .viewer-placeholder h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #4fc3f7;
        }
        .viewer-placeholder p {
            font-size: .82rem;
            text-align: center;
            max-width: 400px;
            line-height: 1.6;
        }
        .viewer-placeholder .orthanc-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem 1.25rem;
            background: #1565c0;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: .85rem;
            margin-top: .5rem;
            transition: background .15s;
        }
        .viewer-placeholder .orthanc-link:hover {
            background: #1976d2;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .5rem 1.5rem;
            background: #0d1b2a;
            border: 1px solid #2a3a5c;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-top: 1rem;
            text-align: left;
            width: 100%;
            max-width: 500px;
        }
        .info-grid dt {
            font-size: .65rem;
            color: #8899bb;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .info-grid dd {
            font-size: .82rem;
            font-weight: 600;
            color: #e0e6f0;
            margin: 0;
        }
    </style>
</head>
<body>

<!-- Header do viewer -->
<div class="viewer-header">
    <div class="viewer-logo">
        <i class="fa fa-x-ray"></i>
        VOXEL PACS
    </div>

    <div class="viewer-info">
        <div class="viewer-info-item">
            <span class="viewer-info-label">Paciente</span>
            <span class="viewer-info-value"><?= htmlspecialchars($estudo['patient_name'] ?? 'ANON') ?></span>
        </div>
        <div class="viewer-info-item">
            <span class="viewer-info-label">Estudo</span>
            <span class="viewer-info-value"><?= htmlspecialchars($estudo['study_description'] ?? '—') ?></span>
        </div>
        <div class="viewer-info-item">
            <span class="viewer-info-label">Data</span>
            <span class="viewer-info-value">
                <?php
                $dt = $estudo['study_date'] ?? '';
                if ($dt) {
                    try { echo (new DateTime($dt))->format('d/m/Y'); }
                    catch (\Throwable $t) { echo htmlspecialchars($dt); }
                } else { echo '—'; }
                ?>
            </span>
        </div>
        <div class="viewer-info-item">
            <span class="viewer-info-label">Modalidade</span>
            <span class="viewer-info-value"><?= htmlspecialchars($estudo['modalities'] ?? '—') ?></span>
        </div>
        <div class="viewer-info-item">
            <span class="viewer-info-label">Unidade</span>
            <span class="viewer-info-value"><?= htmlspecialchars($estudo['institution_name'] ?? '—') ?></span>
        </div>
        <div class="viewer-info-item">
            <span class="viewer-info-label">Acesso</span>
            <span class="viewer-info-value"><?= htmlspecialchars($estudo['accession_number'] ?? '—') ?></span>
        </div>
    </div>

    <div class="viewer-actions">
        <button class="viewer-btn" onclick="toggleFullscreen()" title="Tela cheia">
            <i class="fa fa-expand"></i> Tela Cheia
        </button>
        <a href="/estudos" class="viewer-btn">
            <i class="fa fa-arrow-left"></i> Voltar
        </a>
        <a href="<?= htmlspecialchars($viewerUrl) ?>" target="_blank" class="viewer-btn primary">
            <i class="fa fa-external-link-alt"></i> Abrir Externo
        </a>
    </div>
</div>

<!-- Corpo do viewer -->
<div class="viewer-body" id="viewerBody">
    <?php if (!empty($estudo['orthanc_id'])): ?>
        <!-- Tenta carregar o viewer via iframe -->
        <iframe
            id="viewerFrame"
            class="viewer-iframe"
            src="<?= htmlspecialchars($viewerUrl) ?>"
            allowfullscreen
            sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-top-navigation"
            onload="checkIframeLoad(this)"
            onerror="showPlaceholder()">
        </iframe>
        <!-- Fallback se iframe falhar -->
        <div class="viewer-placeholder" id="viewerPlaceholder" style="display:none;">
            <i class="fa fa-x-ray"></i>
            <h2>Viewer DICOM</h2>
            <p>
                O viewer não pôde ser carregado no iframe.<br>
                Clique no botão abaixo para abrir em nova aba.
            </p>
            <a href="<?= htmlspecialchars($viewerUrl) ?>" target="_blank" class="orthanc-link">
                <i class="fa fa-external-link-alt"></i> Abrir Viewer DICOM
            </a>
            <dl class="info-grid">
                <dt>Paciente</dt>
                <dd><?= htmlspecialchars($estudo['patient_name'] ?? 'ANON') ?></dd>
                <dt>Estudo</dt>
                <dd><?= htmlspecialchars($estudo['study_description'] ?? '—') ?></dd>
                <dt>Orthanc ID</dt>
                <dd><?= htmlspecialchars($estudo['orthanc_id']) ?></dd>
            </dl>
        </div>
    <?php else: ?>
        <!-- Sem orthanc_id: mostra placeholder informativo -->
        <div class="viewer-placeholder">
            <i class="fa fa-triangle-exclamation"></i>
            <h2>Estudo sem imagens vinculadas</h2>
            <p>
                Este estudo ainda não possui imagens DICOM associadas no servidor PACS.<br>
                Verifique a configuração do servidor Orthanc ou aguarde a sincronização.
            </p>
            <a href="/estudos" class="orthanc-link">
                <i class="fa fa-arrow-left"></i> Voltar à Worklist
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
function checkIframeLoad(iframe) {
    // Se o iframe carregou mas está vazio ou com erro de CORS, mostra placeholder
    try {
        const doc = iframe.contentDocument || iframe.contentWindow.document;
        if (!doc || doc.body.innerHTML === '') showPlaceholder();
    } catch (e) {
        // CORS block — mostra placeholder com link externo
        showPlaceholder();
    }
}

function showPlaceholder() {
    const frame = document.getElementById('viewerFrame');
    const ph    = document.getElementById('viewerPlaceholder');
    if (frame) frame.style.display = 'none';
    if (ph)    ph.style.display    = 'flex';
}

function toggleFullscreen() {
    const el = document.getElementById('viewerBody');
    if (!document.fullscreenElement) {
        el.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen();
    }
}
</script>
</body>
</html>
