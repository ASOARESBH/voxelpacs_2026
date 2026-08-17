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
| `conteudo_livre` | Fonte principal das novas Máscaras. Armazena HTML sanitizado do editor Quill livre; `NULL` identifica uma Máscara legada por seções. |
| `secao_tecnica` | Legada; continua sendo lida para compatibilidade. |
| `secao_achados` | Legada; continua sendo lida para compatibilidade. |
| `secao_conclusao` | Legada; continua sendo lida para compatibilidade. |
| `secao_recomendacao` | Legada; preservada em edição, não exibida no novo modal. |

## Editor de máscara

A tela `app/Views/medicos/form.php`, aba **Máscaras**, carrega Quill 1.3.7 somente em modo de edição de médico. O modal **Nova Máscara** possui um único editor clínico livre, sem obrigatoriedade de Técnica, Achados ou Impressão. A toolbar compartilhada com o Laudário contém negrito, itálico, sublinhado, títulos, listas, tabela 2×2, desfazer/refazer e limpeza de formatação.

O backend preserva somente HTML clínico seguro: `p`, `br`, `strong`, `em`, `u`, headings, listas e tabelas sem atributos. Máscaras antigas com seções continuam editáveis por conversão de leitura: suas seções são apresentadas como conteúdo contínuo, sem destruir o fallback legado.

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

## Aplicação no Laudário

**Máscaras** e o antigo seletor de **Templates de Laudo** usam a mesma tabela `report_templates`; a diferença é apenas de apresentação. `ReportsController::templates()` entrega as Máscaras do médico, as compartilhadas e as globais do tenant, filtradas pelas modalidades DICOM do estudo e priorizadas pela TAG Study Description `(0008,1030)`.

Na tela `app/Views/reports/show.php`, a ordem da coluna lateral é **Paciente → Buscar Máscara → Exame → demais cards**. O card de busca inline, em `partials/_mascara_search_card.php`, substitui o modal central. Ao focar, carrega e mantém em memória as Máscaras compatíveis; a digitação filtra em memória por nome, modalidade e Study Description com normalização de acentos. Setas, Enter, Esc e clique fora são suportados. Ao selecionar, `reports-templates.js` reaproveita a mesma aplicação clínica: `loadConteudoLivre()` para Máscaras novas e `loadSecoes()` somente para o fallback legado.

## Migration e compatibilidade

Antes de publicar o código em produção, execute `database/migrations/2026-08-13_report_templates_importacao_docx.sql` e `database/migrations/2026-08-16_report_templates_conteudo_livre.sql` no phpMyAdmin. Elas adicionam, respectivamente, os metadados de importação (`origem`, `arquivo_origem`, `revisar`) e a coluna `conteudo_livre` para o editor rico. As migrations são compatíveis com MySQL 5.7/MariaDB em HostGator e não usam `INFORMATION_SCHEMA`, procedures, triggers ou sintaxe de MySQL 8.

Máscaras existentes recebem origem `manual`. Ao editar uma máscara anterior, `secao_exame` e `secao_recomendacao` são preservadas exatamente como estavam, embora não apareçam no modal novo. Em novas máscaras, essas duas seções são gravadas vazias.

## Arquivos principais

- `app/Services/MascaraDocxImportService.php` — parser PHPWord seguro, seções, modalidades e flags de revisão.
- `app/Controllers/TemplatesController.php` — CRUD, guarda de acesso, análise, confirmação transacional e auditoria.
- `app/Views/medicos/form.php` — modal de upload, modal de revisão, badges e payloads da máscara.
- `database/migrations/2026-08-13_report_templates_importacao_docx.sql` e `2026-08-16_report_templates_conteudo_livre.sql` — metadados de importação e conteúdo rico.
- `app/Views/reports/show.php` e `partials/_mascara_search_card.php` — posição e markup da busca inline no painel lateral.
- `public/assets/js/reports/reports-templates.js` — cache, filtro acessível e aplicação da Máscara.
- `app/Controllers/ReportsController.php` — recuperação de Máscaras para o Laudário.

## Pré-visualização somente leitura

Cada cartão da aba **Máscaras** possui, nesta ordem, as ações **Visualizar Laudo**, **Editar** e **Excluir**. O botão de visualização abre uma nova aba:

```text
GET /medicos/{medicoId}/mascaras/{mascaraId}/visualizar
```

A rota usa `TemplatesController::visualizar()`. Ela não altera `uso_count`, não cria relatório e não consulta estudos ou pacientes. A consulta exige `tenant_id` do contexto atual, `ativo = 1` e que a máscara seja do médico indicado, compartilhada com a clínica ou global. Para médico restrito, `MedicoAccess` exige que `{medicoId}` seja o próprio cadastro.

A view `app/Views/mascaras/visualizar.php` reaproveita o padrão tipográfico, cabeçalho, títulos de seção e ações de impressão do template PDF Clássico Centralizado, porém **não exibe nenhum dado de paciente**. Ela mostra somente Técnica, Achados e Impressão, preservando o HTML sanitizado de negrito, e contém o aviso visível de que se trata de uma pré-visualização sem vínculo com estudo real.

Não há botão de download de PDF nessa página. Os únicos controles são **Imprimir** e **Voltar para Máscaras**.

## Navegação da aba Máscaras e pré-visualização

A aba **Máscaras** da edição de Médico é acionada por uma única rotina: `ativarAbaMedico()` delega os efeitos de cada aba a `carregarConteudoAbaMedico()`. Por isso, o clique manual, a URL direta `/medicos/{id}/edit?aba=mascaras`, favoritos e o fallback da pré-visualização sempre chamam `carregarMascaras()` e não deixam a interface no estado permanente de carregamento. A aba Assinatura reutiliza o mesmo padrão de inicialização preguiçosa.

O botão **Visualizar Laudo** continua abrindo em nova aba com `target="_blank"` e `rel="noopener"`, preservando a listagem original. A pré-visualização usa o padrão compartilhado `voxelVoltar(fallbackUrl)`: quando houver histórico interno, retorna à página anterior; em nova aba ou acesso direto, usa `/medicos/{id}/edit?aba=mascaras` como fallback seguro. Ver também `SKILL-VOXEL-PACS/patterns/padrao-navegacao-voltar.md`.
