# Referência técnica — retorno DICOM Philips/Carestream

Use este documento somente para configuração e diagnóstico técnico. Não copie valores de pacientes, Study UID, SOP Instance UID, PDF, credenciais ou chaves para tickets, commits ou chats.

## Perfil de fluxo

| Item | Padrão operacional |
|---|---|
| Direção | VOXEL gateway → receptor Philips/Carestream |
| Transporte preferencial | WireGuard por tenant; o IP de origem deve ser o do gateway na VPN |
| Pré-condição DICOM | C-ECHO aceito com Calling AE e Called AE homologados |
| Entrega inicial | DICOM Encapsulated PDF Storage por C-STORE |
| Segurança de ponte | API→gateway por mTLS + HMAC privado; gateway→receptor somente pela VPN |
| Escopo de teste | Um job interno explicitamente allowlisted; worker geral inativo |
| Pós-teste | Confirmar resposta, estado da outbox/job, desabilitar bridge e preservar auditoria sanitizada |

## Valores a registrar por destino

Registre esses campos no control-plane protegido, nunca em código ou material versionado:

1. Tenant e servidor PACS de origem.
2. Peer WireGuard, IP VPN do receptor e porta DICOM.
3. Calling AE aceito pelo receptor.
4. Called AE **com capitalização exata**.
5. Perfil TLS, se houver; nunca degradar TLS silenciosamente.
6. SOP Classes permitidas, começando por Encapsulated PDF Storage.
7. Issuer de saída esperado pelo receptor.
8. Limite de tamanho, timeouts, política de retentativa e contato técnico.

## C-ECHO e C-STORE

Faça um C-ECHO antes do primeiro C-STORE e após qualquer alteração de rota, AE, TLS ou peer. C-ECHO aceito prova apenas conectividade e associação; não prova que o receptor aceitará a instância do Encapsulated PDF.

No C-STORE inicial, permita somente um relatório de teste autorizado. A execução deve gerar uma referência técnica, hash truncado, horário e resultado; não registrar identificadores clínicos no log local da ponte.

## Identidade do paciente no PDF DICOM

| Campo | Tag DICOM | Fonte/regra |
|---|---|---|
| Patient ID | `(0010,0020)` | Identificador base associado ao estudo; não anexar issuer no texto |
| Issuer of Patient ID | `(0010,0021)` | Valor exigido pelo receptor de retorno; pode divergir do issuer de roteamento de entrada |
| Study Instance UID | `(0020,000D)` | UID do estudo autorizado; não criar UID novo para devolutiva |
| SOP Class | `1.2.840.10008.5.1.4.1.1.104.1` | Encapsulated PDF Storage, quando suportada pelo destino |

Ao gerar o objeto com `pdf2dcm --study-from`, use também `--key 0010,0021=<issuer>` quando houver issuer configurado. A ferramenta pode preservar o Patient ID vindo do DICOM de estudo e, ainda assim, descartar o Issuer of Patient ID. Antes de transmitir, valide esse comportamento com documento sintético e `dcmdump` das duas tags.

## Onde procurar logs no receptor

Em instalações Carestream/Philips, procure os logs do serviço DICOM/SVDSER, frequentemente com nomes como `SVDSER.log` ou `SVDSER_Dicom_Log.log`. Se necessário, procurar também os logs DIDB/Database do serviço.

Use busca textual pelos termos abaixo, substituindo somente o valor do Calling AE ou IP VPN no ambiente real:

```text
<Calling AE do gateway>
ClientIP(<IP VPN do gateway>)
Received ASSOCIATION REQUEST
Sent ASSOCIATION ACCEPT
Received C-ECHO COMMAND
CStoreResponse
Fail-to-Store
SCL_STATUS_REFUSED
STORE_STUDY_CONFLICT
Rejected image
DIDB_Studies_Table
```

| Padrão no log | Significado | Próxima ação |
|---|---|---|
| Called AE não encontrado | O título chamado não existe ou está com caixa diferente | Corrigir AE no destino e repetir somente C-ECHO |
| Origem VPN aparece no `ClientIP` e C-ECHO é aceito | Rota, IP e associação estão corretos | Prosseguir somente após validar o artefato |
| `SCL_STATUS_REFUSED` + `STORE_STUDY_CONFLICT` | Receptor localizou um estudo com mesmo UID, mas a identidade diverge | Comparar Patient ID e Issuer; não tentar novamente automaticamente |
| `Rejected image` em `DIDB_Studies_Table` | O banco do receptor recusou o objeto antes de armazenar | Ajustar o perfil de identidade de saída por destino |
| Aviso de SOP Class duplicada | Configuração de serviço duplicada; não é, por si só, recusa de C-STORE | Procurar o status C-STORE posterior |
| C-STORE com resposta de sucesso | Receptor aceitou o objeto | Confirmar estado `delivered`, encerrar bridge |

## Checklist de falha

1. Parar/desabilitar a ponte e manter o job terminal.
2. Não executar retry automático nem iniciar o worker geral.
3. Obter o trecho de log do receptor no horário da tentativa, sem PHI.
4. Classificar a falha em rede/AE, associação, SOP Class, identidade DICOM ou armazenamento do receptor.
5. Aplicar a menor correção em clone limpo e validar com dados sintéticos.
6. Fazer backup antes de alterar runtime, configuração de destino ou fila.
7. Solicitar autorização explícita para novo C-STORE.
8. Após sucesso ou desistência, restaurar a política de bridge para `disabled`.
