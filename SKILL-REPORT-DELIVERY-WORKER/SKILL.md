---
name: report-delivery-worker
description: "Operação, diagnóstico, validação e evolução segura do Worker do VOXEL Report Delivery Hub para laudos DICOM Encapsulated PDF. Use ao liberar, revisar, enfileirar, transmitir, auditar, automatizar, pausar ou corrigir devolutivas de laudo entre o VOXEL PACS e um destino DICOM, especialmente quando houver snapshots PDF imutáveis, isolamento tenant-scoped, gateway privado, WireGuard, C-ECHO/C-STORE, filas, retries, estados de entrega ou kill switches."
---

# Worker do VOXEL Report Delivery Hub

Use esta habilidade para trabalhar no fluxo de devolutiva de laudos por **DICOM Encapsulated PDF**. Trate todo C-STORE como transmissão clínica externa e preserve o fluxo:

```text
liberação clínica → snapshot PDF imutável → outbox tenant-scoped
→ job restrito → worker → bridge mTLS/HMAC privada → WireGuard → receptor DICOM
```

Leia `references/worker-contract.md` antes de alterar worker, job, destino, bridge ou unidade persistente. A fonte de verdade é o código, migration e configuração operacional do ambiente ativo.

## Limites inegociáveis

| Controle | Regra |
|---|---|
| Dados clínicos | Não imprima, copie ou anexe PDF, nome, Patient ID, UID, hash completo, AE, host, porta, certificado, HMAC ou chave VPN. Use somente IDs internos e estados técnicos sanitizados. |
| Autorização | Solicite autorização explícita antes de cada transmissão clínica unitária, novo receptor, alteração de allowlist, retry automático ou promoção de ambiente. |
| Tenant | Exija igualdade de tenant entre sessão/job, outbox, destino, estudo e servidor PACS. Não use rota global, fallback de tenant ou destino sem vínculo explícito. |
| Origem | Exija `servidor_pacs_id` no destino de produção e igualdade com a origem do estudo. Faça matching por Issuer; use InstitutionName apenas quando issuer estiver ausente, conforme contrato da aplicação. |
| Rede | O gateway é a única borda DICOM de saída. Exija listener privado, mTLS/HMAC API→gateway e rota WireGuard do tenant. Não use rota pública como fallback. |
| Artefato | O worker lê somente snapshot binário imutável registrado na liberação. Nunca regenere PDF clínico simplificado para entrega. |
| Histórico | Não confunda versões antigas de outbox com a entrega atual. Preserve ledger ORIGINAL/REV e mostre no painel somente a maior versão por laudo. |
| Runtime/Git | Trabalhe em clone limpo. No runtime, nunca use `git pull`, `git reset` ou `git clean`; use patch cirúrgico, backup individual, manifest e validação. |

## Localizar e entender antes de alterar

1. Leia `docs/REPORT_DELIVERY_HUB.md`, a migration mais recente de destinos e `tests/report_delivery_production_routing_contract.php`.
2. Revise `ReportDeliveryOutboxService`, `ReportDeliveryWorkerRepository`, `ReportDeliveryArtifactService`, `ReportDeliveryGatewayBridgeClient`, `bin/report_delivery_worker.php` e `bridge_server.py`.
3. Liste impacto em autorização, destino, job/outbox, snapshot, gateway, WireGuard, serviço persistente, logs e rollback.
4. Use consultas tenant-scoped e agregadas. Em diagnóstico, produza estados, contagens, versões e IDs internos; nunca atributos clínicos ou parâmetros de conexão.

## Pré-validação comum

Pare no primeiro desvio.

| Camada | Evidência mínima |
|---|---|
| Snapshot | A outbox da versão atual possui snapshot binário privado, referência, hash e tamanho válidos. Não abra o PDF. |
| Identidade | Patient ID e Issuer são obtidos separadamente; o worker reaplica Issuer no DICOM final. |
| Destino | Ambiente, transporte `dicom_pdf`, tenant, `servidor_pacs_id`, Issuer e estado do destino são compatíveis. |
| Bridge | URL privada fixa, modo correto, autenticação mTLS/HMAC e policy root-only do mesmo tenant/destino. |
| Gateway | Bridge sem exposição pública, container DICOM ativo, WireGuard com handshake observado e rota ao receptor pelo túnel. |
| Exclusividade | Nenhum job fora do escopo pode ser reclamado. Preserve jobs bloqueados, dead-letter, históricos e de outros tenants. |

## Encapsulated PDF e compatibilidade

Use **Encapsulated PDF Storage** homologado e preserve o estudo de origem. Escreva explicitamente:

