<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\SqlHelper;

$root = dirname(__DIR__);
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(
    SqlHelper::searchTokens('Aparecido') === ['aparecido'],
    'A busca simples deve ignorar a caixa do nome.'
);
$require(
    SqlHelper::searchTokens(' APARECÍDO   Isaias ') === ['aparecido', 'isaias'],
    'A busca deve ignorar acentos e espaços repetidos.'
);
$require(
    SqlHelper::searchTokens('João-Paulo / D\'Ávila') === ['joao', 'paulo', 'd', 'avila'],
    'A busca deve separar pontuação e manter todos os termos do nome composto.'
);

$originalDriver = $_ENV['DB_DRIVER'] ?? null;
$_ENV['DB_DRIVER'] = 'pgsql';
$postgresExpression = SqlHelper::normalizedSearchExpression('e.patient_name');
$require(
    str_contains($postgresExpression, 'TRANSLATE(LOWER(COALESCE((e.patient_name)::text')
    && str_contains($postgresExpression, "'áàãâäéèêëíìîïóòôõöúùûüçñýÿ'"),
    'PostgreSQL deve normalizar caixa e acentos sem depender da extensão unaccent.'
);

$_ENV['DB_DRIVER'] = 'mysql';
$mysqlExpression = SqlHelper::normalizedSearchExpression('e.patient_name');
$require(
    str_contains($mysqlExpression, 'LOWER(COALESCE(e.patient_name')
    && str_contains($mysqlExpression, 'COLLATE utf8_unicode_ci'),
    'MySQL 5.7 deve manter comparação compatível com a collation legada.'
);

if ($originalDriver === null) {
    unset($_ENV['DB_DRIVER']);
} else {
    $_ENV['DB_DRIVER'] = $originalDriver;
}

$controller = (string) file_get_contents($root . '/app/Controllers/EstudosController.php');
$require(
    str_contains($controller, 'private function aplicarBuscaNormalizada')
    && str_contains($controller, 'SqlHelper::searchTokens($consulta)'),
    'A Worklist deve centralizar a busca por termos normalizados.'
);
$require(
    str_contains($controller, '$this->aplicarBuscaNormalizada($where, $params, $filtros[\'paciente\'], [\'e.patient_name\']);'),
    'O filtro Nome do paciente deve usar a busca normalizada.'
);
$require(
    str_contains($controller, "['e.especialidade', 'e.referring_physician_name']")
    && str_contains($controller, "['e.assumido_por']"),
    'Solicitante e médico responsável devem usar o mesmo contrato de busca.'
);
$require(
    !str_contains($controller, "e.patient_name LIKE ?"),
    'Não pode restar filtro direto case-sensitive para o nome do paciente.'
);

if ($failures !== []) {
    fwrite(STDERR, "ESTUDOS_SEARCH_NORMALIZED_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "ESTUDOS_SEARCH_NORMALIZED_STATIC_OK\n");
