# Servidor PACS multi-servidor: modelo N:N, roteamento por InstitutionName, sync incremental

**Data:** 2026-07-27

## Contexto — o que existia antes desta mudança

Antes desta tarefa, `bi_pacs_servidor` era uma tabela com **uma única linha fixa (`id=1`)** — não existia
conceito de "vários servidores Orthanc" em lugar nenhum do código (`servidor_id = 1` estava hardcoded em
mais de 15 pontos: `ServidorPacsController`, `EstudosController`, `EstudosRepository`, `ExamesPacsController`,
`ReportsController`, `DesktopViewerService`). Apesar disso, multi-negócio-por-servidor **já acontecia de fato
em produção**: os tenants INOVA e ORIX TELERRADIOLOGIA já dividem o mesmo Orthanc físico via duas tabelas de
"de-para" (`bi_pacs_roteamento` e `bi_negocio_institution_names`), sem nenhuma detecção de ambiguidade — a
migration `2026-07-26_migrate_institution_names.sql` já documenta um caso real de estudos que foram parar no
tenant errado por causa disso.

Havia ainda um sistema legado morto (`bi_orthanc_servidores` / `ServidorController` / rota `/servidor`,
per-tenant, migration `2026-05-11`) sem nenhuma rota ativa — não foi tocado nesta tarefa, fica documentado aqui
como referência histórica caso alguém o encontre.

## 1. Schema N:N final

### `bi_pacs_servidor` (existente, virou multi-linha de verdade)
Nenhuma mudança de PK — apenas novas colunas para suportar sincronização incremental por servidor:
`changes_cursor` (cursor do `GET /changes` do Orthanc), `sync_lock_at` (lock de concorrência do ciclo
automático), `sync_estudos_ultimo_ciclo` / `sync_nao_identificados_ultimo_ciclo` / `sync_conflitos_ultimo_ciclo`
(resumo do último ciclo, exibido no dashboard). A senha (`senha`) passou a ser gravada criptografada
(ver seção 4).

### `bi_negocio_servidor_pacs` (novo — pivot N:N)
```
id, tenant_id (FK bi_tenants), servidor_id (FK bi_pacs_servidor), ativo, criado_em, criado_por (FK bi_users)
UNIQUE (tenant_id, servidor_id)
```
Um servidor pode ter 0, 1 ou N negócios associados; um negócio pode ter 0, 1 ou N servidores associados.
As credenciais de acesso ao Orthanc continuam sendo propriedade exclusiva do **servidor**, nunca do vínculo.

### `bi_pacs_estudos` (existente, ganhou colunas de roteamento + dump completo)
`roteamento_status` (`roteado`/`nao_identificado`/`conflito`), `roteamento_candidatos` (JSON com os tenants
candidatos, só preenchido em conflito), `roteamento_resolvido_por` / `roteamento_resolvido_em` (auditoria da
resolução manual), `dicom_tags_completas` (JSON — ver seção 3).

**Decisão deliberada: não foi criada uma tabela-fila separada para "não identificados"/"conflitos".** O
schema já tinha **duas versões conflitantes** de `bi_institution_name_pendentes` em migrations diferentes,
nenhuma usada em código — exatamente o tipo de "fila esquecida" que o enunciado pede para evitar. Em vez
disso, o estado vive na própria `bi_pacs_estudos` (a mesma tabela que já é a UI principal), e a tela de
estudos apenas filtra por esse status. Um estudo nunca fica fora da tabela onde o Platform Admin já olha.

### Fontes independentes: InstitutionName e Issuer por modalidade
`InstitutionName (0008,0080)` continua sendo administrado em `bi_negocio_institution_names`, que representa
as origens institucionais autorizadas de cada negócio. Ele é normalizado para comparação sem distinção de
caixa, espaço e acento, mas seu valor original segue preservado como evidência DICOM.

`Issuer of Patient ID (0010,0021)` **não é vinculado a InstitutionName**. A migration
`2026-08-25_issuer_por_modalidade_postgresql.sql` cria `bi_tenant_issuer_modalidades`, cuja chave é
`tenant_id + issuer_of_patient_id_normalized + modalidade`. Cada regra informa quais modalidades DICOM um
Issuer pode receber para um negócio; nenhuma linha contém InstitutionName. Valores legados de Issuer nas
Unidades não são migrados automaticamente, porque não trazem a modalidade necessária para uma autorização
segura.

