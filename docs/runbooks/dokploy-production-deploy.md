# Runbook: Dokploy production deploy (Bank Transfer Pro)

Production path for `https://my.securiace.com`. Complements [ADR 0001](../adr/0001-independent-shipping-model.md) and the [release bundle contract](../release-bundle-contract.md).

Merging a pull request in **this** repo does not deploy. Green CI here is not permission to change Dokploy.

Historical only: `securiace-dev/securiace-whmcs-prod` was archived on 2026-10-25 (including its `image.lock` and deploy tags). It is not the live path.

## Topology

```
whmcs-bank-transfer-pro (this repo)  main
        │  pinned commit vendored into overlays/  (merge here does not deploy)
        ▼
securiace-dev/whmcs-prod-new
        overlays/catalog.json
        scripts/sync-overlay-package.sh <id>
        SYNC_<ID>_SHA=<module commit>
        │  PR to main; merge only when every check is green
        ▼
release-identity CI job on whmcs-prod-new main
        ghcr.io/securiace-dev/whmcs-prod-new@sha256:…
        tag git-<sha>-installer-removed
        ▼
Dokploy  https://dokploy.securiace.com
        compose app "WHMCS 8.13.5"
        appName whmcs-production-jvjwfo
        source: whmcs-prod-new main
        autoDeploy: off
        │  update WHMCS_IMAGE, VCS_REF, BUILD_DATE only, then deploy
        ▼
https://my.securiace.com
```

## Current production

BTP **1.2.3** is already live. It was deployed on 22 Oct 2026 through `whmcs-prod-new` PR **109**. No production deploy is pending for that release.

## Immutable production contract

| Setting | Value |
|---------|--------|
| `WHMCS_MUTABLE_APP` | `false` |
| `BTP_PROOFS_DIR` | Absolute writable path (recommend `/var/www/storage/banktransferpro/proofs`) |
| Static gateway | Shipped `modules/gateways/banktransferpro.php` |
| `modules/gateways/` | Read-only at runtime |

## Steps (module → live)

1. Merge the module pull request to `main` in this repo (preserve commits; squash only when asked). That merge does not deploy.
2. Vendor the module into `securiace-dev/whmcs-prod-new` under `overlays/`. The catalog is `overlays/catalog.json`. From that repo, run `scripts/sync-overlay-package.sh <id>` with the matching `SYNC_<ID>_SHA` pin set to the module commit (`<id>` is this module's id in the catalog). Run the overlay tests. Open a pull request to `whmcs-prod-new` `main` and merge it only when every check is green. Do not edit Bank Transfer Pro PHP in the host repo.
3. On `whmcs-prod-new` `main`, the `release-identity` CI job publishes `ghcr.io/securiace-dev/whmcs-prod-new@sha256:…` with tag `git-<sha>-installer-removed`.
4. On Dokploy (`https://dokploy.securiace.com`), open compose app **WHMCS 8.13.5** (appName `whmcs-production-jvjwfo`). The app is sourced from `whmcs-prod-new` `main` and autoDeploy stays off. Update only these env values so they match the new image, then deploy:
   - `WHMCS_IMAGE` — `ghcr.io/securiace-dev/whmcs-prod-new@sha256:…`
   - `VCS_REF`
   - `BUILD_DATE`
   Leave every other env value unchanged. The compose command uses `--pull never`. A temporary `--pull missing` for that single deploy is allowed only with owner approval, and the command goes back to `--pull never` immediately after that deploy.
5. Smoke `https://my.securiace.com`:
   - `GET /` → 302
   - `GET /login` → 200
   - `GET /cart.php` → 302
   - admin login → 200
   - this module: an unpaid `banktransferpro` invoice `viewinvoice` shows bank details (not the WHMCS Oops page)

WHMCS system cron stays Dokploy-owned. The release path is the image digest above. Do not rsync this repository onto the live container, and do not ship module source with `docker exec`.

## Lab only (never prod)

```bash
# Requires ~/Projects/whmcs-devbox WHMCS trees
./deploy/lab.sh          # both 8.13 and 9.0
./deploy/lab.sh 8.13     # one target
```

`deploy/lab.sh` rsyncs the addon into local devbox trees and is a lab tool only.

## Sibling patterns (do not conflate)

| Module | Artifact | Prod apply |
|--------|----------|------------|
| This repo (BTP) | Overlay tree pinned by `SYNC_<ID>_SHA` | `whmcs-prod-new` PR → `release-identity` image → Dokploy env `WHMCS_IMAGE`, `VCS_REF`, `BUILD_DATE` |
| RC & LB Tools | `dist/*-whmcs.zip` from `build-whmcs-zip.sh` | Overlay/extract into the same host image; CLI cron via host cron + `docker exec … php -q` |
| Cashfree | Gateway files under `modules/gateways/` | Same image/overlay host |
| MCP event hook | `includes/hooks/mcp_event_hook.php` | Copy + container env (no secrets in git) |

## Agent / operator rules

1. Ask before any Dokploy deploy. A merged module pull request is not a rollout.
2. Change production by updating the three env values in step 4, then deploying that compose app.
3. `docker exec` is for ops (cron, allowlist heal, ownership fixes), not for shipping this module.
4. After a real deploy, comment on the module pull request with the `whmcs-prod-new` PR number, the image digest, and the smoke results. Record setting names only — no IPs, API keys, or tokens.
5. Dokploy compose pull policy stays `--pull never`. The one exception is an owner-approved `--pull missing` for a single deploy, restored to `--pull never` immediately afterwards.
6. BTP 1.2.3 needs no further deploy. It is already in production via `whmcs-prod-new` PR 109 (22 Oct 2026).

## Rollback

On the same Dokploy app, restore the previous `WHMCS_IMAGE` digest together with the `VCS_REF` and `BUILD_DATE` that belonged to that image, then deploy. Reverting only this module's git history leaves production on the image that is already running.
