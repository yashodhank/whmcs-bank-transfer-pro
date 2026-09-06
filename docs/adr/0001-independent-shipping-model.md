# ADR 0001: Independent shipping model

## Status

Accepted

## Decision

`whmcs-bank-transfer-pro` ships as a self-contained module artifact.

- The module repo owns addon code, the static gateway file, docs, and release packaging rules.
- The WHMCS host repo owns environment wiring only: placement of files, writable storage, Composer install, and normal WHMCS activation.
- Production installs default to immutable mode. Mutable mode exists for labs and legacy installs.

## Runtime contract

### Immutable mode

Use immutable mode when `WHMCS_MUTABLE_APP` is set to a falsey value such as `false`, `0`, `no`, or `off`.

- Ship `modules/gateways/banktransferpro.php` as part of the artifact.
- Do not generate per-bank gateway PHP files at runtime.
- Store uploaded proofs outside the module tree via `BTP_PROOFS_DIR`.
- If `BTP_PROOFS_DIR` is not set, default to `/var/www/storage/banktransferpro/proofs`.
- Support one active bank per currency and resolve invoice output from addon data.

### Mutable mode

Use mutable mode when `WHMCS_MUTABLE_APP` is unset or truthy.

- Allow runtime creation of `modules/gateways/banktransferpro_{slug}.php`.
- Allow proof storage under `modules/addons/banktransferpro/storage/proofs`.
- Treat generated gateway files and uploaded proofs as local runtime artifacts, not release artifacts.

## Consequences

- Releases are portable and do not require module-specific production edits in another repo.
- `whmcs-prod-new` should consume the shipped artifact and env contract instead of carrying Bank Transfer Pro patches.
- Future feature work should preserve the static gateway path and env-driven proof storage contract unless a new ADR intentionally replaces it.
