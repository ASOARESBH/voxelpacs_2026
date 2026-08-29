# Registro operacional sanitizado — Delivery Hub

Use este modelo para registrar uma validação, piloto, ativação, incidente ou rollback. Não inclua PDF, Patient ID, nome, UID completo, destino de rede, AE, certificado, HMAC, chave VPN ou credencial.

| Campo | Registro permitido |
|---|---|
| Data/hora UTC | `AAAA-MM-DDTHH:MM:SSZ` |
| Tipo | `preflight`, `piloto`, `automacao`, `incidente` ou `rollback` |
| Escopo | ID interno de tenant, servidor PACS, destino e job, quando autorizado |
| Snapshot | `válido`/`inválido`, versão e hash truncado somente se aprovado pela política interna |
| Isolamento | `tenant_ok`, `origem_pacs_ok`, `issuer_ok`, `destino_ok` |
| Gateway | `bridge_active/inactive`, `listener_private`, `policy_ok`, `container_ok` |
| VPN | `handshake_observed` ou `handshake_missing` |
| DICOM | `echo_ok`, `cstore_ok`, `refused` ou `not_executed` |
| Fila | Contagem por estado, tentativa e resultado do job autorizado |
| Resultado | `approved`, `blocked`, `delivered`, `failed` ou `rolled_back` |
| Ação posterior | Texto curto sem conteúdo clínico ou segredo |

## Checklist de preflight

- [ ] Snapshot binário imutável da versão atual confirmado sem abrir o PDF.
- [ ] Tenant, servidor PACS de origem e Issuer compatíveis.
- [ ] Destino tenant-scoped e bridge mode corretos.
- [ ] Worker e bridge no estado exigido para a operação.
- [ ] Listener privado, policy root-only, mTLS/HMAC, container e WireGuard verificados.
- [ ] Nenhum job histórico ou de outro tenant elegível.
- [ ] Autorização explícita registrada quando houver transmissão clínica.

## Checklist de encerramento

- [ ] Status técnico do job registrado sem PHI.
- [ ] Somente job/destino do escopo alterado.
- [ ] Para piloto: bridge e policy temporária desativadas.
- [ ] Para automação: retry, kill switch e observabilidade confirmados.
- [ ] Para incidente: worker/bridge interrompidos e evidências sanitizadas preservadas.
- [ ] Snapshots, ledger, outbox, jobs e backups preservados.
