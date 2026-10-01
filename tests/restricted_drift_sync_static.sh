#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_sha='617e7d67bf88ba325773b220b78052d416eddb2b'
source_script="$repo/ops/deploy/voxelpacs-sync-restricted-drift"
manifest_source="$repo/ops/deploy/voxelpacs-restricted-drift-sync.manifest.tsv"

fail() {
  printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2
  exit 1
}

[[ -f "$source_script" && ! -L "$source_script" ]] || fail 'executor_missing'
[[ -f "$manifest_source" && ! -L "$manifest_source" ]] || fail 'manifest_missing'

# Static security contracts: no arbitrary copy surface, global delete, service,
# Composer, database or transport operation may appear in the executor.
for forbidden in \
  '--path' '--source' '--destination' '--file' '--files' '--include' '--exclude' \
  'rsync --delete' 'rm -rf' 'find ... -delete' 'composer install' 'composer update' \
  'systemctl' 'smbclient' 'psql' 'mysql' 'curl' 'wget'; do
  if grep -Fq -- "$forbidden" "$source_script"; then
    fail "forbidden_literal:$forbidden"
  fi
done

# The literal option names occur in the rejection contract only when the
# script's parser is expected to reject all arguments other than two modes.
for allowed in '--dry-run' '--execute'; do
  grep -Fq -- "$allowed" "$source_script" || fail "missing_mode:$allowed"
done

# Build an isolated Git checkout fixture from the approved source. The test
# copy rewrites only fixed filesystem roots, never production constants in Git.
tmp="$(mktemp -d /tmp/voxelpacs-restricted-sync-test.XXXXXX)"
cleanup() { rm -rf -- "$tmp"; }
trap cleanup EXIT

fixture_source="$tmp/source/$source_sha"
fixture_target="$tmp/runtime/app"
fixture_manifest="$tmp/manifest.tsv"
fixture_transactions="$tmp/transactions"
fixture_lock="$tmp/sync.lock"
fixture_script="$tmp/executor"
mkdir -p "$tmp/source"
git clone --quiet --no-local "$repo" "$fixture_source"
git -C "$fixture_source" checkout --detach --quiet "$source_sha"
mkdir -p "$fixture_target/app/Repositories" "$fixture_target/app/Services" "$fixture_target/bin"
printf 'test-env\n' > "$fixture_target/.env"
mkdir -p "$fixture_target/storage"
printf 'preserve-me\n' > "$fixture_target/extra-unlisted.txt"
cp -p "$manifest_source" "$fixture_manifest"

# Replace only test filesystem roots; TARGET_SHA, allowlist and hashes remain
# exactly those from the approved source. Replacing all fixed literals also
# keeps the production scope assertions active in the isolated fixture.
sed \
  -e "s#/var/www/voxelpacs/app#$fixture_target#g" \
  -e "s#/var/lib/voxelpacs/restricted-drift-sync/source#$tmp/source#g" \
  -e "s#/usr/local/share/voxelpacs/voxelpacs-restricted-drift-sync.manifest.tsv#$fixture_manifest#g" \
  -e "s#/var/lib/voxelpacs/restricted-drift-sync/transactions#$fixture_transactions#g" \
  -e "s#/run/lock/voxelpacs-restricted-drift-sync.lock#$fixture_lock#g" \
  "$source_script" > "$fixture_script"
chmod 700 "$fixture_script"
mkdir -p "$tmp/source"

run_output="$tmp/dry-run.out"
"$fixture_script" --dry-run > "$run_output"
grep -Fxq 'SOURCE_SHA_CHECK=PASS' "$run_output"
grep -Fxq 'MANIFEST_CHECK=PASS' "$run_output"
grep -Fxq 'ALLOWLIST_CHECK=PASS' "$run_output"
grep -Fxq 'FILES_EXPECTED=11' "$run_output"
grep -Fxq 'VENDOR_SYNC=BLOCKED' "$run_output"
grep -Fxq 'OPTIONAL_SYNC=SKIPPED' "$run_output"
grep -Fxq 'DRY_RUN=PASS' "$run_output"
grep -Fxq 'SYNC=NOT_EXECUTED' "$run_output"
grep -Fxq 'PRODUCTION_CHANGED=NO' "$run_output"
[[ ! -e "$fixture_target/app/Services/ActiveDestinationResolver.php" ]] || fail 'dry_run_mutated_runtime'
[[ -f "$fixture_target/extra-unlisted.txt" ]] || fail 'dry_run_removed_extra'

if ! "$fixture_script" --execute > "$tmp/execute.out"; then
  cat "$tmp/execute.out" >&2 || true
  fail 'execute_fixture_failed'
fi
grep -Fxq 'SYNC=PASS' "$tmp/execute.out"
grep -Fxq 'FILES_SYNCED=11' "$tmp/execute.out"
grep -Fxq 'SYNC_SHA_MATCH=PASS' "$tmp/execute.out"
grep -Fxq 'PRODUCTION_CHANGED=YES' "$tmp/execute.out"
while IFS=$'\t' read -r path expected; do
  [[ "$(sha256sum "$fixture_source/$path" | awk '{print $1}')" == "$expected" ]] || fail "fixture_source_hash:$path"
  [[ "$(sha256sum "$fixture_target/$path" | awk '{print $1}')" == "$expected" ]] || fail "fixture_target_hash:$path"
