# Checklist de implementação do Worker do Delivery Hub

Use este checklist para mudanças de código, migrations, destino, serviço ou gateway. Não contém valores de ambiente e não substitui autorização clínica.

## Análise de impacto

| Pergunta | Critério de aceite |
|---|---|
| O escopo envolve transmissão externa? | Separar preflight, autorização explícita, piloto e automação. |
| Quais componentes consomem a mudança? | Mapear outbox, worker, bridge, destino, gateway, serviço e painel. |
| O isolamento está completo? | Tenant, estudo, outbox, job, destino e servidor PACS usam joins explícitos. |
| Há alteração de schema? | Criar migration aditiva, transacionada, com índice/ACL e rollback documentado. |
| Há risco de PHI/segredo? | Sanitizar logs, commits, documentos, scripts e diagnósticos. |

## Mudança de código

1. Manter snapshots binários imutáveis como única fonte do PDF entregue.
2. Preservar Patient ID e Issuer em tags distintas e escrever Series Description no objeto final.
3. Atualizar todo consumidor de seleção de destino quando o contrato tenant/PACS/issuer mudar.
4. Manter `controlled_job` para piloto e `tenant_destination` para produção; não misturar seus controles.
5. Garantir que bridge valide job, tenant e destination na assinatura e na policy.
6. Garantir que o loop automático não reclame jobs históricos, manuais, de teste, de outros tenants ou de data clínica anterior.
7. Não incluir host, AE, chave, certificado, token, URL privada, PDF, UID, Patient ID ou dados pessoais no código versionado.

## Aplicação cirúrgica

1. Trabalhar a partir de clone limpo e commit sanitizado.
2. Criar manifest/hash do pacote e backup individual dos arquivos substituídos.
3. Em banco, fazer backup restrito das tabelas atingidas e executar migration/transação com `ON_ERROR_STOP`.
4. No runtime, não usar `git pull`, `git reset` ou `git clean`.
5. Instalar alteração de API e gateway em hosts separados. Não copiar policy, chave ou `.env` para Git.
6. Manter bridge e worker inativos durante deploy, salvo ativação explicitamente autorizada.

## Pré-validação técnica sanitizada

| Componente | Verificar |
|---|---|
| Worker | `--check`, sintaxe PHP, bootstrap, contexto de ambiente do systemd e permissões de storage. |
| Banco | Destino único, tenant/origem PACS/Issuer compatíveis, snapshot válido, versão atual e ausência de jobs indevidos. |
| Bridge | Listener somente privado, policy root-only, mTLS/HMAC, limite de tamanho/hash e trava por job. |
| Rede | Container DICOM ativo, rota pelo WireGuard, handshake observado e ausência de fallback público. |
| Painel | Agregação pela maior versão de outbox sem perder histórico/ledger. |

## Evidência e encerramento

Produza uma tabela de estados técnicos e evidências sem PHI. Para piloto, desative bridge e policy temporária ao final. Para automação, registre kill switches, limites de retry, critérios de inclusão/exclusão e procedimento de rollback. Sempre preserve jobs, snapshots e ledger; não apague como forma de recuperação.
