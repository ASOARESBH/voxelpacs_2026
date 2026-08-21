<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use PDO;
use RuntimeException;
use Throwable;

/** Gateway de leitura DICOMweb do Portal — somente GET e somente um estudo. */
final class PortalImageGatewayService
{
    public function __construct(private readonly PortalImageSessionService $sessions)
    {
    }

    /** @return array{body:string,content_type:string,status:int}|null */
    public function proxy(string $token, string $requestPath, string $ip, string $accept): ?array
    {
        $session = $this->sessions->validateGatewayToken($token, $ip, $requestPath);
        if ($session === null) {
            return null;
        }
        $uid = (string) ($session['anonymized_study_uid'] ?? '');
        if (!$this->isAllowedStudyPath($requestPath, $uid)) {
            Logger::warning('PortalImageGatewayService: caminho negado', ['session_id' => $session['session_id'] ?? null]);
            return null;
        }
        try {
            return $this->targetClient()->dicomWeb($requestPath, $accept);
        } catch (Throwable $e) {
            Logger::error('PortalImageGatewayService: proxy falhou', ['session_id' => $session['session_id'] ?? null, 'error' => $e->getMessage()]);
            throw new RuntimeException('Falha ao recuperar imagem anonimizada.');
        }
    }

    private function isAllowedStudyPath(string $path, string $studyUid): bool
    {
        if ($studyUid === '' || strlen($path) > 2048 || str_contains($path, '\\')) {
            return false;
        }
        $pathOnly = (string) strtok($path, '?');
        if ($pathOnly === '/studies') {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            return count($query) === 1
                && isset($query['StudyInstanceUID'])
                && is_string($query['StudyInstanceUID'])
                && hash_equals($studyUid, $query['StudyInstanceUID']);
        }
        $prefix = '/studies/' . rawurlencode($studyUid);
        if (!str_starts_with($pathOnly, $prefix)) {
            return false;
        }
        $suffix = substr($pathOnly, strlen($prefix));
        if ($suffix !== '' && !str_starts_with($suffix, '/')) {
            return false;
        }
        // Bloqueia busca QIDO genérica, STOW, delete, bulk e quaisquer recursos fora do estudo autorizado.
        return !preg_match('#/(?:studies|series|instances)(?:/|$)#', $suffix)
            || preg_match('#^/(?:metadata|series(?:/[^/]+(?:/metadata|/instances/[^/]+(?:/frames(?:/[^/]+)?(?:/rendered)?|/rendered)?)?)?)?$#', $suffix) === 1;
    }

    private function targetClient(): PortalAnonymizedOrthancClient
    {
        $url = trim((string) (getenv('PORTAL_ANONYMIZED_ORTHANC_URL') ?: ''));
        if ($url === '') {
            throw new RuntimeException('Gateway de imagens não configurado.');
        }
        $username = trim((string) (getenv('PORTAL_ANONYMIZED_ORTHANC_USERNAME') ?: ''));
        return new PortalAnonymizedOrthancClient(
            $url,
            $username === '' ? null : $username,
            getenv('PORTAL_ANONYMIZED_ORTHANC_PASSWORD') ?: null,
            90,
        );
    }
}
