<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

if (! defined('BTP_ADDON_ASSET_VERSION')) {
    define('BTP_ADDON_ASSET_VERSION', '1.2.2');
}

require_once __DIR__ . '/lib/Bootstrap.php';

use BankTransferPro\Bootstrap;
use BankTransferPro\Client\ProofPanel;
use BankTransferPro\Email\InvoiceEmailRenderer;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PayerContext;
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

function btp_admin_preview_stylesheet_link_tag(): string
{
    $href = '../modules/addons/banktransferpro/assets/css/client.css?v=' . urlencode(BTP_ADDON_ASSET_VERSION);

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

    return btp_stylesheet_link_tag() . btp_admin_preview_stylesheet_link_tag();
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

    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return $vars;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice === null) {
        return $vars;
    }

    $gateway = (string) $invoice->paymentmethod;
    if (! btp_is_supported_gateway($gateway)) {
        return $vars;
    }

    $bank = btp_resolve_invoice_bank($invoice, $gateway);
    if ($bank !== null) {
        $vars['btp_payment_label'] = BankRepository::buildInvoiceLabel($bank);

        $packSet = InstructionPackEngine::build(
            $bank,
            PayerContext::fromClientDetails(
                is_array($vars['clientsdetails'] ?? null) ? $vars['clientsdetails'] : [],
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            ),
            [
                'id' => $invoiceId,
                'number' => (string) ($invoice->invoicenum ?? '') !== '' ? (string) $invoice->invoicenum : (string) $invoiceId,
                'amount' => (string) ($invoice->total ?? ''),
                'currency' => (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice),
            ]
        );
        $vars['btp_payment_reference'] = (string) $packSet['reference'];
        $vars['btp_recommended_pack'] = (string) ($packSet['recommended'] ?? '');
        $vars['btp_invoice_currency'] = (string) ($packSet['currency'] ?? '');
        $vars['btp_pack_options'] = array_values(array_map(
            static fn (array $pack): array => ['id' => (string) $pack['id'], 'title' => (string) $pack['title']],
            $packSet['packs']
        ));
    }

    $vars['btp_support_url'] = btp_support_ticket_url($settings);

    $status = (string) $invoice->status;
    if (
        $settings->isProofUploadEnabled()
        && in_array($status, ['Unpaid', 'Payment Pending'], true)
    ) {
        $vars['btp_show_payment_proof'] = true;
        $vars['btp_upload_action'] = 'index.php?m=banktransferpro&action=upload-proof';
        $vars['btp_invoice_id'] = $invoiceId;
        $vars['btp_csrf_token'] = generate_token('plain');
        $vars['btp_max_upload_mb'] = $settings->get('max_upload_size_mb', '5');
        $vars['btp_allowed_types'] = implode(', ', $settings->allowedMimeTypes());
    }

    return $vars;
});

add_hook('InvoiceCreation', 1, static function (array $vars): void {
    // WHMCS InvoiceCreation does not support a response; write paymentmethod via DB UPDATE.
    try {
        Bootstrap::init();

        $settings = new SettingsRepository();
        if (! $settings->isAutoSelectGatewayEnabled()) {
            return;
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['id', 'paymentmethod', 'userid']);
        if ($invoice === null) {
            return;
        }

        $paymentMethod = (string) ($invoice->paymentmethod ?? '');
        if ($paymentMethod !== '' && $paymentMethod !== 'banktransfer') {
            return;
        }

        $code = (new InvoiceCurrencyResolver())->codeFromHookVars($vars);
        if ($code === null) {
            $code = (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice);
        }
        if ($code === null) {
            return;
        }

        $bank = (new BankRepository())->findActiveByCurrencyCode($code);
        if ($bank === null) {
            return;
        }

        $gateway = RuntimeEnvironment::usesStaticGatewayMode()
            ? 'banktransferpro'
            : (string) $bank['gateway_slug'];

        Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['paymentmethod' => $gateway]);
    } catch (Throwable $exception) {
        if (function_exists('logActivity')) {
            logActivity('Bank Transfer Pro: InvoiceCreation auto-select failed: ' . $exception->getMessage());
        }
    }
});

/**
 * Invoice email merge fields. The stock invoice templates reference them once the admin
 * enables "Invoice emails" on the Info tab (or pastes the snippet from the Documentation tab).
 */
add_hook('EmailPreSend', 1, static function (array $vars): array {
    return btp_invoice_email_merge_fields($vars);
});

