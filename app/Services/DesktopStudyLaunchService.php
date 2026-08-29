<?php

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Launch temporário do VOXEL Desktop baseado no protocolo Weasis.
 * O browser recebe somente token opaco e assinatura; UID, topologia Orthanc
 * e credenciais permanecem exclusivamente no servidor.
 */
final class DesktopStudyLaunchService
{
    private const LAUNCH_TTL_SECONDS = 120;
    private const MAX_INSTANCES = 10000;

    public function create(array $study, int $tenantId, int $userId, ?string $ip): array
    {
        $secret = $this->secret();
        $studyId = (int) ($study['id'] ?? 0);
        $orthancId = trim((string) ($study['orthanc_id'] ?? ''));
        $serverId = (int) ($study['servidor_id'] ?? 0);
        if ($studyId <= 0 || $tenantId <= 0 || $userId <= 0 || $orthancId === '' || $serverId <= 0) {
            throw new \RuntimeException('desktop_launch_context_invalid');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        // Referência opaca curta: evita truncamento de URI em Windows/Chromium.
        $launchRef = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', 'desktop-launch:v1:' . $token, $secret);
        $tokenHash = hash_hmac('sha256', $token, $secret);
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::LAUNCH_TTL_SECONDS . ' seconds')
            ->format('Y-m-d H:i:sP');

        Database::getInstance()->prepare(
            'INSERT INTO bi_desktop_study_launches
                (token_hash, launch_ref, signature, estudo_id, tenant_id, usuario_id, servidor_id, orthanc_study_id, ip_origem, expires_at)
             VALUES
                (:token_hash, :launch_ref, :signature, :estudo_id, :tenant_id, :usuario_id, :servidor_id, :orthanc_study_id, :ip_origem, :expires_at)'
        )->execute([
            ':token_hash' => $tokenHash,
            ':launch_ref' => $launchRef,
            ':signature' => $signature,
            ':estudo_id' => $studyId,
            ':tenant_id' => $tenantId,
            ':usuario_id' => $userId,
            ':servidor_id' => $serverId,
            ':orthanc_study_id' => $orthancId,
            ':ip_origem' => $ip,
            ':expires_at' => $expiresAt,
        ]);

        $base = $this->publicBaseUrl();
        $manifestUrl = $base . '/desktop-short-launch/' . rawurlencode($launchRef) . '/manifest';
        $command = '$dicom:get -w "' . $manifestUrl . '"';

        return [
            // O protocolo nativo do VOXEL Desktop; o fallback atende instalações
            // clínicas legadas que ainda registram somente o esquema Weasis.
            'launch_uri' => 'voxel://?' . rawurlencode($command),
            'compatibility_uri' => 'weasis://?' . rawurlencode($command),
            'expires_in_seconds' => self::LAUNCH_TTL_SECONDS,
        ];
    }

    public function manifest(string $token, string $signature): string
    {
        return $this->manifestForLaunch($this->resolve($token, $signature), $token, $signature);
    }

    /** A referência curta é opaca, temporária e suficiente para resolver um único launch. */
    public function manifestByReference(string $launchRef): string
    {
        return $this->manifestForLaunch($this->resolveReference($launchRef));
    }

