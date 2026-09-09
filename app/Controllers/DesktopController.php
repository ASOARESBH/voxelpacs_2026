<?php
// Materialização de runtime do catálogo Downloads para publicação restrita.
/**
 * DesktopController — VOXEL Desktop
 *
 * Endpoints públicos (sem autenticação) para o aplicativo VOXEL Desktop:
 *
 *  GET  /api/desktop/version
 *       Retorna a versão mais recente disponível para atualização automática.
 *       Consultado pelo VOXEL Desktop ao iniciar.
 *
 *  GET  /desktop/download
     *       Serve o instalador mais recente do catálogo privado ou mantém o
     *       redirecionamento apenas para releases legadas ainda configuradas.
 *       Usado pelo botão "Download VOXEL Desktop" na worklist.
 *
 *  POST /api/desktop/ping
 *       Registra que o VOXEL Desktop está instalado no cliente (para o botão
 *       "VOXEL Desktop Instalado" na worklist — chamado via protocolo voxel://).
 *
 * @see database/migrations/2026-08-02_bi_desktop_releases.sql
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Services\DesktopDownloadService;

class DesktopController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/desktop/version
    // Retorna JSON com a versão mais recente do VOXEL Desktop.
    // Consultado pelo app ao iniciar para verificar atualizações.
    // ─────────────────────────────────────────────────────────────────────────
    public function version(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *'); // VOXEL Desktop (app nativo) precisa de CORS

        $plataforma = strtolower(trim($_GET['platform'] ?? 'windows'));
        $canal      = strtolower(trim($_GET['channel']  ?? 'stable'));

        // Sanitizar
        if (!in_array($plataforma, ['windows', 'mac', 'linux'], true)) $plataforma = 'windows';
        if (!in_array($canal, ['stable', 'beta'], true)) $canal = 'stable';

        try {
            $catalog = new DesktopDownloadService();
            $package = $catalog->resolvePublished($plataforma, $canal);
            if ($package) {
                echo json_encode([
                    'versao' => $package['version_name'],
                    'plataforma' => $package['platform'],
                    'canal' => $package['channel'],
                    'download_url' => 'https://server.voxelpacs.com.br/desktop/download?platform=' . rawurlencode((string) $package['platform']) . '&channel=' . rawurlencode((string) $package['channel']) . '&source=desktop_app',
                    'tamanho_bytes' => (int) $package['size_bytes'],
                    'checksum_sha256' => $package['checksum_sha256'],
                    'notas' => $package['notes'],
                    'lancado_em' => $package['published_at'],
                    'disponivel' => true,
                ]);
                return;
            }
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT versao, plataforma, canal, download_url,
                       tamanho_bytes, checksum_sha256, notas, created_at
                FROM   bi_desktop_releases
                WHERE  plataforma = ? AND canal = ? AND ativo = 1
                ORDER  BY id DESC
                LIMIT  1
            ");
            $stmt->execute([$plataforma, $canal]);
            $release = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$release) {
                // Nenhuma release cadastrada: não anuncia URL histórica inexistente.
                echo json_encode([
                    'versao'       => '1.0.0',
                    'plataforma'   => $plataforma,
                    'canal'        => $canal,
                    'download_url' => null,
                    'notas'        => null,
                    'disponivel'   => false, // Sem release real cadastrada
                ]);
                return;
            }

            echo json_encode([
                'versao'          => $release['versao'],
                'plataforma'      => $release['plataforma'],
                'canal'           => $release['canal'],
                'download_url'    => $release['download_url'],
                'tamanho_bytes'   => $release['tamanho_bytes'] ? (int)$release['tamanho_bytes'] : null,
                'checksum_sha256' => $release['checksum_sha256'],
                'notas'           => $release['notas'],
                'lancado_em'      => $release['created_at'],
                'disponivel'      => true,
            ]);

        } catch (\Throwable $e) {
            Logger::error('[DesktopController::version] Erro ao buscar release', ['error' => $e->getMessage()]);
            // Fallback seguro: retorna versão mínima sem quebrar o app
            echo json_encode([
                'versao'       => '1.0.0',
                'plataforma'   => $plataforma,
                'canal'        => $canal,
                'download_url' => null,
                'notas'        => null,
                'disponivel'   => false,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /desktop/download[?platform=windows]
    // Redireciona para o instalador mais recente.
    // Usado pelo botão "Download VOXEL Desktop" na worklist.
    // ─────────────────────────────────────────────────────────────────────────
    public function download(): void
    {
        $plataforma = strtolower(trim($_GET['platform'] ?? 'windows'));
        if (!in_array($plataforma, ['windows', 'mac', 'linux'], true)) $plataforma = 'windows';
        $canal = strtolower(trim($_GET['channel'] ?? 'stable'));
        if (!in_array($canal, ['stable', 'beta'], true)) $canal = 'stable';
        $source = strtolower(trim($_GET['source'] ?? 'public'));
        if (!in_array($source, ['worklist', 'installer_page', 'platform', 'desktop_app', 'public'], true)) $source = 'public';

        try {
            $catalog = new DesktopDownloadService();
            $package = $catalog->resolvePublished($plataforma, $canal);
            if ($package) {
                $path = $catalog->pathFor($package);
                if (!$path) {
                    Logger::warning('[DesktopController::download] pacote publicado ausente');
                    http_response_code(410);
                    exit;
                }
                $userId = Auth::check() ? (int) Auth::userId() : null;
                $tenantId = Auth::check() ? (int) (Auth::tenantId() ?: 0) : null;
                $secret = (string) ($_ENV['APP_SECRET'] ?? $_ENV['JWT_SECRET'] ?? '');
                $ipHash = $secret !== '' && !empty($_SERVER['REMOTE_ADDR']) ? hash_hmac('sha256', (string) $_SERVER['REMOTE_ADDR'], $secret) : null;
                $agentHash = $secret !== '' && !empty($_SERVER['HTTP_USER_AGENT']) ? hash_hmac('sha256', (string) $_SERVER['HTTP_USER_AGENT'], $secret) : null;
                try {
                    $catalog->repository()->recordDownload((int) $package['id'], $userId, $tenantId ?: null, $source, $ipHash, $agentHash);
                } catch (\Throwable) {
                    Logger::warning('[DesktopController::download] telemetria não registrada');
                }
                header('Content-Type: application/zip');
                header('Content-Length: ' . (string) filesize($path));
                header('Content-Disposition: attachment; filename="VOXELDesktop-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $package['version_name']) . '.zip"');
                header('Cache-Control: no-store, private');
                header('X-Content-Type-Options: nosniff');
                readfile($path);
                exit;
            }
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT download_url FROM bi_desktop_releases
                WHERE  plataforma = ? AND canal = 'stable' AND ativo = 1
                ORDER  BY id DESC LIMIT 1
            ");
            $stmt->execute([$plataforma]);
            $url = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $url = null;
        }

        // Compatibilidade com o fluxo histórico até que o primeiro ZIP seja publicado.
        if (!$url) {
            http_response_code(404);
            exit;
        }

        header('Location: ' . $url, true, 302);
        exit;
    }
}
