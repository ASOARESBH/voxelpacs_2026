<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;
use App\Repositories\RelatorioAuditoriaRepository;
use App\Services\RelatorioAuditoriaApresentacaoService;
use App\Services\RelatorioAuditoriaEmissaoService;
use App\Services\RelatorioAuditoriaExportService;
use App\Services\RelatorioPermissaoService;

final class RelatorioAuditoriaController extends Controller
{
    private const TIPO_CHAVE = ['acesso' => 'auditoria_acesso', 'estudos' => 'auditoria_estudos', 'clinica' => 'auditoria_clinica'];

    public function index(): void
    {
        $tenantId = (int) TenantContext::id();
        if (!$tenantId) { $this->redirect('/selecionar-empresa'); return; }
        $tipo = (string) ($_GET['tipo'] ?? 'acesso');
        if ($tipo === 'sla_medicos') { $this->redirect('/relatorios/sla-medicos'); return; }
        if (!isset(self::TIPO_CHAVE[$tipo])) $tipo = 'acesso';

        $permissoes = new RelatorioPermissaoService();
        if (!$permissoes->podeLer(self::TIPO_CHAVE[$tipo])) {
            foreach (self::TIPO_CHAVE as $candidate => $chave) {
                if ($permissoes->podeLer($chave)) { $tipo = $candidate; break; }
            }
        }
        $permissoes->exigir(self::TIPO_CHAVE[$tipo]);
        $filtros = $this->filtros($tenantId, $tipo);
        $repo = new RelatorioAuditoriaRepository(Database::getInstance());
        $resultado = $repo->buscar($filtros);
        AuditLogger::log('relatorio.auditoria_visualizado', 'relatorio_auditoria', null, ['tipo' => $tipo, 'periodo' => $filtros['atalho'], 'filtros' => ['usuario' => (bool) $filtros['usuario_id'], 'grupo' => (bool) $filtros['grupo_id']]], $tenantId, 'acesso');

        $this->view('relatorios/auditoria', [
            'title' => 'Relatório de Auditoria',
            'tipo' => $tipo,
            'filtros' => $filtros,
            'opcoes' => $repo->opcoes($tenantId),
            'linhas' => $resultado['linhas'],
            'total' => $resultado['total'],
            'totalPaginas' => max(1, (int) ceil($resultado['total'] / $filtros['por_pagina'])),
            'permissoes' => $permissoes,
            'apresentacao' => new RelatorioAuditoriaApresentacaoService(),
        ]);
    }

    public function exportar(): void
    {
        $tenantId = (int) TenantContext::id();
        if (!$tenantId) { $this->redirect('/selecionar-empresa'); return; }
        $tipo = (string) ($_GET['tipo'] ?? 'acesso');
        if (!isset(self::TIPO_CHAVE[$tipo])) $tipo = 'acesso';
        (new RelatorioPermissaoService())->exigir(self::TIPO_CHAVE[$tipo]);
        $formato = strtolower((string) ($_GET['formato'] ?? 'pdf'));
        if (!in_array($formato, ['pdf', 'csv'], true)) $formato = 'pdf';

        $filtros = $this->filtros($tenantId, $tipo);
        $filtros['pagina'] = 1;
        $filtros['por_pagina'] = 5000;
        $repo = new RelatorioAuditoriaRepository(Database::getInstance());
        $resultado = $repo->buscar($filtros);
        $apresentacao = new RelatorioAuditoriaApresentacaoService();
        $linhas = array_map(fn(array $linha): array => $this->linhaExportacao($linha, $apresentacao, $tipo), $resultado['linhas']);
        $emissor = new RelatorioAuditoriaEmissaoService();
        $emissao = $emissor->emitir($tenantId, Auth::userId(), $tipo, $formato, $filtros, $resultado['linhas']);
        $tenant = $emissor->identidadeTenant($tenantId);
        $usuario = (string) (Auth::user()?->name ?? t('auditoria.sistema'));
        AuditLogger::log('relatorio.auditoria_exportado', 'relatorio_auditoria_export', $emissao['id'], [
            'tipo' => $tipo, 'formato' => $formato, 'total_linhas' => count($linhas), 'codigo_publico' => $emissao['codigo_publico'],
        ], $tenantId, 'acesso');

        $exportador = new RelatorioAuditoriaExportService();
        if ($formato === 'csv') {
            $exportador->csv($linhas, $tenant, $tipo, $filtros, $emissao, $usuario);
            return;
        }
        $labels = ['acesso' => t('auditoria.tipo.acesso'), 'estudos' => t('auditoria.tipo.estudos'), 'clinica' => t('auditoria.tipo.clinica')];
        $exportador->pdf([
            'tenant' => $tenant, 'emissao' => $emissao, 'linhas' => $linhas, 'filtros' => $filtros,
            'tipo' => $tipo, 'tipo_label' => $labels[$tipo], 'usuario_nome' => $usuario,
        ], 'RELATORIO_AUDITORIA_' . strtoupper($tipo) . '_' . date('Ymd_Hi') . '.pdf');
    }

