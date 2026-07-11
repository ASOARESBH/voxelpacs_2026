# Diagnóstico — Compatibilidade DICOM

## Checklist antes de alterar qualquer código que toca DICOM/Orthanc

- [ ] `StudyInstanceUID`, `SeriesInstanceUID`, `SOPInstanceUID` continuam sendo gerados/tratados de forma consistente (nenhum truncamento, nenhuma regeneração acidental).
- [ ] `AccessionNumber` e `InstitutionName` preservados corretamente no fluxo alterado.
- [ ] `Transfer Syntax` suportada não foi restringida sem intenção explícita.
- [ ] `AE Title` configurado corretamente se a alteração envolve comunicação C-STORE/C-MOVE/C-FIND/C-ECHO.
- [ ] Se a alteração toca MWL (Modality Worklist) ou MPPS, o formato esperado pelas modalidades (equipamentos) não foi quebrado.
- [ ] Testado (ou pelo menos analisado) contra pelo menos um estudo real/de teste com múltiplas séries, não só um caso trivial de série única.

## Regra geral

Compatibilidade DICOM não é negociável por conveniência de implementação — se uma mudança exigiria quebrar um desses pontos, isso deve ser levantado explicitamente como risco no plano da tarefa, não decidido silenciosamente.
