# Módulo — Template de Laudo por Unidade

## Propósito

O módulo controla exclusivamente a **apresentação** do laudo na visualização, impressão e fluxo de “Salvar como PDF” do navegador. Ele não altera o conteúdo clínico, o autosave, a assinatura, as Máscaras ou a autorização do laudo. O catálogo visual utiliza `report_layout_templates`; as Máscaras de conteúdo clínico permanecem em `report_templates`.

## Catálogo visual

| Código | Nome | Origem da renderização |
|---|---|---|
| `classico_centralizado` | Clássico Centralizado | Partial PHP fixo |
| `moderno_lateral` | Moderno Lateral | Partial PHP fixo |
| `corporativo_faixa` | Corporativo com Faixa | Partial PHP fixo |
| `minimalista` | Minimalista | Partial PHP fixo |
| `personalizado` | Personalizado | Versão publicada por Unidade, renderizada no dispatcher existente |

A resolução é centralizada em `App\Services\ReportLayoutService`. O PDF continua entrando por `ReportsController::pdf()` e `app/Views/reports/pdf.php`; a opção personalizada apenas fornece um partial adicional, sem criar rota paralela de geração de documento.

## Unidades coexistentes

O sistema mantém dois cadastros de Unidade. O Sistema A é prioritário em produção e usa `bi_negocio_institution_names` na rota `/unidades/{id}/edit`; o Sistema B usa `bi_unidades` nas rotas `/unidades/{id}/editar`. Por isso `report_custom_templates` armazena `unit_source` e `unit_id`. Toda leitura e escrita exige `tenant_id` da sessão e valida a Unidade antes de operar.

## Personalizado: rascunho, publicação e histórico

`report_custom_templates` guarda um rascunho editável e versões publicadas imutáveis para cada Unidade. O administrador salva o rascunho sem alterar documentos emitidos. Ao publicar, é criada uma nova versão incremental; a versão anterior permanece preservada. A publicação também seleciona `personalizado` como layout visual da Unidade.

Quando o médico assina, `ReportService::congelarTemplatePersonalizadoAssinado()` registra o ID da versão publicada em `reports.report_custom_template_id`. Assim, uma publicação posterior não altera a apresentação de um laudo já assinado. Se a Unidade estiver com Personalizado selecionado, mas não tiver publicação válida, `ReportsController::pdf()` usa o fallback `classico_centralizado` e registra um aviso técnico.

## Editor e preview

O editor dedicado é acessado pelo botão **Editar layout** dentro do quinto card em Unidade. Ele contém os blocos **Cabeçalho**, **Corpo** e **Rodapé**, alternáveis entre Texto (Quill via `voxel-quill-factory.js`) e HTML (textarea monoespaçado sem dependência adicional). O preview é enviado por POST com CSRF e exibido em `iframe sandbox="allow-same-origin"`; ele só usa dados fictícios, nunca IDs ou dados de paciente reais.

## Placeholders permitidos

| Categoria | Placeholders |
|---|---|
| Unidade | `{{unidade.nome}}`, `{{unidade.logo}}`, `{{unidade.cnpj}}`, `{{unidade.endereco}}` |
| Paciente | `{{paciente.nome}}`, `{{paciente.data_nascimento}}`, `{{paciente.id}}` |
| Exame | `{{exame.modalidade}}`, `{{exame.data}}`, `{{exame.descricao}}`, `{{exame.prontuario}}`, `{{exame.acesso}}` |
| Médico | `{{medico.nome}}`, `{{medico.crm}}`, `{{medico_solicitante.nome}}` |
| Laudo | `{{laudo.titulo}}`, `{{laudo.corpo}}`, `{{laudo.tecnica}}`, `{{laudo.achados}}`, `{{laudo.impressao}}`, `{{laudo.data_emissao}}`, `{{laudo.token_validacao}}` |
| Assinatura/rastreio | `{{assinatura.imagem}}`, `{{assinatura.data}}`, `{{qrcode}}` |

Valores clínicos e textuais são escapados por `htmlspecialchars`. Somente `unidade.logo`, `assinatura.imagem`, `laudo.corpo` e `qrcode` podem retornar markup gerado e controlado pelo servidor.

## Sanitização e CSS

O HTML é sanitizado antes de salvar e de renderizar. A allowlist aceita somente tags de texto, lista, tabela, cabeçalho, `div`, `span`, `hr` e `style`; `<script>`, atributos `on*`, formulários, links e recursos externos são removidos. O CSS remove `@import`, `@font-face`, `url()`, `expression()`, `javascript:`, `data:` e mecanismos equivalentes. O preview do navegador serve como aproximação visual: CSS arbitrário pode ter diferenças no diálogo de impressão, portanto devem ser testados documento longo, tabelas e cabeçalho/rodapé repetidos antes de ativação operacional.

## Permissões

A edição, preview, rascunho e publicação exigem sessão autenticada de superadmin ou administrador com `manage_configuracoes`. Médico, analista e viewer recebem HTTP 403. A validação de tenant é feita no backend; `unit_source`, `unit_id` e `tenant_id` nunca são aceitos como verdade do payload do navegador.

## Migration e validação

Execute `database/migrations/2026-08-18_report_custom_templates.sql` uma única vez após backup. Ela cria a tabela versionada, adiciona a referência congelada no `reports` e semeia o quinto layout. Valide com:

```bash
php tests/report_custom_templates_static.php
php -l app/Services/ReportCustomTemplateService.php
php -l app/Controllers/ReportCustomTemplateController.php
node --check public/assets/js/unidades/template-personalizado.js
```
