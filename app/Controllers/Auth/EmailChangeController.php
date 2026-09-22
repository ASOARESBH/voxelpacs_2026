<?php
namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Services\UserEmailChangeService;

/** Confirmação pública de troca de e-mail; GET apenas exibe, POST promove. */
final class EmailChangeController extends Controller
{
    public function show(string $token): void
    {
        $result = (new UserEmailChangeService())->inspect($this->cleanToken($token));
        $this->render($result['ok'] ? 'ready' : (string) ($result['error'] ?? 'invalid_token'));
    }

    public function confirm(string $token): void
    {
        if (!$this->validCsrf()) {
            $this->render('csrf_error');
            return;
        }

        $result = (new UserEmailChangeService())->confirm($this->cleanToken($token));
        if (!empty($result['ok'])) {
            $this->render(!empty($result['notice_sent']) ? 'confirmed' : 'confirmed_notice_failed');
            return;
        }

        $this->render((string) ($result['error'] ?? 'internal'));
    }

    private function render(string $state): void
    {
        $this->view('auth/confirmar_email', [
            'title' => 'VOXEL PACS — Confirmar e-mail',
            'state' => $state,
            'csrf_token' => $this->csrfToken(),
        ], 'auth');
    }

    private function validCsrf(): bool
    {
        $csrf = (string) ($_POST['_csrf_token'] ?? '');
        return $csrf !== '' && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $csrf);
    }

    private function cleanToken(string $token): string
    {
        return preg_replace('/[^a-fA-F0-9]/', '', $token) ?? '';
    }
}
