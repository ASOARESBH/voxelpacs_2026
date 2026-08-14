<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Access\MedicoAccess;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use PDO;

/**
 * Autoriza o acesso a recursos de laudo por identificador sem revelar sua
 * existência fora do escopo clínico do usuário atual.
 *
 * O registro retornado contém dados mínimos do report e do estudo. Chamadores
 * devem tratar null como "não encontrado ou sem permissão".
 */
final class ReportAccessService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findAuthorizedReport(int $reportId, bool $requireOwnership = true): ?object
    {
        if ($reportId <= 0 || !Auth::check()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.institution_name, e.usuario_responsavel_id,
                    e.study_instance_uid, e.situacao AS estudo_situacao
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
             WHERE r.id = :report_id
             LIMIT 1"
        );
        $stmt->execute(['report_id' => $reportId]);
        $report = $stmt->fetch();

        if (!$report || !$this->isAllowed($report, $requireOwnership)) {
            return null;
        }

        return $report;
    }

    /**
     * Resolve a URL pública /reports/r/{token}. O formato fixo impede que a
     * rota aceite ids sequenciais, Study UID ou outros identificadores legados.
     */
    public function findAuthorizedReportByPublicToken(string $token, bool $requireOwnership = true): ?object
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token) || !Auth::check()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.institution_name, e.usuario_responsavel_id,
                    e.study_instance_uid, e.situacao AS estudo_situacao
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
             WHERE r.public_token = :token
             LIMIT 1"
        );
        $stmt->execute(['token' => $token]);
        $report = $stmt->fetch();

        if (!$report || !$this->isAllowed($report, $requireOwnership)) {
            return null;
        }

        return $report;
    }

    public function findAuthorizedReportByEstudoId(int $estudoId, bool $requireOwnership = true): ?object
    {
        if ($estudoId <= 0 || !Auth::check()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.institution_name, e.usuario_responsavel_id,
                    e.study_instance_uid, e.situacao AS estudo_situacao
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
             WHERE r.estudo_id = :estudo_id
             ORDER BY r.id DESC
             LIMIT 1"
        );
        $stmt->execute(['estudo_id' => $estudoId]);
        $report = $stmt->fetch();

        if (!$report || !$this->isAllowed($report, $requireOwnership)) {
            return null;
        }

        return $report;
    }

    /**
     * Verifica um estudo já carregado antes da criação do report.
     */
    public function isStudyAllowed(object $estudo, bool $requireOwnership = true): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if (Auth::isPlatformAdmin() && !Auth::isImpersonating()) {
            return true;
        }

        $currentTenantId = (int) (Auth::tenantId() ?? 0);
        $studyTenantId = (int) ($estudo->tenant_id ?? 0);
        $institutionName = trim((string) ($estudo->institution_name ?? ''));

        if ($currentTenantId <= 0 || ($studyTenantId > 0 && $studyTenantId !== $currentTenantId)) {
            Logger::warning('[ReportAccessService] acesso negado a estudo de laudo', [
                'estudo_id' => (int) ($estudo->id ?? 0),
                'usuario_id' => Auth::userId(),
                'tenant_atual' => $currentTenantId,
                'tenant_recurso' => $studyTenantId,
                'motivo' => 'tenant_divergente',
            ]);
            return false;
        }

        // bi_pacs_estudos pode não ter tenant_id. Nesse schema, a vinculação
        // segura ao tenant é feita por InstitutionName, como na Worklist.
        if ($studyTenantId === 0) {
            $tenantInstitutions = InstitutionResolverService::getInstitutionNamesByTenant($currentTenantId);
            $found = false;
            foreach ($tenantInstitutions as $tenantInstitution) {
                if (strcasecmp(trim((string) $tenantInstitution), $institutionName) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                Logger::warning('[ReportAccessService] estudo sem InstitutionName autorizado', [
                    'estudo_id' => (int) ($estudo->id ?? 0),
                    'usuario_id' => Auth::userId(),
                    'tenant_id' => $currentTenantId,
                    'motivo' => 'unidade_fora_do_tenant',
                ]);
                return false;
            }
        }

        $resource = (object) [
            'id' => (int) ($estudo->id ?? 0),
            'tenant_id' => $studyTenantId ?: $currentTenantId,
            'institution_name' => $institutionName,
            'usuario_responsavel_id' => $estudo->usuario_responsavel_id ?? null,
        ];

        return $this->isAllowed($resource, $requireOwnership);
    }

    private function isAllowed(object $resource, bool $requireOwnership): bool
    {
        if (Auth::isPlatformAdmin() && !Auth::isImpersonating()) {
            return true;
        }

        $reportTenantId = (int) ($resource->tenant_id ?? 0);
        $currentTenantId = (int) (Auth::tenantId() ?? 0);
        $reason = null;

        $perfil = strtolower((string) (Auth::perfilAtual() ?? ''));
        $medicoId = MedicoAccess::currentMedicoId();

        if ($reportTenantId <= 0 || $currentTenantId <= 0 || $reportTenantId !== $currentTenantId) {
            $reason = 'tenant_divergente';
        } elseif ($perfil === 'medico' && (!$medicoId || $medicoId <= 0)) {
            // Falha fechada: um login médico sem cadastro ativo vinculado não
            // pode herdar o escopo integral do tenant.
            $reason = 'medico_nao_vinculado';
        } elseif (!MedicoAccess::isInstitutionAllowed((string) ($resource->institution_name ?? ''))) {
            $reason = 'unidade_nao_autorizada';
        } elseif ($requireOwnership && MedicoAccess::isRestricted()
            && (int) ($resource->usuario_responsavel_id ?? 0) !== (int) Auth::userId()) {
            $reason = 'estudo_assumido_por_outro';
        }

        if ($reason === null) {
            return true;
        }

        Logger::warning('[ReportAccessService] acesso negado a recurso de laudo', [
            'report_id' => (int) ($resource->id ?? 0),
            'usuario_id' => Auth::userId(),
            'tenant_atual' => $currentTenantId,
            'tenant_recurso' => $reportTenantId,
            'motivo' => $reason,
        ]);
        return false;
    }
}
