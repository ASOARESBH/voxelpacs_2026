<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$rotas = (string) file_get_contents($base . '/routes/web.php');
$controller = (string) file_get_contents($base . '/app/Controllers/GestaoExamesController.php');
$service = (string) file_get_contents($base . '/app/Services/GestaoExamesService.php');
$repository = (string) file_get_contents($base . '/app/Repositories/GestaoExamesRepository.php');
$inicioAuditoriaSolicitante = strpos($repository, 'public function addManualRequestingPhysicianAudit');
$fimAuditoriaSolicitante = strpos($repository, 'public function updateStudyInformation', (int) $inicioAuditoriaSolicitante);
$auditoriaSolicitante = $inicioAuditoriaSolicitante === false
    ? ''
    : substr($repository, (int) $inicioAuditoriaSolicitante, $fimAuditoriaSolicitante === false ? null : $fimAuditoriaSolicitante - (int) $inicioAuditoriaSolicitante);

$checks = [
    'rota_aponta_para_metodo_existente' => str_contains($rotas, "'/api/gestao-exames/estudos/{id}/medico-solicitante', 'GestaoExamesController@alterarSolicitante'"),
    'controller_expoe_metodo' => str_contains($controller, 'public function alterarSolicitante(int $estudoId): void'),
    'controller_aplica_autorizacao' => str_contains($controller, 'if (!$this->autorizadoGerenciar()) return;'),
    'controller_valida_csrf' => str_contains($controller, "t('gestao_gerenciar.erro.csrf')"),
    'service_preserva_tenant' => str_contains($service, 'changeRequestingPhysician(int $studyId, int $tenantId, int $userId, string $value)'),
    'service_audita_sem_expor_valor' => str_contains($service, "'estudo.medico_solicitante_alterado'"),
    'repository_filtra_tenant' => str_contains($repository, 'WHERE id = :study_id AND tenant_id = :tenant_id'),
    'auditoria_fornece_placeholder_study_id' => $auditoriaSolicitante !== '' && str_contains($auditoriaSolicitante, 'VALUES (:tenant_id, :study_id') && str_contains($auditoriaSolicitante, "'study_id' => \$studyId"),
    'auditoria_nao_depende_de_last_insert_id' => $auditoriaSolicitante !== '' && str_contains($auditoriaSolicitante, 'return 0;'),
];

$failed = array_keys(array_filter($checks, static fn(bool $check): bool => !$check));
if ($failed !== []) {
    fwrite(STDERR, 'FALHAS=' . implode(',', $failed) . PHP_EOL);
    exit(1);
}

echo "VALIDACAO_MEDICO_SOLICITANTE=OK\n";
