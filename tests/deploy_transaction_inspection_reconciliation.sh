#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-deploy-transaction"
publisher="$repo/ops/deploy/voxelpacs-deploy-runtime"
sudoers="$repo/ops/sudoers/voxelpacs-deploy-transaction"
provisioner="$repo/scripts/provision-deploy-transaction-helper.sh"

fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
expect() { "$@" || fail "$*"; }

[[ -f "$helper" && ! -L "$helper" ]] || fail helper_missing
[[ -f "$publisher" && ! -L "$publisher" ]] || fail publisher_missing
[[ -f "$sudoers" && ! -L "$sudoers" ]] || fail sudoers_missing
[[ -f "$provisioner" && ! -L "$provisioner" ]] || fail provisioner_missing
bash -n "$helper"
bash -n "$publisher"
bash -n "$provisioner"

for marker in \
  'RECONCILIATION_MARKER' \
  'assert_reconciled_transaction' \
  'prepare_transaction_dir' \
  'TRANSACTION_RUN=' \
  'resolve_rollback_transaction'; do
  grep -Fq -- "$marker" "$publisher" || fail "publisher_contract_missing:$marker"
done
if grep -Fq "TRANSACTION_ALREADY_EXISTS" "$publisher"; then fail stale_transaction_rejection_removed; fi

for forbidden in \
  'rm -rf' \
  'git pull' \
  'git checkout' \
  'rsync --delete' \
  'composer install' \
  'psql' \
  'mysql' \
  'smbclient' \
  'systemctl start' \
  'systemctl restart'; do
  if grep -Fq -- "$forbidden" "$helper"; then fail "forbidden_helper_literal:$forbidden"; fi
done
if grep -Eq 'NOPASSWD:[[:space:]]+ALL' "$sudoers"; then fail generic_sudo; fi
if grep -Eq '/(ba|d)?sh|/usr/(bin|sbin)/(cp|rm|rsync|chmod|chown|systemctl|tar)' "$sudoers"; then fail generic_command_sudo; fi

fixture_root="$(mktemp -d /tmp/voxelpacs-deploy-transaction-test.XXXXXX)"
cleanup() {
  find "$fixture_root" -depth -type f -delete 2>/dev/null || true
  find "$fixture_root" -depth -type l -delete 2>/dev/null || true
  find "$fixture_root" -depth -type d -empty -delete 2>/dev/null || true
}
trap cleanup EXIT

fixture_helper="$fixture_root/helper"
cp -- "$helper" "$fixture_helper"
chmod 0750 "$fixture_helper"
# The production helper has fixed roots. These substitutions are confined to
# this disposable root-owned test copy and never enter the repository/runtime.
sed -i "s#readonly APP_ROOT='/var/www/voxelpacs/app'#readonly APP_ROOT='$fixture_root/app'#" "$fixture_helper"
sed -i "s#readonly RELEASE_ROOT='/var/lib/voxelpacs/deploy/releases'#readonly RELEASE_ROOT='$fixture_root/releases'#" "$fixture_helper"
sed -i "s#readonly LOCK_PATH='/run/lock/voxelpacs-deploy-runtime.lock'#readonly LOCK_PATH='$fixture_root/deploy.lock'#" "$fixture_helper"
mkdir -p "$fixture_root/app" "$fixture_root/releases/transactions"
chmod 0700 "$fixture_root/releases/transactions"

run_helper() {
  if [[ "$EUID" -eq 0 ]]; then
    env -u SUDO_USER bash "$fixture_helper" "$@"
  else
    sudo -n env -u SUDO_USER bash "$fixture_helper" "$@"
  fi
}

sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
tx="$fixture_root/releases/transactions/$sha"

# 1. transaction inexistente
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'TRANSACTION_EXISTS=NO' <<<"$out"
grep -Fxq 'CLASSIFICATION=UNKNOWN' <<<"$out"

# Fixture builder for explicit states.
make_tx() {
  local status="$1"
  if [[ -d "$tx" ]]; then
    find "$tx" -depth -type f -delete 2>/dev/null || true
    find "$tx" -depth -type l -delete 2>/dev/null || true
    find "$tx" -depth -type d -empty -delete 2>/dev/null || true
  fi
  mkdir -p "$tx/validated"
  chmod 0700 "$tx" "$tx/validated"
  printf '%s\n' "$status" > "$tx/status"
  chmod 0600 "$tx/status"
  printf 'fixture\n' > "$tx/validated/fixture.txt"
  chmod 0600 "$tx/validated/fixture.txt"
}

# 2. ACTIVE
make_tx publishing
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'TRANSACTION_STATUS=publishing' <<<"$out"
grep -Fxq 'CLASSIFICATION=ACTIVE' <<<"$out"

# 3. COMPLETED: stage equals runtime
printf 'fixture\n' > "$fixture_root/app/fixture.txt"
make_tx published
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'TRANSACTION_STATUS=published' <<<"$out"
grep -Fxq 'TRANSACTION_PARTIAL_PUBLICATION=NO' <<<"$out"
grep -Fxq 'CLASSIFICATION=COMPLETED' <<<"$out"

