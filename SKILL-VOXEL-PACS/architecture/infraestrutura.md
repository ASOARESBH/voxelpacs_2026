# Arquitetura — Infraestrutura

## Filas e Jobs

- Sistema de filas: `[A confirmar — Redis + biblioteca?]`
- Ver `indexes/eventos-filas.md` para o mapa fila-a-fila.

## Cache

- Onde é usado (sessão, resultado de query, resposta de API externa?): `[A confirmar]`
- Estratégia de invalidação: `[A confirmar]`

## Uploads / Ingestão de arquivos

- Fluxo de upload de DICOM manual (fora do C-STORE): `[A confirmar]`
- Limites de tamanho/validações aplicadas: `[A confirmar]`
- Onde os arquivos ficam armazenados (disco local, S3-compatível, dentro do Orthanc?): `[A confirmar]`

## Logs e Auditoria

- Onde ficam os logs de aplicação: `[A preencher caminho]`
- Existe log de auditoria específico para acesso a dados de paciente/exame (requisito comum em PACS)? `[A confirmar — se não existir, sinalizar como gap]`

## Containers / Deploy

- Orquestração: `[A confirmar — Docker Compose, Kubernetes, outro?]`
- Arquivos de configuração relevantes: `[A preencher caminhos]`
- Ambientes existentes (dev/staging/produção) e diferenças relevantes: `[A confirmar]`

## Configurações e variáveis de ambiente

- Onde ficam: `[A preencher caminho]`
- Variáveis sensíveis conhecidas (sem valores, só nomes/propósito): `[A preencher conforme necessário para a tarefa]`
