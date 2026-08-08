<?php
namespace App\Services;

use App\Core\Logger;
use App\Repositories\MedicoAssinaturaRepository;

/**
 * MedicoAssinaturaService — regras de negócio da assinatura do médico
 * (upload de imagem, assinatura livre via canvas, exclusividade de ativação).
 *
 * Armazenamento: storage/uploads/assinaturas/{tenant_id}/{medico_id}/ — fora
 * de public/, nunca servido direto pelo webserver. Diferente do padrão de
 * UnidadesController::processarLogoUpload() (que salva em public/uploads/,
 * acessível sem autenticação) — decisão deliberada, assinatura tem peso
 * jurídico maior que logo de clínica. Preview passa por
 * MedicoAssinaturaController::preview(), que autentica antes de fazer stream.
 */
class MedicoAssinaturaService
{
    public const TIPOS_VALIDOS = ['imagem', 'livre', 'certificado'];
    public const TIPOS_COM_ARQUIVO = ['imagem', 'livre']; // certificado não tem upload nesta entrega
    private const UPLOAD_MAX_MB = 2; // mesmo limite já usado em UnidadesController::UPLOAD_MAX_MB

    private MedicoAssinaturaRepository $repo;

    public function __construct()
    {
        $this->repo = new MedicoAssinaturaRepository();
    }

    /** Estado dos 3 blocos para a view (existe/ativa/preview de cada tipo). */
    public function listar(int $medicoId, int $tenantId): array
    {
        $porTipo = [];
        foreach ($this->repo->findByMedicoId($medicoId, $tenantId) as $row) {
            $porTipo[$row['tipo']] = $row;
        }

        $resultado = [];
        foreach (self::TIPOS_VALIDOS as $tipo) {
            $row = $porTipo[$tipo] ?? null;
            $resultado[$tipo] = [
                'existe'      => $row !== null && !empty($row['caminho_arquivo']),
                'ativa'       => $row !== null && (bool) $row['ativa'],
                'ativado_em'  => $row['ativado_em'] ?? null,
                'preview_url' => ($row && !empty($row['caminho_arquivo']))
                    ? "/medicos/{$medicoId}/assinatura/{$tipo}/preview"
                    : null,
            ];
        }
        return $resultado;
    }

