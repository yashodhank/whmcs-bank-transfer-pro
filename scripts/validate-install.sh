#!/usr/bin/env bash
set -euo pipefail

MODE="${BTP_INSTALL_MODE:-immutable}"
ROOT="${1:-$(pwd)}"
PROOFS_DIR="${BTP_PROOFS_DIR:-}"

usage() {
  cat <<'EOF'
Usage: validate-install.sh [WHMCS_ROOT]

Environment:
  BTP_INSTALL_MODE=immutable|mutable   default: immutable
  BTP_PROOFS_DIR=/absolute/path        optional immutable proof path override
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  usage
  exit 0
fi

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'OK: %s\n' "$1"
}

require_file() {
  local path="$1"
  [[ -f "$path" ]] || fail "missing file: $path"
  pass "file present: $path"
}

require_dir() {
  local path="$1"
  [[ -d "$path" ]] || fail "missing directory: $path"
  pass "directory present: $path"
}

require_writable_dir() {
  local path="$1"
  [[ -d "$path" ]] || fail "missing directory: $path"
  [[ -w "$path" ]] || fail "directory not writable: $path"
  pass "directory writable: $path"
}

require_dir "$ROOT/modules/addons/banktransferpro"
require_file "$ROOT/modules/addons/banktransferpro/banktransferpro.php"
require_file "$ROOT/modules/gateways/banktransferpro.php"
require_file "$ROOT/modules/addons/banktransferpro/lib/Bootstrap.php"

if [[ -f "$ROOT/vendor/autoload.php" ]]; then
  pass "WHMCS/root Composer autoload present"
else
  printf 'WARN: %s\n' "root vendor/autoload.php not found; runtime still works via Bank Transfer Pro PSR-4 fallback, but host Composer policies may still require composer install"
fi

case "$MODE" in
  immutable)
    proofs_path="${PROOFS_DIR:-/var/www/storage/banktransferpro/proofs}"
    if [[ "$proofs_path" != /* ]]; then
      fail "immutable proofs path must be absolute: $proofs_path"
    fi
    if [[ -d "$proofs_path" ]]; then
      [[ -w "$proofs_path" ]] || fail "immutable proofs path not writable: $proofs_path"
      pass "immutable proofs path writable: $proofs_path"
    else
      parent_dir="$(dirname "$proofs_path")"
      [[ -d "$parent_dir" ]] || fail "immutable proofs parent missing: $parent_dir"
      [[ -w "$parent_dir" ]] || fail "immutable proofs parent not writable: $parent_dir"
      pass "immutable proofs parent writable: $parent_dir"
    fi
    printf 'INFO: %s\n' "activate addon manually in WHMCS admin: Setup -> Addon Modules"
    ;;
  mutable)
    require_writable_dir "$ROOT/modules/gateways"
    storage_dir="$ROOT/modules/addons/banktransferpro/storage"
    if [[ -d "$storage_dir/proofs" ]]; then
      require_writable_dir "$storage_dir/proofs"
    else
      require_writable_dir "$storage_dir"
    fi
    printf 'INFO: %s\n' "activate addon manually in WHMCS admin: Setup -> Addon Modules"
    ;;
  *)
    fail "unsupported BTP_INSTALL_MODE: $MODE"
    ;;
esac
