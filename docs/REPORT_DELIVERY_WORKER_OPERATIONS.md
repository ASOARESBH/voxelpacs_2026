# Operação do Worker — VOXEL Report Delivery Hub

**Escopo.** Este documento descreve a operação segura do Worker que devolve laudos por DICOM Encapsulated PDF. Ele cobre o fluxo automatizado, pilotos unitários, evidências permitidas, mecanismos de parada e rollback. Valores de tenant, receptor, rede, AE, credenciais, chaves e dados clínicos permanecem no control-plane e em políticas protegidas; não devem ser registrados aqui.

> **Regra de segurança:** C-STORE é transmissão clínica externa. Todo piloto exige autorização explícita e delimitada. A automação exige aprovação independente, controles persistentes e exclusão expressa de pendências históricas.

## Arquitetura e limites

```text
Médico libera laudo
  → snapshot PDF binário imutável e versionado
  → outbox no mesmo tenant do estudo
  → job com destino tenant/PACS/Issuer-scoped
  → Worker local sem privilégio
  → bridge privada com mTLS/HMAC
  → WireGuard do tenant
  → C-ECHO + C-STORE para o receptor
```

| Componente | Responsabilidade | Restrição principal |
|---|---|---|
| Snapshot PDF | Congelar o PDF clínico na liberação e preservar revisões | O Worker nunca renderiza substituto em runtime. |
| Outbox e job | Materializar uma entrega por versão e destino válido | Tenant, estudo, origem PACS e destino permanecem coerentes. |
| Worker | Reivindicar e processar jobs elegíveis | Opera sem privilégio e não usa fila global fora do escopo. |
| Bridge | Validar mTLS/HMAC, policy e trava de entrega | Não expõe listener público nem aceita alvo arbitrário. |
| Gateway | Executar C-ECHO/C-STORE pelo túnel | Saída depende de WireGuard e de container DICOM operacional. |

## Identidade e artefato DICOM

O laudo é encapsulado como **Encapsulated PDF Storage** no estudo correspondente. Patient ID e Issuer devem permanecer separados. Quando `pdf2dcm --study-from` é usado, o Worker reaplica Issuer of Patient ID no objeto final e escreve Series Description explicitamente, evitando que o receptor apresente o objeto como `ND`.

| Tag | Uso |
|---|---|
| `(0010,0020)` | Patient ID base, sem concatenar issuer. |
| `(0010,0021)` | Issuer of Patient ID, reaplicado após encapsulamento quando aplicável. |
| `(0008,103E)` | Series Description legível do Encapsulated PDF. |
| `(0020,000D)` | Study Instance UID preservado do estudo associado. |

## Critérios de roteamento

A rota automática de produção exige simultaneamente: tenant igual entre job, outbox, estudo e destino; destino vinculado a `servidor_pacs_id`; estudo proveniente desse mesmo servidor PACS; Issuer compatível; ambiente de produção; transporte `dicom_pdf`; bridge em modo `tenant_destination`; e política root-only que permita o mesmo tenant/destino.

O matching por InstitutionName é um fallback permitido somente quando o Issuer de entrada não existe. Destinos legados sem servidor PACS vinculado não são elegíveis para criação de novas rotas automáticas.

## Piloto unitário

O piloto não usa o loop automático. Ele deve começar com worker geral, bridge e destino de produção desativados, depois de validar snapshot, versão, tenant, origem PACS, Issuer, destino, policy, VPN e exclusividade do job. A bridge é iniciada temporariamente com policy restrita a um job/tenant/destino, executa C-ECHO e somente depois um C-STORE. Ao fim, preserve a trilha técnica, pare a bridge e restaure a policy anterior.

| Etapa | Evidência permitida |
|---|---|
| Pré-validação | Estados, IDs internos, versões, contagens e flags de isolamento. |
| Associação | `echo_ok`/`echo_failed`, sem logar parâmetros de conexão. |
| Transmissão | Uma tentativa, status DICOM e referência técnica sanitizada. |
| Encerramento | Job terminal, bridge inativa, listener ausente e destino retornado ao estado esperado. |

## Automação de produção

A automação só deve ser ativada após piloto aceito. O Worker processa exclusivamente novos jobs automáticos da data clínica corrente, criados para destino de produção habilitado e com origem PACS correspondente. Pendências legadas, versões antigas, pilotos, homologações e jobs de outros tenants não são elegíveis para o loop persistente.

