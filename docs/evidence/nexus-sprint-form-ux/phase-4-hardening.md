# Gate 4 — Hardening packet

## PHPUnit

```
vendor/bin/phpunit
OK (39 tests, 95 assertions)
```

Runtime: PHP 8.5.9. New CSRF/UX contracts in `tests/CsrfUxContractTest.php` are green. Existing `AdminDashboardTemplateTest` modal-alert and asset-path guards remain.

## Browser / live WHMCS

**Not reachable this sprint.** `user-whmcs` `get_whmcs_details` returned HTTP 403 (API allowlist / edge). No local WHMCS URL is in this repo. This sprint does not edit `whmcs-prod-new` and does not deploy.

Substitute evidence: source/template PHPUnit contracts covering add/edit/save/delete transport, Info PRG, `_link()` markup, proof inject, and upload CSRF JSON. Screenshot index remains empty ([screenshot-index.md](screenshot-index.md)).

## API Tester (contract, not live HTTP)

| Path | Happy | Bad token | Notes |
| --- | --- | --- | --- |
| list / get | session only | CSRF not required | `AjaxController::handle` |
| create / update / delete | form-encoded POST + `token` | JSON `CSRF_FAILED` via catch | ProgramExit → JSON |
| settings | `WHMCS.admin.default` + PRG `saved=1` | in-template danger alert | stays on Info tab / `tbladdonmodules` |
| upload | FormData POST `invoiceid` | JSON `CSRF_FAILED` | GET invoiceid removed; owner check unchanged |

Duplicate bank / immutable one-bank-per-currency / `confirm_delete` were not re-litigated; existing repository guards remain.

## Performance

N/A at 10x. Bank list is still a single Capsule read via `BankRepository::all()`.

## Legal / Brand

- CSRF remains on every state-changing POST.
- Proof upload still checks invoice ownership (`userid` vs session `uid`).
- No tokens written to `logActivity` (activation errors only, exception message).
- Author string: Securiace Technologies. Independent module only.

## Reality Checker

**NEEDS WORK** — default until an operator edit round-trip is proven (modal success, row appears, edit persists, settings PRG). PHPUnit is green; that is not a live save.

## Go / no-go for PR

| Check | Verdict |
| --- | --- |
| PHPUnit green | GO |
| P0 CSRF/transport tests | GO |
| Live admin/client round-trip | NO-GO (blocked on WHMCS 403) |
| Merge / production deploy | NO — open PR only |

**PR recommendation:** open to `main` for review. Do not merge or deploy until a live edit round-trip is recorded.
