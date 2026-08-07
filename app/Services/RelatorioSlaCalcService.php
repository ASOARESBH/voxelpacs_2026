<?php
/**
 * RelatorioSlaCalcService — motor de cálculo de SLA do relatório SLA Médicos.
 *
 * Fonte do "SLA alvo": bi_sla_regras (Cadastros → Regras de SLA), a única
 * tabela de configuração de SLA já existente no sistema. Essa tabela foi
 * desenhada como motor de gatilho de remanejamento automático (não como uma
 * tabela de "meta por prioridade"), então este serviço resolve, para cada
 * estudo, a regra ativa mais específica que casa por unidade/modalidade —
 * ver `resolverRegra()`. Estudo sem nenhuma regra que case cai no status
 * `sem_sla` (não conta como dentro do prazo nem como estourado).
 *
 * @see SKILL-VOXEL-PACS/modules/relatorios.md
 */
namespace App\Services;

class RelatorioSlaCalcService
{
    /**
     * % do SLA alvo a partir do qual o status vira "Atenção" (amarelo).
     * Nomeado e configurável de propósito — não hardcoded solto no meio do
     * cálculo — para poder ajustar depois sem procurar o número escondido.
     */
    public const ATENCAO_THRESHOLD_PCT = 80.0;

    /**
     * Processa as linhas cruas do repositório: calcula SLA por estudo,
     * aplica o filtro "Tempo maior que" (se houver), ordena por tempo
     * decorrido decrescente, pagina e agrega por médico responsável.
     *
     * @param array $linhasCru  saída de RelatorioEstudosRepository::buscarEstudos()['linhas']
     * @param array $regrasAtivas saída de RelatorioEstudosRepository::getRegrasSlaAtivas()
     * @param array $filtros   ver RelatorioFiltrosService::parse()
     */
    public function processar(array $linhasCru, array $regrasAtivas, array $filtros): array
    {
        $calculadas = array_map(
            fn(array $linha) => $this->calcularLinha($linha, $regrasAtivas, $filtros['relatorio_por']),
            $linhasCru
        );

        if ($filtros['tempo_valor'] !== null) {
            $limiteMin = $filtros['tempo_unidade'] === 'horas' ? $filtros['tempo_valor'] * 60 : $filtros['tempo_valor'];
            $calculadas = array_values(array_filter(
                $calculadas,
                fn(array $l) => $l['tempo_decorrido_min'] !== null && $l['tempo_decorrido_min'] > $limiteMin
            ));
        }

        usort($calculadas, fn(array $a, array $b) => ($b['tempo_decorrido_min'] ?? -1) <=> ($a['tempo_decorrido_min'] ?? -1));

        $total      = count($calculadas);
        $porPagina  = (int) ($filtros['por_pagina'] ?? 25);
        $pagina     = max(1, (int) ($filtros['pagina'] ?? 1));
        $paginadas  = array_slice($calculadas, ($pagina - 1) * $porPagina, $porPagina);

        return [
            'linhas'              => $paginadas,
            'total'               => $total,
            'agregado_por_medico' => $this->agruparPorMedico($calculadas),
        ];
    }

    /**
     * Enriquece uma linha do estudo com: marco temporal, tempo decorrido,
     * SLA alvo, status (verde|amarelo|vermelho|sem_sla) e percentual.
     */
    public function calcularLinha(array $linha, array $regrasAtivas, string $relatorioPor): array
    {
        $concluido = in_array($linha['situacao'], RelatorioFiltrosService::SITUACOES_CONCLUIDAS, true) && !empty($linha['assinado_em']);

        $marcoTs = $this->resolverMarcoTimestamp($linha, $relatorioPor);
        if ($marcoTs === null) {
            return $linha + [
                'tempo_decorrido_min' => null, 'sla_alvo_min' => null,
                'status_sla' => 'sem_sla', 'percentual_sla' => null, 'concluido' => $concluido,
            ];
        }

        // Congela no momento da conclusão — não continua contando depois de assinado/liberado.
        $fimTs = $concluido ? strtotime((string) $linha['assinado_em']) : time();
        $tempoDecorridoMin = $fimTs ? max(0, (int) round(($fimTs - $marcoTs) / 60)) : null;

        $regra      = $this->resolverRegra($regrasAtivas, (string) ($linha['institution_name'] ?? ''), (string) ($linha['modalities'] ?? ''));
        $slaAlvoMin = $regra['limite_minutos'] ?? null;

        if ($slaAlvoMin === null || $tempoDecorridoMin === null) {
            $status = 'sem_sla';
            $pct    = null;
        } else {
            $pct    = $slaAlvoMin > 0 ? ($tempoDecorridoMin / $slaAlvoMin) * 100 : 100.0;
            $status = $pct <= self::ATENCAO_THRESHOLD_PCT ? 'verde' : ($pct <= 100.0 ? 'amarelo' : 'vermelho');
        }

        return $linha + [
            'tempo_decorrido_min' => $tempoDecorridoMin,
            'sla_alvo_min'        => $slaAlvoMin,
            'status_sla'          => $status,
            'percentual_sla'      => $pct,
            'concluido'           => $concluido,
        ];
    }

