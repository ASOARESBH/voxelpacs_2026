# Integração nativa Imagiflow ↔ VOXEL PACS

**Versão do contrato:** 1.0
**Data:** 21 de agosto de 2026
**Estado:** VOXEL PACS implementado; consumo pelo Imagiflow pendente de implementação e homologação conjunta.

## 1. Objetivo e limites

O conector permite que o Imagiflow importe, para a apuração existente, os estudos que tenham laudo **assinado** ou **liberado** no VOXEL PACS. O fluxo é de leitura iniciada pelo Imagiflow: o VOXEL não cria, edita ou fatura apurações no Imagiflow.

> A apuração continua centralizada no módulo de clientes do Imagiflow. O conector substitui somente a origem manual da planilha pelos dados clínico-operacionais fornecidos pelo VOXEL PACS.

O contrato não fornece corpo de laudo, PDF, imagem DICOM, token público de relatório nem telefone/endereço de paciente. Ele fornece somente os dados mínimos compatíveis com a importação manual atual: identificação do estudo, paciente, médico, modalidade, prioridade, unidade, datas e SLA.

| Responsabilidade | VOXEL PACS | Imagiflow |
|---|---|---|
| Credencial por negócio | Gera, cifra, revoga e audita | Armazena como segredo por empresa/usuário |
| Médico | Confirma médico ativo por CRM ou nome | Vincula o médico local antes da importação |
| Estudos concluídos | Expõe página de estudos assinados/liberados no período | Cria itens da apuração cliente e sub-apurações por prestador |
| Valores e faturamento | Não calcula valores financeiros | Aplica contrato, tabela do cliente e regra de prestador existente |
| Idempotência | Entrega `source_reference` estável | Impede duplicação por `usuario_id + source_reference` |

## 2. Habilitação por negócio

No VOXEL PACS, um superadmin acessa:

```text
Plataforma → Negócios → [Negócio] → Conector Imagiflow
```

O botão **Gerar código e chave** ativa a integração e retorna apenas uma vez:

| Campo | Uso no Imagiflow |
|---|---|
| `integration_code` | Identifica o negócio VOXEL. Pode ser salvo como texto de configuração. |
| `secret` | Segredo HMAC de 256 bits. Armazenar cifrado; nunca registrar em log, tela ou arquivo. |

Regenerar a credencial invalida imediatamente a chave anterior. Revogar a integração bloqueia novas chamadas sem modificar apurações já importadas.

## 3. Autenticação HMAC obrigatória

Todos os endpoints são `POST`, recebem `Content-Type: application/json` e usam os cabeçalhos abaixo.

| Cabeçalho | Valor |
|---|---|
| `X-Imagiflow-Code` | `integration_code` fornecido pelo VOXEL |
| `X-Imagiflow-Timestamp` | Unix timestamp em segundos, com tolerância máxima de 5 minutos |
| `X-Imagiflow-Signature` | HMAC SHA-256 em hexadecimal minúsculo |
| `X-Request-Id` | Identificador único de 8–64 caracteres (`UUID` recomendado) |

A string canônica é exatamente:

```text
POST\n{PATH}\n{UNIX_TIMESTAMP}\n{SHA256_DO_CORPO_BRUTO}
```

A assinatura é:

```text
hex(hmac_sha256(string_canonica, secret))
```

Exemplo conceitual em PHP:

```php
$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$timestamp = (string) time();
$hash = hash('sha256', $body);
$canonical = "POST\n{$path}\n{$timestamp}\n{$hash}";
$signature = hash_hmac('sha256', $canonical, $secret);
```

Não repetir uma mesma chamada com timestamp vencido. Em falha de rede, gerar novo `X-Request-Id`, novo timestamp e nova assinatura; a importação de itens deve continuar idempotente pelo `source_reference`.

## 4. Consulta de médico

**Endpoint:** `POST https://server.voxelpacs.com.br/api/integracoes/imagiflow/v1/medicos/consultar`

O request deve ter CRM, nome ou ambos. A busca prioriza CRM normalizado; se não houver CRM, usa todos os termos significativos do nome.

```json
{
  "crm": "123234",
  "nome": "JOAO DE TESTE"
}
```

Resposta de sucesso:

```json
{
  "ok": true,
  "request_id": "1bdb422d-2b61-46a1-899c-907de3e1a0a1",
  "data": {
    "found": true,
    "matches": [
      {
        "medico_id": 2,
        "nome": "JOAO DE TESTE",
        "crm": "123234",
        "crm_uf": "SP",
        "especialidade": "Radiologia",
        "ativo": true
      }
    ]
  }
}
```

O Imagiflow só deve liberar a importação automática quando `found` for `true`. Havendo zero ou múltiplos médicos, deve apresentar uma tela de conciliação manual e não atribuir estudos automaticamente.

## 5. Consulta de estudos para apuração

**Endpoint:** `POST https://server.voxelpacs.com.br/api/integracoes/imagiflow/v1/apuracao/estudos`

Payload mínimo:

```json
{
  "periodo_inicio": "2026-08-01",
  "periodo_fim": "2026-08-31",
  "medico_crm": "123234",
  "pagina": 1,
  "por_pagina": 100
}
```

