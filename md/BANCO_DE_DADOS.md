# Dicionário de Dados — VOXEL PACS/BI

**Base:** leitura integral dos 15 arquivos `.sql` em `database/migrations/` e dos 2 arquivos em `database/seeds/`. Banco: MySQL/MariaDB (`config/database.php`), charset `utf8mb4`.

> Complementa `md/MANUAL_TECNICO.md`. Leia o aviso geral abaixo **antes** de tratar este documento como fonte de verdade absoluta.

---

## ⚠️ Aviso geral — como este schema foi construído

O projeto **não usa uma ferramenta formal de migration** (tipo Laravel/Phinx). Os arquivos em `database/migrations/` são scripts soltos, pensados para rodar manualmente via phpMyAdmin em hospedagem compartilhada (MySQL 5.7). Consequências diretas para quem for usar este dicionário:

- Existem **3 migrations concorrentes** para o módulo Negócios em 2026-05-11 (`negocios_colunas_individuais`, `negocios_module`, `negocios_phpmyadmin`) — são tentativas alternativas do mesmo deploy, não passos sequenciais.
- Existem **2 versões conflitantes da tabela `reports`** (2026-07-04 vs 2026-07-05), com colunas e nomes de FK diferentes (`bi_pacs_estudos_id` vs `estudo_id`, `status` vs `situacao`, `conteudo JSON` vs colunas `secao_*` separadas). Ambas usam `CREATE TABLE IF NOT EXISTS`, então **só a que rodou primeiro em produção prevalece** — o schema real depende da ordem de execução manual, não do controle de versão. **Antes de qualquer alteração nesta tabela, rode `SHOW CREATE TABLE reports;` em produção.**
- A migration `2026-07-05_bi_pacs_estudos_worklist_indices.sql` cria índices sobre `assumido_por`, `laudo_assinado_em` e `urgente_em`, mas **nenhuma migration do repositório cria essas colunas** — o script provavelmente falha se executado isoladamente, a menos que tenham sido criadas manualmente em produção.
- O seed `database/seeds/001_superadmin.sql` é **incompatível com o schema atual** (`bi_plans` não tem colunas `descricao`/`recursos_json`; `bi_users` usa `name`, não `nome`). Use `001_superadmin_pacs.sql`, que é compatível.
- Há **dois modelos paralelos de arquitetura PACS** no histórico: (i) "1 Orthanc por tenant" (`bi_orthanc_servidores` + `bi_orthanc_sync_log`, migrations de 05-11/05-12, parece legado) e (ii) "1 Orthanc global + roteamento por tenant" (`bi_pacs_servidor` + `bi_pacs_roteamento` + `bi_pacs_estudos`, migrations de 05-26 em diante — **este é o modelo ativo**, confirmado pelo código do `ServidorPacsController`).

Trate `database/migrations/` como **histórico de scripts de deploy**, não como fonte de verdade absoluta do schema em produção.

---

## 1. Migrations em ordem cronológica

