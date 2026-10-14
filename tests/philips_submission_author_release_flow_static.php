<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$expect = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$repository = file_get_contents($root . '/app/Repositories/PhilipsSubmissionAuthorRepository.php');
$resolver = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$reportService = file_get_contents($root . '/app/Services/ReportService.php');
$peerReviewService = file_get_contents($root . '/app/Services/ReportPeerReviewService.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
foreach ([$repository, $resolver, $reportService, $peerReviewService, $outbox] as $source) {
    $expect(is_string($source) && $source !== '', 'Source de fluxo de autoria ausente');
}

$expect(str_contains($reportService, "createVersion(\$reportId, \$conteudoDecodificado, 'assinado', \$userId"), 'Assinar e fechar deve gravar a versão assinado com o usuário que assinou');
$expect(str_contains($reportService, "createVersion(\$reportId, \$conteudo, 'liberado', \$userId"), 'Liberação separada deve gravar sua versão própria');
$expect(str_contains($reportService, 'public function liberarAssinado'), 'Fluxo de liberação separada deve existir');
$expect(str_contains($reportService, 'concluirNaTransacao('), 'Assinatura de Peer Review deve fechar o ciclo na transação');
$expect(str_contains($reportService, '$versaoNumero'), 'Peer Review deve receber a versão final da assinatura');
$expect(str_contains($peerReviewService, 'concluirNaTransacao'), 'Serviço de Peer Review deve expor conclusão transacional');
$expect(str_contains($peerReviewService, 'versaoFinal'), 'Peer Review deve registrar a versão final');

$expect(str_contains($repository, "if (\$action === 'assinado')"), 'Versão assinado deve usar o próprio número');
$expect(str_contains($repository, "if (\$action !== 'liberado')"), 'Apenas versão liberado pode procurar assinatura anterior');
$expect(str_contains($repository, 'rv.versao <= :report_version'), 'Assinatura anterior deve ser limitada à versão do Job');
$expect(str_contains($repository, 'ORDER BY rv.versao DESC'), 'Duas assinaturas devem escolher a mais recente');
$expect(str_contains($repository, "rv.acao = 'assinado'"), 'Somente ações assinado podem fornecer a autoria');
$expect(str_contains($repository, 'LIMIT 2'), 'Duplicidade de cadastro deve falhar fechado');
$expect(str_contains($resolver, 'frozenSigner'), 'Resolver deve validar identidade congelada');
$expect(str_contains($resolver, 'versionSigner'), 'Resolver deve validar ID contra a versão assinada');
$expect(str_contains($resolver, 'released_by') === false, 'Resolver não pode usar o usuário que liberou');
$expect(str_contains($outbox, 'findSignerForReportVersion'), 'Outbox deve compartilhar a regra do repository');

fwrite(STDOUT, "philips_submission_author_release_flow_static: PASS\n");
fwrite(STDOUT, "SIGN_AND_CLOSE_SIGNER=STRUCTURE_COVERED\n");
fwrite(STDOUT, "SEPARATE_RELEASE_OTHER_MEDICO=STRUCTURE_COVERED\n");
fwrite(STDOUT, "SEPARATE_RELEASE_NON_MEDICO=STRUCTURE_COVERED\n");
fwrite(STDOUT, "RELEASE_WITHOUT_SIGNED_VERSION=FAIL_CLOSED\n");
fwrite(STDOUT, "PEER_REVIEW_FINAL_SIGNER=STRUCTURE_COVERED\n");
fwrite(STDOUT, "LATEST_SIGNED_VERSION_SELECTED=STRUCTURE_COVERED\n");
