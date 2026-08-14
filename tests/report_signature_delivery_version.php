<?php
/**
 * Regressão estática — a assinatura e a devolutiva devem usar a versão
 * recém-criada (>= 1), nunca a versão anterior ou zero.
 * Executar: php tests/report_signature_delivery_version.php
 */
$root = dirname(__DIR__);

function assertText(string $needle, string $content, string $message): void
{
    if (strpos($content, $needle) === false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

function assertNotText(string $needle, string $content, string $message): void
{
    if (strpos($content, $needle) !== false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$service = file_get_contents($root . '/app/Services/ReportService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');

assertNotText('proximaVersao($reportId) - 1', $service,
    'Nenhum fluxo de report pode reutilizar a versão anterior ou zero.');
assertText('$versaoNumero = $this->repo->proximaVersao($reportId);', $service,
    'A assinatura deve usar o próximo número de versão persistível.');
assertText('$this->repo->createVersion($reportId, $conteudoDecodificado, \'assinado\', $userId, $versaoNumero);', $service,
    'A versão assinada deve ser criada antes da devolutiva.');
assertText('$versaoNumero,', $service,
    'A devolutiva deve receber a mesma versão criada na assinatura.');
assertText("'versao_report' => \$versaoNumero ?? null", $service,
    'Falhas transacionais devem registrar a versão para diagnóstico.');
assertText("'devolutiva_dados_insuficientes'", $service,
    'A falha de contrato da devolutiva deve ter código específico.');
assertText("'devolutiva_dados_insuficientes' =>", $controller,
    'O modal deve traduzir a falha específica de devolutiva.');
assertText('if ($tenantId <= 0 || $reportId <= 0 || $estudoId <= 0 || $reportVersion < 1)', $outbox,
    'A outbox deve manter a defesa contra versão inválida.');

fwrite(STDOUT, "Regressão de assinatura e devolutiva por versão verificada com sucesso.\n");
