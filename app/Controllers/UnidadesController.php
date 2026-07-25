<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;

/**
 * UnidadesController
 *
 * Fase 3 / Fase 8 — As unidades são derivadas automaticamente das InstitutionNames
 * cadastradas no Negócio (bi_negocio_institution_names). O usuário NÃO pode criar
 * nem renomear unidades — apenas editar dados complementares.
 */
class UnidadesController extends Controller
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // INDEX — lista todas as InstitutionNames do tenant com cadeado
    public function index(): void
    {
        $tenantId = TenantContext::id() ?? Auth::tenantId();

        if (!$tenantId) {
            $_SESSION['error'] = 'Selecione um negócio para visualizar as unidades.';
            header('Location: /selecionar-empresa');
            exit;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT n.*,
                       (SELECT COUNT(*) FROM bi_exames e
                        WHERE e.institution_name = n.institution_name
                          AND e.tenant_id = n.tenant_id) AS total_exames
                FROM bi_negocio_institution_names n
                WHERE n.tenant_id = :tenant_id
                ORDER BY n.institution_name ASC
            ");
            $stmt->execute(['tenant_id' => $tenantId]);
            $unidades = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::index] ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            $unidades = [];
        }

        $this->view('unidades/index', ['unidades' => $unidades]);
    }

    // EDIT — formulário de dados complementares (institution_name é somente leitura)
    public function edit(int $id): void
    {
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $unidade  = $this->findOrFail($id, $tenantId);
        $this->view('unidades/edit', ['unidade' => $unidade]);
    }

    // UPDATE — salva apenas os campos complementares, nunca institution_name
    public function update(int $id): void
    {
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $this->findOrFail($id, $tenantId);

        $campos = [
            'descricao'              => trim($_POST['descricao']              ?? ''),
            'responsavel'            => trim($_POST['responsavel']            ?? ''),
            'cidade'                 => trim($_POST['cidade']                 ?? ''),
            'estado'                 => strtoupper(trim($_POST['estado']      ?? '')),
            'telefone'               => trim($_POST['telefone']               ?? ''),
            'email'                  => trim($_POST['email']                  ?? ''),
            'cnpj'                   => trim($_POST['cnpj']                   ?? ''),
            'horario'                => trim($_POST['horario']                ?? ''),
            'sla_minutos'            => (int)($_POST['sla_minutos'] ?? 0) ?: null,
            'modalidades_permitidas' => trim($_POST['modalidades_permitidas'] ?? ''),
            'observacoes'            => trim($_POST['observacoes']            ?? ''),
            'ativo'                  => isset($_POST['ativo']) ? 1 : 0,
        ];

        try {
            $set = implode(', ', array_map(function($k) { return "`{$k}` = :{$k}"; }, array_keys($campos)));
            $stmt = $this->pdo->prepare("
                UPDATE bi_negocio_institution_names
                SET {$set}
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $campos['id']        = $id;
            $campos['tenant_id'] = $tenantId;
            $stmt->execute($campos);
            Logger::error('[UnidadesController::update] Unidade atualizada', ['id' => $id, 'tenant_id' => $tenantId]);
            $_SESSION['success'] = 'Unidade atualizada com sucesso!';
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::update] ERRO: ' . $e->getMessage(), ['id' => $id]);
            $_SESSION['error'] = 'Erro ao atualizar unidade. Tente novamente.';
        }

        header('Location: /unidades');
        exit;
    }

    // API: retorna unidades do tenant para filtro dependente (JSON)
    public function apiListar(): void
    {
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        header('Content-Type: application/json');
        if (!$tenantId) { echo json_encode([]); exit; }
        try {
            $stmt = $this->pdo->prepare("
                SELECT institution_name AS nome
                FROM bi_negocio_institution_names
                WHERE tenant_id = :tenant_id AND ativo = 1
                ORDER BY institution_name ASC
            ");
            $stmt->execute(['tenant_id' => $tenantId]);
            echo json_encode($stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::apiListar] ' . $e->getMessage());
            echo json_encode([]);
        }
        exit;
    }

    private function findOrFail(int $id, ?int $tenantId): array
    {
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_negocio_institution_names WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
            $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $row = null; }
        if (!$row) {
            $_SESSION['error'] = 'Unidade não encontrada.';
            header('Location: /unidades');
            exit;
        }
        return $row;
    }
}