Enquanto não houver regra ativa para uma modalidade, o roteamento permanece compatível usando InstitutionName.
Quando existe ao menos uma regra de Issuer para a modalidade recebida, Issuer torna-se obrigatório e apenas
valores explicitamente autorizados são elegíveis. Assim, cadastrar o primeiro Issuer de CT, por exemplo, não
altera MR, US ou demais modalidades até que elas também recebam uma política explícita.

## 2. Algoritmo de roteamento (`App\Services\PacsRoutingService::resolveTenant`)

Entrada: `servidor_id`, `InstitutionName (0008,0080)`, `Issuer of Patient ID (0010,0021)` e
`ModalitiesInStudy (0008,0061)` do estudo importado.

1. Busca os negócios ativos associados àquele servidor (`bi_negocio_servidor_pacs`). Sem associação, o estudo
   permanece `nao_identificado`.
2. Busca candidatos por InstitutionName exclusivamente em `bi_negocio_institution_names`; essa busca é
   independente do Issuer e pode retornar zero, um ou vários negócios.
3. Normaliza todas as modalidades do estudo. A existência de qualquer política ativa em
   `bi_tenant_issuer_modalidades` para uma dessas modalidades ativa o controle de Issuer para o estudo.
4. Com política ativa, Issuer ausente ou não autorizado resulta em `nao_identificado`, sem fallback para
   InstitutionName. Um Issuer autorizado por uma modalidade candidata a um único negócio resulta em `roteado`,
   mesmo quando InstitutionName estiver ausente.
5. Quando InstitutionName e Issuer autorizados estão presentes, os dois conjuntos de candidatos devem
   convergir. Interseção vazia ou mais de um negócio resulta em `conflito`; o sistema nunca escolhe um destino
   silenciosamente.
6. Sem política ativa para as modalidades do estudo, InstitutionName mantém o fallback compatível. Se ele for
   ambíguo, o resultado continua `conflito`; se não houver cadastro, `nao_identificado`.

`Issuer of Patient ID` identifica a autoridade que emitiu o identificador administrativo do paciente. Ele não
é `Issuer of Admission ID`, não é o campo `iss` de um token e não deve ser inferido a partir de texto livre.
O Orthanc fornece a tag preferencialmente por `shared-tags?simplify`; se ela não existir ali, o cliente busca
somente a primeira instância do estudo como fallback controlado. A origem e a estrutura da tag permanecem no
dump DICOM, enquanto a coluna estruturada permite filtro e roteamento sem varrer JSON.

`Issuer of Patient ID` identifica a autoridade que emitiu o identificador administrativo do paciente. Ele não
é `Issuer of Admission ID`, não é o campo `iss` de um token e não deve ser inferido a partir de texto livre.
O Orthanc fornece a tag preferencialmente por `shared-tags?simplify`; se ela não existir ali, o cliente busca
somente a primeira instância do estudo como fallback controlado. A origem e a estrutura da tag permanecem no
dump DICOM, enquanto a coluna estruturada permite filtro e roteamento sem varrer JSON.

Uma resolução manual (`ServidorPacsController::resolverEstudo`) verifica antes se o negócio está associado ao
servidor PACS, grava `roteamento_resolvido_por`/`_em` e registra o evento `roteamento.manual_resolvido` na
auditoria sem conteúdo clínico. O roteamento automático, a Worklist, Exames PACS e download em lote usam
`tenant_id` como escopo final de leitura: InstitutionName e Issuer definem a entrada, mas jamais substituem a
barreira multi-tenant de saída.
A partir daí, `PacsSyncService::upsertEstudo()` **nunca mais sobrescreve** o roteamento daquele estudo em
ciclos futuros (só atualiza metadados/tags) — validado explicitamente (ver seção de testes).

## 3. Tags DICOM completas: JSON, não tabela EAV

Decisão: coluna `bi_pacs_estudos.dicom_tags_completas` (LONGTEXT/JSON), alimentada por
`GET /studies/{id}/shared-tags` (mais completo que o `MainDicomTags` usado nas colunas estruturadas — inclui
qualquer tag compartilhada por todas as instâncias do estudo, não só o subconjunto que o Orthanc indexa).

