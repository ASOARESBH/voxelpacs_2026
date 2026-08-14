<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/database/migrations/2026-08-14_bi_modalidade_descricoes_study_description_manual.sql',
    'repo' => $root . '/app/Repositories/ModalidadeDescricaoRepository.php',
    'service' => $root . '/app/Services/ModalidadeDescricaoService.php',
    'controller' => $root . '/app/Controllers/GestaoExamesController.php',
    'sync' => $root . '/app/Services/PacsSyncService.php',
    'routes' => $root . '/routes/web.php',
    'view' => $root . '/app/Views/estudos/index.php',
    'js' => $root . '/public/assets/js/gestao-exames-gerenciar.js',
];

foreach ($files as $name => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Arquivo ausente: {$name}\n");
        exit(1);
    }
}

$contents = array_map('file_get_contents', $files);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
};

$assert(strpos($contents['migration'], 'CREATE TABLE IF NOT EXISTS bi_modalidade_descricoes') !== false, 'migration cria tabela de sugestões');
$assert(strpos($contents['migration'], 'study_description_manual') !== false, 'migration cria marca de preservação manual');
$assert(strpos($contents['migration'], 'uq_tenant_modalidade_descricao') !== false, 'migration impede sugestão duplicada por tenant e modalidade');
$assert(stripos($contents['migration'], 'INFORMATION_SCHEMA') === false, 'migration não usa catálogo proibido no HostGator');

$assert(strpos($contents['repo'], 'tenant_id = :tenant_id') !== false, 'repositório filtra operações por tenant');
$assert(strpos($contents['repo'], 'study_description_manual = 0') !== false, 'lote somente alcança estudos sem correção manual');
$assert(strpos($contents['repo'], "study_description IS NULL OR TRIM(study_description) = ''") !== false, 'lote somente alcança descrições vazias');
$assert(strpos($contents['repo'], 'ON DUPLICATE KEY UPDATE') !== false, 'sugestões reutilizadas incrementam uso sem duplicar cadastro');

$assert(strpos($contents['service'], 'AuditLogger::log') !== false, 'operações individual e em lote são auditadas');
$assert(strpos($contents['service'], 'previewBatch') !== false && strpos($contents['service'], 'applyBatch') !== false, 'serviço separa prévia de confirmação de lote');
$assert(strpos($contents['service'], 'mb_') === false, 'novo serviço não depende de mbstring');

$assert(strpos($contents['controller'], 'autorizadoLoteDescricao') !== false, 'controller aplica autorização exclusiva ao lote');
$assert(strpos($contents['controller'], "\$confirmar !== true") !== false && strpos($contents['controller'], "(string) \$confirmar !== '1'") !== false, 'controller exige confirmação estrita no lote');
$assert(strpos($contents['controller'], 'descricoesPorModalidade') !== false, 'controller oferece sugestões por modalidade');

$assert(strpos($contents['sync'], 'study_description_manual') !== false, 'sincronização reconhece marca manual');
$assert(strpos($contents['sync'], "unset(\$cols['study_description'])") !== false, 'sincronização preserva descrição manual');

$assert(strpos($contents['routes'], '/descricao/previa-lote') !== false && strpos($contents['routes'], '/descricao/lote') !== false, 'rotas de prévia e lote estão registradas');
$assert(strpos($contents['view'], 'id="gerenciarDescricao"') !== false && strpos($contents['view'], 'id="gerenciarDescricaoModal"') !== false, 'view apresenta opção e etapa de descrição');
$assert(strpos($contents['js'], 'confirmarDescricaoLote') !== false && strpos($contents['js'], 'previa-lote') !== false, 'frontend busca prévia e exige confirmação visual');

echo "Regressão de descrição por modalidade na Gestão de Exames verificada com sucesso.\n";