A API fornece a feature flag do Hub e executa o Worker em uma unidade systemd. O ambiente é disponibilizado ao processo via `EnvironmentFile` e deve ser protegido por proprietário root e grupo do usuário de serviço. O bootstrap da aplicação hidrata as variáveis já injetadas em `$_ENV` sem registrar seus valores, evitando uma diferença entre execução interativa e execução como serviço.

No gateway, a bridge roda com listener privado, policy `tenant_destination`, mTLS/HMAC, validação de job/tenant/destino e reinício somente em falha. A rota ao receptor exige handshake WireGuard e container DICOM ativo. O limite inicial é uma tentativa externa por job; falhas passam para revisão humana.

A ativação do destino de produção deve persistir na configuração interna da rota tanto o identificador do tenant quanto o identificador do destino autorizados pela bridge. O cliente privado compara esses dois valores com o job antes de abrir qualquer conexão; uma ausência ou divergência é falha de política e deve permanecer terminal, sem fallback de tenant, destino ou rede.

## Kill switch e rollback

Em incidente, interrompa primeiro a API e, depois, o gateway. Isso evita que a fila gere ou consuma nova entrega enquanto a bridge ainda estiver disponível.

```text
API:     /usr/local/sbin/voxelpacs-disable-report-delivery-api
Gateway: /usr/local/sbin/voxelpacs-disable-report-delivery-gateway
```

O kill switch da API desliga a feature flag e o Worker. O do gateway para/desabilita a bridge e remove a flag de automação da policy. Ambos preservam destino, jobs, snapshots, ledger, backup e auditoria. Não apague filas ou artefatos como método de recuperação.

Para retomar, corrija a causa, valide o contrato estático, o ambiente do Worker, a policy privada, o listener, o container, o handshake, a rota e o escopo de jobs. Solicite nova autorização se receptor, tenant, allowlist, retry ou conteúdo de transmissão mudar.

## Observabilidade sanitizada

Acompanhe somente estados técnicos e contagens agregadas. A correlação operacional permitida é o ID interno de job; PDF, dados DICOM identificáveis, destino de rede, AE, certificados, chaves, tokens e hashes completos são proibidos nos logs e relatórios operacionais.

| Métrica ou estado | Finalidade |
|---|---|
| Worker/bridge ativo e habilitado | Disponibilidade dos serviços persistentes. |
| Listener exclusivamente privado | Verificação de ausência de exposição pública. |
| Handshake WireGuard | Pré-requisito de rota para o receptor. |
| Container DICOM ativo | Pré-requisito para C-ECHO/C-STORE. |
| Jobs por estado e tentativa | Auditoria da fila sem revelar conteúdo clínico. |
| Resultado C-ECHO/C-STORE | Confirmação técnica da associação e entrega. |
| Snapshots/versões agregados | Integridade operacional sem abrir PDF. |

## Validação antes de publicação

Execute sintaxe PHP e Python, sintaxe dos scripts, o contrato estático de roteamento e preflights de sistema sem transmissão. Para alterações no runtime, use backup individual e patch cirúrgico; nunca operações Git destrutivas. O commit deve conter somente código, documentação e artefatos sanitizados.

## Evolução opcional: read model resiliente do painel

A tela administrativa hoje calcula o estado de roteamento de cada laudo liberado consultando destinos configurados e ativos. O Worker e a criação da outbox continuam sendo a autoridade para entrega; o painel não deve tomar decisões clínicas ou liberar transmissões. Como evolução opcional, recomenda-se criar um **read model de roteamento** dedicado à interface.

| Elemento | Evolução proposta | Benefício |
|---|---|---|
| Consulta | Substituir composição manual de fragmentos SQL por repositório tipado, com consulta PostgreSQL única para a lista de entregas e seu estado de rota. | Evita escapes literais e reduz consultas por linha. |
| Estado do painel | Materializar ou calcular em `VIEW`/consulta `WITH` o estado `unmapped`, `configured_inactive`, `manual_eligible` e `automatic_only`. | Mantém a regra visual coerente e auditável. |
| Falha de leitura | Capturar erro somente na camada de read model e exibir estado operacional `indisponível`; registrar categoria técnica sanitizada. | Uma falha de visualização não derruba toda a página administrativa. |
| Segurança | Manter Worker, outbox e bridge fail-closed e independentes do read model. | O fallback visual nunca pode ativar, criar ou transmitir job. |
| Teste | Adicionar integração contra PostgreSQL real para as duas ramificações de `findDestinations`, além do contrato estático. | Detecta sintaxe específica do dialeto antes do deploy. |

Essa evolução é recomendada para uma próxima janela de melhoria. Ela não é necessária para a correção atual e não deve ser aplicada junto de mudanças de destino, gateway ou automação sem validação própria.
