<?php
namespace App\Services;

use App\Core\Logger;

/**
 * Serviço de integração com o ERP VOXEL (futura integração)
 */
class VoxelErpService {
    private string $apiUrl;
    private string $apiToken;

    public function __construct(string $apiUrl, string $apiToken) {
        $this->apiUrl   = rtrim($apiUrl, '/');
        $this->apiToken = $apiToken;
    }

    public function testarConexao(): array {
        return $this->request('GET', '/api/ping');
    }

    public function buscarExames(string $periodoInicio, string $periodoFim): array {
        return $this->request('GET', '/api/exames', [
            'data_inicio' => $periodoInicio,
            'data_fim'    => $periodoFim,
        ]);
    }

    private function request(string $method, string $endpoint, array $params = []): array {
        $url = $this->apiUrl . $endpoint;
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$this->apiToken}",
                'Accept: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::error('VoxelErpService::request', ['error' => $error, 'url' => $url]);
            return ['sucesso' => false, 'mensagem' => $error];
        }

        $data = json_decode($response, true) ?? [];
        $data['http_code'] = $httpCode;
        return $data;
    }
}
