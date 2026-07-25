<?php
/**
 * DownloadLoteController — Download em lote de estudos DICOM via Orthanc
 *
 * Fluxo (POST /tools/create-archive do Orthanc):
 *  1. Frontend envia lista de IDs internos VOXEL (bi_pacs_estudos.id)
 *  2. Backend valida: limite 5, tenant, permissão por unidade (deny-by-default)
 *  3. Backend traduz IDs VOXEL → orthanc_id
 *  4. Backend chama POST /tools/create-archive no Orthanc → retorna Job ID
 *  5. Frontend faz polling em GET /api/estudos/download-lote/{jobId}/status
 *  6. Quando Job concluído, frontend chama GET /api/estudos/download-lote/{jobId}/arquivo
 *  7. Backend faz streaming do ZIP como proxy autenticado (nunca expõe URL do Orthanc)
 *
 * Segurança:
 *  - Orthanc nunca é acessível diretamente pelo browser
 *  - Mesma checagem de autorização por unidade usada na listagem de Estudos
 *  - Limite de 5 estudos validado no backend (independente do frontend)
 *  - Auditoria em bi_download_lote_log (compliance de dado de saúde)
 *  - Streaming em chunks (não carrega ZIP inteiro em memória)
 *
 * @see database/migrations/2026-07-25_download_lote_log.sql
 * @see docs/orthanc-archive-job-flow.md
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\InstitutionResolverService;
use App\Services\OrthancService;

class DownloadLoteController extends Controller
{
    private const MAX_ESTUDOS = 5;

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT 1: Iniciar job de archive no Orthanc
    // POST /api/estudos/download-lote/iniciar
    // Body JSON: {"ids": [1, 2, 3]}
    // ─────────────────────────────────────────────────────────────────────────
    public function iniciar(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        try {
            $userId   = Auth::userId();
            $tenantId = TenantContext::id();

            if (!$userId || !$tenantId) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
                return;
            }

            // ── 1. Ler e validar payload ──────────────────────────────────
            $body = json_decode(file_get_contents('php://input'), true);
            $ids  = array_map('intval', (array)($body['ids'] ?? []));
            $ids  = array_filter($ids, fn($id) => $id > 0);
            $ids  = array_values(array_unique($ids));

            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Nenhum estudo selecionado.']);
                return;
            }

            // ── 2. Limite de 5 (server-side — nunca confiar só no frontend) ─
            if (count($ids) > self::MAX_ESTUDOS) {
                http_response_code(400);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Máximo de ' . self::MAX_ESTUDOS . ' estudos por download.',
                ]);
                return;
            }

            // ── 3. Buscar estudos no banco e validar permissão por unidade ─
            $pdo         = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Unidades autorizadas para este tenant (deny-by-default)
            $institutionNames = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);

            if (!empty($institutionNames)) {
                $iPlaceholders = implode(',', array_fill(0, count($institutionNames), '?'));
                $sql = "SELECT id, orthanc_id, institution_name, patient_name
                        FROM bi_pacs_estudos
                        WHERE id IN ({$placeholders})
                          AND tenant_id = ?
                          AND institution_name IN ({$iPlaceholders})";
                $params = array_merge($ids, [$tenantId], $institutionNames);
            } else {
                // Fallback: apenas tenant_id (sem InstitutionNames cadastradas)
                $sql = "SELECT id, orthanc_id, institution_name, patient_name
                        FROM bi_pacs_estudos
                        WHERE id IN ({$placeholders})
                          AND tenant_id = ?";
                $params = array_merge($ids, [$tenantId]);
            }

            $stmt   = $pdo->prepare($sql);
            $stmt->execute($params);
            $estudos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Se algum estudo não passou na checagem de permissão → rejeitar lote inteiro
            if (count($estudos) !== count($ids)) {
                http_response_code(403);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Um ou mais estudos selecionados não estão disponíveis para download.',
                ]);
                return;
            }

            // ── 4. Traduzir IDs VOXEL → orthanc_id ───────────────────────
            $orthancIds = array_column($estudos, 'orthanc_id');
            $orthancIds = array_values(array_filter($orthancIds, fn($v) => !empty($v)));

            if (empty($orthancIds)) {
                http_response_code(422);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Os estudos selecionados ainda não possuem imagens indexadas no PACS.',
                ]);
                return;
            }

            // ── 5. Instanciar OrthancService para este tenant ─────────────
            $orthanc = $this->getOrthancService($tenantId, $pdo);
            if (!$orthanc) {
                http_response_code(503);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Servidor PACS não configurado para este negócio.',
                ]);
                return;
            }

            // ── 6. Criar job de archive no Orthanc ────────────────────────
            // POST /tools/create-archive
            // Body: {"Resources": ["orthancId1", "orthancId2", ...], "Synchronous": false}
            $archiveResult = $orthanc->createArchive($orthancIds);

            if (!$archiveResult['success']) {
                Logger::error('[DownloadLote] Falha ao criar archive no Orthanc', [
                    'tenant_id'   => $tenantId,
                    'usuario_id'  => $userId,
                    'orthanc_ids' => $orthancIds,
                    'error'       => $archiveResult['error'],
                ]);
                http_response_code(502);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Erro ao iniciar download no servidor PACS. Tente novamente.',
                ]);
                return;
            }

            $jobId = $archiveResult['data']['ID'] ?? null;
            if (!$jobId) {
                Logger::error('[DownloadLote] Orthanc não retornou Job ID', [
                    'response' => $archiveResult['data'],
                ]);
                http_response_code(502);
                echo json_encode(['ok' => false, 'msg' => 'Resposta inesperada do servidor PACS.']);
                return;
            }

            // ── 7. Registrar auditoria ────────────────────────────────────
            $usuarioNome = '';
            $uStmt = $pdo->prepare("SELECT nome FROM bi_users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(\PDO::FETCH_ASSOC);
            if ($uRow) $usuarioNome = $uRow['nome'];

            $logId = null;
            try {
                $pdo->prepare("
                    INSERT INTO bi_download_lote_log
                        (tenant_id, usuario_id, usuario_nome, estudo_ids, orthanc_ids, orthanc_job_id, status, ip, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'iniciado', ?, NOW())
                ")->execute([
                    $tenantId,
                    $userId,
                    $usuarioNome,
                    json_encode($ids),
                    json_encode($orthancIds),
                    $jobId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $logId = (int)$pdo->lastInsertId();
            } catch (\Throwable $e) {
                // Auditoria não pode bloquear o download — apenas loga
                Logger::error('[DownloadLote] Falha ao inserir log de auditoria', ['error' => $e->getMessage()]);
            }

            echo json_encode([
                'ok'     => true,
                'job_id' => $jobId,
                'log_id' => $logId,
                'total'  => count($orthancIds),
            ]);

        } catch (\Throwable $e) {
            Logger::error('[DownloadLote::iniciar] Exceção não tratada', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente novamente.']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT 2: Polling de status do job
    // GET /api/estudos/download-lote/{jobId}/status
    // ─────────────────────────────────────────────────────────────────────────
    public function status(string $jobId): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        try {
            $userId   = Auth::userId();
            $tenantId = TenantContext::id();

            if (!$userId || !$tenantId) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
                return;
            }

            // Validar jobId (apenas alfanumérico + hífens — UUID do Orthanc)
            if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $jobId)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Job ID inválido.']);
                return;
            }

            $pdo     = Database::getInstance();
            $orthanc = $this->getOrthancService($tenantId, $pdo);

            if (!$orthanc) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'msg' => 'Servidor PACS não disponível.']);
                return;
            }

            // Consultar /jobs/{jobId} no Orthanc
            $result = $orthanc->getJob($jobId);

            if (!$result['success']) {
                http_response_code(502);
                echo json_encode(['ok' => false, 'msg' => 'Erro ao consultar status do job.']);
                return;
            }

            $job      = $result['data'];
            $state    = $job['State']    ?? 'Unknown';
            $progress = (int)($job['Progress'] ?? 0);

            // Normalizar estado para o frontend
            // Orthanc states: Pending, Running, Success, Failure, Paused, Retry
            $done  = ($state === 'Success');
            $error = in_array($state, ['Failure', 'Paused'], true);

            // Se concluído com sucesso, atualizar log de auditoria
            if ($done) {
                try {
                    $pdo->prepare("
                        UPDATE bi_download_lote_log
                        SET status = 'concluido', concluido_at = NOW()
                        WHERE orthanc_job_id = ? AND tenant_id = ?
                    ")->execute([$jobId, $tenantId]);
                } catch (\Throwable $e) {
                    Logger::error('[DownloadLote::status] Falha ao atualizar log', ['error' => $e->getMessage()]);
                }
            }

            if ($error) {
                try {
                    $errMsg = $job['ErrorDescription'] ?? 'Erro desconhecido no Orthanc';
                    $pdo->prepare("
                        UPDATE bi_download_lote_log
                        SET status = 'erro', erro_msg = ?, concluido_at = NOW()
                        WHERE orthanc_job_id = ? AND tenant_id = ?
                    ")->execute([$errMsg, $jobId, $tenantId]);
                } catch (\Throwable $e) {
                    Logger::error('[DownloadLote::status] Falha ao atualizar log de erro', ['error' => $e->getMessage()]);
                }
            }

            echo json_encode([
                'ok'       => true,
                'state'    => $state,
                'progress' => $progress,
                'done'     => $done,
                'error'    => $error,
            ]);

        } catch (\Throwable $e) {
            Logger::error('[DownloadLote::status] Exceção', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Erro interno.']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT 3: Streaming do ZIP (proxy autenticado — nunca expõe URL Orthanc)
    // GET /api/estudos/download-lote/{jobId}/arquivo
    // ─────────────────────────────────────────────────────────────────────────
    public function arquivo(string $jobId): void
    {
        try {
            $userId   = Auth::userId();
            $tenantId = TenantContext::id();

            if (!$userId || !$tenantId) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
                return;
            }

            // Validar jobId
            if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $jobId)) {
                http_response_code(400);
                echo 'Job ID inválido.';
                return;
            }

            $pdo     = Database::getInstance();
            $orthanc = $this->getOrthancService($tenantId, $pdo);

            if (!$orthanc) {
                http_response_code(503);
                echo 'Servidor PACS não disponível.';
                return;
            }

            // Verificar que o job pertence a este tenant (auditoria)
            $logStmt = $pdo->prepare("
                SELECT id FROM bi_download_lote_log
                WHERE orthanc_job_id = ? AND tenant_id = ? AND usuario_id = ?
                LIMIT 1
            ");
            $logStmt->execute([$jobId, $tenantId, $userId]);
            if (!$logStmt->fetch(\PDO::FETCH_ASSOC)) {
                http_response_code(403);
                echo 'Acesso negado.';
                return;
            }

            // Verificar que o job está concluído antes de fazer streaming
            $jobResult = $orthanc->getJob($jobId);
            if (!$jobResult['success'] || ($jobResult['data']['State'] ?? '') !== 'Success') {
                http_response_code(409);
                echo 'O arquivo ainda não está pronto. Aguarde o processamento.';
                return;
            }

            // Nome do arquivo ZIP
            $filename = 'voxelpacs_download_lote_' . date('Ymd_Hi') . '.zip';

            // Headers para download
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no'); // Nginx: desabilitar buffering para streaming

            // Streaming em chunks via cURL — nunca carrega o ZIP inteiro em memória
            $orthanc->streamJobArchive($jobId, function (string $chunk): void {
                echo $chunk;
                if (ob_get_level()) ob_flush();
                flush();
            });

        } catch (\Throwable $e) {
            Logger::error('[DownloadLote::arquivo] Exceção no streaming', ['error' => $e->getMessage()]);
            // Não podemos mais enviar JSON aqui pois headers já foram enviados
            // Apenas encerrar — o browser vai detectar download incompleto
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: instanciar OrthancService para o tenant atual
    // ─────────────────────────────────────────────────────────────────────────
    private function getOrthancService(int $tenantId, \PDO $pdo): ?OrthancService
    {
        $stmt = $pdo->prepare("
            SELECT url, usuario, senha, timeout
            FROM   bi_orthanc_servidores
            WHERE  tenant_id = ? AND ativo = 1
            ORDER  BY id ASC
            LIMIT  1
        ");
        $stmt->execute([$tenantId]);
        $servidor = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$servidor) {
            // Fallback: URL padrão do ambiente (para tenants sem servidor cadastrado)
            $defaultUrl = $_ENV['ORTHANC_URL'] ?? null;
            if (!$defaultUrl) return null;
            return new OrthancService($defaultUrl, null, null, 30);
        }

        return new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?: null,
            $servidor['senha']   ?: null,
            (int)($servidor['timeout'] ?: 60)
        );
    }
}
