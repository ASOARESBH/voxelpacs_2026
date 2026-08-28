# Ponte DICOM de devolutiva pelo gateway

Este componente recebe, em rede privada, um **artefato DICOM Encapsulated PDF** produzido pelo worker e realiza uma associação DICOM de saída via WireGuard. A ponte não recebe conexões DICOM externas, não substitui o gateway de entrada e não pode ser exposta publicamente.

## Limites de segurança

A ponte escuta apenas no IP privado do gateway e exige simultaneamente certificado de cliente emitido pela CA interna e assinatura HMAC com validade limitada. A configuração efetiva permanece em arquivo root-only fora do repositório. Nenhuma chave, certificado privado, configuração de destino, artefato DICOM ou dado clínico pode ser versionado.

O servidor aceita exclusivamente URLs privadas fixas, o SOP Class de **Encapsulated PDF Storage**, tamanho e hash do corpo declarados no HMAC, e uma política estática de saída. Registros operacionais devem conter apenas identificadores internos, horário, hash truncado e resultado técnico.

| Modo | Finalidade | Restrições obrigatórias |
| --- | --- | --- |
| `controlled_job` | Homologação ou piloto unitário | Um único job interno, hash e destino allowlisted; sem retentativa automática. |
| `tenant_destination` | Rota de produção já homologada | Job positivo, tenant e destino positivos e idênticos à política; o destino deve ter sido previamente vinculado ao servidor PACS autorizado no control-plane. |

> O modo `tenant_destination` **não habilita** a ponte por si só. A unidade continua desativada até que a política root-only seja revisada, o destino esteja explicitamente habilitado e haja autorização operacional para iniciar o worker.

## Fluxo permitido

| Etapa | Responsável | Controle |
| --- | --- | --- |
| 1 | API | No momento da liberação, registra snapshot PDF binário imutável e a outbox com tenant, servidor PACS de origem e versão do laudo. |
| 2 | Worker | Lê somente o snapshot registrado, materializa o DICOM Encapsulated PDF, reaplica Issuer de Patient ID e Series Description e calcula SHA-256. |
| 3 | API/worker → gateway | mTLS, HMAC, IP/path privados fixos e cabeçalhos de job, tenant e destino. |
| 4 | Gateway | Confirma path, assinatura, política, SOP Class, tamanho e hash; em modo produção, exige tenant e destino configurados. |
| 5 | Gateway → PACS | Sai exclusivamente por `wg0`, realiza C-ECHO e somente então C-STORE. |
| 6 | Worker | Marca o job entregue somente após a confirmação técnica do gateway. |

## Operação controlada

Antes de iniciar a unidade, confirme o peer WireGuard, a rota de saída pela interface VPN, a saúde do gateway e o C-ECHO com os AEs homologados. Para um piloto, use somente `report_delivery_worker.php --job-id=<id>` com a ponte em `controlled_job`; esse modo não seleciona outros itens da fila.

Depois de toda tentativa unitária, pare e desabilite a unidade. Não habilite o worker geral, a ponte ou retentativas automáticas até que a confirmação técnica remota seja registrada e exista nova autorização explícita para promoção operacional.

## Configuração de produção protegida

A policy root-only precisa declarar o modo `tenant_destination`, um único tenant, um único destino, endpoint roteado no WireGuard, AEs exatos, perfil TLS, limite de tamanho, timeout e credenciais mTLS/HMAC já aprovadas. Esses valores são inseridos diretamente no gateway protegido e nunca em Git, chat ou script de deploy.

O control-plane impede uma rota automática de produção sem `servidor_pacs_id` vinculado ao mesmo tenant; destinos legados sem esse vínculo permanecem inelegíveis até revisão manual. Com Issuer no estudo, o matching é feito somente por Issuer; `InstitutionName` é usado apenas quando Issuer estiver ausente.

## Rollback

Para interromper antes de uma tentativa, pare e desabilite a unidade, mantenha o worker geral inativo e desabilite o destino. Se já houver policy instalada, restaure o backup root-only ou remova a policy conforme procedimento operacional autorizado. Não altere listeners de entrada, porta DICOM do gateway, peers de outros tenants, Orthanc ou viewer.
