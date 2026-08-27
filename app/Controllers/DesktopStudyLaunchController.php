<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Services\DesktopStudyLaunchService;

/** Endpoints públicos estritamente limitados a launch assinado e temporário. */
final class DesktopStudyLaunchController extends Controller
{
    public function manifest(string $token): void
    {
        try {
            $manifest = (new DesktopStudyLaunchService())->manifest($token, (string) ($_GET['sig'] ?? ''));
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            echo $manifest;
        } catch (\Throwable $ex) {
            Logger::error('[DesktopStudyLaunch::manifest] ' . $ex->getMessage());
            http_response_code(404);
            header('Cache-Control: no-store');
            echo 'Launch indisponível.';
        }
    }

    public function instance(string $token, string $instanceId): void
    {
        try {
            $file = (new DesktopStudyLaunchService())->instance($token, (string) ($_GET['sig'] ?? ''), $instanceId);
            header('Content-Type: application/dicom');
            header('Content-Length: ' . strlen((string) $file['body']));
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            echo $file['body'];
        } catch (\Throwable $ex) {
            Logger::error('[DesktopStudyLaunch::instance] ' . $ex->getMessage());
            http_response_code(404);
            header('Cache-Control: no-store');
            echo 'Instância indisponível.';
        }
    }
}
