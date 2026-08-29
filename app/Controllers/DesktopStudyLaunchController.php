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

    public function shortManifest(string $launchRef): void
    {
        try {
            $manifest = (new DesktopStudyLaunchService())->manifestByReference($launchRef);
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            echo $manifest;
        } catch (\Throwable $ex) {
            Logger::error('[DesktopStudyLaunch::shortManifest] ' . $ex->getMessage());
            http_response_code(404);
            header('Cache-Control: no-store');
            echo 'Launch indisponível.';
        }
    }

    public function instance(string $token, string $instanceId): void
    {
        $headersSent = false;
        try {
            $result = (new DesktopStudyLaunchService())->streamInstance(
                $token,
                (string) ($_GET['sig'] ?? ''),
                $instanceId,
                static function (string $contentType, ?int $contentLength) use (&$headersSent): void {
                    // O tipo é fixado pela API: cabeçalhos internos do Orthanc não são repassados.
                    header('Content-Type: application/dicom');
                    header('Content-Disposition: inline; filename="instance.dcm"');
                    header('Cache-Control: no-store, private');
                    header('Pragma: no-cache');
                    header('Referrer-Policy: no-referrer');
                    header('X-Content-Type-Options: nosniff');
                    if ($contentLength !== null && $contentLength >= 0) {
                        header('Content-Length: ' . $contentLength);
                    }
                    $headersSent = true;
                },
                static function (string $chunk): void {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            );
            if (!($result['success'] ?? false) && !$headersSent) {
                throw new \RuntimeException('desktop_instance_stream_unavailable');
            }
        } catch (\Throwable $ex) {
            Logger::error('[DesktopStudyLaunch::instance] ' . $ex->getMessage());
            if (!$headersSent) {
                http_response_code(404);
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                echo 'Instância indisponível.';
            }
        }
    }

    public function shortInstance(string $launchRef, string $instanceId): void
    {
        $headersSent = false;
        try {
            $result = (new DesktopStudyLaunchService())->streamInstanceByReference(
                $launchRef,
                (string) ($_GET['sig'] ?? ''),
                $instanceId,
                static function (string $contentType, ?int $contentLength) use (&$headersSent): void {
                    header('Content-Type: application/dicom');
                    header('Content-Disposition: inline; filename="instance.dcm"');
                    header('Cache-Control: no-store, private');
                    header('Pragma: no-cache');
                    header('Referrer-Policy: no-referrer');
                    header('X-Content-Type-Options: nosniff');
                    if ($contentLength !== null && $contentLength >= 0) {
                        header('Content-Length: ' . $contentLength);
                    }
                    $headersSent = true;
                },
                static function (string $chunk): void {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            );
            if (!($result['success'] ?? false) && !$headersSent) {
                throw new \RuntimeException('desktop_instance_stream_unavailable');
            }
        } catch (\Throwable $ex) {
            Logger::error('[DesktopStudyLaunch::shortInstance] ' . $ex->getMessage());
            if (!$headersSent) {
                http_response_code(404);
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                echo 'Instância indisponível.';
            }
        }
    }
}
