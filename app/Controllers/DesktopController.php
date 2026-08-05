<?php
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
 *       Redireciona para o instalador mais recente (Windows).
 *       Usado pelo botão "Download VOXEL Desktop" na worklist.
 *
 *  POST /api/desktop/ping
 *       Registra que o VOXEL Desktop está instalado no cliente (para o botão
 *       "VOXEL Desktop Instalado" na worklist — chamado via protocolo voxel://).
 *
 * @see database/migrations/2026-08-02_bi_desktop_releases.sql
 */
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;

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
                // Nenhuma release cadastrada ainda — retorna versão placeholder
                echo json_encode([
                    'versao'       => '1.0.0',
                    'plataforma'   => $plataforma,
                    'canal'        => $canal,
                    'download_url' => 'https://server.voxelpacs.com.br/downloads/VOXELDesktopSetup.exe',
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
                'download_url' => 'https://server.voxelpacs.com.br/downloads/VOXELDesktopSetup.exe',
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

        try {
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

        // Fallback para URL padrão
        if (!$url) {
            $url = 'https://server.voxelpacs.com.br/downloads/VOXELDesktopSetup.exe';
        }

        header('Location: ' . $url, true, 302);
        exit;
    }
}