add_hook('ClientAreaPageViewInvoice', 2, static function (array $vars): array {
    if (empty($vars['btp_payment_label']) && empty($vars['btp_show_payment_proof'])) {
        return $vars;
    }

    $html = btp_render_invoice_footer($vars);
    btp_payment_proof_footer_html($html);

    return array_merge($vars, [
        'btp_payment_proof_html' => ! empty($vars['btp_show_payment_proof']) ? btp_render_payment_proof_panel($vars) : '',
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
    return ProofPanel::render($vars);
}

/**
 * Footer output for themes that print ClientAreaFooterOutput on the invoice page.
 * Stock themes never do; there the gateway link carries the same assets itself.
 *
 * @param array<string, mixed> $vars
 */
function btp_render_invoice_footer(array $vars): string
{
    $paymentLabel = json_encode((string) ($vars['btp_payment_label'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP);
    if (! is_string($paymentLabel) || $paymentLabel === '') {
        $paymentLabel = '""';
    }
    $panelHtml = '';
    if (! empty($vars['btp_show_payment_proof'])) {
        $panelHtml = btp_render_payment_proof_panel($vars);
    }

    $src = htmlspecialchars('modules/addons/banktransferpro/assets/js/client.js?v=' . urlencode(BTP_ADDON_ASSET_VERSION), ENT_QUOTES, 'UTF-8');

    return $panelHtml . '<script>window.BTP_PAYMENT_LABEL = ' . $paymentLabel . ';</script>'
        . '<script src="' . $src . '"></script>';
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

function btp_support_ticket_url(SettingsRepository $settings): string
{
    $deptId = max(1, $settings->ticketDepartmentId());

    return 'submitticket.php?step=2&deptid=' . urlencode((string) $deptId);
}

function btp_resolve_invoice_bank(object $invoice, string $gateway): ?array
{
    $repo = new BankRepository();
    if ($gateway !== 'banktransferpro') {
        return $repo->findActiveBySlug($gateway);
    }

    $code = (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice);
    if ($code === null) {
        return null;
    }

    return $repo->findActiveByCurrencyCode($code);
}

/**
 * @param array<string, mixed> $vars EmailPreSend hook vars (messagename, relid, ...)
 * @return array<string, string>
 */
function btp_invoice_email_merge_fields(array $vars): array
{
    $invoiceId = (int) ($vars['relid'] ?? 0);
    $template = (string) ($vars['messagename'] ?? '');
    if ($invoiceId <= 0 || $template === '') {
        return [];
    }

    try {
        Bootstrap::init();

        if (! Capsule::table('tblemailtemplates')->where('name', $template)->where('type', 'invoice')->exists()) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null || ! btp_is_supported_gateway((string) $invoice->paymentmethod)) {
            return [];
        }

        $bank = btp_resolve_invoice_bank($invoice, (string) $invoice->paymentmethod);
        if ($bank === null) {
            return [];
        }

        $client = Capsule::table('tblclients')->where('id', (int) $invoice->userid)->first(['country']);
        $packSet = InstructionPackEngine::build(
            $bank,
            PayerContext::fromClientDetails(['country' => $client !== null ? (string) $client->country : '']),
            [
                'id' => $invoiceId,
                'number' => (string) ($invoice->invoicenum ?? '') !== '' ? (string) $invoice->invoicenum : (string) $invoiceId,
                'amount' => btp_invoice_balance_due($invoice),
                'currency' => (new InvoiceCurrencyResolver())->codeFromInvoiceRecord($invoice),
            ]
        );

        return InvoiceEmailRenderer::mergeFields(
            $packSet,
            BankRepository::buildInvoiceLabel($bank),
            btp_invoice_url($invoiceId)
        );
    } catch (Throwable $exception) {
        if (function_exists('logActivity')) {
            logActivity('Bank Transfer Pro: invoice email merge fields failed: ' . $exception->getMessage());
        }

        return [];
    }
}

/**
 * Outstanding balance: invoice total less payments/credit already applied to it.
 */
function btp_invoice_balance_due(object $invoice): ?string
{
    $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', (int) $invoice->id)->sum('amountin')
        - (float) Capsule::table('tblaccounts')->where('invoiceid', (int) $invoice->id)->sum('amountout');
    $balance = round((float) $invoice->total - $paid, 2);

    return $balance > 0 ? number_format($balance, 2, '.', '') : null;
}

function btp_invoice_url(int $invoiceId): string
{
    $base = class_exists(\WHMCS\Config\Setting::class) ? (string) \WHMCS\Config\Setting::getValue('SystemURL') : '';

    return $base === '' ? '' : rtrim($base, '/') . '/viewinvoice.php?id=' . $invoiceId;
}
