<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Logger;
use App\Repositories\PlatformNotificationRepository;
use DomainException;

/** Serviço de comunicados da plataforma; não substitui políticas de alerta por grupo. */
final class PlatformNotificationService
{
    public const PROFILES = ['admin', 'medico', 'secretaria', 'analista', 'viewer'];
    public const TYPES = ['informacao', 'aviso', 'sucesso', 'critico', 'manutencao', 'nova_funcionalidade'];
    public const DISPLAY = ['unica_vez', 'durante_periodo'];
    private PlatformNotificationRepository $repo;

    public function __construct(?PlatformNotificationRepository $repo = null) { $this->repo = $repo ?? new PlatformNotificationRepository(); }
    public function repository(): PlatformNotificationRepository { return $this->repo; }

    public function platformPageData(): array
    {
        return ['notifications' => $this->repo->listForPlatform(), 'tenants' => $this->repo->listActiveTenants(), 'profiles' => self::PROFILES, 'types' => self::TYPES, 'csrfToken' => $this->csrfToken()];
    }

    public function save(array $input, int $authorId, ?int $notificationId, bool $publish): int
    {
        $data = $this->normalizeInput($input, $publish);
        $before = $notificationId ? $this->repo->find($notificationId) : null;
        if ($notificationId && !$before) throw new DomainException('Notificação não encontrada.');
        $pdo = $this->repo->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->repo->save($data, $notificationId, $authorId);
            $this->repo->replaceSegments($id, $data['tenant_ids'], $data['profiles']);
            $recipients = $data['status'] === 'rascunho' ? [] : $this->repo->eligibleUsers(['id' => $id, 'escopo' => $data['escopo']]);
            if ($data['canal_email'] && $data['status'] !== 'rascunho') $this->repo->enqueueEmail($id, $recipients);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        AuditLogger::log($before ? 'plataforma.notificacao_atualizada' : 'plataforma.notificacao_criada', 'bi_platform_notificacoes', $id, ['status' => $data['status'], 'escopo' => $data['escopo'], 'perfis' => $data['profiles'], 'tenants_count' => count($data['tenant_ids']), 'canal_email' => $data['canal_email']], null, 'notificacoes');
        return $id;
    }

