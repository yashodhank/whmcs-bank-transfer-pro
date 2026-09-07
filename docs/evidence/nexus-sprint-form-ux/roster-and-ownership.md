# Roster, base SHA, and file ownership

## Base

| Field | Value |
| --- | --- |
| Repo | `yashodhank/whmcs-bank-transfer-pro` |
| Branch | `fix/admin-form-csrf-whmcs-ux` |
| Base SHA | `470c92f13804102ac20aed5f98334e9009b783e7` (`origin/main`, `fix(admin): wire plain csrf token for CRUD`) |
| GitHub identity | `yashodhank` (preflight healthy: keyring, API, git HTTPS) |
| Carried WIP | Modal-scoped save errors; `BTP_ADDON_ASSET_VERSION` / addon version `1.1.1`; dashboard asset version from constant |
| Not carried | `.cursor/`, `AGENTS.md` (local agent files, not product) |

## Agent roster (this session)

| Phase | Roles |
| --- | --- |
| 0–1 | Agents Orchestrator, Project Shepherd, Senior Project Manager, Sprint Prioritizer, UX Architect |
| 1 | UX Researcher (operator CRUD + client invoice jobs), UI Designer (WHMCS mapping), Legal Compliance Checker, Brand Guardian |
| 2 | Frontend Developer, Backend Architect, DevOps Automator |
| 3 | Senior Developer, Frontend Developer, Backend Architect |
| 4 | Evidence Collector, API Tester, Reality Checker, Test Results Analyzer |
| 5 | DevOps Automator, Project Shepherd, Executive Summary Generator |

Finance Tracker monthly ritual: skipped (engineering-time only). Experiment Tracker: out of scope.

## File ownership

| File | Owner for this sprint |
| --- | --- |
| `modules/addons/banktransferpro/lib/Admin/AjaxController.php` | 3A CSRF/transport |
| `modules/addons/banktransferpro/lib/Admin/DashboardController.php` | 3B settings PRG; 3C `_lang` |
| `modules/addons/banktransferpro/assets/js/admin.js` | 3A form-encoded POST + delete `.catch` |
| `modules/addons/banktransferpro/templates/admin/` | 3A modal token; 3B/3C tabs, lang, FA |
| `modules/addons/banktransferpro/hooks.php` | 3C scoped admin CSS; 3D proof inject + client CSS |
| `modules/addons/banktransferpro/lib/Gateway/GatewayRenderer.php` | 3D theme-native `_link` |
| `modules/addons/banktransferpro/lang/english.php` | 3C flatten `$_ADDONLANG` |
| `modules/addons/banktransferpro/assets/css/admin.css` | 3C layout-only |
| `modules/addons/banktransferpro/assets/css/client.css` | 3D inherit theme, no pastel cards |
| `modules/addons/banktransferpro/lib/Client/UploadController.php` | 3E POST-only invoiceid + CSRF JSON |
| `modules/addons/banktransferpro/banktransferpro.php` | 3C optional `_sidebar`; keep `fields => []`; author Securiace Technologies |
| `tests/AdminDashboardTemplateTest.php` | Keep modal-alert and asset-path guards |

## Permissions

- Do not merge the PR.
- Do not deploy to production.
- Do not edit `whmcs-prod-new`.
- Do not commit to `main`.
- Settings remain on the Info tab (`tbladdonmodules`). Do not migrate to native addon `config()['fields']`.