done < "$fixture_manifest"
[[ "$(cat "$fixture_target/.env")" == 'test-env' ]] || fail 'env_changed'
[[ -d "$fixture_target/storage" ]] || fail 'storage_changed'
[[ -f "$fixture_target/extra-unlisted.txt" ]] || fail 'extra_removed'

# Invalid operator surfaces must fail before any mutation.
for option in --path --source --destination --file --files --include --exclude; do
  if "$fixture_script" "$option" app/Services/ActiveDestinationResolver.php >/dev/null 2>&1; then
    fail "arbitrary_option_accepted:$option"
  fi
done
if "$fixture_script" --execute --path app/Services/ActiveDestinationResolver.php >/dev/null 2>&1; then
  fail 'arbitrary_path_with_execute_accepted'
fi

# Protected and traversal paths must be rejected by the manifest parser.
first_hash="$(head -n 1 "$fixture_manifest" | cut -f2)"
bad_index=0
for bad_path in \
  vendor/composer/installed.php \
  .env \
  storage/secret \
  uploads/file \
  database/schema.sql \
  migrations/001.sql \
  ../../etc/passwd; do
  bad_index=$((bad_index + 1))
  bad_manifest="$tmp/manifest-bad-$bad_index.tsv"
  {
    printf '%s\t%s\n' "$bad_path" "$first_hash"
    tail -n +2 "$fixture_manifest"
  } > "$bad_manifest"
  bad_executor="$tmp/executor-bad-$bad_index"
  sed "s|readonly MANIFEST_PATH=.*|readonly MANIFEST_PATH='$bad_manifest'|" "$fixture_script" > "$bad_executor"
  chmod 700 "$bad_executor"
  if "$bad_executor" --dry-run >/dev/null 2>&1; then
    fail "protected_or_traversal_path_accepted:$bad_path"
  fi
done

# Source symlinks, missing source files and runtime target symlinks are all
# rejected before an execute can begin.
resolver_source="$fixture_source/app/Services/ActiveDestinationResolver.php"
resolver_backup="$tmp/ActiveDestinationResolver.php.backup"
cp -p "$resolver_source" "$resolver_backup"
rm -f "$resolver_source"
ln -s "$fixture_source/app/Services/ActiveDestinationResolutionException.php" "$resolver_source"
if "$fixture_script" --dry-run >/dev/null 2>&1; then
  fail 'source_symlink_accepted'
fi
rm -f "$resolver_source"
cp -p "$resolver_backup" "$resolver_source"

rm -f "$resolver_source"
if "$fixture_script" --dry-run >/dev/null 2>&1; then
  fail 'missing_source_file_accepted'
fi
git -C "$fixture_source" checkout --quiet -- app/Services/ActiveDestinationResolver.php

target_resolver="$fixture_target/app/Services/ActiveDestinationResolver.php"
rm -f "$target_resolver"
ln -s "$fixture_target/.env" "$target_resolver"
if "$fixture_script" --dry-run >/dev/null 2>&1; then
  fail 'runtime_target_symlink_accepted'
fi
rm -f "$target_resolver"

# A tampered manifest must be rejected. The target is checked before execute.
cp -p "$fixture_manifest" "$tmp/manifest.bad.tsv"
printf '%s\t%s\n' 'vendor/composer/installed.php' 'b3b28093705f5a749db27621977f3ea0a01c3606c8ae819762fb3d8f857ac78a' >> "$tmp/manifest.bad.tsv"
sed "s|readonly MANIFEST_PATH=.*|readonly MANIFEST_PATH='$tmp/manifest.bad.tsv'|" "$fixture_script" > "$tmp/executor-bad-manifest"
chmod 700 "$tmp/executor-bad-manifest"
if "$tmp/executor-bad-manifest" --dry-run >/dev/null 2>&1; then
  fail 'vendor_manifest_accepted'
fi

# A source checkout at another commit must fail the fixed SHA check.
legacy_source="$tmp/source-legacy/$source_sha"
mkdir -p "$tmp/source-legacy"
git clone --quiet --no-local "$repo" "$legacy_source"
git -C "$legacy_source" checkout --detach --quiet afb95768db9c56c6b0854eba13dd775251816390
tampered_script="$tmp/executor-legacy-source"
sed "s|readonly SOURCE_ROOT_BASE=.*|readonly SOURCE_ROOT_BASE='$tmp/source-legacy'|" "$fixture_script" > "$tampered_script"
chmod 700 "$tampered_script"
if "$tampered_script" --dry-run >/dev/null 2>&1; then
  fail 'legacy_source_accepted'
fi

printf 'TEST=PASS\nALLOWLIST=11\nDRY_RUN=PASS\nEXECUTE_FIXTURE=PASS\nNEGATIVE_CASES=PASS\nPRODUCTION_CHANGED=NO\n'
