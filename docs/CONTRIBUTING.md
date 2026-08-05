# Guia de Contribuição — VOXEL PACS

Obrigado por contribuir com o VOXEL PACS! Este guia descreve como colaborar com o projeto.

## Fluxo de Trabalho

1. **Crie uma branch** a partir de `development`:
   ```bash
   git checkout development
   git pull origin development
   git checkout -b feat/nome-da-funcionalidade
   ```

2. **Faça suas alterações** seguindo as convenções do projeto.

3. **Commit** seguindo o padrão Conventional Commits:
   ```
   feat: adiciona módulo de agendamentos
   fix: corrige erro no login com tenant inativo
   docs: atualiza README com instruções de deploy
   refactor: extrai lógica de KPI para service dedicado
   ```

4. **Abra um Pull Request** para a branch `development`.

## Convenções de Código

- **PHP:** PSR-4 para autoload, PSR-12 para estilo de código
- **Banco:** Migrations idempotentes, charset `utf8/utf8_unicode_ci`
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/)
- **Branches:** `feat/`, `fix/`, `docs/`, `refactor/`, `chore/`

## Regras Importantes

- Nunca commite o arquivo `.env`
- Nunca altere regras de negócio sem aprovação
- Nunca altere o schema do banco sem migration idempotente
- Sempre teste localmente antes de abrir PR

## Dúvidas

Abra uma issue ou entre em contato: andre@voxelpacs.com.br
