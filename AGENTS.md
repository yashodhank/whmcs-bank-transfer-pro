# AGENTS.md — Bank Transfer Pro

Read this before changing or deploying this repository.

## Hard constraints

1. **Independent shipping** — This repo owns the module. Production vendors a pinned commit into `securiace-dev/whmcs-prod-new` under `overlays/` (catalog: `overlays/catalog.json`). Do not fork BTP PHP inside the host repo.
2. **Prod is immutable** — `WHMCS_MUTABLE_APP=false`, proofs under `BTP_PROOFS_DIR`, static `modules/gateways/banktransferpro.php`. See [docs/adr/0001-independent-shipping-model.md](docs/adr/0001-independent-shipping-model.md).
3. **Never lab→prod** — [deploy/lab.sh](deploy/lab.sh) targets `~/Projects/whmcs-devbox` only.
4. **Merging this repo does not deploy.** Ask before any Dokploy change or live `docker exec`. Green CI here is not deploy approval.
5. **No secrets** in chat, commits, or audit notes — setting names and `file:line` only. Never record IPs, API keys, or tokens.
6. **PR delivery** — Atomic commits; prefer merge strategies that preserve history (see user workflow `pr-delivery`).

## Reading order

1. [README.md](README.md)
2. [docs/adr/0001-independent-shipping-model.md](docs/adr/0001-independent-shipping-model.md)
3. [docs/release-bundle-contract.md](docs/release-bundle-contract.md)
4. [docs/runbooks/dokploy-production-deploy.md](docs/runbooks/dokploy-production-deploy.md) — **Dokploy prod path**
5. Host Mac (when available): `~/.ai-audit/` and `~/Projects/` (`whmcs-prod-new`, `whmcs-devbox`, `whmcs-mcp-server`). When those notes disagree with the runbook, follow the runbook.

## Deploy one-liner

Module PR merged to `main` here (this does not deploy) → in `whmcs-prod-new`, `scripts/sync-overlay-package.sh <id>` with `SYNC_<ID>_SHA` → overlay tests → PR to `whmcs-prod-new` `main`, merge when every check is green → `release-identity` publishes `ghcr.io/securiace-dev/whmcs-prod-new@sha256:…` (tag `git-<sha>-installer-removed`) → on Dokploy, update only `WHMCS_IMAGE`, `VCS_REF`, and `BUILD_DATE`, then deploy → smoke (`/` 302, `/login` 200, `/cart.php` 302, admin login 200, viewinvoice).

## Learned facts (retain)

- Live billing: `https://my.securiace.com`. Dokploy: `https://dokploy.securiace.com`. Compose app **WHMCS 8.13.5**, appName `whmcs-production-jvjwfo`, sourced from `whmcs-prod-new` `main`, **autoDeploy off**.
- BTP **1.2.3** is already live in production (deployed 22 Oct 2026 via `whmcs-prod-new` PR **109**). No deploy is pending for that release.
- FastPanel → Dokploy migration (2026-08): no on-disk FastPanel docroot deploy for this site.
- Sibling modules (RC&LB Tools, Cashfree, MCP hooks) share the host; their ship shapes differ (zip vs overlay pin vs hook copy). See the runbook table. This module follows the overlay path above.
