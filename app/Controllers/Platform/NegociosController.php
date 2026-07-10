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

    // ============================================================
    // ETAPAS 5 e 6 — Unidades DICOM (Grid CRUD)
    // ============================================================

    public function listarUnidades(int $id): void
    {
        $pdo = Database::getInstance();
        try {
            $stmt = $pdo->prepare("
                SELECT id, nome, cnpj, cidade, uf, institution_name, ae_title, codigo_interno, status, observacoes
                FROM bi_tenant_unidades_dicom
                WHERE tenant_id = ?
                ORDER BY nome
            ");
            $stmt->execute([$id]);
            $this->json($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            error_log("[NegociosController::listarUnidades] " . $e->getMessage());
            $this->json([]);
        }
    }

    public function getUnidade(int $id, int $uid): void
    {
        $pdo = Database::getInstance();
        try {
            $stmt = $pdo->prepare("SELECT * FROM bi_tenant_unidades_dicom WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$uid, $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) { $this->json(['error' => 'Não encontrado'], 404); return; }
            $this->json($row);
        } catch (\Throwable $e) {
            error_log("[NegociosController::getUnidade] " . $e->getMessage());
            $this->json(['error' => 'Erro interno'], 500);
        }
    }

    public function criarUnidade(int $id): void
    {
        $pdo = Database::getInstance();
        try {
            $nome            = trim($_POST['nome'] ?? '');
            $institutionName = trim($_POST['institution_name'] ?? '');

            if (empty($nome) || empty($institutionName)) {
                $this->json(['error' => 'Nome e InstitutionName são obrigatórios.'], 422);
                return;
            }

            // Previne duplicidade de InstitutionName no mesmo tenant
            $dup = $pdo->prepare("SELECT id FROM bi_tenant_unidades_dicom WHERE tenant_id = ? AND institution_name = ? LIMIT 1");
            $dup->execute([$id, $institutionName]);
            if ($dup->fetch()) {
                $this->json(['error' => 'Já existe uma unidade com este InstitutionName neste tenant.'], 409);
                return;
            }

            $pdo->prepare("
                INSERT INTO bi_tenant_unidades_dicom
                    (tenant_id, nome, cnpj, logradouro, numero, complemento, bairro, cidade, uf, cep, institution_name, ae_title, codigo_interno, status, observacoes)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id,
                $nome,
                preg_replace('/[^0-9]/', '', $_POST['cnpj'] ?? '') ?: null,
                $_POST['logradouro']    ?? null,
                $_POST['numero']        ?? null,
                $_POST['complemento']   ?? null,
                $_POST['bairro']        ?? null,
                $_POST['cidade']        ?? null,
                strtoupper($_POST['uf'] ?? '') ?: null,
                preg_replace('/[^0-9]/', '', $_POST['cep'] ?? '') ?: null,
                $institutionName,
                strtoupper(trim($_POST['ae_title'] ?? '')) ?: null,
                $_POST['codigo_interno'] ?? null,
                in_array($_POST['status'] ?? '', ['ativo','inativo']) ? $_POST['status'] : 'ativo',
                $_POST['observacoes']   ?? null,
            ]);

            $newId = (int) $pdo->lastInsertId();
            error_log("[NegociosController::criarUnidade] Unidade #{$newId} criada para tenant_id={$id}");
            $this->json(['success' => true, 'id' => $newId]);

        } catch (\Throwable $e) {
            error_log("[NegociosController::criarUnidade] " . $e->getMessage());
            $this->json(['error' => 'Erro ao criar unidade: ' . $e->getMessage()], 500);
        }
    }

    public function atualizarUnidade(int $id, int $uid): void
    {
        $pdo = Database::getInstance();
        try {
            $nome            = trim($_POST['nome'] ?? '');
            $institutionName = trim($_POST['institution_name'] ?? '');

            if (empty($nome) || empty($institutionName)) {
                $this->json(['error' => 'Nome e InstitutionName são obrigatórios.'], 422);
                return;
            }

            $dup = $pdo->prepare("SELECT id FROM bi_tenant_unidades_dicom WHERE tenant_id = ? AND institution_name = ? AND id != ? LIMIT 1");
            $dup->execute([$id, $institutionName, $uid]);
            if ($dup->fetch()) {
                $this->json(['error' => 'Já existe outra unidade com este InstitutionName neste tenant.'], 409);
                return;
            }

            $pdo->prepare("
                UPDATE bi_tenant_unidades_dicom
                SET nome = ?, cnpj = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?,
                    cidade = ?, uf = ?, cep = ?, institution_name = ?, ae_title = ?,
                    codigo_interno = ?, status = ?, observacoes = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ")->execute([
                $nome,
                preg_replace('/[^0-9]/', '', $_POST['cnpj'] ?? '') ?: null,
                $_POST['logradouro']    ?? null,
                $_POST['numero']        ?? null,
                $_POST['complemento']   ?? null,
                $_POST['bairro']        ?? null,
                $_POST['cidade']        ?? null,
                strtoupper($_POST['uf'] ?? '') ?: null,
                preg_replace('/[^0-9]/', '', $_POST['cep'] ?? '') ?: null,
                $institutionName,
                strtoupper(trim($_POST['ae_title'] ?? '')) ?: null,
                $_POST['codigo_interno'] ?? null,
                in_array($_POST['status'] ?? '', ['ativo','inativo']) ? $_POST['status'] : 'ativo',
                $_POST['observacoes']   ?? null,
                $uid,
                $id,
            ]);

            error_log("[NegociosController::atualizarUnidade] Unidade #{$uid} atualizada para tenant_id={$id}");
            $this->json(['success' => true]);

        } catch (\Throwable $e) {
            error_log("[NegociosController::atualizarUnidade] " . $e->getMessage());
            $this->json(['error' => 'Erro ao atualizar unidade: ' . $e->getMessage()], 500);
        }
    }

    public function excluirUnidade(int $id, int $uid): void
    {
        $pdo = Database::getInstance();
        try {
            $pdo->prepare("DELETE FROM bi_tenant_unidades_dicom WHERE id = ? AND tenant_id = ?")
                ->execute([$uid, $id]);
            error_log("[NegociosController::excluirUnidade] Unidade #{$uid} excluída para tenant_id={$id}");
            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            error_log("[NegociosController::excluirUnidade] " . $e->getMessage());
            $this->json(['error' => 'Erro ao excluir unidade'], 500);
        }
    }

    // ============================================================
    // ETAPA 3 — Upload de Logo isolado por Tenant
    // ============================================================

    public function uploadLogo(int $id): void
    {
        $pdo = Database::getInstance();
        try {
            if (($_POST['remove_logo'] ?? '0') === '1') {
                $row = $pdo->prepare("SELECT logo_path FROM bi_tenants WHERE id = ? LIMIT 1");
                $row->execute([$id]);
                $tenant = $row->fetch(\PDO::FETCH_ASSOC);
                if (!empty($tenant['logo_path'])) {
                    $oldFile = __DIR__ . '/../../../storage/tenants/' . $id . '/logo/' . $tenant['logo_path'];
                    if (file_exists($oldFile)) @unlink($oldFile);
                }
                $pdo->prepare("UPDATE bi_tenants SET logo_path = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
                $this->json(['success' => true, 'removed' => true]);
                return;
            }

            if (empty($_FILES['logo_file']['tmp_name'])) {
                $this->json(['error' => 'Nenhum arquivo enviado.'], 422);
                return;
            }

            $file    = $_FILES['logo_file'];
            $maxSize = 2 * 1024 * 1024;
            $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            $extMap  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

            if ($file['size'] > $maxSize) {
                $this->json(['error' => 'Arquivo muito grande. Máximo: 2MB.'], 422);
                return;
            }

            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeReal = $finfo->file($file['tmp_name']);
            if (!in_array($mimeReal, $allowed)) {
                $this->json(['error' => 'Formato não permitido. Use PNG, JPG, WEBP ou SVG.'], 422);
                return;
            }

            $ext = $extMap[$mimeReal];
            $dir = __DIR__ . '/../../../storage/tenants/' . $id . '/logo/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $row = $pdo->prepare("SELECT logo_path FROM bi_tenants WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $tenant = $row->fetch(\PDO::FETCH_ASSOC);
            if (!empty($tenant['logo_path'])) {
                $oldFile = $dir . $tenant['logo_path'];
                if (file_exists($oldFile)) @unlink($oldFile);
            }

            $filename = 'logo_' . $id . '_' . time() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dir . $filename);

            $pdo->prepare("UPDATE bi_tenants SET logo_path = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$filename, $id]);

            error_log("[NegociosController::uploadLogo] Logo salva para tenant_id={$id}: {$filename}");
            $this->json(['success' => true, 'filename' => $filename]);

        } catch (\Throwable $e) {
            error_log("[NegociosController::uploadLogo] " . $e->getMessage());
            $this->json(['error' => 'Erro ao fazer upload da logo.'], 500);
        }
    }

    // ============================================================
    // ETAPA 4 — Token de Acesso para Admin
    // ============================================================

    public function enviarTokenAcesso(int $id): void
    {
        $pdo = Database::getInstance();
        try {
            $adminEmail = trim($_POST['admin_email'] ?? '');
            $adminNome  = trim($_POST['admin_nome']  ?? 'Administrador');

            if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $this->json(['error' => 'E-mail inválido.'], 422);
                return;
            }

            $existUser = $pdo->prepare("SELECT id FROM bi_users WHERE email = ? LIMIT 1");
            $existUser->execute([$adminEmail]);
            $user = $existUser->fetch(\PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            if (!$user) {
                $pdo->prepare("
                    INSERT INTO bi_users (name, email, password, role, status, created_at)
                    VALUES (?, ?, '', 'admin', 'inativo', NOW())
                ")->execute([$adminNome, $adminEmail]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT IGNORE INTO bi_user_tenants (user_id, tenant_id, role, ativo)
                    VALUES (?, ?, 'admin', 1)
                ")->execute([$userId, $id]);
            } else {
                $userId = (int) $user['id'];
            }

            // Invalida tokens anteriores
            $pdo->prepare("UPDATE bi_tenant_access_tokens SET usado = 1 WHERE user_id = ? AND tenant_id = ? AND usado = 0")
                ->execute([$userId, $id]);

            // Gera token criptográfico seguro
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $pdo->prepare("
                INSERT INTO bi_tenant_access_tokens (user_id, tenant_id, token, tipo, usado, expires_at)
                VALUES (?, ?, ?, 'criar_senha', 0, ?)
            ")->execute([$userId, $id, $token, $expiresAt]);

            $pdo->commit();

            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $link    = $baseUrl . '/acesso/criar-senha/' . $token;

            error_log("[NegociosController::enviarTokenAcesso] Token gerado user_id={$userId} email={$adminEmail} tenant_id={$id} expires={$expiresAt}");

            $this->json([
                'success' => true,
                'message' => 'Token gerado com sucesso.',
                'link'    => $link,
                'expires' => $expiresAt,
            ]);

        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::enviarTokenAcesso] " . $e->getMessage());
            $this->json(['error' => 'Erro ao gerar token de acesso.'], 500);
        }
    }
}
