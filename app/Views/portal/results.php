<section class="portal-results" aria-labelledby="portal-results-title">
    <div class="portal-results-heading">
        <div>
            <p class="portal-eyebrow">OLÁ, <?= htmlspecialchars($patientName ?? '', ENT_QUOTES) ?></p>
            <h1 id="portal-results-title">Meus exames</h1>
        </div>
        <span class="portal-results-count"><?= count($studies ?? []) ?> exame(s)</span>
    </div>
    <?php if (empty($studies)): ?>
        <div class="portal-empty"><h2>Nenhum exame disponível</h2><p>Não há exames associados aos dados informados neste momento.</p></div>
    <?php else: ?>
        <div class="portal-study-list">
            <?php foreach ($studies as $study): ?>
                <article class="portal-study-card">
                    <div class="portal-study-date"><?= htmlspecialchars($study['study_date'] ? date('d/m/Y', strtotime($study['study_date'])) : 'Data não informada', ENT_QUOTES) ?></div>
                    <div class="portal-study-main">
                        <h2><?= htmlspecialchars($study['study_description'], ENT_QUOTES) ?></h2>
                        <p><?= htmlspecialchars($study['modalities'] ?: 'Modalidade não informada', ENT_QUOTES) ?> · <?= htmlspecialchars($study['institution_name'], ENT_QUOTES) ?></p>
                    </div>
                    <div class="portal-study-action">
                        <span class="portal-status <?= $study['released'] ? 'is-released' : 'is-pending' ?>"><?= htmlspecialchars($study['status_label'], ENT_QUOTES) ?></span>
                        <?php if ($study['released']): ?>
                            <a class="portal-primary portal-inline" href="/laudo/<?= htmlspecialchars($study['report_token'], ENT_QUOTES) ?>" target="_blank" rel="noopener">Ver laudo</a>
                        <?php else: ?>
                            <span class="portal-disabled">Disponível após liberação</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
