<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\SqlHelper;
use PDO;
use Throwable;

/**
 * Emite somente sessões opacas e temporárias para estudos já preparados no
 * repositório anonimizado. Este serviço nunca entrega UID, ID Orthanc, URL ou
 * credencial clínica ao navegador.
 */
final class PortalImageSessionService
{
    public const REPOSITORY_KEY = 'portal-anonymized';
    public const PROFILE_VERSION = 'voxel-portal-2026-08-v1';
    private const SESSION_MINUTES = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cria ou recupera a fila da cópia anonimizada vinculada exclusivamente ao
     * estudo de um report liberado. Retorna uma sessão apenas quando a cópia
     * estiver pronta e aprovada para revisão de dados em pixels.
     *
     * @param array<string,mixed> $releasedReport
     * @param array<string,mixed> $scope
     * @return array{status:string,token?:string,study_uid?:string,expires_at?:string,message:string}
     */
    public function issueOrQueue(array $releasedReport, array $scope, string $ip, string $userAgent): array
    {
        $reportId = (int) ($releasedReport['report_id'] ?? 0);
        $tenantId = (int) ($releasedReport['tenant_id'] ?? $scope['tenant_id'] ?? 0);
        $studyId = (int) ($releasedReport['estudo_id'] ?? 0);
        $orthancId = trim((string) ($releasedReport['orthanc_id'] ?? ''));
        $studyUid = trim((string) ($releasedReport['study_instance_uid'] ?? ''));
        $identityHash = trim((string) ($scope['identity_hash'] ?? ''));

        if ($reportId <= 0 || $tenantId <= 0 || $studyId <= 0 || $orthancId === '' || $studyUid === '' || $identityHash === '') {
            Logger::warning('PortalImageSessionService::issueOrQueue contexto incompleto', [
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'estudo_id' => $studyId,
            ]);
            return ['status' => 'unavailable', 'message' => 'As imagens deste exame ainda não podem ser preparadas.'];
        }

        try {
            $copy = $this->findCopy($tenantId, $studyId);
            if ($copy === null) {
                $this->queueCopy($tenantId, $studyId, $orthancId, $studyUid);
                $copy = $this->findCopy($tenantId, $studyId);
                if ($copy === null) {
                    return ['status' => 'unavailable', 'message' => 'Não foi possível preparar as imagens deste exame.'];
                }
                $this->audit(null, $tenantId, $reportId, 'queued', 'info', $ip, null, 'copy_queued');
            }

            if (
                (string) ($copy['state'] ?? '') !== 'ready'
                || (string) ($copy['pixel_review_status'] ?? '') !== 'approved'
                || empty($copy['anonymized_study_uid'])
            ) {
                return [
                    'status' => 'pending',
                    'message' => 'As imagens estão sendo preparadas com proteção de privacidade. Tente novamente após a validação.',
                ];
            }

            if (!empty($copy['expires_at']) && strtotime((string) $copy['expires_at']) <= time()) {
                $this->audit(null, $tenantId, $reportId, 'expired', 'info', $ip, null, 'copy_expired');
                return ['status' => 'pending', 'message' => 'A cópia protegida expirou e será preparada novamente.'];
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresSql = SqlHelper::futureTimestamp('MINUTE', self::SESSION_MINUTES);
            $stmt = $this->pdo->prepare(
                'INSERT INTO bi_portal_image_sessions
                    (token_hash, report_id, tenant_id, estudo_id, anonymized_study_id, identity_hash, ip_address, user_agent_hash, expires_at)
                 VALUES
                    (:token_hash, :report_id, :tenant_id, :estudo_id, :anonymized_study_id, :identity_hash, :ip_address, :user_agent_hash, ' . $expiresSql . ')'
            );
            $stmt->execute([
                'token_hash' => $tokenHash,
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'estudo_id' => $studyId,
                'anonymized_study_id' => (int) $copy['id'],
                'identity_hash' => $identityHash,
                'ip_address' => $this->limitIp($ip),
                'user_agent_hash' => hash('sha256', mb_substr($userAgent, 0, 1024, 'UTF-8')),
            ]);

            $sessionId = $this->lastInsertedId('bi_portal_image_sessions', $tokenHash);
            $this->audit($sessionId, $tenantId, $reportId, 'session_issued', 'allowed', $ip, null, 'ready_copy');

            return [
                'status' => 'ready',
                'token' => $token,
                'study_uid' => (string) $copy['anonymized_study_uid'],
                'expires_at' => gmdate('c', time() + self::SESSION_MINUTES * 60),
                'message' => 'Sessão segura de imagens criada.',
            ];
        } catch (Throwable $e) {
            Logger::error('PortalImageSessionService::issueOrQueue falhou', [
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $this->audit(null, $tenantId, $reportId, 'failed', 'error', $ip, null, 'issue_failed');
            return ['status' => 'unavailable', 'message' => 'As imagens não estão disponíveis neste momento.'];
        }
    }

    /**
     * Valida uma sessão opaca para o gateway. O retorno só contém o UID
     * anonimizado e dados mínimos internos para aplicação do escopo no proxy.
     *
     * @return array<string,mixed>|null
     */
    public function validateGatewayToken(string $token, string $ip, string $path): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT s.id AS session_id, s.tenant_id, s.report_id, s.estudo_id, s.identity_hash, s.ip_address,
                    c.anonymized_study_uid, c.anonymized_orthanc_id
             FROM bi_portal_image_sessions s
             INNER JOIN bi_portal_anonymized_studies c ON c.id = s.anonymized_study_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()
               AND c.state = 'ready'
               AND c.pixel_review_status = 'approved'
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($session === null || !hash_equals((string) $session['ip_address'], $this->limitIp($ip))) {
            if ($session !== null) {
                $this->audit((int) $session['session_id'], (int) $session['tenant_id'], (int) $session['report_id'], 'gateway_denied', 'denied', $ip, $path, 'ip_mismatch');
            }
            return null;
        }

        $this->pdo->prepare(
            'UPDATE bi_portal_image_sessions
             SET opened_at = COALESCE(opened_at, NOW()), last_accessed_at = NOW(), access_count = access_count + 1
             WHERE id = :id'
        )->execute(['id' => (int) $session['session_id']]);
        $this->audit((int) $session['session_id'], (int) $session['tenant_id'], (int) $session['report_id'], 'gateway_allowed', 'allowed', $ip, $path, 'token_valid');

        return $session;
    }

    /** @return array<string,mixed>|null */
    private function findCopy(int $tenantId, int $studyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bi_portal_anonymized_studies
             WHERE tenant_id = :tenant_id AND source_estudo_id = :study_id AND repository_key = :repository_key
             LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'study_id' => $studyId,
            'repository_key' => self::REPOSITORY_KEY,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function queueCopy(int $tenantId, int $studyId, string $orthancId, string $studyUid): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bi_portal_anonymized_studies
                    (tenant_id, source_estudo_id, source_orthanc_id, source_study_uid, repository_key, profile_version, state)
                 VALUES
                    (:tenant_id, :study_id, :source_orthanc_id, :source_study_uid, :repository_key, :profile_version, :state)'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'study_id' => $studyId,
                'source_orthanc_id' => $orthancId,
                'source_study_uid' => $studyUid,
                'repository_key' => self::REPOSITORY_KEY,
                'profile_version' => self::PROFILE_VERSION,
                'state' => 'pending',
            ]);
        } catch (\PDOException $e) {
            // Corrida entre requisições do mesmo paciente é esperada pela chave única.
            if (!str_contains(strtolower($e->getMessage()), 'duplicate') && !str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }
        }
    }

    private function lastInsertedId(string $table, string $tokenHash): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => $tokenHash]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function audit(?int $sessionId, int $tenantId, int $reportId, string $event, string $outcome, string $ip, ?string $path, ?string $detail): void
    {
        if ($tenantId <= 0 || $reportId <= 0) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bi_portal_image_audit
                    (image_session_id, tenant_id, report_id, event_type, outcome, ip_address, request_path, detail_code)
                 VALUES
                    (:session_id, :tenant_id, :report_id, :event_type, :outcome, :ip_address, :request_path, :detail_code)'
            );
            $stmt->execute([
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'event_type' => $event,
                'outcome' => $outcome,
                'ip_address' => $this->limitIp($ip),
                'request_path' => $path === null ? null : mb_substr($path, 0, 255, 'UTF-8'),
                'detail_code' => $detail,
            ]);
        } catch (Throwable $e) {
            Logger::error('PortalImageSessionService::audit falhou', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function limitIp(string $ip): string
    {
        return mb_substr(trim($ip), 0, 45, 'UTF-8');
    }
}
