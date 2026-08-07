<?php
/**
 * RelatorioExportService — export profissional (PDF/XLSX) compartilhado
 * pelos relatórios Exames e SLA Médicos.
 *
 * PDF segue o mesmo padrão já usado por ReportPdfService (HTML → Dompdf,
 * `isRemoteEnabled=false`). XLSX usa PhpSpreadsheet diretamente (não o
 * ExportService genérico, que só faz array→planilha sem nenhuma formatação
 * — insuficiente para o padrão pedido: cabeçalho, resumo de filtros, zebra,
 * cor de status SLA).
 */
namespace App\Services;

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RelatorioExportService
{
    private const COR_PRIMARIA   = '1A56DB';
    private const COR_ZEBRA      = 'F3F4F6';
    private const COR_SLA_VERDE  = 'DCFCE7';
    private const COR_SLA_AMARELO= 'FEF9C3';
    private const COR_SLA_VERMELHO='FEE2E2';
    private const COR_SLA_NEUTRO = 'E5E7EB';

    // ─────────────────────────────────────────────────────────────────────
    // PDF — HTML (view dedicada) → Dompdf, mesmo mecanismo de ReportPdfService
    // ─────────────────────────────────────────────────────────────────────
    public function streamPdf(string $viewPath, array $data, string $filename): void
    {
        $html = $this->renderView($viewPath, $data);

        // isPhpEnabled: só pra rodar o <script type="text/php"> do rodapé de
        // paginação (page_text) — seguro aqui porque os templates são
        // arquivos próprios (não HTML de terceiros) e todo dado dinâmico
        // neles passa por htmlspecialchars(), então não há como injetar
        // um novo bloco <script type="text/php"> via dado de estudo/paciente.
        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'isPhpEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
    }

    private function renderView(string $viewPath, array $data): string
    {
        extract($data);
        ob_start();
        require $viewPath;
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────
    // XLSX — Relatório Exames
    // ─────────────────────────────────────────────────────────────────────
    public function streamXlsxExames(array $linhas, array $resumoFiltros, string $tenantNome, string $usuarioNome, string $filename): void
    {
        $colunas = ['Data', 'Paciente', 'Unidade', 'Modalidade', 'Prioridade', 'Situação', 'Médico', 'Solicitante'];
        $linha0  = $this->cabecalho($tenantNome, 'Relatório de Exames', $usuarioNome, $resumoFiltros, count($colunas));
        $dados   = array_map(fn(array $l) => [
            $l['study_date'] ?? '',
            $l['patient_name'] ?? '',
            $l['institution_name'] ?? '',
            $l['modalities'] ?? '',
            ucfirst($l['prioridade'] ?? ''),
            $this->situacaoLabel($l['situacao'] ?? ''),
            $l['assumido_por'] ?: '—',
            $l['especialidade'] ?: ($l['referring_physician_name'] ?: '—'),
        ], $linhas);

        $sheet = $this->montarPlanilhaBase($linha0, $colunas, $dados);
        $this->baixarXlsx($sheet->getParent(), $filename);
    }

    // ─────────────────────────────────────────────────────────────────────
    // XLSX — Relatório SLA Médicos (2 seções: detalhe + resumo por médico)
    // ─────────────────────────────────────────────────────────────────────
    public function streamXlsxSla(array $linhas, array $agregadoPorMedico, array $resumoFiltros, string $tenantNome, string $usuarioNome, string $filename): void
    {
        $colunas = ['Data', 'Paciente', 'Unidade', 'Modalidade', 'Médico', 'Tempo decorrido', 'SLA alvo', '% SLA', 'Status'];
        $primeiraLinha = $this->cabecalho($tenantNome, 'Relatório SLA Médicos', $usuarioNome, $resumoFiltros, count($colunas));

        $dados = [];
        $statusPorLinha = [];
        foreach ($linhas as $l) {
            $dados[] = [
                $l['study_date'] ?? '',
                $l['patient_name'] ?? '',
                $l['institution_name'] ?? '',
                $l['modalities'] ?? '',
                $l['assumido_por'] ?: 'Não atribuído',
                $this->formatarMinutos($l['tempo_decorrido_min'] ?? null),
                $l['sla_alvo_min'] !== null ? $this->formatarMinutos($l['sla_alvo_min']) : '—',
                $l['percentual_sla'] !== null ? round($l['percentual_sla']) . '%' : '—',
                $this->statusSlaLabel($l['status_sla'] ?? 'sem_sla'),
            ];
            $statusPorLinha[] = $l['status_sla'] ?? 'sem_sla';
        }

        $sheet = $this->montarPlanilhaBase($primeiraLinha, $colunas, $dados);
        $primeiraLinhaDados = $primeiraLinha + 1;
        foreach ($statusPorLinha as $i => $status) {
            $linhaExcel = $primeiraLinhaDados + $i;
            $cor = match ($status) {
                'verde'    => self::COR_SLA_VERDE,
                'amarelo'  => self::COR_SLA_AMARELO,
                'vermelho' => self::COR_SLA_VERMELHO,
                default    => self::COR_SLA_NEUTRO,
            };
            $sheet->getStyle('I' . $linhaExcel)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($cor);
        }

        // ── Seção 2: resumo por médico ──────────────────────────────────
        $linhaResumo = $primeiraLinhaDados + count($dados) + 2;
        $sheet->setCellValue('A' . $linhaResumo, 'Resumo por médico');
        $sheet->getStyle('A' . $linhaResumo)->getFont()->setBold(true)->setSize(12);
        $linhaResumo++;

        $colunasResumo = ['Médico', 'Total', 'Dentro do prazo', 'Atenção', 'Estourado', 'Sem SLA', 'Tempo médio de laudo', '% Cumprimento'];
        $sheet->fromArray([$colunasResumo], null, 'A' . $linhaResumo);
        $sheet->getStyle('A' . $linhaResumo . ':H' . $linhaResumo)->getFont()->setBold(true);
        $sheet->getStyle('A' . $linhaResumo . ':H' . $linhaResumo)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COR_ZEBRA);
        $linhaResumo++;

        foreach ($agregadoPorMedico as $g) {
            $sheet->fromArray([[
                $g['nome'],
                $g['total'],
                $g['verde'],
                $g['amarelo'],
                $g['vermelho'],
                $g['sem_sla'],
                $g['tempo_medio_laudo_min'] !== null ? $this->formatarMinutos($g['tempo_medio_laudo_min']) : '—',
                $g['percentual_cumprimento'] !== null ? $g['percentual_cumprimento'] . '%' : '—',
            ]], null, 'A' . $linhaResumo);
            $linhaResumo++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->baixarXlsx($sheet->getParent(), $filename);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers de montagem/estilo compartilhados
    // ─────────────────────────────────────────────────────────────────────

    /** Cabeçalho (tenant/relatório/data/usuário) + resumo de filtros. Retorna a linha onde a tabela começa. */
    private function cabecalho(string $tenantNome, string $tituloRelatorio, string $usuarioNome, array $resumoFiltros, int $numColunas): int
    {
        $this->_cabecalhoBuffer = [
            'tenant' => $tenantNome, 'titulo' => $tituloRelatorio, 'usuario' => $usuarioNome,
            'gerado_em' => date('d/m/Y H:i'), 'filtros' => $resumoFiltros, 'num_colunas' => $numColunas,
        ];
        // Linhas ocupadas: tenant, título, gerado em/por, linha em branco, resumo de filtros, linha em branco
        return 5 + count($resumoFiltros);
    }

    private array $_cabecalhoBuffer = [];

    private function montarPlanilhaBase(int $linhaTabela, array $colunas, array $dados)
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $h           = $this->_cabecalhoBuffer;

        $sheet->setCellValue('A1', $h['tenant']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $h['titulo']);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::COR_PRIMARIA);
        $sheet->setCellValue('A3', 'Gerado em ' . $h['gerado_em'] . ' por ' . $h['usuario']);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        $linha = 5;
        foreach ($h['filtros'] as $label => $valor) {
            $sheet->setCellValue('A' . $linha, $label . ': ' . $valor);
            $sheet->getStyle('A' . $linha)->getFont()->setSize(9);
            $linha++;
        }

        // Cabeçalho da tabela
        $ultimaColuna = chr(ord('A') + count($colunas) - 1);
        $sheet->fromArray([$colunas], null, 'A' . $linhaTabela);
        $sheet->getStyle('A' . $linhaTabela . ':' . $ultimaColuna . $linhaTabela)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $linhaTabela . ':' . $ultimaColuna . $linhaTabela)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COR_PRIMARIA);
        $sheet->freezePane('A' . ($linhaTabela + 1));

        // Linhas de dados + zebra
        $primeiraLinhaDados = $linhaTabela + 1;
        $sheet->fromArray($dados, null, 'A' . $primeiraLinhaDados);
        foreach (array_keys($dados) as $i) {
            if ($i % 2 === 1) {
                $linhaExcel = $primeiraLinhaDados + $i;
                $sheet->getStyle('A' . $linhaExcel . ':' . $ultimaColuna . $linhaExcel)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COR_ZEBRA);
            }
        }

        $sheet->getStyle('A' . $linhaTabela . ':' . $ultimaColuna . ($primeiraLinhaDados + count($dados) - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (range('A', $ultimaColuna) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $sheet;
    }

    private function baixarXlsx(Spreadsheet $spreadsheet, string $filename): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    public function formatarMinutos(?int $min): string
    {
        if ($min === null) return '—';
        $h = intdiv($min, 60);
        $m = $min % 60;
        return $h > 0 ? "{$h}h{$m}min" : "{$m}min";
    }

    public function situacaoLabel(string $situacao): string
    {
        $map = [
            'novo' => 'Novo', 'aberto' => 'Aberto', 'a_laudar' => 'A Laudar', 'em_laudo' => 'Em Laudo',
            'rascunho' => 'Rascunho', 'revisao' => 'Revisão', 'assinado' => 'Assinado', 'liberado' => 'Liberado',
            'urgente' => 'Urgente',
        ];
        return $map[$situacao] ?? ucfirst($situacao);
    }

    public function statusSlaLabel(string $status): string
    {
        return match ($status) {
            'verde'    => 'Dentro do prazo',
            'amarelo'  => 'Atenção',
            'vermelho' => 'Estourado',
            default    => 'Sem SLA definido',
        };
    }
}
