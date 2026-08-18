<?php
namespace App\Core;

class View {

    /**
     * Versão dos assets — incrementar ao alterar CSS/JS para forçar cache bust.
     * Nunca remover esta constante: ela blinda o CSS contra cache do browser.
     */
    private const ASSET_VERSION = '2.2.6';

    /**
     * Renderiza uma view com layout.
     *
     * O layout é composto por:
     *   app/Views/layout/{$layout}_header.php
     *   app/Views/{$view}.php
     *   app/Views/layout/{$layout}_footer.php
     *
     * A versão dos assets é injetada como constante ASSET_VERSION
     * para que o header possa usar: /assets/css/pacs.css?v=<?= ASSET_VERSION ?>
     */
    public static function render(string $view, array $data = [], string $layout = 'pacs'): void {
        // Define constante de versão de assets (apenas uma vez)
        if (!defined('ASSET_VERSION')) {
            define('ASSET_VERSION', self::ASSET_VERSION);
        }

        extract($data);

        $viewPath = __DIR__ . "/../Views/{$view}.php";
        if (!file_exists($viewPath)) {
            throw new \Exception("View não encontrada: {$view}");
        }

        // Captura o conteúdo da view em buffer
        ob_start();
        try {
            require $viewPath;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = ob_get_clean();

        // Renderiza header → conteúdo → footer
        $headerPath = __DIR__ . "/../Views/layout/{$layout}_header.php";
        $footerPath = __DIR__ . "/../Views/layout/{$layout}_footer.php";

        if (file_exists($headerPath)) require $headerPath;
        echo $content;
        if (file_exists($footerPath)) require $footerPath;
    }

    /**
     * Retorna a URL de um asset com cache-busting automático.
     * Uso nas views: <?= View::asset('css/pacs.css') ?>
     */
    public static function asset(string $path): string {
        $version = defined('ASSET_VERSION') ? ASSET_VERSION : self::ASSET_VERSION;
        return '/assets/' . ltrim($path, '/') . '?v=' . $version;
    }
}
