<?php

namespace App\Services;

use App\Core\Logger;
use App\Core\Mailer;
use App\Repositories\GrupoNotificacaoRepository;

/** Dispara alertas de prioridade por grupo sem bloquear a alteração clínica já persistida. */
final class GrupoAlertaService
{
    private GrupoNotificacaoRepository $repo;

    public function __construct(?GrupoNotificacaoRepository $repo = null)
    {
        $this->repo = $repo ?? new GrupoNotificacaoRepository();
    }

    /** @return array{groups: array<int, array<string, mixed>>} */
    public function preview(array $study, int $tenantId, string $priority): array
    {
        $priority = strtoupper(trim($priority));
        $studyModalities = $this->parseModalities((string) ($study['modalities'] ?? ''));
        $groups = [];
        foreach ($this->repo->listEligibleRules($tenantId, $priority) as $rule) {
            $configured = $this->repo->listModalities((int) $rule['grupo_id'], $tenantId, 'notificacao');
            if (!$this->matchesModalities($studyModalities, $configured)) continue;
            $channels = [];
            foreach (['email', 'whatsapp', 'telegram'] as $channel) {
                if (!empty($rule['canal_' . $channel])) $channels[] = $channel;
            }
            $groups[] = [
                'group_id' => (int) $rule['grupo_id'],
                'name' => (string) $rule['nome'],
                'member_count' => (int) $rule['total_membros'],
                'channels' => $channels,
                'modalities' => $configured,
            ];
        }
        return ['groups' => $groups];
    }

    public function notifyPriorityChanged(array $study, int $tenantId, string $previous, string $priority, int $auditId): array
    {
        $priority = strtoupper(trim($priority));
        $preview = $this->preview($study, $tenantId, $priority);
        $summary = ['groups' => count($preview['groups']), 'email_sent' => 0, 'email_failed' => 0];
        $studyId = (int) ($study['id'] ?? 0);
        $modalitiesLabel = implode('\\', $this->parseModalities((string) ($study['modalities'] ?? ''))) ?: 'N/I';

        foreach ($preview['groups'] as $group) {
            $groupId = (int) $group['group_id'];
            if (in_array('email', $group['channels'], true)) {
                $members = $this->repo->listMemberEmails($groupId, $tenantId);
                $sent = 0;
                foreach ($members as $member) {
                    $subject = '[VOXEL PACS] Prioridade ' . $priority . ' atribuída';
                    $body = '<p>Um estudo recebeu prioridade <strong>' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                        . '<p><strong>Modalidades:</strong> ' . htmlspecialchars($modalitiesLabel, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<p>Acesse a Worklist autenticada para consultar os detalhes autorizados.</p>';
                    if (Mailer::send((string) $member['email'], $subject, $body)) $sent++;
                }
                $failed = count($members) - $sent;
                $status = $members === [] ? 'ignorado' : ($failed === 0 ? 'enviado' : ($sent > 0 ? 'parcial' : 'erro'));
                $this->repo->registerDelivery($tenantId, $studyId, $groupId, $priority, 'email', $status, count($members), $sent);
                $summary['email_sent'] += $sent;
                $summary['email_failed'] += $failed;
            }
            $this->dispatchGlobalConnectors($studyId, $tenantId, $groupId, $priority, $modalitiesLabel, $group['channels']);
        }

        Logger::info('[GrupoAlertaService::notifyPriorityChanged] alertas processados', [
            'tenant_id' => $tenantId, 'study_id' => $studyId, 'prioridade_anterior' => $previous,
            'prioridade_nova' => $priority, 'audit_id' => $auditId, 'grupos' => $summary['groups'],
            'email_enviado' => $summary['email_sent'], 'email_falhou' => $summary['email_failed'],
        ]);
        return $summary;
    }

    private function dispatchGlobalConnectors(int $studyId, int $tenantId, int $groupId, string $priority, string $modalities, array $channels): void
    {
        $message = "VOXEL PACS: prioridade {$priority} registrada. Modalidades: {$modalities}. Consulte a Worklist autenticada.";
        foreach (['whatsapp', 'telegram'] as $channel) {
            if (!in_array($channel, $channels, true)) continue;
            try {
                if ($channel === 'whatsapp') {
                    $service = new WhatsAppService();
                    $config = $service->config();
                    if (!$service->isConfigured() || !$config) {
                        $this->repo->registerDelivery($tenantId, $studyId, $groupId, $priority, $channel, 'ignorado', 0, 0, 'Conector não configurado');
                        continue;
                    }
                    $result = $service->enviarTexto((string) $config['whatsapp_destino'], $message, 'prioridade_alterada');
                } else {
                    $service = new TelegramService();
                    $config = $service->config();
                    if (!$service->isConfigured() || !$config) {
                        $this->repo->registerDelivery($tenantId, $studyId, $groupId, $priority, $channel, 'ignorado', 0, 0, 'Conector não configurado');
                        continue;
                    }
                    $result = $service->enviarTexto((string) $config['telegram_chat_id'], $message, 'prioridade_alterada');
                }
                $ok = !empty($result['ok']);
                $this->repo->registerDelivery($tenantId, $studyId, $groupId, $priority, $channel, $ok ? 'enviado' : 'erro', 1, $ok ? 1 : 0);
            } catch (\Throwable $e) {
                Logger::warning('[GrupoAlertaService] conector falhou', [
                    'tenant_id' => $tenantId, 'estudo_id' => $studyId, 'grupo_id' => $groupId,
                    'canal' => $channel, 'error' => $e->getMessage(),
                ]);
                $this->repo->registerDelivery($tenantId, $studyId, $groupId, $priority, $channel, 'erro', 1, 0, 'Falha no conector');
            }
        }
    }

    private function parseModalities(string $stored): array
    {
        $result = [];
        foreach (explode('\\', $stored) as $value) {
            $value = strtoupper(trim($value));
            if (preg_match('/^[A-Z0-9]{1,16}$/', $value)) $result[$value] = true;
        }
        return array_keys($result);
    }

    private function matchesModalities(array $studyModalities, array $configured): bool
    {
        if ($configured === []) return true;
        return array_intersect($studyModalities, $configured) !== [];
    }
}
