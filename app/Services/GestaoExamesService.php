<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Audit\AuditLogger;
use App\Repositories\GestaoExamesRepository;

/**
 * Regras administrativas da tela Gestão de Exames.
 * A prioridade exibida é a efetiva: override operacional quando houver,
 * senão a tag DICOM bruta importada do Orthanc.
 */
class GestaoExamesService
{
    private const PRIORITIES = ['STAT', 'HIGH', 'ROUTINE', 'MEDIUM', 'LOW'];

    private GestaoExamesRepository $repo;

    public function __construct(?GestaoExamesRepository $repo = null)
    {
        $this->repo = $repo ?: new GestaoExamesRepository();
    }

    /** Resolve a empresa efetiva do estudo respeitando o escopo da sessão ou bypass global. */
    public function resolveTenantForStudy(int $studyId, ?int $sessionTenantId, bool $bypassGlobal): ?int
    {
        return $this->repo->findTenantIdForStudy($studyId, $sessionTenantId, $bypassGlobal);
    }

    public function context(int $studyId, int $tenantId, int $currentUserId = 0): ?array
    {
        $study = $this->repo->findStudyContext($studyId, $tenantId);
        if (!$study) return null;

        $reportSituacao = strtolower(trim((string) ($study['report_situacao'] ?? '')));
        $chatPending = ($study['chat_status'] ?? '') === 'pendente';
        $priority = strtoupper(trim((string) ($study['prioridade_efetiva'] ?? 'ROUTINE')));
        if (!in_array($priority, self::PRIORITIES, true)) $priority = 'ROUTINE';

        $chat = null;
        if ((int) ($study['report_id'] ?? 0) > 0) {
            $chat = (new ReportChatService())->context((int) $study['report_id'], $tenantId, $currentUserId);
        }
        if (is_array($chat) && ($chat['status'] ?? '') === 'pendente') $chatPending = true;
        $chatCanInteract = !is_array($chat) || ($chat['can_interact'] ?? true) !== false;
        $chatCanComplete = !is_array($chat) || ($chat['can_complete'] ?? true) !== false;

        return [
            'study_id' => (int) $study['id'],
            'tenant_id' => (int) $study['tenant_id'],
            'study_instance_uid' => (string) ($study['study_instance_uid'] ?? ''),
            'modalities' => (string) ($study['modalities'] ?? ''),
            'patient_name' => (string) ($study['patient_name'] ?? ''),
            'modalidade' => $this->primaryModality((string) ($study['modalities'] ?? '')),
            'study_description' => (string) ($study['study_description'] ?? ''),
            'study_description_manual' => !empty($study['study_description_manual']),
            'requesting_physician' => (string) ($study['medico_solicitante_exibicao'] ?? ''),
            'requesting_physician_manual' => (string) ($study['medico_solicitante_manual'] ?? ''),
            'study_information' => (string) ($study['informacoes_manual'] ?? ''),
            'study_information_present' => trim((string) ($study['informacoes_manual'] ?? '')) !== '',
            'situacao' => (string) ($study['situacao'] ?? 'novo'),
            'report_id' => (int) ($study['report_id'] ?? 0),
            'report_situacao' => $reportSituacao,
            'can_view_report' => in_array($reportSituacao, ['assinado', 'liberado'], true),
            'report_url' => $this->reportUrl((string) ($study['report_public_token'] ?? '')),
            'pdf_url' => $this->pdfUrl((string) ($study['report_public_token'] ?? '')),
            'chat_pending' => $chatPending,
            'can_interact' => $chatCanInteract,
            'can_complete' => $chatCanComplete,
            'chat' => $chat,
            'priority' => [
                'effective' => $priority,
                'raw_dicom' => strtoupper(trim((string) ($study['dicom_priority'] ?? ''))),
                'override' => $study['dicom_priority_override'] !== null
                    ? strtoupper(trim((string) $study['dicom_priority_override']))
                    : null,
                'label' => $this->priorityLabel($priority),
                'options' => $this->priorityOptions(),
            ],
            'can_change_priority' => !$chatPending,
            'priority_audit' => $this->priorityAudit($studyId, $tenantId),
        ];
    }

