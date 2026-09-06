# Bank Transfer Pro for WHMCS

Open-source WHMCS addon that manages multi-currency bank transfer payment gateways, displays bank details on invoices, and lets clients upload payment proof screenshots that automatically open support tickets.

Primary shipping references:

- `docs/adr/0001-independent-shipping-model.md`
- `docs/release-bundle-contract.md`

## Features

- **Admin CRUD dashboard** for bank accounts with modal add/edit UI
- **Mutable-runtime gateway generation** under `modules/gateways/banktransferpro_{slug}.php`
- **Immutable-runtime static gateway** via `modules/gateways/banktransferpro.php`
- **Currency-scoped gateways** using WHMCS `convertto` settings
- **Duplicate prevention** for same bank + branch + currency
- **Invoice bank details box** rendered from the database (no manual gateway file edits)
- **Client payment proof upload** with automatic `OpenTicket` via WHMCS Local API (v1.1)
- **Optional invoice auto-gateway selection** when payment method is generic `banktransfer`

## Requirements

- WHMCS **8.13.x** or **9.x**
- PHP **8.1+**
- Composer
- Mutable installs: writable `modules/gateways/`
- Mutable installs: writable `modules/addons/banktransferpro/storage/proofs/`
- Immutable installs: writable proof directory via `BTP_PROOFS_DIR` or `/var/www/storage/banktransferpro/proofs`

## Installation

1. Copy this repository into your WHMCS root so the addon lives at:
   ```
   modules/addons/banktransferpro/
   ```
   and the immutable-safe gateway file is available at:
   ```
   modules/gateways/banktransferpro.php
   ```
2. Install PHP dependencies at the WHMCS root (or repository root when deployed as a bundle):
   ```bash
   composer install
   ```
3. For mutable installs, set permissions:
   ```bash
   chmod 775 modules/gateways
   chmod -R 775 modules/addons/banktransferpro/storage
   ```
4. For immutable installs, configure the production contract before activation:
   ```bash
   export WHMCS_MUTABLE_APP=false
   export BTP_PROOFS_DIR=/var/www/storage/banktransferpro/proofs
   ```
5. In WHMCS admin, go to **Setup → Addon Modules**, activate **Bank Transfer Pro**, and grant admin access.
6. Open the addon dashboard and add your first bank account.

## Automation helpers

The repo now includes two small install/deploy helpers:

- `composer package:immutable` creates a production-style bundle in `dist/` with the addon, static gateway, docs, and manifests needed for immutable installs.
- `composer validate:install -- /path/to/whmcs` checks the installed file layout and the mode-specific prerequisites.

Examples:

```bash
composer package:immutable
BTP_INSTALL_MODE=immutable BTP_PROOFS_DIR=/var/www/storage/banktransferpro/proofs composer validate:install -- /var/www/html
BTP_INSTALL_MODE=mutable composer validate:install -- /var/www/html
```

### What can be automated today

- Packaging a self-contained immutable bundle from this repo
- Verifying that the addon files and static gateway landed in the expected WHMCS paths
- Checking mutable permission requirements for `modules/gateways` and addon storage
- Checking immutable proof-storage readiness for `BTP_PROOFS_DIR` or the default `/var/www/storage/banktransferpro/proofs`

### What still stays manual

- Copying or deploying the artifact into a specific WHMCS host or image, because each environment owns its own release path and permissions
- Running `composer install` when the target host policy expects a root Composer refresh
- Setting env vars such as `WHMCS_MUTABLE_APP` and `BTP_PROOFS_DIR`
- Activating the addon in WHMCS admin and granting access, because that is an authenticated UI action with application-side effects

## Usage

### Admin

- **Dashboard** — create, edit, and delete bank records. Mutable installs create dedicated gateways; immutable installs use the shipped static `banktransferpro` gateway and resolve the bank from invoice currency.
- **Info** — configure ticket department, upload limits, MIME allow-list, cooldown, and auto-gateway behavior.
- **Documentation** — in-addon install and usage notes.

### Client

- Select the matching Bank Transfer Pro gateway on an invoice to see bank account details.
- On unpaid invoices, upload a JPG/PNG/PDF payment proof; the addon stores the file securely and opens a support ticket with the attachment.

## Development

```bash
composer install
vendor/bin/phpunit
```

Mutable-runtime generated gateway files are runtime artifacts and are gitignored. Immutable deployments should ship the static `modules/gateways/banktransferpro.php` gateway file instead of creating gateways at runtime.

The module no longer depends on the host WHMCS Composer autoloader to discover `BankTransferPro\\` classes. The addon registers its own PSR-4 fallback at runtime, so install validation can distinguish "autoload is optional here" from genuine missing files.

Production hosts should treat this repo as a self-contained artifact. Avoid Bank Transfer Pro source edits in `whmcs-prod-new`; change this repo, cut a new artifact, and redeploy instead.

## Architecture

| Layer | Responsibility |
|-------|----------------|
| Addon (`modules/addons/banktransferpro/`) | Admin UI, migrations, JSON API, hooks, proof storage |
| Static gateway (`modules/gateways/banktransferpro.php`) | Immutable-safe WHMCS payment module delegating to `GatewayRenderer` |
| Generated gateways (`modules/gateways/banktransferpro_*.php`) | Mutable-runtime payment modules delegating to `GatewayRenderer` |
| Database | `mod_btp_banks`, `mod_btp_payment_proofs`, `mod_btp_schema_version` |

## Manual smoke checklist

1. Activate addon → empty dashboard loads
2. Create USD + EUR banks → immutable installs keep one static gateway; mutable installs create per-bank gateway files
3. Create USD invoice → payment method resolves to the correct bank for the invoice currency
4. Edit bank details → invoice shows updated IBAN/details
5. Delete bank → confirmation → gateway deactivated when the last bank is removed
6. Upload payment proof on unpaid invoice → ticket created with attachment in the configured proof directory

## License

MIT — see [LICENSE](LICENSE).
