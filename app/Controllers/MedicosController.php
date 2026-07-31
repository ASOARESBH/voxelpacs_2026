<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\MedicoService;

/**
 * MedicosController — enxuto, sem SQL, sem regras de negócio.
 * Toda lógica está em MedicoService; toda SQL está em MedicoRepository.
 */
class MedicosController extends Controller
{
    private MedicoService $service;

    public function __construct()
    {
        $this->service = new MedicoService();
    }

    // -------------------------------------------------------------------------
    // Guard de tenant: garante que o controller nunca opera sem tenant_id
    // -------------------------------------------------------------------------
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            Logger::error('[MedicosController] Acesso sem tenant_id — redirecionando', [
                'user_id'  => Auth::userId(),
                'is_admin' => Auth::isPlatformAdmin(),
                'uri'      => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $id;
    }

    // -------------------------------------------------------------------------
    // READ — Listagem
    // -------------------------------------------------------------------------

    public function index(): void
    {
        $tenantId     = $this->tenantId();
        $busca        = trim($_GET['busca'] ?? '');
        $pagina       = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina    = 20;

        $medicos      = $this->service->listar($tenantId, $busca, $pagina, $porPagina);
        $total        = $this->service->total($tenantId, $busca);
        $totalPaginas = (int) ceil($total / $porPagina);

        Logger::error('[MedicosController::index] Listagem carregada', [
            'tenant_id' => $tenantId,
            'busca'     => $busca,
            'pagina'    => $pagina,
            'total'     => $total,
        ]);

        $this->view('medicos/index', [
            'title'        => 'Médicos',
            'medicos'      => $medicos,
            'busca'        => $busca,
            'pagina'       => $pagina,
            'totalPaginas' => $totalPaginas,
            'total'        => $total,
        ]);
    }

    // -------------------------------------------------------------------------
    // CREATE — Formulário + Persistência
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $tenantId = $this->tenantId();

        // Recupera erros e dados do POST anterior (se houver redirect de volta)
        $erros     = $_SESSION['form_erros'] ?? [];
        $formDados = $_SESSION['form_dados'] ?? [];
        unset($_SESSION['form_erros'], $_SESSION['form_dados']);

        $form = $this->service->dadosFormulario($tenantId);

        $this->view('medicos/form', array_merge($form, [
            'title'     => 'Novo Médico',
            'medico'    => $formDados ?: null,
            'erros'     => $erros,
        ]));
    }

