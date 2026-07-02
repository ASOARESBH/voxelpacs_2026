# API VOXEL PACS — Sistema de Gestão PACS

Sistema PHP 8.1 Multi-Tenant para gestão de exames DICOM, integrado ao Orthanc e ao OHIF Viewer.

> Este diretório é um **submódulo Git** do repositório [andreprogramadorbh-ai/voxelpacs](https://github.com/andreprogramadorbh-ai/voxelpacs).
> Para atualizar o código da API: `git submodule update --remote api`

## Arquitetura do Sistema

O sistema é uma aplicação PHP MVC com as seguintes camadas:

| Camada | Localização | Responsabilidade |
|---|---|---|
| **Controllers** | `app/Controllers/` | Recebe requisições HTTP, chama Services |
| **Models** | `app/Models/` | Acesso ao banco de dados (MySQL) |
| **Services** | `app/Services/` | Lógica de negócio (Orthanc, ERP, KPI) |
| **Views** | `app/Views/` | Templates PHP (layouts por contexto) |
| **Core** | `app/Core/` | Router, Database, Auth, Middleware, RBAC |
| **Middlewares** | `app/Middlewares/` | Auth, CSRF, Permissão, Tenant, Sessão |
| **Routes** | `routes/` | Definição de rotas (web.php, platform.php) |
| **Migrations** | `database/migrations/` | Scripts SQL de criação/alteração de tabelas |

## Módulos Principais

| Módulo | Descrição |
|---|---|
| **Multi-Tenant** | Isolamento por tenant (clínica/hospital) |
| **Auth/RBAC** | Autenticação + controle de permissões por papel |
| **Estudos DICOM** | Listagem, busca e abertura de estudos via OHIF |
| **OrthancService** | Integração com o servidor Orthanc via REST API |
| **PacsConnectorService** | Conexão e monitoramento do servidor PACS |
| **KpiService** | Indicadores de desempenho (SLA, produtividade) |
| **BenchmarkService** | Comparativos entre unidades/modalidades |
| **PreditivoService** | Análise preditiva de demanda |
| **VoxelErpService** | Integração com o ERP inlaudo |
| **ImportacaoService** | Importação de planilhas de exames |
| **ExportService** | Exportação de relatórios |

## Banco de Dados

O sistema utiliza **MySQL 8.0** (container Docker). As migrations são executadas automaticamente na primeira inicialização do container.

| Arquivo de Migration | Descrição |
|---|---|
| `2026-01-01_bi_multitenant_schema.sql` | Schema principal multi-tenant |
| `2026-05-11_negocios_module.sql` | Módulo de negócios |
| `2026-05-12_orthanc_colunas.sql` | Colunas de integração Orthanc |
| `2026-05-26_pacs_estudos_dicom_tags.sql` | Tags DICOM dos estudos |
| `2026-05-26_servidor_pacs.sql` | Configuração do servidor PACS |

## Variáveis de Ambiente

As variáveis são injetadas pelo `docker-compose.yml` a partir do `.env` do projeto de deploy:

| Variável | Descrição |
|---|---|
| `APP_URL` | URL pública da aplicação |
| `APP_SECRET` | Chave secreta para sessões e tokens |
| `DB_HOST` | Host do MySQL (interno Docker: `mysql`) |
| `DB_DATABASE` | Nome do banco de dados |
| `DB_USERNAME` | Usuário do banco |
| `DB_PASSWORD` | Senha do banco |
| `ORTHANC_URL` | URL do Orthanc (interno Docker: `http://orthanc:8042`) |
| `ORTHANC_USER` | Usuário admin do Orthanc |
| `ORTHANC_PASS` | Senha admin do Orthanc |
| `DICOM_VIEWER_URL` | URL pública do OHIF (via Nginx) |

## Fluxo de Abertura de Exame por Token

```
ERP inlaudo
    │
    ▼
POST /api/token/gerar
    │ (retorna token único)
    ▼
Usuário acessa: https://view.voxelpacs.com.br/open/{token}
    │
    ▼
API valida token → busca StudyInstanceUID no banco
    │
    ▼
Redireciona para OHIF: /viewer?StudyInstanceUIDs={uid}
    │
    ▼
OHIF carrega imagens via DICOMweb (/dicom-web → Orthanc)
```

## Build Docker

```bash
# Build manual (feito automaticamente pelo install.sh)
cd docker
docker compose build voxelpacs-api

# Rebuild após atualizar o código
git submodule update --remote api
cd docker
docker compose up -d --build voxelpacs-api
```

## Credenciais Padrão (alterar após primeiro acesso)

| Campo | Valor |
|---|---|
| **E-mail** | `admin@voxelpacs.com.br` |
| **Senha** | `Admin259087@` |

> **IMPORTANTE:** Altere a senha imediatamente após o primeiro acesso em produção.
