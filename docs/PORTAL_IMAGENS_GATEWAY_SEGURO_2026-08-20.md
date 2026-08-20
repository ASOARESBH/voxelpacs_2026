# Portal de Resultados — Gateway Seguro de Imagens DICOM

**Status:** planejamento técnico; imagens permanecem bloqueadas em produção.
**Domínios envolvidos:** `portal.voxelpacs.com.br`, `server.voxelpacs.com.br`, `view.voxelpacs.com.br` e Orthanc privado `10.0.0.3:8042`.

> **Decisão de segurança:** não ativar `PORTAL_IMAGES_ENABLED=true` enquanto não existir uma camada que entregue exclusivamente imagens anonimizadas, com autorização temporária por estudo e auditoria. O Viewer interno não pode ser reutilizado diretamente pelo Portal.

## 1. Diagnóstico confirmado

| Componente | Situação atual | Impedimento para ativação direta |
|---|---|---|
| Rota `/imagens/{report_token}` | Exige sessão do Portal, confere laudo liberado e exige as flags de segurança | Mesmo com as flags verdadeiras, responde `503`: não há gateway implementado |
| Portal de Resultados | Trabalha com `reports.public_token` | Não recebe identificador Orthanc nem um token de Viewer específico para o paciente |
| Viewer interno | Usa token temporário e, ao final, redireciona para `StudyInstanceUIDs={uid}` | Não verifica sessão do Portal e não anonimiza o estudo |
| Orthanc | Acessível somente da API pela rede privada | A porta e as credenciais não podem ser expostas ao navegador ou ao subdomínio público |

A documentação do Orthanc confirma que a anonimização cria novos UIDs e permite remover/alterar tags; por padrão, tags privadas também são removidas. A mesma documentação alerta que qualquer alteração de identificadores deve preservar o modelo DICOM e a rastreabilidade.[1] O plugin DICOMweb disponibiliza QIDO-RS e WADO-RS, mas herda os parâmetros HTTP do Orthanc; portanto, expô-lo sem uma camada de autorização por sessão não atende o Portal.[2]

## 2. Requisitos inegociáveis

A implantação deverá cumprir todos os controles abaixo antes de habilitar o botão **Ver imagens**.

| Controle | Regra obrigatória |
|---|---|
| Isolamento da origem | O navegador nunca acessa `10.0.0.3:8042`, a API REST do Orthanc nem as credenciais do PACS |
| Anonimização | O estudo apresentado deve ser uma cópia anonimizada, com novo conjunto de UIDs; nunca o estudo clínico original |
| Dados em pixel | Cada modalidade deve ser validada para texto queimado em pixels. Anonimizar tags não remove marcações visuais incrustadas na imagem |
| Escopo | Um token só pode acessar um estudo anonimizado, de um único paciente, tenant e laudo liberado |
| Prazo | Sessão de imagem curta — recomendação de 15 minutos, sem renovação silenciosa |
| DICOMweb | QIDO/WADO/metadata/frame devem ser filtrados pelo gateway; nenhuma listagem geral, busca livre ou download de DICOM original |
| Auditoria | Criar e registrar abertura, expiração, falha, IP, tenant, `report_id`, study anonimizado e quantidade de acessos |
| Cache | `Cache-Control: no-store` nas respostas de autenticação; não incluir token em logs, analytics ou referer externo |
| Revisão | Teste de anonimização com amostras CT, MR, CR/DX, US e MG antes de ativação para pacientes reais |

## 3. Alternativas viáveis

| Alternativa | Como funciona | Vantagens | Limitações | Recomendação |
|---|---|---|---|---|
| **A. Gateway por demanda para cópia anonimizada** | Ao paciente abrir as imagens, a API valida a sessão e gera/recupera uma cópia anonimizada em repositório separado; o Viewer recebe sessão temporária e filtrada | Menor custo de armazenamento inicial; preserva o Orthanc original; auditoria precisa | Primeira abertura pode demorar; exige fila/controlador de cópia e limpeza de expiração | **Viável após implementação e homologação** |
| **B. Repositório anonimizado pré-gerado** | Após a liberação do laudo, um processo controlado prepara a cópia anonimizada em um Orthanc público isolado; o Portal só concede uma sessão curta para ela | Abertura mais rápida; forte separação entre produção clínica e Portal | Requer armazenamento adicional, rotina de expiração e monitoramento | **Mais robusta para produção** |
| **C. Abrir o Viewer interno diretamente** | O Portal chamaria o fluxo atual `/open/{token}` | Sem desenvolvimento inicial | Expõe o fluxo interno, o `StudyInstanceUID` e não aplica anonimização | **Não permitido** |

