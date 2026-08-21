<?php

namespace App\Services;

use App\Core\Logger;
use App\Models\ConectorConfig;
use App\Models\ConectorLog;

class WhatsAppService
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
        return $this->configModel->findByTipo('whatsapp');
    }

    public function isConfigured(): bool
    {
        $config = $this->config();
        return !empty($config['ativo'])
            && ConectorHttpClient::validateUrl((string) ($config['evolution_api_url'] ?? '')) !== null
            && trim((string) $this->configModel->segredoDecriptado($config['evolution_api_key'] ?? null)) !== ''
            && trim((string) ($config['evolution_instance'] ?? '')) !== ''
            && $this->sanitizarNumero((string) ($config['whatsapp_destino'] ?? '')) !== '';
    }

    /** @return array{ok:bool,http_code:int,response:string,error:string|null} */
    public function enviarTexto(string $numero, string $mensagem, string $evento): array
    {
        $config = $this->config();
        $destino = $this->sanitizarNumero($numero);
        if (!$this->isConfigured() || $config === null || $destino === '') {
            return $this->registrarFalha($evento, $destino, $mensagem, 'WhatsApp não configurado ou destino inválido.');
        }

        $baseUrl = rtrim((string) $config['evolution_api_url'], '/');
        $instance = rawurlencode(trim((string) $config['evolution_instance']));
        $apiKey = (string) $this->configModel->segredoDecriptado($config['evolution_api_key'] ?? null);
        $result = ConectorHttpClient::request(
            'POST',
            $baseUrl . '/message/sendText/' . $instance,
            ['apikey: ' . $apiKey],
            ['number' => $destino, 'text' => $mensagem]
        );

        return $this->registrarResultado($evento, $destino, $mensagem, $result);
    }

    /** @return array{ok:bool,http_code:int,response:string,error:string|null} */
    public function testarConexao(): array
    {
        $config = $this->config();
        if ($config === null || ConectorHttpClient::validateUrl((string) ($config['evolution_api_url'] ?? '')) === null) {
            $this->configModel->updateTeste('whatsapp', false, 'Informe uma URL válida da Evolution API.');
            return ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => 'Informe uma URL válida da Evolution API.'];
        }

        $apiKey = (string) $this->configModel->segredoDecriptado($config['evolution_api_key'] ?? null);
        $instance = rawurlencode(trim((string) ($config['evolution_instance'] ?? '')));
        if ($apiKey === '' || $instance === '') {
            $this->configModel->updateTeste('whatsapp', false, 'Informe API Key e instância antes de testar.');
            return ['ok' => false, 'http_code' => 0, 'response' => '', 'error' => 'Informe API Key e instância antes de testar.'];
        }

        $result = ConectorHttpClient::request(
            'GET',
            rtrim((string) $config['evolution_api_url'], '/') . '/instance/connectionState/' . $instance,
            ['apikey: ' . $apiKey]
        );
        $state = strtolower((string) ($result['json']['instance']['state'] ?? $result['json']['state'] ?? ''));
        $ok = $result['ok'] && $state === 'open';
        $mensagem = $ok ? 'Instância conectada (open).' : ($result['error'] ?? ('Estado da instância: ' . ($state ?: 'indisponível')));
        $this->configModel->updateTeste('whatsapp', $ok, $mensagem);
        $this->logModel->create([
            'conector_tipo' => 'whatsapp',
            'evento' => 'teste',
            'destino' => $this->sanitizarNumero((string) ($config['whatsapp_destino'] ?? '')),
            'mensagem' => 'Teste de conexão Evolution API.',
            'payload' => ['instance' => rawurldecode($instance), 'operation' => 'connectionState'],
            'status' => $ok ? 'enviado' : 'erro',
            'resposta' => $result['response'] ?: $mensagem,
            'http_code' => $result['http_code'],
        ]);
        return ['ok' => $ok, 'http_code' => $result['http_code'], 'response' => $result['response'], 'error' => $ok ? null : $mensagem];
    }

    private function sanitizarNumero(string $numero): string
    {
        $numero = preg_replace('/\D+/', '', $numero) ?? '';
        return strlen($numero) >= 10 && strlen($numero) <= 20 ? $numero : '';
    }

    /** @param array{ok:bool,http_code:int,response:string,error:string|null} $result */
    private function registrarResultado(string $evento, string $destino, string $mensagem, array $result): array
    {
        try {
            $this->logModel->create([
                'conector_tipo' => 'whatsapp',
                'evento' => $evento,
                'destino' => $destino,
                'mensagem' => $mensagem,
                'payload' => ['number' => $destino, 'message_length' => mb_strlen($mensagem)],
                'status' => $result['ok'] ? 'enviado' : 'erro',
                'resposta' => $result['response'] ?: ($result['error'] ?? null),
                'http_code' => $result['http_code'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[WhatsAppService] Falha ao registrar log de envio', ['error' => $e->getMessage()]);
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
