# Diagnóstico — Performance

## Queries lentas / SQL repetido

- Procurar queries dentro de loops (N+1) — comum ao carregar lista de estudos com dados relacionados (paciente, série, laudo) um a um.
- Confirmar se colunas usadas em `WHERE`/`JOIN` frequentes têm índice (ver `indexes/tabelas-banco.md`).
- SQL repetido: mesma query (ou muito parecida) escrita em múltiplos Repositories — candidato a método compartilhado.

## Cache

- Verificar se dados de leitura frequente e baixa mutabilidade (ex: configuração, permissões, metadados de instituição) já usam cache (ver `architecture/infraestrutura.md`).
- Cuidado ao cachear dado clínico/estudo: invalidação incorreta pode mostrar dado desatualizado num contexto onde isso importa — documentar a estratégia de invalidação explicitamente ao introduzir cache novo.

## Filas

- Jobs que deveriam ser assíncronos mas estão rodando de forma síncrona no request (ex: processar imagem DICOM, enviar HL7) — candidato a mover para fila (ver `indexes/eventos-filas.md`).

## Ao encontrar um problema de performance

Documentar em `modules/<modulo>.md` com o padrão observado e o impacto estimado, mesmo que a correção não seja feita imediatamente — isso evita que a mesma investigação seja refeita do zero na próxima vez que alguém notar lentidão na mesma área.
