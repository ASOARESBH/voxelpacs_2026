<?php
/**
 * Funções globais curtas para uso nas views. Ver patterns/padrao-i18n.md.
 */

if (!function_exists('t')) {
    function t(string $key): string {
        return \App\Core\Translator::t($key);
    }
}
