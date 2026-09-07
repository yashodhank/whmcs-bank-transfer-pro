# Screenshot index (placeholder)

Live WHMCS admin/client captures, if reachable in Phase 4. Until then, PHPUnit + rendered-template contracts substitute.

| ID | Flow | Viewport | Path / notes | Status |
| --- | --- | --- | --- | --- |
| S1 | Addon Dashboard — empty table | desktop | pending | not captured |
| S2 | Add bank — modal Save success, row appears | desktop | pending | not captured |
| S3 | Edit bank — round-trip | desktop | pending | not captured |
| S4 | Delete bank — success / CSRF failure | desktop | pending | not captured |
| S5 | Info tab — Save Settings PRG, alert inside `.btp-admin-scope` | desktop | pending | not captured |
| S6 | Invoice `_link()` bank details | desktop | pending | not captured |
| S7 | Invoice proof upload (unpaid BTP invoice) | desktop | pending | not captured |
| S8 | Admin CSS absent off addon page | desktop | pending | not captured |
| S9 | Client CSS absent off `viewinvoice` | desktop | pending | not captured |

Substitute evidence: PHPUnit source/template contracts listed in [gate-checklist.md](gate-checklist.md) and [phase-4-hardening.md](phase-4-hardening.md). Live WHMCS was not reachable (API HTTP 403). Reality Checker remains **NEEDS WORK** until an edit round-trip is proven in a live admin.
