<?php
namespace App\Core;
abstract class Controller {
    protected function view(string $view, array $data = [], string $layout = 'pacs'): void {
        View::render($view, $data, $layout);
    }
    protected function json(mixed $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    protected function redirect(string $url): void {
        header("Location: {$url}");
        exit;
    }
    protected function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
