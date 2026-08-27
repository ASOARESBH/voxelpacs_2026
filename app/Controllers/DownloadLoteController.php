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
            // Aceita 'estudo_ids' (JS) ou 'ids' (alias legado)
            $ids  = array_map('intval', (array)($body['estudo_ids'] ?? $body['ids'] ?? []));
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

            // ── 3. Buscar estudos no banco e validar escopo definitivo ─────
            $pdo         = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // tenant_id é preenchido somente após o roteamento seguro pelo par
            // Institution Name + Issuer. Exigir novamente apenas o nome da
            // instituição poderia negar uma unidade válida ou reabrir ambiguidade.
            $sql = "SELECT id, orthanc_id, servidor_id
                    FROM bi_pacs_estudos
                    WHERE id IN ({$placeholders})
                      AND tenant_id = ?";
            $params = array_merge($ids, [$tenantId]);

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

            // ── 5. Resolver o servidor de origem, nunca um fallback global ─
            $serverIds = array_values(array_unique(array_map('intval', array_column($estudos, 'servidor_id'))));
            if (count($serverIds) !== 1 || $serverIds[0] <= 0) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'msg' => 'Selecione estudos do mesmo servidor PACS para um único download.']);
                return;
            }
            $serverId = $serverIds[0];
            $orthanc = $this->getOrthancService($tenantId, $serverId, $pdo);
            if (!$orthanc) {
                http_response_code(503);
                echo json_encode([
                    'ok'  => false,
                    'msg' => 'Servidor PACS não configurado para este negócio.',
                ]);
                return;
            }

            // ── 6. Confirmar existência antes de criar o archive ──────────
            $missing = false;
            foreach ($estudos as $estudo) {
                $probe = $orthanc->studyExists((string) $estudo['orthanc_id']);
                if (!empty($probe['exists'])) {
                    $this->saveDownloadAvailability($pdo, (int) $estudo['id'], $tenantId, $serverId, 'available', null);
                    continue;
                }
                if (($probe['code'] ?? 0) === 404) {
                    $missing = true;
                    $this->saveDownloadAvailability($pdo, (int) $estudo['id'], $tenantId, $serverId, 'unavailable', 'ORTHANC_RESOURCE_NOT_FOUND');
                    continue;
                }
                http_response_code(503);
                echo json_encode(['ok' => false, 'msg' => 'Não foi possível confirmar o servidor PACS agora. Tente novamente.']);
                return;
            }
            if ($missing) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'msg' => 'Um ou mais estudos não estão mais disponíveis no servidor PACS.']);
                return;
            }

            // ── 7. Criar job de archive no Orthanc ────────────────────────
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
            $uStmt = $pdo->prepare("SELECT name FROM bi_users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(\PDO::FETCH_ASSOC);
            if ($uRow) $usuarioNome = $uRow['name'];

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
    // GET /api/download-lote/status?job_id=xxx&log_id=yyy
    // ─────────────────────────────────────────────────────────────────────────
    public function status(): void
    {
        $jobId = trim($_GET['job_id'] ?? '');
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

            // Validar jobId
            if (empty($jobId) || !preg_match('/^[a-f0-9\-]{8,64}$/i', $jobId)) {
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
    // GET /api/download-lote/baixar?job_id=xxx&log_id=yyy
    // ─────────────────────────────────────────────────────────────────────────
    public function baixar(): void
    {
        $jobId = trim($_GET['job_id'] ?? '');
        try {
            $userId   = Auth::userId();
            $tenantId = TenantContext::id();

            if (!$userId || !$tenantId) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
                return;
            }

            // Validar jobId
            if (empty($jobId) || !preg_match('/^[a-f0-9\-]{8,64}$/i', $jobId)) {
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

    private function saveDownloadAvailability(\PDO $pdo, int $studyId, int $tenantId, int $serverId, string $status, ?string $errorCode): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO bi_pacs_download_availability (estudo_id, tenant_id, servidor_id, status, checked_at, error_code, updated_at) VALUES (?, ?, ?, ?, NOW(), ?, NOW()) ON CONFLICT (estudo_id) DO UPDATE SET tenant_id=EXCLUDED.tenant_id, servidor_id=EXCLUDED.servidor_id, status=EXCLUDED.status, checked_at=EXCLUDED.checked_at, error_code=EXCLUDED.error_code, updated_at=NOW()");
            $stmt->execute([$studyId, $tenantId, $serverId, $status, $errorCode]);
        } catch (\Throwable $ignored) {
            // Migration ainda não aplicada: a checagem continua protegendo o download.
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: instanciar OrthancService para o tenant atual
    //
    // Hierarquia de fontes (deny-by-default → fallback progressivo):
    //  1. bi_orthanc_servidores (tabela multi-tenant por negócio) — se existir e tiver registro
    //  2. bi_pacs_servidor (tabela global única, gerenciada pelo superadmin) — fallback
    //  3. $_ENV['ORTHANC_URL'] — fallback de ambiente
    // ─────────────────────────────────────────────────────────────────────────
    private function getOrthancService(int $tenantId, int $serverId, \PDO $pdo): ?OrthancService
    {
        // A origem é sempre o servidor gravado no estudo. Células exclusivas
        // só podem ser usadas pelo tenant dono; não há fallback de ambiente.
        try {
            $stmt = $pdo->prepare("
                SELECT s.url, s.usuario, s.senha, s.timeout
                FROM bi_pacs_servidor s
                WHERE s.id = ? AND s.ativo = 1
                  AND NOT EXISTS (
                    SELECT 1 FROM bi_tenant_orthanc_cells c
                    WHERE c.servidor_id = s.id AND c.tenant_id <> ?
                  )
                LIMIT 1
            ");
            $stmt->execute([$serverId, $tenantId]);
            $servidor = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$servidor) {
                return null;
            }
            return new OrthancService(
                $servidor['url'],
                $servidor['usuario'] ?: null,
                $servidor['senha'] ?: null,
                (int) ($servidor['timeout'] ?: 60)
            );
        } catch (\Throwable $e) {
            Logger::error('[DownloadLote] Servidor de origem indisponível', [
                'tenant_id' => $tenantId,
                'servidor_id' => $serverId,
                'error_type' => get_class($e),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINT 4: Download Inteligente — ZIP com launcher + DICOMDIR + Leia-me
    // GET /api/download-lote/baixar-inteligente?job_id=xxx&log_id=yyy&patient=Nome&suffix=opcional
    //
    // Novo fluxo (item 17 da especificação VOXEL Desktop):
    //  1. Baixa o ZIP gerado pelo Orthanc para arquivo temporário
    //  2. Reabre o ZIP e adiciona:
    //     ├── Abrir Exame.exe  (launcher Windows — verifica VOXEL Desktop e abre o estudo)
    //     ├── DICOMDIR         (gerado pelo Orthanc, já está no ZIP original)
    //     └── Leia-me.pdf      (instruções de uso)
    //  3. Serve o ZIP enriquecido com nome amigável (ex: 31192_GUSTAVO.zip)
    //
    // Modos de uso pelo frontend (ver app/Views/estudos/index.php):
    //  - Agrupado: 1 chamada de iniciar() com todos os IDs selecionados → 1 job →
    //    1 zip aqui, com pasta por estudo dentro (o que o Orthanc já gera para
    //    múltiplos Resources num único archive job).
    //  - Individual (padrão): N chamadas de iniciar() com 1 ID cada → N jobs →
    //    N chamadas a este endpoint, cada uma reaproveitando o mesmo código —
    //    o zip resultante já sai no formato "1 estudo só" porque o job só tinha
    //    1 Resource. Não há branch de código separado para os dois modos.
    //  - `suffix` (opcional): usado só pelo modo individual, para evitar que dois
    //    estudos do mesmo paciente gerem o mesmo nome de arquivo (o frontend manda
    //    o ID VOXEL do estudo, já único). Sem `suffix` o nome final é idêntico ao
    //    comportamento histórico (modo agrupado e o caso de sempre existiu de
    //    selecionar 1 único estudo).
    // ─────────────────────────────────────────────────────────────────────────
    public function baixarInteligente(): void
    {
        $jobId = trim($_GET['job_id'] ?? '');
        $nomePaciente = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtoupper(trim($_GET['patient'] ?? 'PACIENTE')));
        $nomePaciente = substr($nomePaciente, 0, 30);
        $sufixo = preg_replace('/[^A-Za-z0-9_\-]/', '', trim($_GET['suffix'] ?? ''));
        $sufixo = substr($sufixo, 0, 20);

        try {
            $userId   = Auth::userId();
            $tenantId = TenantContext::id();

            if (!$userId || !$tenantId) {
                http_response_code(401);
                echo 'Não autenticado.';
                return;
            }

            if (empty($jobId) || !preg_match('/^[a-f0-9\-]{8,64}$/i', $jobId)) {
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

            // Verificar que o job pertence a este tenant
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

            // Verificar que o job está concluído
            $jobResult = $orthanc->getJob($jobId);
            if (!$jobResult['success'] || ($jobResult['data']['State'] ?? '') !== 'Success') {
                http_response_code(409);
                echo 'O arquivo ainda não está pronto.';
                return;
            }

            // ── 1. Baixar ZIP do Orthanc para arquivo temporário ─────────────────────
            $tmpOrthanc = tempnam(sys_get_temp_dir(), 'voxel_dl_');
            $fh = fopen($tmpOrthanc, 'wb');
            $orthanc->streamJobArchive($jobId, function (string $chunk) use ($fh): void {
                fwrite($fh, $chunk);
            });
            fclose($fh);

            // ── 2. Verificar se ZipArchive está disponível ──────────────────────────
            if (!class_exists('ZipArchive')) {
                // Fallback: servir o ZIP original sem modificações
                Logger::error('[DownloadLote::baixarInteligente] ZipArchive não disponível, usando ZIP original');
                $filename = date('Ymd_Hi') . '_' . $nomePaciente . ($sufixo !== '' ? '_' . $sufixo : '') . '.zip';
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store');
                readfile($tmpOrthanc);
                @unlink($tmpOrthanc);
                return;
            }

            // ── 3. Criar novo ZIP enriquecido ─────────────────────────────────────
            $tmpFinal = tempnam(sys_get_temp_dir(), 'voxel_smart_');

            // Copiar o ZIP original para o novo (preserva todos os arquivos DICOM)
            copy($tmpOrthanc, $tmpFinal);
            @unlink($tmpOrthanc);

            $zip = new \ZipArchive();
            if ($zip->open($tmpFinal, \ZipArchive::CREATE) !== true) {
                Logger::error('[DownloadLote::baixarInteligente] Falha ao abrir ZIP temporário');
                http_response_code(500);
                echo 'Erro ao processar arquivo.';
                @unlink($tmpFinal);
                return;
            }

            // ── 3a. Adicionar launcher "Abrir Exame.exe" (script AutoIt compilado) ──
            // Por enquanto, adicionamos um placeholder .bat que verifica o VOXEL Desktop
            // e abre o estudo. Quando o .exe real estiver disponível, substituir o
            // conteúdo abaixo pelo binário do launcher compilado.
            $launcherPath = BASE_PATH . '/public/downloads/VOXELDesktopLauncher.exe';
            if (file_exists($launcherPath)) {
                $zip->addFile($launcherPath, 'Abrir Exame.exe');
            } else {
                // Placeholder: script .bat com instruções de abertura
                $batContent = $this->gerarLauncherBat($nomePaciente);
                $zip->addFromString('Abrir Exame.bat', $batContent);
            }

            // ── 3b. Adicionar Leia-me.pdf ou Leia-me.txt ─────────────────────────
            $leiaMe = BASE_PATH . '/public/downloads/Leia-me.pdf';
            if (file_exists($leiaMe)) {
                $zip->addFile($leiaMe, 'Leia-me.pdf');
            } else {
                $zip->addFromString('Leia-me.txt', $this->gerarLeiaMe($nomePaciente));
            }

            $zip->close();

            // ── 4. Servir o ZIP enriquecido ──────────────────────────────────────
            $filename = date('Ymd') . '_' . $nomePaciente . ($sufixo !== '' ? '_' . $sufixo : '') . '.zip';
            $filesize = filesize($tmpFinal);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $filesize);
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');

            readfile($tmpFinal);
            @unlink($tmpFinal);

        } catch (\Throwable $e) {
            Logger::error('[DownloadLote::baixarInteligente] Exceção', ['error' => $e->getMessage()]);
            if (!headers_sent()) {
                http_response_code(500);
                echo 'Erro interno ao gerar download.';
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: gera o conteúdo do launcher .bat (placeholder até o .exe estar disponível)
    // ─────────────────────────────────────────────────────────────────────────
    private function gerarLauncherBat(string $nomePaciente): string
    {
        return "@echo off\r\n" .
            "title Abrir Exame VOXEL Desktop\r\n" .
            "echo.\r\n" .
            "echo  ====================================================\r\n" .
            "echo   VOXEL Desktop - Visualizador de Imagens Medicas\r\n" .
            "echo  ====================================================\r\n" .
            "echo.\r\n" .
            "echo  Paciente: {$nomePaciente}\r\n" .
            "echo.\r\n" .
            "echo  Verificando VOXEL Desktop instalado...\r\n" .
            "echo.\r\n" .
            "start voxel://open?dicomdir=DICOMDIR\r\n" .
            "timeout /t 2 /nobreak >nul\r\n" .
            "echo  Se o VOXEL Desktop nao abrir automaticamente,\r\n" .
            "echo  acesse: https://server.voxelpacs.com.br/desktop/download\r\n" .
            "echo.\r\n" .
            "pause\r\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: gera o conteúdo do Leia-me.txt (placeholder até o PDF estar disponível)
    // ─────────────────────────────────────────────────────────────────────────
    private function gerarLeiaMe(string $nomePaciente): string
    {
        $data = date('d/m/Y');
        return "VOXEL Desktop - Visualizador Oficial VOXEL PACS\r\n" .
            "=================================================\r\n\r\n" .
            "Paciente: {$nomePaciente}\r\n" .
            "Data de exportacao: {$data}\r\n\r\n" .
            "COMO ABRIR ESTE EXAME\r\n" .
            "---------------------\r\n" .
            "1. Execute o arquivo 'Abrir Exame.exe' (ou 'Abrir Exame.bat')\r\n" .
            "   O VOXEL Desktop sera aberto automaticamente.\r\n\r\n" .
            "2. Se o VOXEL Desktop nao estiver instalado:\r\n" .
            "   Acesse https://server.voxelpacs.com.br/desktop/download\r\n" .
            "   e instale o visualizador oficial.\r\n\r\n" .
            "3. Voce tambem pode abrir o arquivo DICOMDIR com qualquer\r\n" .
            "   visualizador DICOM compativel (RadiAnt, Weasis, etc.)\r\n\r\n" .
            "SUPORTE\r\n" .
            "-------\r\n" .
            "Site:    https://www.voxelpacs.com.br\r\n" .
            "Suporte: https://server.voxelpacs.com.br/suporte\r\n\r\n" .
            "VOXEL Tecnologia em Diagnostico\r\n" .
            "Copyright (c) " . date('Y') . " VOXEL PACS. Todos os direitos reservados.\r\n";
    }
}
