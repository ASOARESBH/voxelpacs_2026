<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Audit\AuditLogger;
use App\Repositories\GestaoExamesRepository;

/**
 * Regras administrativas da tela Gestão de Exames.
 *
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

    /** Resolve o tenant efetivo do estudo respeitando o escopo da sessão ou bypass global. */
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
            $chatService = new ReportChatService();
            $chat = $chatService->context((int) $study['report_id'], $tenantId, $currentUserId);
        }
        if (is_array($chat) && ($chat['status'] ?? '') === 'pendente') {
            $chatPending = true;
        }
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

    public function changePriority(
        int $studyId,
        int $tenantId,
        int $userId,
        string $priority,
        string $reason
    ): array {
        $priority = strtoupper(trim($priority));
        $reason = trim($reason);
        if (!in_array($priority, self::PRIORITIES, true)) {
            return ['ok' => false, 'error' => 'prioridade_invalida'];
        }
        if (mb_strlen($reason, 'UTF-8') < 20) {
            return ['ok' => false, 'error' => 'motivo_curto'];
        }
        if (mb_strlen($reason, 'UTF-8') > 1000) {
            return ['ok' => false, 'error' => 'motivo_longo'];
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

            $previous = strtoupper(trim((string) ($study['prioridade_efetiva'] ?? 'ROUTINE')));
            if (!in_array($previous, self::PRIORITIES, true)) $previous = 'ROUTINE';
            if ($previous === $priority) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'prioridade_igual'];
            }

            $this->repo->updatePriorityOverride($studyId, $tenantId, $priority);
            $auditId = $this->repo->addPriorityAudit(
                $studyId,
                $tenantId,
                (string) ($study['dicom_priority'] ?? ''),
                $previous,
                $priority,
                $reason,
                $userId
            );
            $pdo->commit();

            Logger::info('[GestaoExamesService::changePriority] prioridade alterada', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'previous' => $previous,
                'next' => $priority,
                'audit_id' => $auditId,
            ]);

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

            return [
                'ok' => true,
                'audit_id' => $auditId,
                'priority' => $priority,
                'label' => $this->priorityLabel($priority),
                'alerts' => $alerts,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[GestaoExamesService::changePriority] falha', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    private function priorityAudit(int $studyId, int $tenantId): array
    {
        try {
            return $this->repo->listPriorityAudit($studyId, $tenantId);
        } catch (\Throwable $e) {
            Logger::warning('[GestaoExamesService::priorityAudit] migration ausente ou consulta indisponível', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function priorityOptions(): array
    {
        return array_map(fn(string $value): array => [
            'value' => $value,
            'label' => $this->priorityLabel($value),
        ], self::PRIORITIES);
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
        if (preg_match('/[A-Z0-9]{1,16}/', $modalities, $matches)) {
            return (string) $matches[0];
        }
        return '';
    }

    private function reportUrl(string $publicToken): ?string
    {
        $publicToken = strtolower(trim($publicToken));
        return preg_match('/^[a-f0-9]{48}$/', $publicToken)
            ? '/reports/r/' . rawurlencode($publicToken) . '?origem=gestao'
            : null;
    }

    private function pdfUrl(string $publicToken): ?string
    {
        $publicToken = strtolower(trim($publicToken));
        return preg_match('/^[a-f0-9]{48}$/', $publicToken)
            ? '/reports/r/' . rawurlencode($publicToken) . '/pdf'
            : null;
    }
}