| # | Arquivo | Tabela(s) afetada(s) | Propósito |
|---|---|---|---|
| 1 | `2026-01-01_bi_multitenant_schema.sql` | `bi_plans`, `bi_tenants`, `bi_users`, `bi_user_tenants`, `bi_pacs_conexoes`, `bi_layouts_importacao`, `bi_unidades`, `bi_modalidades`, `bi_medicos`, `bi_importacoes`, `bi_exames`, `bi_kpi_snapshots`, `bi_audit_logs`, `bi_configuracoes` | Schema inicial completo multi-tenant + seed de planos/modalidades/superadmin |
| 2 | `2026-05-11_negocios_colunas_individuais.sql` | `bi_tenants` | Colunas cadastrais (razão social, CNPJ, endereço...) — versão "uma coluna por vez" |
| 3 | `2026-05-11_negocios_module.sql` | `bi_tenants`, `bi_negocio_contatos`, `bi_negocio_institution_names`, `bi_negocio_plano_historico`, `bi_orthanc_servidores`, `bi_orthanc_sync_log` | Versão "completa" do módulo Negócios, com PROCEDURE auxiliar `voxel_add_column` |
| 4 | `2026-05-11_negocios_phpmyadmin.sql` | idem #3 | Mesma finalidade, reescrita como script único para colar direto no phpMyAdmin |
| 5 | `2026-05-11_producao_fix.sql` | `bi_plans`, `bi_tenants`, `bi_negocio_contatos`, `bi_negocio_institution_names` | Script de "correção de produção" — subconjunto reduzido |
| 6 | `2026-05-12_orthanc_colunas.sql` | `bi_orthanc_servidores` | Colunas extras idempotentes (`ultimo_ping`, `status_ping`, `versao`...) |
| 7 | `2026-05-26_pacs_estudos_dicom_tags.sql` | `bi_pacs_estudos` | **DROP + CREATE** com dezenas de tags DICOM completas — roda **depois** de `servidor_pacs.sql` apesar do nome alfabético vir antes |
| 8 | `2026-05-26_servidor_pacs.sql` | `bi_pacs_servidor`, `bi_pacs_roteamento`, `bi_pacs_estudos` (versão simples), `bi_pacs_sync_log` | Cria o módulo de Servidor PACS/Orthanc global (modelo ativo) |
| 9 | `2026-07-02_bi_pacs_estudos_worklist.sql` | `bi_pacs_estudos` | Campos de worklist: `situacao`, `especialidade`, `orthanc_url` |
| 10 | `2026-07-02_pacs_sync_agendado.sql` | `bi_pacs_servidor`, `bi_pacs_sync_execucoes` | Colunas/tabela para sincronização automática via cron externo (**funcionalidade hoje removida do código**, ver `md/MANUAL_TECNICO.md` §4.1) |
| 11 | `2026-07-02_pacs_viewer_tokens.sql` | `pacs_viewer_tokens` | Tokens temporários (1h) para abertura segura do OHIF |
| 12 | `2026-07-04_bi_reports_module.sql` | `bi_pacs_estudos`, `bi_users`, `reports`, `report_versions`, `report_templates`, `report_autotext`, `report_signatures` | 1ª versão do módulo de Laudos — `conteudo` em JSON único |
| 13 | `2026-07-05_bi_pacs_estudos_indices.sql` | `bi_pacs_estudos` | Colunas `prioridade`, `medico_responsavel`, `sincronizado_em` + índices |
| 14 | `2026-07-05_bi_pacs_estudos_worklist_indices.sql` | `bi_pacs_estudos` | 7 índices adicionais — referencia colunas não criadas em nenhuma migration do repo |
| 15 | `2026-07-05_reports_module.sql` | `reports`, `report_versions`, `report_templates`, `report_logs`, `report_autotext`, `report_favorites`, `report_signatures` | **2ª versão, incompatível com a #12**, do módulo de Laudos — colunas de texto separadas em vez de JSON |

---

## 2. Dicionário de dados — tabelas principais

### 2.1 `bi_tenants` — multi-tenancy (clientes/empresas da plataforma)

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `nome` | VARCHAR(255) NOT NULL | |
| `slug` | VARCHAR(100) UNIQUE | Subdomínio/identificador de URL |
| `plan_id` | INT UNSIGNED, FK → `bi_plans.id` | |
| `status` | ENUM('ativo','suspenso','cancelado','trial') | |
| `trial_expira_em` | DATE NULL | |
| `configuracoes_json` | JSON NULL | |
| `erp_api_url` / `erp_api_token` | VARCHAR(500) NULL | Integração ERP do cliente |
| `cor_primaria`, `logo_url` | VARCHAR | White-label |
| `cnpj`, `razao_social`, `nome_fantasia`, `inscricao_estadual/municipal`, `cep`, `logradouro`, `numero`, `complemento`, `bairro`, `cidade`, `estado`, `pais`, `site`, `descricao`, `porte`, `natureza_juridica`, `cnae_principal/descricao`, `data_abertura`, `situacao_cadastral`, `observacoes` | vários | Dados cadastrais, adicionados via migrations do módulo Negócios (05-11) |
| `created_at`/`updated_at` | TIMESTAMP | |
| **Índices** | `idx_slug`, `idx_status` | |
| **FK** | `fk_tenant_plan` → `bi_plans(id)` | |

