<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

if (! defined('BTP_ADDON_ASSET_VERSION')) {
    define('BTP_ADDON_ASSET_VERSION', '1.1.1');
}

require_once __DIR__ . '/lib/Bootstrap.php';

use BankTransferPro\Bootstrap;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\InvoiceCurrencyResolver;
use BankTransferPro\Support\RuntimeEnvironment;
use WHMCS\Database\Capsule;

function btp_stylesheet_link_tag(): string
{
    $href = '../modules/addons/banktransferpro/assets/css/admin.css?v=' . urlencode(BTP_ADDON_ASSET_VERSION);

    return '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
}

function btp_client_stylesheet_link_tag(): string
{
    $href = 'modules/addons/banktransferpro/assets/css/client.css?v=' . urlencode(BTP_ADDON_ASSET_VERSION);

    return '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
}

add_hook('AdminAreaHeadOutput', 1, static function (array $vars = []): string {
    $filename = (string) ($vars['filename'] ?? '');
    $module = (string) ($_GET['module'] ?? '');
    if ($filename !== 'addonmodules' || $module !== 'banktransferpro') {
        return '';
    }

    return btp_stylesheet_link_tag();
});

add_hook('ClientAreaHeadOutput', 1, static function (array $vars = []): string {
    $filename = (string) ($vars['filename'] ?? '');
    if ($filename !== 'viewinvoice') {
        return '';
    }

    return btp_client_stylesheet_link_tag();
});

add_hook('ClientAreaPageViewInvoice', 1, static function (array $vars): array {
    try {
        Bootstrap::init();
    } catch (Throwable) {
        return $vars;
    }

    $settings = new SettingsRepository();
    if (! $settings->isProofUploadEnabled()) {
        return $vars;
    }

    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return $vars;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice === null) {
        return $vars;
    }

    $status = (string) $invoice->status;
    if (! in_array($status, ['Unpaid', 'Payment Pending'], true)) {
        return $vars;
    }

    $gateway = (string) $invoice->paymentmethod;
    if (! btp_is_supported_gateway($gateway)) {
        return $vars;
    }

    $vars['btp_show_payment_proof'] = true;
    $vars['btp_upload_action'] = 'index.php?m=banktransferpro&action=upload-proof';
    $vars['btp_invoice_id'] = $invoiceId;
    $vars['btp_csrf_token'] = generate_token('plain');
    $vars['btp_max_upload_mb'] = $settings->get('max_upload_size_mb', '5');
    $vars['btp_allowed_types'] = implode(', ', $settings->allowedMimeTypes());

    return $vars;
});

add_hook('InvoiceCreation', 1, static function (array $vars): array {
    Bootstrap::init();

    $settings = new SettingsRepository();
    if (! $settings->isAutoSelectGatewayEnabled()) {
        return $vars;
    }

    $paymentMethod = (string) ($vars['paymentmethod'] ?? '');
    if ($paymentMethod !== '' && $paymentMethod !== 'banktransfer') {
        return $vars;
    }

    $code = (new InvoiceCurrencyResolver())->codeFromHookVars($vars);
    if ($code === null) {
        return $vars;
    }

    $bank = (new BankRepository())->findActiveByCurrencyCode($code);
    if ($bank === null) {
        return $vars;
    }

    $vars['paymentmethod'] = RuntimeEnvironment::usesStaticGatewayMode()
        ? 'banktransferpro'
        : $bank['gateway_slug'];

    return $vars;
});

add_hook('ClientAreaPageViewInvoice', 2, static function (array $vars): array {
    if (empty($vars['btp_show_payment_proof'])) {
        return $vars;
    }

    $html = btp_render_payment_proof_panel($vars);
    btp_payment_proof_footer_html($html);

    return array_merge($vars, [
        'btp_payment_proof_html' => $html,
    ]);
});

add_hook('ClientAreaFooterOutput', 1, static function (array $vars = []): string {
    $filename = (string) ($vars['filename'] ?? '');
    if ($filename !== 'viewinvoice') {
        return '';
    }

    return btp_payment_proof_footer_html();
});

/**
 * @param array<string, mixed> $vars
 */
function btp_render_payment_proof_panel(array $vars): string
{
    $uploadAction = htmlspecialchars((string) ($vars['btp_upload_action'] ?? ''), ENT_QUOTES, 'UTF-8');
    $invoiceId = (int) ($vars['btp_invoice_id'] ?? 0);
    $token = htmlspecialchars((string) ($vars['btp_csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
    $maxMb = htmlspecialchars((string) ($vars['btp_max_upload_mb'] ?? '5'), ENT_QUOTES, 'UTF-8');
    $allowed = htmlspecialchars((string) ($vars['btp_allowed_types'] ?? ''), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="btp-payment-proof" id="btp-payment-proof">
    <h4>Upload Payment Proof</h4>
    <p>Upload a screenshot or PDF of your bank transfer receipt. A support ticket will be opened automatically.</p>
    <form method="post" action="{$uploadAction}" enctype="multipart/form-data" class="btp-payment-proof__form">
        <input type="hidden" name="token" value="{$token}" />
        <input type="hidden" name="invoiceid" value="{$invoiceId}" />
        <div class="form-group">
            <label for="btp-proof-file">Payment proof file</label>
            <input type="file" name="proof" id="btp-proof-file" class="form-control" required />
            <p class="help-block">Max {$maxMb} MB. Allowed: {$allowed}</p>
        </div>
        <div class="form-group">
            <label for="btp-proof-note">Optional note</label>
            <textarea name="note" id="btp-proof-note" class="form-control" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Upload &amp; Open Ticket</button>
        <div class="btp-payment-proof__result" aria-live="polite"></div>
    </form>
</div>
<script>
(function () {
    function initPaymentProofForm() {
        var form = document.querySelector('#btp-payment-proof form');
        if (!form || form.dataset.btpBound === '1') { return; }
        form.dataset.btpBound = '1';

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var result = form.querySelector('.btp-payment-proof__result');
            var data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (bodyText) {
                        var payload = null;
                        if (bodyText) {
                            try {
                                payload = JSON.parse(bodyText);
                            } catch (error) {
                                payload = null;
                            }
                        }
                        if (payload && typeof payload.success === 'boolean') {
                            return payload;
                        }
                        var message = 'Upload failed.';
                        if (response && !response.ok) {
                            message = 'Unexpected server response (HTTP ' + response.status + ').';
                        } else if (bodyText) {
                            message = 'Unexpected non-JSON response: ' + bodyText.substring(0, 160);
                        }
                        throw new Error(message);
                    });
                })
                .then(function (payload) {
                    if (payload.success) {
                        result.className = 'btp-payment-proof__result alert alert-success';
                        result.textContent = payload.message || 'Upload successful.';
                        form.reset();
                    } else {
                        result.className = 'btp-payment-proof__result alert alert-danger';
                        result.textContent = (payload.error && payload.error.message) ? payload.error.message : 'Upload failed.';
                    }
                })
                .catch(function (error) {
                    result.className = 'btp-payment-proof__result alert alert-danger';
                    result.textContent = (error && error.message) ? error.message : 'Upload failed. Please try again.';
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPaymentProofForm, { once: true });
    } else {
        initPaymentProofForm();
    }
})();
</script>
HTML;
}

function btp_payment_proof_footer_html(?string $html = null): string
{
    static $stored = '';
    if ($html !== null) {
        $stored = $html;
    }

    return $stored;
}

function btp_is_supported_gateway(string $gateway): bool
{
    return $gateway === 'banktransferpro' || str_starts_with($gateway, 'banktransferpro_');
}
