# Arquitetura — Visão Geral

> Objetivo deste arquivo: dar, em uma leitura, o mapa mental completo do sistema — as camadas, como se conectam, e para onde ir para o detalhe de cada uma. Detalhe fino de cada camada vive em arquivo próprio nesta mesma pasta.

## Camadas do sistema

```
┌─────────────────────────────────────────────────────────┐
│  Frontend (telas, componentes, viewer DICOM/OHIF)        │  → architecture/frontend.md
├─────────────────────────────────────────────────────────┤
│  Backend (controllers, services, repositories, API)      │  → architecture/backend.md
├─────────────────────────────────────────────────────────┤
│  Banco de dados (schema, migrations)                     │  → architecture/banco-de-dados.md
├─────────────────────────────────────────────────────────┤
│  Integrações (Orthanc, HL7, RIS, HIS)                     │  → architecture/integracoes.md
├─────────────────────────────────────────────────────────┤
│  Infraestrutura (fila, cache, uploads, logs, assets)      │  → architecture/infraestrutura.md
├─────────────────────────────────────────────────────────┤
│  Autenticação e Permissões                                │  → architecture/auth-e-permissoes.md
└─────────────────────────────────────────────────────────┘
```

## Como as camadas conversam (preencher conforme confirmado)

- Frontend → Backend: `[A confirmar — REST? GraphQL? Ambos?]`
- Backend → Banco: `[A confirmar — ORM usado, Repository Pattern?]`
- Backend → Orthanc: `[A confirmar — REST API direta? DICOMweb? Plugin Lua customizado?]`
- Backend → HL7: `[A confirmar — biblioteca/engine de parsing, canal de entrada (MLLP?)]`
- Backend → Filas: `[A confirmar — Redis + qual biblioteca de filas?]`

## Fluxos críticos de ponta a ponta (mapear conforme analisados)

Estes são os fluxos onde um bug tem maior impacto clínico/operacional — priorize documentá-los antes de fluxos administrativos:

1. **Ingestão de estudo DICOM** (Orthanc recebe → sistema é notificado → metadados são persistidos → aparece na worklist) — `[A detalhar em modules/ingestao-dicom.md]`
2. **Geração e liberação de laudo** (RIS/HIS → laudo → assinatura → distribuição) — `[A detalhar em modules/laudos.md]`
3. **Mensageria HL7 de admissão/pedido** (ADT/ORM recebido → paciente/pedido criado ou atualizado no PACS) — `[A detalhar em modules/hl7-integracao.md]`
4. **Autenticação e controle de acesso a exames** (login → permissão → acesso ao estudo) — `[A detalhar em modules/auth.md]`

## Diagrama de dependências entre módulos

Ver `architecture/dependencias.md` — mantido separado porque tende a crescer e mudar com frequência; misturá-lo aqui tornaria este arquivo desatualizado rápido demais.

## Regra de manutenção deste arquivo

Este arquivo deve responder "como o sistema se encaixa" em menos de 2 minutos de leitura. Se você sentir vontade de adicionar mais de um parágrafo sobre uma camada específica, esse detalhe pertence ao arquivo dedicado da camada (`architecture/<camada>.md`), não aqui.
