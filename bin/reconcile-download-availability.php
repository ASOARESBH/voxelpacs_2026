<?php
/** Reconcilia disponibilidade técnica sem ler nem exportar conteúdo DICOM. */
chdir(dirname(__DIR__));
require __DIR__ . '/../app/bootstrap.php';

$options = getopt('', ['tenant:', 'server:', 'limit::']);
$tenantId = (int) ($options['tenant'] ?? 0);
$serverId = (int) ($options['server'] ?? 0);
$limit = max(1, min(250, (int) ($options['limit'] ?? 100)));
if ($tenantId <= 0 || $serverId <= 0) {
    fwrite(STDERR, "usage: --tenant=N --server=N [--limit=100]\n");
    exit(2);
}

$pdo = \App\Core\Database::getInstance();
$serverStmt = $pdo->prepare("SELECT s.url, s.usuario, s.senha, s.timeout FROM bi_pacs_servidor s WHERE s.id=? AND s.ativo=1 AND NOT EXISTS (SELECT 1 FROM bi_tenant_orthanc_cells c WHERE c.servidor_id=s.id AND c.tenant_id<>?) LIMIT 1");
$serverStmt->execute([$serverId, $tenantId]);
$server = $serverStmt->fetch(\PDO::FETCH_ASSOC);
if (!$server) {
    fwrite(STDERR, "source server unavailable for tenant\n");
    exit(3);
}
$orthanc = new \App\Services\OrthancService($server['url'], $server['usuario'] ?: null, $server['senha'] ?: null, (int) ($server['timeout'] ?: 30));
$list = $pdo->prepare("SELECT e.id, e.orthanc_id FROM bi_pacs_estudos e LEFT JOIN bi_pacs_download_availability a ON a.estudo_id=e.id WHERE e.tenant_id=? AND e.servidor_id=? AND e.orthanc_id IS NOT NULL AND e.orthanc_id<>'' AND (a.checked_at IS NULL OR a.checked_at < NOW() - INTERVAL '7 days') ORDER BY e.id ASC LIMIT ?");
$list->bindValue(1, $tenantId, \PDO::PARAM_INT);
$list->bindValue(2, $serverId, \PDO::PARAM_INT);
$list->bindValue(3, $limit, \PDO::PARAM_INT);
$list->execute();
$upsert = $pdo->prepare("INSERT INTO bi_pacs_download_availability (estudo_id,tenant_id,servidor_id,status,checked_at,error_code,updated_at) VALUES (?,?,?,?,NOW(),?,NOW()) ON CONFLICT (estudo_id) DO UPDATE SET status=EXCLUDED.status, checked_at=EXCLUDED.checked_at, error_code=EXCLUDED.error_code, updated_at=NOW()");
$counts = ['checked' => 0, 'available' => 0, 'unavailable' => 0, 'deferred' => 0];
while ($study = $list->fetch(\PDO::FETCH_ASSOC)) {
    $probe = $orthanc->studyExists((string) $study['orthanc_id']);
    $counts['checked']++;
    if (!empty($probe['exists'])) {
        $upsert->execute([(int)$study['id'], $tenantId, $serverId, 'available', null]);
        $counts['available']++;
    } elseif (($probe['code'] ?? 0) === 404) {
        $upsert->execute([(int)$study['id'], $tenantId, $serverId, 'unavailable', 'ORTHANC_RESOURCE_NOT_FOUND']);
        $counts['unavailable']++;
    } else {
        $counts['deferred']++;
    }
}
printf("RECONCILIATION tenant=%d server=%d checked=%d available=%d unavailable=%d deferred=%d\n", $tenantId, $serverId, $counts['checked'], $counts['available'], $counts['unavailable'], $counts['deferred']);