O período máximo é de 93 dias. `medico_crm` e `unidade` são filtros opcionais. A resposta contém somente estudos cujo report esteja assinado ou liberado; o período é baseado em `liberado_em`, com fallback para `assinado_em`.

```json
{
  "ok": true,
  "request_id": "1bdb422d-2b61-46a1-899c-907de3e1a0a1",
  "data": {
    "periodo_inicio": "2026-08-01",
    "periodo_fim": "2026-08-31",
    "pagina": 1,
    "por_pagina": 100,
    "total": 1,
    "total_paginas": 1,
    "itens": [
      {
        "estudo_id": 1287,
        "unidade": "NOVA IMAGEM - CAMBUI",
        "paciente_nome": "PACIENTE EXEMPLO",
        "paciente_id": "51243",
        "modalidade": "CT",
        "study_description": "TC TÓRAX",
        "prioridade": "ROUTINE",
        "accession_number": "51227",
        "study_instance_uid": "1.2.840...",
        "medico_nome": "JOAO DE TESTE",
        "medico_crm": "123234",
        "medico_crm_uf": "SP",
        "assinado_em": "2026-08-20 15:40:00-03",
        "liberado_em": "2026-08-20 15:45:00-03",
        "situacao": "liberado",
        "sla_minutos": 42,
        "source_reference": "voxel:2:report:32",
        "origem": "voxel_pacs",
        "data_estudo": "2026-08-20 14:42:55",
        "data_conclusao": "2026-08-20 15:45:00-03"
      }
    ]
  }
}
```

## 6. Mapeamento para o importador manual existente

| Campo Imagiflow | Campo VOXEL | Observação |
|---|---|---|
| `unidade` | `unidade` | InstitutionName canônico do negócio |
| `medico_nome` | `medico_nome` | Conciliar primeiro por CRM |
| `medico_crm` | `medico_crm` | Normalizar para dígitos no Imagiflow |
| `modalidade` | `modalidade` | Pode conter modalidades DICOM múltiplas |
| `study_description` | `study_description` | Já possui fallback clínico seguro |
| `paciente_nome` | `paciente_nome` | Necessário para a tela detalhada da apuração |
| `paciente_id` | `paciente_id` | Identificador operacional, não usar como credencial |
| `prioridade` | `prioridade` | Converter `HIGH`/`STAT` para urgência conforme regra existente |
| `data_estudo` | `data_estudo` | Data/hora do estudo |
| `data_conclusao` | `data_conclusao` | Liberação ou assinatura |
| `sla` | `sla_minutos` | Duração em minutos |
| `accession_number` | `accession_number` | Campo operacional de auditoria |
| `registro` | `source_reference` | **Chave de idempotência obrigatória** |
| `origem` | `origem` | Persistir `voxel_pacs` |

No Imagiflow, criar ou reutilizar uma tabela de controle com chave única por `usuario_id` e `source_reference`. Antes de inserir um `apuracao_item`, consultar essa chave. Quando já existir, ignorar o item ou atualizá-lo apenas se a apuração ainda estiver em rascunho e a política local permitir reimportação.

## 7. Fluxo obrigatório no Imagiflow

1. Adicionar configuração por usuário/empresa: URL base, código e segredo cifrado.
2. Criar cliente HMAC reutilizável, com timeout de 15 segundos e sem seguir redirecionamentos.
3. Na tela de apuração cliente, selecionar período e opcionalmente médico/unidade.
4. Consultar médico no VOXEL pelo CRM. Não prosseguir automaticamente se houver ambiguidade.
5. Buscar todas as páginas de estudos e validar `source_reference` antes de inserir.
6. Alimentar o mesmo serviço de cálculo usado na importação manual. Não duplicar tabela de preço, cálculo de venda ou geração de sub-apuração.
7. Mostrar resumo de importados, já existentes, sem médico conciliado e erros de comunicação.
8. Registrar auditoria sem tokens, assinatura HMAC ou corpo clínico do laudo.

## 8. Homologação conjunta posterior

A implementação do Imagiflow deve ser homologada com uma credencial temporária de um negócio de teste. O roteiro mínimo é:

| Cenário | Resultado esperado |
|---|---|
| Sem cabeçalhos HMAC | `401` sem detalhe de segredo |
| Timestamp vencido | `401` |
| Assinatura inválida | `401` e log sanitizado no VOXEL |
| Médico não cadastrado no VOXEL | `found: false`; não criar item automático |
| Médico com CRM compatível | `found: true`; importar apenas estudos daquele médico |
| Laudo rascunho/em laudo | Não retornar na API |
| Repetir sincronização | Não duplicar item por `source_reference` |
| Outro negócio | Não acessar médicos ou estudos do tenant configurado |
| Revogar credencial | Chamadas seguintes retornam `401` |

## 9. Limites de segurança

Não inclua a chave em URL, query string, log de HTTP, planilha, e-mail ou JavaScript. Não exponha corpo de laudo, PDF, DICOM ou token de resultado por esta integração. O acesso é estritamente do tenant identificado pela credencial e tem finalidade de apuração administrativa.

A implantação no Imagiflow deve ser feita em branch própria, com migration de idempotência, testes automatizados e revisão manual antes da primeira sincronização real.
