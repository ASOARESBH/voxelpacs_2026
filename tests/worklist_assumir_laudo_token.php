<?php
/**
 * Regressão estática — assumir estudo deve criar o report/token no mesmo ciclo.
 * Executar: php tests/worklist_assumir_laudo_token.php
 */
$root = dirname(__DIR__);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$controller = file_get_contents($root . '/app/Controllers/EstudosController.php');
$view       = file_get_contents($root . '/app/Views/estudos/index.php');
$routes     = file_get_contents($root . '/routes/web.php');

assertContainsText('SELECT id, tenant_id, situacao, assumido_por,', $controller,
    'A assunção deve carregar o tenant explícito do estudo antes de criar o report.');
assertContainsText('$reportService->getOrCreateReport((object) $estudo, (int) $userId);', $controller,
    'A assunção deve criar ou recuperar report com token no mesmo ciclo.');
assertContainsText("'url'          => \$reportUrl", $controller,
    'A API de assunção deve devolver a URL opaca do Laudário.');
assertContainsText('public function obterUrlLaudoAssumido(): void', $controller,
    'Deve existir recuperação segura para estudos assumidos antes do token.');
assertContainsText('ReportAccessService())->isStudyAllowed($estudo)', $controller,
    'A recuperação de URL deve validar tenant, Unidade e posse médica.');
assertContainsText("Router::post('/api/estudos/laudo-url',  'EstudosController@obterUrlLaudoAssumido');", $routes,
    'A rota de recuperação de URL opaca deve estar registrada.');
assertContainsText('wl-btn-laudo-recuperar', $view,
    'A Worklist deve manter botão Laudo para registros sem token legado.');
assertContainsText("fetch('/api/estudos/laudo-url'", $view,
    'O botão de recuperação deve solicitar URL opaca ao backend.');
assertContainsText("const reportUrl = data.url || '';", $view,
    'A atualização dinâmica deve usar a URL devolvida pelo endpoint de assunção.');

fwrite(STDOUT, "Regressão de assunção e botão Laudo com token verificada com sucesso.\n");