    public function changePriority(int $studyId, int $tenantId, int $userId, string $priority, string $reason): array
    {
        $priority = strtoupper(trim($priority));
        $reason = trim($reason);
        if (!in_array($priority, self::PRIORITIES, true)) return ['ok' => false, 'error' => 'prioridade_invalida'];
        if (mb_strlen($reason, 'UTF-8') < 20) return ['ok' => false, 'error' => 'motivo_curto'];
        if (mb_strlen($reason, 'UTF-8') > 1000) return ['ok' => false, 'error' => 'motivo_longo'];

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $study = $this->repo->lockStudyContext($studyId, $tenantId);
            if (!$study) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
            }
            if ((string) ($study['situacao'] ?? '') === 'pendente') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_pendente'];
            }
            $previous = strtoupper(trim((string) ($study['prioridade_efetiva'] ?? 'ROUTINE')));
            if (!in_array($previous, self::PRIORITIES, true)) $previous = 'ROUTINE';
            if ($previous === $priority) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'prioridade_igual'];
            }
            $this->repo->updatePriorityOverride($studyId, $tenantId, $priority);
            $auditId = $this->repo->addPriorityAudit(
                $studyId, $tenantId, (string) ($study['dicom_priority'] ?? ''), $previous, $priority, $reason, $userId
            );
            $pdo->commit();

            AuditLogger::logChange(
                'prioridade.alterada',
                'estudo',
                $studyId,
                ['prioridade' => $previous],
                ['prioridade' => $priority, 'audit_id' => $auditId, 'motivo_informado' => true],
                $tenantId,
                'gestao_estudos'
            );

            $alerts = ['groups' => 0, 'email_sent' => 0, 'email_failed' => 0];
            try {
                $alerts = (new GrupoAlertaService())->notifyPriorityChanged($study, $tenantId, $previous, $priority, $auditId);
            } catch (\Throwable $notificationError) {
                Logger::warning('[GestaoExamesService::changePriority] alertas não bloquearam a prioridade', [
                    'study_id' => $studyId, 'tenant_id' => $tenantId, 'audit_id' => $auditId,
                    'error' => $notificationError->getMessage(),
                ]);
            }
            return ['ok' => true, 'audit_id' => $auditId, 'priority' => $priority, 'label' => $this->priorityLabel($priority), 'alerts' => $alerts];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[GestaoExamesService::changePriority] falha', [
                'study_id' => $studyId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    /** Mantém a sobrescrita administrativa separada da tag DICOM original. */
    public function changeRequestingPhysician(int $studyId, int $tenantId, int $userId, string $value): array
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = mb_strtoupper($value, 'UTF-8');
        if ($value !== '' && (mb_strlen($value, 'UTF-8') < 3 || mb_strlen($value, 'UTF-8') > 180)) {
            return ['ok' => false, 'error' => 'solicitante_invalido'];
        }
        if ($value !== '' && preg_match('/[\x00-\x1F\x7F]/u', $value)) return ['ok' => false, 'error' => 'solicitante_invalido'];

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $study = $this->repo->lockStudyContext($studyId, $tenantId);
            if (!$study) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
            }
            if ((string) ($study['situacao'] ?? '') === 'pendente') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_pendente'];
            }
            $before = trim((string) ($study['medico_solicitante_manual'] ?? ''));
            if ($before === $value) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'solicitante_igual'];
            }
            $this->repo->updateManualRequestingPhysician($studyId, $tenantId, $value !== '' ? $value : null, $userId);
            $auditId = $this->repo->addManualRequestingPhysicianAudit(
                $studyId, $tenantId, $before !== '' ? $before : null, $value !== '' ? $value : null, $userId
            );
            $pdo->commit();
            AuditLogger::logChange(
                'estudo.medico_solicitante_alterado',
                'bi_pacs_estudos',
                $studyId,
                ['sobrescrita_manual' => $before !== ''],
                ['sobrescrita_manual' => $value !== '', 'audit_id' => $auditId],
                $tenantId,
                'gestao_estudos'
            );
            return ['ok' => true, 'value' => $value, 'audit_id' => $auditId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[GestaoExamesService::changeRequestingPhysician] falha', [
                'study_id' => $studyId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    /** Registra informação única para ciência médica, sem semântica de conversa. */
    public function changeStudyInformation(int $studyId, int $tenantId, int $userId, string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
        $value = preg_replace('/[ \t]+$/mu', '', $value) ?? '';
        $value = mb_strtoupper($value, 'UTF-8');
        if ($value !== '' && (mb_strlen($value, 'UTF-8') < 3 || mb_strlen($value, 'UTF-8') > 1000)) {
            return ['ok' => false, 'error' => 'informacoes_invalida'];
        }
        if ($value !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            return ['ok' => false, 'error' => 'informacoes_invalida'];
        }

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $study = $this->repo->lockStudyContext($studyId, $tenantId);
            if (!$study) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
            }
            if ((string) ($study['situacao'] ?? '') === 'pendente') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_pendente'];
            }
            $before = trim((string) ($study['informacoes_manual'] ?? ''));
            if ($before === $value) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'informacoes_igual'];
            }
            $hasAfter = $value !== '';
            $this->repo->updateStudyInformation($studyId, $tenantId, $hasAfter ? $value : null, $userId);
            $auditId = $this->repo->addStudyInformationAudit($studyId, $tenantId, $before !== '', $hasAfter, $userId);
            $pdo->commit();

            AuditLogger::logChange(
                'estudo.informacoes_alteradas',
                'bi_pacs_estudos',
                $studyId,
                ['informacao_registrada' => $before !== ''],
                ['informacao_registrada' => $hasAfter, 'audit_id' => $auditId],
                $tenantId,
                'gestao_estudos'
            );
            return ['ok' => true, 'value' => $value, 'audit_id' => $auditId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[GestaoExamesService::changeStudyInformation] falha', [
                'study_id' => $studyId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    /** Reaplica no endpoint o mesmo escopo de modalidade imposto à Worklist. */
    public function canAccessStudyModalities(int $studyId, int $tenantId, int $userId, bool $bypassGlobal): bool
    {
        if ($bypassGlobal) return true;
        if ($userId <= 0) return false;
        $groups = new GrupoModalidadeService();
        $scope = $groups->scopeForUser($userId, $tenantId);
        if (empty($scope['restricted'])) return true;
        $modalities = $this->repo->findStudyModalities($studyId, $tenantId);
        return $modalities !== null && $groups->allowsStoredModalities($modalities, $scope);
    }

    private function priorityAudit(int $studyId, int $tenantId): array
    {
        try {
            return $this->repo->listPriorityAudit($studyId, $tenantId);
        } catch (\Throwable $e) {
            Logger::warning('[GestaoExamesService::priorityAudit] migration ausente ou consulta indisponível', [
                'study_id' => $studyId, 'tenant_id' => $tenantId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function priorityOptions(): array
    {
        return array_map(fn(string $value): array => ['value' => $value, 'label' => $this->priorityLabel($value)], self::PRIORITIES);
    }

    private function priorityLabel(string $value): string
    {
        return [
            'STAT' => 'Emergência (STAT)',
            'HIGH' => 'Urgência (HIGH)',
            'ROUTINE' => 'Rotina (ROUTINE)',
            'MEDIUM' => 'Rotina (MEDIUM)',
            'LOW' => 'Ambulatorial (LOW)',
        ][$value] ?? 'Rotina (ROUTINE)';
    }

    /** Retorna a primeira modalidade DICOM válida de estudos multisseriados. */
    private function primaryModality(string $modalities): string
    {
        $modalities = strtoupper(trim($modalities));
        return preg_match('/[A-Z0-9]{1,16}/', $modalities, $matches) ? (string) $matches[0] : '';
    }

    private function reportUrl(string $publicToken): ?string
    {
        $publicToken = strtolower(trim($publicToken));
        return preg_match('/^[a-f0-9]{48}$/', $publicToken) ? '/reports/r/' . rawurlencode($publicToken) . '?origem=gestao' : null;
    }

    private function pdfUrl(string $publicToken): ?string
    {
        $publicToken = strtolower(trim($publicToken));
        return preg_match('/^[a-f0-9]{48}$/', $publicToken) ? '/reports/r/' . rawurlencode($publicToken) . '/pdf' : null;
    }
}
