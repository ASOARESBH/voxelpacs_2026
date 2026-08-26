<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\RequestAuditContext;
use App\Core\Database;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupSVG;

/**
 * Emite documentos de auditoria verificáveis sem persistir o token público.
 * O QR Code aponta para uma rota pública de consulta sem dados clínicos.
 */
final class RelatorioAuditoriaEmissaoService
{
    private const TIPOS = ['acesso', 'estudos', 'clinica'];
    private const FORMATOS = ['pdf', 'csv'];
    private const DIAS_VALIDADE = 365;
    private const ARQUIVO_CHAVE = '/etc/voxelpacs/audit-export-signing-key';

    public function emitir(int $tenantId, ?int $usuarioId, string $tipo, string $formato, array $filtros, array $linhas): array
    {
        if (!in_array($tipo, self::TIPOS, true) || !in_array($formato, self::FORMATOS, true)) {
            throw new \InvalidArgumentException('Tipo ou formato de exportação inválido.');
        }

        $emitidoEm = new \DateTimeImmutable('now');
        $manifesto = [
            'versao' => 1,
            'tenant_id' => $tenantId,
            'tipo' => $tipo,
            'formato' => $formato,
            'emitido_por' => $usuarioId,
            'emitido_em' => $emitidoEm->format(DATE_ATOM),
            'filtros' => [
                'periodo' => (string) ($filtros['atalho'] ?? ''),
                'data_de' => (string) ($filtros['data_de'] ?? ''),
                'data_ate' => (string) ($filtros['data_ate'] ?? ''),
                'filtra_usuario' => !empty($filtros['usuario_id']),
                'filtra_grupo' => !empty($filtros['grupo_id']),
            ],
            'linhas' => array_map(static fn(array $linha): array => [
                'id' => (int) ($linha['id'] ?? 0),
                'data' => (string) ($linha['created_at'] ?? ''),
                'acao' => (string) ($linha['action'] ?? ''),
                'entidade' => (string) ($linha['entity'] ?? ''),
                'entidade_id' => (int) ($linha['entity_id'] ?? 0),
                'autor_id' => (int) ($linha['user_id'] ?? 0),
                'ip_hash' => hash('sha256', (string) ($linha['ip'] ?? '')),
                'regiao' => (string) ($linha['region_code'] ?? ''),
                'contexto_hash' => hash('sha256', (string) ($linha['details'] ?? '')),
            ], $linhas),
        ];

        $manifestoJson = json_encode($manifesto, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $manifestoHash = hash('sha256', $manifestoJson);
        $assinatura = hash_hmac('sha256', $manifestoHash, $this->chaveAssinatura());
        $token = $this->novoToken();
        $codigoPublico = 'AUD-' . $emitidoEm->format('Ymd') . '-' . strtoupper(substr(hash('sha256', $token), 0, 12));
        $expiraEm = $emitidoEm->modify('+' . self::DIAS_VALIDADE . ' days');
        $contexto = RequestAuditContext::metadata();

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO bi_audit_report_exports
                (tenant_id, requested_by_user_id, report_type, export_format, public_code, token_hash, manifest_hash, manifest_signature, rows_count, issued_at, expires_at, issued_ip, request_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             RETURNING id, issued_at'
        );
        $stmt->execute([
            $tenantId,
            $usuarioId,
            $tipo,
            $formato,
            $codigoPublico,
            hash('sha256', $token),
            $manifestoHash,
            $assinatura,
            count($linhas),
            $emitidoEm->format('Y-m-d H:i:sP'),
            $expiraEm->format('Y-m-d H:i:sP'),
            $contexto['ip'] ?? null,
            $contexto['request_id'] ?? null,
        ]);
        $registro = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'id' => (int) ($registro['id'] ?? 0),
            'codigo_publico' => $codigoPublico,
            'token' => $token,
            'url_validacao' => $this->urlValidacao($token),
            'qr_data_uri' => $this->qrDataUri($this->urlValidacao($token)),
            'emitido_em' => $emitidoEm,
            'expira_em' => $expiraEm,
            'manifesto_hash_curto' => strtoupper(substr($manifestoHash, 0, 16)),
            'total_linhas' => count($linhas),
        ];
    }

