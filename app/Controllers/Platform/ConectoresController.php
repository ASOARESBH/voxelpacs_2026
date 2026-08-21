<?php

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Models\ConectorConfig;
use App\Models\ConectorLog;
use App\Services\ConectorHttpClient;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use DomainException;
use Throwable;

/** Administração global de conectores de comunicação, exclusiva de superadmin. */
class ConectoresController extends Controller
{
    private ConectorConfig $configs;
    private ConectorLog $logs;

    public function __construct()
    {
        $this->configs = new ConectorConfig();
        $this->logs = new ConectorLog();
    }

    public function index(): void
    {
        if (!$this->requirePlatformAdmin()) return;
        $whatsapp = $this->configs->paraView('whatsapp');
        $telegram = $this->configs->paraView('telegram');
        $this->view('platform/conectores/index', [
            'whatsapp' => $whatsapp,
            'telegram' => $telegram,
            'contagens' => $this->logs->contagensRecentes(),
        ], 'platform');
    }

    public function whatsapp(): void
    {
        if (!$this->requirePlatformAdmin()) return;
        $this->view('platform/conectores/whatsapp', [
            'config' => $this->configs->paraView('whatsapp'),
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function salvarWhatsapp(): void
    {
        if (!$this->requirePlatformAdmin() || !$this->validCsrf()) return;
        try {
            $url = ConectorHttpClient::validateUrl((string) ($_POST['evolution_api_url'] ?? ''));
            $instance = trim((string) ($_POST['evolution_instance'] ?? ''));
            $numero = preg_replace('/\D+/', '', (string) ($_POST['whatsapp_destino'] ?? '')) ?? '';
            if ($url === null) throw new DomainException('Informe uma URL HTTP ou HTTPS válida para a Evolution API.');
            if ($instance === '' || !preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $instance)) throw new DomainException('Informe uma instância Evolution API válida.');
            if ($numero !== '' && (strlen($numero) < 10 || strlen($numero) > 20)) throw new DomainException('Informe o número com DDI, DDD e número.');

            $data = [
                'ativo' => !empty($_POST['ativo']),
                'evolution_api_url' => $url,
                'evolution_instance' => $instance,
                'whatsapp_destino' => $numero,
                'evolution_api_key' => (string) ($_POST['evolution_api_key'] ?? ''),
            ];
            $this->configs->update('whatsapp', $data);
            AuditLogger::log('conectores.whatsapp_salvo', 'bi_conectores_config', 0, ['ativo' => $data['ativo'], 'instance' => $instance]);
            $_SESSION['success'] = 'Configuração do WhatsApp salva. A API Key existente é preservada quando o campo permanece em branco.';
        } catch (DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('[ConectoresController::salvarWhatsapp] Falha', ['error' => $e->getMessage()]);
            $_SESSION['error'] = 'Não foi possível salvar o conector WhatsApp.';
        }
        $this->redirect('/platform/conectores/whatsapp');
    }

    public function testarWhatsapp(): void
    {
        if (!$this->requirePlatformAdmin(true) || !$this->validCsrf(true)) return;
        try {
            $result = (new WhatsAppService())->testarConexao();
            $this->json(['ok' => $result['ok'], 'message' => $result['ok'] ? 'Conexão WhatsApp validada.' : ($result['error'] ?? 'Falha no teste.'), 'http_code' => $result['http_code']]);
        } catch (Throwable $e) {
            Logger::error('[ConectoresController::testarWhatsapp] Falha', ['error' => $e->getMessage()]);
            $this->json(['ok' => false, 'message' => 'Falha inesperada ao testar o WhatsApp.'], 500);
        }
    }

    public function telegram(): void
    {
        if (!$this->requirePlatformAdmin()) return;
        $this->view('platform/conectores/telegram', [
            'config' => $this->configs->paraView('telegram'),
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function salvarTelegram(): void
    {
        if (!$this->requirePlatformAdmin() || !$this->validCsrf()) return;
        try {
            $chatId = trim((string) ($_POST['telegram_chat_id'] ?? ''));
            if ($chatId !== '' && !preg_match('/^-?\d{1,20}$/', $chatId)) throw new DomainException('Informe um Chat ID numérico válido. Grupos podem começar com sinal negativo.');
            $this->configs->update('telegram', [
                'ativo' => !empty($_POST['ativo']),
                'telegram_chat_id' => $chatId,
                'telegram_bot_token' => (string) ($_POST['telegram_bot_token'] ?? ''),
            ]);
            AuditLogger::log('conectores.telegram_salvo', 'bi_conectores_config', 0, ['ativo' => !empty($_POST['ativo'])]);
            $_SESSION['success'] = 'Configuração do Telegram salva. O Bot Token existente é preservado quando o campo permanece em branco.';
        } catch (DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('[ConectoresController::salvarTelegram] Falha', ['error' => $e->getMessage()]);
            $_SESSION['error'] = 'Não foi possível salvar o conector Telegram.';
        }
        $this->redirect('/platform/conectores/telegram');
    }

    public function testarTelegram(): void
    {
        if (!$this->requirePlatformAdmin(true) || !$this->validCsrf(true)) return;
        try {
            $result = (new TelegramService())->testarConexao();
            $this->json(['ok' => $result['ok'], 'message' => $result['ok'] ? ('Bot validado: @' . ($result['username'] ?? '')) : ($result['error'] ?? 'Falha no teste.'), 'http_code' => $result['http_code']]);
        } catch (Throwable $e) {
            Logger::error('[ConectoresController::testarTelegram] Falha', ['error' => $e->getMessage()]);
            $this->json(['ok' => false, 'message' => 'Falha inesperada ao testar o Telegram.'], 500);
        }
    }

    public function logs(): void
    {
        if (!$this->requirePlatformAdmin()) return;
        $tipo = (string) ($_GET['tipo'] ?? '');
        $this->view('platform/conectores/logs', [
            'tipo' => in_array($tipo, ['whatsapp', 'telegram'], true) ? $tipo : '',
            'logs' => $this->logs->recentes($tipo, 100),
        ], 'platform');
    }

    private function requirePlatformAdmin(bool $json = false): bool
    {
        if (Auth::check() && Auth::isPlatformAdmin()) return true;
        if ($json) $this->json(['ok' => false, 'message' => 'Sem permissão.'], 403);
        $this->redirect('/login');
        return false;
    }

    private function validCsrf(bool $json = false): bool
    {
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) return true;
        if ($json) $this->json(['ok' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        $_SESSION['error'] = 'Sessão expirada. Atualize a página e tente novamente.';
        $this->redirect('/platform/conectores');
        return false;
    }
}
