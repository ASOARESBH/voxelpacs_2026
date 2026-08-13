# Máscaras de Laudo

## Finalidade

O módulo permite que cada médico crie e compartilhe máscaras de laudo associadas a modalidade e, opcionalmente, à TAG DICOM **Study Description** `(0008,1030)`. As máscaras pertencem ao tenant e são persistidas em `report_templates`.

## Acesso e privacidade

As operações administrativas usam `TemplatesController`. O médico vinculado só pode consultar, criar, editar, importar ou excluir máscaras do próprio cadastro. Uma máscara com `compartilhar = 1` pode ser utilizada pelos demais médicos do mesmo tenant, mas continua editável apenas pelo proprietário.

## Estrutura persistida

A tabela mantém as colunas legadas para compatibilidade com o laudário e com máscaras já existentes:

| Coluna | Situação atual |
|---|---|
| `secao_exame` | Legada; preservada em edição, não exibida no novo modal. |
| `secao_tecnica` | Editável no modal. Aceita HTML sanitizado com `<p>`, `<br>` e `<strong>`. |
| `secao_achados` | Editável no modal. Aceita HTML sanitizado com `<p>`, `<br>` e `<strong>`. |
| `secao_conclusao` | Editável no modal, exibida ao usuário como **Impressão**. A chave interna não foi renomeada. |
| `secao_recomendacao` | Legada; preservada em edição, não exibida no novo modal. |

## Editor de máscara

A tela `app/Views/medicos/form.php`, aba **Máscaras**, carrega Quill 1.3.7 somente em modo de edição de médico. O modal **Nova Máscara** possui exatamente três editores: **Técnica**, **Achados** e **Impressão**. A toolbar é deliberadamente mínima: apenas **negrito**, inclusive via `Ctrl+B`.

O backend permite somente as tags `p`, `br`, `strong` e `b` para as três seções editáveis. Tags e atributos não permitidos são removidos antes da persistência. Assim, o negrito clínico é preservado em segurança no banco e no laudo final.

## Aplicação no laudário

`TemplatesController::autoCarregar()` resolve a máscara pela Study Description e `ReportsController::template()` atende a aplicação manual. Em `app/Views/reports/index.php`, `aplicarTemplate()` une as seções preenchidas em um corpo clínico único, sem impor títulos. O HTML de `<strong>` chega intacto ao editor e à impressão/PDF.

## Compatibilidade

Nenhuma migration é necessária. Máscaras existentes continuam sendo lidas. Ao editar uma máscara antiga, `secao_exame` e `secao_recomendacao` são preservadas exatamente como estavam, embora não apareçam no modal novo. Em novas máscaras, essas duas seções são gravadas vazias.

## Arquivos principais

- `app/Views/medicos/form.php` — modal, Quill e payload da máscara.
- `app/Controllers/TemplatesController.php` — CRUD, guarda de acesso e sanitização de HTML.
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