    /**
     * Consulta pública propositalmente mínima: confirma emissão, sem filtros,
     * endereço IP, usuário, eventos detalhados ou dados de paciente.
     */
    public function validar(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return ['status' => 'invalido'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT e.*, t.nome AS tenant_nome, t.razao_social AS tenant_razao_social
                   FROM bi_audit_report_exports e
                   INNER JOIN bi_tenants t ON t.id = e.tenant_id
                  WHERE e.token_hash = ?
                  FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $token)]);
            $registro = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$registro) {
                $pdo->commit();
                return ['status' => 'invalido'];
            }
            if (strtotime((string) $registro['expires_at']) < time()) {
                $pdo->commit();
                return ['status' => 'expirado', 'codigo_publico' => (string) $registro['public_code']];
            }
            if (!hash_equals($this->assinarHash((string) $registro['manifest_hash']), (string) $registro['manifest_signature'])) {
                $pdo->commit();
                return ['status' => 'integridade_invalida'];
            }

            $primeiraValidacao = empty($registro['first_validated_at']);
            $agora = new \DateTimeImmutable('now');
            $upd = $pdo->prepare(
                'UPDATE bi_audit_report_exports
                    SET first_validated_at = COALESCE(first_validated_at, ?),
                        last_validated_at = ?,
                        validation_count = validation_count + 1
                  WHERE id = ?'
            );
            $upd->execute([$agora->format('Y-m-d H:i:sP'), $agora->format('Y-m-d H:i:sP'), $registro['id']]);
            $pdo->commit();

            return [
                'status' => 'valido',
                'primeira_validacao' => $primeiraValidacao,
                'codigo_publico' => (string) $registro['public_code'],
                'tenant_nome' => (string) ($registro['tenant_razao_social'] ?: $registro['tenant_nome']),
                'tipo' => (string) $registro['report_type'],
                'formato' => strtoupper((string) $registro['export_format']),
                'emitido_em' => new \DateTimeImmutable((string) $registro['issued_at']),
                'validado_em' => $agora,
                'primeira_validacao_em' => $primeiraValidacao ? $agora : new \DateTimeImmutable((string) $registro['first_validated_at']),
                'total_linhas' => (int) $registro['rows_count'],
                'codigo_integridade' => strtoupper(substr((string) $registro['manifest_hash'], 0, 16)),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function identidadeTenant(int $tenantId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT nome, razao_social, cnpj, logo_path FROM bi_tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $logo = $this->logoDataUri((string) ($tenant['logo_path'] ?? ''));
        return [
            'nome' => (string) ($tenant['nome'] ?? ''),
            'razao_social' => (string) ($tenant['razao_social'] ?? ''),
            'cnpj' => (string) ($tenant['cnpj'] ?? ''),
            'logo_data_uri' => $logo,
        ];
    }

    private function novoToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function chaveAssinatura(): string
    {
        $segredo = $_ENV['AUDIT_EXPORT_SIGNING_KEY'] ?? $_ENV['JWT_SECRET'] ?? null;
        if (!is_string($segredo) || $segredo === '') {
            $segredo = getenv('AUDIT_EXPORT_SIGNING_KEY') ?: getenv('JWT_SECRET');
        }
        if (!is_string($segredo) || $segredo === '') {
            $segredo = is_readable(self::ARQUIVO_CHAVE) ? trim((string) file_get_contents(self::ARQUIVO_CHAVE)) : null;
        }
        if (!is_string($segredo) || $segredo === '') {
            throw new \RuntimeException('Chave de assinatura de exportação não configurada.');
        }
        return hash('sha256', 'voxelpacs:audit-export:v1:' . $segredo);
    }

    private function assinarHash(string $manifestoHash): string
    {
        return hash_hmac('sha256', $manifestoHash, $this->chaveAssinatura());
    }

    private function urlValidacao(string $token): string
    {
        $base = rtrim((string) (getenv('APP_URL') ?: 'https://server.voxelpacs.com.br'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with($base, 'https://')) {
            $base = 'https://server.voxelpacs.com.br';
        }
        return $base . '/validar/auditoria/' . rawurlencode($token);
    }

    private function qrDataUri(string $url): string
    {
        $opcoes = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 4,
            'addQuietzone' => true,
        ]);
        $svg = (new QRCode($opcoes))->render($url);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function logoDataUri(string $logoPath): ?string
    {
        $path = trim(str_replace('\\', '/', $logoPath));
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_contains($path, '..')) {
            return null;
        }
        $publico = realpath(dirname(__DIR__, 2) . '/public');
        $arquivo = realpath($publico . '/' . ltrim($path, '/'));
        if (!$publico || !$arquivo || !str_starts_with($arquivo, $publico . DIRECTORY_SEPARATOR) || !is_file($arquivo) || filesize($arquivo) > 1024 * 1024) {
            return null;
        }
        $mime = mime_content_type($arquivo) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($arquivo));
    }
}
