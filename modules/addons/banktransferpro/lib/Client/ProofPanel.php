<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Repository\SettingsRepository;

/**
 * "Confirm payment" panel: proof upload plus payment reference, pack used, rail reference
 * and declared amount. Rendered by the gateway link (stock themes) and by the invoice hooks.
 */
final class ProofPanel
{
    /**
     * @param array<string, mixed> $packSet InstructionPackEngine::build() result
     * @return array<string, mixed>
     */
    public static function context(array $packSet, int $invoiceId, SettingsRepository $settings, string $token): array
    {
        $options = [];
        foreach ($packSet['packs'] ?? [] as $pack) {
            $options[] = ['id' => (string) $pack['id'], 'title' => (string) $pack['title']];
        }

        $deptId = max(1, $settings->ticketDepartmentId());

        return [
            'btp_upload_action' => 'index.php?m=banktransferpro&action=upload-proof',
            'btp_invoice_id' => $invoiceId,
            'btp_csrf_token' => $token,
            'btp_max_upload_mb' => $settings->get('max_upload_size_mb', '5'),
            'btp_allowed_types' => implode(', ', $settings->allowedMimeTypes()),
            'btp_support_url' => 'submitticket.php?step=2&deptid=' . $deptId,
            'btp_payment_reference' => (string) ($packSet['reference'] ?? ''),
            'btp_recommended_pack' => (string) ($packSet['recommended'] ?? ''),
            'btp_invoice_currency' => (string) ($packSet['currency'] ?? ''),
            'btp_pack_options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function render(array $vars): string
    {
        $uploadAction = htmlspecialchars((string) ($vars['btp_upload_action'] ?? ''), ENT_QUOTES, 'UTF-8');
        $invoiceId = (int) ($vars['btp_invoice_id'] ?? 0);
        $token = htmlspecialchars((string) ($vars['btp_csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
        $maxMb = htmlspecialchars((string) ($vars['btp_max_upload_mb'] ?? '5'), ENT_QUOTES, 'UTF-8');
        $allowed = htmlspecialchars((string) ($vars['btp_allowed_types'] ?? ''), ENT_QUOTES, 'UTF-8');
        $supportUrl = htmlspecialchars((string) ($vars['btp_support_url'] ?? 'supporttickets.php'), ENT_QUOTES, 'UTF-8');
        $reference = htmlspecialchars((string) ($vars['btp_payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8');
        $currency = htmlspecialchars((string) ($vars['btp_invoice_currency'] ?? ''), ENT_QUOTES, 'UTF-8');
        $recommended = (string) ($vars['btp_recommended_pack'] ?? '');

        $packChoices = '';
        $options = is_array($vars['btp_pack_options'] ?? null) ? $vars['btp_pack_options'] : [];
        if (count($options) > 1) {
            $packChoices = '<div class="form-group btp-payment-proof__pack"><label>How did you pay?</label>';
            foreach ($options as $option) {
                $id = htmlspecialchars((string) $option['id'], ENT_QUOTES, 'UTF-8');
                $title = htmlspecialchars((string) $option['title'], ENT_QUOTES, 'UTF-8');
                $checked = (string) $option['id'] === $recommended ? ' checked' : '';
                $packChoices .= '<div class="radio"><label><input type="radio" name="pack_id" value="' . $id . '"' . $checked . ' /> ' . $title . '</label></div>';
            }
            $packChoices .= '</div>';
        } elseif (count($options) === 1) {
            $packChoices = '<input type="hidden" name="pack_id" value="' . htmlspecialchars((string) $options[0]['id'], ENT_QUOTES, 'UTF-8') . '" />';
        }

        return <<<HTML
<div class="btp-payment-proof" id="btp-payment-proof">
    <h4>Upload Payment Proof</h4>
    <p>Upload a screenshot or PDF of your bank transfer receipt. A support ticket will be opened automatically.</p>
    <form method="post" action="{$uploadAction}" enctype="multipart/form-data" class="btp-payment-proof__form">
        <input type="hidden" name="token" value="{$token}" />
        <input type="hidden" name="invoiceid" value="{$invoiceId}" />
        <input type="hidden" name="payment_reference" value="{$reference}" />
        <p class="btp-payment-proof__reference">Payment reference: <strong>{$reference}</strong></p>
        {$packChoices}
        <div class="form-group">
            <label for="btp-proof-rail-ref">Bank reference (UTR / UETR / RRN) <span class="text-muted">optional</span></label>
            <input type="text" name="rail_reference" id="btp-proof-rail-ref" class="form-control" maxlength="64" autocomplete="off" />
        </div>
        <div class="form-group btp-payment-proof__amount">
            <label for="btp-proof-amount">Amount you sent <span class="text-muted">optional</span></label>
            <div class="btp-payment-proof__amount-row">
                <input type="text" name="declared_amount" id="btp-proof-amount" class="form-control" inputmode="decimal" autocomplete="off" />
                <input type="text" name="declared_currency" value="{$currency}" maxlength="3" class="form-control btp-payment-proof__currency" aria-label="Currency you sent" />
            </div>
        </div>
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
        <a href="{$supportUrl}" class="btn btn-default btp-payment-proof__support-link">Open Support Instead</a>
        <p class="help-block">If you cannot upload here, open a support ticket or reply to your invoice email with the receipt screenshot.</p>
        <div class="btp-payment-proof__result" aria-live="polite"></div>
    </form>
</div>
HTML;
    }
}
