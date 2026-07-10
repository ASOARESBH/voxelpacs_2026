<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

/**
 * ViewerTokenController
 *
 * Gerencia a abertura segura de exames DICOM no OHIF Viewer via token temporário.
 *
 * Fluxo:
 *   1. EstudosController::abrir() gera um token UUID e salva em pacs_viewer_tokens
 *   2. Redireciona o usuário para {VIEWER_ERP_URL}/open/{token}  (PHP resolve)
 *   3. Este controller recebe o token (rota pública, sem autenticação)
 *   4. Valida o token (existe, não expirou)
 *   5. Redireciona para {VIEWER_URL}/viewer?StudyInstanceUIDs={uid}  (OHIF abre)
 *
 * Variáveis de ambiente (.env):
 *   VIEWER_URL      = https://view.voxelpacs.com.br   (URL do OHIF)
 *   VIEWER_ERP_URL  = https://server.voxelpacs.com.br (URL do PHP)
 */

class ViewerTokenController extends Controller
{
    /** URL base do OHIF Viewer (configurável via .env) */
    private string $viewerBase;

    public function __construct()
    {
        // Ordem de prioridade: VIEWER_URL > OHIF_VIEWER_URL > hardcoded
        $this->viewerBase = rtrim(
            getenv('VIEWER_URL') ?: (getenv('OHIF_VIEWER_URL') ?: 'https://view.voxelpacs.com.br'),
            '/'
        );
    }

    // =========================================================================
    // GET /open/{token}
    // Rota PÚBLICA — não exige autenticação
    // =========================================================================
    public function abrir(string $token): void
    {
        // Sanitizar token
        $token = preg_replace('/[^a-zA-Z0-9\-_]/', '', $token);

        if (empty($token) || strlen($token) < 8) {
            $this->renderErro(400, 'Token inválido.');
            return;
        }

        $pdo = Database::getInstance();

        try {
            // Buscar token válido (não expirado)
            $stmt = $pdo->prepare("
                SELECT id, study_instance_uid, orthanc_id, expires_at, usado, usos
                FROM pacs_viewer_tokens
                WHERE token = :token
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':token' => $token]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            // Log de erro para diagnóstico
            error_log('[ViewerToken] Erro ao buscar token: ' . $e->getMessage());
            $this->renderErro(500, 'Erro interno ao validar o token. Tente novamente.');
            return;
        }

        // Token não encontrado ou expirado
        if (!$row) {
            $this->renderErro(404, 'Link expirado ou inválido. Solicite um novo link de acesso ao exame.');
            return;
        }

        // Registrar uso do token (sem bloquear — permite múltiplos acessos na validade)
        try {
            $pdo->prepare("
                UPDATE pacs_viewer_tokens
                SET usado = 1, usos = usos + 1
                WHERE id = :id
            ")->execute([':id' => $row['id']]);
        } catch (\Throwable $e) {
            // Não bloquear a abertura por falha no log de uso
            error_log('[ViewerToken] Erro ao registrar uso: ' . $e->getMessage());
        }

        // Montar URL do OHIF Viewer
        $studyUid  = $row['study_instance_uid'];
        $viewerUrl = $this->viewerBase
            . '/viewer?StudyInstanceUIDs='
            . urlencode($studyUid);

        // Redirecionar para o OHIF
        header('Location: ' . $viewerUrl, true, 302);
        exit;
    }

    // =========================================================================
    // Renderiza página de erro amigável (sem layout autenticado)
    // =========================================================================
    private function renderErro(int $httpCode, string $mensagem): void
    {
        http_response_code($httpCode);
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOXEL PACS — Acesso ao Exame</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0d1117;
            color: #e6edf3;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 2.5rem 3rem;
            max-width: 480px;
            width: 90%;
            text-align: center;
        }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        h1 { font-size: 1.4rem; margin-bottom: 0.75rem; color: #f0f6fc; }
        p  { font-size: 0.95rem; color: #8b949e; line-height: 1.6; }
        .code { color: #6e7681; font-size: 0.8rem; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><?= $httpCode === 404 ? '🔒' : '⚠️' ?></div>
        <h1>VOXEL PACS</h1>
        <p><?= htmlspecialchars($mensagem) ?></p>
        <p class="code">Código: HTTP <?= $httpCode ?></p>
    </div>
</body>
</html>
        <?php
        exit;
    }
}
