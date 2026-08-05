<?php
namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;

/**
 * CopilotIntegracaoController
 *
 * Gerencia a integração sistêmica VoxelPACS ↔ VOXEL Copilot no painel de Negócios.
 *
 * Rotas (todas em platform.php):
 *   GET  /platform/negocios/{id}/copilot              → Aba de integração (show)
 *   POST /platform/negocios/{id}/copilot/gerar-codigo → Gera código de unidade
 *   POST /platform/negocios/{id}/copilot/revogar      → Revoga integração
 *   POST /platform/negocios/{id}/copilot/medico/{mid}/gerar-token → Gera token do médico
 *   POST /platform/negocios/{id}/copilot/medico/{mid}/revogar     → Revoga token do médico
 *   GET  /platform/api/negocios/{id}/copilot/status   → JSON: status da integração
 */
class CopilotIntegracaoController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /platform/negocios/{id}/copilot
    // Exibe a aba de integração com o Copilot para um negócio específico.
    // ─────────────────────────────────────────────────────────────────────────
    public function show(int $id): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }

        $pdo = Database::getInstance();

        // Dados do negócio
        $negocio = $pdo->prepare("SELECT * FROM bi_tenants WHERE id = :id LIMIT 1");
        $negocio->execute(['id' => $id]);
        $negocio = $negocio->fetch(\PDO::FETCH_OBJ);
        if (!$negocio) {
            http_response_code(404);
            echo 'Negócio não encontrado.';
            return;
        }

        // Configuração Copilot da unidade
        $unidade = $this->getUnidade($pdo, $id);

        // Médicos do negócio com seus tokens
        $medicos = [];
        try {
            $stmt = $pdo->prepare("
                SELECT
                    m.id, m.nome, m.crm, m.crm_uf, m.especialidade, m.email, m.ativo,
                    t.id            AS token_id,
                    t.token_integracao,
                    t.status        AS token_status,
                    t.total_exames,
                    t.total_laudos,
                    t.ultimo_uso,
                    t.token_expira_em,
                    t.created_at    AS token_criado_em
                FROM bi_medicos m
                LEFT JOIN bi_copilot_medico_tokens t
                    ON t.medico_id = m.id AND t.unidade_id = :uid
                WHERE m.tenant_id = :tid AND m.ativo = 1
                ORDER BY m.nome ASC
            ");
            $stmt->execute([
                'uid' => $unidade ? $unidade->id : 0,
                'tid' => $id,
            ]);
            $medicos = $stmt->fetchAll(\PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            Logger::error('[CopilotIntegracaoController::show] Erro ao buscar médicos: ' . $e->getMessage());
        }

        // Últimos 20 eventos do log
        $logEventos = [];
        try {
            $stmt = $pdo->prepare("
                SELECT l.*, m.nome AS medico_nome
                FROM bi_copilot_sync_log l
                LEFT JOIN bi_medicos m ON m.id = l.medico_id
                WHERE l.tenant_id = :tid
                ORDER BY l.created_at DESC
                LIMIT 20
            ");
            $stmt->execute(['tid' => $id]);
            $logEventos = $stmt->fetchAll(\PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            // Tabela pode não existir ainda — silencioso
        }

        $this->view('platform/negocios/copilot', [
            'title'      => 'VOXEL Copilot — ' . $negocio->nome,
            'negocio'    => $negocio,
            'unidade'    => $unidade,
            'medicos'    => $medicos,
            'logEventos' => $logEventos,
        ], 'platform');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /platform/negocios/{id}/copilot/gerar-codigo
    // Gera (ou regenera) o código de unidade e a chave secreta.
    // ─────────────────────────────────────────────────────────────────────────
    public function gerarCodigo(int $id): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $pdo   = Database::getInstance();
        $input = $this->getJsonBody();

        // Valida negócio
        $negocio = $pdo->prepare("SELECT id, nome, slug, cidade, estado, cnpj FROM bi_tenants WHERE id = :id LIMIT 1");
        $negocio->execute(['id' => $id]);
        $negocio = $negocio->fetch(\PDO::FETCH_OBJ);
        if (!$negocio) {
            $this->json(['ok' => false, 'msg' => 'Negócio não encontrado.'], 404);
            return;
        }

        // Gera código legível: SLUG-ANO-SEQ (ex: HOSP-BH-2026-001)
        $slug     = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '-', $negocio->slug), 0, 12));
        $ano      = date('Y');
        $seq      = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $codigo   = "{$slug}-{$ano}-{$seq}";

        // Garante unicidade
        $tentativas = 0;
        while ($tentativas < 10) {
            $check = $pdo->prepare("SELECT id FROM bi_copilot_unidades WHERE codigo_unidade = :c LIMIT 1");
            $check->execute(['c' => $codigo]);
            if (!$check->fetch()) break;
            $seq    = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $codigo = "{$slug}-{$ano}-{$seq}";
            $tentativas++;
        }

        // Chave secreta HMAC (256 bits)
        $chaveSecreta = bin2hex(random_bytes(32));

        // URL e token do Copilot (opcionais, enviados pelo formulário)
        $copilotUrl      = trim($input['copilot_url']       ?? '');
        $copilotApiToken = trim($input['copilot_api_token'] ?? '');
        $modalidades     = trim($input['modalidades']       ?? '');

        try {
            $unidadeExistente = $this->getUnidade($pdo, $id);

            if ($unidadeExistente) {
                // Regenera código e chave, mantém contadores
                $pdo->prepare("
                    UPDATE bi_copilot_unidades SET
                        codigo_unidade    = :codigo,
                        chave_secreta     = :chave,
                        copilot_url       = :url,
                        copilot_api_token = :api_token,
                        modalidades       = :modalidades,
                        status            = 'ativo',
                        criado_por        = :uid,
                        updated_at        = NOW()
                    WHERE tenant_id = :tid
                ")->execute([
                    'codigo'      => $codigo,
                    'chave'       => $chaveSecreta,
                    'url'         => $copilotUrl ?: null,
                    'api_token'   => $copilotApiToken ?: null,
                    'modalidades' => $modalidades ?: null,
                    'uid'         => Auth::userId(),
                    'tid'         => $id,
                ]);
                $unidadeId = $unidadeExistente->id;
            } else {
                // Cria nova entrada
                $pdo->prepare("
                    INSERT INTO bi_copilot_unidades
                        (tenant_id, codigo_unidade, chave_secreta, copilot_url, copilot_api_token, modalidades, status, criado_por, created_at, updated_at)
                    VALUES
                        (:tid, :codigo, :chave, :url, :api_token, :modalidades, 'ativo', :uid, NOW(), NOW())
                ")->execute([
                    'tid'         => $id,
                    'codigo'      => $codigo,
                    'chave'       => $chaveSecreta,
                    'url'         => $copilotUrl ?: null,
                    'api_token'   => $copilotApiToken ?: null,
                    'modalidades' => $modalidades ?: null,
                    'uid'         => Auth::userId(),
                ]);
                $unidadeId = (int) $pdo->lastInsertId();
            }

            Logger::info("[CopilotIntegracao::gerarCodigo] tenant={$id} codigo={$codigo} unidade_id={$unidadeId}");

            $this->json([
                'ok'             => true,
                'msg'            => 'Código de unidade gerado com sucesso.',
                'codigo_unidade' => $codigo,
                'chave_secreta'  => $chaveSecreta,
                'unidade_id'     => $unidadeId,
            ]);
        } catch (\Throwable $e) {
            Logger::error('[CopilotIntegracao::gerarCodigo] ' . $e->getMessage(), ['tenant_id' => $id]);
            $this->json(['ok' => false, 'msg' => 'Erro ao gerar código: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /platform/negocios/{id}/copilot/medico/{mid}/gerar-token
    // Gera (ou regenera) o token de integração de um médico.
    // ─────────────────────────────────────────────────────────────────────────
    public function gerarTokenMedico(int $id, int $mid): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $pdo = Database::getInstance();

        // Verifica se a unidade Copilot existe para este negócio
        $unidade = $this->getUnidade($pdo, $id);
        if (!$unidade) {
            $this->json(['ok' => false, 'msg' => 'Gere primeiro o Código de Unidade para este negócio.'], 422);
            return;
        }

        // Busca dados do médico
        $medico = $pdo->prepare("SELECT * FROM bi_medicos WHERE id = :id AND tenant_id = :tid AND ativo = 1 LIMIT 1");
        $medico->execute(['id' => $mid, 'tid' => $id]);
        $medico = $medico->fetch(\PDO::FETCH_OBJ);
        if (!$medico) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        // Gera token único: prefixo + 48 bytes hex
        $token = 'VCOP-' . strtoupper(bin2hex(random_bytes(24)));

        try {
            // Verifica se já existe token para este médico nesta unidade
            $tokenExistente = $pdo->prepare("
                SELECT id FROM bi_copilot_medico_tokens
                WHERE medico_id = :mid AND unidade_id = :uid LIMIT 1
            ");
            $tokenExistente->execute(['mid' => $mid, 'uid' => $unidade->id]);
            $tokenExistente = $tokenExistente->fetch(\PDO::FETCH_OBJ);

            if ($tokenExistente) {
                // Regenera token
                $pdo->prepare("
                    UPDATE bi_copilot_medico_tokens SET
                        token_integracao    = :token,
                        token_expira_em     = NULL,
                        medico_nome         = :nome,
                        medico_crm          = :crm,
                        medico_crm_uf       = :crm_uf,
                        medico_especialidade = :esp,
                        medico_email        = :email,
                        status              = 'ativo',
                        gerado_por          = :uid,
                        updated_at          = NOW()
                    WHERE medico_id = :mid AND unidade_id = :unid
                ")->execute([
                    'token'  => $token,
                    'nome'   => $medico->nome,
                    'crm'    => $medico->crm ?? null,
                    'crm_uf' => $medico->crm_uf ?? null,
                    'esp'    => $medico->especialidade ?? null,
                    'email'  => $medico->email ?? null,
                    'uid'    => Auth::userId(),
                    'mid'    => $mid,
                    'unid'   => $unidade->id,
                ]);
            } else {
                // Cria novo token
                $pdo->prepare("
                    INSERT INTO bi_copilot_medico_tokens
                        (unidade_id, tenant_id, medico_id, token_integracao,
                         medico_nome, medico_crm, medico_crm_uf, medico_especialidade, medico_email,
                         status, gerado_por, created_at, updated_at)
                    VALUES
                        (:unid, :tid, :mid, :token,
                         :nome, :crm, :crm_uf, :esp, :email,
                         'ativo', :uid, NOW(), NOW())
                ")->execute([
                    'unid'   => $unidade->id,
                    'tid'    => $id,
                    'mid'    => $mid,
                    'token'  => $token,
                    'nome'   => $medico->nome,
                    'crm'    => $medico->crm ?? null,
                    'crm_uf' => $medico->crm_uf ?? null,
                    'esp'    => $medico->especialidade ?? null,
                    'email'  => $medico->email ?? null,
                    'uid'    => Auth::userId(),
                ]);
            }

            Logger::info("[CopilotIntegracao::gerarTokenMedico] tenant={$id} medico_id={$mid} token_prefix=VCOP-...");

            $this->json([
                'ok'              => true,
                'msg'             => 'Token gerado com sucesso.',
                'token_integracao'=> $token,
                'codigo_unidade'  => $unidade->codigo_unidade,
                'medico_nome'     => $medico->nome,
            ]);
        } catch (\Throwable $e) {
            Logger::error('[CopilotIntegracao::gerarTokenMedico] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao gerar token: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /platform/negocios/{id}/copilot/medico/{mid}/revogar
    // ─────────────────────────────────────────────────────────────────────────
    public function revogarTokenMedico(int $id, int $mid): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }
        $pdo = Database::getInstance();
        try {
            $pdo->prepare("
                UPDATE bi_copilot_medico_tokens SET status = 'revogado', updated_at = NOW()
                WHERE medico_id = :mid AND tenant_id = :tid
            ")->execute(['mid' => $mid, 'tid' => $id]);
            $this->json(['ok' => true, 'msg' => 'Token revogado.']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /platform/api/negocios/{id}/copilot/status
    // JSON com status da integração (usado pelo frontend para atualizar a aba)
    // ─────────────────────────────────────────────────────────────────────────
    public function apiStatus(int $id): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false], 401);
            return;
        }
        $pdo     = Database::getInstance();
        $unidade = $this->getUnidade($pdo, $id);
        if (!$unidade) {
            $this->json(['ok' => true, 'integrado' => false]);
            return;
        }
        // Contagem de médicos com token ativo
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bi_copilot_medico_tokens
            WHERE unidade_id = :uid AND status = 'ativo'
        ");
        $stmt->execute(['uid' => $unidade->id]);
        $totalMedicos = (int) $stmt->fetchColumn();

        $this->json([
            'ok'             => true,
            'integrado'      => true,
            'codigo_unidade' => $unidade->codigo_unidade,
            'status'         => $unidade->status,
            'total_medicos'  => $totalMedicos,
            'total_exames'   => $unidade->total_exames_sync,
            'total_laudos'   => $unidade->total_laudos_recv,
            'ultimo_sync'    => $unidade->ultimo_sync,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: busca a configuração Copilot de um tenant
    // ─────────────────────────────────────────────────────────────────────────
    private function getUnidade(\PDO $pdo, int $tenantId): ?\stdClass
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM bi_copilot_unidades WHERE tenant_id = :tid LIMIT 1");
            $stmt->execute(['tid' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_OBJ);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: decodifica body JSON
    // ─────────────────────────────────────────────────────────────────────────
    private function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return $_POST;
    }
}
