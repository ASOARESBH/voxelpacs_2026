<?php

namespace App\Services;

use App\Core\Logger;
use App\Models\ConectorConfig;
use App\Models\ConectorLog;

class TelegramService
{
    private ConectorConfig $configModel;
    private ConectorLog $logModel;

    public function __construct(?ConectorConfig $configModel = null, ?ConectorLog $logModel = null)
    {
        $this->configModel = $configModel ?? new ConectorConfig();
        $this->logModel = $logModel ?? new ConectorLog();
    }

    /** @return array<string, mixed>|null */
    public function config(): ?array
    {
        return $this->configModel->findByTipo('telegram');
    }

    public function isConfigured(): bool
    {
        $config = $this->config();
        return !empty($config['ativo'])
            && trim((string) $this->configModel->segredoDecriptado($config['telegram_bot_token'] ?? null)) !== ''
            && $this->validarChatId((string) ($config['telegram_chat_id'] ?? '')) !== null;
    }

    /** @return array{ok:bool,http_code:int,response:string,error:string|null} */
    public function enviarTexto(string $chatId, string $mensagem, string $evento, string $parseMode = 'HTML'): array
    {
        $config = $this->config();
        $destino = $this->validarChatId($chatId);
        if (!$this->isConfigured() || $config === null || $destino === null) {
            return $this->registrarFalha($evento, (string) $chatId, $mensagem, 'Telegram não configurado ou Chat ID inválido.');
        }

        $token = (string) $this->configModel->segredoDecriptado($config['telegram_bot_token'] ?? null);
        $result = ConectorHttpClient::request(
            'POST',
            'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage',
            [],
            [
                'chat_id' => $destino,
                'text' => $mensagem,
                'parse_mode' => $parseMode === 'HTML' ? 'HTML' : 'HTML',
                'disable_web_page_preview' => true,
            ]
        );
        $ok = $result['ok'] && (($result['json']['ok'] ?? false) === true);
        $result['ok'] = $ok;
        if (!$ok && $result['error'] === null) {
            $result['error'] = (string) ($result['json']['description'] ?? 'Falha ao enviar mensagem ao Telegram.');
        }
        return $this->registrarResultado($evento, $destino, $mensagem, $result);
    }

    /** @return array{ok:bool,http_code:int,response:string,error:string|null,username?:string} */
    public function testarConexao(): array
    {
        $config = $this->config();
        $token = $config ? (string) $this->configModel->segredoDecriptado($config['telegram_bot_token'] ?? null) : '';
        if ($token === '') {
            $this->configModel->updateTeste('telegram', false, 'Informe o Bot Token antes de testar.');
            return ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => 'Informe o Bot Token antes de testar.'];
        }

        $result = ConectorHttpClient::request('GET', 'https://api.telegram.org/bot' . rawurlencode($token) . '/getMe');
        $ok = $result['ok'] && (($result['json']['ok'] ?? false) === true);
        $username = (string) ($result['json']['result']['username'] ?? '');
        $mensagem = $ok
            ? ('Bot validado: @' . ($username !== '' ? $username : 'sem_username'))
            : (string) ($result['json']['description'] ?? $result['error'] ?? 'Falha ao validar token do Telegram.');
        $this->configModel->updateTeste('telegram', $ok, $mensagem);
        $this->logModel->create([
            'conector_tipo' => 'telegram',
            'evento' => 'teste',
            'destino' => $config['telegram_chat_id'] ?? null,
            'mensagem' => 'Teste de conexão Telegram Bot API.',
            'payload' => ['operation' => 'getMe'],
            'status' => $ok ? 'enviado' : 'erro',
            'resposta' => $result['response'] ?: $mensagem,
            'http_code' => $result['http_code'],
        ]);
        return [
            'ok' => $ok,
            'http_code' => $result['http_code'],
            'response' => $result['response'],
            'error' => $ok ? null : $mensagem,
            'username' => $ok ? $username : null,
        ];
    }

    private function validarChatId(string $chatId): ?string
    {
        $chatId = trim($chatId);
        return preg_match('/^-?\d{1,20}$/', $chatId) ? $chatId : null;
    }

    /** @param array{ok:bool,http_code:int,response:string,error:string|null} $result */
    private function registrarResultado(string $evento, string $destino, string $mensagem, array $result): array
    {
        try {
            $this->logModel->create([
                'conector_tipo' => 'telegram',
                'evento' => $evento,
                'destino' => $destino,
                'mensagem' => $mensagem,
                'payload' => ['chat_id' => $destino, 'message_length' => mb_strlen($mensagem)],
                'status' => $result['ok'] ? 'enviado' : 'erro',
                'resposta' => $result['response'] ?: ($result['error'] ?? null),
                'http_code' => $result['http_code'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[TelegramService] Falha ao registrar log de envio', ['error' => $e->getMessage()]);
        }
        return $result;
    }

    /** @return array{ok:bool,http_code:int,response:string,error:string} */
    private function registrarFalha(string $evento, string $destino, string $mensagem, string $erro): array
    {
        return $this->registrarResultado($evento, $destino, $mensagem, [
            'ok' => false, 'http_code' => 0, 'response' => '', 'error' => $erro,
        ]);
    }
}
