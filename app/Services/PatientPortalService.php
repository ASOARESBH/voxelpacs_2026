<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Database;
use App\Core\Logger;
use App\Core\PatientPortalSession;
use PDO;

final class PatientPortalService
{
    private const GENERIC_FAILURE = 'Não foi possível confirmar seus dados. Verifique as informações e tente novamente.';
    private const FAILURE_LIMIT = 5;
    private const FAILURE_WINDOW_MINUTES = 15;
    private const BLOCK_MINUTES = 5;
    private const CHALLENGE_MINUTES = 10;
    private const SESSION_MINUTES = 30;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::getInstance();
    }

    /** @return array{ok:bool,msg:string,challenge_token?:string,options?:array<int,string>} */
    public function identify(string $name, string $birthDate, string $sex, string $ip, string $userAgent): array
    {
        $identity = $this->identity($name, $birthDate, $sex);
        if ($identity === null) {
            $this->recordAttempt($ip, str_repeat('0', 64), 1, false, $userAgent);
            return $this->failure();
        }
        if ($this->isBlocked($ip, $identity['identity_hash'])) {
            $this->recordAttempt($ip, $identity['identity_hash'], 1, false, $userAgent, false);
            return $this->failure();
        }

        $institutions = $this->matchingInstitutions($identity);
        // A etapa 2 é sempre exibida. Sem correspondência, todas as opções são
        // distratoras; isso impede que a resposta revele se a identidade existe.
        $correct = $institutions ? $institutions[array_rand($institutions)] : null;
        $options = $this->buildOptions($correct['institution_name'] ?? null, $institutions);
        $rawToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->pdo->prepare(
            'INSERT INTO bi_portal_challenges
             (token_hash, identity_hash, tenant_id, institution_name, options_json, ip_address, expires_at)
             VALUES (:token_hash, :identity_hash, :tenant_id, :institution_name, :options_json, :ip_address, DATE_ADD(NOW(), INTERVAL ' . self::CHALLENGE_MINUTES . ' MINUTE))'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'identity_hash' => $identity['identity_hash'],
            'tenant_id' => (int) ($correct['tenant_id'] ?? 0),
            'institution_name' => (string) ($correct['institution_name'] ?? ''),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $ip,
        ]);

        $_SESSION['patient_portal_candidate'] = $identity;
        $this->recordAttempt($ip, $identity['identity_hash'], 1, $correct !== null, $userAgent);
        $this->audit('portal.identificacao_desafiada', 0, ['identity_hash' => $identity['identity_hash'], 'ip' => $ip, 'match_found' => $correct !== null]);

        return ['ok' => true, 'msg' => '', 'challenge_token' => $rawToken, 'options' => $options];
    }

    /** @return array{ok:bool,msg:string} */
    public function verifyInstitution(string $rawToken, string $institutionName, string $ip, string $userAgent): array
    {
        $candidate = $_SESSION['patient_portal_candidate'] ?? null;
        $tokenHash = hash('sha256', trim($rawToken));
        if (!is_array($candidate) || empty($candidate['identity_hash'])) {
            $this->recordAttempt($ip, str_repeat('0', 64), 2, false, $userAgent);
            return $this->failure();
        }
        if ($this->isBlocked($ip, (string) $candidate['identity_hash'])) {
            $this->recordAttempt($ip, (string) $candidate['identity_hash'], 2, false, $userAgent, false);
            return $this->failure();
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM bi_portal_challenges
             WHERE token_hash = :token_hash AND ip_address = :ip_address
               AND expires_at > NOW() AND used_at IS NULL LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash, 'ip_address' => $ip]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        $valid = $challenge
            && hash_equals((string) $candidate['identity_hash'], (string) $challenge['identity_hash'])
            && hash_equals((string) $challenge['institution_name'], trim($institutionName));
        if (!$valid) {
            $this->recordAttempt($ip, (string) $candidate['identity_hash'], 2, false, $userAgent);
            return $this->failure();
        }

        $sessionToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $sessionToken);
        $insert = $this->pdo->prepare(
            'INSERT INTO bi_portal_sessions
             (token_hash, identity_hash, tenant_id, institution_name, ip_address, last_seen_at, expires_at)
             VALUES (:token_hash, :identity_hash, :tenant_id, :institution_name, :ip_address, NOW(), DATE_ADD(NOW(), INTERVAL ' . self::SESSION_MINUTES . ' MINUTE))'
        );
        $insert->execute([
            'token_hash' => $sessionHash,
            'identity_hash' => $candidate['identity_hash'],
            'tenant_id' => (int) $challenge['tenant_id'],
            'institution_name' => (string) $challenge['institution_name'],
            'ip_address' => $ip,
        ]);
        $this->pdo->prepare('UPDATE bi_portal_challenges SET used_at = NOW() WHERE id = :id')->execute(['id' => $challenge['id']]);
        PatientPortalSession::start($candidate, (int) $challenge['tenant_id'], (string) $challenge['institution_name'], $sessionToken);
        unset($_SESSION['patient_portal_candidate']);

        $this->recordAttempt($ip, (string) $candidate['identity_hash'], 2, true, $userAgent);
        $this->audit('portal.sessao_iniciada', 0, ['identity_hash' => $candidate['identity_hash'], 'ip' => $ip]);
        return ['ok' => true, 'msg' => ''];
    }

    /** @return array<string,mixed>|null */
    public function activeScope(string $ip): ?array
    {
        $scope = PatientPortalSession::current();
        if ($scope === null) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id FROM bi_portal_sessions
             WHERE token_hash = :token_hash AND identity_hash = :identity_hash
               AND ip_address = :ip_address AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([
            'token_hash' => hash('sha256', (string) $scope['database_token']),
            'identity_hash' => (string) $scope['identity_hash'],
            'ip_address' => $ip,
        ]);
        if (!$stmt->fetchColumn()) {
            PatientPortalSession::destroy();
            return null;
        }
        $this->pdo->prepare('UPDATE bi_portal_sessions SET last_seen_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . self::SESSION_MINUTES . ' MINUTE) WHERE token_hash = :token_hash')
            ->execute(['token_hash' => hash('sha256', (string) $scope['database_token'])]);
        return $scope;
    }

    /** @return array<int,array<string,mixed>> */
    public function studies(array $scope): array
    {
        $rows = $this->studyRows($scope, false);
        $result = [];
        foreach ($rows as $row) {
            if (!$this->samePatient($row, $scope)) continue;
            $released = (string) ($row['report_status'] ?? '') === 'liberado';
            $result[] = [
                'report_token' => $released ? (string) ($row['public_token'] ?? '') : '',
                'study_date' => (string) ($row['study_date'] ?? ''),
                'modalities' => (string) ($row['modalities'] ?? ''),
                'study_description' => (string) ($row['study_description'] ?? 'Exame sem descrição'),
                'institution_name' => (string) ($row['institution_name'] ?? ''),
                'released' => $released && !empty($row['public_token']),
                'status_label' => $released ? 'Laudo liberado' : 'Em análise',
            ];
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function releasedReportByToken(string $token, array $scope): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/i', $token)) return null;
        $rows = $this->studyRows($scope, true, $token);
        foreach ($rows as $row) {
            if ($this->samePatient($row, $scope) && (string) ($row['report_status'] ?? '') === 'liberado') return $row;
        }
        return null;
    }

    /** @param array<string,mixed> $scope */
    public function auditLaudoAberto(int $reportId, array $scope, string $publicToken): void
    {
        $this->audit('portal.laudo_aberto', $reportId, [
            'identity_hash' => (string) ($scope['identity_hash'] ?? ''),
            'tenant_id' => (int) ($scope['tenant_id'] ?? 0),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'public_token_hash' => hash('sha256', $publicToken),
        ]);
    }

    public function logout(): void
    {
        $scope = PatientPortalSession::current();
        if ($scope) {
            $this->pdo->prepare('UPDATE bi_portal_sessions SET revoked_at = NOW() WHERE token_hash = :token_hash')
                ->execute(['token_hash' => hash('sha256', (string) $scope['database_token'])]);
            $this->audit('portal.sessao_encerrada', 0, ['identity_hash' => $scope['identity_hash']]);
        }
        PatientPortalSession::destroy();
    }

    /** @return array<int,array<string,mixed>> */
    private function studyRows(array $scope, bool $byToken, ?string $token = null): array
    {
        $sql = 'SELECT e.id AS estudo_id, e.patient_name, e.patient_name_display, e.patient_birth_date, e.patient_sex,
                       e.study_date, e.modalities, e.study_description, e.institution_name,
                       r.id AS report_id, r.public_token, r.situacao AS report_status
                FROM bi_pacs_estudos e
                LEFT JOIN reports r ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id
                WHERE e.tenant_id = :tenant_id
                  AND e.patient_birth_date = :patient_birth_date
                  AND e.patient_sex = :patient_sex';
        $params = [
            'tenant_id' => (int) $scope['tenant_id'],
            'patient_birth_date' => (string) $scope['patient_birth_date'],
            'patient_sex' => (string) $scope['patient_sex'],
        ];
        if ($byToken) {
            $sql .= ' AND r.public_token = :public_token';
            $params['public_token'] = $token;
        }
        $sql .= ' ORDER BY e.study_date DESC, e.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array{tenant_id:int,institution_name:string}> */
    private function matchingInstitutions(array $identity): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT tenant_id, institution_name, patient_name, patient_name_display
             FROM bi_pacs_estudos
             WHERE patient_birth_date = :birth_date AND patient_sex = :sex
               AND institution_name IS NOT NULL AND TRIM(institution_name) <> \'\''
        );
        $stmt->execute(['birth_date' => $identity['patient_birth_date'], 'sex' => $identity['patient_sex']]);
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!$this->samePatient($row, $identity)) continue;
            $key = (int) $row['tenant_id'] . '|' . (string) $row['institution_name'];
            $matches[$key] = ['tenant_id' => (int) $row['tenant_id'], 'institution_name' => (string) $row['institution_name']];
        }
        return array_values($matches);
    }

    /** @param array<int,array{tenant_id:int,institution_name:string}> $matches @return array<int,string> */
    private function buildOptions(?string $correct, array $matches): array
    {
        $excluded = array_map(static fn(array $row): string => $row['institution_name'], $matches);
        $sql = "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE institution_name IS NOT NULL AND TRIM(institution_name) <> ''";
        if ($excluded) {
            $sql .= ' AND institution_name NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
        }
        $sql .= ' ORDER BY RAND() LIMIT 12';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($excluded);
        $distractors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $distractors, true)) $distractors[] = $name;
            if (count($distractors) === 3) break;
        }
        // Em bases pequenas, mantém quatro opções sem revelar a instituição real.
        $targetDistractors = $correct === null ? 4 : 3;
        while (count($distractors) < $targetDistractors) $distractors[] = 'Instituição ' . (count($distractors) + 1);
        $options = $correct === null ? array_slice($distractors, 0, 4) : array_slice(array_merge([$correct], $distractors), 0, 4);
        shuffle($options);
        return $options;
    }

    /** @return array<string,string>|null */
    private function identity(string $name, string $birthDate, string $sex): ?array
    {
        $name = $this->normalizeName($name);
        $sex = strtoupper(trim($sex));
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($birthDate));
        if ($name === '' || !$date || $date->format('Y-m-d') !== trim($birthDate) || !in_array($sex, ['M', 'F', 'O'], true)) return null;
        return [
            'patient_name_normalized' => $name,
            'patient_birth_date' => $date->format('Y-m-d'),
            'patient_sex' => $sex,
            'identity_hash' => hash('sha256', $name . '|' . $date->format('Y-m-d') . '|' . $sex),
        ];
    }

    private function samePatient(array $row, array $identity): bool
    {
        $expected = (string) ($identity['patient_name_normalized'] ?? '');
        return $expected !== '' && (
            hash_equals($expected, $this->normalizeName((string) ($row['patient_name'] ?? '')))
            || hash_equals($expected, $this->normalizeName((string) ($row['patient_name_display'] ?? '')))
        );
    }

    private function normalizeName(string $value): string
    {
        $value = str_replace('^', ' ', trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? '';
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function isBlocked(string $ip, string $identityHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM bi_portal_login_attempts
             WHERE (ip_address = :ip_address OR identity_hash = :identity_hash)
               AND blocked_until IS NOT NULL AND blocked_until > NOW() LIMIT 1'
        );
        $stmt->execute(['ip_address' => $ip, 'identity_hash' => $identityHash]);
        return (bool) $stmt->fetchColumn();
    }

    private function recordAttempt(string $ip, string $identityHash, int $stage, bool $success, string $userAgent, bool $evaluateLimit = true): void
    {
        try {
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM bi_portal_login_attempts
                 WHERE sucesso = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ' . self::FAILURE_WINDOW_MINUTES . ' MINUTE)
                   AND (ip_address = :ip_address OR identity_hash = :identity_hash)'
            );
            $countStmt->execute(['ip_address' => $ip, 'identity_hash' => $identityHash]);
            $previousFailures = (int) $countStmt->fetchColumn();
            $blockedUntil = ($evaluateLimit && !$success && ($previousFailures + 1) >= self::FAILURE_LIMIT)
                ? date('Y-m-d H:i:s', time() + self::BLOCK_MINUTES * 60) : null;
            $stmt = $this->pdo->prepare(
                'INSERT INTO bi_portal_login_attempts (ip_address, identity_hash, etapa, sucesso, blocked_until, user_agent)
                 VALUES (:ip_address, :identity_hash, :etapa, :sucesso, :blocked_until, :user_agent)'
            );
            $stmt->execute([
                'ip_address' => $ip,
                'identity_hash' => $identityHash,
                'etapa' => $stage,
                'sucesso' => $success ? 1 : 0,
                'blocked_until' => $blockedUntil,
                'user_agent' => mb_substr($userAgent, 0, 255),
            ]);
        } catch (\Throwable $e) {
            Logger::error('PatientPortalService::recordAttempt falhou', ['error' => $e->getMessage(), 'stage' => $stage]);
        }
    }

    /** @return array{ok:bool,msg:string} */
    private function failure(): array
    {
        return ['ok' => false, 'msg' => self::GENERIC_FAILURE];
    }

    private function audit(string $action, int $entityId, array $details): void
    {
        try { AuditLogger::log($action, 'patient_portal', $entityId, $details); }
        catch (\Throwable $e) { Logger::warning('Auditoria do Portal indisponível', ['action' => $action, 'error' => $e->getMessage()]); }
    }
}
