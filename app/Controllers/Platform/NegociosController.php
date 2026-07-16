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
                'idioma_padrao' => in_array($_POST['idioma_padrao'] ?? '', \App\Core\Translator::SUPPORTED, true)
                                    ? $_POST['idioma_padrao'] : \App\Core\Translator::FALLBACK,
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
            $negocio = $negocio->fetch(\PDO::FETCH_ASSOC);
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
        // Busca admin principal do tenant para exibir no Perfil/Acesso
        $admin = null;
        try {
            $stmtAdmin = $pdo->prepare("
                SELECT u.id, u.name, u.email
                FROM bi_users u
                JOIN bi_user_tenants ut ON ut.user_id = u.id
                WHERE ut.tenant_id = ? AND ut.role = 'admin' AND ut.ativo = 1
                ORDER BY u.id ASC LIMIT 1
            ");
            $stmtAdmin->execute([$id]);
            $admin = $stmtAdmin->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { $admin = null; }
        $this->view('platform/negocios/form', compact('negocio', 'planos', 'contatos', 'institutionNames', 'admin'), 'platform');
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
                'idioma_padrao' => in_array($_POST['idioma_padrao'] ?? '', \App\Core\Translator::SUPPORTED, true)
                                    ? $_POST['idioma_padrao'] : \App\Core\Translator::FALLBACK,
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

    // ----------------------------------------------------------------
    // UNIDADES DICOM (Grid CRUD) — bi_tenant_unidades_dicom
    // ----------------------------------------------------------------

    public function listarUnidades(int $id): void {
        $pdo = Database::getInstance();
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM bi_tenant_unidades_dicom WHERE tenant_id = ? ORDER BY nome ASC
            ");
            $stmt->execute([$id]);
            $this->json(['success' => true, 'unidades' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            error_log("[NegociosController::listarUnidades] " . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao listar unidades.'], 500);
        }
    }

    public function getUnidade(int $id, int $uid): void {
        $pdo = Database::getInstance();
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM bi_tenant_unidades_dicom WHERE id = ? AND tenant_id = ? LIMIT 1
            ");
            $stmt->execute([$uid, $id]);
            $unidade = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$unidade) {
                $this->json(['success' => false, 'message' => 'Unidade não encontrada.'], 404);
                return;
            }
            $this->json(['success' => true, 'unidade' => $unidade]);
        } catch (\Throwable $e) {
            error_log("[NegociosController::getUnidade] " . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao buscar unidade.'], 500);
        }
    }

    public function criarUnidade(int $id): void {
        $pdo = Database::getInstance();

        $institutionName = trim($_POST['institution_name'] ?? '');
        $nome             = trim($_POST['nome'] ?? '');

        if (empty($nome) || empty($institutionName)) {
            $this->json(['success' => false, 'message' => 'Nome da unidade e InstitutionName são obrigatórios.'], 400);
            return;
        }

        try {
            $tenant = $pdo->prepare("SELECT id FROM bi_tenants WHERE id = ? LIMIT 1");
            $tenant->execute([$id]);
            if (!$tenant->fetchColumn()) {
                $this->json(['success' => false, 'message' => 'Negócio não encontrado.'], 404);
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO bi_tenant_unidades_dicom
                (tenant_id, nome, cnpj, logradouro, numero, complemento, bairro, cidade, uf, cep,
                 institution_name, ae_title, codigo_interno, status, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $nome,
                trim($_POST['cnpj'] ?? '') ?: null,
                trim($_POST['logradouro'] ?? '') ?: null,
                trim($_POST['numero'] ?? '') ?: null,
                trim($_POST['complemento'] ?? '') ?: null,
                trim($_POST['bairro'] ?? '') ?: null,
                trim($_POST['cidade'] ?? '') ?: null,
                trim($_POST['uf'] ?? '') ?: null,
                trim($_POST['cep'] ?? '') ?: null,
                $institutionName,
                trim($_POST['ae_title'] ?? '') ?: null,
                trim($_POST['codigo_interno'] ?? '') ?: null,
                in_array($_POST['status'] ?? 'ativo', ['ativo', 'inativo']) ? $_POST['status'] : 'ativo',
                trim($_POST['observacoes'] ?? '') ?: null,
            ]);

            $this->json(['success' => true, 'message' => 'Unidade criada com sucesso.', 'id' => (int)$pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            error_log("[NegociosController::criarUnidade] " . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'uq_tenant_institution')
                ? 'Este InstitutionName já está cadastrado para este negócio.'
                : 'Erro ao criar unidade.';
            $this->json(['success' => false, 'message' => $msg], 500);
        }
    }

    public function atualizarUnidade(int $id, int $uid): void {
        $pdo = Database::getInstance();

        $institutionName = trim($_POST['institution_name'] ?? '');
        $nome             = trim($_POST['nome'] ?? '');

        if (empty($nome) || empty($institutionName)) {
            $this->json(['success' => false, 'message' => 'Nome da unidade e InstitutionName são obrigatórios.'], 400);
            return;
        }

        try {
            $existe = $pdo->prepare("SELECT id FROM bi_tenant_unidades_dicom WHERE id = ? AND tenant_id = ?");
            $existe->execute([$uid, $id]);
            if (!$existe->fetchColumn()) {
                $this->json(['success' => false, 'message' => 'Unidade não encontrada.'], 404);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE bi_tenant_unidades_dicom
                SET nome=?, cnpj=?, logradouro=?, numero=?, complemento=?, bairro=?, cidade=?, uf=?, cep=?,
                    institution_name=?, ae_title=?, codigo_interno=?, status=?, observacoes=?, updated_at=NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                $nome,
                trim($_POST['cnpj'] ?? '') ?: null,
                trim($_POST['logradouro'] ?? '') ?: null,
                trim($_POST['numero'] ?? '') ?: null,
                trim($_POST['complemento'] ?? '') ?: null,
                trim($_POST['bairro'] ?? '') ?: null,
                trim($_POST['cidade'] ?? '') ?: null,
                trim($_POST['uf'] ?? '') ?: null,
                trim($_POST['cep'] ?? '') ?: null,
                $institutionName,
                trim($_POST['ae_title'] ?? '') ?: null,
                trim($_POST['codigo_interno'] ?? '') ?: null,
                in_array($_POST['status'] ?? 'ativo', ['ativo', 'inativo']) ? $_POST['status'] : 'ativo',
                trim($_POST['observacoes'] ?? '') ?: null,
                $uid,
                $id,
            ]);

            $this->json(['success' => true, 'message' => 'Unidade atualizada com sucesso.']);
        } catch (\Throwable $e) {
            error_log("[NegociosController::atualizarUnidade] " . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'uq_tenant_institution')
                ? 'Este InstitutionName já está cadastrado para este negócio.'
                : 'Erro ao atualizar unidade.';
            $this->json(['success' => false, 'message' => $msg], 500);
        }
    }

    public function excluirUnidade(int $id, int $uid): void {
        $pdo = Database::getInstance();
        try {
            $stmt = $pdo->prepare("DELETE FROM bi_tenant_unidades_dicom WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$uid, $id]);

            if ($stmt->rowCount() === 0) {
                $this->json(['success' => false, 'message' => 'Unidade não encontrada.'], 404);
                return;
            }
            $this->json(['success' => true, 'message' => 'Unidade removida com sucesso.']);
        } catch (\Throwable $e) {
            error_log("[NegociosController::excluirUnidade] " . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao remover unidade.'], 500);
        }
    }
}
