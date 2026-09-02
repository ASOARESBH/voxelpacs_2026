<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\Mailer;
use App\Repositories\ReportChatRepository;

/**
 * Regras de negócio do CHAT contextual do Report.
 * Destinatários e estados sempre são resolvidos dentro do tenant atual.
 */
class ReportChatService
{
    private const GROUP_ADMINISTRATIVO = 'administrativo';

    private const SUBJECTS = [
        'erro_pedido' => 'Erro no pedido',
        'contraste' => 'Contraste',
        'exames_complementares' => 'Exames complementares',
        'duvida_administrativa' => 'Dúvida administrativa',
        'achado_critico' => 'ACHADO CRÍTICO',
        'outro' => 'Outro',
    ];

    private ReportChatRepository $repo;

    public function __construct()
    {
        $this->repo = new ReportChatRepository();
    }

    public function subjects(): array
    {
        $items = [];
        foreach (self::SUBJECTS as $codigo => $label) {
            $items[] = ['codigo' => $codigo, 'label' => $label];
        }
        return $items;
    }

    public function context(int $reportId, int $tenantId, int $currentUserId = 0): ?array
    {
        $report = $this->repo->findReportContext($reportId, $tenantId);
        if (!$report) return null;
        if (!$this->canAccessStudyModalities($report, $tenantId, $currentUserId)) return null;

        $chat = $this->repo->findByReport($reportId, $tenantId);
        $messages = $chat ? $this->repo->listMessages((int) $chat['id'], $tenantId) : [];
        $lastAuthorId = $chat ? $this->repo->lastMessageAuthorId((int) $chat['id'], $tenantId) : null;
        $groups = $this->repo->listActiveGroups($tenantId);
        $defaultGroup = $this->repo->findDefaultAdministrativeGroup($tenantId);

        $selectedGroupId = (int) ($chat['destinatario_grupo_id'] ?? 0);
        if ($selectedGroupId <= 0 && (($chat['destinatario_grupo'] ?? self::GROUP_ADMINISTRATIVO) === self::GROUP_ADMINISTRATIVO) && $defaultGroup) {
            $selectedGroupId = (int) $defaultGroup['id'];
        }
        if ($selectedGroupId <= 0 && !$chat && $defaultGroup) {
            $selectedGroupId = (int) $defaultGroup['id'];
        }

        $groupOptions = [];
        foreach ($groups as $group) {
            $groupOptions[] = [
                'id' => (int) ($group['id'] ?? 0),
                'codigo' => (string) ($group['id'] ?? ''),
                'label' => (string) ($group['nome'] ?? 'Grupo'),
                'descricao' => (string) ($group['descricao'] ?? ''),
                'total_membros' => (int) ($group['total_membros'] ?? 0),
                'padrao' => $defaultGroup && (int) $defaultGroup['id'] === (int) ($group['id'] ?? 0),
            ];
        }

        $subjects = $this->subjects();
        if (Auth::perfilAtual() !== 'medico') {
            $subjects = array_values(array_filter(
                $subjects,
                static fn(array $subject): bool => ($subject['codigo'] ?? '') !== 'achado_critico'
            ));
        }

        $chatPendente = $chat && ($chat['status'] ?? '') === 'pendente';
        $autorOriginalId = (int) ($chat['criado_por'] ?? 0);
        $contraparteRespondeu = $chatPendente
            && $currentUserId > 0
            && $lastAuthorId !== null
            && $lastAuthorId === $currentUserId
            && $autorOriginalId > 0
            && $autorOriginalId !== $currentUserId;

        return [
            'report_id' => $reportId,
            'estudo_id' => (int) $report['estudo_id'],
            'status' => $chat['status'] ?? 'sem_chat',
            'pendente' => ($chat['status'] ?? '') === 'pendente',
            'destinatario_tipo' => $chat['destinatario_tipo'] ?? 'grupo',
            'destinatario_grupo' => $selectedGroupId > 0 ? (string) $selectedGroupId : '',
            'destinatario_grupo_id' => $selectedGroupId > 0 ? $selectedGroupId : null,
            'destinatario_grupo_nome' => (string) ($chat['destinatario_grupo'] ?? ($defaultGroup['nome'] ?? 'Administrativo')),
            'destinatario_user_id' => isset($chat['destinatario_user_id']) ? (int) $chat['destinatario_user_id'] : null,
            'assunto_codigo' => $chat['assunto_codigo'] ?? 'outro',
            'assunto' => $chat['assunto'] ?? '',
            'situacao_anterior' => $chat['situacao_anterior'] ?? null,
            'criado_em' => $chat['criado_em'] ?? null,
            'atualizado_em' => $chat['atualizado_em'] ?? null,
            'concluido_em' => $chat['concluido_em'] ?? null,
            'messages' => $messages,
            'subjects' => $subjects,
            'groups' => $groupOptions,
            'users' => $this->repo->listActiveUsers($tenantId, $currentUserId),
            'last_message_author_id' => $lastAuthorId,
            'can_interact' => !($chatPendente && $lastAuthorId !== null && $lastAuthorId === $currentUserId),
            // A contraparte encerra somente depois de responder. O autor
            // original não pode concluir a própria solicitação clínica.
            'can_complete' => $contraparteRespondeu,
        ];
    }

