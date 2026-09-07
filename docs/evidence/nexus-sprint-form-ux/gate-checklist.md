# Gate checklist (Phase 0–5)

| Phase | Gate | Status | Evidence |
| --- | --- | --- | --- |
| 0 | Branch `fix/admin-form-csrf-whmcs-ux` from updated `origin/main`; WIP carried; evidence folder; roster + SHA | **PASS** | This folder; git branch; uncommitted modal-alert / asset `1.1.1` WIP restored onto the branch |
| 1 | CSRF/UI contracts locked; MoSCoW | **PASS** | [architecture-lock.md](architecture-lock.md) |
| 2 | Red PHPUnit contracts exist and fail on pre-fix behavior | **PASS (red)** | [phase-2-red.md](phase-2-red.md) — 14 `CsrfUxContract` failures; existing suite green |
| 3A | Form-encoded mutations; session-only reads; JSON CSRF failures; modal token + delete catch | **PASS** | PHPUnit `CsrfUxContract` + `AdminDashboardTemplate` |
| 3B | Settings `WHMCS.admin.default` + PRG + alert inside `.btp-admin-scope` | **PASS** | `DashboardController` + `info.tpl`; PHPUnit |
| 3C | WHMCS-native admin tabs/lang/FA/scoped CSS/optional sidebar | **PASS** | `lang/english.php`, `tabs.tpl`, `banktransferpro_sidebar` |
| 3D | Theme-native `_link`; no `paymentmethod` pollution; viewinvoice-gated proof+CSS | **PASS** | `hooks.php`, `GatewayRenderer.php`, `client.css` |
| 3E | Client upload CSRF JSON envelope; safe JSON parse; POST-only invoiceid | **PASS** | `UploadController` + proof-form `response.text()` |
| 4 | Full PHPUnit; browser/API or honest substitute; Reality Checker default NEEDS WORK | **PASS (NEEDS WORK)** | [phase-4-hardening.md](phase-4-hardening.md) — PHPUnit 39/39; live WHMCS 403 |
| 5 | graphify update; audit ledger; PR to `main`; no self-merge | pending | PR URL in [phase-5-rollout.md](phase-5-rollout.md) |

Permissions held: no merge, no production deploys, no `whmcs-prod-new` edits, no commit to `main`.
