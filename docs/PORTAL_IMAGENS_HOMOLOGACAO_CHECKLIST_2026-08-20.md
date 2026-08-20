# Homologação de imagens anonimizadas no Portal de Resultados

**Estado atual:** arquitetura B implantada em homologação. O pipeline interno está ativo para preparar cópias isoladas após a liberação do laudo. A visualização pública permanece bloqueada por `PORTAL_IMAGES_ENABLED=false` e `PORTAL_IMAGES_ANONYMIZED=false`.

> A ativação pública é proibida enquanto qualquer item deste checklist estiver pendente. A cópia anonimizada não substitui a análise humana de identificação queimada em pixels.

| Controle | Implementação atual | Evidência exigida para aprovação |
|---|---|---|
| Separação do PACS clínico | Orthanc anonimizado local em `127.0.0.1:8044`, sem porta pública | Serviço ativo, UFW e teste externo bloqueado |
| Sessão temporária | Token opaco com SHA-256, 15 minutos, cookie HttpOnly/SameSite Strict | Teste de expiração e ausência de token retornando 404 genérico |
| Escopo | Gateway aceita somente o UID anonimizado da sessão e uma rota DICOMweb específica | Teste de UID do estudo A solicitado contra sessão B bloqueado |
| Laudo liberado/paciente | Emissão consulta apenas report liberado no escopo da sessão do paciente | Teste de laudo pendente e outro paciente/tenant retornando 404 |
| Tags DICOM | Perfil remove identificadores e tags privadas; preserva somente atributos técnicos definidos | Amostra DICOM revisada com a lista de tags removidas |
| Dados em pixel | `pixel_review_status=pending` bloqueia emissão de sessão | Revisão humana documentada de amostras, por modalidade, sem texto queimado identificável |
| Viewer | OHIF estático same-origin, sem upload, exportação, worklist ou URL do Orthanc clínico | Inspeção de `app-config.js` e teste de navegação |
| Auditoria | Eventos de fila, emissão, abertura, negação e IP sem token puro | Consulta de `bi_portal_image_audit` para uma amostra aprovada |
| Limpeza | Worker a cada 10 minutos, sessão expirada revogada e cópia expirada removida do repositório isolado | Log do serviço e conferência de estado `purged` |
| Reversão | Flags de Viewer são independentes do pipeline | Desabilitar `PORTAL_IMAGES_ENABLED` impede novas sessões sem afetar PDF/laudos |

## Roteiro de revisão humana

O superadmin acessa `/platform/negocios/{tenantId}/portal-imagens` e encontra as cópias preparadas. A revisão deve ocorrer em ambiente administrativo controlado, usando a cópia no repositório anonimizado local. O revisor registra **Aprovar** apenas após examinar amostras suficientes para as modalidades presentes e confirmar que não há nome, ID, nascimento, instituição, accession, médico, telefone ou marca queimada identificável. Se houver qualquer ocorrência, use **Rejeitar**; a cópia muda para `failed` e não poderá gerar sessão.

## Testes de aceitação obrigatórios

| Cenário | Resultado esperado |
|---|---|
| Laudo em rascunho/assinado sem liberar | Não cria cópia nem sessão |
| Outro paciente ou outro tenant | HTTP 404 genérico sem identificador do estudo |
| Token ausente, inválido ou expirado | HTTP 404 genérico para metadata, frames, thumbnails e séries |
| Token do estudo A em URL do estudo B | HTTP 404 genérico |
| Gateway DICOMweb genérico/QIDO-RS | Não existe rota pública |
| Orthanc anonimizado por IP/porta | Indisponível fora do CPX22 |
| Viewer | Sem upload, exportação, worklist, configuração dinâmica ou origem clínica |
| Retenção | Sessões revogadas e cópias apagadas após expiração |

## Ordem de ativação futura

Após todas as evidências acima, a aprovação deve ocorrer em duas etapas: primeiro `PORTAL_IMAGES_ANONYMIZED=true`; depois, em teste supervisionado, `PORTAL_IMAGES_ENABLED=true`. A reversão é imediata: defina `PORTAL_IMAGES_ENABLED=false`, recarregue PHP-FPM se houver cache de ambiente e valide que a tela de proteção voltou a ser exibida.
