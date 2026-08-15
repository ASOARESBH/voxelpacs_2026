<?php

use App\Core\View;

$relatorio = is_array($relatorio ?? null) ? $relatorio : [];
$evolucao = is_array($evolucao ?? null) ? $evolucao : [];
$resumo = is_array($resumo ?? null) ? $resumo : [];
$erroRelatorio = isset($erroRelatorio) && is_string($erroRelatorio) ? $erroRelatorio : null;

$formatarMes = static function (string $mes): string {
    try {
        return (new DateTimeImmutable($mes . '-01'))->format('m/Y');
    } catch (Throwable $e) {
        return $mes;
    }
};
$maxExames = max(1, ...array_map(static fn(array $linha): int => (int) ($linha['total_exames'] ?? 0), $evolucao ?: [['total_exames' => 0]]));
$maxReceita = max(1, ...array_map(static fn(array $linha): float => (float) ($linha['receita'] ?? 0), $evolucao ?: [['receita' => 0]]));
?>
<link rel="stylesheet" href="<?= htmlspecialchars(View::asset('css/platform-reports.css')) ?>">

<section class="platform-reports" aria-labelledby="platform-reports-title">
    <header class="platform-reports__header">
        <div>
            <p class="platform-reports__eyebrow"><i class="fa fa-chart-line" aria-hidden="true"></i> Inteligência de negócio</p>
            <h1 id="platform-reports-title">Relatórios Estratégicos</h1>
            <p>Acompanhe a evolução consolidada de receita, volume de exames e novos negócios da plataforma.</p>
        </div>
        <a href="/platform/reports/exportar" class="btn-pacs-primary platform-reports__export">
            <i class="fa fa-file-excel" aria-hidden="true"></i> Exportar XLSX
        </a>
    </header>

    <?php if ($erroRelatorio): ?>
        <div class="platform-reports__alert" role="alert">
            <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
            <span><?= htmlspecialchars($erroRelatorio) ?></span>
        </div>
    <?php endif; ?>

    <div class="platform-reports__stats" aria-label="Resumo consolidado da plataforma">
        <article class="platform-reports__stat-card">
            <span class="platform-reports__stat-icon platform-reports__stat-icon--blue"><i class="fa fa-building" aria-hidden="true"></i></span>
            <div><strong><?= number_format((int) ($resumo['total_negocios'] ?? 0)) ?></strong><span>Negócios cadastrados</span></div>
        </article>
        <article class="platform-reports__stat-card">
            <span class="platform-reports__stat-icon platform-reports__stat-icon--green"><i class="fa fa-circle-check" aria-hidden="true"></i></span>
            <div><strong><?= number_format((int) ($resumo['negocios_ativos'] ?? 0)) ?></strong><span>Negócios ativos</span></div>
        </article>
        <article class="platform-reports__stat-card">
            <span class="platform-reports__stat-icon platform-reports__stat-icon--purple"><i class="fa fa-x-ray" aria-hidden="true"></i></span>
            <div><strong><?= number_format((int) ($resumo['total_exames'] ?? 0)) ?></strong><span>Exames processados</span></div>
        </article>
        <article class="platform-reports__stat-card">
            <span class="platform-reports__stat-icon platform-reports__stat-icon--orange"><i class="fa fa-coins" aria-hidden="true"></i></span>
            <div><strong>R$ <?= number_format((float) ($resumo['receita_total'] ?? 0), 2, ',', '.') ?></strong><span>Receita consolidada</span></div>
        </article>
        <article class="platform-reports__stat-card">
            <span class="platform-reports__stat-icon platform-reports__stat-icon--cyan"><i class="fa fa-arrow-trend-up" aria-hidden="true"></i></span>
            <div><strong><?= number_format((int) ($resumo['novos_negocios_periodo'] ?? 0)) ?></strong><span>Novos negócios em 12 meses</span></div>
        </article>
    </div>

    <div class="platform-reports__evolution-grid">
        <article class="pacs-card platform-reports__evolution-card">
            <div class="pacs-card-header">
                <span><i class="fa fa-chart-column text-pacs-primary" aria-hidden="true"></i> Volume de exames e novos negócios</span>
                <span class="platform-reports__period">Últimos 12 meses</span>
            </div>
            <div class="platform-reports__chart" role="group" aria-label="Evolução mensal de exames e novos negócios">
                <?php foreach ($evolucao as $linha): ?>
                    <?php
                    $exames = (int) ($linha['total_exames'] ?? 0);
                    $novosNegocios = (int) ($linha['novos_negocios'] ?? 0);
                    $mes = (string) ($linha['mes'] ?? '');
                    ?>
                    <div class="platform-reports__chart-row">
                        <span class="platform-reports__chart-month"><?= htmlspecialchars($formatarMes($mes)) ?></span>
                        <div class="platform-reports__chart-values">
                            <label>Exames <strong><?= number_format($exames) ?></strong>
                                <progress class="platform-reports__progress platform-reports__progress--exams" value="<?= $exames ?>" max="<?= $maxExames ?>"><?= number_format($exames) ?></progress>
                            </label>
                            <span class="platform-reports__new-business"><i class="fa fa-building-circle-arrow-right" aria-hidden="true"></i> <?= number_format($novosNegocios) ?> novos</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="pacs-card platform-reports__evolution-card">
            <div class="pacs-card-header">
                <span><i class="fa fa-coins text-pacs-primary" aria-hidden="true"></i> Receita mensal</span>
                <span class="platform-reports__period">Últimos 12 meses</span>
            </div>
            <div class="platform-reports__chart" role="group" aria-label="Evolução mensal da receita">
                <?php foreach ($evolucao as $linha): ?>
                    <?php
                    $receita = (float) ($linha['receita'] ?? 0);
                    $mes = (string) ($linha['mes'] ?? '');
                    ?>
                    <div class="platform-reports__chart-row">
                        <span class="platform-reports__chart-month"><?= htmlspecialchars($formatarMes($mes)) ?></span>
                        <div class="platform-reports__chart-values">
                            <label>Receita <strong>R$ <?= number_format($receita, 2, ',', '.') ?></strong>
                                <progress class="platform-reports__progress platform-reports__progress--revenue" value="<?= $receita ?>" max="<?= $maxReceita ?>">R$ <?= number_format($receita, 2, ',', '.') ?></progress>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <article class="pacs-card platform-reports__table-card">
        <div class="pacs-card-header">
            <span><i class="fa fa-table-list text-pacs-primary" aria-hidden="true"></i> Visão atual por negócio</span>
            <span class="platform-reports__period"><?= number_format(count($relatorio)) ?> negócio(s)</span>
        </div>
        <div class="platform-reports__table-wrap">
            <table class="platform-table">
                <thead>
                    <tr><th>Negócio</th><th>Status</th><th>Plano</th><th class="text-end">Exames</th><th class="text-end">Receita</th></tr>
                </thead>
                <tbody>
                <?php if (!$relatorio): ?>
                    <tr><td colspan="5" class="platform-reports__empty"><i class="fa fa-chart-line" aria-hidden="true"></i>Nenhum negócio encontrado para os indicadores atuais.</td></tr>
                <?php else: ?>
                    <?php foreach ($relatorio as $linha): ?>
                        <?php $status = strtolower((string) ($linha['status'] ?? 'inativo')); ?>
                        <tr>
                            <td class="platform-reports__tenant-name"><?= htmlspecialchars((string) ($linha['nome'] ?? '—')) ?></td>
                            <td><span class="badge badge-<?= $status === 'ativo' ? 'ativo' : ($status === 'suspenso' ? 'suspenso' : 'inativo') ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                            <td><?= htmlspecialchars((string) (($linha['plano'] ?? '') ?: 'Sem plano')) ?></td>
                            <td class="text-end"><?= number_format((int) ($linha['total_exames'] ?? 0)) ?></td>
                            <td class="text-end platform-reports__revenue">R$ <?= number_format((float) ($linha['receita'] ?? 0), 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