### 2.2 `bi_plans` — planos comerciais

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `nome` | VARCHAR(100) | |
| `slug` | VARCHAR(50) UNIQUE | |
| `max_usuarios`, `max_pacs`, `max_exames_mes` | INT | Limites do plano |
| `permite_benchmark`, `permite_preditivo`, `permite_api` | TINYINT(1) | Feature flags (lidas por `TenantContext::allows()`) |
| `preco_mensal` | DECIMAL(10,2) | |
| `ativo` | TINYINT(1) DEFAULT 1 | |

> ⚠️ Seed `001_superadmin.sql` insere com colunas `descricao`/`recursos_json` que **não existem** aqui — seed incompatível, não usar.

### 2.3 `bi_users` — usuários da plataforma

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `name` | VARCHAR(255) | |
| `email` | VARCHAR(255) UNIQUE | |
| `password` | VARCHAR(255) | Hash — **inconsistente entre pontos de criação**, ver `md/MANUAL_TECNICO.md` §15.5 |
| `role` | ENUM('superadmin','admin','analista','viewer') | Papel global |
| `crm` | VARCHAR(30) NULL | Usado na assinatura de laudos |
| `status` | ENUM('ativo','inativo') | |
| `ultimo_login` | DATETIME NULL | |
| **Índice** | `idx_email` | |

> Sem FK direta para `bi_tenants` — vínculo via `bi_user_tenants` (usuário pode pertencer a múltiplos tenants).

### 2.4 `bi_user_tenants` — associação usuário × tenant (RBAC por tenant)

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | PK AI | |
| `user_id` | FK → `bi_users.id` ON DELETE CASCADE | |
| `tenant_id` | FK → `bi_tenants.id` ON DELETE CASCADE | |
| `role` | ENUM('admin','analista','viewer') | Papel **dentro do tenant** (distinto do `role` global) |
| `ativo` | TINYINT(1) | |
| **Chave única** | `uq_user_tenant` (user_id, tenant_id) | |

### 2.5 `bi_pacs_servidor` — servidor Orthanc único e global

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | PK AI (fixo = 1 na prática) | |
| `nome` | VARCHAR(255) DEFAULT 'Orthanc Principal' | |
| `url` | VARCHAR(500) | Endpoint REST do Orthanc |
| `usuario`/`senha` | VARCHAR NULL | HTTP Basic Auth — **senha em texto puro** |
| `timeout` | INT DEFAULT 30 | |
| `dicom_aet` / `dicom_port` | VARCHAR(64) / INT | |
| `status_ping` | ENUM('online','offline','erro','nunca_testado') | |
| `total_estudos`, `total_pacientes`, `total_series`, `total_instancias`, `disk_size_mb` | contadores de saúde do PACS | |
| `sync_auto_ativo`, `sync_intervalo_minutos`, `sync_cron_token`, `sync_ultima_execucao` | colunas do cron externo | ⚠️ **funcionalidade removida do código atual** — colunas existem no banco mas não são mais lidas/escritas por nenhum Controller |

> Sem `tenant_id` — tabela **global**. Um único Orthanc atende todos os tenants; o roteamento por tenant é feito via `bi_pacs_roteamento`/`institution_name`.

### 2.6 `bi_pacs_estudos` — cache de estudos DICOM (**tabela mais crítica do sistema**)

Versão final após `2026-05-26_pacs_estudos_dicom_tags.sql` (DROP+CREATE) + migrations sucessivas de 07-02/07-04/07-05.

