# Máscaras de Laudo

## Finalidade

O módulo permite que cada médico crie, revise, importe e compartilhe máscaras de laudo associadas à modalidade e, opcionalmente, à TAG DICOM **Study Description** `(0008,1030)`. As máscaras pertencem ao tenant e são persistidas em `report_templates`.

## Acesso e privacidade

As operações administrativas usam `TemplatesController`. O médico vinculado só pode consultar, criar, editar, importar ou excluir máscaras do próprio cadastro. Uma máscara com `compartilhar = 1` pode ser utilizada pelos demais médicos do mesmo tenant, mas continua editável apenas pelo proprietário. Máscaras importadas por DOCX entram como privadas (`compartilhar = 0`); o médico pode compartilhá-las conscientemente em edição posterior.

## Estrutura persistida

A tabela mantém colunas legadas para compatibilidade com o laudário e com máscaras anteriores.

| Coluna | Situação atual |
|---|---|
| `origem` | `manual` para máscaras criadas pelo modal e `importado` para confirmações de DOCX. |
| `arquivo_origem` | Nome sanitizado do DOCX que originou uma máscara importada; é informativo, nunca um caminho de servidor. |
| `revisar` | `1` quando o parser não identificou seções clínicas reconhecidas; a máscara recebe badge **Revisar**. |
| `secao_exame` | Legada; preservada em edição, não exibida no novo modal. |
| `secao_tecnica` | Editável no modal. Aceita HTML sanitizado com `<p>`, `<br>` e `<strong>`. |
| `secao_achados` | Editável no modal. Aceita HTML sanitizado com `<p>`, `<br>` e `<strong>`. |
| `secao_conclusao` | Editável no modal, exibida ao usuário como **Impressão**. A chave interna não foi renomeada. |
| `secao_recomendacao` | Legada; preservada em edição, não exibida no novo modal. |

## Editor de máscara

A tela `app/Views/medicos/form.php`, aba **Máscaras**, carrega Quill 1.3.7 somente em modo de edição de médico. O modal **Nova Máscara** possui exatamente três editores: **Técnica**, **Achados** e **Impressão**. A toolbar é deliberadamente mínima: apenas **negrito**, inclusive via `Ctrl+B`.

O backend permite somente as tags `p`, `br`, `strong` e `b` para as três seções editáveis. Tags e atributos não permitidos são removidos antes da persistência. Assim, o negrito clínico é preservado em segurança no banco e no laudo final.

## Importação DOCX com revisão obrigatória

A importação é deliberadamente dividida em duas etapas. A primeira **analisa**, mas não grava. A segunda **confirma** apenas as máscaras selecionadas pelo médico. A rota antiga `/importar` é apenas um alias compatível para a etapa de análise e nunca faz persistência direta.

| Etapa | Rota | Responsabilidade |
|---|---|---|
| Análise | `POST /api/medicos/{medicoId}/templates/importar/analisar` | Valida o upload, interpreta o DOCX e devolve JSON com as máscaras detectadas, modalidades sugeridas e flags de revisão. |
| Revisão | Modal `#modalRevisarImportacao` | Permite selecionar cada máscara, ler prévia resumida dos Achados e alterar a modalidade sugerida antes de salvar. |
| Confirmação | `POST /api/medicos/{medicoId}/templates/importar/confirmar` | Valida novamente o payload, evita duplicatas e persiste o lote em transação PDO. |

O arquivo é aceito somente se houver extensão `.docx`, upload válido, tamanho de até 15 MB e assinatura ZIP `PK\x03\x04`. O nome de origem é reduzido a caracteres seguros antes de ser salvo. Não há `shell_exec`, Python, Pandoc ou binário externo: `MascaraDocxImportService` usa somente PHPWord (`PhpOffice\PhpWord\IOFactory`).

O parser trata qualquer estilo `Heading*` como início de uma máscara. Rótulos em negrito são normalizados pelo dicionário abaixo. Campos não reconhecidos, como **Indicação** ou **Medidas**, nunca são descartados: são encaminhados para **Achados**. Um bloco sem seção reconhecida recebe `revisar = 1`, com destaque no modal e badge na listagem.

