<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Services\ViewerMeasurementService;

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
            // O token é opaco, temporário e pertence sempre a um tenant. Para
            // células segregadas, a origem do OHIF também é resolvida pelo
            // vínculo servidor–tenant do estudo, nunca pela URL global.
            $temCelulas = SqlHelper::hasTable($pdo, 'bi_tenant_orthanc_cells');
            $sql = "
                SELECT vt.id, vt.estudo_id, vt.tenant_id, vt.usuario_id, vt.study_instance_uid,
                       vt.orthanc_id, vt.expires_at, vt.usado, vt.usos";
            if ($temCelulas) {
                $sql .= ", cell.viewer_url AS cell_viewer_url, cell.status AS cell_status";
            }
            $sql .= "\n                FROM pacs_viewer_tokens vt";
            if ($temCelulas) {
                $sql .= "\n                LEFT JOIN bi_pacs_estudos e
                  ON e.id = vt.estudo_id AND e.tenant_id = vt.tenant_id
                LEFT JOIN bi_tenant_orthanc_cells cell
                  ON cell.tenant_id = vt.tenant_id
                 AND cell.servidor_id = e.servidor_id";
            }
            $sql .= "\n                WHERE vt.token = :token
                  AND vt.tenant_id IS NOT NULL
                  AND vt.expires_at > NOW()
                LIMIT 1";
            $stmt = $pdo->prepare($sql);
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

        // A integração de medições é opcional e só pode ser habilitada depois
        // que o adapter correspondente estiver validado no viewer em produção.
        // Enquanto estiver desabilitada, não criamos sessão nem anexamos qualquer
        // credencial à URL clínica do exame.
        $adapterToken = null;
        $measurementAdapterEnabled = filter_var(
            getenv('VOXEL_MEASUREMENT_ADAPTER_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if ($measurementAdapterEnabled && !empty($row['estudo_id'])) {
            try {
                $adapterToken = (new ViewerMeasurementService())->createAdapterToken($row);
            } catch (\Throwable $e) {
                // Não bloqueia a abertura clínica se a camada opcional de medidas falhar.
                error_log('[ViewerToken] Erro ao criar sessão de medições: ' . $e->getMessage());
            }
        }

        // Montar URL do OHIF Viewer. Se o estudo pertence a uma célula
        // exclusiva, uma URL de viewer explicitamente cadastrada é obrigatória.
        $viewerBase = $this->viewerBase;
        if ($temCelulas && !empty($row['cell_status'])) {
            // Células usam um único OHIF compartilhado; o token e o proxy
            // interno continuam resolvendo tenant e servidor de origem.
            $viewerBase = rtrim((string) (getenv('VIEWER_SHARED_URL') ?: ($row['cell_viewer_url'] ?? '')), '/');
            if ($viewerBase === '') {
                error_log('[ViewerToken] Célula sem viewer_url para token autorizado.');
                $this->renderErro(503, 'O visualizador desta empresa ainda não foi configurado.');
                return;
            }
        }

        $studyUid = $row['study_instance_uid'];
        // OHIF v3.12 requer rota explícita de datasource quando o viewer é
        // tenant-scoped. O fluxo legado conserva a rota histórica.
        $viewerPath = ($temCelulas && !empty($row['cell_status']))
            ? '/viewer/dicomweb?StudyInstanceUIDs='
            : '/viewer?StudyInstanceUIDs=';
        $viewerUrl = $viewerBase . $viewerPath . urlencode($studyUid);
        if ($adapterToken !== null) {
            $viewerUrl .= '#voxel_measurement_token=' . rawurlencode($adapterToken);
        }

        // O token continua HttpOnly e acompanha somente origens da Voxel. O
        // proxy DICOMweb do viewer exige esse cookie e o confere contra a
        // célula/tenant antes de encaminhar qualquer requisição ao Orthanc.
        $cookieDomain = trim((string) (getenv('VIEWER_COOKIE_DOMAIN') ?: '.voxelpacs.com.br'));
        setcookie('voxelpacs_viewer_token', $token, [
            'expires' => strtotime((string) $row['expires_at']),
            'path' => '/',
            'domain' => $cookieDomain,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Redirecionar para o OHIF
        header('Location: ' . $viewerUrl, true, 302);
        exit;
    }

    // =========================================================================
    // Autorização interna de DICOMweb para um viewer de célula exclusiva.
    // Nunca retorna UID, token, paciente ou credenciais; Nginx consome apenas
    // o status HTTP 204/401/403 por auth_request.
    // =========================================================================
    public function authorizeDicomweb(string $cellKey): void
    {
        $proxyIp = (string) (getenv('VIEWER_AUTH_PROXY_IP') ?: '10.0.0.3');
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== $proxyIp) {
            http_response_code(403);
            return;
        }

        $cellKey = preg_replace('/[^a-z0-9-]/', '', strtolower($cellKey));
        $token = (string) ($_COOKIE['voxelpacs_viewer_token'] ?? '');
        $viewerOrigin = rtrim((string) ($_SERVER['HTTP_X_VOXEL_VIEWER_ORIGIN'] ?? ''), '/');
        if ($cellKey === '' || $viewerOrigin === '' || !preg_match('/^[a-zA-Z0-9\-_]{8,128}$/', $token)) {
            http_response_code(401);
            return;
        }

        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_tenant_orthanc_cells')) {
                http_response_code(503);
                return;
            }

            $sql = "
                SELECT 1
                  FROM pacs_viewer_tokens vt
                  JOIN bi_pacs_estudos e
                    ON e.id = vt.estudo_id AND e.tenant_id = vt.tenant_id
                  JOIN bi_tenant_orthanc_cells cell
                    ON cell.tenant_id = vt.tenant_id
                   AND cell.servidor_id = e.servidor_id
                 WHERE vt.token = :token
                   AND vt.tenant_id IS NOT NULL
                   AND vt.expires_at > NOW()
                   AND cell.gateway_route_key = :cell_key
                   AND cell.status IN ('provisioned', 'active')
                   AND rtrim(cell.viewer_url, '/') = :viewer_origin
                 LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':token' => $token,
                ':cell_key' => $cellKey,
                ':viewer_origin' => $viewerOrigin,
            ]);
            if (!$stmt->fetchColumn()) {
                http_response_code(401);
                return;
            }
        } catch (\Throwable $e) {
            error_log('[ViewerToken] Falha na autorização interna DICOMweb: ' . get_class($e));
            http_response_code(503);
            return;
        }

        http_response_code(204);
    }

    // =========================================================================
    // Auth-request do OHIF compartilhado. A API retorna cabeçalhos apenas para
    // o Nginx privado, após validar token, tenant, estudo, servidor e origem.
    // Nenhuma informação clínica ou segredo é enviado ao navegador.
    // =========================================================================
    public function authorizeSharedDicomweb(): void
    {
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== (string) (getenv('VIEWER_AUTH_PROXY_IP') ?: '10.0.0.3')) {
            http_response_code(403); return;
        }
        $token = (string) ($_COOKIE['voxelpacs_viewer_token'] ?? '');
        $origin = rtrim((string) ($_SERVER['HTTP_X_VOXEL_VIEWER_ORIGIN'] ?? ''), '/');
        $expected = rtrim((string) (getenv('VIEWER_SHARED_URL') ?: 'https://view.voxelpacs.com.br'), '/');
        if (!preg_match('/^[a-zA-Z0-9\-_]{8,128}$/', $token) || !hash_equals($expected, $origin)) {
            http_response_code(401); return;
        }
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT s.dicomweb_url, s.usuario, s.senha
                      FROM pacs_viewer_tokens vt
                      JOIN bi_pacs_estudos e ON e.id = vt.estudo_id AND e.tenant_id = vt.tenant_id
                      JOIN bi_pacs_servidor s ON s.id = e.servidor_id AND s.ativo = 1
                      JOIN bi_tenant_orthanc_cells cell ON cell.tenant_id = vt.tenant_id AND cell.servidor_id = e.servidor_id
                     WHERE vt.token = :token AND vt.tenant_id IS NOT NULL AND vt.expires_at > NOW()
                       AND cell.status IN ('provisioned','active') LIMIT 1";
            $stmt = $pdo->prepare($sql); $stmt->execute([':token' => $token]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $url = is_array($row) ? rtrim((string) ($row['dicomweb_url'] ?? ''), '/') : '';
            $parts = parse_url($url); $host = $parts['host'] ?? '';
            $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (!$row || ($parts['scheme'] ?? '') !== 'http' || !$isPrivateIp || empty($row['usuario']) || !array_key_exists('senha', $row)) {
                http_response_code(503); return;
            }
            header('X-Voxel-Dicomweb-Upstream: ' . $url);
            header('X-Voxel-Dicomweb-Authorization: Basic ' . base64_encode((string) $row['usuario'] . ':' . (string) $row['senha']));
            http_response_code(204);
        } catch (\Throwable $e) {
            error_log('[ViewerToken] shared viewer authorization failed: ' . get_class($e));
            http_response_code(503);
        }
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