| Grupo | Colunas (exemplos) |
|---|---|
| Controle interno | `id` PK, `servidor_id` FK→`bi_pacs_servidor.id` (CASCADE), `tenant_id` (FK implícita, NULL = não roteado), `orthanc_id` UNIQUE, `importado_em`, `atualizado_em` |
| Patient | `patient_id`, `patient_name`, `patient_name_display`, `patient_birth_date`, `patient_sex`, `patient_age`, `patient_weight/size`, campos veterinários |
| Study | `study_instance_uid`, `study_date`, `study_time`, `study_description`, `accession_number`, `referring_physician_name` |
| Equipamento/Instituição | `institution_name` (**chave de roteamento por tenant**), `station_name`, `manufacturer`, `manufacturer_model_name`, `device_serial_number` |
| Série/imagem | `modalities`, `num_series`, `num_instances`, `body_part_examined`, `pixel_spacing`, `rows`, `columns` |
| Técnico por modalidade | dezenas de colunas CT/MR/US/NM-PET/dose (`kvp`, `slice_thickness`, `repetition_time`, `echo_time`, `magnetic_field_strength`, `ctdi_vol`...) |
| Workflow/worklist | `situacao` ENUM('novo','aberto','rascunho','em_laudo','revisao','assinado','liberado','urgente'), `especialidade`, `prioridade`, `medico_responsavel`, `usuario_responsavel_id`, `sincronizado_em` |
| Orthanc metadata | `is_stable`, `last_update_orthanc`, `tags_raw` JSON |

**PK:** `id`. **Chave única:** `uq_orthanc_id`. **FK explícita:** `servidor_id → bi_pacs_servidor(id)` CASCADE. **FK implícita:** `tenant_id → bi_tenants.id`.

**Índices acumulados (~30):** `idx_servidor`, `idx_tenant`, `idx_institution`, `idx_study_date`, `idx_patient_id/name`, `idx_accession`, `idx_study_uid(64)`, `idx_modalidades`, `idx_body_part`, `idx_tenant_date`, `idx_tenant_modality`, `idx_tenant_institution`, `idx_situacao`, `idx_usuario_responsavel`, `idx_bpe_*` (07-05), `idx_worklist_main (tenant_id, study_date, situacao)`, `idx_tenant_prioridade`, `idx_tenant_especialidade`, `idx_servidor_tenant_date` — **válidos**; e `idx_assumido_por`, `idx_laudo_assinado_em`, `idx_urgente_em` — **sobre colunas não encontradas em nenhuma migration do repositório** (confirmar em produção com `SHOW INDEX FROM bi_pacs_estudos;` se existem de fato).

> Conforme `md/MODULO_ESTUDOS.md`: **todos os módulos consultam o PACS exclusivamente através desta tabela**, nunca diretamente no Orthanc.

### 2.7 `bi_pacs_roteamento` — InstitutionName/AETitle → tenant

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | PK AI | |
| `servidor_id` | FK → `bi_pacs_servidor.id` CASCADE | |
| `tenant_id` | FK → `bi_tenants.id` CASCADE | |
| `institution_name` | VARCHAR(255) | Valor DICOM (0008,0080) |
| `aetitle` | VARCHAR(64) NULL | |
| `ativo` | TINYINT(1) | |
| **Chave única** | `uq_servidor_institution` (servidor_id, institution_name) | |

> Peça central da multi-tenancy no domínio PACS — define a que tenant um estudo recebido do Orthanc pertence. Erro aqui = vazamento de dados clínicos entre clínicas.

### 2.8 `bi_pacs_sync_log` / `bi_pacs_sync_execucoes`

- **`bi_pacs_sync_log`**: log da sincronização manual completa (`iniciado_em`, `finalizado_em`, `status`, `estudos_novos/atualizados/roteados/erros`).
- **`bi_pacs_sync_execucoes`**: histórico do ping automático agendado via cron externo — **tabela criada mas não mais alimentada** (funcionalidade removida do código, ver aviso geral).

