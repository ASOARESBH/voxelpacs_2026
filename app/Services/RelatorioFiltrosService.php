<?php
/**
 * RelatorioFiltrosService — normaliza $_GET em filtros seguros e resolve as
 * opções de cada dropdown/chips dos relatórios (Exames e SLA Médicos).
 *
 * Toda resolução de "unidade" passa por InstitutionResolverService — nunca
 * aceita institution_name vindo do request sem checar que pertence ao tenant
 * autenticado (deny-by-default, mesmo padrão de DownloadLoteController).
 */
namespace App\Services;

use App\Repositories\RelatorioEstudosRepository;

class RelatorioFiltrosService
{
    /** Valores reais de bi_pacs_estudos.prioridade (o que a worklist já chama de "Prioridade"). */
    public const PRIORIDADES_VALIDAS = ['normal', 'urgente', 'critico'];

    /**
     * Valores reais de bi_pacs_estudos.situacao, exceto 'urgente' — que no
     * schema é um resíduo legado conflado com o campo `prioridade`, não um
     * estado de fluxo de trabalho (ver modules/relatorios.md).
     */
    public const SITUACOES_VALIDAS = ['novo', 'aberto', 'a_laudar', 'em_laudo', 'rascunho', 'revisao', 'assinado', 'liberado'];

    /** Situações tratadas como "concluído" — o cálculo de SLA congela nelas. */
    public const SITUACOES_CONCLUIDAS = ['assinado', 'liberado'];

    public const RELATORIO_POR_VALIDOS = ['conclusao', 'estudo', 'registro'];

    public function __construct(private RelatorioEstudosRepository $repo) {}

    /**
     * Normaliza a query string numa estrutura de filtros segura, já com
     * tenant_id e institution_names_autorizadas resolvidos.
     */
    public function parse(array $get, int $tenantId): array
    {
        $periodo = $get['periodo'] ?? 'hoje';
        [$dataDe, $dataAte] = $this->resolverPeriodo($periodo, $get['data_de'] ?? '', $get['data_ate'] ?? '');

        $unidadesAutorizadas = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);

        $unidade = trim($get['unidade'] ?? '');
        // Deny-by-default: só aceita a unidade do request se ela pertencer à lista autorizada do tenant.
        if ($unidade !== '' && !empty($unidadesAutorizadas) && !in_array($unidade, $unidadesAutorizadas, true)) {
            $unidade = '';
        }

        $modalidades = array_filter(array_map('trim', (array) ($get['modalidades'] ?? [])));
        $prioridades = array_values(array_intersect((array) ($get['prioridades'] ?? []), self::PRIORIDADES_VALIDAS));
        $situacoes   = array_values(array_intersect((array) ($get['situacoes']   ?? []), self::SITUACOES_VALIDAS));

        $medicoOuSolicitante = ($get['modo_pessoa'] ?? '') === 'solicitante' ? 'solicitante' : (($get['modo_pessoa'] ?? '') === 'medico' ? 'medico' : '');
        $pessoa = trim($get['pessoa'] ?? '');

        // Default 'estudo' (study_date) — é o que a worklist já usa hoje pro filtro "Data".
        // Só o relatório SLA Médicos expõe o seletor "Relatório por" pra trocar isso.
        $relatorioPor = in_array($get['relatorio_por'] ?? '', self::RELATORIO_POR_VALIDOS, true) ? $get['relatorio_por'] : 'estudo';

        $tempoValor    = isset($get['tempo_valor']) && $get['tempo_valor'] !== '' ? (float) $get['tempo_valor'] : null;
        $tempoUnidade  = ($get['tempo_unidade'] ?? 'horas') === 'minutos' ? 'minutos' : 'horas';

        return [
            'tenant_id'                     => $tenantId,
            'institution_names_autorizadas' => $unidadesAutorizadas,
            'periodo'                       => $periodo,
            'data_de'                       => $dataDe,
            'data_ate'                      => $dataAte,
            'unidade'                       => $unidade,
            'modalidades'                   => $modalidades,
            'prioridades'                   => $prioridades,
            'situacoes'                     => $situacoes,
            'medico_ou_solicitante'         => $medicoOuSolicitante,
            'pessoa'                        => $pessoa,
            'relatorio_por'                 => $relatorioPor,
            'tempo_valor'                   => $tempoValor,
            'tempo_unidade'                 => $tempoUnidade,
            'pagina'                        => max(1, (int) ($get['pagina'] ?? 1)),
            'por_pagina'                    => 25,
        ];
    }

    /** Opções pra popular os selects/chips da tela (sempre escopadas por tenant). */
    public function opcoes(int $tenantId, string $unidadeSelecionada = ''): array
    {
        $unidadesAutorizadas = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
        $unidadesParaModalidade = $unidadeSelecionada !== '' ? [$unidadeSelecionada] : $unidadesAutorizadas;

        return [
            'unidades'     => $unidadesAutorizadas,
            'modalidades'  => $this->repo->getModalidadesDisponiveis($tenantId, $unidadesParaModalidade),
            'medicos'      => $this->repo->getMedicosAtivos($tenantId),
            'solicitantes' => $this->repo->getSolicitantesDistintos($tenantId),
            'prioridades'  => self::PRIORIDADES_VALIDAS,
            'situacoes'    => self::SITUACOES_VALIDAS,
        ];
    }

    private function resolverPeriodo(string $periodo, string $deCustom, string $ateCustom): array
    {
        $hoje = date('Y-m-d');
        switch ($periodo) {
            case '7dias':
                return [date('Y-m-d', strtotime('-6 days')), $hoje];
            case 'mensal':
                return [date('Y-m-01'), $hoje];
            case 'personalizado':
                $de  = $this->validarData($deCustom) ?: $hoje;
                $ate = $this->validarData($ateCustom) ?: $hoje;
                if ($de > $ate) [$de, $ate] = [$ate, $de];
                return [$de, $ate];
            case 'hoje':
            default:
                return [$hoje, $hoje];
        }
    }

    private function validarData(string $data): ?string
    {
        $d = \DateTime::createFromFormat('Y-m-d', $data);
        return ($d && $d->format('Y-m-d') === $data) ? $data : null;
    }
}
