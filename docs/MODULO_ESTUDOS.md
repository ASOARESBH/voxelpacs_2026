# Módulo Estudos — VOXEL PACS

**Autor:** Manus AI  
**Data:** 05/07/2026  
**Versão:** 4.0 (Revisão Completa)

## Visão Geral

O módulo **Estudos** é o coração do VOXEL PACS, servindo como a *Worklist* principal para médicos e operadores visualizarem os exames sincronizados a partir do servidor Orthanc. 

A arquitetura foi refatorada para utilizar o padrão **Repository/Service/Controller**, garantindo melhor separação de responsabilidades, performance otimizada na construção de consultas SQL e maior facilidade de manutenção.

## Estrutura do Módulo

O módulo é composto pelos seguintes arquivos principais:

- **Controller:** `app/Controllers/EstudosController.php`
- **Service:** `app/Services/EstudosService.php`
- **Repository:** `app/Repositories/EstudosRepository.php`
- **View:** `app/Views/estudos/index.php`
- **Tabela Principal:** `bi_pacs_estudos`

## Funcionalidades Implementadas

### 1. Painel de Resumo Dinâmico
A interface agora conta com um painel superior de indicadores (cards) que mostram os totais de exames para:
- Hoje
- Últimos 7 Dias
- Últimos 30 Dias
- Urgentes
- Total na Base
- Contadores dinâmicos por situação (Novo, Aberto, Em Laudo, etc.)
- Data e hora da última sincronização com o Orthanc

### 2. Filtros Inteligentes e Combinados
Todos os exames existentes na tabela `bi_pacs_estudos` são acessíveis através de uma barra de filtros completa:
- **Pesquisa Global (`q`):** Busca em Paciente, ID, UID, Accession, Descrição e Instituição.
- **Período Inteligente:** Opções rápidas (Hoje, Ontem, 7 dias, 30 dias, 90 dias, Ano, Todos) com suporte a datas personalizadas (`dt_inicio` e `dt_fim`). Quando "Todos" é selecionado, nenhum filtro de data é aplicado.
- **Paciente:** Pesquisa parcial (`LIKE`) no nome do paciente.
- **Instituição:** Select dinâmico populado com as unidades cadastradas.
- **Modalidade:** Botões de filtro rápido para modalidades (CR, DX, CT, MR, etc.) e select oculto.
- **Especialidade:** Select dinâmico populado com as especialidades existentes.
- **Situação:** Filtro por status do exame (Novo, Aberto, Em Laudo, Rascunho, Assinado, Liberado).
- **Prioridade:** Novo filtro para diferenciar exames normais de urgentes/críticos.
- **Médico Responsável:** Pesquisa parcial (`LIKE`) pelo médico assumido.

### 3. Paginação e Ordenação
- **Paginação Corrigida:** Opções para exibir 25, 50, 100, 250 ou "Todos" os exames por página.
- **Ordenação Dinâmica:** Permite ordenar por Data, Hora, Paciente, Instituição, Modalidade, Especialidade, Prioridade e Situação (ASC/DESC).
- **Ordenação Padrão:** `study_date DESC, study_time DESC`.

### 4. Otimização de Performance (SQL)
A construção da query SQL foi movida para o `EstudosRepository`, aplicando regras de otimização:
- **Evitar `SELECT *`:** Apenas as colunas necessárias para a view são selecionadas.
- **WHERE Dinâmico:** Condições só são adicionadas à query se o filtro estiver ativo.
- **Paginação via SQL:** Uso de `LIMIT` e `OFFSET` para não carregar todos os registros na memória.
- **Uso de Índices:** Uma nova migration foi criada para adicionar índices estratégicos à tabela `bi_pacs_estudos`.

## Tabela: `bi_pacs_estudos`

Esta é a tabela consultada pelo módulo. O sistema **nunca** consulta o Orthanc diretamente para montar a worklist.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | INT | Chave primária |
| `orthanc_id` | VARCHAR | UUID interno do Orthanc |
| `study_instance_uid` | VARCHAR | UID DICOM do estudo |
| `study_date` | DATE | Data do estudo |
| `study_time` | TIME | Hora do estudo |
| `patient_name` | VARCHAR | Nome do paciente |
| `patient_id` | VARCHAR | ID do paciente |
| `accession_number` | VARCHAR | Número de acesso |
| `institution_name` | VARCHAR | Instituição de origem |
| `modalities` | VARCHAR | Modalidades do estudo |
| `study_description` | VARCHAR | Descrição do estudo |
| `situacao` | VARCHAR | Status do exame (novo, aberto, em_laudo...) |
| `prioridade` | VARCHAR | Prioridade (normal, rotina, urgente, critico) |
| `especialidade` | VARCHAR | Especialidade associada |
| `assumido_por` | VARCHAR | Médico responsável |
| `num_series` | INT | Número de séries |
| `num_instances` | INT | Número total de instâncias (imagens) |
| `tenant_id` | INT | ID do Tenant (multitenant) |

## Fluxo de Abertura no Viewer (OHIF)

1. O usuário clica duas vezes em uma linha ou no botão "Abrir".
2. O Controller `/estudos/{id}/abrir` é chamado.
3. Um token UUID é gerado e salvo na tabela `pacs_viewer_tokens`.
4. O sistema redireciona para a URL do OHIF Viewer (`VIEWER_URL`), passando o `StudyInstanceUID`.

## Migração de Índices Sugeridos

Para garantir a performance da worklist com grandes volumes de dados, o arquivo `2026-07-05_bi_pacs_estudos_worklist_indices.sql` foi criado com os seguintes índices:

1. `idx_worklist_main` (`tenant_id`, `study_date`, `situacao`)
2. `idx_tenant_prioridade` (`tenant_id`, `prioridade`)
3. `idx_tenant_especialidade` (`tenant_id`, `especialidade`)
4. `idx_assumido_por` (`assumido_por`)
5. `idx_servidor_tenant_date` (`servidor_id`, `tenant_id`, `study_date`)

Esses índices cobrem as combinações mais comuns de filtros utilizados pelos usuários na interface.
