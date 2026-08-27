# Onboarding automatizado de célula DICOM tenant

## Objetivo

Este módulo transforma o cadastro de **Servidor PACS** em um fluxo controlado para criar uma célula Orthanc isolada por tenant no host híbrido existente. O fluxo atende o perfil `vpn_only` e mantém a API sem privilégios de root, sem chaves privadas WireGuard e sem acesso direto ao Docker, firewall ou gateway.

> O cadastro administrativo não recebe objetos DICOM, não lê estudos, não transfere imagens e não habilita recepção clínica automaticamente.

## Limites de confiança

| Componente | Responsabilidade | Limite de acesso |
|---|---|---|
| Interface administrativa | Coleta parâmetros obrigatórios, mostra estado, solicita confirmação e disponibiliza o kit de integração. | Apenas superadmin; nunca mostra segredos. |
| API VOXEL PACS | Persiste reservas e auditoria, gera ordem assinada e recebe resultado técnico. | Sem root, Docker, WireGuard, firewall ou chaves privadas. |
| Agente operacional | Executa uma ordem específica e idempotente no host autorizado. | Serviço root-only, com allowlist de ações, assinatura HMAC, nonce único, expiração curta e log técnico sem PHI. |
| Gateway DICOM | Valida IP VPN, Calling AE, Called AE e serviço DICOM. | Inicialmente permite somente C-ECHO; C-STORE exige confirmação separada. |
| Host híbrido | Cria Orthanc, PostgreSQL, storage, regras privadas e contrato de backup por tenant. | Não cria recursos Hetzner, IPs públicos, buckets ou VMs. |

## Estados do ciclo de vida

| Estado | Significado | DICOM permitido |
|---|---|---|
| `draft` | Campos ainda não foram submetidos. | Nenhum |
| `reserved` | Identificadores, portas e IP VPN foram reservados, sem alteração de infraestrutura. | Nenhum |
| `provisioning` | Ordem autenticada foi aceita pelo agente. | Nenhum |
| `echo_ready` | Célula, peer, regras privadas, gateway e timer desabilitado existem; gateway aceita somente C-ECHO. | C-ECHO |
| `echo_validated` | A auditoria do gateway confirmou C-ECHO aceito para o peer e AEs cadastrados. | C-ECHO |
| `active` | Liberação explícita de C-STORE foi autorizada e aplicada. | C-ECHO e C-STORE |
| `failed` | A ordem falhou; o diagnóstico técnico seguro está disponível. | Nenhum |
| `suspended` | Rota desabilitada; a célula não recebe associações. | Nenhum |

## Dados obrigatórios para `vpn_only`

A criação exige tenant existente, nome de exibição, chave de rota, Calling AE do emissor, Called AE exclusivo do gateway, AE do backend Orthanc e chave pública WireGuard do cliente. A API valida AEs em maiúsculas com até 16 caracteres, chave WireGuard em base64 de 32 bytes, slug, unicidade de identificadores e vínculo de tenant.

As portas privadas DICOM/DICOMweb e o IP VPN do cliente são reservados automaticamente a partir dos intervalos controlados. O administrador não pode informar endereço de backend público nem URL Orthanc externa. A URL de DICOMweb registrada no control-plane é privada e somente usada pela API em rede privada.

## Regras de negócio e tags DICOM

O vínculo de um servidor a um negócio é N:N. Para servidores compartilhados, o roteamento mantém `tenant_id` como fronteira final e avalia as regras DICOM já cadastradas no negócio: `InstitutionName (0008,0080)` e `IssuerOfPatientID (0010,0021)`, com Issuer autorizado por modalidade. A interface exibe as tags e regras herdadas ao associar um servidor.

O seletor de modalidades suporta `todas` como política explícita, persistida como curinga `*`; campo vazio sem a opção marcada não cria regra. O curinga não remove a validação do Issuer: ele apenas se aplica a todas as modalidades. Para células exclusivas, essa configuração é apenas informativa para compatibilidade: a associação servidor-célula determina o tenant antes de metadados DICOM.

## Confirmações obrigatórias

| Ação | Exige confirmação na interface | Motivo |
|---|---|---|
| Provisionar célula | Sim | Cria containers, peer WireGuard, regras privadas, rota C-ECHO e timer desabilitado. |
| Liberar C-STORE | Sim, após C-ECHO validado | Passa a aceitar transferência de dados clínicos. |
| Habilitar backup clínico | Sim, em etapa separada | Inicia cópias de dados clínicos para o repositório de backup. |
| Reprovisionar ou rotacionar peer | Sim | Revoga uma chave de acesso e pode interromper integração. |
| Suspender rota | Sim | Interrompe o recebimento DICOM. |

## Diagnóstico e auditoria

Cada ordem possui identificador opaco, nonce, hash da carga e tempos de início/fim. O navegador vê apenas estado, etapa, código de erro permitido e indicação de log técnico. O agente não registra atributos de pacientes, UIDs DICOM, comandos contendo segredos, arquivos DICOM ou configurações privadas completas.

O botão **Verificar C-ECHO** consulta exclusivamente metadados de auditoria do gateway depois da data da ordem. Ele exige correspondência exata de rota, perfil, endereço VPN, Calling AE, Called AE, serviço `C_ECHO` e resultado `accepted`; não executa nem agenda C-STORE.

## Reversão

A falha antes da ativação deixa a rota desabilitada. O agente tenta remover apenas os recursos identificados pelo `operation_id` que acabou de criar, preservando diretórios e contratos preexistentes. Reversão de uma célula ativa, remoção de dados, alteração de retenção e restauração de backup permanecem fora do fluxo automático e exigem procedimento aprovado separado.
