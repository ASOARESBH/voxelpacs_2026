<?php
/**
 * Regressão estática: lista e badges da Worklist devem respeitar o mesmo período.
 * Executar: php tests/worklist_periodo_badges.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/EstudosController.php');
$header = (string) file_get_contents($root . '/app/Views/layout/pacs_header.php');
$footer = (string) file_get_contents($root . '/app/Views/layout/pacs_footer.php');

$rules = [
    'helper único resolve períodos da Worklist' => str_contains($controller, 'private function resolverIntervaloPeriodo'),
    'lista aplica data inicial pelo campo centralizado' => str_contains($controller, "\$where[]  = \$campoDataPeriodo . ' >= ?';"),
    'lista aplica data final pelo campo centralizado' => str_contains($controller, "\$where[]  = \$campoDataPeriodo . ' <= ?';"),
    'contadores iniciais aplicam data inicial por registro' => str_contains($controller, "\$cWhere[]  = \$campoDataBadge . ' >= ?';"),
    'contadores iniciais aplicam data final por registro' => str_contains($controller, "\$cWhere[]  = \$campoDataBadge . ' <= ?';"),
    'endpoint recebe período normalizado' => str_contains($controller, "\$periodo = trim((string) (\$_GET['periodo'] ?? '30dias'));")
        && str_contains($controller, "[\$dtInicio, \$dtFim] = \$this->resolverIntervaloPeriodo("),
    'endpoint aplica data inicial aos badges' => str_contains($controller, "\$where[]  = \$campoDataBadge . ' >= ?';")
        && str_contains($controller, "\$params[] = \$dtInicio;"),
    'endpoint aplica data final aos badges' => str_contains($controller, "\$where[]  = \$campoDataBadge . ' <= ?';")
        && str_contains($controller, "\$params[] = \$dtFim;"),
    'Assinado e Liberado usam data do ato médico na lista' => str_contains($controller, "in_array(\$situacao, ['assinado', 'liberado'], true)")
        && str_contains($controller, 'COALESCE(DATE(e.laudo_assinado_em), e.study_date)'),
    'badges usam a mesma data por registro terminal' => str_contains($controller, "COALESCE(e.situacao, 'novo') IN ('assinado', 'liberado')")
        && str_contains($controller, 'THEN COALESCE(DATE(e.laudo_assinado_em), e.study_date) ELSE e.study_date END'),
    'cabeçalho encaminha período ao endpoint' => str_contains($header, "['periodo', 'dt_inicio', 'dt_fim']")
        && str_contains($header, "return '/api/estudos/contadores'"),
    'rodapé encaminha período ao endpoint' => str_contains($footer, "['periodo', 'dt_inicio', 'dt_fim']")
        && str_contains($footer, "return '/api/estudos/contadores'"),
];

$failures = array_keys(array_filter($rules, static fn(bool $ok): bool => !$ok));
if ($failures) {
    fwrite(STDERR, 'Regra(s) de período ausente(s): ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Regressão de período entre Worklist e badges verificada com sucesso.\n";