    public function hasPending(int $reportId, int $tenantId): bool
    {
        return $this->repo->hasPending($reportId, $tenantId);
    }

    public function send(int $reportId, int $tenantId, int $userId, array $input): array
    {
        $context = $this->repo->findReportContext($reportId, $tenantId);
        if (!$context) return ['ok' => false, 'error' => 'report_nao_encontrado'];
        if (!$this->canAccessStudyModalities($context, $tenantId, $userId)) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        $corpo = trim((string) ($input['mensagem'] ?? ''));
        if ($corpo === '' || mb_strlen($corpo, 'UTF-8') < 2) {
            return ['ok' => false, 'error' => 'mensagem_obrigatoria'];
        }
        if (mb_strlen($corpo, 'UTF-8') > 5000) {
            return ['ok' => false, 'error' => 'mensagem_muito_longa'];
        }

        $tipo = (string) ($input['destinatario_tipo'] ?? 'grupo');
        if (!in_array($tipo, ['grupo', 'usuario'], true)) $tipo = 'grupo';

        $group = null;
        $destinatarioGrupoId = null;
        $destinatarioGrupoNome = null;
        $destinatarioUserId = null;
        if ($tipo === 'grupo') {
            $destinatarioGrupoId = (int) ($input['destinatario_grupo'] ?? 0);
            if ($destinatarioGrupoId <= 0) {
                $group = $this->repo->findDefaultAdministrativeGroup($tenantId);
                $destinatarioGrupoId = (int) ($group['id'] ?? 0);
            } else {
                $group = $this->repo->findActiveGroup($destinatarioGrupoId, $tenantId);
            }
            if (!$group) return ['ok' => false, 'error' => 'destinatario_invalido'];
            $destinatarioGrupoId = (int) $group['id'];
            $destinatarioGrupoNome = (string) $group['nome'];
        } else {
            $destinatarioUserId = (int) ($input['destinatario_user_id'] ?? 0);
            if ($destinatarioUserId <= 0 || !$this->repo->findActiveUser($destinatarioUserId, $tenantId)) {
                return ['ok' => false, 'error' => 'destinatario_invalido'];
            }
            if ($destinatarioUserId === $userId) {
                return ['ok' => false, 'error' => 'destinatario_autor'];
            }
        }

        $assuntoCodigo = (string) ($input['assunto_codigo'] ?? 'outro');
        if (!isset(self::SUBJECTS[$assuntoCodigo])) $assuntoCodigo = 'outro';
        $assunto = trim((string) ($input['assunto'] ?? ''));
        if ($assunto === '') $assunto = self::SUBJECTS[$assuntoCodigo];
        $assunto = preg_replace('/[\r\n]+/', ' ', $assunto) ?? $assunto;
        if (mb_strlen($assunto, 'UTF-8') > 180) $assunto = mb_substr($assunto, 0, 180, 'UTF-8');

        $isAchadoCritico = $assuntoCodigo === 'achado_critico';
        if ($isAchadoCritico && Auth::perfilAtual() !== 'medico') {
            return ['ok' => false, 'error' => 'achado_critico_restrito_medico'];
        }

        $situacaoAtual = (string) ($context['situacao'] ?? 'em_laudo');
        $origemGestao = (string) ($input['origem'] ?? '') === 'gestao_exames';
        if (in_array($situacaoAtual, ['assinado', 'liberado'], true) && !$origemGestao) {
            return ['ok' => false, 'error' => 'estudo_finalizado'];
        }

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $chat = $this->repo->findByReportForUpdate($reportId, $tenantId);
            if ($chat && ($chat['status'] ?? '') === 'pendente') {
                $lastAuthorId = $this->repo->lastMessageAuthorId((int) $chat['id'], $tenantId);
                if ($lastAuthorId !== null && $lastAuthorId === $userId) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'aguardando_contraparte'];
                }
            }
            $situacaoAnterior = (string) ($chat['situacao_anterior'] ?? '');
            if (!$chat || ($chat['status'] ?? '') === 'concluido' || $situacaoAnterior === '') {
                $situacaoAnterior = $situacaoAtual === 'pendente'
                    ? ((int) ($context['usuario_responsavel_id'] ?? 0) > 0 ? 'a_laudar' : 'aberto')
                    : $situacaoAtual;
            }
            $chatId = $this->repo->upsertPending(
                $reportId,
                (int) $context['estudo_id'],
                $tenantId,
                $tipo,
                $destinatarioGrupoNome,
                $destinatarioGrupoId,
                $destinatarioUserId,
                $assuntoCodigo,
                $assunto,
                $situacaoAnterior,
                $userId
            );
            $messageId = $this->repo->addMessage($chatId, $tenantId, $userId, $corpo);
            if ($isAchadoCritico && !$this->repo->markCriticalFinding((int) $context['estudo_id'], $tenantId, $userId, $assunto)) {
                throw new \RuntimeException('Não foi possível marcar o achado crítico no estudo autorizado.');
            }
            $this->repo->updateStudySituation((int) $context['estudo_id'], $tenantId, 'pendente');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[ReportChatService::send] persistência falhou', [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }

        $notification = $isAchadoCritico
            ? $this->notifyCriticalRecipients(
                $reportId,
                $tenantId,
                $tipo,
                $destinatarioGrupoId,
                $destinatarioUserId,
                $assunto,
                $corpo,
                (string) ($context['public_token'] ?? ''),
                (string) ($context['patient_name'] ?? ''),
                (string) ($context['study_description'] ?? '')
            )
            : $this->notifyRecipients(
                $reportId,
                $tenantId,
                $tipo,
                $destinatarioGrupoId,
                $destinatarioUserId,
                $assunto,
                $corpo,
                (string) ($context['public_token'] ?? '')
            );

        if ($isAchadoCritico) {
            AuditLogger::log('estudo.achado_critico_marcado', 'bi_pacs_estudos', (int) $context['estudo_id'], [
                'usuario_id' => $userId,
                'marcado_em' => date('Y-m-d H:i:s'),
                'assunto' => $assunto,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'destinatarios_ids' => $notification['recipient_ids'],
                'emails_enviados' => $notification['sent'],
                'emails_falhos' => $notification['failed'],
            ], $tenantId);
        }

        AuditLogger::log('estudo.chat_enviado', 'bi_pacs_estudos', (int) $context['estudo_id'], [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'destinatario_tipo' => $tipo,
            'destinatario_grupo_id' => $destinatarioGrupoId,
            'destinatario_user_id' => $destinatarioUserId,
            'achado_critico' => $isAchadoCritico,
        ], $tenantId, 'gestao_estudos');

        Logger::info('[ReportChatService::send] interação registrada', [
            'report_id' => $reportId, 'chat_id' => $chatId, 'message_id' => $messageId,
            'tenant_id' => $tenantId, 'user_id' => $userId, 'destinatario_tipo' => $tipo,
            'destinatario_grupo_id' => $destinatarioGrupoId, 'destinatario_user_id' => $destinatarioUserId,
            'achado_critico' => $isAchadoCritico,
            'emails_enviados' => $notification['sent'], 'emails_falhos' => $notification['failed'],
        ]);

        $result = ['ok' => true, 'chat_id' => $chatId, 'message_id' => $messageId, 'status' => 'pendente'];
        if ($isAchadoCritico && $notification['failed'] > 0) {
            $result['email_warning'] = !empty($notification['admin_delivery_issue'])
                ? 'Achado crítico registrado, mas não há administrador ativo com e-mail válido para notificação obrigatória.'
                : 'Achado crítico registrado, mas ' . $notification['failed'] . ' notificação(ões) de e-mail não foram enviadas.';
        }
        return $result;
    }

    public function complete(int $reportId, int $tenantId, int $userId): array
    {
        $context = $this->repo->findReportContext($reportId, $tenantId);
        if (!$context) return ['ok' => false, 'error' => 'report_nao_encontrado'];
        if (!$this->canAccessStudyModalities($context, $tenantId, $userId)) return ['ok' => false, 'error' => 'report_nao_encontrado'];
        $chat = $this->repo->findByReport($reportId, $tenantId);
        if (!$chat || $chat['status'] !== 'pendente') {
            return ['ok' => false, 'error' => 'chat_sem_pendencia'];
        }
        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $chat = $this->repo->findByReportForUpdate($reportId, $tenantId);
            if (!$chat || ($chat['status'] ?? '') !== 'pendente') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_sem_pendencia'];
            }
            $lastAuthorId = $this->repo->lastMessageAuthorId((int) $chat['id'], $tenantId);
            $autorOriginalId = (int) ($chat['criado_por'] ?? 0);
            $contraparteRespondeu = $lastAuthorId !== null
                && $lastAuthorId === $userId
                && $autorOriginalId > 0
                && $autorOriginalId !== $userId;
            if (!$contraparteRespondeu) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'aguardando_contraparte'];
            }
            $closed = $this->repo->complete((int) $chat['id'], $reportId, $tenantId, $userId);
            if (!$closed) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_sem_pendencia'];
            }
            $restore = $this->normalizarSituacaoRestaurada(
                (string) ($chat['situacao_anterior'] ?? ''),
                (int) ($context['usuario_responsavel_id'] ?? 0) > 0
            );
            $this->repo->updateStudySituation((int) $context['estudo_id'], $tenantId, $restore);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[ReportChatService::complete] persistência falhou', [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }

        Logger::info('[ReportChatService::complete] conversa concluída', [
            'report_id' => $reportId, 'tenant_id' => $tenantId, 'user_id' => $userId,
            'situacao_restaurada' => $restore,
        ]);
        AuditLogger::log('estudo.chat_concluido', 'bi_pacs_estudos', (int) $context['estudo_id'], [
            'chat_id' => (int) ($chat['id'] ?? 0),
            'situacao_restaurada' => $restore,
        ], $tenantId, 'gestao_estudos');
        return ['ok' => true, 'status' => 'concluido', 'situacao' => $restore];
    }

    private function normalizarSituacaoRestaurada(string $situacao, bool $hasMedicoResponsavel): string
    {
        // Uma pendência clínica concluída não pode manter o estudo bloqueado em
        // "pendente". Para fluxos ainda em edição, a fila volta a "a_laudar":
        // a posse do médico responsável não é alterada e a abertura autorizada
        // do laudário faz a transição normal para "em_laudo". Estados finais ou
        // de revisão conservam seu significado e nunca são reabertos pelo CHAT.
        if (in_array($situacao, ['assinado', 'liberado', 'peer_review'], true)) {
            return $situacao;
        }

        if ($hasMedicoResponsavel) {
            return 'a_laudar';
        }

        // Para qualquer fluxo sem posse médica, restaura-se o estado anterior
        // permitido; um valor ausente ou inválido recai em aberto.
        $administrativas = ['novo', 'aberto', 'urgente', 'a_laudar', 'em_laudo', 'rascunho', 'revisao'];
        return in_array($situacao, $administrativas, true) ? $situacao : 'aberto';
    }

    private function canAccessStudyModalities(array $context, int $tenantId, int $userId): bool
    {
        if (Auth::isPlatformAdmin() && !Auth::isImpersonating()) return true;
        if ($userId <= 0) return false;
        $scope = (new GrupoModalidadeService())->scopeForUser($userId, $tenantId);
        return empty($scope['restricted'])
            || (new GrupoModalidadeService())->allowsStoredModalities((string) ($context['modalities'] ?? ''), $scope);
    }

    private function notifyRecipients(
        int $reportId,
        int $tenantId,
        string $tipo,
        ?int $destinatarioGrupoId,
        ?int $destinatarioUserId,
        string $assunto,
        string $corpo,
        string $publicToken
    ): array {
        $recipients = $this->selectedRecipients($tenantId, $tipo, $destinatarioGrupoId, $destinatarioUserId);
        return $this->sendNotificationEmails($reportId, $recipients, $assunto, $corpo, $publicToken, false, '', '');
    }

    private function notifyCriticalRecipients(
        int $reportId,
        int $tenantId,
        string $tipo,
        ?int $destinatarioGrupoId,
        ?int $destinatarioUserId,
        string $assunto,
        string $corpo,
        string $publicToken,
        string $patientName,
        string $studyDescription
    ): array {
        $admins = $this->repo->listActiveTenantAdmins($tenantId);
        $recipients = array_merge(
            $this->selectedRecipients($tenantId, $tipo, $destinatarioGrupoId, $destinatarioUserId),
            $admins
        );
        $result = $this->sendNotificationEmails($reportId, $recipients, $assunto, $corpo, $publicToken, true, $patientName, $studyDescription);
        $validAdminEmails = array_filter($admins, static fn(array $admin): bool => trim((string) ($admin['email'] ?? '')) !== '');
        if (!$validAdminEmails) {
            $result['failed']++;
            $result['admin_delivery_issue'] = true;
            Logger::warning('[ReportChatService::notifyCriticalRecipients] administrador sem e-mail notificável', [
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'administradores_ativos' => count($admins),
            ]);
        }
        return $result;
    }

    private function selectedRecipients(int $tenantId, string $tipo, ?int $groupId, ?int $userId): array
    {
        return $tipo === 'usuario'
            ? array_filter([$this->repo->findActiveUser((int) $userId, $tenantId)])
            : $this->repo->listUsersByGroup((int) $groupId, $tenantId);
    }

    private function sendNotificationEmails(
        int $reportId,
        array $recipients,
        string $assunto,
        string $corpo,
        string $publicToken,
        bool $isCritical,
        string $patientName,
        string $studyDescription
    ): array {
        $result = ['sent' => 0, 'failed' => 0, 'recipient_ids' => []];
        if (!preg_match('/^[a-f0-9]{48}$/', $publicToken)) {
            Logger::warning('[ReportChatService::sendNotificationEmails] token público ausente', ['report_id' => $reportId]);
            return ['sent' => 0, 'failed' => 1, 'recipient_ids' => []];
        }

        $unique = [];
        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) ($recipient['email'] ?? '')));
            if ($email !== '') $unique[$email] = $recipient;
        }
        $baseUrl = rtrim((string) (getenv('VIEWER_ERP_URL') ?: 'https://server.voxelpacs.com.br'), '/');
        $url = $baseUrl . '/reports/r/' . rawurlencode($publicToken);
        $subject = $isCritical
            ? '[VOXEL PACS] ACHADO CRÍTICO — ' . ($patientName !== '' ? $patientName : 'Paciente') . ' — ' . ($studyDescription !== '' ? $studyDescription : $assunto)
            : '[VOXEL PACS] Pendência no laudo — ' . $assunto;
        $intro = $isCritical
            ? '<p><strong style="color:#b91c1c">ACHADO CRÍTICO COMUNICADO PELO MÉDICO</strong></p>'
            : '<p>Uma nova interação foi registrada no CHAT do laudo.</p>';
        $body = $intro
            . '<p><strong>Assunto:</strong> ' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Mensagem:</strong><br>' . nl2br(htmlspecialchars($corpo, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Abrir o laudo no VOXEL PACS</a></p>';

        foreach ($unique as $recipient) {
            $result['recipient_ids'][] = (int) ($recipient['id'] ?? 0);
            if (Mailer::send((string) $recipient['email'], $subject, $body)) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }
        $result['recipient_ids'] = array_values(array_unique(array_filter($result['recipient_ids'])));
        return $result;
    }
}
