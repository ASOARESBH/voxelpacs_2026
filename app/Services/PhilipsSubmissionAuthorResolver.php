<?php
declare(strict_types=1);
namespace App\Services;
use App\Contracts\PhilipsSubmissionAuthorLookup;
use App\Repositories\PhilipsSubmissionAuthorRepository;
/**
 * Resolve task_author_* exclusivamente do médico que assinou a versão clínica.
 * O ReferringPhysicianName é o solicitante e nunca é autor do documento.
 */
final class PhilipsSubmissionAuthorResolver
{
    public const SOURCE_SIGNER = 'SIGNER';
    public const SOURCE_UNRESOLVED = 'UNRESOLVED';
    public const DECISION_REAL_AUTHOR_AVAILABLE = 'REAL_AUTHOR_AVAILABLE';
    public const DECISION_AUTHOR_UNRESOLVED = 'AUTHOR_UNRESOLVED';

    public function __construct(
        private readonly PhilipsSubmissionAuthorLookup $authorLookup = new PhilipsSubmissionAuthorRepository()
    ) {
    }

    /** @return array<string,mixed> */
    public function resolve(
        array $payload,
        array $configuration,
        array $deliveryContext,
        array $resolvedMetadata
    ): array {
        return $this->resolveSigner($payload, $deliveryContext);
    }

    /**
     * O diagnóstico no-send usa exatamente a mesma regra de produção. Não há
     * fallback sintético: sem signatário o gerador deve falhar fechado.
     * @return array<string,mixed>
     */
    public function resolveForNoSendDiagnostic(
        array $payload,
        array $configuration,
        array $deliveryContext,
        array $resolvedMetadata
    ): array {
        return $this->resolveSigner($payload, $deliveryContext);
    }

    /** @return array<string,mixed> */
    private function resolveSigner(array $payload, array $deliveryContext): array
    {
        $base = [
            'task_author_id' => null,
            'task_author_humanname_family' => null,
            'task_author_humanname_given' => null,
            'task_author_humanname_middle' => null,
            'author_humanname_flat' => false,
            'author_source' => self::SOURCE_UNRESOLVED,
            'author_decision' => self::DECISION_AUTHOR_UNRESOLVED,
            'author_fallback_used' => 'NO',
            'author_fallback_configuration' => 'NOT_APPLICABLE',
            'task_author_id_resolution' => 'UNRESOLVED',
        ];

        $signer = $this->frozenSigner($payload, $deliveryContext);
        if ($signer === null) {
            $signer = $this->authorLookup->findSignerForReportVersion(
                $this->positiveInteger($deliveryContext['tenant_id'] ?? null) ?? 0,
                $this->positiveInteger($deliveryContext['report_id'] ?? null) ?? 0,
                $this->positiveInteger($deliveryContext['report_version'] ?? null) ?? 0,
                $this->positiveInteger($deliveryContext['estudo_id'] ?? null) ?? 0
            );
        }
        if ($signer === null
            || (int) ($signer['tenant_id'] ?? 0) !== (int) ($deliveryContext['tenant_id'] ?? 0)
            || (int) ($signer['ativo'] ?? 0) !== 1
            || !is_string($signer['nome'] ?? null)
            || trim($signer['nome']) === '') {
            return $base;
        }

        $name = $this->signerName($signer['nome']);
        if ($name === null) {
            return $base;
        }
        return array_replace($base, $name, [
            'task_author_id' => (string) ((int) $signer['id']),
            'author_source' => self::SOURCE_SIGNER,
            'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
            'task_author_id_resolution' => 'SIGNER_ID',
        ]);
    }

    /**
     * Campos congelados são aceitos somente quando a identidade completa do
     * job confere com o contexto atual. Campo parcial ou divergente falha
     * fechado e não é substituído silenciosamente por configuração legada.
     * @return array{id:int,tenant_id:int,nome:string,ativo:int}|null
     */
    private function frozenSigner(array $payload, array $context): ?array
    {
        $fields = [
            'author_signer_id',
            'author_signer_tenant_id',
            'author_signer_report_id',
            'author_signer_report_version',
            'author_signer_study_id',
            'author_signer_name',
        ];
        $present = array_values(array_filter($fields, static fn(string $field): bool => array_key_exists($field, $payload)));
        if ($present === []) {
            return null;
        }
        if (count($present) !== count($fields)) {
            return [];
        }
        $expected = [
            'author_signer_tenant_id' => $context['tenant_id'] ?? null,
            'author_signer_report_id' => $context['report_id'] ?? null,
            'author_signer_report_version' => $context['report_version'] ?? null,
            'author_signer_study_id' => $context['estudo_id'] ?? null,
        ];
        foreach ($expected as $field => $value) {
            if ($this->positiveInteger($payload[$field] ?? null) === null
                || (int) $payload[$field] !== (int) $value) {
                return [];
            }
        }
        $id = $this->positiveInteger($payload['author_signer_id'] ?? null);
        $name = is_string($payload['author_signer_name'] ?? null) ? trim($payload['author_signer_name']) : '';
        if ($id === null || $name === '') {
            return [];
        }
        return [
            'id' => $id,
            'tenant_id' => (int) $payload['author_signer_tenant_id'],
            'nome' => $name,
            'ativo' => 1,
        ];
    }

    /** @return array<string,mixed>|null */
    private function signerName(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('//u', $raw) !== 1) {
            return null;
        }
        // Cadastro médico usa nome civil plano: primeiro token = given;
        // tokens restantes = family, preservando partículas e acentos.
        $tokens = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return null;
        }
        if (count($tokens) === 1) {
            return [
                'task_author_humanname_family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($tokens[0], 'task_author_humanname_family'),
                'task_author_humanname_given' => '',
                'task_author_humanname_middle' => '',
                'author_humanname_flat' => true,
            ];
        }
        try {
            return [
                'task_author_humanname_family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(implode(' ', array_slice($tokens, 1)), 'task_author_humanname_family'),
                'task_author_humanname_given' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($tokens[0], 'task_author_humanname_given'),
                'task_author_humanname_middle' => '',
                'author_humanname_flat' => false,
            ];
        } catch (PhilipsXmlFieldUnresolvedException) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1) {
            return null;
        }
        $integer = (int) trim($value);
        return $integer > 0 ? $integer : null;
    }
}
