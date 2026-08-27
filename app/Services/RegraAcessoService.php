<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Audit\RequestAuditContext;
use App\Core\Auth;
use App\Core\Logger;
use App\Repositories\RegraAcessoRepository;

/** Regras de acesso de sessão, IP e horário; nunca autoriza por ausência de evidência. */
final class RegraAcessoService
{
    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 480;
    private const MAX_IP_LINES = 50;
    private const MAX_IP_TEXT = 4096;

    private RegraAcessoRepository $repo;

    public function __construct()
    {
        $this->repo = new RegraAcessoRepository();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTenant(int $tenantId): array
    {
        return $this->repo->listForTenant($tenantId);
    }

    /** @return array<string,mixed>|null */
    public function userRule(int $userId, int $tenantId): ?array
    {
        return $this->repo->findForUser($userId, $tenantId);
    }

    /** @return array<string,mixed>|null */
    public function tenantUser(int $userId, int $tenantId): ?array
    {
        return $this->repo->findTenantUser($userId, $tenantId);
    }

    /** @return array{ok:bool,reason?:string} */
    public function saveRule(int $targetUserId, int $tenantId, array $post): array
    {
        $target = $this->repo->findTenantUser($targetUserId, $tenantId);
        if (!$target || ($target['status'] ?? '') !== 'ativo' || (int) ($target['tenant_ativo'] ?? 0) !== 1) {
            return ['ok' => false, 'reason' => 'usuario_invalido'];
        }
        if (!$this->canManageTarget($targetUserId, (string) ($target['perfil'] ?? ''))) {
            return ['ok' => false, 'reason' => 'nao_autorizado'];
        }

        $normalized = $this->normalize($post);
        if (!$normalized['ok']) return $normalized;

        $before = $this->repo->findForUser($targetUserId, $tenantId);
        try {
            $this->repo->save($targetUserId, $tenantId, $normalized['rule']);
            AuditLogger::log('regra_acesso.atualizada', 'usuario', $targetUserId, [
                'sessao_timeout_ativo' => (bool) $normalized['rule']['sessao_timeout_ativo'],
                'sessao_timeout_minutos' => $normalized['rule']['sessao_timeout_minutos'],
                'ip_restricao_ativa' => (bool) $normalized['rule']['ip_restricao_ativa'],
                'ips_permitidos_quantidade' => count($this->ipEntries((string) ($normalized['rule']['ip_lista_permitida'] ?? ''))),
                'horario_restricao_ativa' => (bool) $normalized['rule']['horario_restricao_ativa'],
                'horario_dias_quantidade' => count(explode(',', (string) ($normalized['rule']['horario_dias_semana'] ?? ''))),
                'regra_existia' => $before !== null,
            ], $tenantId, 'acesso');
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[RegraAcessoService::saveRule] persistência recusada', ['tenant_id' => $tenantId, 'user_id' => $targetUserId]);
            return ['ok' => false, 'reason' => 'persistencia_falhou'];
        }
    }

    /** @return array{allowed:bool,reason?:string} */
    public function checkLoginForUser(object $user): array
    {
        if (($user->role ?? '') === 'superadmin') return ['allowed' => true];
        $tenantIds = $this->repo->activeTenantIdsForUser((int) $user->id);
        if ($tenantIds === []) return ['allowed' => true];

        // Antes da seleção de empresa, qualquer tenant permitido mantém o
        // login possível. A empresa escolhida é revalidada em setTenant().
        $firstDenied = null;
        foreach ($tenantIds as $tenantId) {
            $result = $this->evaluate((int) $user->id, $tenantId, true, false);
            if ($result['allowed']) return ['allowed' => true];
            $firstDenied ??= ['tenant_id' => $tenantId, 'reason' => $result['reason'] ?? 'configuracao_indisponivel'];
        }
        if ($firstDenied !== null) {
            $this->auditDenied((string) $firstDenied['reason'], (int) $user->id, (int) $firstDenied['tenant_id'], 'login');
            return ['allowed' => false, 'reason' => (string) $firstDenied['reason']];
        }
        return ['allowed' => false, 'reason' => 'configuracao_indisponivel'];
    }

    /** @return array{allowed:bool,reason?:string} */
    public function checkLoginForTenant(int $userId, int $tenantId): array
    {
        if (Auth::isPlatformAdmin()) return ['allowed' => true];
        return $this->evaluate($userId, $tenantId, true, true);
    }

    /** @return array{allowed:bool,reason?:string,timeout_minutes?:int} */
    public function checkCurrentRequest(int $userId, int $tenantId): array
    {
        if (Auth::isPlatformAdmin()) return ['allowed' => true];
        return $this->evaluate($userId, $tenantId, false, true);
    }

    public function auditSessionExpired(int $userId, int $tenantId): void
    {
        AuditLogger::log('acesso.sessao_expirada', 'sessao', $userId, ['motivo' => 'inatividade'], $tenantId, 'acesso');
    }

    private function canManageTarget(int $targetUserId, string $targetProfile): bool
    {
        if (!Auth::canManageTenantUsers()) return false;
        if (!Auth::isPlatformAdmin() && $targetUserId === (int) Auth::userId()) return false;
        return Auth::isPlatformAdmin() || strtolower($targetProfile) !== 'admin';
    }

    /** @return array{allowed:bool,reason?:string,timeout_minutes?:int} */
    private function evaluate(int $userId, int $tenantId, bool $checkSchedule, bool $audit): array
    {
        try {
            $rule = $this->repo->findForUser($userId, $tenantId);
        } catch (\Throwable $e) {
            Logger::warning('[RegraAcessoService] leitura de regra indisponível', ['tenant_id' => $tenantId, 'user_id' => $userId]);
            if ($audit) $this->auditDenied('configuracao_indisponivel', $userId, $tenantId, $checkSchedule ? 'login' : 'requisicao');
            return ['allowed' => false, 'reason' => 'configuracao_indisponivel'];
        }
        if (!$rule) return ['allowed' => true];

        $timeout = (int) ($rule['sessao_timeout_minutos'] ?? 0);
        $result = ['allowed' => true];
        if ((int) ($rule['sessao_timeout_ativo'] ?? 0) === 1) $result['timeout_minutes'] = $timeout;

        if ((int) ($rule['ip_restricao_ativa'] ?? 0) === 1) {
            $ip = RequestAuditContext::metadata()['ip'] ?? null;
            if (!is_string($ip) || !$this->ipAllowed($ip, (string) ($rule['ip_lista_permitida'] ?? ''))) {
                if ($audit) $this->auditDenied('ip_nao_permitido', $userId, $tenantId, $checkSchedule ? 'login' : 'requisicao');
                return ['allowed' => false, 'reason' => 'ip_nao_permitido'];
            }
        }

        if ($checkSchedule && (int) ($rule['horario_restricao_ativa'] ?? 0) === 1 && !$this->scheduleAllowed($rule)) {
            if ($audit) $this->auditDenied('horario_nao_permitido', $userId, $tenantId, 'login');
            return ['allowed' => false, 'reason' => 'horario_nao_permitido'];
        }
        return $result;
    }

    private function auditDenied(string $reason, int $userId, int $tenantId, string $origin): void
    {
        $action = match ($reason) {
            'ip_nao_permitido' => 'acesso.bloqueado_ip',
            'horario_nao_permitido' => 'acesso.bloqueado_horario',
            default => 'acesso.bloqueado_configuracao',
        };
        AuditLogger::log($action, 'sessao', $userId, ['origem' => $origin], $tenantId, 'acesso');
    }

    /** @return array{ok:bool,rule?:array<string,mixed>,reason?:string} */
    private function normalize(array $post): array
    {
        $timeoutActive = isset($post['sessao_timeout_ativo']);
        $timeout = trim((string) ($post['sessao_timeout_minutos'] ?? ''));
        if ($timeoutActive && (!ctype_digit($timeout) || (int) $timeout < self::MIN_TIMEOUT || (int) $timeout > self::MAX_TIMEOUT)) {
            return ['ok' => false, 'reason' => 'timeout_invalido'];
        }

        $ipActive = isset($post['ip_restricao_ativa']);
        $ips = $this->normalizeIpList((string) ($post['ip_lista_permitida'] ?? ''));
        if ($ipActive && ($ips === null || $ips === '')) return ['ok' => false, 'reason' => 'ips_invalidos'];

        $scheduleActive = isset($post['horario_restricao_ativa']);
        $start = $this->normalizeTime((string) ($post['horario_inicio'] ?? ''));
        $end = $this->normalizeTime((string) ($post['horario_fim'] ?? ''));
        $days = $this->normalizeDays($post['horario_dias_semana'] ?? []);
        if ($scheduleActive && ($start === null || $end === null || $start === $end || $days === null || $days === '')) {
            return ['ok' => false, 'reason' => 'horario_invalido'];
        }

        return ['ok' => true, 'rule' => [
            'sessao_timeout_ativo' => $timeoutActive ? 1 : 0,
            'sessao_timeout_minutos' => $timeoutActive ? (int) $timeout : null,
            'ip_restricao_ativa' => $ipActive ? 1 : 0,
            'ip_lista_permitida' => $ipActive ? $ips : null,
            'horario_restricao_ativa' => $scheduleActive ? 1 : 0,
            'horario_inicio' => $scheduleActive ? $start : null,
            'horario_fim' => $scheduleActive ? $end : null,
            'horario_dias_semana' => $scheduleActive ? $days : null,
        ]];
    }

    private function normalizeIpList(string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_IP_TEXT) return null;
        $entries = $this->ipEntries($value);
        if (count($entries) > self::MAX_IP_LINES) return null;
        $valid = [];
        foreach ($entries as $entry) {
            if (!$this->validIpEntry($entry)) return null;
            $valid[strtolower($entry)] = strtolower($entry);
        }
        return implode("\n", array_values($valid));
    }

