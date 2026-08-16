# Módulo — Assinatura do Médico (aba "Assinatura" em Editar Médico)

## Propósito
4ª aba em `/medicos/{id}/edit` (`app/Views/medicos/form.php`) onde o médico cadastra como assina os próprios laudos — imagem JPG, desenho livre (canvas), ou certificado digital (provisionado, não funcional nesta entrega). A assinatura ativa é usada automaticamente quando o médico clica "Assinar" em `/reports/{uid}` (`ReportService::assinar()`).

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Repositories/MedicoAssinaturaRepository.php` | SQL de `bi_medico_assinaturas` — sempre `tenant_id` + `medico_id` no WHERE. |
| `app/Services/MedicoAssinaturaService.php` | Upload de imagem (mime real via `finfo`, mesmo padrão de `UnidadesController::processarLogoUpload`), assinatura livre (valida magic bytes do PNG), exclusividade (`ativar()`/`desativar()`). |
| `app/Controllers/MedicoAssinaturaController.php` | `listar`/`uploadImagem`/`salvarLivre`/`preview`/`ativar`/`desativar`. Autorização: mesmo escopo do resto de `MedicosController::edit()` (decisão confirmada — não restrito ao médico logado). |
| `app/Views/medicos/form.php` | 4ª aba (`#aba-assinatura`), 3 blocos, canvas via `signature_pad@4.1.7` (CDN, versão fixada). |
| `database/migrations/2026-08-08_bi_medico_assinaturas.sql` | Tabela nova. |
| `database/migrations/2026-08-08_reports_assinatura_visual.sql` | Colunas novas em `reports` (não em `report_signatures` — ver decisão abaixo). |

## Modelo de dados

`bi_medico_assinaturas` (`id, tenant_id, medico_id, tipo, ativa, caminho_arquivo, certificado_provedor, certificado_numero_serie, certificado_validade, criado_em, atualizado_em, ativado_em`) — `tenant_id` denormalizado (mesmo padrão de `bi_medico_unidades`), até 1 linha `ativa=1` por médico entre os 3 `tipo` (`imagem`/`livre`/`certificado`), enforçado no service, não no banco (`UNIQUE` não resolveria a corrida entre desativar-a-atual/ativar-a-nova).

**Arquivos ficam fora de `public/`**: `storage/uploads/assinaturas/{tenant_id}/{medico_id}/{tipo}_{timestamp}.{ext}` — diferente do padrão de `UnidadesController` (logo de unidade vai pra `public/uploads/`, acessível sem autenticação). Decisão deliberada: assinatura tem peso jurídico maior que logo de clínica. Preview e uso servidos só via proxy autenticado (`MedicoAssinaturaController::preview()`, `ReportsController::assinaturaImagem()`).

## Regra de exclusividade (bloqueia, nunca troca automático)

`MedicoAssinaturaService::ativar()`: se outro tipo já está `ativa=1`, **bloqueia** com erro `outra_assinatura_ativa` — pedido original foi explícito: nunca trocar automático/silencioso, o médico precisa desativar a atual primeiro. Reativar o próprio tipo já ativo é idempotente (sucesso, não erro). Testado com repositório fake (12 cenários) — ver histórico da sessão de 2026-08-08.

**Bug que quase entrou em produção**: a primeira versão da implementação desativava as outras automaticamente antes de ativar a nova (exatamente o que foi proibido) — pego e corrigido durante a própria implementação, antes de qualquer validação externa.

## Integração com `ReportService::assinar()` — dois checkpoints distintos

### 4(a) — Injeção da assinatura visual
Antes de assinar, resolve `bi_medicos` do usuário logado (`MedicoRepository::findByUsuarioId()`, novo) → busca a assinatura ativa (`MedicoAssinaturaService::buscarAtiva()`, tipo `imagem` ou `livre` — `certificado` nunca é ativável nesta entrega). **Bloqueia com `medico_sem_assinatura_ativa`** se não houver nenhuma — decisão confirmada, não assina sem representação visual.

**Congelamento**: o arquivo é **copiado** (não referenciado) para `storage/uploads/assinaturas_laudos/{tenant_id}/{report_id}.{ext}` no momento da assinatura (`ReportService::congelarAssinaturaVisual()`). Se o médico trocar a assinatura ativa depois, laudos já assinados continuam mostrando a que foi usada de fato — mesmo princípio de "congelar snapshot" já usado em `bi_sla_regras_execucoes.regra_nome_snapshot`. Falha ao congelar não bloqueia a assinatura (mesmo padrão não-bloqueante do webhook Copilot já existente no método) — hash/nome/CRM continuam sendo o registro legal principal.

