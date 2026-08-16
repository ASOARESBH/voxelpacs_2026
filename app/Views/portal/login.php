<section class="portal-auth-card" aria-labelledby="portal-title">
    <p class="portal-eyebrow">ACESSO DO PACIENTE</p>
    <h1 id="portal-title">Consulte seus resultados</h1>
    <p class="portal-subtitle">Informe os dados utilizados no atendimento para continuar.</p>
    <?php if (!empty($error)): ?>
        <div class="portal-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <form method="post" action="/identificar" class="portal-form" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES) ?>">
        <label for="portal-nome">Nome completo</label>
        <input id="portal-nome" name="nome_completo" type="text" autocomplete="name" maxlength="255" required>
        <label for="portal-nascimento">Data de nascimento</label>
        <input id="portal-nascimento" name="data_nascimento" type="date" autocomplete="bday" required>
        <label for="portal-sexo">Sexo</label>
        <select id="portal-sexo" name="sexo" required>
            <option value="">Selecione</option>
            <option value="F">Feminino</option>
            <option value="M">Masculino</option>
            <option value="O">Outro / não informado</option>
        </select>
        <button class="portal-primary" type="submit">Buscar meus resultados</button>
    </form>
    <p class="portal-note">Seus dados são usados apenas para confirmar seu acesso aos resultados.</p>
</section>
