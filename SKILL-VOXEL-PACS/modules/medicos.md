# Módulo — Médicos (Cadastros → Médicos)

## Propósito
CRUD de médicos do tenant (`/medicos`) — cadastro, edição, vinculação a conta de usuário/unidades DICOM, e configuração do VOXEL Copilot (modo de laudário externo + token de integração). É a **implementação de referência de CRUD do projeto**: Controller fino, sem SQL/regra de negócio, delegando tudo a Service/Repository — padrão a copiar para outros módulos que ainda fazem PDO direto no Controller (ex. `EstudosController`, ver `modules/worklist-estudos.md`).

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/MedicosController.php` | Fino: só orquestra Service + monta dados pra view. `tenantId()` é o guard central (nunca opera sem tenant). |
| `app/Services/MedicoService.php` | Toda regra de negócio: `validar()` (nome/CRM/UF/e-mail/telefone/CEP), `cadastrar()`, `atualizar()`, `dadosFormulario()` (monta `usuarios`/`unidades`/`unidadesMarcadas` pra view). |
| `app/Repositories/MedicoRepository.php` | Toda SQL (PDO/prepared statements). |
| `app/Views/medicos/form.php` | View compartilhada entre `/medicos/create` (novo) e `/medicos/{id}/edit` (editar) — ver seção de abas abaixo. |
| `app/Views/medicos/index.php` | Listagem. |

## Rotas
```
GET  /medicos                          index
GET  /medicos/create                   create (form vazio)
POST /medicos                          store
GET  /medicos/{id}/edit                edit (form preenchido)
POST /medicos/{id}/update              update
POST /medicos/{id}/toggle              toggleStatus (soft delete/reativar)
GET  /api/medicos/cep/{cep}            buscarCep (proxy ViaCEP)
POST /api/medicos/{id}/copilot-token   gerar/regenerar/revogar token Copilot (AJAX)
POST /api/medicos/{id}/workspace-laudo toggle Laudário Interno ↔ VOXEL Copilot (AJAX)
```

## Fluxo de validação/erro (POST-redirect-GET)
`MedicoService::validar()` retorna um **array plano de mensagens** (`$erros`, sem chave por campo) para: Nome (obrigatório, 3-200 chars), CRM+UF CRM (formato + duplicidade), E-mail (formato + duplicidade), Telefone (formato), CEP (formato). Em caso de erro, o Controller grava `$_SESSION['form_erros']` + `$_SESSION['form_dados']` (o `$_POST` bruto) e redireciona de volta pro form (`create()`/`edit()` leem e limpam a sessão). A view mostra um alerta no topo (`#alertErros`, lista todas as mensagens) e destaca com `.is-invalid` só o campo `nome` (heurística: `!empty($erros) && empty($val('nome'))` — os demais campos validados não recebem highlight individual, só entram na lista do alerta).

## Estrutura de abas da tela de edição (2026-08-06)

`medicos/form.php` é **um único formulário/POST** (nada de wizard/multi-step) reorganizado visualmente em 3 abas — **só em modo edição** (`$isEdit === true`, ou seja, `/medicos/{id}/edit`). Em `/medicos/create` a tela continua exatamente como antes (Dados Pessoais → Contato → Endereço → Vinculação em sequência, sem abas), porque não existe médico ainda para gerar Token Copilot ou anexar Máscaras.

- **Mecanismo de tabs**: Bootstrap 5 nativo (`data-bs-toggle="tab"`, `.tab-pane`/`.nav`), o único padrão de Tabs já usado no projeto (ver `app/Views/platform/negocios/form.php:17-38`). O JS bundle do Bootstrap já é carregado no layout `pacs` (`app/Views/layout/pacs_footer.php`), layout padrão de `Controller::view()` (`app/Core/Controller.php:4`) — nenhuma lib nova. O **skin visual é novo** (`.medico-tabs-bar`/`.medico-tab-btn`/`.medico-tab-badge`, no `<style>` de `form.php`), consistente com o tema escuro `.medico-*` já usado no resto do arquivo — não reaproveitei o skin claro do Bootstrap puro de `negocios/form.php`.
- **Aba "Dados do Médico"** (`#aba-dados`, padrão/ativa): Seções Dados Pessoais + Contato + Endereço + Vinculação, HTML idêntico ao que já existia (mesmos `name`/`id`/`required`/máscaras JS), só realocado para dentro do `tab-pane`.
- **Aba "Copilot do Laudo"** (`#aba-copilot`): Seções "VOXEL Copilot — Modo de Laudário" (toggle) + "Token Copilot" (gerar/regenerar/revogar), JS inalterado (`toggleWorkspaceLaudo`, `gerarTokenCopilot`, `regenerarTokenCopilot`, `revogarTokenCopilot`).
- **Aba "Máscaras"** (`#aba-mascaras`): **placeholder puro**, sem backend — estado vazio (ícone + "Nenhuma máscara importada ainda"), base para uma futura funcionalidade de importar máscaras/modelos de laudo do médico. Nenhuma rota/tabela criada.
- **Parâmetro opcional `?aba=dados|copilot|mascaras`**: abre a tela direto na aba pedida (`$abaAtiva` em `form.php`, valor inválido/ausente cai em `dados`). Só um `if` na hora de montar as classes `active`/`show`, sem mudança de rota.
- **Erro de validação em campo de aba não ativa**: o alerta `#alertErros` continua fora das abas (sempre visível, nenhuma mensagem escondida). Além disso, `ativarAbaComErro()` (JS, em `form.php`) procura por `.is-invalid` dentro de qualquer `.tab-pane` — no carregamento da página (erro vindo do servidor) e no submit client-side do campo `nome` — e ativa a aba correspondente via `bootstrap.Tab`, acendendo um badge (ponto vermelho) no botão daquela aba. Como hoje **todos** os campos validados (`nome`, `crm`, `crm_uf`, `email`, `telefone`, `cep`) vivem na Aba 1, na prática isso sempre resolve pra aba já ativa por padrão — mas o mecanismo é genérico (não hardcoded pra Aba 1), então continua correto se um campo de outra aba ganhar validação server-side no futuro.
- **Uma única `<form>`**: todas as 3 `tab-pane` ficam dentro do mesmo `<form method="POST">` — trocar de aba só alterna classes CSS (Bootstrap), não desmonta o DOM nem perde valor digitado.

## Achado pendente (não corrigido, fora de escopo desta tarefa)
`lang/{pt_BR,en,es}.php` já tinham um namespace `medicos.form.*` parcialmente preenchido (`campo_crm_uf`, `campo_cep`, `cep_buscando`, etc.) desde antes — só que **a view nunca chamou `t()`** pra esses campos (labels hardcoded em PT-BR). Mesmo padrão de "chaves órfãs" encontrado no módulo de download em lote (ver `modules/worklist-estudos.md`). As novas chaves das abas (`aba_dados`, `aba_copilot`, `aba_mascaras`, `mascaras_vazio_titulo`, `mascaras_vazio_texto`) foram adicionadas nesse mesmo namespace e **são** usadas via `t()` — só nos pontos novos, não retrofitei o resto do form.

## Última análise
2026-08-06