### 4(b) — Trava de re-assinatura
Checagem de `$report->situacao` logo após achar o report (mesmo padrão que `salvar()` já usa) — bloqueia com `report_ja_assinado` se já `assinado`/`liberado`. Resolve o gap registrado em `diagnostics/pendencias-conhecidas.md`.

### Onde a imagem aparece
`reports.assinatura_tipo`/`reports.assinatura_caminho_arquivo` (novas colunas) + `reports.assinatura_hash`/`assinatura_crm` (já existiam, nunca eram populadas por nenhum código antes desta tarefa). `ReportRepository::salvarAssinaturaVisual()` grava tudo de uma vez. `app/Views/reports/pdf.php` (bloco `.pdf-signature`, o template realmente renderizado pela rota viva `GET /reports/pdf`) mostra a imagem via `<img src="/reports/assinatura-imagem?report_id=X">` — nova rota, proxy autenticado que **confere tenant_id** (diferente de `pdf()`, que não confere — achado registrado em pendências).

## Achados fora de escopo, registrados em `diagnostics/pendencias-conhecidas.md`
Durante esta tarefa, três problemas pré-existentes e não relacionados foram descobertos e corrigidos (2 críticos, bloqueavam a própria integração 4a/4b) ou apenas registrados:
1. **`Auth::verifyPassword()` não existia** (regressão, corrigido) — sem isso, `assinar()` falhava incondicionalmente na primeira linha, com ou sem banco.
2. **`ReportService::assinar()` não tinha trava de re-assinatura** (corrigido como parte do 4b).
3. **`report_signatures` tem 3 schemas conflitantes entre migrations** (não corrigido — precisa acesso ao banco real pra saber qual está viva). Por isso as colunas novas desta tarefa foram em `reports`, não em `report_signatures`.
4. **`ReportsController::pdf()` não confere tenant_id** (não corrigido, fora de escopo — só descoberto por contraste ao escrever a rota nova que confere corretamente).

## Segurança
- Upload: mime real via `finfo_open(FILEINFO_MIME_TYPE)` (não confia em extensão) para imagem; magic bytes reais do PNG (`\x89PNG\x0D\x0A\x1A\x0A`) para assinatura livre — nenhum dos dois confia no que o navegador declara.
- Autorização: mesmo escopo de `MedicosController::edit()` — qualquer usuário do tenant que já gerencia esse médico, não restrito ao médico logado (decisão confirmada explicitamente, diferente do texto literal do pedido original, que mencionava "só o médico logado").
- Arquivos (perfil e congelados por laudo) fora de `public/`, servidos só via proxy autenticado com checagem de tenant.

## i18n
23 chaves novas em `medicos.form.assinatura_*` (pt-BR/en/es), paridade verificada (322 chaves totais no projeto).

## Validação executada (2026-08-08)
- `php -l` limpo em todos os arquivos tocados.
- Exclusividade: 12 cenários testados com repositório fake (bloqueio efetivo, idempotência, erro claro).
- Upload/canvas: validação de mime/magic-bytes testada isoladamente via reflection (rejeita JPEG falso, PNG falso, arquivo grande; aceita e grava arquivo real fora de `public/`).
- Render completo da tela via PHP CLI: 4 abas sem duplicação, zero IDs duplicados no HTML, `?aba=assinatura` funciona, `/medicos/create` inalterado.
- `Auth::verifyPassword()`: reflection confirma existência; sem usuário logado retorna `false` sem tocar banco; com usuário logado e sem banco lança `\RuntimeException` (capturável), não `\Error` fatal.
- `ReportService::assinar()`: reflection confirma assinatura do método inalterada (Controller chama posicional); `ReportRepository::salvarAssinaturaVisual()` e `MedicoRepository::findByUsuarioId()` confirmados por reflection.

**Não validado** (sem banco de dados disponível neste ambiente, confirmado por tentativa real de conexão recusada): fluxo completo de assinatura ponta a ponta com dado real, upload/desenho reais via navegador, e — mais importante — se `report_signatures` no banco real bate com o schema que `ReportRepository::createSignature()` espera (ver pendência acima). Recomendo fortemente validar isso antes de considerar a funcionalidade pronta para produção.

## Ajustes no fluxo de assinatura de laudo (2026-08-08, sessão seguinte)

### Decisão de negócio — senha/CRM removidos do modal "Assinar Laudo" (não é bug, não reverter)

**Se você é um agente futuro e está pensando em "corrigir" a ausência de senha/CRM no modal de assinatura de laudo: não faça isso.** Foi uma decisão deliberada do Andre, não um esquecimento.

