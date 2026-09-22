<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$access = (string) file_get_contents($root . '/app/Services/ReportAccessService.php');
$service = (string) file_get_contents($root . '/app/Services/ReportService.php');
$medicoAccess = (string) file_get_contents($root . '/app/Core/Access/MedicoAccess.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(str_contains($access, 'private function isTenantWidePeerReview'), 'Exceção tenant-wide não está isolada no serviço central.');
$expect(str_contains($access, '&& $this->isOpenPeerReview($resource)'), 'Exceção tenant-wide não exige ciclo aberto.');
$expect(str_contains($access, "\$perfil === 'medico'"), 'Exceção tenant-wide não exige perfil médico.');
$expect(str_contains($access, '$this->authorizedPeerReviewForStudy($estudo, $authorizedReport)'), 'Segundo gate não valida a evidência do report autorizado.');
$expect(str_contains($access, 'public function isStudyAllowed(object $estudo, bool $requireOwnership = true, ?object $authorizedReport = null)'), 'Contrato do segundo gate não recebe report autorizado.');
$expect(str_contains($service, 'public function carregarParaEdicao(string $studyUid, ?object $authorizedReport = null)'), 'Editor não aceita contexto autorizado opcional.');
$expect(str_contains($service, 'isStudyAllowed($estudo, true, $authorizedReport)'), 'ReportService não passa o contexto ao segundo gate.');
$expect(str_contains($medicoAccess, 'AND ativo = 1'), 'MedicoAccess pode resolver vínculo inativo.');
$expect(str_contains($access, 'MedicoAccess::isInstitutionAllowed'), 'A posse normal não perdeu o gate de unidade.');

$accessService = (new ReflectionClass(App\Services\ReportAccessService::class))->newInstanceWithoutConstructor();
$tenantWide = new ReflectionMethod(App\Services\ReportAccessService::class, 'isTenantWidePeerReview');
$tenantWide->setAccessible(true);
$openPeerReview = (object) ['tenant_id' => 2, 'situacao' => 'peer_review', 'peer_review_aberta' => 1];
$closedPeerReview = (object) ['tenant_id' => 2, 'situacao' => 'peer_review', 'peer_review_aberta' => 0];

$expect($tenantWide->invoke($accessService, $openPeerReview, 2, 'medico', 13) === true, 'Médico ativo do mesmo tenant deveria passar na exceção.');
$expect($tenantWide->invoke($accessService, $openPeerReview, 3, 'medico', 13) === false, 'Médico de outro tenant não pode passar na exceção.');
$expect($tenantWide->invoke($accessService, $closedPeerReview, 2, 'medico', 13) === false, 'Ciclo fechado não pode passar na exceção.');
$expect($tenantWide->invoke($accessService, $openPeerReview, 2, 'admin', null) === false, 'Perfil não médico não deve usar a exceção tenant-wide.');

if ($failures !== []) {
    fwrite(STDERR, "PEER_REVIEW_TENANT_WIDE_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: Peer Review tenant-wide, segundo gate e vínculo médico ativo validados.\n";
