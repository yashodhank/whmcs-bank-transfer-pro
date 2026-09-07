# Gate 1 — Architecture lock

Architecture-only lock for CSRF transport and WHMCS-native UI. Product files are not changed in this phase.

## Operator and client jobs

| Actor | Job | Success |
| --- | --- | --- |
| Admin | Add bank | Modal Save persists; row appears; errors show in-modal |
| Admin | Edit / update bank | Round-trip: load → change → save → table reflects change |
| Admin | Delete bank | Confirm → row gone; failures visible (`.catch`) |
| Admin | Save Info settings | Persist in `tbladdonmodules`; PRG; success alert inside `.btp-admin-scope` |
| Client | Read bank instructions | Invoice `_link()` HTML, theme-native, no pastel card skin |
| Client | Upload proof | Unpaid BTP invoice; JSON success/error; no `paymentmethod` pollution |

## CSRF / transport contract

Cited: WHMCS `generate_token()` / `check_token()`; admin namespace `WHMCS.admin.default`; client namespace `WHMCS.default`. Token lives in an `application/x-www-form-urlencoded` (or `FormData`) `token` field so `$_POST['token']` is populated. JSON bodies leave `$_POST` empty and `check_token` can `ProgramExit` with HTML.

1. **Admin mutations** (`create` / `update` / `delete`): `POST` form-encoded with `token` + fields. `check_token('WHMCS.admin.default')`. Catch WHMCS `ProgramExit` / `\Throwable` from `check_token` and return JSON `{success:false,error:{code:"CSRF_FAILED"}}`, never HTML, on the API route.
2. **Admin reads** (`list` / `get`): admin session (`assertAdminAccess`) only. No CSRF.
3. **Settings** (Info tab POST): same admin namespace. PRG to `modulelink&tab=info&saved=1`. Success alert rendered **inside** `.btp-admin-scope`.
4. **Client upload**: existing `FormData` POST + `check_token('WHMCS.default')`. Catch HTML/token-exit into the same JSON error envelope. Invoice id from `$_POST['invoiceid']` only.
5. **Token rotation:** do not call token generators that replace the session token on each API hit. Mint once per dashboard/invoice page render via `generate_token('plain')`.

Authoritative docs:

- Addon output / POST forms: https://developers.whmcs.com/addon-modules/admin-area-output/
- Addon configuration (`fields`): https://developers.whmcs.com/addon-modules/configuration/
- Languages (`$_ADDONLANG['key'] = 'string'`, `$vars['_lang']`): https://developers.whmcs.com/addon-modules/multi-language/
- Sample addon: https://github.com/WHMCS/sample-addon-module
- Gateway metadata `gatewayType: Bank`, `_config`, `_link`: https://developers.whmcs.com/payment-gateways/meta-data-params/
- Output hooks + `filename`: https://developers.whmcs.com/hooks-reference/output/

## UI contract

- **Admin:** Blend-compatible Bootstrap 3 only (`nav nav-tabs`, `form-horizontal`, `form-group`, `control-label`, `btn btn-primary|default|danger`, `alert`, `table table-striped`). Font Awesome already in admin. Scoped CSS for layout, not a new palette. Buttons: FA + WHMCS btn classes. Modal keeps BS3 `data-dismiss`. Optional `banktransferpro_sidebar`.
- **Invoice:** `_link()` = bank details + invoice ref (core `banktransfer` pattern). Proof panel via `ClientAreaFooterOutput` (or equivalent) on `viewinvoice` **without** mutating `paymentmethod`. Client CSS only when filename is `viewinvoice`.
- **Lang:** flatten `lang/english.php` to `$_ADDONLANG['key'] = 'string'`. Pass `_lang` into Smarty. Settings stay on Info tab writing `tbladdonmodules`. Do **not** migrate to native `config()['fields']` (department dropdowns).

## MoSCoW

| Priority | Items |
| --- | --- |
| **Must** | P0 CSRF/transport; settings PRG; modal hidden `token` + `method="post"`; delete `.catch`; proof unpollute; scoped assets; flat lang; POST-only invoiceid; safe client JSON parse |
| **Should** | WHMCS-native admin/invoice polish (tabs/`_lang`/FA; theme-native `_link`) |
| **Could** | Addon `_sidebar`; non-JS PRG CRUD |
| **Won't** | New CRUD entities; A/B; custom theme/palette; moving settings into native `config()['fields']`; Payload/React admin; production deploy this sprint |

## Reality Checker (architecture-only)

No live product files changed in Gate 1. Verdict for *architecture completeness*: **GO** (contracts cited). Product readiness remains **NEEDS WORK** until Phase 4 shows an edit round-trip.
