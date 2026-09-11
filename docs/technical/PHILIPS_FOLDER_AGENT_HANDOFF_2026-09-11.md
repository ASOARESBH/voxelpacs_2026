# Handoff — Philips Folder Non-DICOM, Fase 1

**Data de consolidação:** 2026-09-11

> Este documento é sanitizado. Não inclui senhas, tokens, chaves privadas, HMAC, conteúdo de envelopes, URLs públicas, atributos clínicos, identificadores de paciente, caminhos de artefatos, parâmetros de rede ou logs brutos.

## Objetivo e limites permanentes

O piloto permanece em **homologação**, com **uma entrega manual PDF-only** já criada. XML continua proibido até existir contrato/XSD oficial. O PACS/API nunca executa SMB diretamente: a única borda autorizada é a bridge Philips no gateway designado.

Não alterar, reenviar ou processar os jobs DICOM existentes e explicitamente excluídos. Não ativar automação por liberação de laudo. Não alterar WireGuard, firewall, SSHD, Orthanc, DICOM, PostgreSQL, containers ou demais serviços fora do escopo explícito e aprovado.

## Situação operacional consolidada

| Área | Estado conhecido | Evidência ou observação sanitizada |
|---|---|---|
| Destino Philips | Habilitado em homologação; automação por liberação permanece desligada. | Confirmado visualmente antes da criação manual. |
| Bridge Philips | Materializada no gateway designado, com listener privado, mTLS/HMAC e transporte SMB conforme a política root-only. | A materialização e a PKI foram conduzidas fora do PACS/API. |
| PKI mTLS | Causa raiz identificada e correção planejada/aplicada pelo operador segundo a sequência coordenada. | Nunca desabilitar validação estrita de X.509. |
| Roteamento manual | Corrigido no PACS/API para não exigir o gatilho automático de liberação. | Runtime publicado no commit de materialização abaixo. |
| Entrega manual única | Criada; job técnico interno `272` está inicialmente `queued`, sem referência remota e sem erro registrado. | O número é um identificador operacional interno, não clínico. |
| Worker contínuo | Unit ativa, mas o processo em execução não carregou a flag Non-DICOM e sua configuração é mais nova que o processo. | Não reiniciar o loop global sem reavaliar jobs elegíveis. |
| Política da bridge | Policy `single_test` atualizada para permitir exclusivamente o job interno autorizado; backup root-only registrado. | A unit ainda não foi recarregada, portanto o processo em memória não carregou a policy nova. |

## Causa atual do bloqueio

A entrega manual foi criada corretamente depois do ajuste de roteamento, mas ainda não pode ser processada por um controle de runtime que precisa ser tratado de forma coordenada:

1. O worker persistente não carregou a flag que habilita o transporte Philips. Reiniciar o worker global é inseguro porque seu loop pode reclamar outros jobs elegíveis, inclusive fluxos fora do escopo.
2. A policy root-only da bridge foi aplicada com allowlist exclusiva para o job interno autorizado, mas requer uma recarga isolada da unit da bridge para ser carregada em memória. Essa recarga ainda não foi autorizada nem executada.

Esses controles são **intencionais e corretos**; não os contorne, não reduza a policy para destino amplo e não use o job DICOM excluído como substituto.

## Próximo passo seguro — ainda não executado

O próximo agente deve obter uma autorização explícita do operador para recarregar somente a **unit da bridge Philips** no gateway. A policy de job único já foi aplicada com backup e o procedimento versionado é:

```text
docs/technical/enable_philips_folder_controlled_job_272.sh
commit: c36f0f4fa6dea6fbdd518c7acb22e5ac2e9e549b
sha256: 40bb86eb170019b7513ddd88d4549a09d27738a12eea752dceea9b6a4b26bac6
```

O modo `--preview` já foi concluído e o `--apply` retornou `ready_for_separate_reload` com backup registrado. As autorizações seguintes continuam separadas:

| Etapa futura | Requer nova autorização? | Limite obrigatório |
|---|---:|---|
| Aplicar policy `PHILIPS_FOLDER_ALLOW_JOB_ID` para o job interno 272 | Concluída | Alterou somente a policy root-only; backup e rollback permanecem disponíveis. |
| Recarregar exclusivamente a unit da bridge Philips | Sim | Não reiniciar worker, PHP-FPM, Nginx ou qualquer outro serviço. |
| Preparar e validar um runner unitário para o job 272 | Sim, para preparar; outra para executar | Não usar o loop global do worker; não reclamar outros jobs. |
| Executar uma única vez o job 272 | Sim | Apenas PDF-only, destino homologado e policy do mesmo job; não retentar. |
| Monitorar resultado terminal | Não cria ação externa | Usar somente estados e categorias sanitizadas. |

O runner unitário **ainda não foi preparado nem executado**. Antes de criá-lo, o novo agente deve ler o código atual de `bin/report_delivery_worker.php`, confirmar o argumento de job unitário e validar que ele não entra no loop automático. O runner deve ser root-owned, sem argumentos e invocar o processo de serviço com filtro estrito para o job interno autorizado; não deve registrar segredos, corpo de PDF, URL pública ou dados clínicos.

