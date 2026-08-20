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
                            <div class="portal-action-group">
                                <a class="portal-primary portal-inline" href="/laudo/<?= htmlspecialchars($study['report_token'], ENT_QUOTES) ?>" target="_blank" rel="noopener">Ver laudo</a>
                                <a class="portal-secondary portal-inline" href="/imagens/<?= htmlspecialchars($study['report_token'], ENT_QUOTES) ?>">Ver imagens</a>
                                <button class="portal-secondary portal-inline portal-share-trigger" type="button"
                                        data-report-token="<?= htmlspecialchars($study['report_token'], ENT_QUOTES) ?>"
                                        data-study-label="<?= htmlspecialchars($study['study_description'], ENT_QUOTES) ?>">Compartilhar</button>
                            </div>
                        <?php else: ?>
                            <span class="portal-disabled">Disponível após liberação</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<dialog class="portal-share-dialog" id="portal-share-dialog" aria-labelledby="portal-share-title">
    <form id="portal-share-form" novalidate>
        <div class="portal-dialog-heading">
            <div>
                <p class="portal-eyebrow">COMPARTILHAMENTO SEGURO</p>
                <h2 id="portal-share-title">Compartilhar laudo</h2>
            </div>
            <button class="portal-dialog-close" type="button" data-portal-share-close aria-label="Fechar">×</button>
        </div>
        <p class="portal-share-study" id="portal-share-study"></p>
        <p class="portal-dialog-copy">Confirme o destinatário. O compartilhamento será registrado e o acesso expira em 24 horas.</p>
        <input type="hidden" id="portal-share-token" value="">
        <input type="hidden" id="portal-share-csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES) ?>">
        <fieldset class="portal-share-channel">
            <legend>Como deseja compartilhar?</legend>
            <label><input type="radio" name="portal_share_channel" value="whatsapp" checked> WhatsApp</label>
            <label><input type="radio" name="portal_share_channel" value="email"> E-mail</label>
        </fieldset>
        <div class="portal-share-field" id="portal-share-phone-field">
            <label for="portal-share-phone">Número do WhatsApp</label>
            <input id="portal-share-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="(00) 00000-0000" maxlength="20">
        </div>
        <div class="portal-share-field" id="portal-share-email-field" hidden>
            <label for="portal-share-email">E-mail do destinatário</label>
            <input id="portal-share-email" name="email" type="email" autocomplete="email" placeholder="destinatario@exemplo.com" maxlength="190">
        </div>
        <p class="portal-share-feedback" id="portal-share-feedback" role="status" aria-live="polite"></p>
        <div class="portal-dialog-actions">
            <button class="portal-secondary" type="button" data-portal-share-close>Cancelar</button>
            <button class="portal-primary" id="portal-share-submit" type="submit">Confirmar compartilhamento</button>
        </div>
    </form>
</dialog>
