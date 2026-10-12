# Guia de Contribuição — VOXEL PACS

Obrigado por contribuir com o VOXEL PACS! Este guia descreve como colaborar com o projeto.

## Fluxo de Trabalho

O VOXEL PACS não usa branches de feature, Pull Request, revisão nem merge. Toda alteração pelo Git é feita **somente por commit direto na `main`** (commit + push).

1. **Atualize a `main` local** com a árvore de trabalho limpa:
   ```bash
   git checkout main
   git pull --ff-only origin main
   ```

2. **Faça suas alterações** seguindo as convenções do projeto e **teste localmente**.

3. **Commit direto na `main`** seguindo o padrão Conventional Commits:
   ```
   feat: adiciona módulo de agendamentos
   fix: corrige erro no login com tenant inativo
   docs: atualiza README com instruções de deploy
   refactor: extrai lógica de KPI para service dedicado
   ```

4. **Envie para o remoto** com `git push origin main`. Nunca use force push nem reescreva o histórico da `main`.

## Convenções de Código

- **PHP:** PSR-4 para autoload, PSR-12 para estilo de código
- **Banco:** Migrations idempotentes, charset `utf8/utf8_unicode_ci`
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/)
- **Branch:** somente `main` (commit direto, sem PR/merge)

## Regras Importantes

- Nunca commite o arquivo `.env`
- Nunca altere regras de negócio sem aprovação
- Nunca altere o schema do banco sem migration idempotente
- Sempre teste localmente antes de commitar na `main`

## Dúvidas

Abra uma issue ou entre em contato: andre@voxelpacs.com.br
