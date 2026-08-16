<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Logger;
use App\Repositories\ModalidadeDescricaoRepository;

/** Regras de preenchimento manual de Study Description na Gestão de Exames. */
class ModalidadeDescricaoService
{
    private ModalidadeDescricaoRepository $repo;

    public function __construct(?ModalidadeDescricaoRepository $repo = null)
    {
        $this->repo = $repo ?: new ModalidadeDescricaoRepository();
    }

    /** @return array<int,array{id:int,descricao:string,uso_count:int}> */
    public function suggestions(int $tenantId, string $modalidade): array
    {
        $modalidade = $this->normalizeModalidade($modalidade);
        if ($tenantId <= 0 || $modalidade === '') return [];
        return $this->repo->suggestions($tenantId, $modalidade);
    }

    public function applySingle(int $studyId, int $tenantId, int $userId, string $descricao): array
    {
        $descricao = $this->normalizeDescricao($descricao);
        if ($studyId <= 0 || $tenantId <= 0) return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        if ($descricao === '') return ['ok' => false, 'error' => 'descricao_invalida'];

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $study = $this->repo->lockStudy($studyId, $tenantId);
            if (!$study) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
            }
            $modalidade = $this->normalizeModalidade((string) ($study['modalities'] ?? ''));
            if ($modalidade === '') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'modalidade_indisponivel'];
            }

            $anterior = trim((string) ($study['study_description'] ?? ''));
            $this->repo->updateManualDescription($studyId, $tenantId, $descricao);
            $this->repo->registerSuggestion($tenantId, $modalidade, $descricao, $userId);
            $pdo->commit();

            AuditLogger::log('atualizar_study_description', 'estudo', $studyId, [
                'modalidade' => $modalidade,
                'descricao_anterior' => $anterior,
                'descricao_nova' => $descricao,
                'origem' => 'gestao_exames_manual',
            ], $tenantId);

            return ['ok' => true, 'modalidade' => $modalidade, 'descricao' => $descricao];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[ModalidadeDescricaoService::applySingle] falha', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    public function previewBatch(int $studyId, int $tenantId, string $descricao): array
    {
        $descricao = $this->normalizeDescricao($descricao);
        if ($descricao === '') return ['ok' => false, 'error' => 'descricao_invalida'];

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $study = $this->repo->lockStudy($studyId, $tenantId);
            if (!$study) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
            }
            $modalidade = $this->normalizeModalidade((string) ($study['modalities'] ?? ''));
            if ($modalidade === '') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'modalidade_indisponivel'];
            }
            $total = $this->repo->countBlankByModality($tenantId, $modalidade);
            $pdo->commit();
            return ['ok' => true, 'modalidade' => $modalidade, 'total' => $total, 'descricao' => $descricao];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[ModalidadeDescricaoService::previewBatch] falha', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    public function applyBatch(int $studyId, int $tenantId, int $userId, string $descricao): array
    {
        $preview = $this->previewBatch($studyId, $tenantId, $descricao);
        if (!$preview['ok']) return $preview;

        $modalidade = (string) $preview['modalidade'];
        $descricao = (string) $preview['descricao'];
        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            // A contagem é recalculada e o UPDATE preserva somente descrições vazias.
            $total = $this->repo->updateBlankByModality($tenantId, $modalidade, $descricao);
            $this->repo->registerSuggestion($tenantId, $modalidade, $descricao, $userId);
            $pdo->commit();

            AuditLogger::log('atualizar_study_description_lote', 'modalidade', null, [
                'modalidade' => $modalidade,
                'descricao_nova' => $descricao,
                'total_afetado' => $total,
                'origem' => 'gestao_exames_lote',
            ], $tenantId);

            return ['ok' => true, 'modalidade' => $modalidade, 'total' => $total, 'descricao' => $descricao];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[ModalidadeDescricaoService::applyBatch] falha', [
                'study_id' => $studyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    private function normalizeModalidade(string $modalidade): string
    {
        $modalidade = strtoupper(trim($modalidade));
        // Estudos importados podem carregar mais de uma modalidade (ex.: CR\\OT).
        // A descrição é indexada pela primeira modalidade DICOM válida, como na
        // Worklist, sem aceitar conteúdo livre como código de modalidade.
        if (preg_match('/[A-Z0-9]{1,16}/', $modalidade, $matches)) {
            return (string) $matches[0];
        }
        return '';
    }

    private function normalizeDescricao(string $descricao): string
    {
        $descricao = trim(strip_tags($descricao));
        if (strlen($descricao) < 3 || strlen($descricao) > 255) return '';
        return $descricao;
    }
}
