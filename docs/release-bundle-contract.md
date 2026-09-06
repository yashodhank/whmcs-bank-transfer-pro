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

## Release check

Before cutting or consuming a production bundle, verify:

1. The static gateway file exists at `modules/gateways/banktransferpro.php`
2. Immutable mode is documented and supported by the shipped code
3. Proof storage is configurable with `BTP_PROOFS_DIR`
4. No required runtime step writes back into the shipped module tree
