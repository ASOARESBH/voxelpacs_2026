<?php
namespace App\Core;

/**
 * Tradução em arquivos PHP puros (lang/{locale}.php), sem gettext/locale do SO —
 * ver patterns/padrao-i18n.md para a convenção de chave e como adicionar uma nova.
 */
class Translator {
    public const SUPPORTED = ['pt_BR', 'en', 'es'];
    public const FALLBACK  = 'pt_BR';

    private static ?string $locale = null;
    private static array $cache = [];

    public static function setLocale(?string $locale): void {
        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : self::FALLBACK;
    }

    public static function locale(): string {
        return self::$locale ?? self::FALLBACK;
    }

    /**
     * Nunca lança/quebra a tela por causa de tradução faltando: chave ausente no
     * idioma ativo cai no pt_BR; ausente também lá, retorna a própria chave.
     */
    public static function t(string $key): string {
        $strings = self::load(self::locale());
        if (array_key_exists($key, $strings)) {
            return $strings[$key];
        }

        if (self::locale() !== self::FALLBACK) {
            $fallback = self::load(self::FALLBACK);
            if (array_key_exists($key, $fallback)) {
                return $fallback[$key];
            }
        }

        return $key;
    }

    private static function load(string $locale): array {
        if (!isset(self::$cache[$locale])) {
            $path = BASE_PATH . "/lang/{$locale}.php";
            self::$cache[$locale] = file_exists($path) ? require $path : [];
        }
        return self::$cache[$locale];
    }
}
