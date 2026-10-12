#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/ops/deploy/voxelpacs-composer-metadata-profile-auditor"
profile_builder="$repo/scripts/build-composer-metadata-profile.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-composer-metadata-profile.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-composer-metadata-profile.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

fixture_helper="$fixture_root/helper"
app="$fixture_root/app"
baseline_root="$fixture_root/baselines"
baseline_sha='0123456789abcdef0123456789abcdef01234567'
artifact_sha="$(git -C "$repo" rev-parse HEAD)"
other_sha='abcdef0123456789abcdef0123456789abcdef01'

sed \
  -e "s|readonly APP_ROOT='/var/www/voxelpacs/app'|readonly APP_ROOT='$app'|" \
  -e "s|readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'|readonly BASELINE_ROOT='$baseline_root'|" \
  "$source_helper" > "$fixture_helper"
chmod 0755 "$fixture_helper"
mkdir -p "$app/vendor/composer" "$baseline_root/$baseline_sha"
baseline_dir="$baseline_root/$baseline_sha"
cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'name' => 'voxel/bi',
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$artifact_sha',
        ),
    ),
);
EOF
chmod 0644 "$app/vendor/composer/installed.php"

printf '%s\n' 'baseline-manifest-fixture' > "$baseline_dir/manifest.tsv"
manifest_sha="$(sha256sum "$baseline_dir/manifest.tsv" | awk '{print $1}')"
old_baseline_dir="$baseline_dir"
baseline_sha="${manifest_sha:0:40}"
baseline_dir="$baseline_root/$baseline_sha"
mv -- "$old_baseline_dir" "$baseline_dir"
printf '%s\n' "$baseline_sha" > "$baseline_dir/baseline-id"
printf '%s\n' "$manifest_sha" > "$baseline_dir/manifest.sha256"
cat > "$baseline_dir/metadata.txt" <<EOF
mechanism_version=1
baseline_id=$baseline_sha
manifest_sha256=$manifest_sha
hash_unavailable=0
metadata_unavailable=0
secrets_content=NOT_CAPTURED
persistent_content=NOT_CAPTURED
clinical_content=NOT_CAPTURED
EOF
: > "$baseline_dir/symlinks.tsv"
: > "$baseline_dir/exclusions.tsv"
printf 'profile_source\tofficial_root_controlled_baseline_profile_v1\nprofile_baseline_id\t%s\nprofile_manifest_sha256\t%s\nprofile_artifact_sha\t%s\nroot_reference_state\tSOURCE_SHA\npackage_reference_state\tSOURCE_SHA\n' \
  "$baseline_sha" "$manifest_sha" "$artifact_sha" > "$baseline_dir/composer-profile.tsv"
chmod 0700 "$baseline_root" "$baseline_dir"
chmod 0600 "$baseline_dir/baseline-id" "$baseline_dir/manifest.tsv" "$baseline_dir/manifest.sha256" "$baseline_dir/metadata.txt" "$baseline_dir/symlinks.tsv" "$baseline_dir/exclusions.tsv" "$baseline_dir/composer-profile.tsv"
run_as_root chown -R root:root "$baseline_root"

artifact_profile="$fixture_root/artifact-profile.tsv"
printf 'profile_artifact_sha\t%s\nroot_reference_state\tSOURCE_SHA\npackage_reference_state\tSOURCE_SHA\n' "$artifact_sha" > "$artifact_profile"

run_helper() {
  cat "$artifact_profile" | run_as_root env -u SUDO_USER bash "$fixture_helper" --baseline-id "$baseline_sha" --artifact-sha "$artifact_sha"
}

output="$(run_helper)"
grep -Fxq 'AUDIT=PASS' <<<"$output" || fail equality_audit
grep -Fxq 'BASELINE_PROFILE=YES' <<<"$output" || fail baseline_profile_available
grep -Fxq 'PROFILE_COMPARISON=COMPLETE' <<<"$output" || fail equality_complete
grep -Fxq 'PROFILE_DIAGNOSIS=ALL_THREE_PROFILE_STATES_MATCH' <<<"$output" || fail equality_diagnosis
grep -Fxq 'ARTIFACT_RUNTIME_ROOT_COMPARISON=MATCH' <<<"$output" || fail root_equality
grep -Fxq 'ARTIFACT_RUNTIME_PACKAGE_COMPARISON=MATCH' <<<"$output" || fail package_equality
grep -Fxq 'READY_FOR_DEPLOY=NO' <<<"$output" || fail deploy_guard
! grep -Fq "'reference'" <<<"$output" || fail raw_key_content_exposed

