# Ditado por Voz no Laudário

## Escopo da primeira entrega

O ditado está disponível no Laudário para médicos em modo de edição por meio do botão **Ditar** na barra superior. A primeira entrega usa exclusivamente a interface nativa de reconhecimento de fala do navegador (`SpeechRecognition`/`webkitSpeechRecognition`), configurada para `pt-BR`. Não existe endpoint de transcrição, upload, persistência de áudio ou credencial de provedor no VOXEL PACS nesta fase.

A digitação manual permanece o caminho principal. Uma falha de microfone, de rede do navegador ou de reconhecimento apenas mostra uma mensagem de estado; nunca bloqueia a edição, o autosave ou a assinatura do laudo.

## Integração técnica

| Arquivo | Responsabilidade |
|---|---|
| `app/Views/layout/reports_header.php` | Exibe o botão `#btn-dictate` e a área acessível de status. O botão só é renderizado quando o laudo pode ser editado. |
| `app/Views/layout/reports_footer.php` | Carrega `reports-dictation.js` depois de `reports-editor.js`. |
| `public/assets/js/reports/reports-main.js` | Inicializa `window.VoxelReports.dictation` depois do editor e antes do autosave. |
| `public/assets/js/reports/reports-dictation.js` | Detecta suporte, controla o ciclo de reconhecimento, converte comandos de pontuação e insere o texto final no Quill. |
| `public/assets/css/reports.css` | Define os estados de gravação, aviso, erro, sucesso, foco e movimento reduzido. |

O módulo não lê novamente os atributos da tela. Ele recebe a configuração canônica já criada por `reports-main.js`, respeita `readonly` e usa `window.VoxelReports.editor.getQuill()` para inserir somente o trecho reconhecido na posição atual do cursor. A inserção usa `source: 'user'`, o que preserva os listeners de autosave e de autotexto.

## Comandos de fala

| Expressão falada | Resultado no editor |
|---|---|
| `ponto` ou `ponto final` | `. ` |
| `vírgula` | `, ` |
| `dois pontos` | `: ` |
| `ponto e vírgula` | `; ` |
| `nova linha` ou `quebra de linha` | quebra de linha |
| `novo parágrafo` | duas quebras de linha |

Os comandos são uma conveniência de edição e não alteram o conteúdo já existente do laudo. Cada resultado final é inserido incrementalmente. O reconhecimento provisório fica apenas no indicador visual, não é persistido no editor e não é enviado ao backend.

## Privacidade, LGPD e compatibilidade

Nenhum áudio é enviado ao backend do VOXEL PACS, gravado ou mantido pela aplicação nesta fase. Contudo, a Web Speech API é uma funcionalidade do navegador e a disponibilidade, política de processamento e qualidade são determinadas pelo fornecedor do navegador. Profissionais devem evitar ditar identificadores desnecessários do paciente e utilizar apenas estações autorizadas da instituição.

O recurso é habilitado no Google Chrome e Microsoft Edge quando o navegador expõe uma implementação compatível. Em navegadores sem suporte, como situações comuns de Firefox e Safari, o botão é desabilitado e informa claramente que a digitação manual segue disponível.

## Evolução para provedor em nuvem

A Fase 2 somente pode ser iniciada após decisão explícita do responsável técnico e jurídico sobre o provedor, contrato de tratamento de dados de saúde, região de processamento, custos por minuto, política de retenção e credenciais em variáveis de ambiente. A implementação futura deve incluir `DictationService`, autorização por `ReportAccessService`, CSRF, auditoria sem conteúdo transcrito e descarte imediato do áudio bruto. Nenhuma dessas integrações foi criada nesta primeira entrega.
