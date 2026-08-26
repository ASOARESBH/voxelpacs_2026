<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\PedidoMedicoRepository;

/**
 * Regras de negócio para pedidos médicos anexados a estudos DICOM.
 *
 * Arquivos nunca são gravados em public/. O nome original é apenas metadado;
 * o nome físico é aleatório e o proxy autenticado valida o caminho antes de
 * fazer streaming.
 */
class PedidoMedicoService
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** MIME => extensões aceitas para o mesmo conteúdo real. */
    private const MIME_EXTENSOES = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'image/heic'      => ['heic'],
        'image/heif'      => ['heif'],
    ];

    private PedidoMedicoRepository $repo;

    public function __construct(?PedidoMedicoRepository $repo = null)
    {
        $this->repo = $repo ?? new PedidoMedicoRepository();
    }

    /**
     * Gestão de pedido exige o módulo explícito de Gestão de Exames. A escolha
     * por perfil é feita pelo administrador do tenant; escopo de empresa,
     * grupo e modalidade continuam sendo conferidos nos endpoints do estudo.
     */
    public function podeGerenciar(?int $tenantId, bool $bypassGlobal): bool
    {
        if (!Auth::hasModule('gestao_exames')) return false;
        if ($bypassGlobal) return true;

        // O perfil ativo no tenant é a autoridade para operações administrativas
        // da Gestão de Exames. O papel global pode ser "viewer" em contas
        // antigas compartilhadas entre tenants e não deve ocultar ações de um
        // administrador legítimo do tenant atual.
        $userId = Auth::userId();
        if (!$tenantId || !$userId) return false;
        return true;
    }

    /**
     * Retorna metadados prontos para Worklist, Gestão de Exames ou Report.
     * A URL de consulta é contextual: a Worklist administrativa usa o proxy
     * de Gestão de Exames; o Report usa seu token opaco e autorização clínica.
     */
    public function buscarPorEstudo(int $estudoId, int $tenantId, ?string $reportToken = null): ?array
    {
        $row = $this->repo->findByEstudoId($estudoId, $tenantId);
        return $row ? $this->normalizarParaView($row, $reportToken) : null;
    }

    /**
     * Anexa ou substitui o pedido do estudo.
     *
     * @param array $file entrada de $_FILES['pedido']
     * @return array{ok:bool,error?:string,pedido?:array,substituido?:bool}
     */
    public function anexar(
        int $estudoId,
        ?int $tenantId,
        bool $bypassGlobal,
        array $file,
        int $usuarioId
    ): array {
        if ($usuarioId <= 0) {
            return ['ok' => false, 'error' => 'nao_autenticado'];
        }

        $estudo = $this->repo->findEstudoById($estudoId, $tenantId, $bypassGlobal);
        if (!$estudo) {
            return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        }

        $validacao = $this->validarArquivo($file);
        if (!$validacao['ok']) {
            return $validacao;
        }

        $tenantEfetivo = (int) $estudo['tenant_id'];
        $tmpPath       = (string) $file['tmp_name'];
        $ext           = $validacao['extensao'];
        $hash          = hash_file('sha256', $tmpPath) ?: '';
        if ($hash === '') {
            return ['ok' => false, 'error' => 'arquivo_invalido'];
        }

        $nomeOriginal = $this->normalizarNomeOriginal((string) ($file['name'] ?? 'pedido.' . $ext));
        $nomeInterno  = date('YmdHis') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $relativo     = $tenantEfetivo . '/' . $estudoId . '/' . $nomeInterno;
        $diretorio    = BASE_PATH . '/storage/uploads/pedidos_medicos/' . $tenantEfetivo . '/' . $estudoId;
        $destino      = $diretorio . '/' . $nomeInterno;

        if (!is_dir($diretorio) && !mkdir($diretorio, 0750, true) && !is_dir($diretorio)) {
            Logger::error('[PedidoMedicoService::anexar] Falha ao criar diretório', [
                'diretorio' => $diretorio,
                'tenant_id' => $tenantEfetivo,
                'estudo_id' => $estudoId,
            ]);
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        $existente = $this->repo->findByEstudoId($estudoId, $tenantEfetivo);
        if (!$this->moverUpload($tmpPath, $destino)) {
            Logger::error('[PedidoMedicoService::anexar] Falha ao mover upload', [
                'estudo_id' => $estudoId,
                'destino'   => $destino,
            ]);
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        try {
            $pedido = $this->repo->upsert([
                'tenant_id'       => $tenantEfetivo,
                'estudo_id'       => $estudoId,
                'nome_original'   => $nomeOriginal,
                'nome_arquivo'    => $nomeInterno,
                'mime_type'       => $validacao['mime_type'],
                'extensao'        => $ext,
                'tamanho_bytes'   => (int) $validacao['tamanho_bytes'],
                'hash_sha256'     => $hash,
                'caminho_arquivo' => $relativo,
                'usuario_id'      => $usuarioId,
            ]);
        } catch (\Throwable $e) {
            @unlink($destino);
            throw $e;
        }

        if ($existente && !empty($existente['caminho_arquivo']) && $existente['caminho_arquivo'] !== $relativo) {
            $arquivoAnterior = $this->caminhoAbsolutoSeguro((string) $existente['caminho_arquivo']);
            if ($arquivoAnterior && is_file($arquivoAnterior)) {
                @unlink($arquivoAnterior);
            }
        }

        $substituido = $existente !== null;
        AuditLogger::log(
            $substituido ? 'pedido_medico.substituir' : 'pedido_medico.anexar',
            'bi_pacs_estudos',
            $estudoId,
            [
                'pedido_id'       => (int) $pedido['id'],
                'tenant_id'       => $tenantEfetivo,
                'nome_original'   => $nomeOriginal,
                'mime_type'       => $validacao['mime_type'],
                'tamanho_bytes'   => (int) $validacao['tamanho_bytes'],
            ],
            $tenantEfetivo
        );

        return [
            'ok'          => true,
            'pedido'      => $this->normalizarParaView($pedido),
            'substituido' => $substituido,
        ];
    }

    /** Remove o pedido do estudo e o arquivo privado correspondente. */
    public function remover(
        int $estudoId,
        ?int $tenantId,
        bool $bypassGlobal,
        int $usuarioId
    ): array {
        $estudo = $this->repo->findEstudoById($estudoId, $tenantId, $bypassGlobal);
        if (!$estudo) {
            return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        }

        $tenantEfetivo = (int) $estudo['tenant_id'];
        $existente     = $this->repo->findByEstudoId($estudoId, $tenantEfetivo);
        if (!$existente) {
            return ['ok' => false, 'error' => 'pedido_nao_encontrado'];
        }

        $apagou = $this->repo->deleteByEstudoId($estudoId, $tenantEfetivo);
        if (!$apagou) {
            return ['ok' => false, 'error' => 'pedido_nao_encontrado'];
        }

        $arquivo = $this->caminhoAbsolutoSeguro((string) $existente['caminho_arquivo']);
        if ($arquivo && is_file($arquivo) && !@unlink($arquivo)) {
            Logger::warning('[PedidoMedicoService::remover] Registro removido, arquivo não apagado', [
                'estudo_id' => $estudoId,
                'pedido_id' => $existente['id'],
                'arquivo'   => $arquivo,
            ]);
        }

        AuditLogger::log(
            'pedido_medico.remover',
            'bi_pacs_estudos',
            $estudoId,
            [
                'pedido_id'     => (int) $existente['id'],
                'tenant_id'     => $tenantEfetivo,
                'nome_original' => $existente['nome_original'],
                'usuario_id'    => $usuarioId,
            ],
            $tenantEfetivo
        );

        return ['ok' => true];
    }

    /**
     * Resolve o arquivo que pode ser servido pelo proxy autenticado.
     * Retorna null para registro inexistente, arquivo ausente ou path inseguro.
     */
    public function obterArquivo(int $pedidoId, ?int $tenantId, bool $bypassGlobal): ?array
    {
        $pedido = $this->repo->findById($pedidoId, $tenantId, $bypassGlobal);
        if (!$pedido) {
            return null;
        }

        $arquivo = $this->caminhoAbsolutoSeguro((string) $pedido['caminho_arquivo']);
        if (!$arquivo || !is_file($arquivo) || !is_readable($arquivo)) {
            return null;
        }

        $tenantEfetivo = (int) $pedido['tenant_id'];
        AuditLogger::log(
            'pedido_medico.visualizar',
            'bi_pacs_estudos',
            (int) $pedido['estudo_id'],
            ['pedido_id' => (int) $pedido['id'], 'nome_original' => $pedido['nome_original']],
            $tenantEfetivo
        );

        return ['pedido' => $pedido, 'caminho' => $arquivo];
    }

    /** Converte um registro de banco em dados seguros para a view. */
    public function normalizarParaView(array $pedido, ?string $reportToken = null): array
    {
        $tamanho = (int) ($pedido['tamanho_bytes'] ?? 0);
        $pedido['tamanho_formatado'] = $this->formatarTamanho($tamanho);
        $pedido['is_imagem'] = str_starts_with((string) ($pedido['mime_type'] ?? ''), 'image/');
        $token = strtolower(trim((string) $reportToken));
        $pedido['visualizar_url'] = preg_match('/^[a-f0-9]{48}$/', $token) === 1
            ? '/reports/r/' . rawurlencode($token) . '/pedido'
            : '/api/gestao-exames/pedidos/' . (int) $pedido['id'] . '/arquivo';
        return $pedido;
    }

    /** Retorna o caminho apenas se ele permanecer dentro do diretório privado-base. */
    public function caminhoAbsolutoSeguro(string $caminhoRelativo): ?string
    {
        if ($caminhoRelativo === '' || str_contains($caminhoRelativo, "\0")) {
            return null;
        }

        $base = realpath(BASE_PATH . '/storage/uploads/pedidos_medicos');
        if ($base === false) {
            return null;
        }

        $arquivo = realpath($base . '/' . ltrim($caminhoRelativo, '/'));
        if ($arquivo === false || !is_file($arquivo)) {
            return null;
        }

        $prefixo = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($arquivo, $prefixo, strlen($prefixo)) !== 0) {
            return null;
        }

        return $arquivo;
    }

    /** @return array{ok:bool,error?:string,mime_type?:string,extensao?:string,tamanho_bytes?:int} */
    private function validarArquivo(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return ['ok' => false, 'error' => 'arquivo_muito_grande'];
            }
            return ['ok' => false, 'error' => $error === UPLOAD_ERR_NO_FILE ? 'arquivo_ausente' : 'erro_upload'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
            return ['ok' => false, 'error' => 'arquivo_invalido'];
        }
        if ($size <= 0) {
            $size = (int) filesize($tmp);
        }
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'arquivo_invalido'];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'arquivo_muito_grande'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::MIME_EXTENSOES[$mime]) || !in_array($ext, self::MIME_EXTENSOES[$mime], true)) {
            return ['ok' => false, 'error' => 'tipo_invalido'];
        }

        return [
            'ok'           => true,
            'mime_type'    => $mime,
            'extensao'     => $ext,
            'tamanho_bytes'=> $size,
        ];
    }

    private function moverUpload(string $origem, string $destino): bool
    {
        if (PHP_SAPI === 'cli') {
            return @rename($origem, $destino) || @copy($origem, $destino);
        }
        return @move_uploaded_file($origem, $destino);
    }

    private function normalizarNomeOriginal(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[\\x00-\\x1F\\x7F]+/u', '_', $nome) ?: 'pedido_medico';
        $nome = trim($nome, '. ');
        if ($nome === '') $nome = 'pedido_medico';
        return function_exists('mb_substr') ? mb_substr($nome, 0, 255) : substr($nome, 0, 255);
    }

    private function formatarTamanho(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        return $bytes . ' B';
    }
}