## 4. Arquitetura recomendada — alternativa B

```text
Paciente autenticado no Portal
        │
        ▼
POST /portal-image-sessions/{report_token}
        │ valida sessão, paciente, tenant, laudo liberado e consentimento
        ▼
API VOXEL PACS (CPX22)
        │ HTTPS privado + credencial exclusiva de serviço
        ▼
Orthanc clínico CPX32 (10.0.0.3:8042)
        │ gera ou localiza cópia anonimizada
        ▼
Orthanc/repositório anonimizado isolado
        │ DICOMweb somente atrás de gateway
        ▼
Gateway de imagens (`images.voxelpacs.com.br`)
        │ token curto, escopo de um estudo, auditoria por requisição
        ▼
OHIF dedicado ao Portal
```

O gateway precisa validar o token em **todas** as chamadas DICOMweb e limitar o estudo pelo identificador anonimizado armazenado no servidor. O cliente receberá apenas um token opaco de sessão; não receberá `orthanc_id`, UID original, URL do Orthanc, login ou segredo de serviço.

## 5. Implementações necessárias no VOXEL PACS

1. Criar a tabela `portal_image_sessions` com token armazenado por hash, `report_id`, `tenant_id`, estudo original, estudo anonimizado, data de expiração, IP, abertura e contagem de acesso.
2. Criar uma tabela/mapeamento de cópias anonimizadas para impedir duplicação e garantir limpeza programada.
3. Criar serviço de anonimização com perfil explícito de tags removidas, tags permitidas e versão DICOM documentada.
4. Criar endpoint autenticado do Portal que somente emite sessão para laudo liberado pertencente ao paciente autenticado.
5. Criar gateway DICOMweb restrito, sem acesso público à API nativa do Orthanc e sem `QIDO-RS` genérico.
6. Criar configuração OHIF exclusiva do Portal, sem ferramentas administrativas, sem upload, sem exportação de DICOM e sem ligação para Viewer interno.
7. Criar rotina de expiração e limpeza das sessões/cópias anonimizadas conforme política aprovada.
8. Adicionar testes automatizados de escopo, expiração, tenant, sessão de paciente e bloqueio de qualquer UID/origem clínica.

## 6. Homologação obrigatória

A funcionalidade só poderá ser ativada depois de todos os itens serem aprovados.

| Teste | Critério de aprovação |
|---|---|
| Laudo não liberado | Não gera sessão de imagem |
| Outro paciente/tenant | Recebe 404 genérico e nenhum detalhe do estudo |
| Token expirado | Não abre metadata, frames, thumbnail nem série |
| Token de estudo A no estudo B | Bloqueado no gateway |
| URL direta do Orthanc | Continua inacessível externamente |
| Tags DICOM | Não contêm nome, ID, nascimento, instituição, accession, médico, telefones nem tags privadas autorizadas indevidamente |
| Dados em pixel | Revisão manual documentada não encontra identificação queimada nas amostras aprovadas |
| Auditoria | Cada criação e abertura apresenta report, tenant, IP, horário, resultado e sessão sem registrar o token puro |
| Reversão | Desativar `PORTAL_IMAGES_ENABLED` bloqueia novas sessões imediatamente e não rompe laudos do Portal |

## 7. Estado atual e próxima decisão

Mantenha estas variáveis no CPX22:

```dotenv
PORTAL_IMAGES_ENABLED=false
PORTAL_IMAGES_ANONYMIZED=false
```

A próxima etapa é escolher **A** ou **B**. Para uma operação de saúde em produção, a recomendação é **B — repositório anonimizado separado**, pois evita que o Viewer público consulte qualquer dado do Orthanc clínico.

## Referências

[1]: https://orthanc.uclouvain.be/book/users/anonymization.html "Orthanc Book — Anonymization and modification"
[2]: https://orthanc.uclouvain.be/book/plugins/dicomweb.html "Orthanc Book — DICOMweb plugin"
