<?php
// Materialização controlada do runtime de notificações.

namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\PlatformNotificationService;
use DomainException;
use Throwable;

/** Gestão exclusiva de comunicados de plataforma; alertas de grupo permanecem em /usuarios/notificacoes. */
final class NotificacoesController extends Controller
{
    private PlatformNotificationService $service;

    public function __construct() { $this->service = new PlatformNotificationService(); }

    public function index(): void
    {
        if (!$this->authorize()) return;
        $this->view('platform/notificacoes/index', ['title' => 'Notificações da Plataforma'] + $this->service->platformPageData(), 'platform');
    }

    public function create(): void
    {
        if (!$this->authorize()) return;
        $this->view('platform/notificacoes/form', $this->formData(null), 'platform');
    }

    public function edit(int $id): void
    {
        if (!$this->authorize()) return;
        $notification = $this->service->repository()->find($id);
        if (!$notification || ($notification['origem'] ?? '') !== 'comunicado') { $_SESSION['error'] = 'Notificação não encontrada.'; $this->redirect('/platform/notificacoes'); return; }
        $this->view('platform/notificacoes/form', $this->formData($notification), 'platform');
    }

    public function save(): void { $this->saveInternal(null); }
    public function update(int $id): void { $this->saveInternal($id); }

    public function pause(int $id): void
    {
        if (!$this->authorize() || !$this->validCsrf()) return;
        try { $this->service->pause($id, false); $_SESSION['success'] = 'Notificação pausada.'; }
        catch (Throwable $e) { Logger::warning('[NotificacoesController::pause] ação recusada', ['notification_id' => $id]); $_SESSION['error'] = 'Não foi possível pausar a notificação.'; }
        $this->redirect('/platform/notificacoes');
    }

    public function resume(int $id): void
    {
        if (!$this->authorize() || !$this->validCsrf()) return;
        try { $this->service->pause($id, true); $_SESSION['success'] = 'Notificação reativada.'; }
        catch (Throwable $e) { Logger::warning('[NotificacoesController::resume] ação recusada', ['notification_id' => $id]); $_SESSION['error'] = 'Não foi possível reativar a notificação.'; }
        $this->redirect('/platform/notificacoes');
    }

    public function archive(int $id): void
    {
        if (!$this->authorize() || !$this->validCsrf()) return;
        try { $this->service->archive($id); $_SESSION['success'] = 'Notificação arquivada.'; }
        catch (Throwable $e) { Logger::warning('[NotificacoesController::archive] ação recusada', ['notification_id' => $id]); $_SESSION['error'] = 'Não foi possível arquivar a notificação.'; }
        $this->redirect('/platform/notificacoes');
    }

    public function previewAudience(): void
    {
        if (!$this->authorize(true) || !$this->validCsrf(true)) return;
        try { $this->json(['ok' => true, 'total' => $this->service->audiencePreview($_POST)]); }
        catch (DomainException $e) { $this->json(['ok' => false, 'message' => $e->getMessage()], 422); }
        catch (Throwable $e) { Logger::warning('[NotificacoesController::previewAudience] falhou'); $this->json(['ok' => false, 'message' => 'Não foi possível estimar o alcance.'], 500); }
    }

    public function stats(int $id): void
    {
        if (!$this->authorize()) return;
        $item = $this->service->repository()->find($id);
        if (!$item) { $_SESSION['error'] = 'Notificação não encontrada.'; $this->redirect('/platform/notificacoes'); return; }
        $pdo = $this->service->repository()->pdo();
        $summary = $pdo->prepare("SELECT COUNT(DISTINCT s.user_id) vistos, COUNT(DISTINCT CASE WHEN s.confirmado_em IS NOT NULL THEN s.user_id END) confirmados, COUNT(DISTINCT CASE WHEN e.status = 'enviado' THEN e.user_id END) emails_enviados, COUNT(DISTINCT CASE WHEN e.status = 'falha' THEN e.user_id END) emails_falhos FROM bi_platform_notificacoes n LEFT JOIN bi_platform_notificacao_status_usuario s ON s.notificacao_id = n.id LEFT JOIN bi_platform_notificacao_envios e ON e.notificacao_id = n.id WHERE n.id = ? GROUP BY n.id");
        $summary->execute([$id]);
        $this->view('platform/notificacoes/stats', ['title' => 'Estatísticas da notificação', 'notification' => $item, 'summary' => $summary->fetch(\PDO::FETCH_ASSOC) ?: []], 'platform');
    }

    private function saveInternal(?int $id): void
    {
        if (!$this->authorize() || !$this->validCsrf()) return;
        try {
            $isPublish = (string) ($_POST['submit_action'] ?? '') === 'publicar';
            $saved = $this->service->save($_POST, (int) Auth::userId(), $id, $isPublish);
            $_SESSION['success'] = $isPublish ? 'Notificação publicada. Os e-mails, quando habilitados, serão processados em fila.' : 'Rascunho salvo.';
            $this->redirect('/platform/notificacoes/' . $saved . '/editar');
        } catch (DomainException $e) { $_SESSION['error'] = $e->getMessage(); $this->redirect($id ? '/platform/notificacoes/' . $id . '/editar' : '/platform/notificacoes/nova'); }
        catch (Throwable $e) { Logger::error('[NotificacoesController::save] falhou', ['notification_id' => $id]); $_SESSION['error'] = 'Não foi possível salvar a notificação.'; $this->redirect('/platform/notificacoes'); }
    }

    private function formData(?array $notification): array
    {
        $page = $this->service->platformPageData();
        return ['title' => $notification ? 'Editar notificação' : 'Nova notificação', 'notification' => $notification, 'selectedTenants' => $notification ? $this->service->repository()->targetTenants((int) $notification['id']) : [], 'selectedProfiles' => $notification ? $this->service->repository()->targetProfiles((int) $notification['id']) : [], 'csrfToken' => $page['csrfToken'], 'tenants' => $page['tenants'], 'profiles' => $page['profiles'], 'types' => $page['types'], 'includeQuill' => true];
    }

    private function authorize(bool $json = false): bool
    {
        if (Auth::check() && Auth::isPlatformAdmin() && !Auth::isImpersonating()) return true;
        if ($json) { $this->json(['ok' => false, 'message' => 'Sem permissão.'], 403); return false; }
        $this->redirect('/login'); return false;
    }
    private function validCsrf(bool $json = false): bool
    {
        $valid = hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_csrf_token'] ?? ''));
        if ($valid) return true;
        if ($json) { $this->json(['ok' => false, 'message' => 'Sessão expirada.'], 419); return false; }
        $_SESSION['error'] = 'Sessão expirada. Atualize a página e tente novamente.'; $this->redirect('/platform/notificacoes'); return false;
    }
}
