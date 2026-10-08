# Release bundle contract

This repo publishes a deployable Bank Transfer Pro artifact. The artifact must be consumable by a WHMCS host without repo-local Bank Transfer Pro edits.

## Separation of concerns

- Repo: addon code, static gateway, migrations, templates, tests, and release docs.
- Platform repo or server: place files in the WHMCS tree, run `composer install`, set env vars, and provide writable storage.
- Runtime state: uploaded proofs, generated mutable gateways, and WHMCS database rows.

## Required artifact contents

Every production-capable bundle must include:

- `modules/addons/banktransferpro/`
- `modules/gateways/banktransferpro.php`
- `composer.json`
- `composer.lock`
- `README.md`
- `docs/adr/0001-independent-shipping-model.md`
- `docs/release-bundle-contract.md`

The bundle must not require pre-generated `modules/gateways/banktransferpro_*.php` files.

## Production contract

Production should run in immutable mode.

- Set `WHMCS_MUTABLE_APP=false`
- Set `BTP_PROOFS_DIR=/absolute/writable/path`
- Ship the static gateway file at `modules/gateways/banktransferpro.php`
- Keep `modules/gateways/` read-only for normal runtime
- Do not rely on module-local proof storage inside the artifact

## Mutable and lab contract

Lab or legacy installs may run in mutable mode.

- `WHMCS_MUTABLE_APP` may be left unset or set truthy
- `modules/gateways/` must be writable
- Proofs may live under `modules/addons/banktransferpro/storage/proofs`
- Generated `banktransferpro_{slug}.php` files remain runtime artifacts only

## Host repo rule

`whmcs-prod-new` should treat Bank Transfer Pro as an imported artifact plus env contract.

- Avoid module-specific source edits in `whmcs-prod-new`
- Avoid copying custom gateway variants into the host repo
- Prefer env wiring, volume mounts, and standard WHMCS activation steps
- If production needs module behavior changes, make them here and release a new artifact
- Overlay pin in `whmcs-prod-new` must reference the intended artifact version/digest (document the pin when consuming; do not edit the host repo from this module repo)

## Release check

Before cutting or consuming a production bundle, verify:

1. Full tree is present: `modules/addons/banktransferpro/` **and** `modules/gateways/banktransferpro.php`
2. `BTP_INSTALL_MODE=immutable ./scripts/validate-install.sh <WHMCS_ROOT>` must pass (requires `InvoiceCurrencyResolver`, `ModuleFingerprint` / `invoice_currency_via_client`, addon version `>= 1.1.3`, and no banned `GatewayRenderer` `tblinvoices.currency` pattern)
3. Overlay pin in `whmcs-prod-new` must reference this artifact before platform rebuild/consume
4. Smoke: unpaid invoice with `banktransferpro` → `viewinvoice` shows bank details, not WHMCS Oops
5. Immutable mode is documented and supported by the shipped code
6. Proof storage is configurable with `BTP_PROOFS_DIR`
7. No required runtime step writes back into the shipped module tree
