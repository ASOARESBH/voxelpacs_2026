#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$repo/scripts/provision-restricted-drift-sync.sh"

fail() {
  printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2
  exit 1
}

[[ -f "$script" && ! -L "$script" ]] || fail 'provisioner_missing'
grep -Fq "readonly EXPECTED_SYNC_SHA='617e7d67bf88ba325773b220b78052d416eddb2b'" "$script" || fail 'target_sha_contract_missing'
grep -Fq "readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-sync-restricted-drift'" "$script" || fail 'helper_destination_contract_missing'
grep -Fq "readonly INSTALLED_MANIFEST=\"\$SHARE_ROOT/voxelpacs-restricted-drift-sync.manifest.tsv\"" "$script" || fail 'manifest_destination_contract_missing'
grep -Fq "readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-restricted-drift-sync'" "$script" || fail 'sudoers_destination_contract_missing'

for forbidden in \
  'NOPASSWD: ALL' \
  'git pull' \
  'git checkout' \
  'rm -rf' \
  'rsync --delete' \
  'composer install' \
  'psql' \
  'mysql' \
  'smbclient'; do
  if grep -Fq -- "$forbidden" "$script"; then
    fail "forbidden_literal:$forbidden"
  fi
done

bash -n "$script"

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then
    bash "$@"
  else
    sudo -n env -u SUDO_USER bash "$@"
  fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-provisioner-test.XXXXXX)"
cleanup_fixture() {
  [[ "$fixture_root" == /tmp/voxelpacs-provisioner-test.* && -d "$fixture_root" ]] || return 0
  find "$fixture_root" -depth -type f -delete
  find "$fixture_root" -depth -type l -delete
  find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup_fixture EXIT
fixture="$fixture_root/repo"
git clone --no-local "$repo" "$fixture" >/dev/null 2>&1
# The provisioner intentionally protects the separately approved historical
# source release. Keep the current provisioner/manifest files, but materialize
# the allowlisted source paths from that release and commit the isolated
# fixture so WORKTREE_NOT_CLEAN remains an active contract.
source_sha='617e7d67bf88ba325773b220b78052d416eddb2b'
while IFS=$'\t' read -r path _hash; do
  [[ -n "$path" ]] || continue
  git -C "$fixture" checkout "$source_sha" -- "$path" >/dev/null 2>&1
done < "$fixture/ops/deploy/voxelpacs-restricted-drift-sync.manifest.tsv"
git -C "$fixture" add -- \
  app/Repositories/ReportDeliveryRepository.php \
  app/Repositories/ReportDeliveryWorkerRepository.php \
  app/Services/PhilipsFolderDeliveryService.php \
  app/Services/PhilipsFolderGatewayBridgeClient.php \
  app/Services/PhilipsSubmissionPackageProducer.php \
  bin/report_delivery_worker.php \
  .htaccess \
  app/Services/ActiveDestinationResolutionException.php \
  app/Services/ActiveDestinationResolver.php \
  composer.json composer.lock
git -C "$fixture" -c user.name='VOXEL PACS CI fixture' -c user.email='ci-fixture@localhost' commit -m 'fixture: pin restricted sync source' >/dev/null 2>&1
sha="$(git -C "$fixture" rev-parse HEAD)"
fixture_script="$fixture/scripts/provision-restricted-drift-sync.sh"
output="$(run_as_root "$fixture_script" --expected-sha "$sha" --dry-run)"
grep -Fxq 'SOURCE_SHA_CHECK=PASS' <<<"$output"
grep -Fxq 'MANIFEST_CHECK=PASS' <<<"$output"
grep -Fxq 'HELPER_CHECK=PASS' <<<"$output"
grep -Fxq 'SUDOERS_CHECK=PASS' <<<"$output"
grep -Fxq 'DRY_RUN=PASS' <<<"$output"
grep -Fxq 'INSTALL=NOT_EXECUTED' <<<"$output"
grep -Fxq 'PRODUCTION_CHANGED=NO' <<<"$output"

if run_as_root "$fixture_script" --expected-sha "$sha" --invalid-mode >/dev/null 2>&1; then
  fail 'invalid_mode_accepted'
fi
if run_as_root "$fixture_script" --expected-sha 0000000000000000000000000000000000000000 --dry-run >/dev/null 2>&1; then
  fail 'unexpected_sha_accepted'
fi

printf 'TEST=PASS\nDRY_RUN=PASS\nINVALID_ARGUMENTS=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\n'
