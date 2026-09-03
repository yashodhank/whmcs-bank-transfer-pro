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
        <li>Ensure <code>modules/gateways</code> is writable by the web server.</li>
        <li>Ensure <code>modules/addons/banktransferpro/storage/proofs</code> is writable.</li>
        <li>Activate <strong>Bank Transfer Pro</strong> under Setup → Addon Modules.</li>
    </ol>

    <h4>Usage</h4>
    <ul>
        <li>Create bank records from the Dashboard tab. Each record generates an active payment gateway scoped to one currency.</li>
        <li>Clients see bank account details on invoices when they select the generated gateway.</li>
        <li>Clients can upload payment proof from unpaid invoices; the addon opens a support ticket with the attachment.</li>
    </ul>

    <h4>Compatibility</h4>
    <p>WHMCS 8.13.x and 9.x, PHP 8.1+. Open source MIT license. No ionCube required.</p>
</div>
