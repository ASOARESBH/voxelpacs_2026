<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo ausente: ' . $relative);
    }
    return (string) file_get_contents($path);
};

$estudos = $read('app/Controllers/EstudosController.php');
$view = $read('app/Views/estudos/index.php');
$access = $read('app/Services/ReportAccessService.php');
$service = $read('app/Services/ReportService.php');

$expect(str_contains($estudos, "SqlHelper::hasTable(Database::getInstance(), 'pacs_report_peer_reviews')"), 'Worklist não detecta tabela Peer Review de forma compatível.');
$expect(str_contains($estudos, "pr.tenant_id = e.tenant_id"), 'Fila Peer Review não está escopada por tenant.');
$expect(str_contains($estudos, "pr.estudo_id = e.id"), 'Fila Peer Review não está vinculada ao estudo atual.');
$expect(str_contains($estudos, "pr.status = 'aberta'"), 'Fila Peer Review não exige ciclo aberto.');
$expect(str_contains($estudos, 'OR {$peerReviewClause})'), 'Fila normal não combina explicitamente com a exceção Peer Review.');

$expect(str_contains($view, '$peerReviewAberta'), 'View não consome o estado técnico do ciclo aberto.');
$expect(str_contains($view, '($peerReviewAberta && $sit === \'peer_review\')'), 'View não libera a ação para Peer Review compartilhado.');
$expect(str_contains($view, '$estudoPertenceAoMedico && in_array($sit, [\'assinado\', \'liberado\'], true)'), 'Laudo normal perdeu a exigência de ownership.');

$expect(str_contains($access, 'SqlHelper::hasTable($this->pdo, \'pacs_report_peer_reviews\')'), 'Autorização não tem fallback para schema sem Peer Review.');
$expect(str_contains($access, 'pr.tenant_id = e.tenant_id'), 'Autorização Peer Review não está escopada por tenant.');
$expect(str_contains($access, 'pr.report_id = r.id'), 'Autorização Peer Review não está vinculada ao report.');
$expect(str_contains($access, 'pr.status = \'aberta\''), 'Autorização não exige ciclo Peer Review aberto.');
$expect(str_contains($access, '!$this->isOpenPeerReview($resource)'), 'Ownership normal não está preservado fora de Peer Review.');

$expect(substr_count($service, '$sharedPeerReview = $reportSituacao === \'peer_review\' && $peerReviewAberto !== null;') >= 2, 'Salvar e assinar não compartilham a mesma exceção controlada.');
$expect(str_contains($service, '&& !$sharedPeerReview'), 'Service não mantém fail-closed para laudos fora de Peer Review.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: contrato estático de Peer Review compartilhado validado.\n";
