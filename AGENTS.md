# AGENTS.md — Bank Transfer Pro

Read this before changing or deploying this repository.

## Hard constraints

1. **Independent shipping** — This repo owns the module artifact. Production consumes it via overlay pin in `securiace-dev/whmcs-prod-new`, then Dokploy via `securiace-dev/securiace-whmcs-prod`. Do not fork BTP PHP inside the host image repo.
2. **Prod is immutable** — `WHMCS_MUTABLE_APP=false`, proofs under `BTP_PROOFS_DIR`, static `modules/gateways/banktransferpro.php`. See [docs/adr/0001-independent-shipping-model.md](docs/adr/0001-independent-shipping-model.md).
3. **Never lab→prod** — [deploy/lab.sh](deploy/lab.sh) targets `~/Projects/whmcs-devbox` only.
4. **Ask before Dokploy / live `docker exec` / image lock changes.** Green CI ≠ deploy approval.
5. **No secrets** in chat, commits, or audit notes — setting names and `file:line` only.
6. **PR delivery** — Atomic commits; prefer merge strategies that preserve history (see user workflow `pr-delivery`).

## Reading order

1. [README.md](README.md)
2. [docs/adr/0001-independent-shipping-model.md](docs/adr/0001-independent-shipping-model.md)
3. [docs/release-bundle-contract.md](docs/release-bundle-contract.md)
4. [docs/runbooks/dokploy-production-deploy.md](docs/runbooks/dokploy-production-deploy.md) — **Dokploy prod path (do not forget)**
5. Host Mac (when available): `~/.ai-audit/` and related `~/Projects/` (whmcs-prod-new, securiace-whmcs-prod, whmcs-devbox, whmcs-mcp-server)

## Deploy one-liner

Module PR → `composer package:immutable` → pin overlay in `whmcs-prod-new` → rebuild image → update `securiace-whmcs-prod` lock → Dokploy redeploy → `validate-install` + viewinvoice smoke.

## Learned facts (retain)

- Live billing: `https://my.securiace.com` on Dokploy; app container name matches `whmcs-production-*-app-1`.
- Recent BTP prod ship: **1.2.2** via `whmcs-prod-new` overlay PR **102** / `main` @ `20735c1`.
- FastPanel → Dokploy migration (2026-08): no more on-disk FastPanel docroot deploy for this site.
- Sibling modules (RC&LB Tools, Cashfree, MCP hooks) use the same host; ship shapes differ (zip vs overlay pin vs hook copy) — see the Dokploy runbook table.
