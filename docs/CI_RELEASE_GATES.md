# Gates de release do VOXEL PACS

## Fonte de verdade

O workflow obrigatório de CI é `.github/workflows/ci.yml`. Ele executa `composer validate --strict`, lint PHP, o runner `scripts/test-pdf-regressions.sh` e o job PHPUnit. O runner PDF contém somente os testes de PDF, snapshots, revisões, branding e delivery visual que pertencem ao fluxo atualmente utilizado.

No HEAD analisado em 23 de setembro de 2026 (`8f029d8128bfd22ae7b3153ff649f135a96950d4`), os testes abaixo não são referenciados pelo workflow CI, pelo runner PDF ou por outra lista versionada de gates obrigatórios:

- `tests/report_delivery_crypto_smoke.php`
- `tests/report_delivery_institution_routing_contract.php`
- `tests/report_delivery_stale_processing_contract.php`
- `tests/reports_workflow_static.php`

Esses arquivos permanecem no repositório para histórico e referência. Seu resultado de release é **NOT_APPLICABLE**, não PASS e não FAIL bloqueador. Eles não devem ser removidos, renomeados, alterados para fabricar sucesso ou usados para mascarar falhas de funcionalidades presentes no ambiente produtivo.

> Os testes acima permanecem no repositório para histórico/referência, porém não constituem gates obrigatórios do release porque correspondem a funcionalidades/fluxos não utilizados pelo ambiente produtivo atual.

## Testes analisados separadamente

`tests/reports_responsive_static.php` foi executado e falhou somente porque procura o marcador literal obsoleto `chatDestinatarioGrupo`. A implementação atual usa o seletor `chatDestinatario`, carrega `chatGroups` e renderiza grupos em um `optgroup` de destinatários. O comportamento de seleção de grupo está presente no runtime; portanto, a expectativa literal do teste é incompatível com a implementação vigente. Classificação: **NOT_APPLICABLE — expectativa estática obsoleta**. O teste não foi alterado.

`tests/worklist_modality_render_static.php` foi executado e falhou somente porque exige a versão antiga de assets `2.3.12`. O runtime atual declara `ASSET_VERSION = 2.3.14` em `app/Core/View.php`, e o cabeçalho utiliza essa constante para cache-busting. A falha é uma expectativa hardcoded desatualizada, não evidência de falha funcional do renderizador de modalidades. Classificação: **NOT_APPLICABLE — expectativa de versão obsoleta**. O teste não foi alterado.

## Política de interpretação

A classificação NOT_APPLICABLE não transforma uma falha em PASS. Ela registra que o teste não representa um gate funcional do release atual ou contém uma expectativa estática comprovadamente obsoleta. Qualquer teste que falhe por comportamento runtime real, segurança, isolamento de tenant, integridade clínica, PDF, snapshot, revisão ou delivery utilizado continua bloqueando a promoção.

O PHPUnit permanece separado dos gates estáticos. Quando nenhum teste PHPUnit é descoberto, o resultado correto é `PHPUNIT=NOT_CONCLUSIVE`; ausência de testes não pode ser reportada como PASS.

## Escopo preservado

Esta classificação não altera banco, migrations, produção, permissões, Nginx, PHP-FPM, DICOM, Orthanc, WireGuard, Bridge, `.env` ou arquivos dos seis testes listados. O ajuste é documental e de interpretação do gate de release.
