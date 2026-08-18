<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Importacao;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportacaoService {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    public function processar(string $filePath, int $pacsId, array $layout): array {
        $tenantId    = TenantContext::id();
        $importacao  = new Importacao();
        $importacaoId = $importacao->create([
            'user_id'      => $_SESSION['user_id'],
            'pacs_id'      => $pacsId,
            'nome_arquivo' => basename($filePath),
            'status'       => 'processando',
        ]);

        $total = $importados = $duplicados = $erros = 0;
        $log   = [];

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray(null, true, true, true);
            $header      = array_shift($rows);

            foreach ($rows as $rowNum => $row) {
                $total++;
                try {
                    $data = $this->mapearLinha($row, $header, $layout);
                    if (!$data) { $erros++; continue; }

                    $data['tenant_id']    = $tenantId;
                    $data['pacs_id']      = $pacsId;
                    $data['importacao_id'] = $importacaoId;
                    $data['hash_dedup']   = hash('sha256', $tenantId . ($data['accession_number'] ?? '') . ($data['data_estudo'] ?? '') . ($data['medico_crm'] ?? ''));
                    $data['periodo_ref']  = !empty($data['data_estudo']) ? substr($data['data_estudo'], 0, 7) : null;

                                        $cols = implode(', ', array_keys($data));
                    $vals = ':' . implode(', :', array_keys($data));
                    $isPostgres = \App\Core\SqlHelper::isPostgres();
                    $sql = $isPostgres
                        ? "INSERT INTO bi_exames ({$cols}) VALUES ({$vals})
                           ON CONFLICT (hash_dedup) DO UPDATE SET importacao_id = EXCLUDED.importacao_id
                           RETURNING (xmax = 0) AS inserted"
                        : "INSERT INTO bi_exames ({$cols}) VALUES ({$vals})
                           ON DUPLICATE KEY UPDATE importacao_id = VALUES(importacao_id)";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($data);
                    $inserted = $isPostgres ? $stmt->fetchColumn() : null;
                    $affected = $isPostgres
                        ? ($inserted === true || $inserted === 't' || $inserted === '1' ? 1 : 2)
                        : $stmt->rowCount();

                    if ($affected === 1) $importados++;

                    else $duplicados++;
                } catch (\Throwable $e) {
                    $erros++;
                    $log[] = "Linha {$rowNum}: " . $e->getMessage();
                    Logger::error('ImportacaoService linha', ['row' => $rowNum, 'error' => $e->getMessage()]);
                }
            }

            $importacao->updateStatus($importacaoId, 'concluido', [
                'total_registros'  => $total,
                'total_importados' => $importados,
                'total_duplicados' => $duplicados,
                'total_erros'      => $erros,
                'log'              => implode("\n", $log),
            ]);

            // Atualiza snapshots preditivos
            $periodos = $this->pdo->prepare("SELECT DISTINCT periodo_ref FROM bi_exames WHERE importacao_id = :id AND periodo_ref IS NOT NULL");
            $periodos->execute(['id' => $importacaoId]);
            $predService = new PreditivoService();
            foreach ($periodos->fetchAll(\PDO::FETCH_COLUMN) as $periodo) {
                $predService->atualizarSnapshots($tenantId, $periodo);
            }
        } catch (\Throwable $e) {
            $importacao->updateStatus($importacaoId, 'erro', ['log' => $e->getMessage()]);
            Logger::error('ImportacaoService::processar', ['error' => $e->getMessage()]);
        }

        return compact('total', 'importados', 'duplicados', 'erros', 'importacaoId');
    }

    private function mapearLinha(array $row, array $header, array $layout): ?array {
        $data = [];
        foreach ($layout as $campo => $coluna) {
            $key = array_search($coluna, $header);
            $data[$campo] = $key !== false ? ($row[$key] ?? null) : null;
        }
        return empty(array_filter($data)) ? null : $data;
    }
}
