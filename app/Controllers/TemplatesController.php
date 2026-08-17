<?php
namespace App\Controllers;

use App\Core\Access\MedicoAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\MascaraDocxImportService;

/**
 * TemplatesController — Módulo de Máscaras/Templates de Laudo
 *
 * Rotas:
 *   GET  /api/medicos/{medicoId}/templates          → listar()
 *   POST /api/medicos/{medicoId}/templates          → salvar()
 *   PUT  /api/medicos/{medicoId}/templates/{id}     → salvar() (com id no body)
 *   DELETE /api/medicos/{medicoId}/templates/{id}   → excluir()
 *   POST /api/medicos/{medicoId}/templates/importar/analisar  → analisar() (prévia DOCX)
 *   POST /api/medicos/{medicoId}/templates/importar/confirmar → confirmar() (persistência revisada)
 *   POST /api/medicos/{medicoId}/templates/importar           → importar() (alias legado da prévia)
 *   GET  /api/templates/buscar                                 → buscar() (para o laudário)
 *   GET  /api/templates/auto                        → autoCarregar() (por study_description)
 */
class TemplatesController extends Controller
{
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            $this->json(['ok' => false, 'msg' => 'Tenant não identificado.'], 403);
            exit;
        }
        return $id;
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return $_POST;
    }

    /**
     * Preserva somente marcação clínica produzida pela toolbar comum do Quill.
     * Atributos são eliminados para impedir estilos, URLs e handlers executáveis
     * vindos de colagem ou importação de DOCX.
     */
    private function sanitizeSectionHtml($value): string
    {
        $html = (string) $value;
        $allowed = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><thead><tbody><tr><th><td>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/<(?:strong|b)\\b[^>]*>/i', '<strong>', $html) ?? '';
        $html = preg_replace('/<\\/(?:strong|b)>/i', '</strong>', $html) ?? '';
        $html = preg_replace('/<(?:em|i)\\b[^>]*>/i', '<em>', $html) ?? '';
        $html = preg_replace('/<\\/(?:em|i)>/i', '</em>', $html) ?? '';
        $html = preg_replace_callback('/<(p|h[1-6]|ul|ol|li|table|thead|tbody|tr|th|td|u|br)\\b[^>]*>/i', static function (array $match): string {
            return '<' . strtolower($match[1]) . '>';
        }, $html) ?? '';
        return trim($html);
    }

    /** Bloqueia médico restrito de consultar ou manipular máscaras de outro cadastro. */
    private function guardOwnMedicoOrDeny(int $medicoId): bool
    {
        if (!MedicoAccess::isRestricted() || MedicoAccess::currentMedicoId() === $medicoId) {
            return true;
        }

        Logger::error('[TemplatesController] Tentativa de acesso a máscaras de outro médico', [
            'tenant_id' => Auth::tenantId(),
            'user_id' => Auth::userId(),
            'medico_id_solicitado' => $medicoId,
        ]);
        $this->json(['ok' => false, 'msg' => 'Acesso negado: você só pode gerenciar as próprias máscaras.'], 403);
        return false;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/medicos/{medicoId}/templates
    // Lista templates do médico + compartilhados da unidade
    // ══════════════════════════════════════════════════════════════════════════
    public function listar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $tenantId = $this->tenantId();
        try {
            $pdo = Database::getInstance();
            // Próprios do médico + compartilhados da unidade (de outros médicos)
            $stmt = $pdo->prepare("
                SELECT t.*,
                       m.nome AS medico_nome
                FROM report_templates t
                LEFT JOIN bi_medicos m ON m.id = t.medico_id
                WHERE t.tenant_id = :tid
                  AND t.ativo = 1
                  AND (t.medico_id = :mid OR t.compartilhar = 1 OR t.medico_id IS NULL)
                ORDER BY t.medico_id = :mid2 DESC, t.nome ASC
            ");
            $stmt->execute(['tid' => $tenantId, 'mid' => $medicoId, 'mid2' => $medicoId]);
            $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->json(['ok' => true, 'templates' => $templates]);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::listar error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao listar templates.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /medicos/{medicoId}/mascaras/{mascaraId}/visualizar
    // Prévia somente leitura: máscara própria, compartilhada ou global do tenant.
    // Não incrementa uso_count nem escreve no banco.
    // ══════════════════════════════════════════════════════════════════════════
    public function visualizar(int $medicoId, int $mascaraId): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;

        $tenantId = $this->tenantId();
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT id, nome, modalidade, compartilhar, study_description_tag, conteudo_livre,
                       secao_tecnica, secao_achados, secao_conclusao, medico_id
                FROM report_templates
                WHERE id = :id
                  AND tenant_id = :tid
                  AND ativo = 1
                  AND (medico_id = :mid OR compartilhar = 1 OR medico_id IS NULL)
                LIMIT 1
            ");
            $stmt->execute(['id' => $mascaraId, 'tid' => $tenantId, 'mid' => $medicoId]);
            $mascara = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$mascara) {
                Logger::warning('TemplatesController::visualizar acesso negado ou máscara ausente', [
                    'tenant_id' => $tenantId,
                    'user_id' => Auth::userId(),
                    'medico_id' => $medicoId,
                    'mascara_id' => $mascaraId,
                ]);
                http_response_code(404);
                $this->view('reports/error', [
                    'mensagem' => 'Máscara não encontrada ou você não tem permissão para visualizá-la.',
                ], 'pacs');
                return;
            }

            $this->view('mascaras/visualizar', [
                'mascara' => $mascara,
                'medicoId' => $medicoId,
            ], 'none');
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::visualizar error', [
                'msg' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'medico_id' => $medicoId,
                'mascara_id' => $mascaraId,
            ]);
            http_response_code(500);
            $this->view('reports/error', [
                'mensagem' => 'Erro ao abrir a pré-visualização da máscara.',
            ], 'pacs');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/medicos/{medicoId}/templates
    // Cria ou atualiza template
    // ══════════════════════════════════════════════════════════════════════════
    public function salvar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $tenantId = $this->tenantId();
        $input    = $this->getJsonInput();

        $id                  = (int) ($input['id'] ?? 0);
        $nome                = trim($input['nome'] ?? '');
        $modalidade          = trim($input['modalidade'] ?? '');
        $compartilhar        = (int) ($input['compartilhar'] ?? 0);
        $studyDescriptionTag = trim($input['study_description_tag'] ?? '');
        $conteudoLivre       = $this->sanitizeSectionHtml($input['conteudo_livre'] ?? '');
        $secaoExame          = (string) ($input['secao_exame']        ?? '');
        $secaoTecnica        = $this->sanitizeSectionHtml($input['secao_tecnica'] ?? '');
        $secaoAchados        = $this->sanitizeSectionHtml($input['secao_achados'] ?? '');
        $secaoConclusao      = $this->sanitizeSectionHtml($input['secao_conclusao'] ?? '');
        $secaoRecomendacao   = (string) ($input['secao_recomendacao'] ?? '');

        if (!$nome) {
            $this->json(['ok' => false, 'msg' => 'Nome do template é obrigatório.'], 422);
            return;
        }

        try {
            $pdo = Database::getInstance();

            if ($id > 0) {
                // Os campos Exame/Recomendação não são mais editáveis no modal.
                // Preserva os valores antigos para não destruir máscaras legadas.
                $legacy = $pdo->prepare("
                    SELECT secao_exame, secao_recomendacao
                    FROM report_templates
                    WHERE id = :id AND medico_id = :mid AND tenant_id = :tid
                    LIMIT 1
                ");
                $legacy->execute(['id' => $id, 'mid' => $medicoId, 'tid' => $tenantId]);
                $legacyTemplate = $legacy->fetch(\PDO::FETCH_ASSOC);
                if (!$legacyTemplate) {
                    $this->json(['ok' => false, 'msg' => 'Máscara não encontrada ou sem permissão de edição.'], 404);
                    return;
                }
                $secaoExame = (string) ($legacyTemplate['secao_exame'] ?? '');
                $secaoRecomendacao = (string) ($legacyTemplate['secao_recomendacao'] ?? '');

                // Atualizar — garante que pertence ao médico e tenant
                $stmt = $pdo->prepare("
                    UPDATE report_templates SET
                        nome                 = :nome,
                        modalidade           = :modalidade,
                        compartilhar         = :compartilhar,
                        study_description_tag = :study_desc,
                        conteudo_livre        = :conteudo_livre,
                        secao_exame          = :s_exame,
                        secao_tecnica        = :s_tecnica,
                        secao_achados        = :s_achados,
                        secao_conclusao      = :s_conclusao,
                        secao_recomendacao   = :s_recomendacao
                    WHERE id = :id AND medico_id = :mid AND tenant_id = :tid
                ");
                $stmt->execute([
                    'nome'           => $nome,
                    'modalidade'     => $modalidade,
                    'compartilhar'   => $compartilhar,
                    'study_desc'     => $studyDescriptionTag ?: null,
                    'conteudo_livre' => $conteudoLivre !== '' ? $conteudoLivre : null,
                    's_exame'        => $secaoExame,
                    's_tecnica'      => $secaoTecnica,
                    's_achados'      => $secaoAchados,
                    's_conclusao'    => $secaoConclusao,
                    's_recomendacao' => $secaoRecomendacao,
                    'id'             => $id,
                    'mid'            => $medicoId,
                    'tid'            => $tenantId,
                ]);
                Logger::info('TemplatesController::salvar update', ['id' => $id, 'medico_id' => $medicoId]);
                $this->json(['ok' => true, 'id' => $id, 'msg' => 'Template atualizado.']);
            } else {
                // Criar novo
                $stmt = $pdo->prepare("
                    INSERT INTO report_templates
                        (tenant_id, medico_id, origem, arquivo_origem, revisar,
                         nome, modalidade, compartilhar, study_description_tag, conteudo_livre,
                         secao_exame, secao_tecnica, secao_achados,
                         secao_conclusao, secao_recomendacao, ativo)
                    VALUES
                        (:tid, :mid, 'manual', NULL, 0,
                         :nome, :modalidade, :compartilhar, :study_desc, :conteudo_livre,
                         :s_exame, :s_tecnica, :s_achados,
                         :s_conclusao, :s_recomendacao, 1)
                ");
                $stmt->execute([
                    'tid'            => $tenantId,
                    'mid'            => $medicoId,
                    'nome'           => $nome,
                    'modalidade'     => $modalidade,
                    'compartilhar'   => $compartilhar,
                    'study_desc'     => $studyDescriptionTag ?: null,
                    'conteudo_livre' => $conteudoLivre !== '' ? $conteudoLivre : null,
                    's_exame'        => $secaoExame,
                    's_tecnica'      => $secaoTecnica,
                    's_achados'      => $secaoAchados,
                    's_conclusao'    => $secaoConclusao,
                    's_recomendacao' => $secaoRecomendacao,
                ]);
                $newId = (int) $pdo->lastInsertId();
                Logger::info('TemplatesController::salvar insert', ['id' => $newId, 'medico_id' => $medicoId]);
                $this->json(['ok' => true, 'id' => $newId, 'msg' => 'Template criado com sucesso.']);
            }
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::salvar error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar template.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE /api/medicos/{medicoId}/templates/{id}
    // Soft-delete (ativo = 0)
    // ══════════════════════════════════════════════════════════════════════════
    public function excluir(int $medicoId, int $id): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $tenantId = $this->tenantId();
        try {
            $pdo = Database::getInstance();
            $pdo->prepare("UPDATE report_templates SET ativo = 0 WHERE id = :id AND medico_id = :mid AND tenant_id = :tid")
                ->execute(['id' => $id, 'mid' => $medicoId, 'tid' => $tenantId]);
            Logger::info('TemplatesController::excluir', ['id' => $id, 'medico_id' => $medicoId]);
            $this->json(['ok' => true, 'msg' => 'Template removido.']);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::excluir error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao excluir template.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/medicos/{medicoId}/templates/importar/analisar
    // Analisa DOCX sem persistir nenhuma máscara.
    // ══════════════════════════════════════════════════════════════════════════
    public function analisar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $this->tenantId();

        try {
            $arquivo = $this->validarUploadDocx($_FILES['arquivo'] ?? null);
            $mascaras = (new MascaraDocxImportService())->analisar($arquivo['tmp_name']);
            $totalRevisar = count(array_filter($mascaras, static fn(array $mascara): bool => !empty($mascara['revisar'])));

            $this->json([
                'ok' => true,
                'mascaras' => $mascaras,
                'arquivo_nome' => $arquivo['nome'],
                'total' => count($mascaras),
                'total_revisar' => $totalRevisar,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::analisar error', [
                'medico_id' => $medicoId,
                'msg' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível analisar o DOCX enviado.'], 500);
        }
    }

    // Mantém compatibilidade com a rota antiga, mas nunca grava antes da revisão.
    public function importar(int $medicoId): void
    {
        $this->analisar($medicoId);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/medicos/{medicoId}/templates/importar/confirmar
    // Persiste somente máscaras selecionadas e revisadas pelo médico.
    // ══════════════════════════════════════════════════════════════════════════
    public function confirmar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $tenantId = $this->tenantId();
        $input = $this->getJsonInput();

        try {
            $mascaras = $this->prepararMascarasImportacao($input['mascaras'] ?? []);
            $arquivoOrigem = $this->sanitizarArquivoOrigem($input['arquivo_nome'] ?? 'mascaras.docx');
            $totalRevisar = count(array_filter($mascaras, static fn(array $mascara): bool => $mascara['revisar'] === 1));

            $pdo = Database::getInstance();
            $pdo->beginTransaction();
            try {
                $existe = $pdo->prepare(
                    'SELECT id FROM report_templates
                     WHERE tenant_id = :tid AND medico_id = :mid AND nome = :nome AND ativo = 1
                     LIMIT 1'
                );
                $inserir = $pdo->prepare(
                    'INSERT INTO report_templates
                        (tenant_id, medico_id, origem, arquivo_origem, revisar,
                         nome, modalidade, compartilhar, study_description_tag, conteudo_livre,
                         secao_exame, secao_tecnica, secao_achados,
                         secao_conclusao, secao_recomendacao, ativo)
                     VALUES
                        (:tid, :mid, \'importado\', :arquivo_origem, :revisar,
                         :nome, :modalidade, 0, :study_description_tag, :conteudo_livre,
                         \'\', :secao_tecnica, :secao_achados,
                         :secao_conclusao, \'\', 1)'
                );

                $importados = 0;
                $ignorados = 0;
                foreach ($mascaras as $mascara) {
                    $existe->execute([
                        'tid' => $tenantId,
                        'mid' => $medicoId,
                        'nome' => $mascara['nome'],
                    ]);
                    if ($existe->fetchColumn()) {
                        $ignorados++;
                        continue;
                    }

                    $inserir->execute([
                        'tid' => $tenantId,
                        'mid' => $medicoId,
                        'arquivo_origem' => $arquivoOrigem,
                        'revisar' => $mascara['revisar'],
                        'nome' => $mascara['nome'],
                        'modalidade' => $mascara['modalidade'],
                        'study_description_tag' => $mascara['study_description_tag'],
                        'conteudo_livre' => $mascara['conteudo_livre'],
                        'secao_tecnica' => $mascara['secao_tecnica'],
                        'secao_achados' => $mascara['secao_achados'],
                        'secao_conclusao' => $mascara['secao_conclusao'],
                    ]);
                    $importados++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // Um único evento resume o lote; nunca há auditoria por máscara.
            AuditLogger::log('importar_mascaras_docx', 'medico', $medicoId, [
                'arquivo' => $arquivoOrigem,
                'total_selecionadas' => count($mascaras),
                'total_importadas' => $importados,
                'total_ignoradas_duplicadas' => $ignorados,
                'total_revisar' => $totalRevisar,
            ], $tenantId);

            $this->json([
                'ok' => true,
                'importados' => $importados,
                'ignorados' => $ignorados,
                'msg' => $importados . ' máscara(s) importada(s) com sucesso.',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::confirmar error', [
                'medico_id' => $medicoId,
                'msg' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível confirmar a importação das máscaras.'], 500);
        }
    }

    /** @return array{tmp_name:string,nome:string} */
    private function validarUploadDocx(mixed $arquivo): array
    {
        if (!is_array($arquivo) || !isset($arquivo['error'], $arquivo['tmp_name'], $arquivo['name'])) {
            throw new \InvalidArgumentException('Selecione um arquivo DOCX para análise.');
        }
        if ((int) $arquivo['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('O upload do DOCX falhou. Selecione o arquivo novamente.');
        }
        if (!is_uploaded_file((string) $arquivo['tmp_name'])) {
            throw new \InvalidArgumentException('Upload DOCX inválido.');
        }
        if ((int) ($arquivo['size'] ?? 0) <= 0 || (int) ($arquivo['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new \InvalidArgumentException('O DOCX deve ter entre 1 byte e 15 MB.');
        }

        $nome = $this->sanitizarArquivoOrigem($arquivo['name']);
        if (strtolower(substr($nome, -5)) !== '.docx') {
            throw new \InvalidArgumentException('Formato inválido. Envie exclusivamente um arquivo DOCX.');
        }

        $handle = @fopen((string) $arquivo['tmp_name'], 'rb');
        $assinatura = $handle ? fread($handle, 4) : false;
        if (is_resource($handle)) fclose($handle);
        if ($assinatura !== "PK\x03\x04") {
            throw new \InvalidArgumentException('Arquivo DOCX inválido: a assinatura ZIP esperada não foi encontrada.');
        }

        return ['tmp_name' => (string) $arquivo['tmp_name'], 'nome' => $nome];
    }

    /** @return array<int,array{nome:string,modalidade:string,study_description_tag:string,secao_tecnica:string,secao_achados:string,secao_conclusao:string,revisar:int}> */
    private function prepararMascarasImportacao(mixed $mascaras): array
    {
        if (!is_array($mascaras) || !$mascaras) {
            throw new \InvalidArgumentException('Selecione ao menos uma máscara para importar.');
        }
        if (count($mascaras) > 100) {
            throw new \InvalidArgumentException('O lote de importação aceita no máximo 100 máscaras.');
        }

        $preparadas = [];
        $nomesRecebidos = [];
        foreach ($mascaras as $mascara) {
            if (!is_array($mascara)) continue;
            $nome = trim(strip_tags((string) ($mascara['nome'] ?? '')));
            $nome = substr($nome, 0, 255);
            if ($nome === '') continue;

            // A mesma máscara pode ser marcada duas vezes no browser; não deixe
            // uma seleção repetida gerar duas linhas no mesmo lote.
            $chaveNome = strtolower($nome);
            if (isset($nomesRecebidos[$chaveNome])) continue;
            $nomesRecebidos[$chaveNome] = true;

            $modalidade = strtoupper(trim((string) ($mascara['modalidade'] ?? '')));
            $modalidade = preg_replace('/[^A-Z0-9_-]/', '', $modalidade) ?? '';
            $modalidade = substr($modalidade, 0, 16);
            $studyDescription = trim(strip_tags((string) ($mascara['study_description_tag'] ?? $nome)));

            $preparadas[] = [
                'nome' => $nome,
                'modalidade' => $modalidade,
                'study_description_tag' => substr($studyDescription, 0, 255),
                'conteudo_livre' => $this->sanitizeSectionHtml(
                    (string) ($mascara['conteudo_livre'] ?? '') !== ''
                        ? $mascara['conteudo_livre']
                        : implode('<p><br></p>', array_filter([
                            $mascara['secao_tecnica'] ?? '',
                            $mascara['secao_achados'] ?? '',
                            $mascara['secao_conclusao'] ?? '',
                        ], static fn($valor): bool => trim((string) $valor) !== ''))
                ),
                'secao_tecnica' => $this->sanitizeSectionHtml($mascara['secao_tecnica'] ?? ''),
                'secao_achados' => $this->sanitizeSectionHtml($mascara['secao_achados'] ?? ''),
                'secao_conclusao' => $this->sanitizeSectionHtml($mascara['secao_conclusao'] ?? ''),
                'revisar' => !empty($mascara['revisar']) ? 1 : 0,
            ];
        }

        if (!$preparadas) {
            throw new \InvalidArgumentException('Nenhuma máscara válida foi selecionada para importação.');
        }
        return $preparadas;
    }

    private function sanitizarArquivoOrigem(mixed $nome): string
    {
        $nome = basename(str_replace('\\', '/', (string) $nome));
        $nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nome) ?? '';
        $nome = trim($nome, '._-');
        return substr($nome !== '' ? $nome : 'mascaras.docx', 0, 255);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/templates/buscar?q=termo&modalidade=CT&medico_id=2
    // Busca templates para o painel do laudário
    // ══════════════════════════════════════════════════════════════════════════
    public function buscar(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId  = $this->tenantId();
        $q         = trim($_GET['q'] ?? '');
        $modalidade = trim($_GET['modalidade'] ?? '');
        $medicoId  = (int) ($_GET['medico_id'] ?? 0);
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;

        try {
            $pdo = Database::getInstance();
            $sql = "
                SELECT id, nome, modalidade, compartilhar, study_description_tag,
                       secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao,
                       medico_id, uso_count
                FROM report_templates
                WHERE tenant_id = :tid
                  AND ativo = 1
                  AND (medico_id = :mid OR compartilhar = 1 OR medico_id IS NULL)
            ";
            $params = ['tid' => $tenantId, 'mid' => $medicoId];

            if ($q) {
                $sql .= " AND (nome LIKE :q OR study_description_tag LIKE :q2)";
                $params['q']  = '%' . $q . '%';
                $params['q2'] = '%' . $q . '%';
            }
            if ($modalidade) {
                $sql .= " AND (modalidade = :modalidade OR modalidade IS NULL OR modalidade = '')";
                $params['modalidade'] = $modalidade;
            }
            $sql .= " ORDER BY medico_id = :mid2 DESC, uso_count DESC, nome ASC LIMIT 50";
            $params['mid2'] = $medicoId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->json(['ok' => true, 'templates' => $templates]);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::buscar error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar templates.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/templates/auto?study_description=TC+TORAX&medico_id=2
    // Auto-carregamento por TAG DICOM (0008,1030) Study Description
    // ══════════════════════════════════════════════════════════════════════════
    public function autoCarregar(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId        = $this->tenantId();
        $studyDescription = strtoupper(trim($_GET['study_description'] ?? ''));
        $medicoId        = (int) ($_GET['medico_id'] ?? 0);
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;

        if (!$studyDescription) {
            $this->json(['ok' => true, 'template' => null]);
            return;
        }

        try {
            $pdo = Database::getInstance();
            // Prioridade: template do próprio médico > compartilhado > global
            $stmt = $pdo->prepare("
                SELECT id, nome, modalidade, secao_exame, secao_tecnica,
                       secao_achados, secao_conclusao, secao_recomendacao,
                       study_description_tag, medico_id
                FROM report_templates
                WHERE tenant_id = :tid
                  AND ativo = 1
                  AND study_description_tag IS NOT NULL
                  AND study_description_tag != ''
                  AND UPPER(study_description_tag) = :desc
                  AND (medico_id = :mid OR compartilhar = 1 OR medico_id IS NULL)
                ORDER BY
                    CASE WHEN medico_id = :mid2 THEN 0 ELSE 1 END ASC,
                    uso_count DESC
                LIMIT 1
            ");
            $stmt->execute([
                'tid'  => $tenantId,
                'desc' => $studyDescription,
                'mid'  => $medicoId,
                'mid2' => $medicoId,
            ]);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            if ($template) {
                // Incrementar uso
                $pdo->prepare("UPDATE report_templates SET uso_count = uso_count + 1 WHERE id = :id")
                    ->execute(['id' => $template['id']]);
                Logger::info('TemplatesController::autoCarregar matched', [
                    'template_id'      => $template['id'],
                    'study_description' => $studyDescription,
                    'medico_id'        => $medicoId,
                ]);
            }

            $this->json(['ok' => true, 'template' => $template]);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::autoCarregar error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar template automático.'], 500);
        }
    }
}
