<ul class="nav nav-tabs btp-nav-tabs">
    <li><a href="{$modulelink}&tab=dashboard">Dashboard</a></li>
    <li><a href="{$modulelink}&tab=info">Info</a></li>
    <li class="active"><a href="{$modulelink}&tab=documentation">Documentation</a></li>
</ul>

<h2>Documentation</h2>

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
        <li>Clients can upload payment proof from unpaid invoices; the addon opens a support ticket with the attachment.</li>
    </ul>

    <h4>Compatibility</h4>
    <p>WHMCS 8.13.x and 9.x, PHP 8.1+. Open source MIT license. No ionCube required.</p>
</div>
