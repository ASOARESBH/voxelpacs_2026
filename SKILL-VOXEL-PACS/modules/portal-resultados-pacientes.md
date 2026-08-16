# Portal de Resultados para Pacientes

## Escopo inicial

O Portal é atendido pelo mesmo codebase do VOXEL PACS exclusivamente quando o host é `portal.voxelpacs.com.br`. O `public/index.php` carrega somente `routes/portal.php` nesse host; rotas, menu, sessão e autenticação interna do PACS não são disponibilizados ao paciente.

A primeira entrega oferece **histórico de estudos e laudos liberados**. A abertura de imagens OHIF/VOXEL View não integra esta fase, pois o visualizador interno ainda termina em URL com `StudyInstanceUID`.

## Autenticação e privacidade

A identificação pede nome completo, data de nascimento e sexo. O nome é normalizado removendo o separador DICOM `^`, acentos, diferença de caixa e espaços repetidos. O Portal exige sempre a confirmação de instituição, inclusive quando há somente um resultado compatível.

> Falhas de identificação, instituição inválida e bloqueio recebem a mesma resposta: `Não foi possível confirmar seus dados. Verifique as informações e tente novamente.`

O limite é de cinco falhas na janela de quinze minutos por IP ou hash da identidade. A quinta falha bloqueia silenciosamente por cinco minutos. Auditoria armazena hash SHA-256 da identidade, IP, etapa e resultado; nome completo não é persistido nas tabelas do Portal.

A sessão fica em PHP e é vinculada à sessão registrada em `bi_portal_sessions`, ao IP, ao tenant confirmado e à expiração por inatividade de trinta minutos. O host do Portal usa cookies `HttpOnly`, `Secure` em HTTPS e `SameSite=Strict`.

## Estruturas e rotas

| Recurso | Responsabilidade |
|---|---|
| `bi_portal_login_attempts` | Rate limit e auditoria de tentativas. |
| `bi_portal_challenges` | Desafio temporário de instituição com quatro opções. |
| `bi_portal_sessions` | Sessão temporária vinculada à identidade hash, IP e tenant. |
| `GET /` | Identificação do paciente no host do Portal. |
| `POST /identificar` | Cria sempre o desafio institucional. |
| `POST /instituicao` | Valida desafio e cria sessão. |
| `GET /resultados` | Histórico escopado à identidade e ao tenant. |
| `GET /laudo/{public_token}` | Reutiliza `reports.public_token`; permite somente report `liberado`. |
| `POST /sair` | Revoga sessão do Portal. |

## Implantação

1. No HostGator, configure `portal.voxelpacs.com.br` com document root apontando para a pasta `public/` do mesmo codebase do PACS.
2. Execute `database/migrations/2026-08-16_portal_resultados_pacientes.sql` **no schema do VOXEL PACS**.
3. Confirme HTTPS válido no subdomínio para que o cookie seguro seja enviado.
4. Teste identificação válida, instituição errada, cinco falhas, laudo liberado, laudo pendente, sessão expirada e logout.

Não criar links por `report_id`, `estudo_id`, PatientID ou StudyInstanceUID. O Portal usa apenas token opaco de laudo já existente em `reports.public_token`.