`Issuer of Patient ID` é uma exceção intencional à regra "somente JSON": ele também é preservado em
`bi_pacs_estudos.issuer_of_patient_id` e na chave normalizada correspondente, com índice por servidor e
InstitutionName. Isso é necessário para a decisão determinística de roteamento e para o filtro administrativo
da tela de Estudos DICOM.

**Por que não uma tabela genérica `(estudo_id, tag_group, tag_element, tag_name, tag_value)`:**
- As ~120 tags mais usadas em filtro/ordenação do worklist já são colunas estruturadas dedicadas — isso não
  muda, continua sendo o caminho de performance.
- O caso de uso do dump completo é **"ver tudo de um estudo específico"** (tela de detalhe/modal), não
  "buscar estudos por uma tag DICOM arbitrária qualquer" — não há requisito nem indício de uso para o
  segundo caso hoje.
- Uma tabela EAV com uma linha por tag por estudo multiplicaria o volume de linhas por ~80-150x em relação a
  `bi_pacs_estudos` (validado no teste: um único estudo já gera dezenas de tags mesmo num fixture mínimo de
  teste) sem nenhum ganho de consulta, já que a busca é sempre "todas as tags de 1 estudo", um único
  `SELECT` indexado por `id` de qualquer forma.
- Se um dia surgir a necessidade real de busca por tag específica, JSON em MySQL 5.7+/MariaDB ainda permite
  `JSON_EXTRACT`/`JSON_CONTAINS` — não fecha a porta, só não paga o custo de armazenamento antecipadamente.

O endpoint `GET /platform/servidor-pacs/estudos/{id}/tags` busca esse JSON sob demanda (lazy, só quando o
Platform Admin abre o modal "Ver tags"), para não inflar a listagem paginada com o dump completo de até 50
estudos por vez.

## 4. Credenciais criptografadas (`App\Core\Crypto`)

Não existia nenhum helper de criptografia no projeto — todo campo "criptografado" (`bi_pacs_servidor.senha`,
`bi_orthanc_servidores.senha`, `business_webhook_hub_configs.jwt_secret`) tinha só um comentário de coluna
aspiracional, valor real sempre em texto puro. `App\Core\Crypto` (AES-256-GCM via `openssl_encrypt`, chave
derivada por HKDF do `APP_SECRET` já existente em `.env`, nunca lido antes) resolve isso para o servidor PACS.
Migração suave: valores novos gravados com prefixo `enc:v1:`; leitura decripta só se tiver esse prefixo, senão
trata como texto legado — sem quebrar o servidor Hetzner já configurado em produção, sem script de migração
arriscado.

## 5. Sincronização automática a cada 2 minutos

`App\Services\PacsSyncService::executarParaTodosServidores()`, disparado por
`GET /api/servidor-pacs/sync-robo?token=...` — mesmo padrão já validado do robô de Regras de SLA
(`SlaRoboController`): endpoint público, token comparado com `hash_equals()`, chamado por um cron externo
(cron-job.org), já que esta hospedagem compartilhada não tem crontab real.

Por ciclo: itera todos os servidores `ativo=1`; por servidor, tenta obter um lock (`sync_lock_at`, evita 2
ciclos simultâneos no mesmo servidor — expira sozinho após 10 minutos, cobre travamento por crash); faz
`ping()` e, se falhar, marca offline e **passa para o próximo servidor sem lançar exceção** (isolamento
validado — ver testes); pagina `GET /changes?since=cursor` até `Done=true`, salvando o cursor a cada página
processada (idempotência: um crash no meio do ciclo não reprocessa o que já avançou); para cada
`NewStudy`/`StableStudy` de tipo `Study`, busca o estudo completo + shared-tags, aplica
`PacsRoutingService::resolveTenant()` e faz upsert em `bi_pacs_estudos`.

O botão manual "Sincronizar agora" (`ServidorPacsController::sincronizar`) continua existindo como
complemento síncrono (full-resync via `/studies`, não incremental) — útil para forçar uma resincronização
completa fora do ciclo de 2 minutos; ambos os caminhos compartilham a mesma lógica de upsert/roteamento
(`PacsSyncService::upsertEstudo`), para nunca haver dois comportamentos de roteamento divergentes.

## Validação executada

