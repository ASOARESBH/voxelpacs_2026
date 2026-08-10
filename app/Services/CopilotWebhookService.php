<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * CopilotWebhookService
 *
 * Responsável por notificar o VOXEL Copilot sobre eventos do PACS:
 *   - estudo.assumido    → médico assumiu um exame na worklist
 *   - estudo.aberto      → médico abriu o viewer (em_laudo)
 *   - estudo.liberado    → laudo assinado e liberado no PACS
 *
 * Também expõe o método receberLaudo() para processar o retorno do Copilot.
 *
 * Uso:
 *   $svc = new CopilotWebhookService();
 *   $svc->notificarEstudoAssumido($tenantId, $estudo, $medico);
 */
class CopilotWebhookService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVENTO: médico assumiu um estudo na worklist
    // ─────────────────────────────────────────────────────────────────────────
    public function notificarEstudoAssumido(int $tenantId, array $estudo, array $medico): void
    {
        $payload = [
            'evento'     => 'estudo.assumido',
            'estudo'     => $this->buildEstudoPayload($estudo),
            'medico'     => $this->buildMedicoPayload($medico),
            'timestamp'  => date('c'),
        ];
        $this->enviar($tenantId, $medico['id'] ?? 0, $estudo['id'] ?? 0, 'estudo.assumido', $payload);
        // Atualiza copilot_status no bi_pacs_estudos
        if (!empty($estudo['id'])) {
            try {
                $this->pdo->prepare("
                    UPDATE bi_pacs_estudos SET
                        copilot_status     = 'enviado_copilot',
                        copilot_enviado_em = NOW(),
                        updated_at         = NOW()
                    WHERE id = :id
                ")->execute(['id' => $estudo['id']]);
            } catch (\Throwable $e) { /* coluna pode não existir ainda */ }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVENTO: médico abriu o viewer (em_laudo)
    // ─────────────────────────────────────────────────────────────────────────
    public function notificarEstudoAberto(int $tenantId, array $estudo, array $medico): void
    {
        $payload = [
            'evento'    => 'estudo.aberto',
            'estudo'    => $this->buildEstudoPayload($estudo),
            'medico'    => $this->buildMedicoPayload($medico),
            'timestamp' => date('c'),
        ];
        $this->enviar($tenantId, $medico['id'] ?? 0, $estudo['id'] ?? 0, 'estudo.aberto', $payload);
        // Atualiza copilot_status no bi_pacs_estudos
        if (!empty($estudo['id'])) {
            try {
                $this->pdo->prepare("
                    UPDATE bi_pacs_estudos SET
                        copilot_status = 'em_laudo',
                        updated_at     = NOW()
                    WHERE id = :id
                ")->execute(['id' => $estudo['id']]);
            } catch (\Throwable $e) { /* coluna pode não existir ainda */ }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVENTO: laudo liberado no PACS
    // ─────────────────────────────────────────────────────────────────────────
    public function notificarLaudoLiberado(int $tenantId, array $estudo, array $medico, array $laudo): void
    {
        $payload = [
            'evento'    => 'estudo.liberado',
            'estudo'    => $this->buildEstudoPayload($estudo),
            'medico'    => $this->buildMedicoPayload($medico),
            'laudo'     => [
                'texto'       => $laudo['texto'] ?? null,
                'assinado_em' => $laudo['assinado_em'] ?? null,
            ],
            'timestamp' => date('c'),
        ];
        $this->enviar($tenantId, $medico['id'] ?? 0, $estudo['id'] ?? 0, 'estudo.liberado', $payload);
        // Atualiza copilot_status no bi_pacs_estudos
        if (!empty($estudo['id'])) {
            try {
                $this->pdo->prepare("
                    UPDATE bi_pacs_estudos SET
                        copilot_status     = 'assinado',
                        copilot_laudo_em   = NOW(),
                        copilot_medico_nome = :nome,
                        updated_at         = NOW()
                    WHERE id = :id
                ")->execute(['id' => $estudo['id'], 'nome' => $medico['nome'] ?? null]);
            } catch (\Throwable $e) { /* coluna pode não existir ainda */ }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECEBER LAUDO DO COPILOT → atualiza bi_pacs_estudos
    // Chamado pelo endpoint POST /api/copilot/webhook/laudo-finalizado
    // ─────────────────────────────────────────────────────────────────────────
    public function receberLaudo(array $input, string $tokenRecebido): array
    {
        // Valida token
        $unidade = $this->buscarUnidadePorToken($tokenRecebido);
        if (!$unidade) {
            return ['ok' => false, 'erro' => 'token_invalido'];
        }

        $studyUid  = trim($input['study_instance_uid'] ?? '');
        $laudoHtml = trim($input['laudo_html']         ?? '');
        $laudoTxt  = trim($input['laudo_texto']        ?? '');
        $medicoNome= trim($input['medico_nome']        ?? '');
        $medicoToken = trim($input['medico_token']     ?? '');

        if (!$studyUid) {
            return ['ok' => false, 'erro' => 'study_uid_obrigatorio'];
        }

        // Busca o estudo
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.situacao, e.tenant_id,
                   r.id AS report_id, r.situacao AS report_situacao
            FROM bi_pacs_estudos e
            LEFT JOIN reports r ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id
            WHERE e.study_instance_uid = :uid AND e.tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $studyUid, 'tid' => $unidade->tenant_id]);
        $estudo = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$estudo) {
            return ['ok' => false, 'erro' => 'estudo_nao_encontrado'];
        }

        // Um webhook tardio não pode reabrir ou sobrescrever uma revisão clínica.
        // Conferimos o estado denormalizado do estudo e o estado operacional do report.
        $peerReviewAberto = ($estudo->situacao === 'peer_review' || $estudo->report_situacao === 'peer_review');
        if (!$peerReviewAberto && !empty($estudo->report_id)) {
            try {
                $peerStmt = $this->pdo->prepare(
                    "SELECT id FROM pacs_report_peer_reviews
                     WHERE report_id = :report_id AND tenant_id = :tenant_id AND status = 'aberta'
                     LIMIT 1"
                );
                $peerStmt->execute([
                    'report_id' => (int) $estudo->report_id,
                    'tenant_id' => (int) $estudo->tenant_id,
                ]);
                $peerReviewAberto = (bool) $peerStmt->fetchColumn();
            } catch (\Throwable $peerError) {
                // Instalações sem a migration ainda seguem o fluxo legado,
                // mas o diagnóstico fica registrado para o deploy.
                Logger::warning('[CopilotWebhookService::receberLaudo] não foi possível consultar Peer Review', [
                    'estudo_id' => $estudo->id,
                    'report_id' => $estudo->report_id ?? null,
                    'tenant_id' => $estudo->tenant_id,
                    'error' => $peerError->getMessage(),
                ]);
            }
        }
        if ($peerReviewAberto) {
            Logger::warning('[CopilotWebhookService::receberLaudo] finalização bloqueada por Peer Review', [
                'estudo_id' => $estudo->id,
                'report_id' => $estudo->report_id ?? null,
                'tenant_id' => $estudo->tenant_id,
                'study_uid' => $studyUid,
            ]);
            return [
                'ok' => false,
                'erro' => 'peer_review_aberto',
                'msg' => 'Laudo em Peer Review. Conclua a revisão no Report antes de receber nova finalização do Copilot.',
            ];
        }

        // Valida token do médico
        $tokenMedico = null;
        if ($medicoToken) {
            $stmt2 = $this->pdo->prepare("
                SELECT * FROM bi_copilot_medico_tokens
                WHERE token_integracao = :tok AND unidade_id = :uid AND status = 'ativo' LIMIT 1
            ");
            $stmt2->execute(['tok' => $medicoToken, 'uid' => $unidade->id]);
            $tokenMedico = $stmt2->fetch(\PDO::FETCH_OBJ);
        }

        try {
            // Atualiza situação do estudo para 'assinado'
            $this->pdo->prepare("
                UPDATE bi_pacs_estudos SET
                    situacao          = 'assinado',
                    laudo_assinado_em = NOW(),
                    updated_at        = NOW()
                WHERE id = :id
            ")->execute(['id' => $estudo->id]);

            // Atualiza contadores da unidade
            $this->pdo->prepare("
                UPDATE bi_copilot_unidades SET
                    total_laudos_recv = total_laudos_recv + 1,
                    ultimo_sync       = NOW(),
                    updated_at        = NOW()
                WHERE id = :uid
            ")->execute(['uid' => $unidade->id]);

            // Atualiza contadores do token do médico
            if ($tokenMedico) {
                $this->pdo->prepare("
                    UPDATE bi_copilot_medico_tokens SET
                        total_laudos = total_laudos + 1,
                        ultimo_uso   = NOW(),
                        updated_at   = NOW()
                    WHERE id = :id
                ")->execute(['id' => $tokenMedico->id]);
            }

            // Registra no log
            $this->registrarLog($unidade->tenant_id, $unidade->id,
                $tokenMedico ? $tokenMedico->medico_id : 0,
                $estudo->id, 'laudo.finalizado', 'copilot_para_pacs', 'sucesso', 200,
                json_encode(['study_uid' => $studyUid, 'medico' => $medicoNome]),
                json_encode(['ok' => true])
            );

            Logger::info("[CopilotWebhookService::receberLaudo] estudo_id={$estudo->id} study_uid={$studyUid}");

            return ['ok' => true, 'msg' => 'Laudo recebido e estudo atualizado para assinado.'];
        } catch (\Throwable $e) {
            Logger::error('[CopilotWebhookService::receberLaudo] ' . $e->getMessage());
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE: envia webhook ao Copilot
    // ─────────────────────────────────────────────────────────────────────────
    private function enviar(int $tenantId, int $medicoId, int $estudoId, string $evento, array $payload): void
    {
        $unidade = $this->buscarUnidadePorTenant($tenantId);
        if (!$unidade || $unidade->status !== 'ativo' || !$unidade->copilot_url) {
            // Integração não configurada — silencioso
            return;
        }

        // Busca token do médico
        $tokenMedico = null;
        if ($medicoId) {
            $stmt = $this->pdo->prepare("
                SELECT token_integracao FROM bi_copilot_medico_tokens
                WHERE medico_id = :mid AND unidade_id = :uid AND status = 'ativo' LIMIT 1
            ");
            $stmt->execute(['mid' => $medicoId, 'uid' => $unidade->id]);
            $tokenMedico = $stmt->fetchColumn();
        }

        // Adiciona metadados de autenticação ao payload
        $payload['_meta'] = [
            'codigo_unidade' => $unidade->codigo_unidade,
            'medico_token'   => $tokenMedico ?: null,
            'pacs_version'   => '2026',
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Assina o payload com HMAC-SHA256
        $assinatura = hash_hmac('sha256', $payloadJson, $unidade->chave_secreta);

        $url = rtrim($unidade->copilot_url, '/') . '/api/pacs/webhook/evento';

        $httpStatus = 0;
        $resposta   = '';
        $erro       = null;

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payloadJson,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Copilot-Signature: sha256=' . $assinatura,
                    'X-Copilot-Unidade: '  . $unidade->codigo_unidade,
                    'Authorization: Bearer ' . ($unidade->copilot_api_token ?? ''),
                ],
            ]);
            $resposta   = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr    = curl_error($ch);
            curl_close($ch);

            if ($curlErr) $erro = $curlErr;

            // Atualiza contador e timestamp
            $this->pdo->prepare("
                UPDATE bi_copilot_unidades SET
                    total_exames_sync = total_exames_sync + 1,
                    ultimo_sync       = NOW(),
                    updated_at        = NOW()
                WHERE id = :uid
            ")->execute(['uid' => $unidade->id]);

        } catch (\Throwable $e) {
            $erro = $e->getMessage();
        }

        $status = ($httpStatus >= 200 && $httpStatus < 300) ? 'sucesso' : 'erro';

        $this->registrarLog(
            $tenantId, $unidade->id, $medicoId, $estudoId,
            $evento, 'pacs_para_copilot', $status, $httpStatus,
            $payloadJson, $resposta, $erro
        );

        if ($status === 'erro') {
            Logger::error("[CopilotWebhookService] Falha ao enviar evento={$evento} tenant={$tenantId} http={$httpStatus}", [
                'erro' => $erro,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────
    private function buscarUnidadePorTenant(int $tenantId): ?\stdClass
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_copilot_unidades WHERE tenant_id = :tid AND status = 'ativo' LIMIT 1");
            $stmt->execute(['tid' => $tenantId]);
            return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buscarUnidadePorToken(string $token): ?\stdClass
    {
        try {
            // O token vem no header Authorization: Bearer <copilot_api_token>
            $stmt = $this->pdo->prepare("SELECT * FROM bi_copilot_unidades WHERE copilot_api_token = :tok AND status = 'ativo' LIMIT 1");
            $stmt->execute(['tok' => $token]);
            return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildEstudoPayload(array $e): array
    {
        return [
            'id'                  => $e['id']                  ?? null,
            'study_instance_uid'  => $e['study_instance_uid']  ?? null,
            'accession_number'    => $e['accession_number']    ?? null,
            'patient_name'        => $e['patient_name_display'] ?? $e['patient_name'] ?? null,
            'patient_id'          => $e['patient_id']          ?? null,
            'patient_birth_date'  => $e['patient_birth_date']  ?? null,
            'patient_sex'         => $e['patient_sex']         ?? null,
            'modalities'          => $e['modalities']          ?? null,
            'study_date'          => $e['study_date']          ?? null,
            'study_description'   => $e['study_description']   ?? null,
            'institution_name'    => $e['institution_name']    ?? null,
            'num_series'          => $e['num_series']          ?? null,
            'num_instances'       => $e['num_instances']       ?? null,
            'situacao'            => $e['situacao']            ?? null,
            'assumido_em'         => $e['assumido_em']         ?? null,
            'prioridade'          => $e['prioridade']          ?? null,
        ];
    }

    private function buildMedicoPayload(array $m): array
    {
        return [
            'id'           => $m['id']           ?? null,
            'nome'         => $m['nome']          ?? null,
            'crm'          => $m['crm']           ?? null,
            'crm_uf'       => $m['crm_uf']        ?? null,
            'especialidade'=> $m['especialidade'] ?? null,
            'email'        => $m['email']         ?? null,
        ];
    }

    private function registrarLog(
        int    $tenantId,
        int    $unidadeId,
        int    $medicoId,
        int    $estudoId,
        string $evento,
        string $direcao,
        string $status,
        int    $httpStatus,
        string $payloadJson,
        string $respostaJson,
        ?string $erroMsg = null
    ): void {
        try {
            $this->pdo->prepare("
                INSERT INTO bi_copilot_sync_log
                    (tenant_id, unidade_id, medico_id, estudo_id, evento, direcao, status,
                     http_status, payload_json, resposta_json, erro_msg, ip, created_at)
                VALUES
                    (:tid, :uid, :mid, :eid, :evento, :dir, :status,
                     :http, :payload, :resp, :erro, :ip, NOW())
            ")->execute([
                'tid'     => $tenantId,
                'uid'     => $unidadeId,
                'mid'     => $medicoId,
                'eid'     => $estudoId,
                'evento'  => $evento,
                'dir'     => $direcao,
                'status'  => $status,
                'http'    => $httpStatus,
                'payload' => substr($payloadJson, 0, 4000),
                'resp'    => substr($respostaJson, 0, 2000),
                'erro'    => $erroMsg,
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (\Throwable $e) {
            // Log silencioso
        }
    }
}
