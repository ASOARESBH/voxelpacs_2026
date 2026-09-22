<?php
$root = dirname(__DIR__);
$files = [
    'pg' => $root . '/database/migrations/2026-09-09_desktop_download_catalog_postgresql.sql',
    'mysql' => $root . '/database/migrations/2026-09-09_desktop_download_catalog_mysql.sql',
    'repository' => $root . '/app/Repositories/DesktopDownloadRepository.php',
    'service' => $root . '/app/Services/DesktopDownloadService.php',
    'controller' => $root . '/app/Controllers/Platform/DownloadsController.php',
    'desktop' => $root . '/app/Controllers/DesktopController.php',
    'worklist' => $root . '/app/Views/estudos/index.php',
    'routes' => $root . '/routes/platform.php',
    'form' => $root . '/app/Views/platform/downloads/form.php',
    'index' => $root . '/app/Views/platform/downloads/index.php',
    'products_pg' => $root . '/database/migrations/2026-09-09_desktop_download_catalog_products_postgresql.sql',
    'products_mysql' => $root . '/database/migrations/2026-09-09_desktop_download_catalog_products_mysql.sql',
];
foreach ($files as $name => $path) if (!is_file($path)) throw new RuntimeException("Arquivo ausente: {$name}");
$pg = file_get_contents($files['pg']); $mysql = file_get_contents($files['mysql']); $service = file_get_contents($files['service']); $controller = file_get_contents($files['controller']); $desktop = file_get_contents($files['desktop']); $worklist = file_get_contents($files['worklist']); $routes = file_get_contents($files['routes']); $form = file_get_contents($files['form']); $index = file_get_contents($files['index']); $productsPg = file_get_contents($files['products_pg']); $productsMysql = file_get_contents($files['products_mysql']);
foreach (['bi_desktop_release_packages','bi_desktop_download_events'] as $table) { if (!str_contains($pg, $table) || !str_contains($mysql, $table)) throw new RuntimeException("Tabela ausente: {$table}"); }
foreach (['is_uploaded_file','PK\\x03\\x04','ZipArchive','MAX_FILES','MAX_UNCOMPRESSED_BYTES','storage/downloads','voxel-view-desktop','voxel-router-desktop'] as $guard) if (!str_contains($service, $guard)) throw new RuntimeException("Proteção de upload ausente: {$guard}");
foreach (['public function publish', 'pathFor($package)', 'package_file_missing'] as $guard) if (!str_contains($service, $guard)) throw new RuntimeException("Proteção de publicação ausente: {$guard}");
if (!str_contains($controller, 'Auth::isPlatformAdmin()') || !str_contains($controller, '!Auth::isImpersonating()')) throw new RuntimeException('CRUD Downloads sem guarda de superadmin.');
foreach (['AuditLogger::log', 'downloads.package_uploaded', 'downloads.package_published', 'downloads.package_archived'] as $audit) if (!str_contains($controller, $audit)) throw new RuntimeException("Auditoria administrativa ausente: {$audit}");
foreach (['downloads.package_deleted', 'deleteDraft(', 'deleteDraftWithoutDownloads'] as $guard) if (!str_contains($controller . $service, $guard)) throw new RuntimeException("Exclusão protegida ausente: {$guard}");
foreach (['PRODUCT_VIEW_DESKTOP', 'PRODUCT_ROUTER_DESKTOP', 'product_key', 'voxel-view-desktop', 'voxel-router-desktop'] as $guard) if (!str_contains($service, $guard)) throw new RuntimeException("Separação de produto ausente: {$guard}");
if (!str_contains($desktop, 'resolvePublished($plataforma, $canal)')) throw new RuntimeException('Endpoint público não mantém o View/Desktop como produto padrão.');
foreach (['voxel_view_desktop', 'voxel_router_desktop'] as $product) if (!str_contains($form . $index . $productsPg . $productsMysql, $product)) throw new RuntimeException("Produto ausente: {$product}");
if (substr_count($form, 'type="radio"') !== 2) throw new RuntimeException('Escolha explícita de produto ausente no formulário.');
if (!str_contains($routes, 'DownloadsController@delete') || !str_contains($index, 'downloads.delete_confirm')) throw new RuntimeException('Rota ou confirmação de exclusão ausente.');
if (!str_contains($desktop, 'recordDownload') || !str_contains($desktop, 'Content-Disposition: attachment')) throw new RuntimeException('Distribuição não registra ou não força download.');
if (str_contains($desktop, '/downloads/VOXELDesktopSetup.exe')) throw new RuntimeException('Manifesto ainda anuncia URL histórica indisponível.');
if (str_contains($desktop, '/storage/downloads/')) throw new RuntimeException('Endpoint não pode expor caminho privado de armazenamento.');
if (!str_contains($worklist, '/desktop/download?platform=windows') || !str_contains($worklist, "downloads.worklist_cta")) throw new RuntimeException('Worklist não restaura CTA do VOXEL Desktop.');
if (!str_contains($routes, 'Platform\\DownloadsController@index')) throw new RuntimeException('Rotas Downloads ausentes.');
echo "desktop_downloads_static: OK\n";