    /** @return string[] */
    private function ipEntries(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn(string $ip): bool => $ip !== ''));
    }

    private function validIpEntry(string $entry): bool
    {
        if (strtolower($entry) === 'localhost') return true;
        if (filter_var($entry, FILTER_VALIDATE_IP)) return true;
        if (!str_contains($entry, '/')) return false;
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        if (!is_string($address) || !is_string($prefix) || !ctype_digit($prefix) || !filter_var($address, FILTER_VALIDATE_IP)) return false;
        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }

    private function ipAllowed(string $ip, string $list): bool
    {
        foreach ($this->ipEntries($list) as $entry) {
            if (strtolower($entry) === 'localhost' && in_array($ip, ['127.0.0.1', '::1'], true)) return true;
            if ($entry === $ip) return true;
            if (str_contains($entry, '/') && $this->cidrContains($ip, $entry)) return true;
        }
        return false;
    }

    private function cidrContains(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) return false;
        $bits = (int) $prefix;
        $fullBytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if ($fullBytes && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) return false;
        if (!$remaining) return true;
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }

    private function scheduleAllowed(array $rule): bool
    {
        $days = array_map('intval', explode(',', (string) ($rule['horario_dias_semana'] ?? '')));
        $today = (int) date('N');
        if (!in_array($today, $days, true)) return false;
        $now = date('H:i:s');
        $start = substr((string) ($rule['horario_inicio'] ?? ''), 0, 8);
        $end = substr((string) ($rule['horario_fim'] ?? ''), 0, 8);
        if ($start === '' || $end === '') return false;
        return $start < $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) return null;
        return $value . ':00';
    }

    private function normalizeDays(mixed $value): ?string
    {
        if (!is_array($value)) return null;
        $days = array_unique(array_map('intval', $value));
        sort($days);
        foreach ($days as $day) if ($day < 1 || $day > 7) return null;
        return implode(',', $days);
    }
}