| Tag | Regra |
|---|---|
| `(0010,0020)` — Patient ID | Use somente o identificador base; não concatene Issuer. |
| `(0010,0021)` — Issuer of Patient ID | Reaplique após `pdf2dcm --study-from`; esse modo pode descartá-lo. |
| `(0008,103E)` — Series Description | Defina descrição legível no worker para evitar apresentação como `ND`. |
| `(0020,000D)` — Study Instance UID | Preserve o UID associado ao laudo autorizado. |

Valide artefatos sintéticos antes de um novo perfil de receptor. Nunca exponha o conteúdo do PDF durante a operação.

## Piloto controlado

Use somente depois de autorização explícita para **um** laudo interno e **um** destino.

1. Confirme que worker geral e bridge estão inativos e que o destino está desativado.
2. Valide snapshot, versão, tenant, servidor PACS, Issuer e unicidade do job sem abrir artefato.
3. Crie ou reatribua somente o job autorizado, com uma tentativa e trava por job.
4. Configure policy temporária root-only para job, tenant e destino específicos.
5. Inicie a bridge temporariamente, execute apenas o worker com filtro explícito e exija C-ECHO antes do C-STORE.
6. Registre apenas job interno, status, tentativa, timestamp, C-ECHO/C-STORE e referência técnica sanitizada.
7. Pare e desabilite a bridge, restaure a policy anterior e retorne o destino ao estado desativado.
8. Não processe jobs pendentes, históricos, de homologação ou de outros tenants.

## Automação controlada

Habilite somente após piloto aceito e autorização separada. A unidade do worker recebe ambiente protegido pelo systemd; o bootstrap precisa hidratar em `$_ENV` as variáveis injetadas, sem registrá-las.

| Controle | Requisito de ativação |
|---|---|
| API | Feature flag ativada; worker como usuário de serviço sem privilégio; `.env` root-owned e legível somente pelo grupo do serviço. |
| Worker | Processar apenas `automatic_production` da data clínica corrente, destino de produção com disparo ativo, uma tentativa e servidor PACS igual ao do estudo. |
| Gateway | Modo `tenant_destination`, flag de automação ativa, policy restrita a tenant/destino, listener privado e reinício somente em falha. |
| Segurança | mTLS/HMAC, tamanho/hash allowlisted, container DICOM e handshake WireGuard ativos. |
| Exclusões | Não habilitar B/C, destinos sem `servidor_pacs_id`, jobs legados, filas pendentes ou versões antigas. |

Após habilitar, confirme que não há job legado elegível. A ausência de job novo é o resultado esperado até nova liberação clínica compatível.

## Kill switch e rollback

Em incidente, pare primeiro a API e depois o gateway. O procedimento deve desligar feature flag e worker, parar/desabilitar bridge e remover a flag de automação da policy. Não apague snapshots, ledger, job, destino, backup ou auditoria.

```text
API:     /usr/local/sbin/voxelpacs-disable-report-delivery-api
Gateway: /usr/local/sbin/voxelpacs-disable-report-delivery-gateway
```

Depois de interromper, confirme que worker e bridge estão inativos, listener privado ausente e nenhum novo job foi reclamado. Para retomar, trate a causa, refaça o preflight completo e peça nova autorização se o escopo de transmissão mudar.

## Diagnóstico orientado a estados

| Sinal técnico | Ação segura |
|---|---|
| `ND` no receptor | Confirmar escrita de `(0008,103E)`; não reenviar sem nova autorização. |
| Conflito de estudo/identidade | Validar Patient ID/Issuer em tags distintas e reaplicação de `(0010,0021)` sem abrir PDF. |
| C-ECHO aceito e C-STORE recusado | Investigar status técnico do receptor; não repetir automaticamente. |
| Worker encerra em systemd | Validar `EnvironmentFile`, grupo do serviço, bootstrap e `--check`, sem mostrar valores. |
| Listener fora da rede privada | Acionar kill switch; não usar fallback público. |
| Job histórico no painel | Exibir apenas maior `report_version`; conservar histórico no ledger. |

## Validação e publicação

1. Execute `php -l` nos PHP alterados, `python3 -m py_compile` na bridge quando aplicável, `bash -n` nos scripts e `php tests/report_delivery_production_routing_contract.php`.
2. Faça backup individual de runtime, tabelas e policy antes de aplicação cirúrgica.
3. Valide API, gateway e contrato completo sem enviar dados clínicos.
4. Publique somente commits sanitizados. Exclua credenciais, `.env`, policies root-only, PDFs, dumps, logs e caches.
5. Documente mudança, validação, rollback e estado operacional com metadados sanitizados.
