<?php
// Materialização controlada do runtime de notificações.

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\PlatformNotificationService;

/** API do sino: nunca aceita user_id ou tenant_id do navegador. */
final class NotificationCenterController extends Controller
{
    private PlatformNotificationService $service;
    public function __construct() { $this->service = new PlatformNotificationService(); }
    public function list(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'message' => 'Sessão expirada.'], 401); return; }
        $this->json(['ok' => true] + $this->service->userPayload());
    }
    public function viewed(int $id): void { $this->acknowledge($id, false); }
    public function confirm(int $id): void { $this->acknowledge($id, true); }
    private function acknowledge(int $id, bool $confirm): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'message' => 'Sessão expirada.'], 401); return; }
        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_csrf_token'] ?? ''))) { $this->json(['ok' => false, 'message' => 'Sessão expirada.'], 419); return; }
        if (!$this->service->acknowledgeForCurrentUser($id, $confirm)) { $this->json(['ok' => false, 'message' => 'Notificação indisponível.'], 404); return; }
        $this->json(['ok' => true]);
    }
}
