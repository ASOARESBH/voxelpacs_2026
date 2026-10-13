# Integração do gate root-controlled ao drift da PR #111

## Fluxo

```text
manifesto do último commit publicado (builder oficial)
        ↓ stdin
runtime-drift-gate --baseline-id
        ↓
leitor root-controlled do manifesto
        ↓ manifesto validado
validate-runtime-drift.sh
        ↓
DRIFT_GATE=PASS|FAIL
```

O leitor é responsável somente por validar e expor o manifesto técnico permitido. O comparador instalado em caminho administrativo fixo (versão 3) compara o runtime com o **último commit publicado** e os extras com o baseline. O código novo a publicar não participa da comparação, então o gate só falha quando a produção foi alterada fora do Git. Detalhes em `RUNTIME_DRIFT_VALIDATION.md`. Nenhum dos dois componentes publica arquivos.

## Pré-condições

- alteração commitada diretamente na `main`, enviada ao remoto e SHA completa confirmada;
- checkout local limpo e manifesto construído do SHA que está publicado em produção (informado pelo gate em `RUNTIME_PUBLISHED_SHA`);
- backup root-only e baseline integral já capturados;
- `baseline-id` confirmado por evidência administrativa;
- provisionamento do leitor/gate executado somente pela SHA aprovada;
- nenhum Worker, Job, Bridge, SMB, Philips, DICOM ou migration iniciado.

## Resultado

O gate pode ser usado no predeploy quando o manifesto root-owned não for legível pelo caller SSH. `PASS` significa somente que a comparação técnica passou; ainda são necessários backup, autorização e os demais gates do deploy formal.

Em `FAIL` ou `BLOCKED`, preservar o runtime e a evidência. Não copiar manualmente o manifesto, não alterar permissões do baseline e não substituir o runtime para contornar a decisão.
