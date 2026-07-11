# Prompt interno — Analisar Módulo

Use este roteiro sempre que precisar entender um módulo que ainda não está (ou não está mais atualizado) em `modules/`.

```
Objetivo: produzir/atualizar modules/<nome-do-modulo>.md

1. Localizar os arquivos do módulo via indexes/mapa-indices.md (não listar diretórios às cegas).
2. Ler apenas os arquivos localizados + suas dependências diretas (architecture/dependencias.md).
3. Resumir em modules/<nome-do-modulo>.md:
   - Propósito do módulo (1-2 frases)
   - Arquivos principais e o que cada um faz (não o código inteiro, o papel)
   - Dependências (quem ele chama, quem o chama)
   - Padrões seguidos (referenciar patterns/ em vez de repetir)
   - Riscos conhecidos / pontos frágeis, se algum for observado
   - Data da análise
4. Atualizar indexes/mapa-indices.md se algum caminho não estava documentado.
```

O resultado deve ser suficiente para que uma tarefa futura NÃO precise reler os arquivos-fonte deste módulo, apenas este resumo — a menos que o índice indique que o código mudou.
