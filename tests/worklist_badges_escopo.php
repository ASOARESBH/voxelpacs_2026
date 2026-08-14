<?php

declare(strict_types=1);

$controller = file_get_contents(dirname(__DIR__) . '/app/Controllers/EstudosController.php');

$regras = [
    'escopo clínico é centralizado' => str_contains(
        $controller,
        'private function resolverEscopoWorklist(?int $tenantId, bool $bypassGlobal, int $usuarioLogadoId): array'
    ),
    'escopo usa as Unidades permitidas do médico' => str_contains(
        $controller,
        'MedicoAccess::allowedInstitutionNames()'
    ),
    'escopo preserva posse exclusiva do médico' => substr_count(
        $controller,
        "COALESCE(e.situacao, 'novo') IN ('novo', 'aberto') OR e.usuario_responsavel_id = ?"
    ) === 1,
    'consulta principal usa o escopo compartilhado' => str_contains(
        $controller,
        '$escopoWorklist  = $this->resolverEscopoWorklist($tenantId, $bypassGlobal, $usuarioLogadoId);'
    ),
    'endpoint de badges usa o escopo compartilhado' => str_contains(
        $controller,
        '$escopoWorklist   = $this->resolverEscopoWorklist($tenantId, $bypassGlobal, $usuarioLogadoId);'
    ),
    'contadores iniciais usam o escopo compartilhado' => str_contains(
        $controller,
        '$cWhere  = $escopoWorklist[\'where\'];'
    ) && str_contains(
        $controller,
        '$cParams = $escopoWorklist[\'params\'];'
    ) && str_contains($controller, '$cBase = implode(\' AND \', $cWhere);'),
    'taxonomia atual cobre os badges novos' => str_contains(
        $controller,
        "'novo'=>0,'aberto'=>0,'pendente'=>0,'a_laudar'=>0,'em_laudo'=>0,"
    ) && str_contains($controller, "'peer_review'=>0,'urgente'=>0,"),
    'endpoint usa alias consistente com escopo' => str_contains(
        $controller,
        "FROM bi_pacs_estudos e WHERE {\$wBase} GROUP BY e.situacao"
    ),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão de escopo entre badges e Worklist verificada com sucesso.\n";
