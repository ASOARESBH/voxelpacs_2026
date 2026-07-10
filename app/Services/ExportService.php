<?php
namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService {
    public function exportarCsv(array $dados, array $colunas, string $filename = 'export.csv'): void {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        $out = fopen('php://output', 'w');
        fputcsv($out, $colunas);
        foreach ($dados as $row) {
            fputcsv($out, is_array($row) ? $row : (array) $row);
        }
        fclose($out);
        exit;
    }

    public function exportarXlsx(array $dados, array $colunas, string $filename = 'export.xlsx'): void {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$colunas], null, 'A1');
        $sheet->fromArray(array_map(fn($r) => is_array($r) ? $r : (array) $r, $dados), null, 'A2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
}