## Commits relevantes

| Finalidade | Commit |
|---|---|
| Materialização inicial do runtime Philips Non-DICOM no PACS/API | `3936cd94e6bce3464cd253b9b914fbeda35be77e` |
| Correção do namespace do serviço SMB e classificação sanitizada | `2e7440a7347d81478c44bf836c5de29e13daeadb` |
| Alerta interno do teste SMB na página | `6d06421d537b5bc1bc49d4c17b32cee9f166b637` |
| Diagnósticos sanitizados de PACS/API e gateway | `8a14076bb30f77f7085282118979395a304db3c0` |
| Instalador controlado da bridge no gateway | `80c277dd6f09387d379c5757d4fa76d2ff2f1794` |
| Carregamento de referências privadas no bootstrap PACS/API | `a4d79b891c5169ee31bfa049c0c3cf13831939fe` |
| Plano/scripts de rotação PKI | `ee03b1f5a07585b9a4e42f9c7a60023b5e8e6072` |
| Prévia de aplicação PKI reforçada | `f5165b869102fdc16bca09956d06830560f77da6` |
| Correção publicada de roteamento manual de homologação | `3e82714650c32e7d65edad62d646e5e75ad17cd0` |
| Diagnóstico de elegibilidade do worker | `28448a92657e2b9fc92a24947c0d6c8a74a65b16` |
| Procedure de policy temporária por job único | `c36f0f4fa6dea6fbdd518c7acb22e5ac2e9e549b` |

## Diagnósticos e documentos a reler

O novo agente deve ler os documentos e o código abaixo antes de propor alteração ou execução:

```text
docs/PHILIPS_NON_DICOM_PHASE1_PDF_SMB.md
docs/PHILIPS_NON_DICOM_SMB_SECRET_ENROLLMENT.md
docs/technical/PHILIPS_FOLDER_PKI_ROTATION_PROPOSAL.md
docs/technical/enable_philips_folder_controlled_job_272.sh
docs/technical/diagnose_philips_manual_job_worker_readonly.sh
deploy/report-delivery-gateway-bridge/philips_folder_bridge.py
app/Services/PhilipsFolderGatewayBridgeClient.php
app/Services/ReportDeliveryOutboxService.php
app/Repositories/ReportDeliveryRepository.php
app/Repositories/ReportDeliveryWorkerRepository.php
bin/report_delivery_worker.php
```

Também deve reler as habilidades `voxel-pacs`, `philips-folder-delivery`, `integracao-philips` e `report-delivery-worker` antes de qualquer mudança ou operação.

## Acesso técnico para a continuidade

O agente anterior **não possuía** um canal SSH shell direto configurado nesta sessão. As ações remotas foram executadas pelo operador em sessões root locais já estabelecidas, após o agente disponibilizar comandos versionados e com hash verificável.

Não gere nem compartilhe credencial SSH, senha root, chave privada, token de acesso, HMAC, senha SMB ou chave de envelope para a troca de agente. O agente seguinte deve operar por sua própria sessão autenticada e, quando for necessário executar comando remoto, solicitar que o operador o execute no host correto ou usar somente uma integração tecnicamente provisionada com privilégio mínimo. Não alterar `authorized_keys`, sudoers, SSHD ou criar contas como atalho de continuidade.

## Prompt para o próximo agente

> Você está assumindo o piloto VOXEL Philips Folder Non-DICOM Fase 1. Leia primeiro as habilidades `voxel-pacs`, `philips-folder-delivery`, `integracao-philips` e `report-delivery-worker`, depois este handoff. Trabalhe em português. Mantenha PDF-only, homologação e automação por liberação desligada; XML é proibido até XSD/contrato oficial. Não exponha PHI, URLs públicas, hosts, portas, certificados, HMAC, senhas, tokens ou chaves. O PACS/API nunca executa SMB; a bridge já materializada no gateway é a única borda.
>
> Existe exatamente um job Philips Non-DICOM manual já criado, identificado internamente como 272. Ele está em fila e não possui evidência remota nem erro registrado. Não o recrie, não reenvie, não reprocese e não toque no job DICOM legado excluído. O worker contínuo está ativo mas não carregou a flag Non-DICOM; não reinicie o loop global, pois ele pode reclamar outros jobs. A bridge permanece em `single_test`, mas sua policy root-only já foi atualizada com allowlist exclusiva para o job 272 e backup registrado. O próximo passo ainda não executado é solicitar autorização explícita para recarregar somente a unit da bridge; não iniciar worker ou SMB nessa recarga.
>
> Peça autorizações independentes e nesta ordem: recarregar exclusivamente a unit Philips Folder Bridge; preparar/validar um runner de job unitário sem loop global; executar o job 272 uma única vez; e monitorar até o primeiro estado terminal sem retentativa. Pare na primeira falha e retorne somente estados/categorias sanitizadas. Não use nem peça credenciais SSH novas; o operador executa comandos root locais nos hosts corretos ou fornece uma integração de privilégio mínimo própria.
