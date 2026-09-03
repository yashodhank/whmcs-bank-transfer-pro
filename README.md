# Bank Transfer Pro for WHMCS

Open-source WHMCS addon that manages multi-currency bank transfer payment gateways, displays bank details on invoices, and lets clients upload payment proof screenshots that automatically open support tickets.

## Features

- **Admin CRUD dashboard** for bank accounts with modal add/edit UI
- **Auto-generated gateway modules** under `modules/gateways/banktransferpro_{slug}.php`
- **Currency-scoped gateways** using WHMCS `convertto` settings
- **Duplicate prevention** for same bank + branch + currency
- **Invoice bank details box** rendered from the database (no manual gateway file edits)
- **Client payment proof upload** with automatic `OpenTicket` via WHMCS Local API (v1.1)
- **Optional invoice auto-gateway selection** when payment method is generic `banktransfer`

## Requirements

- WHMCS **8.13.x** or **9.x**
- PHP **8.1+**
- Composer
- Writable `modules/gateways/`
- Writable `modules/addons/banktransferpro/storage/proofs/`

## Installation

1. Copy this repository into your WHMCS root so the addon lives at:
   ```
   modules/addons/banktransferpro/
   ```
2. Install PHP dependencies at the WHMCS root (or repository root when deployed as a bundle):
   ```bash
   composer install
   ```
3. Set permissions:
   ```bash
   chmod 775 modules/gateways
   chmod -R 775 modules/addons/banktransferpro/storage
   ```
4. In WHMCS admin, go to **Setup → Addon Modules**, activate **Bank Transfer Pro**, and grant admin access.
5. Open the addon dashboard and add your first bank account.

## Usage

### Admin

- **Dashboard** — create, edit, and delete bank records. Each record generates and activates a dedicated gateway.
- **Info** — configure ticket department, upload limits, MIME allow-list, cooldown, and auto-gateway behavior.
- **Documentation** — in-addon install and usage notes.

### Client

- Select a generated Bank Transfer Pro gateway on an invoice to see bank account details.
- On unpaid invoices, upload a JPG/PNG/PDF payment proof; the addon stores the file securely and opens a support ticket with the attachment.

## Development

```bash
composer install
vendor/bin/phpunit
```

Generated gateway files are runtime artifacts and are gitignored. They can be recreated from the addon database at any time.

## Architecture

| Layer | Responsibility |
|-------|----------------|
| Addon (`modules/addons/banktransferpro/`) | Admin UI, migrations, JSON API, hooks, proof storage |
| Generated gateways (`modules/gateways/banktransferpro_*.php`) | WHMCS-native payment modules delegating to `GatewayRenderer` |
| Database | `mod_btp_banks`, `mod_btp_payment_proofs`, `mod_btp_schema_version` |

## Manual smoke checklist

1. Activate addon → empty dashboard loads
2. Create USD + EUR banks → two gateway files + two active gateways in **Setup → Payments**
3. Create USD invoice → dropdown shows USD bank gateway
4. Edit bank details → invoice shows updated IBAN/details
5. Delete bank → confirmation → gateway deactivated + file removed
6. Upload payment proof on unpaid invoice → ticket created with attachment

## License

MIT — see [LICENSE](LICENSE).