    /**
     * Upload da assinatura tipo "imagem" (JPG/JPEG). Valida conteúdo real do
     * arquivo (finfo, não extensão) — mesma técnica de UnidadesController.
     *
     * @param array $file entrada de $_FILES['arquivo']
     */
    public function salvarImagem(int $medicoId, int $tenantId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'erro_upload'];
        }

        $maxBytes = self::UPLOAD_MAX_MB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'arquivo_muito_grande'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime !== 'image/jpeg') {
            return ['ok' => false, 'error' => 'tipo_invalido'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            return ['ok' => false, 'error' => 'tipo_invalido'];
        }

        $dir = $this->diretorioMedico($tenantId, $medicoId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Logger::error('[MedicoAssinaturaService::salvarImagem] Falha ao criar diretório', ['dir' => $dir]);
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        $filename = 'imagem_' . time() . '.jpg';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        $this->removerArquivoAnterior($medicoId, $tenantId, 'imagem');
        $this->repo->upsertArquivo($medicoId, $tenantId, 'imagem', "{$tenantId}/{$medicoId}/{$filename}");

        return ['ok' => true];
    }

    /**
     * Salva a assinatura tipo "livre" (canvas → PNG em base64 data URI).
     * Valida magic bytes reais do PNG, não confia no prefixo do data URI.
     */
    public function salvarLivre(int $medicoId, int $tenantId, string $pngBase64): array
    {
        if (!str_starts_with($pngBase64, 'data:image/png;base64,')) {
            return ['ok' => false, 'error' => 'formato_invalido'];
        }

        $binario = base64_decode(substr($pngBase64, strlen('data:image/png;base64,')), true);
        if ($binario === false || $binario === '') {
            return ['ok' => false, 'error' => 'formato_invalido'];
        }

        $maxBytes = self::UPLOAD_MAX_MB * 1024 * 1024;
        if (strlen($binario) > $maxBytes) {
            return ['ok' => false, 'error' => 'arquivo_muito_grande'];
        }

        // Magic bytes reais do PNG (89 50 4E 47 0D 0A 1A 0A) — não confia no prefixo do data URI.
        if (substr($binario, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
            return ['ok' => false, 'error' => 'formato_invalido'];
        }

        $dir = $this->diretorioMedico($tenantId, $medicoId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Logger::error('[MedicoAssinaturaService::salvarLivre] Falha ao criar diretório', ['dir' => $dir]);
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        $filename = 'livre_' . time() . '.png';
        if (file_put_contents($dir . '/' . $filename, $binario) === false) {
            return ['ok' => false, 'error' => 'falha_ao_salvar'];
        }

        $this->removerArquivoAnterior($medicoId, $tenantId, 'livre');
        $this->repo->upsertArquivo($medicoId, $tenantId, 'livre', "{$tenantId}/{$medicoId}/{$filename}");

        return ['ok' => true];
    }

    /** Caminho absoluto no disco de um caminho_arquivo relativo salvo no banco. */
    public function caminhoAbsoluto(string $caminhoRelativo): string
    {
        return BASE_PATH . '/storage/uploads/assinaturas/' . $caminhoRelativo;
    }

    public function buscarPorTipo(int $medicoId, int $tenantId, string $tipo): ?array
    {
        return $this->repo->findByTipo($medicoId, $tenantId, $tipo);
    }

    /** Usado pela integração 4(a) com ReportService::assinar() — checkpoint futuro. */
    public function buscarAtiva(int $medicoId, int $tenantId): ?array
    {
        return $this->repo->findAtiva($medicoId, $tenantId);
    }

    /**
     * Ativa um tipo — BLOQUEIA se outro tipo já estiver ativo, pedindo pra
     * desativar primeiro (decisão de UX deliberada do pedido original: nunca
     * trocar automático/silencioso). Bloqueia também 'certificado' (não
     * funcional nesta entrega) e ativar um tipo sem arquivo salvo ainda.
     */
    public function ativar(int $medicoId, int $tenantId, string $tipo): array
    {
        if (!in_array($tipo, self::TIPOS_COM_ARQUIVO, true)) {
            return ['ok' => false, 'error' => 'tipo_nao_disponivel'];
        }
        $registro = $this->repo->findByTipo($medicoId, $tenantId, $tipo);
        if (!$registro || empty($registro['caminho_arquivo'])) {
            return ['ok' => false, 'error' => 'assinatura_nao_cadastrada'];
        }

        $ativaAtual = $this->repo->findAtiva($medicoId, $tenantId);
        if ($ativaAtual && $ativaAtual['tipo'] === $tipo) {
            return ['ok' => true]; // já é a ativa — idempotente, não é erro
        }
        if ($ativaAtual) {
            return ['ok' => false, 'error' => 'outra_assinatura_ativa', 'tipo_ativo' => $ativaAtual['tipo']];
        }

        $ok = $this->repo->ativar($medicoId, $tenantId, $tipo);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'falha_ao_ativar'];
    }

    public function desativar(int $medicoId, int $tenantId, string $tipo): array
    {
        $ok = $this->repo->desativar($medicoId, $tenantId, $tipo);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'nao_estava_ativa'];
    }

    private function diretorioMedico(int $tenantId, int $medicoId): string
    {
        return BASE_PATH . "/storage/uploads/assinaturas/{$tenantId}/{$medicoId}";
    }

    private function removerArquivoAnterior(int $medicoId, int $tenantId, string $tipo): void
    {
        $existente = $this->repo->findByTipo($medicoId, $tenantId, $tipo);
        if ($existente && !empty($existente['caminho_arquivo'])) {
            $path = $this->caminhoAbsoluto($existente['caminho_arquivo']);
            if (is_file($path)) @unlink($path);
        }
    }
}
