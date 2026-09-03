<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function viewerSource(string $path): string
{
    global $root;
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException("Arquivo ausente: {$path}");
    return (string) file_get_contents($full);
}

function viewerMustContain(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) throw new RuntimeException($message);
}

require $root . '/app/Core/Access/ViewerRegistry.php';
require $root . '/app/Core/Access/ViewerAccess.php';

use App\Core\Access\ViewerAccess;
use App\Core\Access\ViewerRegistry;

$registry = ViewerRegistry::all();
if (array_keys($registry) !== ['voxel_view', 'voxel_desktop', 'radiant', 'weasis']) {
    throw new RuntimeException('Catálogo de visualizadores não contém exatamente as quatro chaves aprovadas.');
}
if (ViewerRegistry::keyForDesktopViewer('voxel') !== 'voxel_desktop'
    || ViewerRegistry::keyForDesktopViewer('radiant') !== 'radiant'
    || ViewerRegistry::keyForDesktopViewer('weasis') !== 'weasis'
    || ViewerRegistry::keyForDesktopViewer('invalido') !== null) {
    throw new RuntimeException('Mapeamento de visualizadores desktop inválido.');
}
if (!ViewerAccess::isPrivilegedTarget('admin') || !ViewerAccess::isPrivilegedTarget('viewer', 'superadmin') || ViewerAccess::isPrivilegedTarget('medico')) {
    throw new RuntimeException('Bypass administrativo de visualizadores inválido.');
}

$estudos = viewerSource('app/Controllers/EstudosController.php');
$usuarios = viewerSource('app/Controllers/UsuariosController.php');
$worklist = viewerSource('app/Views/estudos/index.php');
$form = viewerSource('app/Views/usuarios/form.php');
$access = viewerSource('app/Core/Access/ViewerAccess.php');
$mysql = viewerSource('database/migrations/2026-09-02_visualizadores_habilitados_usuario_mysql.sql');
$postgres = viewerSource('database/migrations/2026-09-02_visualizadores_habilitados_usuario_postgresql.sql');

viewerMustContain($estudos, 'ViewerAccess::isUserEnabled(\'voxel_view\')', 'Voxel View não possui guarda individual no emissor autenticado.');
viewerMustContain($estudos, 'ViewerRegistry::keyForDesktopViewer($viewer)', 'Viewers desktop não derivam uma chave centralizada para a guarda.');
viewerMustContain($estudos, "'visualizador_restrito_por_usuario'", 'Negação de visualizador desktop não é auditada pelo serviço existente.');
viewerMustContain($estudos, "'viewer_states'", 'Worklist não recebe estados efetivos de visualizadores.');
viewerMustContain($worklist, '$hasViewerVisible', 'Worklist não oculta o menu sem visualizador efetivamente autorizado.');
viewerMustContain($worklist, "viewerVisible('voxel_desktop')", 'Worklist não aplica a interseção à opção VOXEL Desktop.');
viewerMustContain($access, "'visible' => \$enabled", 'Worklist não aplica a regra aprovada de ocultar somente visualizadores desmarcados.');
if (strpos($access, "'visible' => \$enabled && \$tenantAvailable") !== false) {
    throw new RuntimeException('Worklist ainda oculta visualizador marcado por indisponibilidade técnica do tenant.');
}
viewerMustContain($usuarios, 'ViewerRegistry::all()', 'Usuários não usa o catálogo central de visualizadores.');
viewerMustContain($usuarios, 'salvarVisualizadores', 'Usuários não persiste exceções de visualizador.');
viewerMustContain($usuarios, "SqlHelper::hasTable(\$pdo, 'bi_user_viewers')", 'Cadastro de usuários não preserva compatibilidade enquanto a migration estiver pendente.');
viewerMustContain($usuarios, 'auth.visualizadores_usuario_atualizados', 'Alteração de visualizadores não é auditada como acesso.');
viewerMustContain($usuarios, 'validCsrfPost()', 'Criação ou edição de usuários não valida CSRF.');
viewerMustContain($form, 'name="visualizadores[]"', 'Formulário não envia as escolhas de visualizadores.');
viewerMustContain($form, 'name="visualizadores_present"', 'Formulário não diferencia todos os visualizadores desmarcados da ausência de configuração.');
viewerMustContain($form, 'name="_csrf_token"', 'Formulário de usuários não envia CSRF.');
viewerMustContain($form, 'data-tenant-available', 'Formulário não expõe indisponibilidade do tenant.');
viewerMustContain($form, 'atualizarVisualizadoresPorPerfil', 'Formulário não preserva o bypass visual de administrador.');
viewerMustContain($form, "input.setAttribute('aria-disabled', 'true')", 'Administrador não recebe indicação acessível de controle imutável.');
viewerMustContain($form, "item.style.opacity = tenantAvailable ? '1' : '.45'", 'Administrador é visualmente confundido com indisponibilidade do tenant.');
viewerMustContain($form, 'input.onclick = (event) => event.preventDefault()', 'Administrador pode alterar visualizadores apesar do bypass obrigatório.');
viewerMustContain($access, "'bi_user_viewers'", 'Camada de acesso não consulta tabela tenant-scoped.');
viewerMustContain($access, 'Auth::isPlatformAdmin() || Auth::perfilAtual() === \'admin\'', 'Camada de acesso não preserva bypass administrativo.');
viewerMustContain($mysql, 'UNIQUE KEY `uq_user_viewer_tenant` (`user_id`, `tenant_id`, `viewer_key`)', 'Migration MySQL sem unicidade tenant-scoped.');
viewerMustContain($postgres, 'voxelpacs_mysql_source.bi_user_viewers', 'Migration PostgreSQL fora do schema operacional.');
viewerMustContain($postgres, 'UNIQUE (user_id, tenant_id, viewer_key)', 'Migration PostgreSQL sem unicidade tenant-scoped.');

$locales = ['pt_BR', 'en', 'es'];
$keys = [];
foreach ($locales as $locale) $keys[$locale] = require $root . '/lang/' . $locale . '.php';
foreach (array_filter(array_keys($keys['pt_BR']), static fn (string $key): bool => str_starts_with($key, 'viewer_access.')) as $key) {
    foreach (['en', 'es'] as $locale) {
        if (!array_key_exists($key, $keys[$locale])) throw new RuntimeException("Chave ausente em {$locale}: {$key}");
    }
}
foreach (['en', 'es'] as $locale) {
    foreach (array_keys($keys[$locale]) as $key) {
        if (str_starts_with($key, 'viewer_access.') && !array_key_exists($key, $keys['pt_BR'])) {
            throw new RuntimeException("Chave de visualizador sem paridade: {$key}");
        }
    }
}

fwrite(STDOUT, "VIEWER_ACCESS_STATIC_OK\n");