    private function filtros(int $tenantId, string $tipo): array
    {
        $atalho = (string) ($_GET['periodo'] ?? 'mes');
        $hoje = new \DateTimeImmutable('today');
        [$de, $ate] = match ($atalho) {
            'hoje' => [$hoje, $hoje],
            'sete_dias' => [$hoje->modify('-6 days'), $hoje],
            'customizado' => [new \DateTimeImmutable((string) ($_GET['data_de'] ?? $hoje->modify('-29 days')->format('Y-m-d'))), new \DateTimeImmutable((string) ($_GET['data_ate'] ?? $hoje->format('Y-m-d')))],
            default => [$hoje->modify('first day of this month'), $hoje],
        };
        if ($de > $ate) [$de, $ate] = [$ate, $de];
        return [
            'tenant_id' => $tenantId, 'tipo' => $tipo, 'atalho' => $atalho,
            'data_de' => $de->format('Y-m-d'), 'data_ate' => $ate->format('Y-m-d'),
            'usuario_id' => filter_var($_GET['usuario_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'grupo_id' => filter_var($_GET['grupo_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'pagina' => max(1, (int) ($_GET['pagina'] ?? 1)), 'por_pagina' => 50,
        ];
    }

    private function linhaExportacao(array $linha, RelatorioAuditoriaApresentacaoService $apresentacao, string $tipo): array
    {
        $duracao = $linha['duracao_seg'] ?? null;
        $segundos = $duracao === null ? null : max(0, (int) $duracao);
        $textoDuracao = $segundos === null ? '—' : (intdiv($segundos, 3600) > 0
            ? intdiv($segundos, 3600) . 'h' . str_pad((string) intdiv($segundos % 3600, 60), 2, '0', STR_PAD_LEFT) . 'min'
            : intdiv($segundos, 60) . 'min');
        return [
            'data' => !empty($linha['created_at']) ? date('d/m/Y H:i:s', strtotime((string) $linha['created_at'])) : '—',
            'autor' => (string) ($linha['usuario_nome'] ?: t('auditoria.sistema')),
            'evento' => $apresentacao->evento((string) $linha['action']),
            'entidade' => $apresentacao->entidade((string) $linha['entity'], !empty($linha['entity_id']) ? (int) $linha['entity_id'] : null),
            'contexto' => $apresentacao->contexto((string) $linha['action'], $linha['details'] ?? null),
            'ip' => (string) ($linha['ip'] ?: '—'),
            'regiao' => (string) ($linha['region_code'] ?: '—'),
            'assumido_em' => !empty($linha['assumido_em']) ? date('d/m/Y H:i:s', strtotime((string) $linha['assumido_em'])) : '—',
            'duracao' => $textoDuracao,
            'peer_review' => (int) ($linha['possui_peer_review'] ?? 0) === 1 ? t('auditoria.sim') : t('auditoria.nao'),
        ];
    }
}
