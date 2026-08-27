<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\User;
use App\Services\DicomIssuerService;
use App\Services\InstitutionResolverService;

class NegociosController extends Controller {
    use DicomRoutesTrait;

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

            // Campos opcionais — só inclui se a coluna existir. `idioma_padrao`
            // está aqui (e não nos "campos base") porque a migration que a
            // cria (2026-07-15_bi_tenants_idioma.sql) pode não ter sido
            // aplicada em todos os ambientes ainda — sem esse filtro, o INSERT
            // inteiro falha com "Unknown column" e nem o negócio é salvo.
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
                'registro_crm_uf'     => $this->normalizarUfRegistro($_POST['registro_crm_uf'] ?? null),
                'registro_crm_numero' => $this->normalizarNumeroRegistro($_POST['registro_crm_numero'] ?? null),
                'idioma_padrao'       => in_array($_POST['idioma_padrao'] ?? '', \App\Core\Translator::SUPPORTED, true)
                                            ? $_POST['idioma_padrao'] : \App\Core\Translator::FALLBACK,
            ];

            try {
                $colunas = SqlHelper::tableColumns($pdo, 'bi_tenants');
                foreach ($camposOpcionais as $campo => $valor) {
                    if (in_array($campo, $colunas)) {
                        $tenantData[$campo] = $valor;
                    }
                }
                if (!in_array('idioma_padrao', $colunas, true)) {
                    error_log("[NegociosController::store] Coluna 'idioma_padrao' não existe em bi_tenants — rode a migration 2026-07-15_bi_tenants_idioma.sql. Campo ignorado nesta gravação.");
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::store] Introspecção de colunas falhou: " . $e->getMessage());
            }

            // Previne duplicidade por slug
            $existing = $pdo->prepare("SELECT id FROM bi_tenants WHERE slug = ? LIMIT 1");
            $existing->execute([$tenantData['slug']]);
            if ($existing->fetch()) {
                $pdo->rollBack();
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
                    $sqlInst = SqlHelper::isPostgres()
                        ? 'INSERT INTO bi_negocio_institution_names (tenant_id, institution_name)
                           VALUES (?, ?) ON CONFLICT (tenant_id, institution_name) DO NOTHING'
                        : 'INSERT IGNORE INTO bi_negocio_institution_names (tenant_id, institution_name)
                           VALUES (?, ?)';
                    $stmtInst = $pdo->prepare($sqlInst);
                    foreach ($names as $name) {
                        if (!empty($name)) $stmtInst->execute([$tenantId, $name]);
                    }
                } catch (\Throwable $e) {
                    error_log("[NegociosController::store] InstitutionNames: " . $e->getMessage());
                }
            }

            $issuerRules = $this->regrasIssuerModalidadeDaRequisicao();
            $this->sincronizarRegrasIssuerModalidade($pdo, $tenantId, $issuerRules);

            // Usuário admin do negócio — opcional (o formulário só exige o e-mail
            // para tentar criar), mas se informado PRECISA ser criado com sucesso:
            // um negócio sem nenhum admin vinculado é um estado incompleto (o
            // relacionamento Tenant → Admin → Usuário → Perfil não se estabelece),
            // então qualquer falha aqui invalida a transação inteira em vez de ser
            // engolida silenciosamente.
            //
            // Usa o Model canônico (User::create() + attachToTenant()), o mesmo
            // usado pelo restante do sistema (ex: fluxo de login/tenant switch em
            // Auth::login()) — bi_users NÃO tem coluna tenant_id; o vínculo com o
            // tenant vive exclusivamente em bi_user_tenants.
            $adminUserId = null;
            if (!empty($_POST['admin_email'])) {
                $emailExistente = $pdo->prepare("SELECT id FROM bi_users WHERE email = ? LIMIT 1");
                $emailExistente->execute([$_POST['admin_email']]);
                if ($emailExistente->fetch()) {
                    throw new \RuntimeException("Já existe um usuário cadastrado com o e-mail \"{$_POST['admin_email']}\".");
                }

                $userModel = new User();
                $adminUserId = $userModel->create([
                    'name'     => $_POST['admin_nome'] ?: 'Administrador',
                    'email'    => $_POST['admin_email'],
                    'password' => $_POST['admin_senha'] ?: 'Mudar@123',
                    'role'     => 'admin',
                    'status'   => 'ativo',
                ]);
                $userModel->attachToTenant($adminUserId, $tenantId, 'admin');
            }

            $pdo->commit();
            $_SESSION['success'] = "Negócio criado com sucesso!";

            AuditLogger::log('negocio.criar', 'bi_tenants', $tenantId, [
                'nome'             => $tenantData['nome'],
                'slug'             => $tenantData['slug'],
                'admin_criado'     => $adminUserId !== null,
                'regras_issuer_modalidade' => count($issuerRules),
                'admin_email'      => $_POST['admin_email'] ?? null,
                'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'        => 'sucesso',
            ], $tenantId);

            $this->redirect('/platform/negocios');

        } catch (\RuntimeException $e) {
            // Erro de validação de negócio (ex: e-mail duplicado) — mensagem
            // segura para exibir diretamente ao usuário.
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::store] " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();

            AuditLogger::log('negocio.criar.falha', 'bi_tenants', null, [
                'payload'    => $this->payloadParaAuditoria($_POST),
                'erro'       => $e->getMessage(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'  => 'erro',
            ]);

            $this->redirect('/platform/negocios/create');
        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::store] Erro crítico: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
            $_SESSION['error'] = "Erro ao criar negócio. Verifique os logs.";

            AuditLogger::log('negocio.criar.falha', 'bi_tenants', null, [
                'payload'    => $this->payloadParaAuditoria($_POST),
                'erro'       => $e->getMessage(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'  => 'erro',
            ]);

            $this->redirect('/platform/negocios/create');
        }
    }

    /**
     * Remove campos sensíveis (senha) antes de gravar o payload recebido em
     * bi_audit_logs.details — auditoria não pode armazenar senha em texto plano.
     */
    private function payloadParaAuditoria(array $post): array {
        unset($post['admin_senha'], $post['_csrf_token']);
        return $post;
    }

    /** Retorna a UF válida do CRM institucional ou NULL quando o campo é opcional e vazio. */
    private function normalizarUfRegistro(?string $uf): ?string {
        $uf = strtoupper(trim((string) $uf));
        $ufs = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
        return in_array($uf, $ufs, true) ? $uf : null;
    }

    /** Normaliza o número opcional do registro profissional sem aceitar caracteres de controle. */
    /**
     * Sincroniza vínculos N:N informados na aba de servidores. Células exclusivas
     * nunca podem ser vinculadas a outro tenant; o vínculo da própria célula é
     * obrigatório e não pode ser removido pela interface.
     */
    private function sincronizarServidoresVinculados(\PDO $pdo, int $tenantId, mixed $rawIds): void
    {
        if (!is_array($rawIds)) {
            throw new \RuntimeException('Lista de servidores vinculados inválida.');
        }
        $selected = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)));
        $rows = $pdo->query('SELECT s.id, c.tenant_id AS cell_tenant_id FROM bi_pacs_servidor s LEFT JOIN bi_tenant_orthanc_cells c ON c.servidor_id=s.id WHERE s.ativo=1')->fetchAll(\PDO::FETCH_ASSOC);
        $available = [];
        $mandatory = [];
        foreach ($rows as $row) {
            $serverId = (int) $row['id'];
            $cellTenantId = $row['cell_tenant_id'] === null ? null : (int) $row['cell_tenant_id'];
            if ($cellTenantId !== null && $cellTenantId !== $tenantId) {
                continue;
            }
            $available[$serverId] = true;
            if ($cellTenantId === $tenantId) {
                $mandatory[$serverId] = true;
            }
        }
        foreach ($selected as $serverId) {
            if (!isset($available[$serverId])) {
                throw new \RuntimeException('Um servidor selecionado não está disponível para este negócio.');
            }
        }
        $desired = array_values(array_unique(array_merge($selected, array_keys($mandatory))));
        $existing = $pdo->prepare('SELECT servidor_id FROM bi_negocio_servidor_pacs WHERE tenant_id=? AND ativo=1');
        $existing->execute([$tenantId]);
        $existingIds = array_map('intval', $existing->fetchAll(\PDO::FETCH_COLUMN));
        $deactivate = array_values(array_diff($existingIds, $desired));
        if ($deactivate !== []) {
            $marks = implode(',', array_fill(0, count($deactivate), '?'));
            $stmt = $pdo->prepare("UPDATE bi_negocio_servidor_pacs SET ativo=0 WHERE tenant_id=? AND servidor_id IN ($marks)");
            $stmt->execute(array_merge([$tenantId], $deactivate));
        }
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO bi_negocio_servidor_pacs (tenant_id, servidor_id, ativo, criado_por) VALUES (?, ?, 1, ?) ON CONFLICT (tenant_id, servidor_id) DO UPDATE SET ativo=1"
            : "INSERT INTO bi_negocio_servidor_pacs (tenant_id, servidor_id, ativo, criado_por) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE ativo=1";
        $stmt = $pdo->prepare($sql);
        foreach ($desired as $serverId) {
            $stmt->execute([$tenantId, $serverId, \App\Core\Auth::userId()]);
        }
    }

    private function normalizarNumeroRegistro(?string $numero): ?string {
        $numero = trim((string) $numero);
        if ($numero === '') {
            return null;
        }
        $numero = preg_replace('/[^0-9A-Za-z.\\/\\- ]/', '', $numero) ?? '';
        $numero = trim($numero);
        return $numero !== '' ? substr($numero, 0, 30) : null;
    }

    public function edit(int $id): void {
        $pdo    = Database::getInstance();
        $negocio = null;
        $planos  = [];
        $contatos = [];
        $institutionNames = '';
        $issuerModalidadeRules = [];
        $pacsServers = [];

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
            // FETCH_ASSOC explícito: o padrão do PDO neste projeto é FETCH_OBJ
            // (Database.php), mas form.php acessa $c['nome'] com sintaxe de
            // array — sem isso, "Cannot use object of type stdClass as array".
            $contatos = $contatos->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $contatos = []; }

        try {
            $rows = $pdo->prepare("
                SELECT institution_name FROM bi_negocio_institution_names
                WHERE tenant_id = ?
                  AND (excluido_manualmente = 0 OR excluido_manualmente IS NULL)
                ORDER BY institution_name ASC
            ");
            $rows->execute([$id]);
            $institutionNames = implode(', ', array_column($rows->fetchAll(\PDO::FETCH_ASSOC), 'institution_name'));
        } catch (\Throwable $e) { $institutionNames = ''; }
        try {
            $rules = $pdo->prepare("
                SELECT issuer_of_patient_id, issuer_of_patient_id_normalized, modalidade
                FROM bi_tenant_issuer_modalidades
                WHERE tenant_id = ? AND status = 'ativo'
                ORDER BY issuer_of_patient_id, modalidade
            ");
            $rules->execute([$id]);
            foreach ($rules->fetchAll(\PDO::FETCH_ASSOC) as $rule) {
                $key = (string) $rule['issuer_of_patient_id_normalized'];
                $issuerModalidadeRules[$key] ??= ['issuer_of_patient_id' => $rule['issuer_of_patient_id'], 'modalidades' => []];
                $issuerModalidadeRules[$key]['modalidades'][] = $rule['modalidade'];
            }
            $issuerModalidadeRules = array_values($issuerModalidadeRules);
        } catch (\Throwable $e) { $issuerModalidadeRules = []; }
        try {
            $servers = $pdo->prepare("SELECT s.id, s.nome, s.dicom_aet, s.dicom_port, s.status_ping,
                       c.tenant_id AS cell_tenant_id, c.profile AS cell_profile, c.status AS cell_status,
                       EXISTS(SELECT 1 FROM bi_negocio_servidor_pacs nsp WHERE nsp.tenant_id=? AND nsp.servidor_id=s.id AND nsp.ativo=1) AS vinculado
                    FROM bi_pacs_servidor s
                    LEFT JOIN bi_tenant_orthanc_cells c ON c.servidor_id=s.id
                    WHERE s.ativo=1 ORDER BY s.nome");
            $servers->execute([$id]);
            $pacsServers = $servers->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $pacsServers = []; }
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
        $this->view('platform/negocios/form', compact('negocio', 'planos', 'contatos', 'institutionNames', 'issuerModalidadeRules', 'pacsServers', 'admin'), 'platform');
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

            // `idioma_padrao` fica nos campos opcionais (filtrados por
            // SHOW COLUMNS) pelo mesmo motivo do store(): a migration
            // 2026-07-15_bi_tenants_idioma.sql pode não ter rodado ainda em
            // todos os ambientes, e sem esse filtro o UPDATE inteiro falhava
            // com "Unknown column 'idioma_padrao'".
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
                'registro_crm_uf'     => $this->normalizarUfRegistro($_POST['registro_crm_uf'] ?? null),
                'registro_crm_numero' => $this->normalizarNumeroRegistro($_POST['registro_crm_numero'] ?? null),
                'idioma_padrao'       => in_array($_POST['idioma_padrao'] ?? '', \App\Core\Translator::SUPPORTED, true)
                                            ? $_POST['idioma_padrao'] : \App\Core\Translator::FALLBACK,
            ];

            try {
                $colunas = SqlHelper::tableColumns($pdo, 'bi_tenants');
                foreach ($camposOpcionais as $campo => $valor) {
                    if (in_array($campo, $colunas)) $tenantData[$campo] = $valor;
                }
                if (!in_array('idioma_padrao', $colunas, true)) {
                    error_log("[NegociosController::update] Coluna 'idioma_padrao' não existe em bi_tenants — rode a migration 2026-07-15_bi_tenants_idioma.sql. Campo ignorado nesta gravação.");
                }
            } catch (\Throwable $e) {
                error_log("[NegociosController::update] Introspecção de colunas: " . $e->getMessage());
            }

            (new Tenant())->update($id, $tenantData);

            // Administrador Principal — o formulário só exibe os campos de
            // criação quando o negócio ainda não tem admin vinculado (ver
            // form.php, aba Perfil/Acesso). Antes desta correção, update()
            // simplesmente não lia admin_nome/admin_email/admin_senha — os
            // dados eram descartados em silêncio. Mesma regra de store():
            // opcional, mas se informado precisa funcionar ou reportar erro.
            $adminUserId = null;
            if (!empty($_POST['admin_email'])) {
                $existeAdmin = $pdo->prepare("
                    SELECT 1 FROM bi_user_tenants WHERE tenant_id = ? AND role = 'admin' AND ativo = 1 LIMIT 1
                ");
                $existeAdmin->execute([$id]);

                if (!$existeAdmin->fetchColumn()) {
                    $emailExistente = $pdo->prepare("SELECT id FROM bi_users WHERE email = ? LIMIT 1");
                    $emailExistente->execute([$_POST['admin_email']]);
                    if ($emailExistente->fetch()) {
                        throw new \RuntimeException("Já existe um usuário cadastrado com o e-mail \"{$_POST['admin_email']}\".");
                    }

                    $userModel = new User();
                    $adminUserId = $userModel->create([
                        'name'     => $_POST['admin_nome'] ?: 'Administrador',
                        'email'    => $_POST['admin_email'],
                        'password' => $_POST['admin_senha'] ?: 'Mudar@123',
                        'role'     => 'admin',
                        'status'   => 'ativo',
                    ]);
                    $userModel->attachToTenant($adminUserId, $id, 'admin');
                }
            }

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

            // Institution Names — soft-delete com excluido_manualmente=1
            // Isso impede que sincronizarInstitutionNames (UnidadesController)
            // reinsira automaticamente nomes que o operador removeu.
            try {
                $novosNomes = $this->nomesInstituicaoDaRequisicao();

                // 1. Marcar TODOS os nomes atuais do tenant como excluidos_manualmente
                $pdo->prepare("
                    UPDATE bi_negocio_institution_names
                    SET excluido_manualmente = 1, ativo = 0
                    WHERE tenant_id = ?
                ")->execute([$id]);

                // 2. Reativar (ou inserir) cada nome que permanece na lista
                if (!empty($novosNomes)) {
                    $sqlUpsert = SqlHelper::isPostgres()
                        ? "INSERT INTO bi_negocio_institution_names
                               (tenant_id, institution_name, ativo, excluido_manualmente)
                           VALUES (?, ?, 1, 0)
                           ON CONFLICT (tenant_id, institution_name) DO UPDATE SET
                               ativo = EXCLUDED.ativo,
                               excluido_manualmente = EXCLUDED.excluido_manualmente"
                        : "INSERT INTO bi_negocio_institution_names
                               (tenant_id, institution_name, ativo, excluido_manualmente)
                           VALUES (?, ?, 1, 0)
                           ON DUPLICATE KEY UPDATE
                               ativo = VALUES(ativo),
                               excluido_manualmente = VALUES(excluido_manualmente)";
                    $stmtUpsert = $pdo->prepare($sqlUpsert);
                    foreach ($novosNomes as $name) {
                        $stmtUpsert->execute([$id, $name]);
                    }
                }

                // 3. Remover fisicamente os registros excluidos_manualmente
                //    que NAO possuem estudos vinculados (limpeza segura)
                $sqlLimpeza = SqlHelper::isPostgres()
                    ? "DELETE FROM bi_negocio_institution_names n
                         WHERE n.tenant_id = ?
                           AND n.excluido_manualmente = 1
                           AND NOT EXISTS (
                               SELECT 1 FROM bi_pacs_estudos e
                                WHERE e.tenant_id = n.tenant_id
                                  AND LOWER(e.institution_name) = LOWER(n.institution_name)
                           )"
                    : "DELETE n FROM bi_negocio_institution_names n
                         WHERE n.tenant_id = ?
                           AND n.excluido_manualmente = 1
                           AND NOT EXISTS (
                               SELECT 1 FROM bi_pacs_estudos e
                                WHERE e.tenant_id = n.tenant_id
                                  AND e.institution_name COLLATE utf8mb4_general_ci
                                      = n.institution_name COLLATE utf8mb4_general_ci
                           )";
                $pdo->prepare($sqlLimpeza)->execute([$id]);

            } catch (\Throwable $e) {
                error_log("[NegociosController::update] InstitutionNames: " . $e->getMessage());
            }

            $issuerRules = $this->regrasIssuerModalidadeDaRequisicao();
            $this->sincronizarRegrasIssuerModalidade($pdo, $id, $issuerRules);
            if (array_key_exists('servidor_pacs_ids', $_POST)) {
                $this->sincronizarServidoresVinculados($pdo, $id, $_POST['servidor_pacs_ids']);
            }

            $pdo->commit();
            $_SESSION['success'] = "Negócio atualizado com sucesso!";

            AuditLogger::log('negocio.editar', 'bi_tenants', $id, [
                'nome'         => $tenantData['nome'],
                'admin_criado' => $adminUserId !== null,
                'regras_issuer_modalidade' => count($issuerRules),
                'admin_email'  => $_POST['admin_email'] ?? null,
                'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'    => 'sucesso',
            ], $id);

            $this->redirect('/platform/negocios');

        } catch (\RuntimeException $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::update] " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();

            AuditLogger::log('negocio.editar.falha', 'bi_tenants', $id, [
                'payload'    => $this->payloadParaAuditoria($_POST),
                'erro'       => $e->getMessage(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'  => 'erro',
            ], $id);

            $this->redirect("/platform/negocios/{$id}/edit");
        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            error_log("[NegociosController::update] Erro crítico: " . $e->getMessage());
            $_SESSION['error'] = "Erro ao atualizar negócio. Verifique os logs.";

            AuditLogger::log('negocio.editar.falha', 'bi_tenants', $id, [
                'payload'    => $this->payloadParaAuditoria($_POST),
                'erro'       => $e->getMessage(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'resultado'  => 'erro',
            ], $id);

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
        $issuerOfPatientId = DicomIssuerService::sanitizeIssuer($_POST['issuer_of_patient_id'] ?? null);
        $nome = trim($_POST['nome'] ?? '');

        if ($nome === '' || $institutionName === '') {
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
                     institution_name, institution_name_normalized, issuer_of_patient_id, issuer_of_patient_id_normalized,
                     ae_title, codigo_interno, status, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id, $nome, trim($_POST['cnpj'] ?? '') ?: null, trim($_POST['logradouro'] ?? '') ?: null,
                trim($_POST['numero'] ?? '') ?: null, trim($_POST['complemento'] ?? '') ?: null,
                trim($_POST['bairro'] ?? '') ?: null, trim($_POST['cidade'] ?? '') ?: null,
                trim($_POST['uf'] ?? '') ?: null, trim($_POST['cep'] ?? '') ?: null,
                $institutionName, InstitutionResolverService::normalize($institutionName), $issuerOfPatientId,
                DicomIssuerService::normalize($issuerOfPatientId), trim($_POST['ae_title'] ?? '') ?: null,
                trim($_POST['codigo_interno'] ?? '') ?: null,
                in_array($_POST['status'] ?? 'ativo', ['ativo', 'inativo'], true) ? $_POST['status'] : 'ativo',
                trim($_POST['observacoes'] ?? '') ?: null,
            ]);

            $this->json(['success' => true, 'message' => 'Unidade criada com sucesso.', 'id' => (int) $pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            error_log("[NegociosController::criarUnidade] " . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'uq_dicom_unidade_tenant_identity')
                ? 'Este par InstitutionName + Issuer já está cadastrado para este negócio.'
                : 'Erro ao criar unidade.';
            $this->json(['success' => false, 'message' => $msg], 500);
        }
}

public function atualizarUnidade(int $id, int $uid): void {
        $pdo = Database::getInstance();
        $institutionName = trim($_POST['institution_name'] ?? '');
        $issuerOfPatientId = DicomIssuerService::sanitizeIssuer($_POST['issuer_of_patient_id'] ?? null);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '' || $institutionName === '') {
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
                    institution_name=?, institution_name_normalized=?, issuer_of_patient_id=?, issuer_of_patient_id_normalized=?,
                    ae_title=?, codigo_interno=?, status=?, observacoes=?, updated_at=NOW()
                WHERE id=? AND tenant_id=?
            ");
            $stmt->execute([
                $nome, trim($_POST['cnpj'] ?? '') ?: null, trim($_POST['logradouro'] ?? '') ?: null,
                trim($_POST['numero'] ?? '') ?: null, trim($_POST['complemento'] ?? '') ?: null,
                trim($_POST['bairro'] ?? '') ?: null, trim($_POST['cidade'] ?? '') ?: null,
                trim($_POST['uf'] ?? '') ?: null, trim($_POST['cep'] ?? '') ?: null,
                $institutionName, InstitutionResolverService::normalize($institutionName), $issuerOfPatientId,
                DicomIssuerService::normalize($issuerOfPatientId), trim($_POST['ae_title'] ?? '') ?: null,
                trim($_POST['codigo_interno'] ?? '') ?: null,
                in_array($_POST['status'] ?? 'ativo', ['ativo', 'inativo'], true) ? $_POST['status'] : 'ativo',
                trim($_POST['observacoes'] ?? '') ?: null, $uid, $id,
            ]);
            $this->json(['success' => true, 'message' => 'Unidade atualizada com sucesso.']);
        } catch (\Throwable $e) {
            error_log("[NegociosController::atualizarUnidade] " . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'uq_dicom_unidade_tenant_identity')
                ? 'Este par InstitutionName + Issuer já está cadastrado para este negócio.'
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
