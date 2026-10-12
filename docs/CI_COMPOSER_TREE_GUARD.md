# Guard de compatibilidade Composer dos gates

O código e as dependências usadas por um gate devem pertencer à mesma árvore Git. O script `scripts/verify-composer-tree.sh` valida `composer.json`, `composer.lock`, o `HEAD` testado e `vendor/composer/installed.php` antes de permitir os gates.

O guard bloqueia a execução quando o `vendor/` é um symlink para fora da árvore, quando os metadados instalados não informam uma referência válida do código-fonte, quando a referência final do vendor diverge do `HEAD` ou quando o manifest/lock está alterado. O builder pode normalizar atomicamente apenas o campo estrutural `root.reference` quando Composer deixou uma referência hexadecimal válida de outra revisão; referências ausentes, ambíguas ou malformadas continuam bloqueadas. Nesses casos ele emite `COMPOSER_TREE_MISMATCH=FAIL` e encerra com código diferente de zero.

Em uma árvore temporária, o procedimento correto é executar `composer install` dentro da própria árvore, normalizar somente `root.reference` para o `HEAD` validado e validar novamente. O caller deve executar `verify-composer-tree.sh` com o diretório corrente apontando para essa mesma árvore; informar o caminho do script sem mudar o cwd pode validar outra árvore Git. Não é permitido compartilhar `vendor` entre worktrees, branches ou SHAs sem comprovação de compatibilidade; referências de pacotes e o lockfile não são alterados.

Esta alteração atua somente no harness local/CI e nos scripts de instalação/build. Não executa Composer em produção, não altera banco, migrations, DICOM, Orthanc, WireGuard, Bridge, PHP-FPM ou Nginx.
