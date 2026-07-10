<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\TenantContext;
use App\Models\Importacao;
use App\Services\ImportacaoService;

class ImportacaoController extends Controller {
    public function index(): void {
        $importacoes = (new Importacao())->findAll();
        $this->view('importacao/index', ['importacoes' => $importacoes]);
    }

    public function processar(): void {
        if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            $this->redirect('/importacao?erro=arquivo_invalido');
        }

        $pacsId    = (int) ($_POST['pacs_id'] ?? 0);
        $tenantId  = TenantContext::id();
        $uploadDir = __DIR__ . "/../../storage/uploads/importacoes/{$tenantId}/";

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename  = uniqid() . '_' . basename($_FILES['arquivo']['name']);
        $destPath  = $uploadDir . $filename;

        if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destPath)) {
            $this->redirect('/importacao?erro=upload_falhou');
        }

        // Layout padrão — pode ser configurado por PACS
        $layout = ['data_estudo' => 'Data Estudo', 'medico_nome' => 'Médico', 'modalidade' => 'Modalidade'];

        $resultado = (new ImportacaoService())->processar($destPath, $pacsId, $layout);
        $this->redirect('/importacao?sucesso=1&importados=' . $resultado['importados']);
    }

    public function verLog(int $id): void {
        $importacao = (new Importacao())->findAll();
        $this->view('importacao/index', ['importacoes' => $importacao]);
    }

    public function deletar(int $id): void {
        $this->redirect('/importacao');
    }
}
