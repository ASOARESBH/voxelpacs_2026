# Arquitetura — Integrações (Orthanc, HL7, RIS, HIS)

## Orthanc

- Forma de integração: `[A confirmar — REST API síncrona, DICOMweb, plugin Lua customizado, webhooks/callbacks?]`
- Onde vive o código de integração: `[A preencher caminho]`
- Eventos do Orthanc consumidos (ex: `OnStoredInstance`): `[A preencher — ver indexes/eventos-filas.md]`
- Peers/roteamento configurado (se houver): `[A confirmar]`
- Autenticação usada entre app e Orthanc: `[A confirmar]`

### Operações DICOM suportadas e onde ficam

| Operação | Suportada? | Caminho do código |
|---|---|---|
| C-STORE | `[A confirmar]` | |
| C-FIND | `[A confirmar]` | |
| C-MOVE | `[A confirmar]` | |
| C-ECHO | `[A confirmar]` | |
| MWL (Modality Worklist) | `[A confirmar]` | |
| MPPS | `[A confirmar]` | |

**Regra de segurança:** nenhuma alteração nesta camada deve ser feita sem validar Study/Series/Instance/SOP Instance UID/Transfer Syntax/AE Title continuam consistentes. Ver `diagnostics/dicom.md`.

## HL7

- Mensagens suportadas: `[A confirmar — ADT, ORM, ORU, outras?]`
- Biblioteca/engine de parsing usado: `[A confirmar]`
- Canal de entrada (MLLP, arquivo, fila): `[A confirmar]`
- Onde vive o código de parsing/handling: `[A preencher caminho]`
- Segmentos tratados (MSH, PID, PV1, OBR, OBX...): `[A confirmar quais são efetivamente usados]`

## RIS / HIS

- Sistema(s) RIS/HIS integrados: `[A preencher — nomes reais dos sistemas parceiros, se souber]`
- Protocolo de integração (HL7? API própria? Ambos?): `[A confirmar]`
- Direção do fluxo (RIS→PACS, PACS→RIS, bidirecional?): `[A confirmar]`

## Regra geral para qualquer integração externa

Trate toda integração externa como **fronteira de confiança**: valide entrada, não assuma formato, e documente aqui o comportamento esperado em caso de falha (retry? fila de dead-letter? log e ignora?). Se essa informação não existir ainda, marque explicitamente como `[Comportamento de falha desconhecido — investigar antes de alterar]` em vez de presumir.