# 4. ABORTED
make_tx rolled_back_after_failure
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'CLASSIFICATION=ABORTED' <<<"$out"

# 5. STALE
make_tx stale
# A stale marker is accepted only with an explicit no-partial marker.
printf 'NO\n' > "$tx/partial_publication"
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'CLASSIFICATION=STALE' <<<"$out"

# 6. UNKNOWN
make_tx unexpected_status
out="$(run_helper inspect --sha "$sha")"
grep -Fxq 'TRANSACTION_STATUS=UNKNOWN' <<<"$out"
grep -Fxq 'CLASSIFICATION=UNKNOWN' <<<"$out"

# 7. SHA incorreto
if run_helper inspect --sha not-a-sha >/dev/null 2>&1; then fail invalid_sha_accepted; fi

# 8. processo ativo (fixture process includes target SHA)
make_tx rolled_back_after_failure
(bash -c "exec -a 'voxelpacs-deploy-runtime --sha $sha' sleep 4") &
process_pid=$!
sleep 0.2
out="$(run_helper inspect --sha "$sha")" || true
grep -Fxq 'DEPLOY_PROCESS_ACTIVE=YES' <<<"$out"
grep -Fxq 'CLASSIFICATION=ACTIVE' <<<"$out"
wait "$process_pid" || true

# 9. lock ativo
make_tx rolled_back_after_failure
(
  exec 9>"$fixture_root/deploy.lock"
  flock -n 9
  sleep 4
) &
lock_pid=$!
sleep 0.2
out="$(run_helper inspect --sha "$sha")" || true
grep -Fxq 'TRANSACTION_LOCK=YES' <<<"$out"
grep -Fxq 'CLASSIFICATION=ACTIVE' <<<"$out"
wait "$lock_pid" || true

# 10. publicação parcial bloqueia reconciliação
make_tx rolled_back_after_failure
printf 'YES\n' > "$tx/partial_publication"
if run_helper reconcile --sha "$sha" >/dev/null 2>&1; then fail partial_reconciled; fi
[[ ! -e "$tx/reconciliation/approved" ]] || fail partial_marker_created

# 11. rollback pendente bloqueia reconciliação
make_tx rolled_back_after_failure
printf 'pending\n' > "$tx/rollback.pending"
if run_helper reconcile --sha "$sha" >/dev/null 2>&1; then fail rollback_pending_reconciled; fi
[[ ! -e "$tx/reconciliation/approved" ]] || fail rollback_marker_created

# 12. reconciliação preserva a transação e cria evidência
make_tx rolled_back_after_failure
printf 'original\n' > "$tx/state.tsv"
original_status="$(cat "$tx/status")"
out="$(run_helper reconcile --sha "$sha")"
grep -Fxq 'TRANSACTION_RECONCILED=YES' <<<"$out"
grep -Fxq 'OLD_TRANSACTION_PRESERVED=YES' <<<"$out"
grep -Fxq 'RUNTIME_CHANGED=NO' <<<"$out"
grep -Fxq 'NEW_DEPLOY_ALLOWED=YES' <<<"$out"
[[ -d "$tx/reconciliation" ]] || fail evidence_dir_missing
[[ -f "$tx/reconciliation/approved" ]] || fail approval_marker_missing
[[ "$(cat "$tx/status")" == "$original_status" ]] || fail original_status_changed
[[ "$(cat "$tx/state.tsv")" == original ]] || fail original_state_changed

# 13. fail-closed after reconciliation: second reconciliation rejected
if run_helper reconcile --sha "$sha" >/dev/null 2>&1; then fail duplicate_reconciliation_allowed; fi

 # 14. inspect selects the latest publication run without replacing the root transaction
sha_run='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
tx_run="$fixture_root/releases/transactions/$sha_run"
mkdir -p "$tx_run/runs/20260101T000000Z-1/validated"
chmod 0700 "$tx_run" "$tx_run/runs" "$tx_run/runs/20260101T000000Z-1" "$tx_run/runs/20260101T000000Z-1/validated"
printf 'rolled_back_after_failure\n' > "$tx_run/status"
chmod 0600 "$tx_run/status"
printf 'published\n' > "$tx_run/runs/20260101T000000Z-1/status"
chmod 0600 "$tx_run/runs/20260101T000000Z-1/status"
printf 'fixture\n' > "$tx_run/runs/20260101T000000Z-1/validated/fixture.txt"
chmod 0600 "$tx_run/runs/20260101T000000Z-1/validated/fixture.txt"
out="$(run_helper inspect --sha "$sha_run")"
grep -Fxq 'TRANSACTION_STATUS=published' <<<"$out"
grep -Fxq 'CLASSIFICATION=COMPLETED' <<<"$out"

printf 'DEPLOY_TRANSACTION_INSPECTION_RECONCILIATION=PASS\n'
printf 'CASES=14\nREAD_ONLY_INSPECT=PASS\nFAIL_CLOSED=PASS\nPRESERVATION=PASS\nNO_PRODUCTION=PASS\n'