- `app/Views/reports/partials/_modal_assinatura.php` não tem mais campos de CRM nem Senha — é só uma confirmação com dois botões.
- `public/assets/js/reports/reports-signature.js` não coleta nem envia senha/CRM.
- `ReportService::assinar(int $reportId, string $modo): array` — os parâmetros `$senha`/`$crm` foram removidos da assinatura. A chamada a `Auth::verifyPassword()` foi removida de dentro de `assinar()` — **o método em si continua existindo em `Auth.php`** (também usado, hoje só potencialmente, por outros fluxos futuros de reautenticação; confirmado por grep que `assinar()` era seu único chamador real em todo `app/`, `login()` usa `password_verify()` direto).
- CRM é resolvido automaticamente do médico logado (`MedicoRepository::findByUsuarioId()` → `$medico['crm']`), nunca mais digitado manualmente.
- **Justificativa (Andre)**: autenticação passa a ser 100% baseada em sessão. Risco de estação de trabalho compartilhada considerado baixo e conscientemente aceito, dado o uso majoritariamente remoto/teleradiologia da plataforma.
- **Compensação de auditoria**: confirmado que `AuditLogger::log('report.assinar', ...)` já grava `user_id` (via `Auth::user()?->id`, `app/Core/Audit/AuditLogger.php:24`) — nenhuma mudança de código foi necessária para reforçar a rastreabilidade, já existia.

### Dois modos de finalização — "Somente Assinar" vs. "Assinar e Fechar"

`ReportService::assinar()` ganhou o parâmetro `$modo` (`'somente'`|`'fechar'`), condicionando o que já era incondicional:
- **Somente Assinar** (`modo=somente`): `reports.situacao` → `assinado`; `bi_pacs_estudos.situacao` → `assinado` (antes ia direto para `liberado`, sem essa distinção). Permanece na tela, laudo vira somente-leitura.
- **Assinar e Fechar** (`modo=fechar`): igual ao acima, mais `bi_pacs_estudos.situacao` → `liberado`, webhook `CopilotWebhookService::notificarLaudoLiberado()` disparado (antes disparava sempre — agora só quando o estudo de fato é liberado, já que o nome do método é literal), e o frontend navega para `/estudos` (worklist).

Confirmado que essa distinção **não exige nenhuma mudança nos contadores da worklist** — `EstudosController::contadores()` já faz `GROUP BY situacao` e conta `assinado`/`liberado` como buckets independentes desde antes desta tarefa.

### Bloqueio de laudo vazio (novo erro `laudo_vazio`)

Cliente (`reports-signature.js`, antes de abrir o modal) e servidor (`ReportService::assinar()`, logo após a trava de re-assinatura) checam se **todas** as 5 seções (exame/técnica/achados/conclusão/recomendação), sem HTML/whitespace, estão vazias — decisão confirmada com o usuário (bloqueia só se todas vazias, não apenas Conclusão). Mensagem: "Não é possível assinar um laudo em branco. Salve o conteúdo antes de assinar."

### Achados corrigidos na mesma tarefa (fora do pedido original, mas bloqueavam validar tudo acima)

Ver `PENDENCIAS_CONHECIDAS.md`/`diagnostics/pendencias-conhecidas.md` — resumo: `ReportsController::save()`/`::sign()` liam CSRF só do corpo JSON (`$input['csrf']`) e nunca do header `X-CSRF-Token` (único lugar que os dois JS reais mandam o token), e liam o id do laudo como `id` em vez de `report_id` (chave real enviada pelo frontend) — ambos faziam `/reports/save` e `/reports/sign` falharem **sempre** com 403, independente de qualquer outra correção desta sessão. `save()` também lia `secao_exame`/`secao_tecnica`/etc soltos, enquanto o frontend manda um único objeto aninhado `secoes`. Todos corrigidos nesta tarefa, aprovado explicitamente pelo usuário.

## Última análise
2026-08-08

## Assinatura institucional na impressão — 2026-08-15

No template **Moderno Lateral**, a área de assinatura é composta a partir do snapshot já associado ao report: imagem de assinatura congelada, nome do médico signatário, especialidade e CRM do médico. O horário exibido é `reports.assinado_em`, formatado no fuso `America/Sao_Paulo` com a indicação explícita de **horário de Brasília**.

O campo `reports.assinatura_hash` é usado como **token de validação para auditoria**. Ele não é recriado durante a leitura/impressão; apenas é apresentado ao usuário, preservando a rastreabilidade da assinatura registrada no serviço. A identificação institucional usa o negócio vinculado ao tenant, mostrando nome e CNPJ quando disponível.

A migration `2026-08-15_bi_tenants_registro_crm_empresa.sql` acrescenta `bi_tenants.registro_crm_uf` e `bi_tenants.registro_crm_numero`. Ambos são opcionais, são mantidos pelo formulário Platform Negócios e aparecem na assinatura somente quando o número estiver preenchido. A consulta de PDF trata a ausência dessas colunas como migration pendente, registra aviso técnico e continua renderizando o laudo sem interromper a leitura.
