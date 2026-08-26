<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;

final class RelatorioAuditoriaExportService
{
    public function pdf(array $dados, string $arquivo): void
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('Biblioteca de PDF indisponível.');
        }
        ob_start();
        extract($dados, EXTR_SKIP);
        require __DIR__ . '/../Views/relatorios/pdf/auditoria.php';
        $html = (string) ob_get_clean();
        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'isPhpEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($arquivo, ['Attachment' => true]);
    }

    public function csv(array $linhas, array $tenant, string $tipo, array $filtros, array $emissao, string $usuario): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="RELATORIO_AUDITORIA_' . strtoupper($tipo) . '_' . date('Ymd_Hi') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');

        $this->linha($out, ['RELATÓRIO DE AUDITORIA VERIFICÁVEL']);
        $this->linha($out, ['Tenant', $tenant['razao_social'] ?: $tenant['nome']]);
        if (!empty($tenant['cnpj'])) $this->linha($out, ['CNPJ', $tenant['cnpj']]);
        $this->linha($out, ['Tipo', $tipo]);
        $this->linha($out, ['Emitido em', $emissao['emitido_em']->format('d/m/Y H:i:s')]);
        $this->linha($out, ['Emitido por', $usuario]);
        $this->linha($out, ['Código de verificação', $emissao['codigo_publico']]);
        $this->linha($out, ['URL de validação', $emissao['url_validacao']]);
        $this->linha($out, ['Integridade', $emissao['manifesto_hash_curto']]);
        $this->linha($out, ['Período', $filtros['data_de'] . ' a ' . $filtros['data_ate']]);
        $this->linha($out, []);

        $this->linha($out, ['Data/hora', 'Autor', 'Evento', 'Entidade', 'Contexto', 'IP', 'Região', 'Assumido em', 'Tempo clínico', 'Peer Review']);
        foreach ($linhas as $linha) {
            $this->linha($out, [
                $linha['data'], $linha['autor'], $linha['evento'], $linha['entidade'], $linha['contexto'],
                $linha['ip'], $linha['regiao'], $linha['assumido_em'], $linha['duracao'], $linha['peer_review'],
            ]);
        }
        fclose($out);
        exit;
    }

    private function linha($out, array $valores): void
    {
        $seguros = array_map(static function (mixed $valor): string {
            $texto = (string) $valor;
            return preg_match('/^[=+\-@]/', $texto) ? "'" . $texto : $texto;
        }, $valores);
        fputcsv($out, $seguros, ';');
    }
}
