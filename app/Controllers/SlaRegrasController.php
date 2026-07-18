<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;
use App\Models\SlaRegra;
use App\Repositories\EstudosRepository;
use App\Repositories\SlaRegrasRepository;

class SlaRegrasController extends Controller {

    public function __construct() {
        if (!Auth::can('manage_sla_regras')) {
            http_response_code(403);
            exit('Acesso negado.');
        }
    }

    public function index(): void {
        $regras = (new SlaRegra())->findAll();
        $this->view('sla_regras/index', ['regras' => $regras]);
    }

    public function create(): void {
        $this->view('sla_regras/form', [
            'regra'    => null,
            'title'    => 'Nova Regra de SLA',
            'unidades' => $this->listarUnidades(),
            'medicos'  => $this->listarMedicosVinculaveis(),
        ]);
    }

    public function store(): void {
        $tenantId = TenantContext::id();
        if (!$tenantId) { $this->redirect('/sla-regras/create?error=tenant'); return; }

        $dados = $this->dadosDoFormulario();
        if (!$dados['nome'] || !$dados['limite_minutos']) {
            $this->redirect('/sla-regras/create?error=campos_obrigatorios');
            return;
        }
        if ($dados['tipo_acao'] === 'especifico' && !$this->medicoPertenceAoTenant($tenantId, $dados['medico_especifico_id'])) {
            $this->redirect('/sla-regras/create?error=medico_invalido');
            return;
        }

        Database::getInstance()->prepare("
            INSERT INTO bi_sla_regras
                (tenant_id, nome, metrica, operador, limite_minutos, filtro_institution_name,
                 filtro_modalidade, tipo_acao, medico_especifico_id, prioridade, ativo)
            VALUES
                (:tenant_id, :nome, :metrica, :operador, :limite_minutos, :filtro_institution_name,
                 :filtro_modalidade, :tipo_acao, :medico_especifico_id, :prioridade, :ativo)
        ")->execute(array_merge($dados, ['tenant_id' => $tenantId]));

        $this->redirect('/sla-regras');
    }

    public function edit(int $id): void {
        $regra = (new SlaRegra())->findById($id);
        if (!$regra) { http_response_code(404); exit('Regra não encontrada.'); }
        $this->view('sla_regras/form', [
            'regra'    => $regra,
            'title'    => 'Editar Regra de SLA',
            'unidades' => $this->listarUnidades(),
            'medicos'  => $this->listarMedicosVinculaveis(),
        ]);
    }

    public function update(int $id): void {
        $tenantId = TenantContext::id();
        $dados    = $this->dadosDoFormulario();

        if (!$dados['nome'] || !$dados['limite_minutos']) {
            $this->redirect("/sla-regras/{$id}/edit?error=campos_obrigatorios");
            return;
        }
        if ($dados['tipo_acao'] === 'especifico' && !$this->medicoPertenceAoTenant($tenantId, $dados['medico_especifico_id'])) {
            $this->redirect("/sla-regras/{$id}/edit?error=medico_invalido");
            return;
        }

        Database::getInstance()->prepare("
            UPDATE bi_sla_regras SET
                nome = :nome, metrica = :metrica, operador = :operador, limite_minutos = :limite_minutos,
                filtro_institution_name = :filtro_institution_name, filtro_modalidade = :filtro_modalidade,
                tipo_acao = :tipo_acao, medico_especifico_id = :medico_especifico_id,
                prioridade = :prioridade, ativo = :ativo
            WHERE id = :id AND tenant_id = :tenant_id
        ")->execute(array_merge($dados, ['id' => $id, 'tenant_id' => $tenantId]));

        $this->redirect('/sla-regras');
    }

    public function toggleStatus(int $id): void {
        Database::getInstance()
            ->prepare("UPDATE bi_sla_regras SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id = ? AND tenant_id = ?")
            ->execute([$id, TenantContext::id()]);
        $this->redirect('/sla-regras');
    }

    public function execucoes(): void {
        $execucoes = (new SlaRegrasRepository())->listarExecucoes((int) TenantContext::id(), 200);
        $this->view('sla_regras/execucoes', ['execucoes' => $execucoes]);
    }

    public function roboConfig(): void {
        $config = Database::getInstance()->query("SELECT * FROM bi_sla_robo_config WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        $this->view('sla_regras/robo', ['config' => $config]);
    }

    public function roboGerarToken(): void {
        $token = bin2hex(random_bytes(24));
        Database::getInstance()->prepare("UPDATE bi_sla_robo_config SET token = ? WHERE id = 1")->execute([$token]);
        $this->redirect('/sla-regras/robo?token_gerado=1');
    }

    public function roboToggle(): void {
        Database::getInstance()
            ->prepare("UPDATE bi_sla_robo_config SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id = 1")
            ->execute();
        $this->redirect('/sla-regras/robo');
    }

    private function dadosDoFormulario(): array {
        $horas   = max(0, (int) ($_POST['limite_horas'] ?? 0));
        $minutos = max(0, (int) ($_POST['limite_minutos_extra'] ?? 0));

        $tipoAcao = $_POST['tipo_acao'] ?? 'menor_carga';
        if (!in_array($tipoAcao, ['aleatorio', 'especifico', 'menor_carga'], true)) $tipoAcao = 'menor_carga';

        $metrica = $_POST['metrica'] ?? 'sla_medico';
        if (!in_array($metrica, ['sla_medico', 'sla_estudo'], true)) $metrica = 'sla_medico';

        $operador = $_POST['operador'] ?? 'maior';
        if (!in_array($operador, ['maior', 'menor'], true)) $operador = 'maior';

        return [
            'nome'                    => trim($_POST['nome'] ?? ''),
            'metrica'                 => $metrica,
            'operador'                => $operador,
            'limite_minutos'          => ($horas * 60) + $minutos,
            'filtro_institution_name' => trim($_POST['filtro_institution_name'] ?? '') ?: null,
            'filtro_modalidade'       => trim($_POST['filtro_modalidade'] ?? '') ?: null,
            'tipo_acao'               => $tipoAcao,
            'medico_especifico_id'    => $tipoAcao === 'especifico' ? (int) ($_POST['medico_especifico_id'] ?? 0) ?: null : null,
            'prioridade'              => max(0, (int) ($_POST['prioridade'] ?? 0)),
            'ativo'                   => isset($_POST['ativo']) ? 1 : 0,
        ];
    }

    private function medicoPertenceAoTenant(?int $tenantId, ?int $medicoId): bool {
        if (!$tenantId || !$medicoId) return false;
        $stmt = Database::getInstance()->prepare("SELECT id FROM bi_medicos WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$medicoId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function listarUnidades(): array {
        return (new EstudosRepository())->getUnidades(TenantContext::id(), false);
    }

    /** Só médicos com conta de login vinculada podem ser alvo de "médico específico". */
    private function listarMedicosVinculaveis(): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND ativo = 1 AND usuario_id IS NOT NULL ORDER BY nome"
        );
        $stmt->execute([TenantContext::id()]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
