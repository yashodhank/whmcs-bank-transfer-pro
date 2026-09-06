#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SHORT_SHA="$(git -C "$ROOT" rev-parse --short=7 HEAD 2>/dev/null || echo no-git)"
OUT_BASE="${1:-$ROOT/dist}"
PACKAGE_ROOT="$OUT_BASE/banktransferpro-immutable"
ARCHIVE_PATH="$OUT_BASE/banktransferpro-immutable-${SHORT_SHA}-${STAMP}.tar.gz"

rm -rf "$PACKAGE_ROOT"
mkdir -p "$PACKAGE_ROOT/modules/addons" "$PACKAGE_ROOT/modules/gateways" "$OUT_BASE"

rsync -a \
  --delete \
  --exclude '.DS_Store' \
  --exclude '.git/' \
  --exclude 'storage/proofs/*' \
  "$ROOT/modules/addons/banktransferpro/" \
  "$PACKAGE_ROOT/modules/addons/banktransferpro/"

cp "$ROOT/modules/gateways/banktransferpro.php" "$PACKAGE_ROOT/modules/gateways/banktransferpro.php"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$ROOT/README.md" "$ROOT/LICENSE" "$PACKAGE_ROOT/"

if [[ -d "$ROOT/docs" ]]; then
  mkdir -p "$PACKAGE_ROOT/docs"
  cp -R "$ROOT/docs/adr" "$PACKAGE_ROOT/docs/"
  cp "$ROOT/docs/release-bundle-contract.md" "$PACKAGE_ROOT/docs/"
fi

cat > "$PACKAGE_ROOT/INSTALL-AUTOMATION.md" <<'EOF'
Automatable:
- unpack this bundle into the WHMCS root
- validate layout with `bash scripts/validate-install.sh`
- for immutable installs, ship `modules/gateways/banktransferpro.php`
- for mutable installs, verify `modules/gateways` and addon storage are writable

Still operator/admin action:
- set environment values such as `WHMCS_MUTABLE_APP` and `BTP_PROOFS_DIR`
- run `composer install` when the host policy requires root Composer autoload refresh
- activate the addon in WHMCS admin and grant permissions
EOF

tar -C "$OUT_BASE" -czf "$ARCHIVE_PATH" "$(basename "$PACKAGE_ROOT")"

printf 'bundle_dir=%s\n' "$PACKAGE_ROOT"
printf 'bundle_archive=%s\n' "$ARCHIVE_PATH"
