<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Services\PortalImageGatewayService;
use App\Services\PortalImageSessionService;
use Throwable;

/**
 * Endpoint do mesmo domínio do Portal. Não expõe identificador clínico, token
 * na URL, credenciais de Orthanc nem endpoint do repositório anonimizado.
 */
final class PortalImageGatewayController extends Controller
{
    public function study(string $studyUid): void
    {
        $suffix = (string) ($_GET['path'] ?? '');
        $this->serve('/studies/' . rawurlencode($studyUid) . $suffix);
    }

    public function metadata(string $studyUid): void
    {
        $this->serve('/studies/' . rawurlencode($studyUid) . '/metadata');
    }

    public function seriesMetadata(string $studyUid, string $seriesUid): void
    {
        $this->serve('/studies/' . rawurlencode($studyUid) . '/series/' . rawurlencode($seriesUid) . '/metadata');
    }

    public function rendered(string $studyUid, string $seriesUid, string $instanceUid): void
    {
        $this->serve('/studies/' . rawurlencode($studyUid) . '/series/' . rawurlencode($seriesUid) . '/instances/' . rawurlencode($instanceUid) . '/rendered');
    }

    public function frame(string $studyUid, string $seriesUid, string $instanceUid, string $frame): void
    {
        $this->serve('/studies/' . rawurlencode($studyUid) . '/series/' . rawurlencode($seriesUid) . '/instances/' . rawurlencode($instanceUid) . '/frames/' . rawurlencode($frame));
    }

    private function serve(string $path): void
    {
        if (!$this->validUidPath($path)) {
            $this->deny(404, 'path_invalid');
            return;
        }
        $token = (string) ($_COOKIE['voxel_portal_image_session'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $this->deny(404, 'session_missing');
            return;
        }
        try {
            $gateway = new PortalImageGatewayService(new PortalImageSessionService(Database::getInstance()));
            $response = $gateway->proxy($token, $path, $this->clientIp(), (string) ($_SERVER['HTTP_ACCEPT'] ?? 'application/dicom+json'));
            if ($response === null) {
                $this->deny(404, 'gateway_denied');
                return;
            }
            http_response_code((int) $response['status']);
            header('Content-Type: ' . $this->safeContentType((string) $response['content_type']));
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('Cross-Origin-Resource-Policy: same-origin');
            echo $response['body'];
            exit;
        } catch (Throwable $e) {
            Logger::error('PortalImageGatewayController: falha controlada', ['error' => $e->getMessage()]);
            $this->deny(503, 'gateway_unavailable');
        }
    }

    private function validUidPath(string $path): bool
    {
        return strlen($path) <= 1024
            && preg_match('#^/studies/[0-9.]+(?:/metadata|/series/[0-9.]+/metadata|/series/[0-9.]+/instances/[0-9.]+(?:/rendered|/frames/[1-9][0-9]*)?)?$#', $path) === 1;
    }

    private function safeContentType(string $contentType): string
    {
        $normalized = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        return match ($normalized) {
            'application/dicom+json' => 'application/dicom+json; charset=utf-8',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    private function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private function deny(int $status, string $reason): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, private');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'imagem_indisponivel']);
        Logger::warning('PortalImageGatewayController: acesso negado', ['reason' => $reason, 'ip' => $this->clientIp()]);
        exit;
    }
}