    /**
     * Resolve, dentre as regras ativas do tenant, qual "vale" para um estudo
     * de dada unidade/modalidade — por especificidade: unidade+modalidade
     * exatas > só unidade > só modalidade > regra global (ambas NULL).
     * Empate quebrado por menor `prioridade` (mesma ordem que o robô de
     * remanejamento já usa em SlaRulesEngineService). `metrica`/`operador`
     * da regra são ignorados aqui — só interessam pro robô, não pro relatório.
     */
    public function resolverRegra(array $regrasAtivas, string $institutionName, string $modalitiesRaw): ?array
    {
        $candidatas = [];
        foreach ($regrasAtivas as $r) {
            $filtroInst = $r['filtro_institution_name'] ?? null;
            $filtroMod  = $r['filtro_modalidade'] ?? null;

            $matchInst = empty($filtroInst) || $filtroInst === $institutionName;
            $matchMod  = empty($filtroMod) || str_contains($modalitiesRaw, $filtroMod);
            if (!$matchInst || !$matchMod) continue;

            $especificidade = (empty($filtroInst) ? 0 : 2) + (empty($filtroMod) ? 0 : 1);
            $candidatas[] = $r + ['_especificidade' => $especificidade];
        }
        if (empty($candidatas)) return null;

        usort($candidatas, function (array $a, array $b) {
            return $b['_especificidade'] <=> $a['_especificidade']
                ?: $a['prioridade'] <=> $b['prioridade'];
        });

        return $candidatas[0];
    }

    /**
     * Marco temporal (timestamp) usado como início do cálculo, resolvido a
     * partir do "Relatório por". "Data Conclusão do Laudo" governa apenas o
     * filtro de período (aplicado na consulta) — usar a própria conclusão
     * como início do cronômetro seria sempre zero p/ estudos concluídos e
     * indefinido p/ estudos abertos, então nesse caso o cálculo de tempo
     * decorrido cai no mesmo marco de "Data Registro do Estudo".
     */
    private function resolverMarcoTimestamp(array $linha, string $relatorioPor): ?int
    {
        if ($relatorioPor === 'estudo' && !empty($linha['study_date'])) {
            $valor = $linha['study_date'] . (!empty($linha['study_time']) ? ' ' . $linha['study_time'] : '');
            $ts    = strtotime($valor);
            return $ts ?: null;
        }
        if (!empty($linha['recebido_em'])) {
            $ts = strtotime((string) $linha['recebido_em']);
            return $ts ?: null;
        }
        return null;
    }

    /**
     * Agregação por médico responsável — agrupa por usuario_responsavel_id
     * quando disponível (FK estável); cai pro nome (assumido_por) em linhas
     * legadas sem o FK preenchido.
     */
    public function agruparPorMedico(array $linhasCalculadas): array
    {
        $grupos = [];
        foreach ($linhasCalculadas as $l) {
            $nome = $l['assumido_por'] !== '' ? $l['assumido_por'] : 'Não atribuído';
            $key  = !empty($l['usuario_responsavel_id']) ? 'uid_' . $l['usuario_responsavel_id'] : 'nome_' . $nome;

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'nome' => $nome, 'total' => 0,
                    'verde' => 0, 'amarelo' => 0, 'vermelho' => 0, 'sem_sla' => 0,
                    'soma_tempo_laudo_min' => 0, 'qtd_concluidos' => 0,
                    'com_sla' => 0, 'dentro_prazo' => 0,
                ];
            }

            $grupos[$key]['total']++;
            $grupos[$key][$l['status_sla']]++;
            if ($l['status_sla'] !== 'sem_sla') {
                $grupos[$key]['com_sla']++;
                if ($l['status_sla'] !== 'vermelho') $grupos[$key]['dentro_prazo']++;
            }
            if (!empty($l['concluido']) && $l['tempo_decorrido_min'] !== null) {
                $grupos[$key]['soma_tempo_laudo_min'] += $l['tempo_decorrido_min'];
                $grupos[$key]['qtd_concluidos']++;
            }
        }

        foreach ($grupos as &$g) {
            $g['tempo_medio_laudo_min']  = $g['qtd_concluidos'] > 0 ? (int) round($g['soma_tempo_laudo_min'] / $g['qtd_concluidos']) : null;
            $g['percentual_cumprimento'] = $g['com_sla'] > 0 ? round(($g['dentro_prazo'] / $g['com_sla']) * 100, 1) : null;
        }
        unset($g);

        $grupos = array_values($grupos);
        usort($grupos, fn(array $a, array $b) => $b['total'] <=> $a['total']);

        return $grupos;
    }
}
