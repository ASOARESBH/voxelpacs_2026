<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * ReportLayoutService — catálogo e resolução do TEMPLATE VISUAL de
 * impressão/PDF do laudo (`report_layout_templates` + `bi_unidades.report_layout_template_id`).
 *
 * Não confundir com `report_templates` (App\Controllers\ReportsController::templates()) —
 * aquele é conteúdo de texto do corpo do laudo ("Máscaras", por médico).
 * Este é só a APARÊNCIA visual (logo, cores, posição da assinatura) da
 * mesma tela/impressão/PDF já existente — nenhum dado clínico passa por aqui.
 */
class ReportLayoutService
{
    /** Código do template aplicado quando a unidade não escolheu nenhum. */
    public const PADRAO = 'classico_centralizado';

    /** Códigos válidos — precisa ter um partial correspondente em reports/pdf/templates/. */
    private const CODIGOS_VALIDOS = [
        'classico_centralizado',
        'moderno_lateral',
        'corporativo_faixa',
        'minimalista',
    ];

    /** Catálogo completo (ativos), para a tela de seleção em Editar Unidade. */
    public function listarCatalogo(): array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query(
                "SELECT id, codigo, nome, descricao FROM report_layout_templates WHERE ativo = 1 ORDER BY ordem, nome"
            );
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[ReportLayoutService::listarCatalogo] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve o código de template a partir do `report_layout_template_id`
     * salvo na unidade (pode vir NULL — unidade sem template escolhido, ou
     * estudo sem unidade vinculada). Sempre retorna um código válido.
     */
    public function resolverCodigo(?int $templateId): string
    {
        if (!$templateId) return self::PADRAO;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT codigo FROM report_layout_templates WHERE id = :id AND ativo = 1 LIMIT 1");
            $stmt->execute(['id' => $templateId]);
            $codigo = $stmt->fetchColumn();
            return ($codigo && in_array($codigo, self::CODIGOS_VALIDOS, true)) ? $codigo : self::PADRAO;
        } catch (\Throwable $e) {
            Logger::error('[ReportLayoutService::resolverCodigo] ' . $e->getMessage(), ['template_id' => $templateId]);
            return self::PADRAO;
        }
    }

    /** Caminho absoluto do partial de renderização para um código de template. */
    public function caminhoPartial(string $codigo): string
    {
        $codigo = in_array($codigo, self::CODIGOS_VALIDOS, true) ? $codigo : self::PADRAO;
        return __DIR__ . "/../Views/reports/pdf/templates/_{$codigo}.php";
    }
}
