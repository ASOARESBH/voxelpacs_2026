<section class="portal-auth-card" aria-labelledby="portal-challenge-title">
    <p class="portal-eyebrow">CONFIRMAÇÃO DE SEGURANÇA</p>
    <h1 id="portal-challenge-title">Confirme onde realizou seu exame</h1>
    <p class="portal-subtitle">Selecione a instituição em que você foi atendido.</p>
    <form method="post" action="/instituicao" class="portal-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES) ?>">
        <input type="hidden" name="challenge_token" value="<?= htmlspecialchars($challengeToken ?? '', ENT_QUOTES) ?>">
        <?php foreach (($options ?? []) as $index => $option): ?>
            <label class="portal-option" for="institution-<?= (int) $index ?>">
                <input id="institution-<?= (int) $index ?>" type="radio" name="institution_name" value="<?= htmlspecialchars((string) $option, ENT_QUOTES) ?>" required>
                <span><?= htmlspecialchars((string) $option, ENT_QUOTES) ?></span>
            </label>
        <?php endforeach; ?>
        <button class="portal-primary" type="submit">Continuar</button>
    </form>
</section>
