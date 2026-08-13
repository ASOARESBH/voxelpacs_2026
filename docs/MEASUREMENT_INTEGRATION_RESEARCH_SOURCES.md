# Referências técnicas — VOXEL Measurement Integration Layer

Este arquivo preserva as fontes externas consultadas para o desenho da extensão OHIF e deve ser lido juntamente com `MEASUREMENT_INTEGRATION_DESIGN.md`.

| Fonte | URL | Achado aplicado |
|---|---|---|
| OHIF Measurement Service | https://docs.ohif.org/platform/services/data/measurementservice/ | O `MeasurementService` é a representação interna das medições; oferece `getMeasurements`, fontes/mappers e eventos de add, update, raw add, remove, clear e jump. O adapter VOXEL apenas o consome. |
| OHIF Extension Lifecycle | https://docs.ohif.org/platform/extensions/lifecycle/ | `preRegistration` registra serviços; `onModeEnter` conecta recursos do modo; `onModeExit` remove listeners e limpa recursos transitórios. |
| OHIF Service Manager | https://docs.ohif.org/platform/managers/service | Serviços são obtidos por `servicesManager.services`; um serviço customizado deve ter `name` e `create`, e o lifecycle restaura/limpa estado entre modos. |
| OHIF Extension Installation | https://docs.ohif.org/platform/extensions/installation/ | OHIF suporta extensões externas; para produção, a extensão precisa fazer parte de uma build/imagem compatível com a versão do viewer. |
| OHIF v3.12.5 — MeasurementService | https://github.com/OHIF/Viewers/blob/v3.12.5/platform/core/src/services/MeasurementService/MeasurementService.ts | Fonte de verdade para nomes de eventos, schema aceito e payloads na versão implantada. |
| OHIF v3.12.5 — ExtensionManager | https://github.com/OHIF/Viewers/blob/v3.12.5/platform/core/src/extensions/ExtensionManager.ts | O manager chama hooks `onModeEnter` e `onModeExit` das extensões registradas. |
| OHIF v3.12.5 — pluginConfig | https://github.com/OHIF/Viewers/blob/v3.12.5/platform/app/pluginConfig.json | O pluginConfig registra módulos que a build importa dinamicamente. |

> A documentação pública atual exibe conteúdo de versões mais novas do OHIF. Por isso, decisões de código foram validadas contra os arquivos da tag `v3.12.5`, que corresponde ao container de produção do VOXEL VIEW.
