<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\GestaoExamesService;
use App\Services\PedidoMedicoService;

/**
 * Endpoints administrativos de pedido médico vinculado a estudo.
 *
 * A tela é renderizada pelo EstudosController para reutilizar a Worklist. Este
 * controller fica apenas com upload, remoção e streaming autenticado do arquivo.
 */
class GestaoExamesController extends Controller
{
    private PedidoMedicoService $service;
    private GestaoExamesService $gerenciarService;

    public function __construct()
    {
        $this->service = new PedidoMedicoService();
        $this->gerenciarService = new GestaoExamesService();
    }

    public function anexar(int $estudoId): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.nao_autenticado')], 401);
            return;
        }
        if (!$this->service->podeGerenciar(
            Auth::tenantId(),
            Auth::isPlatformAdmin() && !Auth::isImpersonating()
        )) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.sem_permissao')], 403);
            return;
        }
        if (!$this->validarCsrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.csrf')], 403);
            return;
        }
        if (empty($_FILES['pedido']) || !is_array($_FILES['pedido'])) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.arquivo_ausente')], 422);
            return;
        }

        try {
            $resultado = $this->service->anexar(
                $estudoId,
                Auth::tenantId(),
                Auth::isPlatformAdmin() && !Auth::isImpersonating(),
                $_FILES['pedido'],
                (int) (Auth::userId() ?? 0)
            );

            if (!$resultado['ok']) {
                $this->json([
                    'ok'  => false,
                    'msg' => $this->mensagemErro($resultado['error'] ?? null),
                ], $this->statusErro($resultado['error'] ?? null));
                return;
            }

            $this->json([
                'ok'          => true,
                'msg'         => $resultado['substituido']
                    ? t('pedido_medico.msg.substituido')
                    : t('pedido_medico.msg.anexado'),
                'pedido'      => $resultado['pedido'],
                'substituido' => $resultado['substituido'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[GestaoExamesController::anexar] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'usuario_id'=> Auth::userId(),
            ]);
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.interno')], 500);
        }
    }

    public function remover(int $estudoId): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.nao_autenticado')], 401);
            return;
        }
        if (!$this->service->podeGerenciar(
            Auth::tenantId(),
            Auth::isPlatformAdmin() && !Auth::isImpersonating()
        )) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.sem_permissao')], 403);
            return;
        }

        $input = $this->inputJsonOuPost();
        if (!$this->validarCsrf($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.csrf')], 403);
            return;
        }

        try {
            $resultado = $this->service->remover(
                $estudoId,
                Auth::tenantId(),
                Auth::isPlatformAdmin() && !Auth::isImpersonating(),
                (int) (Auth::userId() ?? 0)
            );
            if (!$resultado['ok']) {
                $this->json([
                    'ok'  => false,
                    'msg' => $this->mensagemErro($resultado['error'] ?? null),
                ], $this->statusErro($resultado['error'] ?? null));
                return;
            }
            $this->json(['ok' => true, 'msg' => t('pedido_medico.msg.removido')]);
        } catch (\Throwable $e) {
            Logger::error('[GestaoExamesController::remover] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'usuario_id'=> Auth::userId(),
            ]);
            $this->json(['ok' => false, 'msg' => t('pedido_medico.erro.interno')], 500);
        }
    }

    /**
     * Contexto do submenu Gerenciar para um estudo da Worklist.
     * Inclui report, impressão, Chat e prioridade efetiva/auditada.
     */
    public function gerenciarContext(int $estudoId): void
    {
        if (!$this->autorizadoGerenciar()) return;
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId <= 0) {
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.tenant')], 403);
            return;
        }

        try {
            $context = $this->gerenciarService->context($estudoId, $tenantId, (int) Auth::userId());
            if (!$context) {
                $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.estudo')], 404);
                return;
            }
            $this->json(['ok' => true, 'context' => $context]);
        } catch (\Throwable $e) {
            Logger::error('[GestaoExamesController::gerenciarContext] falha', [
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
                'usuario_id' => Auth::userId(),
                'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.contexto')], 500);
        }
    }

    /** Altera somente o override operacional da prioridade DICOM e audita o motivo. */
    public function alterarPrioridade(int $estudoId): void
    {
        if (!$this->autorizadoGerenciar()) return;
        $input = $this->inputJsonOuPost();
        if (!$this->validarCsrf($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.csrf')], 403);
            return;
        }

        $tenantId = (int) (Auth::tenantId() ?? 0);
        $priority = (string) ($input['prioridade'] ?? '');
        $reason = (string) ($input['motivo'] ?? '');
        if ($tenantId <= 0) {
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.tenant')], 403);
            return;
        }

        try {
            $result = $this->gerenciarService->changePriority(
                $estudoId,
                $tenantId,
                (int) (Auth::userId() ?? 0),
                $priority,
                $reason
            );
            if (!$result['ok']) {
                $this->json([
                    'ok' => false,
                    'msg' => $this->mensagemGerenciar($result['error'] ?? null),
                ], $this->statusGerenciar($result['error'] ?? null));
                return;
            }
            $this->json([
                'ok' => true,
                'msg' => t('gestao_gerenciar.msg.prioridade_alterada'),
                'priority' => $result['priority'],
                'label' => $result['label'],
                'audit_id' => $result['audit_id'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[GestaoExamesController::alterarPrioridade] falha', [
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
                'usuario_id' => Auth::userId(),
                'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.persistencia')], 500);
        }
    }

    /** Proxy autenticado: o arquivo real permanece fora de public/. */
    public function arquivo(int $pedidoId): void
    {
        if (!Auth::check()) {
            http_response_code(401);
            return;
        }

        try {
            $resultado = $this->service->obterArquivo(
                $pedidoId,
                Auth::tenantId(),
                Auth::isPlatformAdmin() && !Auth::isImpersonating()
            );
            if (!$resultado) {
                http_response_code(404);
                return;
            }

            $pedido = $resultado['pedido'];
            $caminho = $resultado['caminho'];
            $nome = basename((string) ($pedido['nome_original'] ?? 'pedido_medico'));
            $nome = str_replace(["\r", "\n", '"'], '_', $nome);
            $nomeUrl = rawurlencode($nome);
            $mime = (string) ($pedido['mime_type'] ?? 'application/octet-stream');

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($caminho));
            header('Content-Disposition: inline; filename="pedido_medico"; filename*=UTF-8\'\'' . $nomeUrl);
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            readfile($caminho);
        } catch (\Throwable $e) {
            Logger::error('[GestaoExamesController::arquivo] ' . $e->getMessage(), [
                'pedido_id' => $pedidoId,
                'usuario_id'=> Auth::userId(),
            ]);
            http_response_code(500);
        }
    }

    private function autorizadoGerenciar(): bool
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.nao_autenticado')], 401);
            return false;
        }
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if (!$this->service->podeGerenciar(
            $tenantId > 0 ? $tenantId : null,
            Auth::isPlatformAdmin() && !Auth::isImpersonating()
        )) {
            $this->json(['ok' => false, 'msg' => t('gestao_gerenciar.erro.permissao')], 403);
            return false;
        }
        return true;
    }

    private function statusGerenciar(?string $codigo): int
    {
        return match ($codigo) {
            'estudo_nao_encontrado' => 404,
            'chat_pendente' => 409,
            default => 422,
        };
    }

    private function mensagemGerenciar(?string $codigo): string
    {
        return match ($codigo) {
            'prioridade_invalida' => t('gestao_gerenciar.erro.prioridade_invalida'),
            'motivo_curto' => t('gestao_gerenciar.erro.motivo_curto'),
            'motivo_longo' => t('gestao_gerenciar.erro.motivo_longo'),
            'prioridade_igual' => t('gestao_gerenciar.erro.prioridade_igual'),
            'chat_pendente' => t('gestao_gerenciar.erro.chat_pendente'),
            'estudo_nao_encontrado' => t('gestao_gerenciar.erro.estudo'),
            'persistencia_falhou' => t('gestao_gerenciar.erro.persistencia'),
            default => t('gestao_gerenciar.erro.interno'),
        };
    }

    private function validarCsrf(string $token): bool
    {
        return $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    private function inputJsonOuPost(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) return $json;
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function statusErro(?string $codigo): int
    {
        return match ($codigo) {
            'sem_permissao'     => 403,
            'estudo_nao_encontrado', 'pedido_nao_encontrado' => 404,
            default             => 422,
        };
    }

    private function mensagemErro(?string $codigo): string
    {
        return match ($codigo) {
            'arquivo_ausente'     => t('pedido_medico.erro.arquivo_ausente'),
            'erro_upload'         => t('pedido_medico.erro.upload'),
            'arquivo_muito_grande'=> t('pedido_medico.erro.muito_grande'),
            'tipo_invalido'       => t('pedido_medico.erro.tipo_invalido'),
            'arquivo_invalido'    => t('pedido_medico.erro.invalido'),
            'falha_ao_salvar'     => t('pedido_medico.erro.salvar'),
            'estudo_nao_encontrado'=> t('pedido_medico.erro.estudo_nao_encontrado'),
            'pedido_nao_encontrado'=> t('pedido_medico.erro.nao_encontrado'),
            'nao_autenticado'     => t('pedido_medico.erro.nao_autenticado'),
            default               => t('pedido_medico.erro.interno'),
        };
    }
}
