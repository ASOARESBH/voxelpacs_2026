# Guard de compatibilidade Composer dos gates

O código e as dependências usadas por um gate devem pertencer à mesma árvore Git. O script `scripts/verify-composer-tree.sh` valida `composer.json`, `composer.lock`, o `HEAD` testado e `vendor/composer/installed.php` antes de permitir os gates.

O guard bloqueia a execução quando o `vendor/` é um symlink para fora da árvore, quando os metadados instalados não informam a referência do código-fonte, quando a referência do vendor diverge do `HEAD` ou quando o manifest/lock está alterado. Nesses casos ele emite `COMPOSER_TREE_MISMATCH=FAIL` e encerra com código diferente de zero.

Em uma árvore temporária, o procedimento correto é executar `composer install` dentro da própria árvore e validar novamente. Não é permitido compartilhar `vendor` entre worktrees, branches ou SHAs sem comprovação de compatibilidade.

Esta alteração atua somente no harness local/CI e nos scripts de instalação/build. Não executa Composer em produção, não altera banco, migrations, DICOM, Orthanc, WireGuard, Bridge, PHP-FPM ou Nginx.
