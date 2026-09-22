<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\ExamesComplementaresService;
use App\Services\GestaoExamesService;
use App\Services\PedidoMedicoService;

/** Endpoints administrativos do anexo complementar privado por estudo. */
final class ExamesComplementaresController extends Controller
{
    private ExamesComplementaresService $service;
    private GestaoExamesService $gestaoService;
    private PedidoMedicoService $pedidoService;

    public function __construct()
    {
        $this->service = new ExamesComplementaresService();
        $this->gestaoService = new GestaoExamesService();
        $this->pedidoService = new PedidoMedicoService();
    }

    public function anexar(int $estudoId): void
    {
        if (!$this->autorizarGestao()) return;
        $tenantId = $this->tenantEfetivoDoEstudo($estudoId);
        if ($tenantId === null) return;
        if (!$this->validarCsrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.csrf')], 403);
            return;
        }
        if (empty($_FILES['exame_complementar']) || !is_array($_FILES['exame_complementar'])) {
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.arquivo_ausente')], 422);
            return;
        }
        try {
            $resultado = $this->service->anexar($estudoId, $tenantId, $this->bypassGlobal(), $_FILES['exame_complementar'], (int) (Auth::userId() ?? 0));
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null)], $this->statusErro($resultado['error'] ?? null));
                return;
            }
            $this->json([
                'ok' => true,
                'msg' => $resultado['substituido'] ? t('exames_complementares.msg.substituido') : t('exames_complementares.msg.anexado'),
                'exame' => $resultado['exame'],
                'substituido' => $resultado['substituido'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresController::anexar] falha', ['tenant_id' => $tenantId, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.interno')], 500);
        }
    }

    public function remover(int $estudoId): void
    {
        if (!$this->autorizarGestao()) return;
        $tenantId = $this->tenantEfetivoDoEstudo($estudoId);
        if ($tenantId === null) return;
        $input = $this->inputJsonOuPost();
        if (!$this->validarCsrf($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.csrf')], 403);
            return;
        }
        try {
            $resultado = $this->service->remover($estudoId, $tenantId, $this->bypassGlobal(), (int) (Auth::userId() ?? 0));
            if (!$resultado['ok']) {
                $this->json(['ok' => false, 'msg' => $this->mensagemErro($resultado['error'] ?? null)], $this->statusErro($resultado['error'] ?? null));
                return;
            }
            $this->json(['ok' => true, 'msg' => t('exames_complementares.msg.removido')]);
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresController::remover] falha', ['tenant_id' => $tenantId, 'usuario_id' => Auth::userId()]);
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.interno')], 500);
        }
    }

    /** Proxy autenticado; sempre reaplica módulo, tenant e modalidade. */
    public function arquivo(int $anexoId): void
    {
        if (!Auth::check()) { http_response_code(401); return; }
        if (!Auth::hasModule('gestao_exames')) { http_response_code(403); return; }
        try {
            $resultado = $this->service->obterArquivo($anexoId, Auth::tenantId(), $this->bypassGlobal());
            if (!$resultado) { http_response_code(404); return; }
            $anexo = $resultado['anexo'];
            $tenant = (int) ($anexo['tenant_id'] ?? 0);
            if ($tenant <= 0 || !$this->gestaoService->canAccessStudyModalities((int) ($anexo['estudo_id'] ?? 0), $tenant, (int) (Auth::userId() ?? 0), $this->bypassGlobal())) {
                http_response_code(404); return;
            }
            $nome = str_replace(["\r", "\n", '"'], '_', basename((string) ($anexo['nome_original'] ?? 'exame_complementar')));
            header('Content-Type: ' . (string) ($anexo['mime_type'] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string) filesize($resultado['caminho']));
            header('Content-Disposition: inline; filename="exame_complementar"; filename*=UTF-8\'\'' . rawurlencode($nome));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            readfile($resultado['caminho']);
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresController::arquivo] falha', ['anexo_complementar' => true, 'usuario_id' => Auth::userId()]);
            http_response_code(500);
        }
    }

    private function autorizarGestao(): bool
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.nao_autenticado')], 401); return false; }
        $tenant = Auth::tenantId();
        if (!$this->pedidoService->podeGerenciar($tenant ? (int) $tenant : null, $this->bypassGlobal())) {
            $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.sem_permissao')], 403); return false;
        }
        return true;
    }

    private function tenantEfetivoDoEstudo(int $estudoId): ?int
    {
        $tenant = $this->gestaoService->resolveTenantForStudy($estudoId, Auth::tenantId(), $this->bypassGlobal());
        if ($tenant !== null && $this->gestaoService->canAccessStudyModalities($estudoId, $tenant, (int) (Auth::userId() ?? 0), $this->bypassGlobal())) return $tenant;
        $this->json(['ok' => false, 'msg' => t('exames_complementares.erro.estudo_nao_encontrado')], 404);
        return null;
    }

    private function bypassGlobal(): bool
    {
        return Auth::isPlatformAdmin() && !Auth::isImpersonating();
    }

    private function statusErro(?string $erro): int
    {
        return match ($erro) { 'estudo_nao_encontrado', 'anexo_nao_encontrado' => 404, 'nao_autenticado' => 401, 'falha_ao_salvar' => 500, default => 422 };
    }

    private function mensagemErro(?string $erro): string
    {
        return match ($erro) {
            'arquivo_ausente' => t('exames_complementares.erro.arquivo_ausente'),
            'arquivo_muito_grande' => t('exames_complementares.erro.muito_grande'),
            'erro_upload' => t('exames_complementares.erro.upload'),
            'tipo_invalido' => t('exames_complementares.erro.tipo_invalido'),
            'arquivo_invalido' => t('exames_complementares.erro.invalido'),
            'nao_autenticado' => t('exames_complementares.erro.nao_autenticado'),
            'estudo_nao_encontrado' => t('exames_complementares.erro.estudo_nao_encontrado'),
            'anexo_nao_encontrado' => t('exames_complementares.erro.nao_encontrado'),
            'falha_ao_salvar' => t('exames_complementares.erro.salvar'),
            default => t('exames_complementares.erro.interno'),
        };
    }
}