Sem acesso a Docker neste ambiente para subir um Orthanc real, a validação foi feita com um Orthanc "fake"
(servidor HTTP local em PHP servindo fixtures JSON para `/system`, `/changes`, `/studies/{id}`,
`/studies/{id}/series`, `/studies/{id}/shared-tags`) — cobre exatamente a mesma superfície de API que
`OrthancService` consome, validando a lógica real de ponta a ponta:

| Cenário pedido | Resultado observado |
|---|---|
| Servidor com 2 negócios, cada um com InstitutionName próprio | 1 servidor, 1 chamada a `/changes` no log; `CLINICA UM`→tenant 1 roteado corretamente |
| InstitutionName sem unidade cadastrada em nenhum negócio associado | `roteamento_status=nao_identificado`, `tenant_id=NULL`, aparece na fila |
| 2 negócios com a mesma InstitutionName no mesmo servidor | `roteamento_status=conflito`, `roteamento_candidatos` com os 2 nomes |
| Negócio com 2 servidores diferentes | Cursores evoluíram independentemente (`changes_cursor=3` e `=1`); ambos sincronizados na mesma chamada |
| Servidor offline (URL inacessível) | Isolado (`status=offline`, erro de conexão), demais servidores do mesmo ciclo processados normalmente |
| Rodar o ciclo 2x seguidas | 2ª rodada: `novos=0` em todos os servidores — cursor evitou reprocessamento |
| Resolução manual de conflito, depois novo evento do mesmo estudo no Orthanc | `tenant_id`/`roteamento_status` preservados da resolução manual; só metadados/tags atualizados |
| Isolamento multi-tenant | `WHERE tenant_id=1` e `WHERE tenant_id=2` não se sobrepõem; estudo `nao_identificado` não aparece em nenhum dos dois |
| Tags DICOM completas | `dicom_tags_completas` bate exatamente com o `shared-tags` retornado pelo Orthanc fake |
| Matriz independente | Tabela, chave única e quatro índices criados no schema ativo; nenhuma regra foi pré-semeada, portanto não houve alteração automática de rota existente |
| Issuer autorizado por CT sem InstitutionName | Diagnóstico transacional retornou `roteado` pelo critério `issuer_modalidade` e fez rollback ao final |
| Issuer desconhecido ou ausente em CT controlado | Diagnóstico transacional retornou `nao_identificado`; não houve fallback para InstitutionName |
| Compatibilidade institucional | Todas as Unidades DICOM ativas possuíam InstitutionName canônico ativo antes da ativação do novo motor |
| Interface operacional | Formulário de Negócios validado no navegador: InstitutionNames e regras de Issuer por modalidade aparecem em seções independentes; nenhuma regra foi gravada no teste visual |

## Referências técnicas

- [DICOM PS3.3 — Patient Identification and Issuer of Patient ID](https://dicom.nema.org/medical/dicom/current/output/chtml/part03/sect_10.15.html)
- [DICOM Data Dictionary — (0010,0021) Issuer of Patient ID](https://dicom.nema.org/medical/dicom/current/output/chtml/part06/chapter_6.html)
- [Orthanc Book — DICOM guide](https://orthanc.uclouvain.be/book/dicom-guide.html)

## Legado — não tocado nesta tarefa

- `bi_orthanc_servidores` / `ServidorController` / rota `/servidor`: sistema per-tenant anterior ao modelo
  global, sem rota ativa hoje. Não migrado, não removido.
- `bi_pacs_roteamento` / tela `/platform/servidor-pacs/roteamento`: de-para manual antigo, mantido apenas
  como registro de compatibilidade. Seus botões não alteram mais estudos automaticamente por InstitutionName;
  decisões retroativas usam a resolução manual auditável.
- Campos legados de Issuer em `bi_tenant_unidades_dicom`: preservados para compatibilidade histórica, mas não
  participam do motor novo. A autorização efetiva está em `bi_tenant_issuer_modalidades`.
- `bi_negocio_institution_names`: continua sendo a fonte de InstitutionName usada pelo motor novo e permanece
  independente da matriz de Issuer.
- `bi_institution_name_pendentes` (as duas versões): permanecem no schema, continuam não usadas por nenhum
  código — decisão explícita de não ressuscitá-las (ver seção 1).
