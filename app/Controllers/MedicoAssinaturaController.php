<?php
/**
 * MedicoAssinaturaController — aba "Assinatura" em /medicos/{id}/edit.
 *
 * Autorização: mesmo escopo do resto de MedicosController::edit() (qualquer
 * usuário autenticado do tenant que já acessa essa tela, não restrito ao
 * médico logado — decisão confirmada explicitamente, ver
 * modules/assinatura-medico.md). Toda ação exige TenantContext + confere que
 * o {id} da URL é um bi_medicos.id do tenant do usuário logado.
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\MedicoAssinaturaService;

class MedicoAssinaturaController extends Controller
{
    private MedicoAssinaturaService $service;

    public function __construct()
    {
        $this->service = new MedicoAssinaturaService();
    }

    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            $this->json(['ok' => false, 'msg' => 'Tenant não identificado.'], 403);
            exit;
        }
        return $id;
    }

    /** Confere que $medicoId pertence ao tenant autenticado antes de qualquer ação. */
    private function validarMedicoDoTenant(int $medicoId, int $tenantId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM bi_medicos WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['id' => $medicoId, 'tenant_id' => $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function mensagemErro(?string $codigo, array $contexto = []): string
    {
        return match ($codigo) {
            'erro_upload'             => 'Erro no upload do arquivo.',
            'arquivo_muito_grande'    => 'Arquivo excede o tamanho máximo de 2MB.',
            'tipo_invalido'           => 'Formato inválido. Envie um arquivo JPG/JPEG.',
            'formato_invalido'        => 'Assinatura inválida — desenhe novamente e tente salvar.',
            'falha_ao_salvar'         => 'Falha ao salvar o arquivo no servidor.',
            'tipo_nao_disponivel'     => 'Este tipo de assinatura ainda não está disponível.',
            'assinatura_nao_cadastrada' => 'Cadastre uma assinatura deste tipo antes de ativá-la.',
            'outra_assinatura_ativa'  => 'Já existe uma assinatura ativa (' . ($this->rotuloTipo($contexto['tipo_ativo'] ?? '')) . '). Desative-a antes de ativar outra.',
            'falha_ao_ativar'         => 'Falha ao ativar a assinatura.',
            'nao_estava_ativa'        => 'Esta assinatura já não estava ativa.',
            default                    => 'Erro ao processar a assinatura.',
        };
    }

    private function rotuloTipo(string $tipo): string
    {
        return match ($tipo) {
            'imagem' => 'Imagem', 'livre' => 'Assinatura livre', 'certificado' => 'Certificado digital',
            default   => $tipo,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /medicos/{id}/assinatura/listar
    // Estado dos 3 blocos, consumido pela view via AJAX ao trocar de aba.
    // ─────────────────────────────────────────────────────────────────────
    public function listar(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }
        $this->json(['ok' => true, 'blocos' => $this->service->listar($medicoId, $tenantId)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /medicos/{id}/assinatura/imagem/upload
    // ─────────────────────────────────────────────────────────────────────
    public function uploadImagem(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        if (empty($_FILES['arquivo'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.'], 422);
            return;
        }

        try {
            $resultado = $this->service->salvarImagem($medicoId, $tenantId, $_FILES['arquivo']);
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null)], 422);
                return;
            }
            Logger::info('[MedicoAssinaturaController::uploadImagem]', ['medico_id' => $medicoId, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => true, 'msg' => 'Assinatura (imagem) salva.']);
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaController::uploadImagem] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao salvar.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /medicos/{id}/assinatura/livre/salvar
    // Body JSON: { "png": "data:image/png;base64,..." }
    // ─────────────────────────────────────────────────────────────────────
    public function salvarLivre(int $medicoId): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $png   = $input['png'] ?? '';
        if (!$png) {
            $this->json(['ok' => false, 'msg' => 'Desenhe a assinatura antes de salvar.'], 422);
            return;
        }

        try {
            $resultado = $this->service->salvarLivre($medicoId, $tenantId, $png);
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null)], 422);
                return;
            }
            Logger::info('[MedicoAssinaturaController::salvarLivre]', ['medico_id' => $medicoId, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => true, 'msg' => 'Assinatura (livre) salva.']);
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaController::salvarLivre] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao salvar.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /medicos/{id}/assinatura/{tipo}/preview
    // Proxy autenticado — o arquivo real fica fora de public/, nunca exposto direto.
    // ─────────────────────────────────────────────────────────────────────
    public function preview(int $medicoId, string $tipo): void
    {
        if (!Auth::check()) { http_response_code(401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) { http_response_code(404); return; }

        if (!in_array($tipo, MedicoAssinaturaService::TIPOS_COM_ARQUIVO, true)) {
            http_response_code(404);
            return;
        }

        $registro = $this->service->buscarPorTipo($medicoId, $tenantId, $tipo);
        if (!$registro || empty($registro['caminho_arquivo'])) {
            http_response_code(404);
            return;
        }

        $caminho = $this->service->caminhoAbsoluto($registro['caminho_arquivo']);
        if (!is_file($caminho)) {
            http_response_code(404);
            return;
        }

        $mime = $tipo === 'imagem' ? 'image/jpeg' : 'image/png';
        header("Content-Type: {$mime}");
        header('Cache-Control: private, no-store');
        readfile($caminho);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /medicos/{id}/assinatura/{tipo}/ativar
    // Bloqueia se outro tipo já estiver ativo — nunca troca automático.
    // ─────────────────────────────────────────────────────────────────────
    public function ativar(int $medicoId, string $tipo): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        try {
            $resultado = $this->service->ativar($medicoId, $tenantId, $tipo);
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null, $resultado)], 422);
                return;
            }
            Logger::info('[MedicoAssinaturaController::ativar]', ['medico_id' => $medicoId, 'tipo' => $tipo, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => true, 'msg' => 'Assinatura ativada.']);
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaController::ativar] ' . $e->getMessage(), ['medico_id' => $medicoId, 'tipo' => $tipo]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao ativar.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /medicos/{id}/assinatura/{tipo}/desativar
    // ─────────────────────────────────────────────────────────────────────
    public function desativar(int $medicoId, string $tipo): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = $this->tenantId();
        if (!$this->validarMedicoDoTenant($medicoId, $tenantId)) {
            $this->json(['ok' => false, 'msg' => 'Médico não encontrado.'], 404);
            return;
        }

        try {
            $resultado = $this->service->desativar($medicoId, $tenantId, $tipo);
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null)], 422);
                return;
            }
            Logger::info('[MedicoAssinaturaController::desativar]', ['medico_id' => $medicoId, 'tipo' => $tipo, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => true, 'msg' => 'Assinatura desativada.']);
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaController::desativar] ' . $e->getMessage(), ['medico_id' => $medicoId, 'tipo' => $tipo]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao desativar.'], 500);
        }
    }
}