### 2.9 `pacs_viewer_tokens` — tokens de acesso ao OHIF Viewer

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | PK AI | |
| `token` | VARCHAR(64) UNIQUE | Token de uso único (verificar entropia do gerador em `EstudosController::abrir()`) |
| `estudo_id`, `tenant_id`, `usuario_id` | INT NULL | Referências **implícitas**, sem FK declarada |
| `study_instance_uid`, `orthanc_id` | VARCHAR | |
| `usado` | TINYINT(1) | Não invalida após uso — permite múltiplos acessos dentro da validade |
| `usos` | INT | Contador |
| `expires_at` | DATETIME | Validade padrão 1h |

### 2.10 `bi_audit_logs` — auditoria

| Coluna | Tipo | Comentário |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `tenant_id`, `user_id` | INT NULL, sem FK | Desenhada de propósito "solta" — permite registrar eventos mesmo após remoção do tenant/usuário |
| `action`, `entity`, `entity_id` | | |
| `details` | JSON NULL | |
| `ip` | VARCHAR(45) NULL | |

> Uso real hoje: só `TenantsController::suspend()`/`::impersonate()` gravam aqui (ver `md/MANUAL_TECNICO.md` §12).

### 2.11 `bi_importacoes` e `bi_exames` — pipeline "BI legado" (paralelo a `bi_pacs_estudos`)

- **`bi_importacoes`**: registro de cada processamento de planilha (`total_registros/importados/duplicados/erros`, `status`).
- **`bi_exames`**: dado de exame importado via planilha/API — `hash_dedup` (SHA-256, UNIQUE) para deduplicação, campos financeiros (`valor_exame`, `valor_venda`), SLA (`sla_minutos`, `sla_status`). **Não tem FK para `bi_pacs_estudos`** — são dois pipelines de dados paralelos e desconectados.

### 2.12 `reports` — laudos (⚠️ duas versões conflitantes)

**Versão A** (`2026-07-04`): `bi_pacs_estudos_id` (FK explícita CASCADE), `medico_id`, `status` ENUM('em_laudo','rascunho','revisao','assinado','liberado'), `conteudo` JSON único, `versao_atual`. Chave única `uq_estudo`.

**Versão B** (`2026-07-05`, redefine a mesma tabela): `tenant_id`, `estudo_id` (sem FK declarada), `usuario_id`, `situacao` ENUM('rascunho','assinado','liberado') — enum menor, `secao_exame/tecnica/achados/conclusao/recomendacao` (TEXT separados, não JSON), `bloqueado_por/em`, `assinado_por/em`, `assinatura_hash/crm`, `liberado_por/em`. Chave única `uq_estudo_report`.

**Tabelas satélite (versão B):** `report_versions` (histórico imutável, FK → `reports.id` CASCADE), `report_templates` (por modalidade, `tenant_id NULL` = global), `report_logs` (auditoria do módulo), `report_autotext` (autocomplete por gatilho), `report_favorites`, `report_signatures` (hash SHA-256, FK → `reports.id` CASCADE).

> **Ação obrigatória antes de mexer nesta tabela:** rodar `SHOW CREATE TABLE reports;` em produção para saber qual versão está ativa. Note também que o Controller/Service/Repository de Reports estão hoje com erro fatal de parse (`md/MANUAL_TECNICO.md` §14.1) — a resolução desse bug provavelmente vai exigir decidir qual dessas duas versões de schema prevalece.

### 2.13 `bi_orthanc_servidores` / `bi_orthanc_sync_log` — modelo legado "1 Orthanc por tenant"

Coexiste no schema com o modelo ativo (`bi_pacs_servidor` + roteamento). `bi_orthanc_servidores` tem `tenant_id` FK CASCADE — cada tenant teria seu próprio Orthanc. Parece ser uma abordagem anterior do produto; confirmar com o time se ainda há algum tenant usando esse modelo antes de removê-lo.

### 2.14 Tabelas auxiliares de negócio (`bi_negocio_*`)

