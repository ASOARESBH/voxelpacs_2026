<?php
/**
 * Regressão estática — padrão de navegação Voltar do VOXEL PACS.
 * Executar: php tests/voltar_navegacao_static.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$falhas = [];

function exigirVoltar(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
    }
}

function lerVoltar(string $caminho): string
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Arquivo ausente: ' . $caminho);
    }
    return $conteudo;
}

$helper = lerVoltar($root . '/public/assets/js/shared/voxel-voltar.js');
foreach ([
    'function veioDoMesmoSistema()',
    'global.history.back()',
    'global.location.assign(destino)',
    'function fallbackDoControle(controle)',
    'data-voxel-voltar-skip',
    'global.voxelVoltar = voxelVoltar',
    'function voxelRetornarWorklist(fallbackUrl)',
    'global.voxelRetornarWorklist = voxelRetornarWorklist',
    'data-voxel-return-worklist',
] as $contrato) {
    exigirVoltar(strpos($helper, $contrato) !== false, 'Contrato ausente no helper de retorno: ' . $contrato);
}

$formMedico = lerVoltar($root . '/app/Views/medicos/form.php');
foreach ([
    'const ABAS_MEDICO =',
    'function carregarConteudoAbaMedico(nomeAba)',
    'function ativarAbaMedico(nomeAba)',
    "carregarConteudoAbaMedico(nomeAba);",
    "ativarAbaMedico(ABAS_MEDICO.includes(abaInicial) ? abaInicial : 'dados');",
    "url.searchParams.set('aba', nomeAba);",
    'target="_blank" rel="noopener" title="Visualizar Laudo"',
] as $contrato) {
    exigirVoltar(strpos($formMedico, $contrato) !== false, 'Fluxo de Médico incompleto: ' . $contrato);
}
exigirVoltar(strpos($formMedico, "document.getElementById('tab-mascaras')?.addEventListener('shown.bs.tab'") === false,
    'Carregamento de Máscaras permanece duplicado em listener exclusivo.');

$preview = lerVoltar($root . '/app/Views/mascaras/visualizar.php');
exigirVoltar(strpos($preview, 'data-voxel-voltar="/medicos/<?= $medicoId ?>/edit?aba=mascaras"') !== false,
    'Pré-visualização não declara fallback para a aba Máscaras.');
exigirVoltar(strpos($preview, 'voxel-voltar.js?v=') !== false,
    'Pré-visualização não carrega o helper de retorno.');

foreach ([
    'app/Views/layout/pacs_footer.php',
    'app/Views/layout/platform_footer.php',
    'app/Views/layout/reports_footer.php',
    'app/Views/layout/auth_footer.php',
    'app/Views/layout/portal_footer.php',
    'app/Views/layout/portal_pdf_footer.php',
] as $arquivo) {
    exigirVoltar(strpos(lerVoltar($root . '/' . $arquivo), 'voxel-voltar.js?v=') !== false,
        'Layout não carrega o helper de retorno: ' . $arquivo);
}

$viewer = lerVoltar($root . '/app/Views/estudos/viewer.php');
$moderno = lerVoltar($root . '/app/Views/reports/pdf/templates/_moderno_lateral.php');
$instalar = lerVoltar($root . '/app/Views/estudos/instalar.php');
$tenant = lerVoltar($root . '/app/Views/auth/select_tenant.php');
exigirVoltar(substr_count($viewer, 'data-voxel-voltar="/estudos"') >= 2, 'Viewer não cobre os dois retornos à Worklist.');
exigirVoltar(strpos($viewer, 'voxel-voltar.js?v=') !== false, 'Viewer independente não carrega o helper.');
exigirVoltar(strpos($moderno, 'data-voxel-voltar="<?= htmlspecialchars($reportReturnUrl, ENT_QUOTES) ?>"') !== false
    && strpos($moderno, '/reports/r/') !== false
    && strpos($moderno, 'voxel-voltar.js?v=') !== false,
    'PDF Moderno Lateral não declara retorno seguro ao Laudário por token.');

$reportsHeader = lerVoltar($root . '/app/Views/layout/reports_header.php');
$worklist = lerVoltar($root . '/app/Views/estudos/index.php');
$reportsMain = lerVoltar($root . '/public/assets/js/reports/reports-main.js');
$reportsSignature = lerVoltar($root . '/public/assets/js/reports/reports-signature.js');
exigirVoltar(strpos($reportsHeader, 'data-voxel-return-worklist') !== false,
    'Laudário não declara retorno coordenado à Worklist.');
exigirVoltar(preg_match('/class="wl-btn-laudo[^"].{0,500}target="_blank"/s', $worklist) === 1,
    'Worklist não abre o laudo por token em nova aba.');
exigirVoltar(strpos($reportsMain, "window.open(pdfUrl, 'voxel-laudo-pdf')") !== false,
    'Prévia PDF não reutiliza sua aba nomeada.');
exigirVoltar(strpos($reportsMain, 'window.voxelRetornarWorklist(\'/estudos\')') !== false
    && strpos($reportsSignature, 'window.voxelRetornarWorklist(\'/estudos\')') !== false,
    'Fluxos de liberar ou assinar e fechar não retornam pela Worklist de origem.');
exigirVoltar(strpos($instalar, 'data-voxel-voltar="/estudos"') !== false, 'Página de instalação não declara fallback para Worklist.');
exigirVoltar(strpos($tenant, 'href="/logout" data-voxel-voltar-skip') !== false,
    'Retorno da seleção de empresa não preserva a exceção de logout.');

$padrao = $root . '/SKILL-VOXEL-PACS/patterns/padrao-navegacao-voltar.md';
$moduloMascaras = lerVoltar($root . '/SKILL-VOXEL-PACS/modules/mascaras-laudo.md');
exigirVoltar(is_file($padrao) && strpos(lerVoltar($padrao), 'voxelVoltar(fallbackUrl)') !== false,
    'O padrão oficial de retorno seguro não foi documentado.');
exigirVoltar(strpos($moduloMascaras, 'ativarAbaMedico()') !== false,
    'O módulo de Máscaras não documenta a correção por query string.');

$core = lerVoltar($root . '/app/Core/View.php');
$assetVersion = null;
if (preg_match("/ASSET_VERSION\s*=\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'/", $core, $assetMatch) === 1) {
    $assetVersion = $assetMatch[1];
}
exigirVoltar($assetVersion !== null && version_compare($assetVersion, '2.3.14', '>='),
    'Assets devem possuir versão semântica atualizada para invalidar cache.');

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: retorno seguro, fallback e inicialização de abas validados estaticamente.\n";
