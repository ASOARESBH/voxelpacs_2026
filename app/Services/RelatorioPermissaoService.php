<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\TenantContext;

final class RelatorioPermissaoService
{
    public const CHAVES = ['sla_medicos', 'auditoria_acesso', 'auditoria_estudos', 'auditoria_clinica'];

    public function podeLer(string $chave): bool
    {
        if (!in_array($chave, self::CHAVES, true)) return false;
        if (strtolower((string) Auth::perfilAtual()) === 'admin') return true;

        $tenantId = TenantContext::id();
        $userId = Auth::userId();
        if (!$tenantId || !$userId) return false;
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM bi_user_permissoes p
               INNER JOIN bi_user_report_permissions rp
                 ON rp.tenant_id = p.tenant_id AND rp.user_id = p.user_id
              WHERE p.tenant_id = ? AND p.user_id = ? AND p.modulo = ? AND rp.report_key = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId, 'relatorios', $chave]);
        return (bool) $stmt->fetchColumn();
    }

    public function exigir(string $chave): void
    {
        if ($this->podeLer($chave)) return;
        http_response_code(403);
        require __DIR__ . '/../Views/errors/403.php';
        exit;
    }

    public function podeAdministrar(): bool
    {
        return strtolower((string) Auth::perfilAtual()) === 'admin';
    }
}