run_as_root sh -c "printf '%s\\n' tampered > '$baseline_root/$baseline_sha/manifest.tsv'"
set +e
tampered_manifest="$(run_helper 2>&1)"
tampered_manifest_rc=$?
set -e
[[ "$tampered_manifest_rc" -eq 64 ]] || fail tampered_manifest_exit_code
grep -Fq 'REASON=BASELINE_MANIFEST_CHECKSUM_MISMATCH' <<<"$tampered_manifest" || fail tampered_manifest_rejected
run_as_root sh -c "printf '%s\\n' baseline-manifest-fixture > '$baseline_root/$baseline_sha/manifest.tsv'"

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$artifact_sha',
            'dist' => array(
                'reference' => '$other_sha',
            ),
        ),
    ),
);
EOF
set +e
ambiguous="$(run_helper 2>&1)"
ambiguous_rc=$?
set -e
[[ "$ambiguous_rc" -eq 65 ]] || fail ambiguous_exit_code
grep -Fxq 'RUNTIME_PACKAGE_REFERENCE_STATE=UNRESOLVED' <<<"$ambiguous" || fail ambiguous_package_state
grep -Fxq 'PROFILE_DIAGNOSIS=PROFILE_FIELDS_INDETERMINATE' <<<"$ambiguous" || fail ambiguous_diagnosis
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$ambiguous" || fail ambiguous_audit_status

rm -f -- "$baseline_root/$baseline_sha/metadata.txt"
set +e
missing_metadata="$(run_helper 2>&1)"
missing_metadata_rc=$?
set -e
[[ "$missing_metadata_rc" -eq 64 ]] || fail missing_metadata_exit_code
grep -Fq 'REASON=BASELINE_METADATA_SECURITY_INVALID' <<<"$missing_metadata" || fail missing_metadata_rejected
cat > "$baseline_root/$baseline_sha/metadata.txt" <<EOF
mechanism_version=1
baseline_id=$baseline_sha
manifest_sha256=$manifest_sha
hash_unavailable=0
metadata_unavailable=0
secrets_content=NOT_CAPTURED
persistent_content=NOT_CAPTURED
clinical_content=NOT_CAPTURED
EOF
chmod 0600 "$baseline_root/$baseline_sha/metadata.txt"

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$artifact_sha',
            'reference' => '$other_sha',
        ),
    ),
);
EOF
set +e
duplicate_reference="$(run_helper 2>&1)"
duplicate_reference_rc=$?
set -e
[[ "$duplicate_reference_rc" -eq 65 ]] || fail duplicate_reference_exit_code
grep -Fxq 'RUNTIME_PACKAGE_REFERENCE_STATE=UNRESOLVED' <<<"$duplicate_reference" || fail duplicate_reference_state
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$duplicate_reference" || fail duplicate_reference_audit_status

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$artifact_sha',
        ),
        'voxel/bi' => array(
            'reference' => '$other_sha',
        ),
    ),
);
EOF
set +e
duplicate_package="$(run_helper 2>&1)"
duplicate_package_rc=$?
set -e
[[ "$duplicate_package_rc" -eq 65 ]] || fail duplicate_package_exit_code
grep -Fxq 'RUNTIME_PACKAGE_REFERENCE_STATE=UNRESOLVED' <<<"$duplicate_package" || fail duplicate_package_state
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$duplicate_package" || fail duplicate_package_audit_status

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
EOF
set +e
unclosed_root="$(run_helper 2>&1)"
unclosed_root_rc=$?
set -e
[[ "$unclosed_root_rc" -eq 65 ]] || fail unclosed_root_exit_code
grep -Fxq 'RUNTIME_ROOT_REFERENCE_STATE=UNRESOLVED' <<<"$unclosed_root" || fail unclosed_root_state
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$unclosed_root" || fail unclosed_root_audit_status

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'other/package' => array(
            'reference' => '$other_sha',
        ),
    ),
);
EOF
set +e
absent_package="$(run_helper 2>&1)"
absent_package_rc=$?
set -e
[[ "$absent_package_rc" -eq 65 ]] || fail absent_package_exit_code
grep -Fxq 'RUNTIME_PACKAGE_REFERENCE_STATE=UNRESOLVED' <<<"$absent_package" || fail absent_package_state
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$absent_package" || fail absent_package_audit_status

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'name' => 'voxel/bi',
        'reference' => '$other_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$other_sha',
        ),
    ),
);
EOF
set +e
mismatch="$(run_helper 2>&1)"
mismatch_rc=$?
set -e
[[ "$mismatch_rc" -eq 66 ]] || fail mismatch_exit_code
grep -Fxq 'PROFILE_COMPARISON=COMPLETE' <<<"$mismatch" || fail mismatch_complete
grep -Fxq 'PROFILE_DIAGNOSIS=PROFILE_STATES_DIFFER' <<<"$mismatch" || fail mismatch_diagnosis
grep -Fxq 'ARTIFACT_RUNTIME_ROOT_COMPARISON=DIFFER' <<<"$mismatch" || fail mismatch_root
grep -Fxq 'AUDIT=FAIL' <<<"$mismatch" || fail mismatch_audit_status
! grep -Fq "$other_sha" <<<"$mismatch" || fail runtime_sha_exposed

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'name' => 'voxel/bi',
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'version' => 'dev-main',
        ),
    ),
);
EOF
set +e
missing="$(run_helper 2>&1)"
missing_rc=$?
set -e
[[ "$missing_rc" -eq 65 ]] || fail missing_exit_code
grep -Fxq 'RUNTIME_PACKAGE_REFERENCE_STATE=UNRESOLVED' <<<"$missing" || fail missing_package_state
grep -Fxq 'PROFILE_DIAGNOSIS=PROFILE_FIELDS_INDETERMINATE' <<<"$missing" || fail missing_diagnosis
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$missing" || fail missing_audit_status

