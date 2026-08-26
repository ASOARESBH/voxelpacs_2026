# Módulo Reports (Laudos Médicos)

**Versão:** 1.0
**Data:** 05/07/2026
**Autor:** Manus AI

## 1. Visão Geral
O módulo Reports é o core de emissão de laudos do VOXEL PACS. Ele fornece um editor médico completo em layout de 3 colunas, focado em produtividade radiológica, com salvamento automático, controle de concorrência, histórico de versões, e geração de PDF.

## 2. Arquitetura

O módulo segue o padrão MVC + Repository + Service:

- **Model:** `App\Models\Report` (Entidade de dados baseada em array/objeto)
- **Repository:** `App\Repositories\ReportRepository` (Abstração do banco de dados, queries complexas, histórico e logs)
- **Service:** `App\Services\ReportService` (Regras de negócio, inicialização de laudo, bloqueio de concorrência)
- **Controller:** `App\Controllers\ReportsController` (Roteamento, requisições HTTP/AJAX, renderização de views e PDFs)

## 3. Banco de Dados

A migration `2026-07-05_reports_module.sql` cria 7 tabelas otimizadas para MariaDB 5.7:

1. `reports`: Tabela principal que armazena as 5 seções do laudo e status.
2. `report_versions`: Histórico incremental de todas as alterações (autosave e manual).
3. `report_templates`: Modelos pré-formatados de laudos por modalidade.
4. `report_autotext`: Textos rápidos ativados por gatilhos (ex: `torax_normal`).
5. `report_favorites`: Templates favoritados por médico.
6. `report_signatures`: Registro criptográfico (hash) de assinaturas digitais.
7. `report_logs`: Trilha de auditoria (quem abriu, assinou, baixou PDF).

## 4. Funcionalidades do Editor (View 3 Colunas)

O editor (`app/Views/reports/index.php`) é dividido em 3 áreas funcionais:

### 4.1. Coluna Esquerda (Paciente & Exame - 25%)
- Dados demográficos do paciente (idade, sexo, peso, altura)
- Metadados DICOM (modalidade, accession, equipamento, solicitante)
- Botões de integração direta com OHIF Viewer
- Histórico rápido de exames anteriores do mesmo paciente

### 4.2. Coluna Central (Editor Médico - 50%)
- **Toolbar Fixa:** Negrito, itálico, listas, tabelas, tamanho de fonte
- **5 Seções Expansíveis:** Exame, Técnica, Achados, Conclusão, Recomendação
- **Autosave:** A cada 30 segundos (configurável)
- **Autotexto Inteligente:** Dropdown automático ao digitar gatilhos (mínimo 3 letras)
- **Assinatura:** Modal com validação de senha do usuário ativo

### 4.3. Coluna Direita (Painel Inteligente - 25%)
- Lista de autotextos filtrável
- Histórico de versões com restauração rápida
- Checklist dinâmico de preenchimento (valida se as seções têm conteúdo)
- Alertas clínicos baseados na prioridade da worklist
- Área para observações internas

## 5. Integração com a Worklist (Módulo Estudos)

A tabela da worklist (`app/Views/estudos/index.php`) foi atualizada para integrar o fluxo do laudo:

- **Situação Novo/Aberto:** Exibe botão **Assumir** (vincula o médico e muda status para `em_laudo`).
- **Situação Em Laudo/Rascunho:** Exibe botão **Continuar** (abre o editor).
- **Situação Assinado/Liberado:** Exibe botões **Laudo** (visualizar) e **PDF** (baixar).
- O Viewer (OHIF) está sempre disponível, independentemente da situação.

## 6. Rotas (web.php)

| Rota | Método | Descrição |
|---|---|---|
| `/reports/{study_uid}` | GET | Abre a interface do editor de laudo |
| `/reports/save` | POST | Salva o conteúdo do laudo (autosave/manual) |
| `/reports/sign` | POST | Assina o laudo com senha |
| `/reports/history` | GET | Retorna JSON com o histórico de versões |
| `/reports/pdf` | GET | Renderiza ou força o download do laudo em PDF |
| `/reports/template` | GET | Retorna JSON com conteúdo de um template |
| `/reports/assumir` | POST | Ação da worklist para iniciar o laudo |
| `/api/reports/autotext` | GET | Busca autotextos para o dropdown (AJAX) |
| `/api/reports/by-estudo` | GET | Resolve ID do report a partir do ID do estudo |

## 7. Controle de Concorrência (Bloqueio)

Para evitar que dois médicos editem o mesmo laudo simultaneamente:
1. Ao abrir o editor, o sistema registra `bloqueado_por = user_id` e `bloqueado_em = NOW()`.
2. Se outro médico tentar abrir, a interface entra em modo **Somente Leitura**.
3. Um alerta amarelo informa quem está editando e desde que horas.
4. O bloqueio expira automaticamente após 60 minutos de inatividade.

## 8. Idade do paciente no cartão do Report

O cartão de paciente calcula a idade a partir de `patient_birth_date` quando há uma data DICOM completa no formato `YYYYMMDD`. O cálculo usa a diferença entre a data de nascimento e a data atual, considerando dia e mês; por isso, não antecipa a idade antes do aniversário anual.

| Situação da data de nascimento | Exibição de idade |
|---|---|
| Data DICOM completa, válida e não futura | Idade calculada em anos e data formatada em `dd/mm/aaaa`. |
| Data ausente, parcial, inválida ou futura | Mantém o fallback `patient_age` quando o valor DICOM estiver disponível. |
| Sem data válida e sem `patient_age` utilizável | Exibe `—`, sem inferir informação clínica. |

Essa lógica é somente de apresentação do laudo. Ela não modifica `patient_birth_date`, `patient_age` ou qualquer registro DICOM no banco.