| Tabela | Observação |
|---|---|
| `bi_negocio_contatos` | Múltiplos contatos por tenant, FK CASCADE, flag `principal` |
| `bi_negocio_institution_names` | Nomes DICOM `InstitutionName` reconhecidos por tenant — associação **alternativa** a `bi_pacs_roteamento` (confirmar qual das duas é a usada de fato pelo fluxo de sync) |
| `bi_negocio_plano_historico` | Histórico de contratação/upgrade/downgrade/cancelamento de plano |

---

## 3. Relacionamentos entre tabelas

### FKs explícitas (`CONSTRAINT ... FOREIGN KEY`)

| Tabela filha | Coluna | Tabela pai | On Delete |
|---|---|---|---|
| `bi_tenants` | `plan_id` | `bi_plans(id)` | — |
| `bi_user_tenants` | `user_id` / `tenant_id` | `bi_users(id)` / `bi_tenants(id)` | CASCADE |
| `bi_pacs_conexoes` | `tenant_id` | `bi_tenants(id)` | CASCADE |
| `bi_negocio_contatos` / `bi_negocio_institution_names` / `bi_negocio_plano_historico` | `tenant_id` | `bi_tenants(id)` | CASCADE |
| `bi_negocio_plano_historico` | `plan_id` | `bi_plans(id)` | — |
| `bi_orthanc_servidores` | `tenant_id` | `bi_tenants(id)` | CASCADE |
| `bi_orthanc_sync_log` | `servidor_id` | `bi_orthanc_servidores(id)` | CASCADE |
| `bi_pacs_roteamento` | `servidor_id` / `tenant_id` | `bi_pacs_servidor(id)` / `bi_tenants(id)` | CASCADE |
| `bi_pacs_estudos` | `servidor_id` | `bi_pacs_servidor(id)` | CASCADE |
| `bi_pacs_sync_log` / `bi_pacs_sync_execucoes` | `servidor_id` | `bi_pacs_servidor(id)` | CASCADE |
| `reports` (versão A) | `bi_pacs_estudos_id` | `bi_pacs_estudos(id)` | CASCADE |
| `report_versions` / `report_signatures` | `report_id` | `reports(id)` | CASCADE |

### FKs implícitas (mesmo padrão de nome, **sem `CONSTRAINT`** — não garantidas pelo banco)

| Coluna | Aparece em | Aponta para |
|---|---|---|
| `tenant_id` | `bi_pacs_estudos`, `pacs_viewer_tokens`, `bi_audit_logs`, `bi_importacoes`, `bi_exames`, `bi_unidades`, `bi_modalidades`, `bi_medicos`, `reports` (ambas versões), etc. | `bi_tenants.id` |
| `user_id`/`usuario_id` | `bi_audit_logs`, `bi_importacoes`, `report_*`, `pacs_viewer_tokens.usuario_id` | `bi_users.id` |
| `estudo_id` | `pacs_viewer_tokens`, `reports` (versão B), `report_logs` | `bi_pacs_estudos.id` |
| `institution_name` | `bi_pacs_estudos`, `bi_pacs_roteamento`, `bi_negocio_institution_names` | Não é FK — é a **chave de negócio** para roteamento DICOM → tenant |

**Atenção:** como a maioria dos vínculos entre tabelas de domínios diferentes é implícita (sem `CONSTRAINT`), o banco **não impede** inconsistência (ex.: apagar um tenant não bloqueia nem propaga automaticamente para `bi_pacs_estudos.tenant_id`). Qualquer rotina de exclusão de tenant precisa tratar isso manualmente na aplicação.

---

## 4. Classificação das tabelas por domínio

