<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Repositories\ReportChatRepository;

/**
 * Regras de negócio do CHAT contextual do Report.
 * Destinatários e estados sempre são resolvidos dentro do tenant atual.
 */
class ReportChatService
{
    public const GROUP_ADMINISTRATIVO = 'administrativo';

    private const GROUP_PROFILES = [
        self::GROUP_ADMINISTRATIVO => ['admin', 'secretaria', 'analista'],
    ];

    private const SUBJECTS = [
        'erro_pedido' => 'Erro no pedido',
        'contraste' => 'Contraste',
        'exames_complementares' => 'Exames complementares',
        'duvida_administrativa' => 'Dúvida administrativa',
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

        $chat = $this->repo->findByReport($reportId, $tenantId);
        $messages = $chat ? $this->repo->listMessages((int) $chat['id'], $tenantId) : [];

        return [
            'report_id' => $reportId,
            'estudo_id' => (int) $report['estudo_id'],
            'status' => $chat['status'] ?? 'sem_chat',
            'pendente' => ($chat['status'] ?? '') === 'pendente',
            'destinatario_tipo' => $chat['destinatario_tipo'] ?? 'grupo',
            'destinatario_grupo' => $chat['destinatario_grupo'] ?? self::GROUP_ADMINISTRATIVO,
            'destinatario_user_id' => isset($chat['destinatario_user_id']) ? (int) $chat['destinatario_user_id'] : null,
            'assunto_codigo' => $chat['assunto_codigo'] ?? 'outro',
            'assunto' => $chat['assunto'] ?? '',
            'situacao_anterior' => $chat['situacao_anterior'] ?? null,
            'criado_em' => $chat['criado_em'] ?? null,
            'atualizado_em' => $chat['atualizado_em'] ?? null,
            'concluido_em' => $chat['concluido_em'] ?? null,
            'messages' => $messages,
            'subjects' => $this->subjects(),
            'groups' => [[
                'codigo' => self::GROUP_ADMINISTRATIVO,
                'label' => 'Administrativo',
                'perfis' => self::GROUP_PROFILES[self::GROUP_ADMINISTRATIVO],
            ]],
            'users' => $this->repo->listActiveUsers($tenantId, $currentUserId),
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

        $corpo = trim((string) ($input['mensagem'] ?? ''));
        if ($corpo === '' || mb_strlen($corpo, 'UTF-8') < 2) {
            return ['ok' => false, 'error' => 'mensagem_obrigatoria'];
        }
        if (mb_strlen($corpo, 'UTF-8') > 5000) {
            return ['ok' => false, 'error' => 'mensagem_muito_longa'];
        }

        $tipo = (string) ($input['destinatario_tipo'] ?? 'grupo');
        if (!in_array($tipo, ['grupo', 'usuario'], true)) $tipo = 'grupo';

        $grupo = null;
        $destinatarioUserId = null;
        if ($tipo === 'grupo') {
            $grupo = (string) ($input['destinatario_grupo'] ?? self::GROUP_ADMINISTRATIVO);
            if (!isset(self::GROUP_PROFILES[$grupo])) {
                return ['ok' => false, 'error' => 'destinatario_invalido'];
            }
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

        $situacaoAtual = (string) ($context['situacao'] ?? 'em_laudo');
        if (in_array($situacaoAtual, ['assinado', 'liberado'], true)) {
            return ['ok' => false, 'error' => 'estudo_finalizado'];
        }

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $chat = $this->repo->findByReport($reportId, $tenantId);
            $situacaoAnterior = (string) ($chat['situacao_anterior'] ?? '');
            if (!$chat || ($chat['status'] ?? '') === 'concluido' || $situacaoAnterior === '') {
                $situacaoAnterior = $situacaoAtual === 'pendente' ? 'em_laudo' : $situacaoAtual;
            }
            $chatId = $this->repo->upsertPending(
                $reportId,
                (int) $context['estudo_id'],
                $tenantId,
                $tipo,
                $grupo,
                $destinatarioUserId,
                $assuntoCodigo,
                $assunto,
                $situacaoAnterior,
                $userId
            );
            $messageId = $this->repo->addMessage($chatId, $tenantId, $userId, $corpo);
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

        $this->notifyRecipients(
            $reportId,
            $tenantId,
            $userId,
            $tipo,
            $grupo,
            $destinatarioUserId,
            $assunto,
            $corpo,
            (string) ($context['study_instance_uid'] ?? '')
        );

        Logger::info('[ReportChatService::send] interação registrada', [
            'report_id' => $reportId, 'chat_id' => $chatId, 'message_id' => $messageId,
            'tenant_id' => $tenantId, 'user_id' => $userId, 'destinatario_tipo' => $tipo,
            'destinatario_grupo' => $grupo, 'destinatario_user_id' => $destinatarioUserId,
        ]);

        return ['ok' => true, 'chat_id' => $chatId, 'message_id' => $messageId, 'status' => 'pendente'];
    }

    public function complete(int $reportId, int $tenantId, int $userId): array
    {
        $context = $this->repo->findReportContext($reportId, $tenantId);
        if (!$context) return ['ok' => false, 'error' => 'report_nao_encontrado'];
        $chat = $this->repo->findByReport($reportId, $tenantId);
        if (!$chat || $chat['status'] !== 'pendente') {
            return ['ok' => false, 'error' => 'chat_sem_pendencia'];
        }

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $closed = $this->repo->complete((int) $chat['id'], $reportId, $tenantId, $userId);
            if (!$closed) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'error' => 'chat_sem_pendencia'];
            }
            $restore = $this->normalizarSituacaoRestaurada((string) ($chat['situacao_anterior'] ?? ''));
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
        return ['ok' => true, 'status' => 'concluido', 'situacao' => $restore];
    }

    private function normalizarSituacaoRestaurada(string $situacao): string
    {
        $permitidas = ['novo', 'aberto', 'a_laudar', 'em_laudo', 'rascunho', 'revisao', 'urgente'];
        return in_array($situacao, $permitidas, true) ? $situacao : 'em_laudo';
    }

    private function notifyRecipients(
        int $reportId,
        int $tenantId,
        int $authorId,
        string $tipo,
        ?string $grupo,
        ?int $destinatarioUserId,
        string $assunto,
        string $corpo,
        string $studyUid
    ): void {
        $recipients = $tipo === 'usuario'
            ? array_filter([$this->repo->findActiveUser((int) $destinatarioUserId, $tenantId)])
            : $this->repo->listUsersByProfiles($tenantId, self::GROUP_PROFILES[$grupo ?? self::GROUP_ADMINISTRATIVO]);

        $baseUrl = rtrim((string) (getenv('APP_URL') ?: 'https://server.voxelpacs.com.br'), '/');
        $url = $baseUrl . '/reports/' . rawurlencode($studyUid);
        $subject = '[VOXEL PACS] Pendência no laudo — ' . $assunto;
        $body = '<p>Uma nova interação foi registrada no CHAT do laudo.</p>'
            . '<p><strong>Assunto:</strong> ' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Mensagem:</strong><br>' . nl2br(htmlspecialchars($corpo, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Abrir o laudo no VOXEL PACS</a></p>';

        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') continue;
            Mailer::send($email, $subject, $body);
        }
    }
}
