<?php
namespace App\Controllers;

use App\Core\Access\MedicoAccess;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;

/**
 * TemplatesController — Módulo de Máscaras/Templates de Laudo
 *
 * Rotas:
 *   GET  /api/medicos/{medicoId}/templates          → listar()
 *   POST /api/medicos/{medicoId}/templates          → salvar()
 *   PUT  /api/medicos/{medicoId}/templates/{id}     → salvar() (com id no body)
 *   DELETE /api/medicos/{medicoId}/templates/{id}   → excluir()
 *   POST /api/medicos/{medicoId}/templates/importar → importar() (upload DOCX)
 *   GET  /api/templates/buscar                      → buscar() (para o laudário)
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
     * O editor de máscaras permite somente <strong> para preservar o negrito
     * clínico. Todo o restante é removido no servidor antes da persistência.
     */
    private function sanitizeSectionHtml($value): string
    {
        $html = (string) $value;
        $html = strip_tags($html, '<p><br><strong><b>');
        $html = preg_replace('/<(?:strong|b)\\b[^>]*>/i', '<strong>', $html) ?? '';
        $html = preg_replace('/<\\/(?:strong|b)>/i', '</strong>', $html) ?? '';
        $html = preg_replace('/<p\\b[^>]*>/i', '<p>', $html) ?? '';
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
                SELECT id, nome, modalidade, compartilhar, study_description_tag,
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
                        (tenant_id, medico_id, nome, modalidade, compartilhar,
                         study_description_tag, secao_exame, secao_tecnica,
                         secao_achados, secao_conclusao, secao_recomendacao, ativo)
                    VALUES
                        (:tid, :mid, :nome, :modalidade, :compartilhar,
                         :study_desc, :s_exame, :s_tecnica,
                         :s_achados, :s_conclusao, :s_recomendacao, 1)
                ");
                $stmt->execute([
                    'tid'            => $tenantId,
                    'mid'            => $medicoId,
                    'nome'           => $nome,
                    'modalidade'     => $modalidade,
                    'compartilhar'   => $compartilhar,
                    'study_desc'     => $studyDescriptionTag ?: null,
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
    // POST /api/medicos/{medicoId}/templates/importar
    // Importa templates de um arquivo DOCX ou JSON
    // ══════════════════════════════════════════════════════════════════════════
    public function importar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        if (!$this->guardOwnMedicoOrDeny($medicoId)) return;
        $tenantId = $this->tenantId();

        // Aceita JSON no body (lista de templates pré-processados)
        $input = $this->getJsonInput();
        if (!empty($input['templates']) && is_array($input['templates'])) {
            $this->importarJson($medicoId, $tenantId, $input['templates']);
            return;
        }

        // Aceita upload de arquivo DOCX
        if (!empty($_FILES['arquivo'])) {
            $this->importarDocx($medicoId, $tenantId, $_FILES['arquivo']);
            return;
        }

        $this->json(['ok' => false, 'msg' => 'Envie um arquivo DOCX ou JSON com os templates.'], 422);
    }

    private function importarJson(int $medicoId, int $tenantId, array $templates): void
    {
        try {
            $pdo       = Database::getInstance();
            $importados = 0;
            foreach ($templates as $tpl) {
                $nome = trim($tpl['nome'] ?? '');
                if (!$nome) continue;
                // Evitar duplicatas
                $check = $pdo->prepare("SELECT id FROM report_templates WHERE tenant_id = :tid AND medico_id = :mid AND nome = :nome AND ativo = 1 LIMIT 1");
                $check->execute(['tid' => $tenantId, 'mid' => $medicoId, 'nome' => $nome]);
                if ($check->fetchColumn()) continue;

                $pdo->prepare("
                    INSERT INTO report_templates
                        (tenant_id, medico_id, nome, modalidade, compartilhar,
                         study_description_tag, secao_exame, secao_tecnica,
                         secao_achados, secao_conclusao, secao_recomendacao, ativo)
                    VALUES (:tid, :mid, :nome, :modalidade, :compartilhar,
                            :study_desc, :s_exame, :s_tecnica,
                            :s_achados, :s_conclusao, :s_recomendacao, 1)
                ")->execute([
                    'tid'            => $tenantId,
                    'mid'            => $medicoId,
                    'nome'           => $nome,
                    'modalidade'     => $tpl['modalidade'] ?? 'CT',
                    'compartilhar'   => (int) ($tpl['compartilhar'] ?? 0),
                    'study_desc'     => $tpl['study_description_tag'] ?? null,
                    's_exame'        => $tpl['secao_exame']        ?? '',
                    's_tecnica'      => $tpl['secao_tecnica']      ?? '',
                    's_achados'      => $tpl['secao_achados']      ?? '',
                    's_conclusao'    => $tpl['secao_conclusao']    ?? '',
                    's_recomendacao' => $tpl['secao_recomendacao'] ?? '',
                ]);
                $importados++;
            }
            Logger::info('TemplatesController::importarJson', ['importados' => $importados, 'medico_id' => $medicoId]);
            $this->json(['ok' => true, 'importados' => $importados, 'msg' => "$importados template(s) importado(s) com sucesso."]);
        } catch (\Throwable $e) {
            Logger::error('TemplatesController::importarJson error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao importar templates.'], 500);
        }
    }

    private function importarDocx(int $medicoId, int $tenantId, array $file): void
    {
        // Processar DOCX via Python (disponível no servidor)
        $tmpPath = tempnam(sys_get_temp_dir(), 'voxel_tpl_') . '.docx';
        move_uploaded_file($file['tmp_name'], $tmpPath);

        $script = <<<'PYEOF'
import sys, json
from docx import Document

doc = Document(sys.argv[1])
templates = []
current = None

for para in doc.paragraphs:
    text = para.text.strip()
    if not text:
        continue
    if para.style.name in ('Heading 1', 'Heading 2', 'Heading 3'):
        if current:
            templates.append(current)
        current = {'nome': text, 'secao_exame': '', 'secao_tecnica': '', 'secao_achados': '', 'secao_conclusao': '', 'secao_recomendacao': '', 'modalidade': 'CT', 'compartilhar': 0, 'study_description_tag': text}
    elif current:
        label = text.lower()
        if 'método' in label or 'metodo' in label or 'técnica' in label or 'tecnica' in label:
            current['_section'] = 'tecnica'
        elif 'análise' in label or 'analise' in label or 'achado' in label:
            current['_section'] = 'achados'
        elif 'conclus' in label:
            current['_section'] = 'conclusao'
        elif 'recomend' in label:
            current['_section'] = 'recomendacao'
        else:
            section = current.get('_section', 'exame')
            key = 'secao_' + section
            current[key] = (current.get(key, '') + '<p>' + text + '</p>').strip()

if current:
    templates.append(current)

# Limpar _section
for t in templates:
    t.pop('_section', None)

print(json.dumps(templates, ensure_ascii=False))
PYEOF;

        $scriptPath = tempnam(sys_get_temp_dir(), 'voxel_py_') . '.py';
        file_put_contents($scriptPath, $script);

        $output = shell_exec("python3 " . escapeshellarg($scriptPath) . " " . escapeshellarg($tmpPath) . " 2>/dev/null");
        unlink($tmpPath);
        unlink($scriptPath);

        $templates = json_decode($output ?: '[]', true);
        if (!is_array($templates)) {
            $this->json(['ok' => false, 'msg' => 'Não foi possível processar o arquivo DOCX.'], 422);
            return;
        }

        $this->importarJson($medicoId, $tenantId, $templates);
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
