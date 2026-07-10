<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\User;

class NegociosController extends Controller {

    public function index(): void {
        $pdo      = Database::getInstance();
        $negocios = [];

        // ── Tenta com JOIN em bi_plans ──────────────────────────────────────
        try {
            $negocios = $pdo->query("
                SELECT t.*, p.nome as plano_nome
                FROM bi_tenants t
                LEFT JOIN bi_plans p ON p.id = t.plan_id
                ORDER BY t.nome ASC
            ")->fetchAll();
        } catch (\Throwable $e) {
            // bi_plans pode não existir ainda — fallback sem JOIN
            error_log("[NegociosController::index] Fallback sem bi_plans: " . $e->getMessage());
            try {
                $negocios = $pdo->query("
                    SELECT *, NULL as plano_nome
                    FROM bi_tenants
                    ORDER BY nome ASC
                ")->fetchAll();
            } catch (\Throwable $e2) {
                error_log("[NegociosController::index] Erro crítico: " . $e2->getMessage());
                $negocios = [];
            }
        }

        $this->view('platform/negocios/index', compact('negocios'), 'platform');
    }

    public function create(): void {
        $planos = [];
        try {
            $planos = (new TenantPlan())->all();
        } catch (\Throwable $e) {
            error_log("[NegociosController::create] Erro ao carregar planos: " . $e->getMessage());
        }
        $this->view('platform/negocios/form', compact('planos'), 'platform');
    }

    public function store(): void {
        try {
            $pdo = Database::getInstance();
            $pdo->beginTransaction();

            // Campos base (sempre existem na tabela original)
            $tenantData = [
                'nome'          => $_POST['nome'] ?? '',
                'slug'          => $_POST['slug'] ?? '',
                'cnpj'          => $_POST['cnpj'] ?? null,
                'email_contato' => $_POST['email_contato'] ?? null,
                'telefone'      => $_POST['telefone'] ?? null,
                'plan_id'       => $_POST['plan_id'] ?? null,
                'status'        => $_POST['status'] ?? 'trial',
                'cor_primaria'  => $_POST['cor_primaria'] ?? '#3b82f6',
            ];

            // Campos opcionais — só inclui se a coluna existir
            $camposOpcionais = [
                'razao_social'        => $_POST['razao_social'] ?? null,
                'nome_fantasia'       => $_POST['nome'] ?? null,
                'inscricao_estadual'  => $_POST['inscricao_estadual'] ?? null,
                'inscricao_municipal' => $_POST['inscricao_municipal'] ?? null,
                'cep'                 => $_POST['cep'] ?? null,
                'logradouro'          => $_POST['logradouro'] ?? null,
                'numero'              => $_POST['numero'] ?? null,
                'complemento'         => $_POST['complemento'] ?? null,
                'bairro'              => $_POST['bairro'] ?? null,
                'cidade'              => $_POST['cidade'] ?? null,
                'estado'              => $_POST['estado'] ?? null,
            ];

            try {
                $colunas = $pdo->query("SHOW COLUMNS FROM bi_tenants")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($camposOpcionais as $campo => $valor) {
                    if (in_array($campo, $colunas)) {
                        $tenantData[$campo] = $valor;
                    }
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::store] SHOW COLUMNS falhou: " . $e->getMessage());
            }

            // Previne duplicidade por slug
            $existing = $pdo->prepare("SELECT id FROM bi_tenants WHERE slug = ? LIMIT 1");
            $existing->execute([$tenantData['slug']]);
            if ($existing->fetch()) {
                $_SESSION['error'] = "Já existe um negócio com este slug: {$tenantData['slug']}";
                $this->redirect('/platform/negocios/create');
                return;
            }

            $tenantId = (new Tenant())->create($tenantData);

            // Contatos (opcional)
            if (!empty($_POST['contatos']) && is_array($_POST['contatos'])) {
                try {
                    $stmtContato = $pdo->prepare("
                        INSERT INTO bi_negocio_contatos
                        (tenant_id, nome, cargo, departamento, email, telefone, celular, whatsapp, principal)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($_POST['contatos'] as $index => $contato) {
                        if (empty($contato['nome'])) continue;
                        $stmtContato->execute([
                            $tenantId,
                            $contato['nome'] ?? '',
                            $contato['cargo'] ?? null,
                            $contato['departamento'] ?? null,
                            $contato['email'] ?? null,
                            $contato['telefone'] ?? null,
                            $contato['celular'] ?? null,
                            $contato['whatsapp'] ?? null,
                            ($index === 0) ? 1 : 0,
                        ]);
                    }
                } catch (\Throwable $e) {
                    error_log("[NegociosController::store] Contatos: " . $e->getMessage());
                }
            }

            // Institution Names (opcional)
            if (!empty($_POST['institution_names'])) {
                try {
                    $names   = array_map('trim', explode(',', $_POST['institution_names']));
                    $stmtInst = $pdo->prepare("
                        INSERT IGNORE INTO bi_negocio_institution_names (tenant_id, institution_name)
                        VALUES (?, ?)
                    ");
                    foreach ($names as $name) {
                        if (!empty($name)) $stmtInst->execute([$tenantId, $name]);
                    }
                } catch (\Throwable $e) {
                    error_log("[NegociosController::store] InstitutionNames: " . $e->getMessage());
                }
            }

            // Usuário admin do negócio (opcional)
            if (!empty($_POST['admin_email'])) {
                try {
                    $senhaHash = password_hash($_POST['admin_senha'] ?? 'Mudar@123', PASSWORD_DEFAULT);
                    $pdo->prepare("
                        INSERT INTO bi_users (tenant_id, name, email, password, role, status)
                        VALUES (?, ?, ?, ?, 'admin', 'ativo')
                    ")->execute([
                        $tenantId,
                        $_POST['admin_nome'] ?? 'Administrador',
                        $_POST['admin_email'],
                        $senhaHash,
                    ]);
                } catch (\Throwable $e) {
                    error_log("[NegociosController::store] Usuário admin: " . $e->getMessage());
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "Negócio criado com sucesso!";
            $this->redirect('/platform/negocios');

        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::store] Erro crítico: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
            $_SESSION['error'] = "Erro ao criar negócio. Verifique os logs.";
            $this->redirect('/platform/negocios/create');
        }
    }

    public function edit(int $id): void {
        $pdo    = Database::getInstance();
        $negocio = null;
        $planos  = [];
        $contatos = [];
        $institutionNames = '';

        try {
            $negocio = $pdo->prepare("SELECT * FROM bi_tenants WHERE id = ? LIMIT 1");
            $negocio->execute([$id]);
            $negocio = $negocio->fetch();
        } catch (\Throwable $e) {
            error_log("[NegociosController::edit] Negócio: " . $e->getMessage());
        }

        if (!$negocio) {
            $_SESSION['error'] = "Negócio não encontrado.";
            $this->redirect('/platform/negocios');
            return;
        }

        try { $planos = (new TenantPlan())->all(); } catch (\Throwable $e) {}

        try {
            $contatos = $pdo->prepare("SELECT * FROM bi_negocio_contatos WHERE tenant_id = ? ORDER BY principal DESC, id ASC");
            $contatos->execute([$id]);
            $contatos = $contatos->fetchAll();
        } catch (\Throwable $e) { $contatos = []; }

        try {
            $rows = $pdo->prepare("SELECT institution_name FROM bi_negocio_institution_names WHERE tenant_id = ?");
            $rows->execute([$id]);
            $institutionNames = implode(', ', array_column($rows->fetchAll(\PDO::FETCH_ASSOC), 'institution_name'));
        } catch (\Throwable $e) { $institutionNames = ''; }

        $this->view('platform/negocios/form', compact('negocio', 'planos', 'contatos', 'institutionNames'), 'platform');
    }

    public function update(int $id): void {
        try {
            $pdo = Database::getInstance();
            $pdo->beginTransaction();

            $tenantData = [
                'nome'          => $_POST['nome'] ?? '',
                'slug'          => $_POST['slug'] ?? '',
                'cnpj'          => $_POST['cnpj'] ?? null,
                'email_contato' => $_POST['email_contato'] ?? null,
                'telefone'      => $_POST['telefone'] ?? null,
                'plan_id'       => $_POST['plan_id'] ?? null,
                'status'        => $_POST['status'] ?? 'ativo',
                'cor_primaria'  => $_POST['cor_primaria'] ?? '#3b82f6',
            ];

            $camposOpcionais = [
                'razao_social'        => $_POST['razao_social'] ?? null,
                'nome_fantasia'       => $_POST['nome'] ?? null,
                'inscricao_estadual'  => $_POST['inscricao_estadual'] ?? null,
                'inscricao_municipal' => $_POST['inscricao_municipal'] ?? null,
                'cep'                 => $_POST['cep'] ?? null,
                'logradouro'          => $_POST['logradouro'] ?? null,
                'numero'              => $_POST['numero'] ?? null,
                'complemento'         => $_POST['complemento'] ?? null,
                'bairro'              => $_POST['bairro'] ?? null,
                'cidade'              => $_POST['cidade'] ?? null,
                'estado'              => $_POST['estado'] ?? null,
            ];

            try {
                $colunas = $pdo->query("SHOW COLUMNS FROM bi_tenants")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($camposOpcionais as $campo => $valor) {
                    if (in_array($campo, $colunas)) $tenantData[$campo] = $valor;
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::update] SHOW COLUMNS: " . $e->getMessage());
            }

            (new Tenant())->update($id, $tenantData);

            // Contatos
            try {
                $pdo->prepare("DELETE FROM bi_negocio_contatos WHERE tenant_id = ?")->execute([$id]);
                if (!empty($_POST['contatos']) && is_array($_POST['contatos'])) {
                    $stmtContato = $pdo->prepare("
                        INSERT INTO bi_negocio_contatos
                        (tenant_id, nome, cargo, departamento, email, telefone, celular, whatsapp, principal)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($_POST['contatos'] as $index => $contato) {
                        if (empty($contato['nome'])) continue;
                        $stmtContato->execute([
                            $id,
                            $contato['nome'] ?? '',
                            $contato['cargo'] ?? null,
                            $contato['departamento'] ?? null,
                            $contato['email'] ?? null,
                            $contato['telefone'] ?? null,
                            $contato['celular'] ?? null,
                            $contato['whatsapp'] ?? null,
                            ($index === 0) ? 1 : 0,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::update] Contatos: " . $e->getMessage());
            }

            // Institution Names
            try {
                $pdo->prepare("DELETE FROM bi_negocio_institution_names WHERE tenant_id = ?")->execute([$id]);
                if (!empty($_POST['institution_names'])) {
                    $names   = array_map('trim', explode(',', $_POST['institution_names']));
                    $stmtInst = $pdo->prepare("
                        INSERT IGNORE INTO bi_negocio_institution_names (tenant_id, institution_name)
                        VALUES (?, ?)
                    ");
                    foreach ($names as $name) {
                        if (!empty($name)) $stmtInst->execute([$id, $name]);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::update] InstitutionNames: " . $e->getMessage());
            }

            $pdo->commit();
            $_SESSION['success'] = "Negócio atualizado com sucesso!";
            $this->redirect('/platform/negocios');

        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::update] Erro crítico: " . $e->getMessage());
            $_SESSION['error'] = "Erro ao atualizar negócio. Verifique os logs.";
            $this->redirect("/platform/negocios/{$id}/edit");
        }
    }

    public function buscarCnpj(string $cnpj): void {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj) !== 14) {
            $this->json(['error' => 'CNPJ inválido'], 400);
            return;
        }

        // 1. ReceitaWS
        $url = "https://www.receitaws.com.br/v1/cnpj/{$cnpj}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!isset($data['status']) || $data['status'] !== 'ERROR') {
                $this->json([
                    'source'       => 'ReceitaWS',
                    'razao_social' => $data['nome'] ?? '',
                    'nome_fantasia'=> $data['fantasia'] ?? '',
                    'cep'          => preg_replace('/[^0-9]/', '', $data['cep'] ?? ''),
                    'logradouro'   => $data['logradouro'] ?? '',
                    'numero'       => $data['numero'] ?? '',
                    'complemento'  => $data['complemento'] ?? '',
                    'bairro'       => $data['bairro'] ?? '',
                    'cidade'       => $data['municipio'] ?? '',
                    'estado'       => $data['uf'] ?? '',
                    'telefone'     => $data['telefone'] ?? '',
                    'email'        => $data['email'] ?? '',
                ]);
                return;
            }
        }

        // 2. BrasilAPI (Fallback)
        $url = "https://brasilapi.com.br/api/cnpj/v1/{$cnpj}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $this->json([
                'source'       => 'BrasilAPI',
                'razao_social' => $data['razao_social'] ?? '',
                'nome_fantasia'=> $data['nome_fantasia'] ?? '',
                'cep'          => preg_replace('/[^0-9]/', '', $data['cep'] ?? ''),
                'logradouro'   => $data['logradouro'] ?? '',
                'numero'       => $data['numero'] ?? '',
                'complemento'  => $data['complemento'] ?? '',
                'bairro'       => $data['bairro'] ?? '',
                'cidade'       => $data['municipio'] ?? '',
                'estado'       => $data['uf'] ?? '',
                'telefone'     => $data['ddd_telefone_1'] ?? '',
                'email'        => '',
            ]);
            return;
        }

        $this->json(['error' => 'CNPJ não encontrado nas APIs'], 404);
    }
}
