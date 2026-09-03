#!/usr/bin/env bash
# Lab-only deploy for Bank Transfer Pro into local whmcs-devbox WHMCS trees.
# Never targets production. Requires writable lab cores under whmcs-devbox.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${REPO_ROOT}/modules/addons/banktransferpro"
DEVBOX_ROOT="${WHMCS_DEVBOX_ROOT:-$HOME/Projects/whmcs-devbox}"

TARGETS=()
if [[ $# -gt 0 ]]; then
  for arg in "$@"; do
    case "$arg" in
      8|8.13) TARGETS+=("${DEVBOX_ROOT}/whmcs/8.13") ;;
      9|9.0)  TARGETS+=("${DEVBOX_ROOT}/whmcs/9.0") ;;
      *)
        echo "usage: $0 [8|9|8.13|9.0]..." >&2
        echo "default: both 8.13 and 9.0 under ${DEVBOX_ROOT}" >&2
        exit 2
        ;;
    esac
  done
else
  TARGETS+=("${DEVBOX_ROOT}/whmcs/8.13" "${DEVBOX_ROOT}/whmcs/9.0")
fi

if [[ ! -d "$SRC" ]]; then
  echo "error: addon source missing: $SRC" >&2
  exit 1
fi

deploy_one() {
  local whmcs_root="$1"
  local dest="${whmcs_root}/modules/addons/banktransferpro"

  if [[ ! -d "${whmcs_root}/modules/addons" ]]; then
    echo "skip: not a WHMCS tree: $whmcs_root" >&2
    return 1
  fi

  mkdir -p "$dest"
  rsync -a --delete \
    --exclude 'storage/proofs/*' \
    --exclude 'vendor/' \
    --exclude '.git/' \
    "${SRC}/" "${dest}/"

  mkdir -p "${dest}/storage/proofs"
  # Keep an empty proofs dir; do not wipe existing lab uploads if any remain
  # after rsync exclude.
  touch "${dest}/storage/proofs/.gitkeep"

  chmod 775 "${whmcs_root}/modules/gateways" 2>/dev/null || true
  chmod -R 775 "${dest}/storage"

  # Smoke: Bootstrap must load BankTransferPro classes without repo-root vendor.
  php -r "
    require '${dest}/lib/Bootstrap.php';
    BankTransferPro\\Bootstrap::init();
    echo class_exists('BankTransferPro\\\\Gateway\\\\GatewayRenderer') ? 'autoload-ok' : 'autoload-fail';
    echo PHP_EOL;
  "

  echo "deployed -> ${dest}"
}

failed=0
for t in "${TARGETS[@]}"; do
  if ! deploy_one "$t"; then
    failed=1
  fi
done

if [[ "$failed" -ne 0 ]]; then
  echo "one or more lab targets failed" >&2
  exit 1
fi

echo "lab deploy complete (activate in WHMCS admin: Setup → Addon Modules)"
