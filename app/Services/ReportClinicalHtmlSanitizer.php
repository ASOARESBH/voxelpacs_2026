<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normaliza o HTML clínico criado pelo Quill para máscaras e laudário.
 *
 * Mantém somente a semântica necessária ao laudo: parágrafos, espaçamento,
 * listas, tabelas, ênfases, alinhamento e links HTTPS. Imagens, estilos
 * arbitrários, event handlers e URLs executáveis são removidos.
 */
final class ReportClinicalHtmlSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'table' => true,
        'thead' => true,
        'tbody' => true,
        'tr' => true,
        'th' => true,
        'td' => true,
        'a' => true,
    ];

    /** @var array<string, true> */
    private const ALLOWED_ALIGN_CLASSES = [
        'ql-align-center' => true,
        'ql-align-right' => true,
        'ql-align-justify' => true,
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (!class_exists(\DOMDocument::class)) {
            return self::sanitizeFallback($html);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="voxel-clinical-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return self::sanitizeFallback($html);
        }

        $root = $dom->getElementById('voxel-clinical-root');
        if (!$root instanceof \DOMElement) {
            return self::sanitizeFallback($html);
        }

        self::sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child) ?: '';
        }

        return trim($output);
    }

    /** @param array<string, mixed> $sections */
    public static function sanitizeSections(array $sections): array
    {
        foreach ($sections as $key => $value) {
            if (is_string($value)) {
                $sections[$key] = self::sanitize($value);
            }
        }

        return $sections;
    }

    private static function sanitizeChildren(\DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!isset(self::ALLOWED_TAGS[$tag])) {
                    self::unwrap($child);
                    continue;
                }

                self::sanitizeAttributes($child, $tag);
                self::sanitizeChildren($child);
            }
        }
    }

    private static function sanitizeAttributes(\DOMElement $element, string $tag): void
    {
        $classList = (string) $element->getAttribute('class');
        $hrefOriginal = (string) $element->getAttribute('href');
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }

        foreach ($attributes as $attributeName) {
            $element->removeAttribute($attributeName);
        }

        $alignment = self::alignmentClass($classList);
        if ($alignment !== '') {
            $element->setAttribute('class', $alignment);
        }

        if ($tag !== 'a') {
            return;
        }

        $href = self::safeHttpsUrl($hrefOriginal);
        if ($href === '') {
            self::unwrap($element);
            return;
        }

        $element->setAttribute('href', $href);
        $element->setAttribute('rel', 'noopener noreferrer');
        $element->setAttribute('target', '_blank');
    }

    private static function alignmentClass(string $classList): string
    {
        foreach (preg_split('/\s+/', trim($classList)) ?: [] as $class) {
            if (isset(self::ALLOWED_ALIGN_CLASSES[$class])) {
                return $class;
            }
        }

        return '';
    }

    private static function safeHttpsUrl(string $href): string
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 2048) {
            return '';
        }

        $parts = parse_url($href);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }

        return filter_var($href, FILTER_VALIDATE_URL) ? $href : '';
    }

    private static function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof \DOMNode) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private static function sanitizeFallback(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><thead><tbody><tr><th><td><a>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s(?:on\w+|style|src)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        $html = preg_replace('/<(?!\/?(?:p|br|strong|b|em|i|u|h[1-6]|ul|ol|li|table|thead|tbody|tr|th|td|a)\b)[^>]*>/iu', '', $html) ?? '';
        $html = preg_replace_callback('/<a\b[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>/iu', static function (array $match): string {
            $href = self::safeHttpsUrl((string) ($match[1] ?? $match[2] ?? $match[3] ?? ''));
            return $href === '' ? '' : '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">';
        }, $html) ?? '';

        return trim($html);
    }
}
