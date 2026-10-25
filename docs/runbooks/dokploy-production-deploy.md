# Runbook: Dokploy production deploy (Bank Transfer Pro)

This is the **production** path for `my.securiace.com`. It complements [ADR 0001](../adr/0001-independent-shipping-model.md) and the [release bundle contract](../release-bundle-contract.md).

> Cloud agents often cannot see the Mac host’s `~/.ai-audit/` or `~/Projects/`. When those paths exist, treat them as authoritative and reconcile with this runbook. Do **not** invent a live `rsync`/`scp` deploy into prod.

## Topology

```
whmcs-bank-transfer-pro (this repo)
        │  immutable artifact (composer package:immutable)
        ▼
securiace-dev/whmcs-prod-new     ← overlay pin only (no module source forks)
        │  build WHMCS image
        ▼
securiace-dev/securiace-whmcs-prod  ← image.lock / instance.yaml
        │  Dokploy / Coolify rollout
        ▼
Container whmcs-production-*-app-1 on ded1.securiace.com
  WHMCS_ROOT=/var/www/html
  Public: https://my.securiace.com
```

Evidence from recent deploys:

- BTP **1.2.2** shipped via overlay pin on `whmcs-prod-new` **PR 102**, then overlay `main` @ `20735c1`, then prod browser re-verify.
- Host ops (other modules) use `docker exec` into `whmcs-production-jvjwfo-app-1` (name pattern `whmcs-production-*-app-1`).
- 2026-08: FastPanel → Dokploy; old on-disk WHMCS root + dedicated updater SSH account are gone.

## Immutable production contract

| Setting | Value |
|---------|--------|
| `WHMCS_MUTABLE_APP` | `false` |
| `BTP_PROOFS_DIR` | Absolute writable path (recommend `/var/www/storage/banktransferpro/proofs`) |
| Static gateway | Shipped `modules/gateways/banktransferpro.php` |
| `modules/gateways/` | Read-only at runtime |

## Steps (module → live)

1. Merge the module PR to `main` (preserve commits; no squash unless asked).
2. Build the artifact: `composer package:immutable`.
3. In **`whmcs-prod-new`**, bump the Bank Transfer Pro overlay pin to that artifact version/digest. Open a host-repo PR; do **not** edit BTP PHP there.
4. Rebuild the WHMCS image; update **`securiace-whmcs-prod`** `image.lock` (and `instance.yaml` if needed) to the new digest.
5. Redeploy via Dokploy (operator action). WHMCS system cron remains Dokploy-owned.
6. Validate inside the running app (or image):  
   `BTP_INSTALL_MODE=immutable bash scripts/validate-install.sh /var/www/html`
7. Smoke:
   - Addon version matches release (admin UI + on-disk `banktransferpro_config` + `tbladdonmodules` if checked)
   - Asset cache-bust `BTP_ADDON_ASSET_VERSION` matches
   - Unpaid BTP invoice `viewinvoice` shows bank details (not Oops)
   - Proof upload still JSON-shaped on success/failure

## Lab only (never prod)

```bash
# Requires ~/Projects/whmcs-devbox WHMCS trees
./deploy/lab.sh          # both 8.13 and 9.0
./deploy/lab.sh 8.13     # one target
```

`deploy/lab.sh` rsyncs the addon into local devbox trees and intentionally refuses to be a production tool.

## Sibling patterns (do not conflate)

| Module | Artifact | Prod apply |
|--------|----------|------------|
| This repo (BTP) | Immutable tarball/bundle | Overlay pin → image → Dokploy |
| RC & LB Tools | `dist/*-whmcs.zip` from `build-whmcs-zip.sh` | Overlay/extract into image; CLI cron via host cron + `docker exec … php -q` |
| Cashfree | Gateway files under `modules/gateways/` | Same image/overlay host |
| MCP event hook | `includes/hooks/mcp_event_hook.php` | Copy + container env (no secrets in git) |

## Agent / operator rules

1. **Ask before deploy.** A green module PR is not permission to roll Dokploy.
2. Prefer image/overlay rollout over editing files in the live container.
3. `docker exec` is for ops (cron, allowlist heal, ownership fixes), not for shipping module source of record.
4. After deploy, comment on the module PR with overlay PR/SHA, digest if known, and smoke results.
5. On the Mac host, also read `~/.ai-audit/` before changing the path.

## Rollback

- Revert the overlay pin / `image.lock` digest to the previous known-good image and redeploy.
- Module-only git revert without a host pin change does **not** remove the code from prod.