rm -f -- "$baseline_root/$baseline_sha/composer-profile.tsv"
cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$artifact_sha',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$artifact_sha',
        ),
    ),
);
EOF
set +e
unavailable="$(run_helper 2>&1)"
unavailable_rc=$?
set -e
[[ "$unavailable_rc" -eq 65 ]] || fail baseline_unavailable_exit_code
grep -Fxq 'BASELINE_PROFILE=UNVERIFIED' <<<"$unavailable" || fail baseline_unavailable
grep -Fxq 'PROFILE_COMPARISON=INCOMPLETE' <<<"$unavailable" || fail incomplete_comparison
grep -Fxq 'PROFILE_DIAGNOSIS=BASELINE_PROFILE_UNAVAILABLE' <<<"$unavailable" || fail unavailable_diagnosis
grep -Fxq 'AUDIT=INCOMPLETE' <<<"$unavailable" || fail unavailable_audit_status

profile_output="$fixture_root/generated-profile.tsv"
profile_repo="$fixture_root/profile-repo"
mkdir -p "$profile_repo/scripts"
cp -- "$profile_builder" "$profile_repo/scripts/build-composer-metadata-profile.sh"
git -C "$profile_repo" init -q
git -C "$profile_repo" config user.email 'test@example.invalid'
git -C "$profile_repo" config user.name 'VOXEL PACS test'
git -C "$profile_repo" add scripts/build-composer-metadata-profile.sh
git -C "$profile_repo" commit -q -m 'test: profile builder fixture'
expected_head="$(git -C "$profile_repo" rev-parse HEAD)"
cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$expected_head',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$expected_head',
        ),
    ),
);
EOF
bash "$profile_repo/scripts/build-composer-metadata-profile.sh" \
  --input "$app/vendor/composer/installed.php" \
  --expected-sha "$expected_head" \
  --output "$profile_output" > "$fixture_root/profile-builder.out"
grep -Fqx $'profile_artifact_sha\t'"$expected_head" "$profile_output" || fail profile_builder_sha
grep -Fqx $'root_reference_state\tSOURCE_SHA' "$profile_output" || fail profile_builder_root
grep -Fqx $'package_reference_state\tSOURCE_SHA' "$profile_output" || fail profile_builder_package
! grep -Fq "$other_sha" "$profile_output" || fail profile_builder_raw_value
! grep -Fq "'reference'" "$profile_output" || fail profile_builder_raw_key

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$expected_head',
    ),
    'versions' => array(
        'voxel/bi' => array(
            'reference' => '$expected_head',
        ),
        'voxel/bi' => array(
            'reference' => '$other_sha',
        ),
    ),
);
EOF
duplicate_profile="$fixture_root/duplicate-profile.tsv"
bash "$profile_repo/scripts/build-composer-metadata-profile.sh" \
  --input "$app/vendor/composer/installed.php" \
  --expected-sha "$expected_head" \
  --output "$duplicate_profile" > "$fixture_root/duplicate-profile-builder.out"
grep -Fqx $'package_reference_state\tUNRESOLVED' "$duplicate_profile" || fail profile_builder_duplicate_package

cat > "$app/vendor/composer/installed.php" <<EOF
<?php
return array(
    'root' => array(
        'reference' => '$expected_head',
EOF
unclosed_profile="$fixture_root/unclosed-profile.tsv"
bash "$profile_repo/scripts/build-composer-metadata-profile.sh" \
  --input "$app/vendor/composer/installed.php" \
  --expected-sha "$expected_head" \
  --output "$unclosed_profile" > "$fixture_root/unclosed-profile-builder.out"
grep -Fqx $'root_reference_state\tUNRESOLVED' "$unclosed_profile" || fail profile_builder_unclosed_root

printf 'TEST=PASS\nISOLATED=PASS\nEQUALITY=PASS\nDIVERGENCE=PASS\nAMBIGUOUS_REFERENCES=PASS\nDUPLICATE_REFERENCES=PASS\nDUPLICATE_PACKAGE=PASS\nUNCLOSED_STRUCTURES=PASS\nABSENT_PACKAGE=PASS\nMISSING_FIELDS=PASS\nBASELINE_BINDING=PASS\nBASELINE_METADATA=PASS\nBASELINE_UNAVAILABLE=PASS\nPROFILE_BUILDER=PASS\nRAW_VALUES_NOT_EXPOSED=PASS\nINCOMPLETE_NOT_PASS=PASS\nFAIL_STATUS=PASS\nREADY_FOR_DEPLOY=NO\nPRODUCTION_CHANGED=NO\n'
