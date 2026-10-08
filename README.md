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

## Payment Instruction Packs

One bank row (a *receive profile*) serves every way a client can pay. The engine builds copy-optimised packs for the payer and shows **one recommended pack** with the rest under *Paying another way?*.

| Pack | Shown to | Contents |
|------|----------|----------|
| Local bank transfer | Clients in the bank's country | Account name/number, local code (IFSC, ABA, sort code, BSB, ...), bank; NEFT / IMPS / RTGS style clearing systems appear as chips only |
| Pay in seconds | Clients in the bank's country | Alias only (UPI, PayNow, PayID, Pix, ...), never account or wire data |
| International wire | Any client; the **only** pack for clients abroad | Beneficiary + address, account/IBAN, bank + address, SWIFT/BIC, intermediary BIC, charge code (OUR default), remittance reference, optional purpose hint; never an instant alias |

- Identifiers live in JSON (`identifiers`) and are described by `SchemeRegistry`; adding a country scheme is a registry entry, not a migration. Legacy `upi_id` / `ifsc_code` columns are kept in sync and backfilled (`country_code=IN`, capabilities inferred) by migration 005.
- Every pack shares a short, SWIFT-safe **payment reference** (`BTP-{invoiceId}-{check}`, max 20 characters) minted by `PaymentReference`. Proofs and tickets store it (migration 006: `payment_reference`, `pack_id`, `rail_reference`, `declared_amount`, `declared_currency`) and the upload endpoint rejects a missing or mismatched reference.
- **Alias QR codes (Phase B).** The *Pay in seconds* pack renders an inline SVG QR for UPI (`upi://pay`, INR), PayNow (SGQR/EMVCo, SGD) and Pix (BR Code, BRL) from a dependency-free encoder (`lib/Packs/Qr/`), plus an *Open in UPI app* deep link. The invoice amount is pre-filled only when the invoice currency equals the rail currency, and the payment reference travels as the UPI note / PayNow bill number / Pix txid (Pix txid is alphanumeric, so hyphens are dropped). Aliases without a published QR format (PayID, Interac, FPS) stay copy-only. The wire and local packs never carry a QR.
- **Treasury helpers (Phase B).** Every pack has *Copy all details* (plain text, reference included). The international wire pack also has *Print wire checklist*: a tick-box sheet built only from the wire pack, printed through a `@media print` rule so no theme change is needed.
- **Invoice emails (Phase B).** An `EmailPreSend` hook supplies `{$btp_payment_instructions}` (inline-styled HTML of the one recommended pack), `{$btp_payment_instructions_text}`, `{$btp_payment_reference}` and `{$btp_payment_pack}` for Bank Transfer Pro invoices. WHMCS hooks cannot rewrite a mail body, so **Info tab → Invoice emails** offers an opt-in, reversible edit of the stock *Invoice Created*, *Payment Reminder* and *Overdue Notice* templates (marker-wrapped; Remove restores them byte for byte), or paste the snippet from the Info tab yourself. The amount in the email is the outstanding balance; QR codes are not embedded in email, the message links to the invoice instead.
- A send currency different from the invoice currency is blocked unless the profile enables *accept_fx_receive*. No live FX quotes.
- Stock WHMCS `viewinvoice` templates only print the gateway link, so the link output carries its own stylesheet, script and proof form. No theme or `whmcs-prod-new` change is required.
- WHMCS HTML-entity-encodes request input; admin payloads are decoded with `WhmcsInput::decode()` and escaped once on output.

## Manual smoke checklist

1. Activate addon → empty dashboard loads
2. Create USD + EUR banks → immutable installs keep one static gateway; mutable installs create per-bank gateway files
3. Create USD invoice → payment method resolves to the correct bank for the invoice currency
4. Edit bank details → invoice shows updated IBAN/details
5. Delete bank → confirmation → gateway deactivated when the last bank is removed
6. Upload payment proof on unpaid invoice → ticket created with attachment in the configured proof directory
7. Receive Profile wizard: country → identity → capabilities → policies → preview shows desktop, phone and abroad payer views
8. INR bank with local + UPI + wire: domestic client sees Local pack (UPI under *Paying another way?*); client abroad sees only the wire pack with the same payment reference
9. Mobile INR client: *Pay in seconds* is recommended with a UPI QR whose note equals the payment reference; *Copy all details* pastes the full block; *Print wire checklist* (wire pack) prints only the checklist
10. Info tab → *Add to invoice emails*, then trigger *Invoice Created* for a Bank Transfer Pro invoice: the email shows the recommended pack and reference; *Remove from invoice emails* restores the templates

## License

MIT — see [LICENSE](LICENSE).
