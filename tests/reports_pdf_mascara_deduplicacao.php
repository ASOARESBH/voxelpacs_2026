<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$dispatcher = $root . '/app/Views/reports/pdf.php';
$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$tecnicaAtual = '<p>Aquisição volumétrica multislice de alta resolução, sem uso de meio de contraste iodado endovenoso.</p>';
$achadosAtuais = '<p>Estruturas vasculares do mediastino de calibre e trajeto preservados.</p>';
$impressaoAtual = '<p>Ausência de alterações significativas detectáveis pelo método.</p>';
$corpoAtual = '<h4>Técnica</h4>' . $tecnicaAtual
    . '<h4>Achados</h4>' . $achadosAtuais
    . '<h4>Impressão</h4>' . $impressaoAtual;

$report = [
    'template_id' => 77,
    'corpo_laudo' => $corpoAtual,
    // Campos históricos deliberadamente divergentes: não podem ser mesclados
    // ao corpo atual e causar repetição na impressão.
    'secao_tecnica' => $tecnicaAtual . $impressaoAtual,
    'secao_achados' => $tecnicaAtual . $achadosAtuais,
    'secao_conclusao' => $impressaoAtual,
    'patient_name' => 'Paciente de Teste',
    'study_description' => 'TC Tórax',
    'modalities' => 'CT',
    'tenant_nome' => 'Empresa de Teste',
];
$templateCodigo = 'moderno_lateral';
$download = false;

ob_start();
require $dispatcher;
$html = (string) ob_get_clean();

$require(substr_count($html, 'Aquisição volumétrica multislice de alta resolução') === 1,
    'PDF mesclou conteúdo histórico e repetiu a Técnica atual.');
$require(substr_count($html, 'Estruturas vasculares do mediastino de calibre e trajeto preservados.') === 1,
    'PDF mesclou conteúdo histórico e repetiu os Achados atuais.');
$require(substr_count($html, 'Ausência de alterações significativas detectáveis pelo método.') === 1,
    'PDF mesclou conteúdo histórico e repetiu a Impressão atual.');
$require(strpos($html, '<h2 class="pdf-clinical-section-title">') === false,
    'PDF reinterpretou o corpo atual do editor como seções legadas.');

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_PDF_MASCARA_DEDUPLICACAO_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_PDF_MASCARA_DEDUPLICACAO_OK\n");
