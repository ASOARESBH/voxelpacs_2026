# Referências externas — operação de células DICOM tenant

## Orthanc PostgreSQL

Fonte: https://orthanc.uclouvain.be/book/plugins/postgresql.html

A documentação oficial distingue os plugins PostgreSQL de índice e armazenamento. Ela mostra a configuração com `EnableIndex: true` e `EnableStorage: false` e aponta esse uso — PostgreSQL para índice e arquivos DICOM no filesystem/NAS com estratégia de recuperação — como prática típica de melhor desempenho para bases grandes. Esse princípio sustenta a célula VOXEL PACS: índice PostgreSQL isolado e diretório de objetos DICOM segregado por tenant.

## Restic

Fonte: https://restic.readthedocs.io/en/latest/040_backup.html

A documentação define backup como snapshot em um ponto no tempo, explica que tags identificam snapshots e recomenda executar regularmente `restic check` para verificar a integridade do repositório. Esse princípio sustenta o contrato de backup por tenant, as tags de componente e a pendência de restore controlado fora do runtime.