| Domínio | Tabelas |
|---|---|
| **Autenticação/usuários/permissões** | `bi_users`, `bi_user_tenants` |
| **Multi-tenancy/planos** | `bi_tenants`, `bi_plans`, `bi_negocio_plano_historico`, `bi_negocio_contatos`, `bi_negocio_institution_names` |
| **Estudos/DICOM/PACS** | `bi_pacs_servidor`, `bi_pacs_roteamento`, `bi_pacs_estudos`, `bi_pacs_sync_log`, `bi_pacs_sync_execucoes`, `bi_orthanc_servidores`, `bi_orthanc_sync_log`, `pacs_viewer_tokens` |
| **Laudos** | `reports`, `report_versions`, `report_templates`, `report_autotext`, `report_signatures`, `report_logs`, `report_favorites` |
| **Auditoria/logs** | `bi_audit_logs`, `report_logs`, `bi_pacs_sync_log`, `bi_orthanc_sync_log` |
| **Negócio/financeiro/importação** | `bi_exames`, `bi_importacoes`, `bi_layouts_importacao`, `bi_kpi_snapshots`, `bi_medicos`, `bi_unidades`, `bi_modalidades`, `bi_pacs_conexoes` |
| **Configuração/sincronização** | `bi_configuracoes`, colunas `sync_*` de `bi_pacs_servidor` |

---

## 5. Tabelas CRÍTICAS — alterar com cuidado redobrado

| Tabela | Por quê |
|---|---|
| **`bi_pacs_estudos`** | Fonte única de verdade sobre estudos DICOM para toda a plataforma. ~140 colunas, ~30 índices, tocada por 7 das 15 migrations. Quebra worklist, viewer, laudos e sync. |
| **`bi_tenants`** | Raiz da multi-tenancy; quase toda tabela depende de `tenant_id` (majoritariamente sem FK declarada — o banco não protege). |
| **`bi_pacs_servidor`** | Servidor Orthanc único e global (`id=1` assumido em código); toda sincronização/roteamento depende dele. |
| **`bi_pacs_roteamento`** | Determina a que tenant cada estudo pertence — erro aqui é vazamento de dados clínicos entre clínicas. |
| **`bi_users`** | Autenticação de toda a plataforma; `role` controla acesso superadmin. |
| **`reports`** | 2 schemas incompatíveis no histórico — confirmar produção antes de qualquer alteração. |
| **`pacs_viewer_tokens`** | Camada de segurança do viewer; erro de lógica pode gerar acesso indevido a exames de pacientes. |

---

## 6. Procedures, Triggers, Views

Nenhuma VIEW, TRIGGER ou FUNCTION em qualquer migration. Uma única PROCEDURE (`voxel_add_column`, em `2026-05-11_negocios_module.sql`), usada como helper temporário para simular `ADD COLUMN IF NOT EXISTS` em MySQL 5.7 e **removida (`DROP PROCEDURE`) no fim do próprio script** — não persiste no banco.

---

## 7. Seeds (`database/seeds/`)

| Arquivo | Conteúdo | Compatível com o schema atual? |
|---|---|---|
| `001_superadmin.sql` | Superadmin "VOXEL B.I." + 3 planos com colunas `descricao`/`recursos_json` | ❌ **Não** — colunas não existem em `bi_plans`; `ON DUPLICATE KEY UPDATE nome=...` também está errado (coluna é `name`) |
| `001_superadmin_pacs.sql` | Superadmin "VOXEL PACS" + 3 planos com as colunas corretas (`slug`, `max_exames_mes`, `permite_*`) | ✅ **Sim** — use este |

Nenhum dos dois popula dados de demonstração (tenants/exames/estudos) — só o super-usuário inicial e os planos comerciais.

---

## 8. Resumo executivo

1. Schema evolutivo, sem ferramenta de migration formal — várias tabelas centrais foram alteradas por scripts redundantes/alternativos.
2. Dois modelos paralelos de arquitetura PACS coexistem no schema; só o modelo "Orthanc global + roteamento" está ativo no código hoje.
3. Módulo de laudos (`reports`) tem 2 definições de schema conflitantes — maior risco a esclarecer antes de qualquer migration nova nessa área.
4. `bi_pacs_estudos` é a tabela mais crítica, mais indexada e mais alterada do sistema.
5. Seeds não estão 100% sincronizados com o schema atual — use `001_superadmin_pacs.sql`, não `001_superadmin.sql`.
