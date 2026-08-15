<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function platformReportsSource(string $path): string
{
    global $root;
    $fullPath = $root . '/' . $path;
    if (!is_file($fullPath)) {
        throw new RuntimeException("Arquivo ausente: {$path}");
    }
    return (string) file_get_contents($fullPath);
}

function platformReportsRequire(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$controller = platformReportsSource('app/Controllers/Platform/PlatformReportsController.php');
$view = platformReportsSource('app/Views/platform/reports/index.php');
$css = platformReportsSource('public/assets/css/platform-reports.css');
$routes = platformReportsSource('routes/platform.php');
$router = platformReportsSource('app/Core/Router.php');
$composer = platformReportsSource('composer.json');

// A rota existe, fica sob o guard global de superadmin e a view que causava 500 passou a existir.
platformReportsRequire($routes, "Router::get('/platform/reports'", 'Rota principal de Platform Reports ausente.');
platformReportsRequire($routes, "Router::get('/platform/reports/exportar'", 'Rota de exportação de Platform Reports ausente.');
platformReportsRequire($router, "strpos(\$uri, '/platform') === 0 && !Auth::isPlatformAdmin()", 'Rotas Platform não possuem guarda central de superadmin.');
platformReportsRequire($controller, "'platform/reports/index'", 'Controller não renderiza a view de Platform Reports.');
platformReportsRequire($view, 'platform-reports-title', 'View estratégica de Platform Reports não foi criada.');

// Consultas cross-tenant são robustas, incluem tenant sem plano e preservam o shape mensal indexado.
platformReportsRequire($controller, 'LEFT JOIN bi_plans p ON p.id = t.plan_id', 'Relatório ainda pode excluir tenant sem plano.');
platformReportsRequire($controller, 'GROUP BY t.id, t.nome, t.status, p.nome', 'Relatório por tenant não é compatível com ONLY_FULL_GROUP_BY.');
platformReportsRequire($controller, 'periodo_ref >= :periodo_inicial', 'Evolução mensal não usa filtro indexável por período.');
platformReportsRequire($controller, "DATE_FORMAT(created_at, '%Y-%m')", 'Crescimento de negócios não é agregado por mês.');
platformReportsRequire($controller, 'serieVazia', 'Série mensal não preenche meses sem movimento.');
platformReportsRequire($controller, 'TOTAL_MESES_EVOLUCAO = 12', 'Janela mensal estratégica não foi definida em 12 meses.');
platformReportsRequire($controller, 'Logger::error', 'Falhas internas do relatório não são registradas em log.');
platformReportsRequire($controller, 'erroRelatorio', 'Falha parcial não é comunicada de modo amigável à view.');

// Exportação rica em duas abas e dependência explicitamente checada.
platformReportsRequire($controller, 'class_exists(Spreadsheet::class)', 'Exportação não protege a dependência PhpSpreadsheet.');
platformReportsRequire($controller, "setTitle('Por negócio')", 'Exportação não contém a visão atual por negócio.');
platformReportsRequire($controller, "setTitle('Evolução mensal')", 'Exportação não contém a série mensal.');
platformReportsRequire($controller, 'createSheet()', 'Exportação não cria a segunda aba estratégica.');
platformReportsRequire($composer, 'phpoffice/phpspreadsheet', 'PhpSpreadsheet não está declarado no Composer.');

// Interface segura e responsiva, sem scripts de terceiros ou CSS inline novo.
platformReportsRequire($view, "View::asset('css/platform-reports.css')", 'View não carrega o CSS próprio de Platform Reports.');
platformReportsRequire($view, 'htmlspecialchars', 'View não escapa campos textuais de negócio.');
platformReportsRequire($view, 'role="alert"', 'View não expõe estado de erro acessível.');
platformReportsRequire($view, '<progress', 'View não exibe gráficos nativos acessíveis para a evolução.');
platformReportsRequire($view, '/platform/reports/exportar', 'Ação de exportar não está disponível na view.');
platformReportsRequire($css, '@media (max-width:820px)', 'CSS não trata a responsividade da tela estratégica.');

fwrite(STDOUT, "PLATFORM_REPORTS_STATIC_OK\n");