    public function store(): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::store] Início do cadastro', [
            'tenant_id' => $tenantId,
            'post_nome' => $_POST['nome'] ?? '(vazio)',
            'post_crm'  => $_POST['crm'] ?? '(vazio)',
            'post_keys' => implode(',', array_keys($_POST)),
        ]);

        $resultado = $this->service->cadastrar($_POST, $tenantId);

        if (!$resultado['ok']) {
            Logger::error('[MedicosController::store] Cadastro falhou — redirecionando com erros', [
                'tenant_id' => $tenantId,
                'erros'     => $resultado['erros'],
            ]);
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            $this->redirect('/medicos/create?error=validacao');
            return;
        }

        $_SESSION['success'] = 'Médico cadastrado com sucesso!';
        Logger::error('[MedicosController::store] Cadastro concluído com sucesso', [
            'tenant_id' => $tenantId,
            'medico_id' => $resultado['id'],
        ]);
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // READ — Edição
    // -------------------------------------------------------------------------

    public function edit(int $id): void
    {
        $tenantId = $this->tenantId();
        $medico   = $this->service->buscarPorId($id, $tenantId);

        if (!$medico) {
            Logger::error('[MedicosController::edit] Médico não encontrado ou tenant inválido', [
                'id'        => $id,
                'tenant_id' => $tenantId,
            ]);
            http_response_code(404);
            exit('Médico não encontrado.');
        }

        $erros     = $_SESSION['form_erros'] ?? [];
        $formDados = $_SESSION['form_dados'] ?? [];
        unset($_SESSION['form_erros'], $_SESSION['form_dados']);

        // Se houver dados do POST anterior (após redirect de erro), mescla com os dados do banco
        if ($formDados) {
            $medico = array_merge($medico, $formDados);
        }

                $form = $this->service->dadosFormulario($tenantId, $id);

        // Busca token Copilot existente para este médico
        $copilotToken   = null;
        $copilotUnidade = null;
        try {
            $pdo = Database::getInstance();
            $tkStmt = $pdo->prepare("
                SELECT t.*, u.codigo_unidade, u.chave_secreta, u.copilot_url
                FROM bi_copilot_medico_tokens t
                JOIN bi_copilot_unidades u ON u.id = t.unidade_id
                WHERE t.medico_id = :mid AND t.tenant_id = :tid
                ORDER BY t.created_at DESC LIMIT 1
            ");
            $tkStmt->execute(['mid' => $id, 'tid' => $tenantId]);
            $row = $tkStmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $copilotToken   = $row;
                $copilotUnidade = ['codigo_unidade' => $row['codigo_unidade']];
            }
        } catch (\Throwable $e) {
            // Tabela pode não existir ainda
        }

        $this->view('medicos/form', array_merge($form, [
            'title'          => 'Editar Médico',
            'medico'         => $medico,
            'erros'          => $erros,
            'copilotToken'   => $copilotToken,
            'copilotUnidade' => $copilotUnidade,
        ]));
    }

    // -------------------------------------------------------------------------
    // UPDATE — Persistência da edição
    // -------------------------------------------------------------------------

    public function update(int $id): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::update] Início da atualização', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
            'post_nome' => $_POST['nome'] ?? '(vazio)',
        ]);

        $resultado = $this->service->atualizar($id, $_POST, $tenantId);

        if (!$resultado['ok']) {
            Logger::error('[MedicosController::update] Atualização falhou', [
                'tenant_id' => $tenantId,
                'medico_id' => $id,
                'erros'     => $resultado['erros'],
            ]);
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            $this->redirect("/medicos/{$id}/edit?error=validacao");
            return;
        }

        $_SESSION['success'] = 'Médico atualizado com sucesso!';
        Logger::error('[MedicosController::update] Atualização concluída', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
        ]);
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // DELETE / TOGGLE STATUS — Soft delete
    // -------------------------------------------------------------------------

    public function toggleStatus(int $id): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::toggleStatus] Alternando status', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
        ]);

        $this->service->toggleStatus($id, $tenantId);
        $_SESSION['success'] = 'Status do médico atualizado.';
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // API — Busca de CEP (ViaCEP)
    // -------------------------------------------------------------------------

    public function buscarCep(string $cep): void
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            $this->json(['error' => 'CEP inválido'], 400);
            return;
        }

        $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            Logger::error('[MedicosController::buscarCep] ViaCEP indisponível', ['cep' => $cep, 'http' => $httpCode]);
            $this->json(['error' => 'Erro ao consultar o CEP.'], 502);
            return;
        }

        $data = json_decode($response, true);
        if (!$data || !empty($data['erro'])) {
            $this->json(['error' => 'CEP não encontrado.'], 404);
            return;
        }

        $this->json([
            'cep'         => $data['cep'] ?? $cep,
            'logradouro'  => $data['logradouro'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'bairro'      => $data['bairro'] ?? '',
            'cidade'      => $data['localidade'] ?? '',
            'estado'      => $data['uf'] ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // API — TOKEN COPILOT (AJAX)
    // POST /api/medicos/{id}/copilot-token
    // Body JSON: { "acao": "gerar" | "regenerar" | "revogar" }
    // -------------------------------------------------------------------------
    public function copilotToken(int $id): void
    {
        $tenantId = $this->tenantId();

        // Verifica se o médico pertence ao tenant
        $medico = $this->service->buscarPorId($id, $tenantId);
        if (!$medico) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $acao = $body['acao'] ?? 'gerar';

        $pdo = Database::getInstance();

        // ── Garante que a unidade Copilot existe para este tenant ────────────────────────
        try {
            $unidadeStmt = $pdo->prepare("
                SELECT id, codigo_unidade, chave_secreta, copilot_url
                FROM bi_copilot_unidades WHERE tenant_id = :tid LIMIT 1
            ");
            $unidadeStmt->execute(['tid' => $tenantId]);
            $unidade = $unidadeStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$unidade) {
                // Cria a unidade automaticamente a partir dos dados do tenant
                $tenantStmt = $pdo->prepare("SELECT * FROM bi_tenants WHERE id = :id LIMIT 1");
                $tenantStmt->execute(['id' => $tenantId]);
                $tenant = $tenantStmt->fetch(\PDO::FETCH_ASSOC) ?? [];

                Logger::info('[MedicosController::copilotToken] Dados do tenant para gerar código', [
                    'tenant_id'     => $tenantId,
                    'tenant_estado' => $tenant['estado'] ?? null,
                    'tenant_cidade' => $tenant['cidade'] ?? null,
                    'tenant_nome'   => $tenant['nome_fantasia'] ?? $tenant['razao_social'] ?? null,
                ]);

                // Gera código robusto: usa apenas o tenant_id se cidade/estado estiverem vazios
                $uf     = strtoupper(trim($tenant['estado'] ?? ''));
                $cidade = strtoupper(trim($tenant['cidade'] ?? ''));

                if ($uf && $cidade) {
                    // Remove acentos e caracteres especiais
                    $cidadeLimpa = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cidade);
                    $cidadeLimpa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $cidadeLimpa));
                    $cidadeLimpa = substr($cidadeLimpa, 0, 6);
                    $codigoUnid  = 'PACS-' . $uf . '-' . $cidadeLimpa . '-' . date('Y') . '-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT);
                } else {
                    // Fallback: usa apenas o tenant_id + hash curto para garantir unicidade
                    $codigoUnid = 'PACS-' . date('Y') . '-' . str_pad($tenantId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                }

                $chaveSecreta = bin2hex(random_bytes(32));

                Logger::info('[MedicosController::copilotToken] Criando bi_copilot_unidades', [
                    'tenant_id'      => $tenantId,
                    'codigo_unidade' => $codigoUnid,
                ]);

                $pdo->prepare("
                    INSERT INTO bi_copilot_unidades
                        (tenant_id, codigo_unidade, chave_secreta, status, criado_por, created_at, updated_at)
                    VALUES
                        (:tid, :cod, :chave, 'ativo', :criado_por, NOW(), NOW())
                ")->execute([
                    'tid'        => $tenantId,
                    'cod'        => $codigoUnid,
                    'chave'      => $chaveSecreta,
                    'criado_por' => Auth::userId(),
                ]);
                $unidadeId = (int) $pdo->lastInsertId();
                $unidade   = ['id' => $unidadeId, 'codigo_unidade' => $codigoUnid, 'chave_secreta' => $chaveSecreta];

                Logger::info('[MedicosController::copilotToken] bi_copilot_unidades criada', [
                    'unidade_id'     => $unidadeId,
                    'codigo_unidade' => $codigoUnid,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('[MedicosController::copilotToken] Erro ao acessar bi_copilot_unidades', [
                'tenant_id' => $tenantId,
                'medico_id' => $id,
                'erro'      => $e->getMessage(),
                'arquivo'   => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Erro ao acessar tabela bi_copilot_unidades. Execute a migration 2026-07-31_copilot_integracao.sql. Detalhe: ' . $e->getMessage()], 500);
            return;
        }

        $unidadeId = (int) $unidade['id'];

        // ── Busca token existente ─────────────────────────────────────────────────────────────────
        $tkStmt = $pdo->prepare("
            SELECT id, token_integracao, status FROM bi_copilot_medico_tokens
            WHERE medico_id = :mid AND tenant_id = :tid LIMIT 1
        ");
        $tkStmt->execute(['mid' => $id, 'tid' => $tenantId]);
        $tokenExistente = $tkStmt->fetch(\PDO::FETCH_ASSOC);

        // Dados do médico (snapshot)
        $medicoArr = is_array($medico) ? $medico : (array) $medico;
        $medicoNome = $medicoArr['nome'] ?? '';
        $medicoCrm  = $medicoArr['crm'] ?? '';
        $medicoCrmUf = $medicoArr['crm_uf'] ?? '';
        $medicoEsp  = $medicoArr['especialidade'] ?? '';
        $medicoEmail = $medicoArr['email'] ?? '';

        Logger::info('[MedicosController::copilotToken] Iniciando acao', [
            'tenant_id'      => $tenantId,
            'medico_id'      => $id,
            'acao'           => $acao,
            'unidade_id'     => $unidadeId,
            'codigo_unidade' => $unidade['codigo_unidade'],
            'token_existente'=> $tokenExistente ? $tokenExistente['id'] : null,
        ]);

        // ── Ação: REVOGAR ──────────────────────────────────────────────────────────────────────────
        if ($acao === 'revogar') {
            if (!$tokenExistente) {
                $this->json(['ok' => false, 'msg' => 'Nenhum token para revogar.']);
                return;
            }
            $pdo->prepare("
                UPDATE bi_copilot_medico_tokens
                SET status = 'revogado', motivo_revogacao = 'Revogado manualmente', updated_at = NOW()
                WHERE id = :id
            ")->execute(['id' => $tokenExistente['id']]);
            Logger::info('[MedicosController::copilotToken] Token revogado', [
                'token_id'  => $tokenExistente['id'],
                'medico_id' => $id,
                'by_user'   => Auth::userId(),
            ]);
            $this->json(['ok' => true, 'msg' => 'Token revogado com sucesso.']);
            return;
        }

        // ── Ação: REGENERAR ─────────────────────────────────────────────────────────────────────────
        $novoToken = 'CPLT-' . strtoupper(bin2hex(random_bytes(20)));

        if ($acao === 'regenerar' && $tokenExistente) {
            $pdo->prepare("
                UPDATE bi_copilot_medico_tokens
                SET token_integracao = :token,
                    status = 'ativo',
                    medico_nome = :nome, medico_crm = :crm, medico_crm_uf = :uf,
                    medico_especialidade = :esp, medico_email = :email,
                    gerado_por = :uid, updated_at = NOW()
                WHERE id = :id
            ")->execute([
                'token' => $novoToken,
                'nome'  => $medicoNome,
                'crm'   => $medicoCrm,
                'uf'    => $medicoCrmUf,
                'esp'   => $medicoEsp,
                'email' => $medicoEmail,
                'uid'   => Auth::userId(),
                'id'    => $tokenExistente['id'],
            ]);
            Logger::info('[MedicosController::copilotToken] Token regenerado', [
                'token_id'       => $tokenExistente['id'],
                'medico_id'      => $id,
                'codigo_unidade' => $unidade['codigo_unidade'],
                'by_user'        => Auth::userId(),
            ]);
            $this->json([
                'ok'               => true,
                'token_integracao' => $novoToken,
                'codigo_unidade'   => $unidade['codigo_unidade'],
                'msg'              => 'Token regenerado com sucesso.',
            ]);
            return;
        }

        // ── Ação: GERAR (novo) ─────────────────────────────────────────────────────────────────────
        if ($tokenExistente) {
            // Já existe: retorna o atual sem criar novo
            Logger::info('[MedicosController::copilotToken] Token já existia, retornando atual', [
                'token_id'       => $tokenExistente['id'],
                'medico_id'      => $id,
                'codigo_unidade' => $unidade['codigo_unidade'],
            ]);
            $this->json([
                'ok'               => true,
                'token_integracao' => $tokenExistente['token_integracao'],
                'codigo_unidade'   => $unidade['codigo_unidade'],
                'msg'              => 'Token já existe. Use Regenerar para criar um novo.',
            ]);
            return;
        }

        // Cria novo token
        $pdo->prepare("
            INSERT INTO bi_copilot_medico_tokens
                (unidade_id, tenant_id, medico_id, token_integracao,
                 medico_nome, medico_crm, medico_crm_uf, medico_especialidade, medico_email,
                 status, gerado_por, created_at, updated_at)
            VALUES
                (:uid, :tid, :mid, :token,
                 :nome, :crm, :cuf, :esp, :email,
                 'ativo', :gerado_por, NOW(), NOW())
        ")->execute([
            'uid'        => $unidadeId,
            'tid'        => $tenantId,
            'mid'        => $id,
            'token'      => $novoToken,
            'nome'       => $medicoNome,
            'crm'        => $medicoCrm,
            'cuf'        => $medicoCrmUf,
            'esp'        => $medicoEsp,
            'email'      => $medicoEmail,
            'gerado_por' => Auth::userId(),
        ]);

        $novoTokenId = (int) $pdo->lastInsertId();

        Logger::info('[MedicosController::copilotToken] Novo token criado com sucesso', [
            'token_id'       => $novoTokenId,
            'medico_id'      => $id,
            'unidade_id'     => $unidadeId,
            'codigo_unidade' => $unidade['codigo_unidade'],
            'medico_nome'    => $medicoNome,
            'medico_crm'     => $medicoCrm . '/' . $medicoCrmUf,
            'by_user'        => Auth::userId(),
        ]);

        $this->json([
            'ok'               => true,
            'token_integracao' => $novoToken,
            'codigo_unidade'   => $unidade['codigo_unidade'],
            'msg'              => 'Token gerado com sucesso.',
        ]);
    }
}
