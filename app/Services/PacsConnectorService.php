<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

class PacsConnectorService {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Testa a conectividade com o PACS
     */
    public function testarConexao(object $pacs): array {
        if ($pacs->tipo === 'upload_manual') {
            return ['sucesso' => true, 'mensagem' => 'Conexão manual — sem teste de conectividade.'];
        }

        if (empty($pacs->url_api)) {
            return ['sucesso' => false, 'mensagem' => 'URL da API não configurada.'];
        }

        try {
            $ch = curl_init($pacs->url_api);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$pacs->api_key}"],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['sucesso' => false, 'mensagem' => "Erro de conexão: {$error}"];
            }

            return $httpCode < 400
                ? ['sucesso' => true,  'mensagem' => "Conexão bem-sucedida (HTTP {$httpCode})."]
                : ['sucesso' => false, 'mensagem' => "Servidor retornou HTTP {$httpCode}."];
        } catch (\Throwable $e) {
            Logger::error('PacsConnectorService::testarConexao', ['error' => $e->getMessage()]);
            return ['sucesso' => false, 'mensagem' => 'Erro inesperado ao testar conexão.'];
        }
    }

    /**
     * Sincroniza exames via API REST
     */
    public function sincronizarViaApi(object $pacs, string $periodoInicio, string $periodoFim): array {
        // Implementação específica por sistema (Pixeon, Carestream, Sectra, etc.)
        return ['importados' => 0, 'erros' => 0, 'mensagem' => 'Sincronização via API não implementada para este sistema.'];
    }

    /**
     * Sincroniza exames via ERP VOXEL
     */
    public function sincronizarViaErp(object $pacs, string $periodo): array {
        return ['importados' => 0, 'erros' => 0, 'mensagem' => 'Sincronização via ERP não implementada.'];
    }

    /**
     * Retorna os conectores disponíveis e seus status
     */
    public function listarConectores(int $tenantId): array {
        $stmt = $this->pdo->prepare("
            SELECT id, nome, tipo, sistema, status, ultimo_sync
            FROM bi_pacs_conexoes
            WHERE tenant_id = :tid
            ORDER BY nome
        ");
        $stmt->execute(['tid' => $tenantId]);
        return $stmt->fetchAll();
    }
}
