<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Estados;
use App\Core\TenantContext;
use App\Models\Medico;
use App\Repositories\EstudosRepository;

class MedicosController extends Controller {
    public function index(): void {
        $medicos = (new Medico())->findAll();
        $this->view('medicos/index', ['medicos' => $medicos]);
    }

    public function detalhe(int $id): void {
        $medico = (new Medico())->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/detalhe', ['medico' => $medico]);
    }

    public function create(): void {
        $medicoModel = new Medico();
        $this->view('medicos/form', [
            'medico'          => null,
            'title'           => 'Novo Médico',
            'usuarios'        => $medicoModel->findUsuariosVinculaveis(),
            'unidades'        => (new EstudosRepository())->getUnidades(TenantContext::id(), false),
            'unidadesMarcadas'=> [],
        ]);
    }

    public function store(): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;
        $usuarioId     = $this->resolverUsuarioId($tenantId, null);
        $unidades      = $_POST['unidades'] ?? [];
        $endereco      = $this->dadosEndereco();

        if (!$nome || !$tenantId) {
            $this->redirect('/medicos/create?error=campos_obrigatorios');
            return;
        }

        $pdo = Database::getInstance();
        $pdo->prepare("
            INSERT INTO bi_medicos
                (tenant_id, nome, crm, crm_uf, especialidade, usuario_id, email, telefone,
                 cep, logradouro, numero, complemento, bairro, cidade, estado, ativo)
            VALUES
                (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ")->execute(array_merge(
            [$tenantId, $nome, $crm, $endereco['crm_uf'], $especialidade, $usuarioId, $endereco['email'], $endereco['telefone']],
            [$endereco['cep'], $endereco['logradouro'], $endereco['numero'], $endereco['complemento'], $endereco['bairro'], $endereco['cidade'], $endereco['estado']]
        ));

        (new Medico())->sincronizarUnidades((int) $pdo->lastInsertId(), $tenantId, $unidades);

        $this->redirect('/medicos');
    }

    public function edit(int $id): void {
        $medicoModel = new Medico();
        $medico = $medicoModel->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/form', [
            'medico'          => $medico,
            'title'           => 'Editar Médico',
            'usuarios'        => $medicoModel->findUsuariosVinculaveis(),
            'unidades'        => (new EstudosRepository())->getUnidades(TenantContext::id(), false),
            'unidadesMarcadas'=> $medicoModel->getUnidadesVinculadas($id),
        ]);
    }

    public function update(int $id): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;
        $usuarioId     = $this->resolverUsuarioId($tenantId, $id);
        $unidades      = $_POST['unidades'] ?? [];
        $endereco      = $this->dadosEndereco();

        if ($nome) {
            Database::getInstance()->prepare("
                UPDATE bi_medicos SET
                    nome=?, crm=?, crm_uf=?, especialidade=?, usuario_id=?, email=?, telefone=?,
                    cep=?, logradouro=?, numero=?, complemento=?, bairro=?, cidade=?, estado=?
                WHERE id=?
            ")->execute(array_merge(
                [$nome, $crm, $endereco['crm_uf'], $especialidade, $usuarioId, $endereco['email'], $endereco['telefone']],
                [$endereco['cep'], $endereco['logradouro'], $endereco['numero'], $endereco['complemento'], $endereco['bairro'], $endereco['cidade'], $endereco['estado'], $id]
            ));
        }

        (new Medico())->sincronizarUnidades($id, $tenantId, $unidades);

        $this->redirect('/medicos');
    }

    /** Lê os campos de contato/endereço do POST, normalizando vazio para null. */
    private function dadosEndereco(): array {
        $campos = ['email', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade'];
        $dados = [];
        foreach ($campos as $campo) {
            $dados[$campo] = trim($_POST[$campo] ?? '') ?: null;
        }
        $uf = strtoupper(trim($_POST['estado'] ?? ''));
        $dados['estado'] = isset(Estados::LISTA[$uf]) ? $uf : null;

        $crmUf = strtoupper(trim($_POST['crm_uf'] ?? ''));
        $dados['crm_uf'] = isset(Estados::LISTA[$crmUf]) ? $crmUf : null;

        return $dados;
    }

    /** Busca automática de endereço por CEP (ViaCEP), usada pelo form via fetch(). */
    public function buscarCep(string $cep): void {
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
            $this->json(['error' => 'Erro ao consultar o CEP.'], 502);
            return;
        }

        $data = json_decode($response, true);
        if (!$data || !empty($data['erro'])) {
            $this->json(['error' => 'CEP não encontrado.'], 404);
            return;
        }

        $this->json([
            'cep'        => $data['cep'] ?? $cep,
            'logradouro' => $data['logradouro'] ?? '',
            'complemento'=> $data['complemento'] ?? '',
            'bairro'     => $data['bairro'] ?? '',
            'cidade'     => $data['localidade'] ?? '',
            'estado'     => $data['uf'] ?? '',
        ]);
    }

    /**
     * Resolve o usuario_id enviado pelo form, validando que pertence ao tenant
     * atual e ainda não está vinculado a outro médico (bi_medicos.uq_tenant_usuario).
     * Retorna null se vazio ou inválido, para não quebrar o cadastro por causa do vínculo.
     */
    private function resolverUsuarioId(?int $tenantId, ?int $medicoIdAtual): ?int {
        $usuarioId = (int) ($_POST['usuario_id'] ?? 0);
        if (!$usuarioId || !$tenantId) return null;

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT u.id FROM bi_users u
            INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
            WHERE u.id = ? AND u.status = 'ativo'
        ");
        $stmt->execute([$tenantId, $usuarioId]);
        if (!$stmt->fetchColumn()) return null;

        $stmtDup = $pdo->prepare("SELECT id FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND id != ?");
        $stmtDup->execute([$tenantId, $usuarioId, $medicoIdAtual ?? 0]);
        if ($stmtDup->fetchColumn()) return null;

        return $usuarioId;
    }

    public function toggleStatus(int $id): void {
        Database::getInstance()
            ->prepare("UPDATE bi_medicos SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id=?")
            ->execute([$id]);
        $this->redirect('/medicos');
    }
}
