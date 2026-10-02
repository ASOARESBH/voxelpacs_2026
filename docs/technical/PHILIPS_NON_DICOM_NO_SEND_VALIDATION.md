# Philips Non-DICOM — validação `no-send`

## Objetivo

`bin/philips_nondicom_submission_no_send.php` valida um Job `submission_document` de produção controlada sem reclamar o Job, criar tentativa, gravar PDF/XML, chamar a Bridge ou acessar SMB.

## Escopo obrigatório

A execução exige explicitamente:

```text
--tenant-id=<tenant autorizado>
--job-id=<Job autorizado>
--no-send
```

O diagnóstico consulta o Job, Outbox, Request e Destination dentro do tenant informado, inicia uma transação PostgreSQL somente-leitura e faz rollback ao final. O Job precisa estar `queued`, sem tentativa e sem lock.

A validação falha fechado quando:

- o Job não pertence ao tenant informado;
- o transporte, ambiente, profile, Destination ou modo de dispatch não correspondem ao fluxo controlado;
- o alias do Destination, Request e payload não é ASCII válido ou não coincide entre os três snapshots;
- o payload/configuração não pode ser decodificado;
- a composição do XML não satisfaz o contrato Philips.

O produtor hidrata o snapshot sem consumir override e serializa o XML somente em memória. O conteúdo do XML, PDF, nomes clínicos, Patient ID, credenciais e hashes não são retornados ou registrados. O resultado informa apenas estados técnicos e IDs internos.

## Proibições

Este diagnóstico não pode chamar `claimJobById`, `claimNextJob`, `enableOneShotForJob`, `deliverNonDicomSubmissionPackage`, `PhilipsFolderGatewayBridgeClient`, `storeGeneratedArtifact`, `recordArtifact`, `completeJob` ou `failJob`. Não deve executar `INSERT`, `UPDATE`, escrita de arquivo, Bridge, SMB ou Worker.

## Exemplo controlado

```bash
php bin/philips_nondicom_submission_no_send.php \
  --tenant-id=2 \
  --job-id=515 \
  --no-send
```

`PASS` neste diagnóstico significa somente que a identidade e a serialização em memória passaram. Não significa que o arquivo chegou ao Windows/Philips nem autoriza transmissão.

## Gate posterior

Somente após um `PASS` no-send, com o Worker global ainda inativo, pode-se preparar uma allowlist temporária exclusiva do mesmo Job, executar o probe SMB read-only oficial e registrar o preflight. A execução do Worker e o envio PDF+XML permanecem etapas separadas e exigem autorização específica.
