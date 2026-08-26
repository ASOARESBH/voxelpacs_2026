<?php

namespace App\Core\Access;

/** Catálogo único de módulos do PACS, governado globalmente pela Plataforma. */
final class ModuleCatalog
{
    private const MODULES = [
        'estudos' => ['label_key' => 'modulos.estudos', 'icon' => 'fa-list-check', 'section' => 'worklist', 'permission' => 'estudos', 'paths' => ['/estudos', '/api/estudos', '/api/download-lote', '/desktop/download', '/api/desktop']],
        'agendamentos' => ['label_key' => 'modulos.agendamentos', 'icon' => 'fa-calendar-days', 'section' => 'worklist', 'permission' => 'agendamentos', 'paths' => ['/agendamentos']],
        'gestao_exames' => ['label_key' => 'modulos.gestao_exames', 'icon' => 'fa-clipboard-list', 'section' => 'worklist', 'permission' => 'gestao_exames', 'paths' => ['/gestao-exames', '/api/gestao-exames']],
        'pacs_exames' => ['label_key' => 'modulos.pacs_exames', 'icon' => 'fa-images', 'section' => 'pacs', 'permission' => 'imagens_dicom', 'paths' => ['/pacs/exames']],
        'pacs_modalidades' => ['label_key' => 'modulos.pacs_modalidades', 'icon' => 'fa-satellite-dish', 'section' => 'pacs', 'permission' => 'imagens_dicom', 'paths' => ['/pacs/modalidades']],
        'cad_medicos' => ['label_key' => 'modulos.cad_medicos', 'icon' => 'fa-user-doctor', 'section' => 'cadastros', 'permission' => 'medicos', 'paths' => ['/medicos', '/api/medicos', '/api/templates']],
        'cad_unidades' => ['label_key' => 'modulos.cad_unidades', 'icon' => 'fa-hospital', 'section' => 'cadastros', 'permission' => 'medicos', 'paths' => ['/unidades', '/api/unidades']],
        'cad_modalidades' => ['label_key' => 'modulos.cad_modalidades', 'icon' => 'fa-satellite-dish', 'section' => 'cadastros', 'permission' => 'medicos', 'paths' => ['/modalidades']],
        'sla_regras' => ['label_key' => 'modulos.sla_regras', 'icon' => 'fa-gauge-high', 'section' => 'cadastros', 'permission' => 'sla', 'paths' => ['/sla-regras']],
        'rel_exames' => ['label_key' => 'modulos.rel_exames', 'icon' => 'fa-file-medical', 'section' => 'relatorios', 'permission' => 'relatorios', 'paths' => ['/relatorios/exames']],
        'rel_medicos' => ['label_key' => 'modulos.rel_medicos', 'icon' => 'fa-user-doctor', 'section' => 'relatorios', 'permission' => 'relatorios', 'paths' => ['/relatorios/medicos']],
        'rel_sla_medicos' => ['label_key' => 'modulos.rel_sla_medicos', 'icon' => 'fa-gauge-high', 'section' => 'relatorios', 'permission' => 'relatorios', 'paths' => ['/relatorios/sla-medicos']],
        'rel_auditoria' => ['label_key' => 'modulos.rel_auditoria', 'icon' => 'fa-shield-halved', 'section' => 'relatorios', 'permission' => 'relatorios', 'paths' => ['/relatorios/auditoria']],
        'usuarios' => ['label_key' => 'modulos.usuarios', 'icon' => 'fa-users', 'section' => 'sistema', 'permission' => 'usuarios', 'paths' => ['/usuarios']],
        'configuracoes' => ['label_key' => 'modulos.configuracoes', 'icon' => 'fa-gear', 'section' => 'sistema', 'permission' => 'configuracoes', 'paths' => ['/configuracoes']],
    ];

    public static function all(): array { return self::MODULES; }
    public static function get(string $key): ?array { return self::MODULES[$key] ?? null; }
    public static function has(string $key): bool { return isset(self::MODULES[$key]); }
    public static function permissionKey(string $key): ?string { return self::MODULES[$key]['permission'] ?? null; }

    public static function moduleForUri(string $uri): ?string
    {
        foreach (self::MODULES as $key => $module) {
            foreach ($module['paths'] as $path) {
                if ($uri === $path || str_starts_with($uri, $path . '/')) {
                    return $key;
                }
            }
        }
        return null;
    }
}
