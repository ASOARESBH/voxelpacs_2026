# VOXEL B.I

**Plataforma de Business Intelligence para Radiologia — Multi-Tenant, Multi-PACS, com Análise Preditiva**

---

## Documentação técnica completa

- **[REGRAS_DE_NEGOCIO.md](REGRAS_DE_NEGOCIO.md)** — referência central de regras de negócio (multi-tenancy, roteamento PACS, SLA, segurança) e pontos críticos de desenvolvimento, consolidando `SKILL-VOXEL-PACS/` e este manual num único documento. **Leia primeiro.**
- **[MANUAL_TECNICO.md](MANUAL_TECNICO.md)** — engenharia reversa completa da aplicação: arquitetura, fluxo de requisição, roteamento, autenticação/RBAC, multi-tenancy, comportamento de cada módulo/controller, frontend, integração DICOM/Orthanc, débito técnico e bugs conhecidos (priorizados), relatório de segurança, guia para novas integrações e checklist antes de implementar. **Datado (2026-07-17) — vários itens já corrigidos, ver ressalvas em REGRAS_DE_NEGOCIO.md §16.**
- **[BANCO_DE_DADOS.md](BANCO_DE_DADOS.md)** — dicionário de dados completo (todas as migrations, tabelas, colunas, relacionamentos, tabelas críticas, avisos sobre schemas conflitantes).
- **[MODULO_ESTUDOS.md](MODULO_ESTUDOS.md)** — detalhe do módulo de worklist (referência de padrão arquitetural).
- **[CONFIGURACAO_MODULOS_E_WORKLIST.md](CONFIGURACAO_MODULOS_E_WORKLIST.md)** — política global exclusiva do superadmin, guard de rotas, permissões individuais e refresh parcial configurável da Worklist.
- **[MODULO_REPORTS.md](MODULO_REPORTS.md)** — detalhe do módulo de laudos (⚠️ ver `MANUAL_TECNICO.md` §14 — está com bug crítico em produção no código atual).
- **[SYNC_AUTOMATICO_PACS.md](SYNC_AUTOMATICO_PACS.md)** — ⚠️ **desatualizado**: descreve a sincronização automática via cron externo, mas essa funcionalidade foi removida do código em um merge posterior (ver `MANUAL_TECNICO.md` §4.1). Mantido como referência histórica.
- **[DEPLOY_HOSTGATOR.md](DEPLOY_HOSTGATOR.md)** — passo a passo de deploy em hospedagem compartilhada.

---

## Visão Geral

O VOXEL B.I é uma plataforma SaaS de inteligência de dados voltada para clínicas e hospitais de radiologia. Permite a análise consolidada de exames provenientes de múltiplos sistemas PACS, com dashboards interativos, análise preditiva de volume e receita, benchmarking anônimo entre clínicas e gestão completa multi-tenant.

---

## Arquitetura

```
voxel-bi/
├── app/
│   ├── bootstrap.php               # Inicialização da aplicação
│   ├── Core/                       # Classes base do framework
│   │   ├── Auth.php                # Autenticação multi-tenant
│   │   ├── Database.php            # Singleton PDO
│   │   ├── Model.php               # Model base com isolamento por tenant
│   │   ├── Controller.php          # Controller base
│   │   ├── View.php                # Renderizador de views
│   │   ├── Router.php              # Roteador HTTP
│   │   ├── TenantContext.php       # Contexto do tenant ativo
│   │   ├── Permission.php          # Matriz de permissões por role
│   │   ├── Middleware.php          # Classe base de middlewares
│   │   ├── Logger.php              # Logger de arquivos
│   │   └── Audit/AuditLogger.php   # Log de auditoria no banco
│   ├── Controllers/                # Controllers da área do tenant
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── ExamesController.php
│   │   ├── MedicosController.php
│   │   ├── UnidadesController.php
│   │   ├── ModalidadesController.php
│   │   ├── FinanceiroController.php
│   │   ├── SlaController.php
│   │   ├── PreditivoController.php
│   │   ├── BenchmarkController.php
│   │   ├── RelatoriosController.php
│   │   ├── ImportacaoController.php
│   │   ├── PacsController.php
│   │   ├── ConfiguracoesController.php
│   │   ├── UsuariosController.php
│   │   └── Platform/               # Controllers da área superadmin
│   │       ├── PlatformDashboardController.php
│   │       ├── TenantsController.php
│   │       ├── PlansController.php
│   │       └── PlatformReportsController.php
│   ├── Middlewares/
│   │   ├── AuthMiddleware.php
│   │   ├── TenantMiddleware.php
│   │   ├── CsrfMiddleware.php
│   │   ├── PermissionMiddleware.php
│   │   ├── PlatformAdminMiddleware.php
│   │   └── SessionTimeoutMiddleware.php
│   ├── Models/
│   │   ├── Tenant.php
│   │   ├── TenantPlan.php
│   │   ├── User.php
│   │   ├── Exame.php
│   │   ├── Medico.php
│   │   ├── Unidade.php
│   │   ├── Modalidade.php
│   │   ├── PacsConexao.php
│   │   ├── Importacao.php
│   │   └── Configuracao.php
│   ├── Services/
│   │   ├── KpiService.php
│   │   ├── PreditivoService.php
│   │   ├── BenchmarkService.php
│   │   ├── ImportacaoService.php
│   │   ├── PacsConnectorService.php
│   │   ├── ExportService.php
│   │   └── VoxelErpService.php
│   └── Views/
│       ├── layout/
│       ├── auth/
│       ├── dashboard/
│       ├── preditivo/
│       └── platform/
├── config/database.php
├── database/migrations/
├── public/
│   ├── index.php
│   ├── .htaccess
│   └── assets/
├── routes/
│   ├── web.php
│   └── platform.php
├── storage/
├── .env.example
└── composer.json
```

