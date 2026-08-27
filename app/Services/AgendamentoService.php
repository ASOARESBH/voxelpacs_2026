<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Database;
use App\Core\SqlHelper;

/**
 * MVP de Agendamentos: pedido planejado separado do estudo DICOM recebido.
 * A publicação MWL é deliberadamente bloqueada até infraestrutura aprovada.
 */
final class AgendamentoService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function listar(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.accession_number, a.patient_name, a.patient_birth_date, a.modalidade,
                    a.data_agendada, a.hora_agendada, a.situacao, a.mwl_status, a.created_at,
                    COALESCE(NULLIF(u.nome_fantasia, ''), NULLIF(u.razao_social, ''), u.nome, '—') AS unidade_nome
             FROM bi_agendamentos a
             JOIN bi_unidades u ON u.id = a.unidade_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = :tenant_id
             ORDER BY CASE a.situacao WHEN 'agendado' THEN 0 WHEN 'realizado' THEN 1 ELSE 2 END,
                      a.data_agendada ASC,
                      CASE WHEN a.hora_agendada IS NULL THEN 1 ELSE 0 END ASC,
                      a.hora_agendada ASC, a.id DESC
             LIMIT 200"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function listarUnidades(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, pacs_id, COALESCE(NULLIF(nome_fantasia, ''), NULLIF(razao_social, ''), nome, '—') AS nome
             FROM bi_unidades
             WHERE tenant_id = :tenant_id AND ativo = 1 AND pacs_id IS NOT NULL
             ORDER BY nome ASC"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Modalidades são reunidas das autorizações de Issuer e dos estudos do próprio tenant. */
    public function listarModalidades(int $tenantId): array
    {
        $values = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT modalidade FROM bi_tenant_issuer_modalidades
                 WHERE tenant_id = :tenant_id AND status = 'ativo'"
            );
            $stmt->execute([':tenant_id' => $tenantId]);
            $values = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            // Instalações anteriores à migration de Issuer continuam suportadas.
        }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT modalities FROM bi_pacs_estudos
             WHERE tenant_id = :tenant_id AND modalities IS NOT NULL AND modalities <> ''"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $stored) {
            foreach (preg_split('/[\\\\,]/', (string) $stored) ?: [] as $modality) {
                $values[] = $modality;
            }
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = strtoupper(trim((string) $value));
            if (preg_match('/^[A-Z0-9]{2,16}$/', $value)) {
                $normalized[$value] = true;
            }
        }
        $modalities = array_keys($normalized);
        sort($modalities, SORT_STRING);
        return $modalities;
    }

    public function criar(int $tenantId, int $userId, array $input): int
    {
        $unidadeId = (int) ($input['unidade_id'] ?? 0);
        $unidade = $this->unidadeDoTenant($tenantId, $unidadeId);
        if ($unidade === null) {
            throw new \InvalidArgumentException('agendamentos.erro_unidade');
        }

        $modalidade = strtoupper(trim((string) ($input['modalidade'] ?? '')));
        if (!in_array($modalidade, $this->listarModalidades($tenantId), true)) {
            throw new \InvalidArgumentException('agendamentos.erro_modalidade');
        }

        $patientName = $this->nomeDicom((string) ($input['patient_name'] ?? ''));
        if ($patientName === '') {
            throw new \InvalidArgumentException('agendamentos.erro_nome');
        }
        $birthDate = $this->data((string) ($input['patient_birth_date'] ?? ''), false);
        $scheduledDate = $this->data((string) ($input['data_agendada'] ?? ''), true);
        $scheduledTime = $this->hora((string) ($input['hora_agendada'] ?? ''));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $accession = $this->novoAccessionNumber();
            $patientId = $this->novoPatientId();
            try {
                $sql =
                    "INSERT INTO bi_agendamentos
                        (tenant_id, unidade_id, pacs_id, accession_number, patient_id, patient_name,
                         patient_birth_date, modalidade, data_agendada, hora_agendada, situacao, mwl_status, criado_por)
                     VALUES
                        (:tenant_id, :unidade_id, :pacs_id, :accession_number, :patient_id, :patient_name,
                         :patient_birth_date, :modalidade, :data_agendada, :hora_agendada, 'agendado', 'aguardando_infraestrutura', :criado_por)";
                if (SqlHelper::isPostgres()) {
                    $sql .= ' RETURNING id';
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenantId,
                    ':unidade_id' => $unidadeId,
                    ':pacs_id' => (int) $unidade['pacs_id'],
                    ':accession_number' => $accession,
                    ':patient_id' => $patientId,
                    ':patient_name' => $patientName,
                    ':patient_birth_date' => $birthDate,
                    ':modalidade' => $modalidade,
                    ':data_agendada' => $scheduledDate,
                    ':hora_agendada' => $scheduledTime,
                    ':criado_por' => $userId ?: null,
                ]);
                $id = SqlHelper::isPostgres() ? (int) $stmt->fetchColumn() : (int) $this->pdo->lastInsertId();
                AuditLogger::log('agendamento.criar', 'agendamento', $id, [
                    'unidade_id' => $unidadeId,
                    'pacs_id' => (int) $unidade['pacs_id'],
                    'modalidade' => $modalidade,
                    'situacao' => 'agendado',
                    'mwl_status' => 'aguardando_infraestrutura',
                ], $tenantId, 'sistema');
                return $id;
            } catch (\PDOException $exception) {
                if ($attempt === 2 || !$this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }
        throw new \RuntimeException('agendamentos.erro_salvar');
    }

    public function cancelar(int $tenantId, int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE bi_agendamentos
             SET situacao = 'cancelado', cancelado_por = :user_id, cancelado_em = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id AND situacao = 'agendado'"
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':user_id' => $userId ?: null]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        AuditLogger::log('agendamento.cancelar', 'agendamento', $id, [
            'situacao' => 'cancelado',
        ], $tenantId, 'sistema');
        return true;
    }

    /** Chamado pela sincronização após o estudo já ter sido persistido e roteado. */
    public static function marcarRealizadoPorEstudo(\PDO $pdo, ?int $tenantId, int $pacsId, ?string $accession, int $estudoId): void
    {
        $accession = trim((string) $accession);
        if (!$tenantId || $pacsId <= 0 || $estudoId <= 0 || $accession === '') {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE bi_agendamentos
                 SET situacao = 'realizado', estudo_id = :estudo_id, realizado_em = NOW(), updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND pacs_id = :pacs_id AND accession_number = :accession
                   AND situacao = 'agendado' AND estudo_id IS NULL"
            );
            $stmt->execute([
                ':estudo_id' => $estudoId,
                ':tenant_id' => $tenantId,
                ':pacs_id' => $pacsId,
                ':accession' => $accession,
            ]);
            if ($stmt->rowCount() === 1) {
                AuditLogger::log('agendamento.correlacionar_estudo', 'agendamento', null, [
                    'estudo_id' => $estudoId,
                    'resultado' => 'realizado',
                ], $tenantId, 'sistema');
            }
        } catch (\Throwable $exception) {
            // A ausência temporária da tabela não deve interromper a importação DICOM.
        }
    }

    private function unidadeDoTenant(int $tenantId, int $unidadeId): ?array
    {
        if ($unidadeId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, pacs_id FROM bi_unidades WHERE id = :id AND tenant_id = :tenant_id AND ativo = 1 AND pacs_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute([':id' => $unidadeId, ':tenant_id' => $tenantId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function data(string $value, bool $mustNotBePast): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new \InvalidArgumentException('agendamentos.erro_data');
        }
        $today = new \DateTimeImmutable('today');
        if ($mustNotBePast ? $date < $today : $date > $today) {
            throw new \InvalidArgumentException($mustNotBePast ? 'agendamentos.erro_data_agendada' : 'agendamentos.erro_nascimento');
        }
        return $date->format('Y-m-d');
    }

    private function hora(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$time || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new \InvalidArgumentException('agendamentos.erro_hora');
        }
        return $time->format('H:i:s');
    }

    private function nomeDicom(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s^\-.]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', '^', $value) ?? '';
        return mb_substr(trim($value, '^'), 0, 64, 'UTF-8');
    }

    private function novoAccessionNumber(): string
    {
        return 'VX' . gmdate('ymd') . strtoupper(bin2hex(random_bytes(4)));
    }

    private function novoPatientId(): string
    {
        return 'VXP' . gmdate('ymd') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function isUniqueViolation(\PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
