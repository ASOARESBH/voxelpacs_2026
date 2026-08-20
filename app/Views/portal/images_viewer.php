<main class="portal-image-viewer" aria-label="Visualização segura de imagens">
    <section class="portal-image-viewer__bar">
        <div>
            <p class="portal-image-viewer__eyebrow">VISUALIZAÇÃO DE IMAGENS</p>
            <strong>Ambiente protegido de imagens anonimizadas</strong>
        </div>
        <a class="portal-action portal-action--secondary" href="/resultados">Voltar aos resultados</a>
    </section>
    <iframe
        class="portal-image-viewer__frame"
        src="<?= htmlspecialchars($viewerUrl, ENT_QUOTES, 'UTF-8') ?>/"
        title="Viewer de imagens anonimizadas"
        referrerpolicy="no-referrer"
        allow="fullscreen"
    ></iframe>
</main>
