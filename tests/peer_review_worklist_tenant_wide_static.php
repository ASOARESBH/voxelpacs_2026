<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/EstudosController.php');
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(str_contains($controller, 'private function resolverEscopoWorklist(?int $tenantId, bool $bypassGlobal, int $usuarioLogadoId): array'), 'Escopo central da Worklist ausente.');
$expect(str_contains($controller, '$normalInstitutionClause'), 'Unidade normal não está isolada em ramo próprio.');
$expect(str_contains($controller, '$normalOwnershipClause'), 'Posse normal não está isolada em ramo próprio.');
$expect(str_contains($controller, '(({$normalInstitutionClause} AND {$normalOwnershipClause})'), 'Posse normal não combina unidade de forma explícita.');
$expect(str_contains($controller, 'OR {$peerReviewClause})'), 'Peer Review não está fora do filtro de unidade.');
$expect(str_contains($controller, 'pr.tenant_id = e.tenant_id'), 'Peer Review da Worklist não está escopado pelo tenant do estudo.');
$expect(str_contains($controller, "// Sem tenant ativo, nenhum ramo clínico pode consultar estudos."), 'Worklist sem tenant não está explicitamente deny-by-default.');
$expect(str_contains($controller, "pr.status = 'aberta'"), 'Worklist não exige ciclo Peer Review aberto.');
$expect(str_contains($controller, 'SqlHelper::hasTable(Database::getInstance(), \'pacs_report_peer_reviews\')'), 'Worklist não falha fechada se o schema Peer Review estiver ausente.');
$expect(str_contains($controller, '$rWhere  = $escopoWorklist[\'where\'];'), 'Resumo não reutiliza a coorte principal.');
$expect(str_contains($controller, '$rBase_p = $escopoWorklist[\'params\'];'), 'Resumo não reutiliza os parâmetros da coorte principal.');
$expect(str_contains($controller, 'SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$rBase}'), 'Resumo não usa alias compatível com a coorte parametrizada.');

if ($failures !== []) {
    fwrite(STDERR, "PEER_REVIEW_WORKLIST_TENANT_WIDE_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: Worklist tenant-wide, tenant scope, unidade normal e resumo validados.\n";
