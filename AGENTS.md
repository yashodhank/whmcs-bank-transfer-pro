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

## Gates and conventions (added by continuity pass)

```bash
composer install && composer test                 # phpunit (phpunit.xml, tests/)
bash scripts/validate-install.sh                  # composer validate:install
bash scripts/package-immutable-bundle.sh          # composer package:immutable (local build only)
find modules -name '*.php' -print0 | xargs -0 -n1 php -l
bash scripts/check-continuity.sh
```

Version constant: `'version' => '1.2.3'` in `modules/addons/banktransferpro/banktransferpro.php`. The repo has no CHANGELOG; do not invent one (the check skips it). ADRs live in `docs/adr/` here (next number used by the continuity pass: 0002). Public repo: no hostnames beyond what is already documented, no secrets. Branch `feat|fix|docs|chore/<scope>` from `origin/main`; PR against `main`. This desk may merge module PRs after review; leave `whmcs-prod-new` PRs for WHMCS-Prod; never commit directly to `main`. Done = gates green, STATE updated.

## Continuity (tool-agnostic)

This repo follows the desk continuity standard (`CONTINUITY-STANDARD.md` in desk-os). Claude Code, Codex, agy, OpenCode, Cursor and any future tool start here.

- **Entry point:** this file (`AGENTS.md`). `CLAUDE.md`, `GEMINI.md` and `.cursor/rules/agents.mdc` only point here.
- **Session state:** `docs/STATE.md` — current version, what is in flight, next steps, last-session summary. Rewrite it at the end of every session (IST timestamps).
- **Decisions:** `docs/adr/` (ADR files; this repo uses `adr`, not `decisions`). Add one when the owner or an architecture choice is made. Do not rewrite old ADRs.
- **Agreement gate:** `bash scripts/check-continuity.sh` must pass before push (`docs/continuity.conf`). It fails if the version, CHANGELOG and STATE disagree.
- **Checkpoints:** small commits; push the feature or WIP branch before you stop; leave no uncommitted work in the worktree; never switch or dirty the user's primary checkout. Use a new worktree from `origin/<default-branch>`.
- **Desk checklists:** `SESSION-START.md` and `SESSION-END.md` in desk-os (`/workspace/whmcs-modules/desk-os/` or `~/Projects/whmcs-modules/desk-os/`).
- Enable hooks in each clone or worktree: `git config core.hooksPath .githooks`.

### Hard rules (fleet)

- No secrets in chat or commits. Never print the live admin folder name (write `ADMIN_PATH`). Never circumvent ionCube. Never touch the WHMCS core licence.
- Production is read-only for this desk. No live deploy. `whmcs-prod-new` merges belong to WHMCS-Prod; deploys belong to CntbOps after Mr. Y's own pick.
- Local checks only; no paid GitHub Actions. Use `gh-account` (unset `GIT_CONFIG_*` first). Repos stay private unless the owner says otherwise.