    private function manifestForLaunch(array $launch, ?string $legacyToken = null, ?string $legacySignature = null): string
    {
        $orthanc = $this->orthancFor($launch);
        $study = $orthanc->getStudy((string) $launch['orthanc_study_id']);
        if (!($study['success'] ?? false) || !is_array($study['data'] ?? null)) {
            throw new \RuntimeException('desktop_manifest_source_unavailable');
        }

        $studyData = $study['data'];
        $studyTags = is_array($studyData['MainDicomTags'] ?? null) ? $studyData['MainDicomTags'] : [];
        $patientTags = is_array($studyData['PatientMainDicomTags'] ?? null) ? $studyData['PatientMainDicomTags'] : [];
        $patientName = $this->xml((string) ($patientTags['PatientName'] ?? $studyTags['PatientName'] ?? 'PACIENTE'));
        $patientId = $this->xml((string) ($patientTags['PatientID'] ?? $studyTags['PatientID'] ?? 'SEM_ID'));
        $patientIssuer = $this->xml((string) ($patientTags['IssuerOfPatientID'] ?? ''));
        $studyUid = $this->xml((string) ($studyTags['StudyInstanceUID'] ?? ''));
        if (!$this->isDicomUid($studyUid)) {
            throw new \RuntimeException('desktop_manifest_study_uid_missing');
        }

        $launchRef = trim((string) ($launch['launch_ref'] ?? ''));
        $usesShortReference = $launchRef !== '';
        $base = $this->publicBaseUrl() . ($usesShortReference
            ? '/desktop-short-launch/' . rawurlencode($launchRef) . '/instance/'
            : '/desktop-launch/' . rawurlencode((string) $legacyToken) . '/instance/');
        $signatureQuery = '?sig=' . rawurlencode($usesShortReference
            ? (string) ($launch['signature'] ?? '')
            : (string) $legacySignature);
        $seriesXml = '';
        $instanceCount = 0;

        foreach ((array) ($studyData['Series'] ?? []) as $seriesId) {
            if (!is_string($seriesId) || $seriesId === '') {
                continue;
            }
            $series = $orthanc->getSeries($seriesId);
            if (!($series['success'] ?? false) || !is_array($series['data'] ?? null)) {
                throw new \RuntimeException('desktop_manifest_series_unavailable');
            }
            $seriesData = $series['data'];
            $tags = is_array($seriesData['MainDicomTags'] ?? null) ? $seriesData['MainDicomTags'] : [];
            $seriesUid = $this->xml((string) ($tags['SeriesInstanceUID'] ?? ''));
            if (!$this->isDicomUid($seriesUid)) {
                throw new \RuntimeException('desktop_manifest_series_uid_missing');
            }
            $instancesXml = '';
            foreach ((array) ($seriesData['Instances'] ?? []) as $instanceId) {
                if (!is_string($instanceId) || $instanceId === '' || ++$instanceCount > self::MAX_INSTANCES) {
                    if ($instanceCount > self::MAX_INSTANCES) {
                        throw new \RuntimeException('desktop_manifest_instance_limit');
                    }
                    continue;
                }
                $instance = $orthanc->getInstance($instanceId);
                if (!($instance['success'] ?? false) || !is_array($instance['data'] ?? null)) {
                    throw new \RuntimeException('desktop_manifest_instance_unavailable');
                }
                $instanceTags = is_array($instance['data']['MainDicomTags'] ?? null) ? $instance['data']['MainDicomTags'] : [];
                $sopUid = $this->xml((string) ($instanceTags['SOPInstanceUID'] ?? ''));
                if (!$this->isDicomUid($sopUid)) {
                    throw new \RuntimeException('desktop_manifest_sop_uid_missing');
                }
                $sopClassUid = $this->xml((string) ($instanceTags['SOPClassUID'] ?? ''));
                if ($sopClassUid !== '' && !$this->isDicomUid($sopClassUid)) {
                    $sopClassUid = '';
                }
                $instanceNumber = $this->xml((string) ($instanceTags['InstanceNumber'] ?? ''));
                $instancesXml .= '<Instance SOPInstanceUID="' . $sopUid . '"'
                    . ($sopClassUid !== '' ? ' SOPClassUID="' . $sopClassUid . '"' : '')
                    . $this->integerAttribute('InstanceNumber', $instanceNumber)
                    . ' DirectDownloadFile="' . $this->xml(rawurlencode($instanceId) . $signatureQuery) . '"/>';
            }
            if ($instancesXml === '') {
                continue;
            }
            $seriesXml .= '<Series SeriesInstanceUID="' . $seriesUid . '"'
                . ' Modality="' . $this->xml($this->modality((string) ($tags['Modality'] ?? 'OT'))) . '"'
                . $this->integerAttribute('SeriesNumber', $this->xml((string) ($tags['SeriesNumber'] ?? '')))
                . ' SeriesDescription="' . $this->xml((string) ($tags['SeriesDescription'] ?? '')) . '">'
                . $instancesXml . '</Series>';
        }
        if ($seriesXml === '') {
            throw new \RuntimeException('desktop_manifest_instances_missing');
        }

        $consume = Database::getInstance()->prepare(
            'UPDATE bi_desktop_study_launches
             SET manifesto_served_at = NOW(), manifesto_uses = 1
             WHERE id = :id AND manifesto_uses = 0 AND expires_at > NOW() AND revogado_em IS NULL'
        );
        $consume->execute([':id' => (int) $launch['id']]);
        if ($consume->rowCount() !== 1) {
            throw new \RuntimeException('desktop_manifest_already_consumed');
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.weasis.org/xsd/2.5">'
            . '<arcQuery arcId="voxel" baseUrl="' . $this->xml($base) . '" requireOnlySOPInstanceUID="false">'
            . '<Patient PatientID="' . $patientId . '" PatientName="' . $patientName . '"'
            . ($patientIssuer !== '' ? ' IssuerOfPatientID="' . $patientIssuer . '"' : '') . '>'
            . '<Study StudyInstanceUID="' . $studyUid . '" StudyDescription="' . $this->xml((string) ($studyTags['StudyDescription'] ?? '')) . '">'
            . $seriesXml . '</Study></Patient></arcQuery></manifest>';
    }

    public function instance(string $token, string $signature, string $instanceId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $instanceId)) {
            throw new \RuntimeException('desktop_instance_invalid');
        }
        $launch = $this->resolve($token, $signature);
        $orthanc = $this->orthancFor($launch);
        $this->assertInstanceBelongsToLaunch($orthanc, $instanceId, $launch);
        $binary = $orthanc->downloadInstance($instanceId);
        if (!($binary['success'] ?? false)) {
            throw new \RuntimeException('desktop_instance_source_unavailable');
        }
        return $binary;
    }

    /**
     * Faz proxy de uma instância autorizada sem carregá-la inteira na memória da API.
     * Os callbacks recebem apenas cabeçalhos sanitizados e blocos binários do Orthanc.
     */
    public function streamInstance(string $token, string $signature, string $instanceId, callable $onHeaders, callable $onChunk): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $instanceId)) {
            throw new \RuntimeException('desktop_instance_invalid');
        }
        $launch = $this->resolve($token, $signature);
        $orthanc = $this->orthancFor($launch);
        $this->assertInstanceBelongsToLaunch($orthanc, $instanceId, $launch);
        return $orthanc->streamInstanceFile($instanceId, $onHeaders, $onChunk);
    }

    /** O proxy curto usa referência aleatória de 128 bits e revalida o estudo. */
    public function streamInstanceByReference(string $launchRef, string $signature, string $instanceId, callable $onHeaders, callable $onChunk): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $launchRef)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $instanceId)) {
            throw new \RuntimeException('desktop_instance_invalid');
        }
        $launch = $this->resolveReference($launchRef);
        if (!hash_equals((string) $launch['signature'], $signature)) {
            throw new \RuntimeException('desktop_launch_invalid');
        }
        $orthanc = $this->orthancFor($launch);
        $this->assertInstanceBelongsToLaunch($orthanc, $instanceId, $launch);
        return $orthanc->streamInstanceFile($instanceId, $onHeaders, $onChunk);
    }

    private function assertInstanceBelongsToLaunch(OrthancService $orthanc, string $instanceId, array $launch): void
    {
        $instance = $orthanc->getInstance($instanceId);
        if (!($instance['success'] ?? false) || !is_array($instance['data'] ?? null)
            || (string) ($instance['data']['ParentStudy'] ?? '') !== (string) $launch['orthanc_study_id']) {
            throw new \RuntimeException('desktop_instance_not_authorized');
        }
    }

    private function resolve(string $token, string $signature): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,96}$/', $token) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new \RuntimeException('desktop_launch_invalid');
        }
        $secret = $this->secret();
        $expected = hash_hmac('sha256', 'desktop-launch:v1:' . $token, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('desktop_launch_invalid');
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, launch_ref, signature, estudo_id, tenant_id, usuario_id, servidor_id, orthanc_study_id
             FROM bi_desktop_study_launches
             WHERE token_hash = :token_hash AND signature = :signature AND expires_at > NOW() AND revogado_em IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => hash_hmac('sha256', $token, $secret),
            ':signature' => $signature,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('desktop_launch_expired');
        }
        return $row;
    }

    private function resolveReference(string $launchRef): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $launchRef)) {
            throw new \RuntimeException('desktop_launch_invalid');
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, launch_ref, signature, estudo_id, tenant_id, usuario_id, servidor_id, orthanc_study_id
             FROM bi_desktop_study_launches
             WHERE launch_ref = :launch_ref AND expires_at > NOW() AND revogado_em IS NULL
             LIMIT 1'
        );
        $stmt->execute([':launch_ref' => $launchRef]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('desktop_launch_expired');
        }
        return $row;
    }

    private function orthancFor(array $launch): OrthancService
    {
        $stmt = Database::getInstance()->prepare('SELECT url, usuario, senha FROM bi_pacs_servidor WHERE id = :id AND ativo = 1 LIMIT 1');
        $stmt->execute([':id' => (int) $launch['servidor_id']]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$server || empty($server['url'])) {
            throw new \RuntimeException('desktop_source_server_unavailable');
        }
        return new OrthancService(
            (string) $server['url'],
            $server['usuario'] ?? null,
            Crypto::decrypt($server['senha'] ?? null),
            30
        );
    }

    private function publicBaseUrl(): string
    {
        $base = rtrim((string) (getenv('APP_URL') ?: 'https://server.voxelpacs.com.br'), '/');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || !filter_var($base, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('desktop_public_url_invalid');
        }
        return $base;
    }

    private function secret(): string
    {
        $secret = trim((string) getenv('APP_SECRET'));
        if ($secret === '') {
            throw new \RuntimeException('desktop_secret_unavailable');
        }
        return $secret;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function isDicomUid(string $uid): bool
    {
        return $uid !== '' && strlen($uid) <= 64 && (bool) preg_match('/^[0-9.]+$/', $uid);
    }

    private function integerAttribute(string $name, string $value): string
    {
        return preg_match('/^-?\d+$/', $value) ? ' ' . $name . '="' . $value . '"' : '';
    }

    private function modality(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z0-9 _]{1,16}$/', $value) ? $value : 'OT';
    }
}
