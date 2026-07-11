# Memória — Regras de Negócio Conhecidas

> Regras que, se violadas, geram bug clínico/operacional grave — não erro de sintaxe, erro de confiança no sistema. Documentar aqui assim que confirmadas no código ou explicadas por alguém do time.

| Regra | Onde é aplicada (arquivo/módulo) | Consequência se violada |
|---|---|---|
| **Modalidade de um estudo (Study) = conjunto de Modalities distintas de suas Series**, na ordem em que aparecem, unidas por `\` (mesmo separador da tag DICOM `ModalitiesInStudy` 0008,0061). Um Study com Series CT e Series PT é `"CT\PT"`, não "CT" nem "PT" isoladamente — a coluna `bi_pacs_estudos.modalities` e a coluna "M" do worklist devem sempre exibir todas, nunca só a primeira. Fonte: `OrthancService::fetchModalitiesInStudy()`, agregando `MainDicomTags.Modality` de `GET /studies/{id}/series` (Modality é atributo de Series, não de Study — nunca leia de `MainDicomTags` do Study diretamente, ver `modules/worklist-estudos.md`) | `app/Services/OrthancService.php` (população), `app/Views/estudos/index.php` (`explode('\\', ...)`, exibição) | Estudo multi-modalidade aparece com modalidade errada/incompleta na coluna "M" e no filtro de modalidade do worklist, levando o usuário a não encontrar (ou mal-classificar) o exame |

## Exemplos do tipo de regra que pertence aqui (preencher com as reais do projeto)

- Quem pode ver um estudo de qual instituição
- Quem pode assinar/liberar um laudo
- O que acontece se uma mensagem HL7 chega duplicada (idempotência)
- O que acontece se o Orthanc está indisponível no momento de uma consulta

## Regra de manutenção

Toda vez que uma tarefa revelar uma regra de negócio que não estava aqui — mesmo que descoberta "de lado" enquanto se fazia outra coisa — registre antes de encerrar a tarefa.