    public function pause(int $notificationId, bool $active): void
    {
        $notification = $this->repo->find($notificationId);
        if (!$notification) throw new DomainException('Notificação não encontrada.');
        $stmt = $this->repo->pdo()->prepare('UPDATE bi_platform_notificacoes SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$active ? 'ativa' : 'pausada', $notificationId]);
        AuditLogger::log('plataforma.notificacao_' . ($active ? 'reativada' : 'pausada'), 'bi_platform_notificacoes', $notificationId, ['status_anterior' => $notification['status']], null, 'notificacoes');
    }

    public function archive(int $notificationId): void
    {
        $notification = $this->repo->find($notificationId);
        if (!$notification) throw new DomainException('Notificação não encontrada.');
        $this->repo->pdo()->prepare("UPDATE bi_platform_notificacoes SET status = 'arquivada', updated_at = NOW() WHERE id = ?")->execute([$notificationId]);
        AuditLogger::log('plataforma.notificacao_arquivada', 'bi_platform_notificacoes', $notificationId, ['status_anterior' => $notification['status']], null, 'notificacoes');
    }

    public function audiencePreview(array $input): int
    {
        $data = $this->normalizeInput($input, false);
        // Preview usa a mesma segmentação, sem estado visto; para preservar uma única fonte de verdade,
        // estima pela seleção de usuários de tenant/perfil e não grava nada.
        $sql = "SELECT COUNT(*) FROM (SELECT DISTINCT u.id, ut.tenant_id FROM bi_users u JOIN bi_user_tenants ut ON ut.user_id = u.id JOIN bi_tenants t ON t.id = ut.tenant_id WHERE u.status = 'ativo' AND u.role <> 'superadmin' AND ut.ativo = 1 AND t.status = 'ativo'";
        if ($data['escopo'] === 'tenant') $sql .= ' AND ut.tenant_id IN (' . implode(',', array_fill(0, count($data['tenant_ids']), '?')) . ')';
        if ($data['profiles']) $sql .= ' AND ut.perfil IN (' . implode(',', array_fill(0, count($data['profiles']), '?')) . ')';
        $sql .= ') audience';
        $stmt = $this->repo->pdo()->prepare($sql);
        $stmt->execute(array_merge($data['escopo'] === 'tenant' ? $data['tenant_ids'] : [], $data['profiles']));
        return (int) $stmt->fetchColumn();
    }

    public function userPayload(): array
    {
        $userId = (int) Auth::userId();
        $tenantId = (int) Auth::tenantId();
        $profile = (string) Auth::perfilAtual();
        if ($userId <= 0 || $tenantId <= 0 || $profile === '' || Auth::isPlatformAdmin()) return ['items' => [], 'modal' => null, 'unread_count' => 0, 'csrf_token' => $this->csrfToken()];
        $items = $this->repo->listForUser($userId, $tenantId, $profile);
        $unseen = array_values(array_filter($items, static fn(array $item): bool => empty($item['visto_em'])));
        return ['items' => $items, 'modal' => $unseen[0] ?? null, 'unread_count' => count($unseen), 'csrf_token' => $this->csrfToken()];
    }

    public function acknowledgeForCurrentUser(int $notificationId, bool $confirm): bool
    {
        $userId = (int) Auth::userId(); $tenantId = (int) Auth::tenantId(); $profile = (string) Auth::perfilAtual();
        if ($userId <= 0 || $tenantId <= 0 || $profile === '' || Auth::isPlatformAdmin()) return false;
        $item = $this->repo->find($notificationId);
        if (!$item || !$this->repo->isEligibleForUser($notificationId, $userId, $tenantId, $profile)) return false;
        if ($confirm && empty($item['requer_confirmacao'])) return false;
        $this->repo->markViewed($notificationId, $userId, $tenantId, $confirm);
        return true;
    }

    /** Emite sinal in-app sem PHI para destinatários já autorizados pelo evento operacional. */
    public function emitOperationalEvent(string $kind, int $tenantId, array $recipientIds, int $actorId, int $eventId): void
    {
        $definitions = [
            'chat_pendente' => ['nome_alerta' => 'Nova pendência operacional', 'assunto' => 'Você possui uma interação pendente', 'mensagem_html' => '<p>Há uma nova interação operacional que exige sua atenção no VOXEL PACS.</p>', 'tipo' => 'aviso', 'requer_confirmacao' => false],
            'achado_critico' => ['nome_alerta' => 'Achado crítico comunicado', 'assunto' => 'Há uma comunicação crítica que exige sua atenção', 'mensagem_html' => '<p>Há uma comunicação crítica registrada no VOXEL PACS. Acesse o fluxo autorizado para avaliação.</p>', 'tipo' => 'critico', 'requer_confirmacao' => true],
        ];
        if (!isset($definitions[$kind]) || $tenantId <= 0 || $eventId <= 0) return;
        $data = $definitions[$kind] + ['chave_evento' => "{$kind}:{$tenantId}:{$eventId}", 'criado_por' => $actorId];
        try {
            $this->repo->createSystemEvent($data, $recipientIds, $tenantId);
        } catch (\Throwable $e) {
            Logger::warning('[PlatformNotificationService] evento in-app não persistido', ['kind' => $kind, 'tenant_id' => $tenantId, 'event_id' => $eventId]);
        }
    }

    private function normalizeInput(array $input, bool $publish): array
    {
        $name = trim((string) ($input['nome_alerta'] ?? ''));
        $subject = trim((string) ($input['assunto'] ?? ''));
        $html = ReportClinicalHtmlSanitizer::sanitize((string) ($input['mensagem_html'] ?? ''));
        $type = (string) ($input['tipo'] ?? 'informacao');
        $scope = (string) ($input['escopo'] ?? 'global');
        $display = (string) ($input['exibicao'] ?? 'unica_vez');
        $start = trim((string) ($input['data_inicio'] ?? ''));
        $end = trim((string) ($input['data_fim'] ?? ''));
        $profiles = array_values(array_intersect(self::PROFILES, array_map('strval', (array) ($input['perfis'] ?? []))));
        $tenantIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['tenant_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($name === '' || mb_strlen($name) > 120 || $subject === '' || mb_strlen($subject) > 255 || trim(strip_tags($html)) === '') throw new DomainException('Preencha nome, assunto e mensagem válida.');
        if (!in_array($type, self::TYPES, true) || !in_array($scope, ['global','tenant'], true) || !in_array($display, self::DISPLAY, true)) throw new DomainException('Configuração de notificação inválida.');
        if ($scope === 'tenant' && !$tenantIds) throw new DomainException('Selecione ao menos um negócio ativo.');
        $startAt = strtotime($start); $endAt = strtotime($end);
        if (!$startAt || !$endAt || $endAt <= $startAt) throw new DomainException('Informe um período válido com data de término posterior ao início.');
        $status = $publish ? ($startAt > time() ? 'agendada' : 'ativa') : 'rascunho';
        return ['nome_alerta' => $name, 'assunto' => $subject, 'mensagem_html' => $html, 'tipo' => $type, 'escopo' => $scope, 'exibicao' => $display, 'requer_confirmacao' => !empty($input['requer_confirmacao']), 'canal_email' => !empty($input['canal_email']), 'status' => $status, 'origem' => 'comunicado', 'data_inicio' => date('Y-m-d H:i:s', $startAt), 'data_fim' => date('Y-m-d H:i:s', $endAt), 'tenant_ids' => $tenantIds, 'profiles' => $profiles];
    }

    private function csrfToken(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return (string) $_SESSION['csrf_token']; }
}
