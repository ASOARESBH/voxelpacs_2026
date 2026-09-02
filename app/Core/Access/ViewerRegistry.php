<?php

namespace App\Core\Access;

/** Catálogo único dos visualizadores expostos pelo menu Abrir da Worklist. Publicação técnica acompanha a migration opt-out. */
final class ViewerRegistry
{
    private const VIEWERS = [
        'voxel_view' => [
            'label_key' => 'viewer_access.catalog.voxel_view',
            'icon' => 'fa-globe',
            'desktop_viewer' => null,
        ],
        'voxel_desktop' => [
            'label_key' => 'viewer_access.catalog.voxel_desktop',
            'icon' => 'fa-desktop',
            'desktop_viewer' => 'voxel',
        ],
        'radiant' => [
            'label_key' => 'viewer_access.catalog.radiant',
            'icon' => 'fa-r',
            'desktop_viewer' => 'radiant',
        ],
        'weasis' => [
            'label_key' => 'viewer_access.catalog.weasis',
            'icon' => 'fa-camera',
            'desktop_viewer' => 'weasis',
        ],
    ];

    public static function all(): array { return self::VIEWERS; }
    public static function get(string $key): ?array { return self::VIEWERS[$key] ?? null; }
    public static function has(string $key): bool { return isset(self::VIEWERS[$key]); }

    public static function keyForDesktopViewer(string $viewer): ?string
    {
        foreach (self::VIEWERS as $key => $item) {
            if (($item['desktop_viewer'] ?? null) === $viewer) return $key;
        }
        return null;
    }
}
