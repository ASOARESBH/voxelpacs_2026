<main class="portal-unavailable" aria-live="polite">
    <section class="portal-unavailable__card">
        <p class="portal-unavailable__eyebrow">VISUALIZAÇÃO DE IMAGENS</p>
        <h1>Imagens em preparação segura</h1>
        <p><?= htmlspecialchars($message ?? 'As imagens estão sendo preparadas com proteção de privacidade.', ENT_QUOTES, 'UTF-8') ?></p>
        <p class="portal-unavailable__hint">A visualização será liberada somente após a cópia anonimizada e a revisão de privacidade.</p>
        <a class="portal-action portal-action--primary" href="/resultados">Voltar aos resultados</a>
    </section>
</main>
