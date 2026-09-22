<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Logger;
use App\Repositories\ExamesComplementaresRepository;

/** Um arquivo complementar privado por estudo, separado do Pedido médico. */
final class ExamesComplementaresService
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const MIME_EXTENSOES = [
        'application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'],
        'image/webp' => ['webp'], 'image/heic' => ['heic'], 'image/heif' => ['heif'],
    ];
    private ExamesComplementaresRepository $repo;

    public function __construct(?ExamesComplementaresRepository $repo = null)
    {
        $this->repo = $repo ?? new ExamesComplementaresRepository();
    }

    public function buscarPorEstudo(int $estudoId, int $tenantId, ?string $reportToken = null): ?array
    {
        $row = $this->repo->findByEstudoId($estudoId, $tenantId);
        return $row ? $this->normalizarParaView($row, $reportToken) : null;
    }

    public function anexar(int $estudoId, ?int $tenantId, bool $bypassGlobal, array $file, int $usuarioId): array
    {
        if ($usuarioId <= 0) return ['ok' => false, 'error' => 'nao_autenticado'];
        $estudo = $this->repo->findEstudoById($estudoId, $tenantId, $bypassGlobal);
        if (!$estudo) return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        $validacao = $this->validarArquivo($file);
        if (!$validacao['ok']) return $validacao;
        $tenant = (int) $estudo['tenant_id'];
        $tmp = (string) $file['tmp_name'];
        $hash = hash_file('sha256', $tmp) ?: '';
        if ($hash === '') return ['ok' => false, 'error' => 'arquivo_invalido'];
        $ext = (string) $validacao['extensao'];
        $original = $this->normalizarNomeOriginal((string) ($file['name'] ?? 'exame_complementar.' . $ext));
        $interno = date('YmdHis') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $relativo = $tenant . '/' . $estudoId . '/' . $interno;
        $diretorio = BASE_PATH . '/storage/uploads/exames_complementares/' . $tenant . '/' . $estudoId;
        $destino = $diretorio . '/' . $interno;
        if (!is_dir($diretorio) && !mkdir($diretorio, 0750, true) && !is_dir($diretorio)) return ['ok' => false, 'error' => 'falha_ao_salvar'];
        $existente = $this->repo->findByEstudoId($estudoId, $tenant);
        if (!$this->moverUpload($tmp, $destino)) return ['ok' => false, 'error' => 'falha_ao_salvar'];
        try {
            $anexo = $this->repo->upsert([
                'tenant_id' => $tenant, 'estudo_id' => $estudoId, 'nome_original' => $original, 'nome_arquivo' => $interno,
                'mime_type' => $validacao['mime_type'], 'extensao' => $ext, 'tamanho_bytes' => (int) $validacao['tamanho_bytes'],
                'hash_sha256' => $hash, 'caminho_arquivo' => $relativo, 'usuario_id' => $usuarioId,
            ]);
        } catch (\Throwable $e) {
            @unlink($destino);
            throw $e;
        }
        if ($existente && ($anterior = $this->caminhoAbsolutoSeguro((string) $existente['caminho_arquivo'])) && is_file($anterior)) @unlink($anterior);
        $substituido = $existente !== null;
        AuditLogger::log($substituido ? 'exames_complementares.substituir' : 'exames_complementares.anexar', 'bi_pacs_estudos', $estudoId, [
            'anexo_id' => (int) $anexo['id'], 'tenant_id' => $tenant, 'mime_type' => $validacao['mime_type'], 'tamanho_bytes' => (int) $validacao['tamanho_bytes'],
        ], $tenant);
        return ['ok' => true, 'exame' => $this->normalizarParaView($anexo), 'substituido' => $substituido];
    }

    public function remover(int $estudoId, ?int $tenantId, bool $bypassGlobal, int $usuarioId): array
    {
        $estudo = $this->repo->findEstudoById($estudoId, $tenantId, $bypassGlobal);
        if (!$estudo) return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        $tenant = (int) $estudo['tenant_id'];
        $existente = $this->repo->findByEstudoId($estudoId, $tenant);
        if (!$existente) return ['ok' => false, 'error' => 'anexo_nao_encontrado'];
        if (!$this->repo->deleteByEstudoId($estudoId, $tenant)) return ['ok' => false, 'error' => 'anexo_nao_encontrado'];
        if (($arquivo = $this->caminhoAbsolutoSeguro((string) $existente['caminho_arquivo'])) && is_file($arquivo)) @unlink($arquivo);
        AuditLogger::log('exames_complementares.remover', 'bi_pacs_estudos', $estudoId, ['anexo_id' => (int) $existente['id'], 'tenant_id' => $tenant, 'usuario_id' => $usuarioId], $tenant);
        return ['ok' => true];
    }

    public function obterArquivo(int $anexoId, ?int $tenantId, bool $bypassGlobal): ?array
    {
        $anexo = $this->repo->findById($anexoId, $tenantId, $bypassGlobal);
        if (!$anexo || !($arquivo = $this->caminhoAbsolutoSeguro((string) $anexo['caminho_arquivo'])) || !is_readable($arquivo)) return null;
        $tenant = (int) $anexo['tenant_id'];
        AuditLogger::log('exames_complementares.visualizar', 'bi_pacs_estudos', (int) $anexo['estudo_id'], ['anexo_id' => (int) $anexo['id']], $tenant);
        return ['anexo' => $anexo, 'caminho' => $arquivo];
    }

    public function normalizarParaView(array $anexo, ?string $reportToken = null): array
    {
        $bytes = (int) ($anexo['tamanho_bytes'] ?? 0);
        $anexo['tamanho_formatado'] = $this->formatarTamanho($bytes);
        $anexo['is_imagem'] = str_starts_with((string) ($anexo['mime_type'] ?? ''), 'image/');
        $token = strtolower(trim((string) $reportToken));
        $anexo['visualizar_url'] = preg_match('/^[a-f0-9]{48}$/', $token) === 1
            ? '/reports/r/' . rawurlencode($token) . '/exames-complementares'
            : '/api/gestao-exames/exames-complementares/' . (int) $anexo['id'] . '/arquivo';
        return $anexo;
    }

    private function caminhoAbsolutoSeguro(string $relativo): ?string
    {
        if ($relativo === '' || str_contains($relativo, "\0")) return null;
        $base = realpath(BASE_PATH . '/storage/uploads/exames_complementares');
        $arquivo = $base === false ? false : realpath($base . '/' . ltrim($relativo, '/'));
        $prefixo = $base === false ? '' : rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $arquivo !== false && is_file($arquivo) && strncmp($arquivo, $prefixo, strlen($prefixo)) === 0 ? $arquivo : null;
    }

    private function validarArquivo(array $file): array
    {
        $erro = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'arquivo_muito_grande' : ($erro === UPLOAD_ERR_NO_FILE ? 'arquivo_ausente' : 'erro_upload')];
        $tmp = (string) ($file['tmp_name'] ?? ''); $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) return ['ok' => false, 'error' => 'arquivo_invalido'];
        if ($size <= 0) $size = (int) filesize($tmp);
        if ($size <= 0) return ['ok' => false, 'error' => 'arquivo_invalido'];
        if ($size > self::MAX_BYTES) return ['ok' => false, 'error' => 'arquivo_muito_grande'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = $finfo ? (string) finfo_file($finfo, $tmp) : ''; if ($finfo) finfo_close($finfo);
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::MIME_EXTENSOES[$mime]) || !in_array($ext, self::MIME_EXTENSOES[$mime], true)) return ['ok' => false, 'error' => 'tipo_invalido'];
        return ['ok' => true, 'mime_type' => $mime, 'extensao' => $ext, 'tamanho_bytes' => $size];
    }

    private function moverUpload(string $origem, string $destino): bool
    {
        return PHP_SAPI === 'cli' ? (@rename($origem, $destino) || @copy($origem, $destino)) : @move_uploaded_file($origem, $destino);
    }

    private function normalizarNomeOriginal(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[\\x00-\\x1F\\x7F]+/u', '_', $nome) ?: 'exame_complementar';
        $nome = trim($nome, '. ');
        return $nome === '' ? 'exame_complementar' : (function_exists('mb_substr') ? mb_substr($nome, 0, 255) : substr($nome, 0, 255));
    }

    private function formatarTamanho(int $bytes): string
    {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        return $bytes . ' B';
    }
}