---

## Requisitos

| Componente | Versão mínima |
|---|---|
| PHP | 8.1+ |
| MariaDB / MySQL | 10.6+ / 8.0+ |
| Servidor Web | Apache 2.4+ / Nginx 1.20+ |
| Composer | 2.x |

---

## Instalação

```bash
# 1. Clone o repositório
git clone https://github.com/ASOARESBH/VOXELBI.git
cd VOXELBI

# 2. Instale as dependências
composer install --no-dev --optimize-autoloader

# 3. Configure o ambiente
cp .env.example .env
# Edite .env com suas credenciais

# 4. Crie o banco de dados
mysql -u root -p -e "CREATE DATABASE voxel_bi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p voxel_bi < database/migrations/2026-01-01_bi_multitenant_schema.sql

# 5. Gere o hash da senha do admin
php -r "echo password_hash('Admin@2026', PASSWORD_ARGON2ID);"
# Cole o hash gerado na migration (linha INSERT bi_users)

# 6. Configure permissões
chmod -R 755 storage/
chmod -R 755 public/assets/
```

---

## Módulos do Sistema

### Área do Tenant

| Módulo | Descrição |
|---|---|
| **Dashboard** | KPIs em tempo real: total de exames, urgências, SLA médio, receita |
| **Exames** | Listagem paginada com filtros por PACS, modalidade, período e prioridade |
| **Médicos** | Ranking de produtividade por médico |
| **Unidades** | Análise por unidade/filial |
| **Modalidades** | Distribuição por tipo de exame (TC, RM, RX, US…) |
| **Financeiro** | Receita mensal, custo, ticket médio |
| **SLA & Performance** | Análise de cumprimento de SLA por período |
| **Análise Preditiva** | Projeção de volume por regressão linear + alertas automáticos |
| **Benchmark** | Comparação anônima com a plataforma (plano PRO) |
| **Relatórios** | Exportação em XLSX e CSV |
| **Importação** | Upload de planilhas com deduplicação por hash SHA-256 |
| **PACS** | Gerenciamento de conexões (upload manual, API REST, HL7 FHIR, ERP VOXEL) |
| **Configurações** | SLA padrão, cores, notificações |
| **Usuários** | Gestão de usuários e permissões por tenant |

### Área da Plataforma (Superadmin)

| Módulo | Descrição |
|---|---|
| **Dashboard Global** | MRR, total de tenants ativos, trials expirando |
| **Tenants** | CRUD de clientes + impersonação auditada |
| **Planos** | Gestão de planos e limites |
| **Relatórios** | Visão consolidada de todos os tenants |

---

## Hierarquia de Usuários

```
Platform Admin (superadmin)
└── Tenant Admin (admin)
    ├── Analista (analista)
    └── Visualizador (viewer)
```

---

## Segurança

- Isolamento de dados por `tenant_id` em todas as queries
- Prepared Statements em 100% das consultas SQL
- Hash de senhas com `PASSWORD_ARGON2ID`
- Proteção CSRF em todos os formulários POST
- Headers de segurança: `X-Frame-Options`, `X-XSS-Protection`, `X-Content-Type-Options`
- Deduplicação de exames por SHA-256
- Log de auditoria para ações sensíveis (impersonação, exclusões)
- Timeout de sessão configurável

---

## Credenciais Padrão (Desenvolvimento)

- **E-mail:** admin@voxel.com.br
- **Senha:** Admin@2026
- **Tipo:** Platform Admin

> **Atenção:** Altere a senha imediatamente após o primeiro acesso em produção.

---

## Licença

Proprietário — VOXEL Sistemas. Todos os direitos reservados.
