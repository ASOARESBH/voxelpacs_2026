<?php

declare(strict_types=1);

namespace App\Services;

/** Traduz eventos técnicos de auditoria em descrições humanas e sanitizadas. */
final class RelatorioAuditoriaApresentacaoService
{
    public function evento(string $acao): string
    {
        $chaves = [
            'login_success' => 'auditoria.evento.login_success',
            'login_failed' => 'auditoria.evento.login_failed',
            'logout' => 'auditoria.evento.logout',
            'estudo.assumir' => 'auditoria.evento.estudo_assumir',
            'report.visualizar' => 'auditoria.evento.report_visualizar',
            'report.criar' => 'auditoria.evento.report_criar',
            'report.salvar' => 'auditoria.evento.report_salvar',
            'report.assinar' => 'auditoria.evento.report_assinar',
            'report.peer_review_aberto' => 'auditoria.evento.peer_review_aberto',
            'report.peer_review_concluido' => 'auditoria.evento.peer_review_concluido',
            'pedido.anexado' => 'auditoria.evento.pedido_anexado',
            'pedido.removido' => 'auditoria.evento.pedido_removido',
            'prioridade.alterada' => 'auditoria.evento.prioridade_alterada',
            'estudo.descricao_alterada' => 'auditoria.evento.descricao_alterada',
            'estudo.descricao_lote_alterada' => 'auditoria.evento.descricao_lote_alterada',
            'relatorio.auditoria_visualizado' => 'auditoria.evento.relatorio_visualizado',
            'relatorio.auditoria_exportado' => 'auditoria.evento.relatorio_exportado',
            'relatorio.sla_medicos_visualizado' => 'auditoria.evento.sla_visualizado',
            'relatorio.sla_medicos_exportado' => 'auditoria.evento.sla_exportado',
        ];
        return isset($chaves[$acao]) ? t($chaves[$acao]) : t('auditoria.evento.registrado');
    }

    public function contexto(string $acao, string|array|null $detalhes): string
    {
        $dados = is_array($detalhes) ? $detalhes : (json_decode((string) $detalhes, true) ?: []);
        if ($acao === 'relatorio.auditoria_visualizado') {
            $tipo = $this->tipo((string) ($dados['tipo'] ?? ''));
            $periodo = $this->periodo((string) ($dados['periodo'] ?? ''));
            $filtros = $dados['filtros'] ?? [];
            $ativos = [];
            if (!empty($filtros['usuario'])) $ativos[] = t('auditoria.filtro.usuario');
            if (!empty($filtros['grupo'])) $ativos[] = t('auditoria.filtro.grupo');
            $sufixo = $ativos ? ' · ' . t('auditoria.contexto.filtros') . ': ' . implode(', ', $ativos) : '';
            return $this->substituir('auditoria.contexto.consulta', ['tipo' => $tipo, 'periodo' => $periodo]) . $sufixo;
        }
        if ($acao === 'relatorio.auditoria_exportado') {
            $formato = strtoupper((string) ($dados['formato'] ?? ''));
            $linhas = (int) ($dados['total_linhas'] ?? 0);
            return $this->substituir('auditoria.contexto.exportacao', ['formato' => $formato, 'linhas' => (string) $linhas]);
        }
        if (str_starts_with($acao, 'relatorio.sla_medicos_')) {
            $periodo = $dados['periodo'] ?? null;
            if (is_array($periodo) && count($periodo) === 2) {
                return $this->substituir('auditoria.contexto.periodo', ['inicio' => (string) $periodo[0], 'fim' => (string) $periodo[1]]);
            }
            return t('auditoria.contexto.registrado');
        }
        if ($acao === 'pedido.anexado') return t('auditoria.contexto.pedido_anexado');
        if ($acao === 'pedido.removido') return t('auditoria.contexto.pedido_removido');
        if ($acao === 'prioridade.alterada') return t('auditoria.contexto.prioridade_alterada');
        if (str_starts_with($acao, 'estudo.descricao_')) return t('auditoria.contexto.descricao_alterada');
        if (str_starts_with($acao, 'report.peer_review_')) return t('auditoria.contexto.peer_review');
        return t('auditoria.contexto.registrado');
    }

    public function entidade(string $entidade, ?int $id): string
    {
        $mapa = [
            'bi_pacs_estudos' => t('auditoria.entidade.estudo'),
            'reports' => t('auditoria.entidade.laudo'),
            'pacs_report_peer_reviews' => t('auditoria.entidade.peer_review'),
            'relatorio_auditoria' => t('auditoria.entidade.relatorio_auditoria'),
            'relatorio_sla' => t('auditoria.entidade.relatorio_sla'),
        ];
        $texto = $mapa[$entidade] ?? t('auditoria.entidade.sistema');
        return $id ? $texto . ' #' . $id : $texto;
    }

    private function tipo(string $tipo): string
    {
        return match ($tipo) {
            'acesso' => t('auditoria.tipo.acesso'),
            'estudos' => t('auditoria.tipo.estudos'),
            'clinica' => t('auditoria.tipo.clinica'),
            default => t('auditoria.titulo'),
        };
    }

    private function periodo(string $periodo): string
    {
        return match ($periodo) {
            'hoje' => t('auditoria.periodo.hoje'),
            'sete_dias' => t('auditoria.periodo.sete_dias'),
            'mes' => t('auditoria.periodo.mes'),
            'customizado' => t('auditoria.periodo.customizado'),
            default => t('auditoria.periodo.personalizado'),
        };
    }

    private function substituir(string $chave, array $valores): string
    {
        $substituicoes = [];
        foreach ($valores as $nome => $valor) {
            $substituicoes['{' . $nome . '}'] = (string) $valor;
        }
        return strtr(t($chave), $substituicoes);
    }
}
