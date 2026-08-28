---
name: integracao-philips
description: "Integração DICOM segura com Philips/Carestream para C-ECHO, C-STORE e devolutiva de laudos em Encapsulated PDF via gateway WireGuard. Use para configurar, validar, diagnosticar ou operar destinos Philips/Carestream no VOXEL PACS, especialmente quando houver AE case-sensitive, conflito de Patient ID/Issuer, fila de delivery ou ponte DICOM restrita."
---

# Integração Philips/Carestream

Use esta habilidade para operar devolutiva DICOM do VOXEL PACS para receptores Philips/Carestream. Trate todo C-STORE como transmissão clínica externa e mantenha a integração **tenant-scoped**, com gateway DICOM como única borda e WireGuard como caminho preferencial.

> Não use esta habilidade para envio de imagens de entrada ao VOXEL. O sentido deste fluxo é **VOXEL → receptor Philips/Carestream**.

## Ordem obrigatória

Execute sempre: **autorizar → identificar destino → isolar job → validar sem dados → transmitir uma vez → confirmar → desabilitar**.

Leia `references/philips-carestream-dicom-return.md` antes de alterar um destino, interpretar logs ou iniciar uma retransmissão.

| Etapa | Exigência mínima | Pare se |
|---|---|---|
| Autorização | Relatório/job de teste explicitamente autorizado pelo usuário | O escopo for genérico ou envolver múltiplos laudos |
| Escopo | Tenant, relatório, job interno, destino e estado confirmados por consulta tenant-scoped | Houver mais de um job elegível ou o job não corresponder ao laudo |
| Rede | Rota do gateway pelo peer WireGuard, origem esperada e listener DICOM alcançável | Houver rota pública/fallback ou peer inativo |
| Associação | C-ECHO com Calling AE e Called AE exatos | A associação for recusada |
| Artefato | PDF Encapsulated DICOM com Patient ID/Issuer validados | As tags de identidade não forem confirmadas |
| Entrega | Um worker filtrado por `job_id` e política allowlisted | O worker geral puder consumir outros jobs |
| Encerramento | Status técnico e estado remoto confirmados; ponte parada/desabilitada | Houver ambiguidade sobre aceite remoto |

## Parâmetros por destino

Cadastre e valide cada destino no control-plane; não deixe os valores em scripts, repositório, logs ou documentação pública.

| Campo | Regra |
|---|---|
| Tenant e servidor PACS | Obrigatórios em toda consulta e no vínculo de destino |
| Peer/rede | Use VPN privada por tenant quando o perfil for `vpn_only` |
| IP e porta do receptor | Use o endpoint roteado pelo túnel; não use IP público como fallback sem nova aprovação |
| Calling AE | AE do gateway de saída permitido pelo receptor |
| Called AE | Preserve exatamente caixa alta/baixa confirmada no receptor |
| TLS | Não rebaixe TLS silenciosamente; se exigido, homologue certificados antes do C-STORE |
| SOP Class | Autorize somente **Encapsulated PDF Storage** no perfil inicial |
| Issuer de saída | Configure por receptor quando a identidade esperada no PACS remoto diferir do issuer de entrada |
| Retentativas | Para teste: uma tentativa explícita; não habilite retry automático |

## Arquitetura obrigatória de saída

```text
API/worker VOXEL (rede privada)
  └─ canal mTLS + HMAC, job allowlisted
       └─ gateway DICOM VOXEL
            └─ wg0 / peer WireGuard
                 └─ Philips/Carestream SCP
```

A ponte de saída deve aceitar somente o job, tenant, destino, AE, SOP Class, limite de tamanho e hash autorizados. Não exponha listener público, não aceite caminho arbitrário e não reutilize o gateway de entrada sem política de saída dedicada.

## Identidade DICOM para Encapsulated PDF

Grave os atributos de paciente em tags distintas no objeto DICOM final:

| Tag | Atributo | Regra |
|---|---|---|
| `(0010,0020)` | Patient ID | Envie somente o identificador base; não concatene issuer |
| `(0010,0021)` | Issuer of Patient ID | Envie o issuer exigido pelo receptor de destino |
| `(0020,000D)` | Study Instance UID | Preserve o Study UID associado ao laudo autorizado |
| `Encapsulated PDF Storage` | SOP Class | Use somente se homologado pelo receptor |

Ao usar `pdf2dcm --study-from`, aplique o issuer novamente no objeto final com `--key 0010,0021=<issuer>`. O modo `--study-from` pode não preservar essa tag, mesmo que ela esteja presente no DICOM de metadados de entrada.

Valide a transformação com PDF e metadados **sintéticos** antes da primeira transmissão clínica. Nunca imprima valores de Patient ID, UID, nome, PDF ou tags completas em logs de automação.

## Isolamento da fila e transmissão

1. Confirme que o worker geral está inativo.
2. Conte jobs por estado de forma agregada; não liste pacientes/destinos fora do escopo.
3. Vincule o relatório autorizado a um destino de homologação/controle com `disparar_na_liberacao=0`.
4. Use uma ponte configurada para um único `job_id` e mantenha o job anterior bloqueado.
5. Execute C-ECHO imediatamente antes do C-STORE.
6. Execute o worker uma única vez com filtro explícito de job e encerre-o após o resultado.
7. Registre somente: job interno, status, tentativa, timestamp, hash truncado, C-ECHO/C-STORE e referência técnica.
8. Em sucesso, marque somente o job autorizado como `delivered`; em erro, mantenha-o terminal e não repita automaticamente.
9. Pare e desabilite a ponte. Preserve backups e a trilha técnica sanitizada.

## Diagnóstico Philips/Carestream

Consulte `references/philips-carestream-dicom-return.md` para padrões de log e decisão. Procure primeiro nos logs de servidor DICOM/SVDSER por:

```text
VOXEL_GW_A
ClientIP(<ip-do-gateway-vpn>)
CStoreResponse
Fail-to-Store
STORE_STUDY_CONFLICT
Rejected image
SCL_STATUS_REFUSED
```

| Evidência | Interpretação | Ação segura |
|---|---|---|
| Called AE não localizado | AE incorreto, incluindo capitalização | Corrigir o Called AE; fazer somente C-ECHO |
| C-ECHO aceito e C-STORE recusado | Associação/rede corretas; investigar objeto/regras do receptor | Não repetir até obter o log do C-STORE |
| `STORE_STUDY_CONFLICT` | Identidade do estudo/Patient ID/Issuer diverge da base remota | Mapear Patient ID e Issuer como tags distintas; validar artefato sem transmitir |
| Aviso de SOP Class previamente definida | Indica configuração duplicada, não necessariamente falha de armazenamento | Não tratar como causa sem C-STORE status de erro |
| C-STORE sucesso | Receptor confirmou resposta DICOM bem-sucedida | Confirmar outbox, job e desligar a ponte |

## Segurança, rollback e publicação

- Nunca exponha chaves VPN, certificados, HMAC, tokens, credenciais Orthanc, cookies, PDF ou PHI.
- Faça backup restrito de runtime/configuração e das tabelas afetadas antes de alterar destino, job ou worker.
- Trabalhe em clone limpo. Não use `git pull`, `git reset` ou `git clean` no runtime de produção.
- Publique commits sanitizados; aplique somente patch/fast-forward com validação e backup.
- Para rollback, mantenha a ponte desabilitada, restaure o backup do destino/job e não reative o worker geral.
- Solicite nova autorização antes de ampliar allowlist, habilitar retry automático, alterar o receptor ou promover o padrão a operação contínua multi-tenant.
