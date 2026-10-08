{include file="tabs.tpl"}

<h2>{$_lang.documentation|default:'Documentation'}</h2>

<div class="well">
    <h4>Installation</h4>
    <ol>
        <li>Copy the repository contents into your WHMCS root directory.</li>
        <li>Run <code>composer install</code> at the WHMCS root (or repository root when deployed as a bundle).</li>
        <li>Copy <code>modules/gateways/banktransferpro.php</code> into your WHMCS gateway directory for immutable deployments.</li>
        <li>For mutable lab installs, ensure <code>modules/gateways</code> is writable by the web server.</li>
        <li>Set <code>BTP_PROOFS_DIR</code> to a writable path for immutable deployments, or ensure <code>modules/addons/banktransferpro/storage/proofs</code> is writable in mutable installs.</li>
        <li>Activate <strong>Bank Transfer Pro</strong> under Setup → Addon Modules.</li>
    </ol>

    <h4>Usage</h4>
    <ul>
        <li>Mutable environments create a dedicated gateway per bank record; immutable deployments use the static <code>banktransferpro</code> gateway and resolve bank details from invoice currency.</li>
        <li>Immutable deployments support one active bank per currency. Edit the existing bank record to change details.</li>
        <li>Clients see bank account details on invoices when they select the matching Bank Transfer Pro gateway.</li>
        <li>Each bank is a receive profile. Tick local transfer, instant payment and/or international wire; clients see one recommended instruction pack and a shared payment reference they must quote with their transfer.</li>
        <li>Clients abroad only see the international wire pack (SWIFT/BIC, address, charge code OUR). Instant aliases such as UPI are never shown for wires.</li>
        <li>Instant packs show a scannable QR code for UPI (India), PayNow (Singapore) and Pix (Brazil); other aliases show copy fields only. Every pack has <em>Copy all details</em>; the international wire pack also has a printable checklist for treasury teams.</li>
        <li>Invoice emails: enable <em>Invoice emails</em> on the Info tab to add the recommended pack and payment reference to the stock invoice templates, or use the merge fields <code>{literal}{$btp_payment_instructions}{/literal}</code> / <code>{literal}{$btp_payment_reference}{/literal}</code> in your own templates.</li>
        <li>Clients can upload payment proof from unpaid invoices; the addon opens a support ticket with the attachment.</li>
    </ul>

    <h4>Compatibility</h4>
    <p>WHMCS 8.13.x and 9.x, PHP 8.1+. Open source MIT license. No ionCube required.</p>
</div>