| Rótulo normalizado | Campo persistido |
|---|---|
| `Técnica`, `Método` | `secao_tecnica` |
| `Análise`, `Achados` | `secao_achados` |
| `Impressão`, `Impressão Diagnóstica`, `Conclusão` | `secao_conclusao` |

A modalidade é somente uma sugestão: `tomografia` e `angiotomografia` → `CT`; `ressonância` → `MR`; `radiografia`/`raio x` → `CR`; `ultrassonografia`/`ecografia` → `US`; `mamografia` → `MG`. A sugestão pode ser alterada antes da confirmação.

O conteúdo textual do DOCX passa por `htmlspecialchars()` antes da geração de `<p>`, `<strong>` ou `<em>`, e é sanitizado novamente no controlador antes do `INSERT`. A confirmação gera **um único** evento `importar_mascaras_docx` em `bi_audit_logs` por lote, com arquivo, totais importados, duplicados ignorados e totais que requerem revisão.

## Aplicação no laudário

`TemplatesController::autoCarregar()` resolve a máscara pela Study Description e `ReportsController::template()` atende a aplicação manual. Em `app/Views/reports/index.php`, `aplicarTemplate()` une as seções preenchidas em um corpo clínico único, sem impor títulos. O HTML de `<strong>` chega intacto ao editor e à impressão/PDF.

## Migration e compatibilidade

Antes de publicar o código em produção, execute `database/migrations/2026-08-13_report_templates_importacao_docx.sql` no phpMyAdmin. Ela adiciona `origem`, `arquivo_origem`, `revisar` e o índice `idx_rt_origem_revisar`. A migration é compatível com MySQL 5.7/MariaDB em HostGator e não usa `INFORMATION_SCHEMA`, procedures, triggers ou sintaxe de MySQL 8.

Máscaras existentes recebem origem `manual`. Ao editar uma máscara anterior, `secao_exame` e `secao_recomendacao` são preservadas exatamente como estavam, embora não apareçam no modal novo. Em novas máscaras, essas duas seções são gravadas vazias.

## Arquivos principais

- `app/Services/MascaraDocxImportService.php` — parser PHPWord seguro, seções, modalidades e flags de revisão.
- `app/Controllers/TemplatesController.php` — CRUD, guarda de acesso, análise, confirmação transacional e auditoria.
- `app/Views/medicos/form.php` — modal de upload, modal de revisão, badges e payloads da máscara.
- `database/migrations/2026-08-13_report_templates_importacao_docx.sql` — metadados de origem e revisão.
- `app/Views/reports/index.php` — aplicação no editor clínico.
- `app/Controllers/ReportsController.php` — recuperação de máscara para o laudário.

## Pré-visualização somente leitura

Cada cartão da aba **Máscaras** possui, nesta ordem, as ações **Visualizar Laudo**, **Editar** e **Excluir**. O botão de visualização abre uma nova aba:

```text
GET /medicos/{medicoId}/mascaras/{mascaraId}/visualizar
```

A rota usa `TemplatesController::visualizar()`. Ela não altera `uso_count`, não cria relatório e não consulta estudos ou pacientes. A consulta exige `tenant_id` do contexto atual, `ativo = 1` e que a máscara seja do médico indicado, compartilhada com a clínica ou global. Para médico restrito, `MedicoAccess` exige que `{medicoId}` seja o próprio cadastro.

A view `app/Views/mascaras/visualizar.php` reaproveita o padrão tipográfico, cabeçalho, títulos de seção e ações de impressão do template PDF Clássico Centralizado, porém **não exibe nenhum dado de paciente**. Ela mostra somente Técnica, Achados e Impressão, preservando o HTML sanitizado de negrito, e contém o aviso visível de que se trata de uma pré-visualização sem vínculo com estudo real.

Não há botão de download de PDF nessa página. Os únicos controles são **Imprimir** e **Voltar para Máscaras**.
