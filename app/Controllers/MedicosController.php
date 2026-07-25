<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\MedicoService;

/**
 * MedicosController — enxuto, sem SQL, sem regras de negócio.
 * Toda lógica está em MedicoService; toda SQL está em MedicoRepository.
 */
class MedicosController extends Controller
{
    private MedicoService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new MedicoService();
    }

    // -------------------------------------------------------------------------
    // Guard de tenant: garante que o controller nunca opera sem tenant_id
    // -------------------------------------------------------------------------
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            Logger::error('[MedicosController] Acesso sem tenant_id — redirecionando', [
                'user_id'  => Auth::userId(),
                'is_admin' => Auth::isPlatformAdmin(),
                'uri'      => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $id;
    }

    // -------------------------------------------------------------------------
    // READ — Listagem
    // -------------------------------------------------------------------------

    public function index(): void
    {
        $tenantId     = $this->tenantId();
        $busca        = trim($_GET['busca'] ?? '');
        $pagina       = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina    = 20;

        $medicos      = $this->service->listar($tenantId, $busca, $pagina, $porPagina);
        $total        = $this->service->total($tenantId, $busca);
        $totalPaginas = (int) ceil($total / $porPagina);

        Logger::error('[MedicosController::index] Listagem carregada', [
            'tenant_id' => $tenantId,
            'busca'     => $busca,
            'pagina'    => $pagina,
            'total'     => $total,
        ]);

        $this->view('medicos/index', [
            'title'        => 'Médicos',
            'medicos'      => $medicos,
            'busca'        => $busca,
            'pagina'       => $pagina,
            'totalPaginas' => $totalPaginas,
            'total'        => $total,
        ]);
    }

    // -------------------------------------------------------------------------
    // CREATE — Formulário + Persistência
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $tenantId = $this->tenantId();

        // Recupera erros e dados do POST anterior (se houver redirect de volta)
        $erros     = $_SESSION['form_erros'] ?? [];
        $formDados = $_SESSION['form_dados'] ?? [];
        unset($_SESSION['form_erros'], $_SESSION['form_dados']);

        $form = $this->service->dadosFormulario($tenantId);

        $this->view('medicos/form', array_merge($form, [
            'title'     => 'Novo Médico',
            'medico'    => $formDados ?: null,
            'erros'     => $erros,
        ]));
    }

    public function store(): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::store] Início do cadastro', [
            'tenant_id' => $tenantId,
            'post_nome' => $_POST['nome'] ?? '(vazio)',
            'post_crm'  => $_POST['crm'] ?? '(vazio)',
            'post_keys' => implode(',', array_keys($_POST)),
        ]);

        $resultado = $this->service->cadastrar($_POST, $tenantId);

        if (!$resultado['ok']) {
            Logger::error('[MedicosController::store] Cadastro falhou — redirecionando com erros', [
                'tenant_id' => $tenantId,
                'erros'     => $resultado['erros'],
            ]);
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            $this->redirect('/medicos/create?error=validacao');
            return;
        }

        $_SESSION['success'] = 'Médico cadastrado com sucesso!';
        Logger::error('[MedicosController::store] Cadastro concluído com sucesso', [
            'tenant_id' => $tenantId,
            'medico_id' => $resultado['id'],
        ]);
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // READ — Edição
    // -------------------------------------------------------------------------

    public function edit(int $id): void
    {
        $tenantId = $this->tenantId();
        $medico   = $this->service->buscarPorId($id, $tenantId);

        if (!$medico) {
            Logger::error('[MedicosController::edit] Médico não encontrado ou tenant inválido', [
                'id'        => $id,
                'tenant_id' => $tenantId,
            ]);
            http_response_code(404);
            exit('Médico não encontrado.');
        }

        $erros     = $_SESSION['form_erros'] ?? [];
        $formDados = $_SESSION['form_dados'] ?? [];
        unset($_SESSION['form_erros'], $_SESSION['form_dados']);

        // Se houver dados do POST anterior (após redirect de erro), mescla com os dados do banco
        if ($formDados) {
            $medico = array_merge($medico, $formDados);
        }

        $form = $this->service->dadosFormulario($tenantId, $id);

        $this->view('medicos/form', array_merge($form, [
            'title'  => 'Editar Médico',
            'medico' => $medico,
            'erros'  => $erros,
        ]));
    }

    // -------------------------------------------------------------------------
    // UPDATE — Persistência da edição
    // -------------------------------------------------------------------------

    public function update(int $id): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::update] Início da atualização', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
            'post_nome' => $_POST['nome'] ?? '(vazio)',
        ]);

        $resultado = $this->service->atualizar($id, $_POST, $tenantId);

        if (!$resultado['ok']) {
            Logger::error('[MedicosController::update] Atualização falhou', [
                'tenant_id' => $tenantId,
                'medico_id' => $id,
                'erros'     => $resultado['erros'],
            ]);
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            $this->redirect("/medicos/{$id}/edit?error=validacao");
            return;
        }

        $_SESSION['success'] = 'Médico atualizado com sucesso!';
        Logger::error('[MedicosController::update] Atualização concluída', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
        ]);
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // DELETE / TOGGLE STATUS — Soft delete
    // -------------------------------------------------------------------------

    public function toggleStatus(int $id): void
    {
        $tenantId = $this->tenantId();

        Logger::error('[MedicosController::toggleStatus] Alternando status', [
            'tenant_id' => $tenantId,
            'medico_id' => $id,
        ]);

        $this->service->toggleStatus($id, $tenantId);
        $_SESSION['success'] = 'Status do médico atualizado.';
        $this->redirect('/medicos');
    }

    // -------------------------------------------------------------------------
    // API — Busca de CEP (ViaCEP)
    // -------------------------------------------------------------------------

    public function buscarCep(string $cep): void
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            $this->json(['error' => 'CEP inválido'], 400);
            return;
        }

        $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            Logger::error('[MedicosController::buscarCep] ViaCEP indisponível', ['cep' => $cep, 'http' => $httpCode]);
            $this->json(['error' => 'Erro ao consultar o CEP.'], 502);
            return;
        }

        $data = json_decode($response, true);
        if (!$data || !empty($data['erro'])) {
            $this->json(['error' => 'CEP não encontrado.'], 404);
            return;
        }

        $this->json([
            'cep'         => $data['cep'] ?? $cep,
            'logradouro'  => $data['logradouro'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'bairro'      => $data['bairro'] ?? '',
            'cidade'      => $data['localidade'] ?? '',
            'estado'      => $data['uf'] ?? '',
        ]);
    }
}
