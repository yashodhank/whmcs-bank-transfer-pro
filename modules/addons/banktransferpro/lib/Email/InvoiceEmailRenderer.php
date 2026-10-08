<?php

declare(strict_types=1);

namespace BankTransferPro\Email;

/**
 * Turns an InstructionPackEngine result into WHMCS email merge fields:
 *
 *   {$btp_payment_reference}          BTP-10482-T
 *   {$btp_payment_pack}               "Local bank transfer" (title of the recommended pack)
 *   {$btp_payment_instructions}       self-contained HTML block (inline styles, no assets)
 *   {$btp_payment_instructions_text}  plain-text equivalent
 *
 * Only the ONE recommended pack is rendered; every other way to pay lives on the invoice
 * page ("Paying another way?"), which the email links to. QR codes are not embedded in
 * email (clients block inline SVG/images) - the invoice link carries them instead.
 */
final class InvoiceEmailRenderer
{
    public const FIELD_REFERENCE = 'btp_payment_reference';
    public const FIELD_PACK = 'btp_payment_pack';
    public const FIELD_HTML = 'btp_payment_instructions';
    public const FIELD_TEXT = 'btp_payment_instructions_text';

    /**
     * @param array<string, mixed> $packSet InstructionPackEngine::build() result
     * @return array<string, string>
     */
    public static function mergeFields(array $packSet, string $paymentLabel, string $invoiceUrl = ''): array
    {
        $recommended = is_string($packSet['recommended'] ?? null) ? $packSet['recommended'] : '';
        $pack = $recommended !== '' && is_array($packSet['packs'][$recommended] ?? null) ? $packSet['packs'][$recommended] : null;

        return [
            self::FIELD_REFERENCE => (string) ($packSet['reference'] ?? ''),
            self::FIELD_PACK => $pack !== null ? (string) ($pack['title'] ?? '') : '',
            self::FIELD_HTML => self::html($packSet, $pack, $paymentLabel, $invoiceUrl),
            self::FIELD_TEXT => self::text($packSet, $pack, $paymentLabel, $invoiceUrl),
        ];
    }

    /**
     * @param array<string, mixed> $packSet
     * @param array<string, mixed>|null $pack
     */
    private static function html(array $packSet, ?array $pack, string $paymentLabel, string $invoiceUrl): string
    {
        $box = 'margin:16px 0;padding:14px;border:1px solid #dddddd;border-radius:4px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222222;';
        $html = '<div class="btp-email-instructions" style="' . $box . '">';
        $html .= '<p style="margin:0 0 8px;"><strong>Pay via ' . self::e($paymentLabel) . '</strong></p>';

        $error = trim((string) ($packSet['error'] ?? ''));
        if ($error !== '') {
            return $html . '<p style="margin:0;">' . self::e($error) . '</p>' . self::link($invoiceUrl, 'Open your invoice') . '</div>';
        }

        $amount = trim((string) ($packSet['amount'] ?? ''));
        if ($amount !== '') {
            $html .= '<p style="margin:0 0 8px;">Send exactly <strong>' . self::e(trim($amount . ' ' . (string) ($packSet['currency'] ?? ''))) . '</strong></p>';
        }

        $reference = (string) ($packSet['reference'] ?? '');
        if ($reference !== '') {
            $html .= '<p style="margin:0 0 12px;padding:8px 10px;background:#fffdf3;border:1px dashed #999999;">'
                . 'Payment reference: <strong style="font-family:Courier New,monospace;font-size:17px;letter-spacing:0.04em;">' . self::e($reference) . '</strong><br />'
                . '<span style="font-size:12px;color:#666666;">Put this exact code in your transfer remarks or remittance information. Banks often shorten long invoice numbers.</span></p>';
        }

        if ($pack === null) {
            $notes = trim((string) ($packSet['legacy_notes'] ?? ''));
            if ($notes !== '') {
                $html .= '<p style="margin:0;">' . nl2br(self::e($notes)) . '</p>';
            }

            return $html . self::link($invoiceUrl, 'Open your invoice') . '</div>';
        }

        $html .= '<p style="margin:0 0 6px;"><strong>' . self::e((string) ($pack['title'] ?? '')) . '</strong>';
        $subtitle = trim((string) ($pack['subtitle'] ?? ''));
        if ($subtitle !== '') {
            $html .= ' <span style="color:#666666;">&mdash; ' . self::e($subtitle) . '</span>';
        }
        $html .= '</p>';

        foreach ($pack['warnings'] ?? [] as $warning) {
            $html .= '<p style="margin:0 0 8px;padding:8px;background:#fcf8e3;border:1px solid #faebcc;">' . self::e((string) $warning) . '</p>';
        }

        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 8px;">';
        foreach ($pack['fields'] ?? [] as $field) {
            $html .= '<tr><td style="padding:3px 12px 3px 0;color:#555555;vertical-align:top;">' . self::e((string) ($field['label'] ?? ''))
                . '</td><td style="padding:3px 0;font-weight:bold;">' . nl2br(self::e((string) ($field['value'] ?? ''))) . '</td></tr>';
        }
        $html .= '</table>';

        $notes = $pack['notes'] ?? [];
        if ($notes !== []) {
            $html .= '<ul style="margin:0 0 8px;padding-left:18px;font-size:13px;">';
            foreach ($notes as $note) {
                $html .= '<li>' . nl2br(self::e((string) $note)) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . self::link($invoiceUrl, self::linkLabel($pack)) . '</div>';
    }

    /**
     * @param array<string, mixed> $packSet
     * @param array<string, mixed>|null $pack
     */
    private static function text(array $packSet, ?array $pack, string $paymentLabel, string $invoiceUrl): string
    {
        $lines = ['Pay via ' . $paymentLabel];

        $error = trim((string) ($packSet['error'] ?? ''));
        if ($error !== '') {
            $lines[] = $error;

            return self::withLink($lines, $invoiceUrl, 'Open your invoice');
        }

        $amount = trim((string) ($packSet['amount'] ?? ''));
        if ($amount !== '') {
            $lines[] = 'Send exactly ' . trim($amount . ' ' . (string) ($packSet['currency'] ?? ''));
        }

        $reference = (string) ($packSet['reference'] ?? '');
        if ($reference !== '') {
            $lines[] = 'Payment reference: ' . $reference;
            $lines[] = '(Put this exact code in your transfer remarks or remittance information.)';
        }

        if ($pack === null) {
            $notes = trim((string) ($packSet['legacy_notes'] ?? ''));
            if ($notes !== '') {
                $lines[] = '';
                $lines[] = $notes;
            }

            return self::withLink($lines, $invoiceUrl, 'Open your invoice');
        }

        $lines[] = '';
        $lines[] = (string) ($pack['title'] ?? '');
        foreach ($pack['warnings'] ?? [] as $warning) {
            $lines[] = '! ' . (string) $warning;
        }
        foreach ($pack['fields'] ?? [] as $field) {
            $lines[] = (string) ($field['label'] ?? '') . ': ' . (string) ($field['value'] ?? '');
        }
        foreach ($pack['notes'] ?? [] as $note) {
            $lines[] = '- ' . (string) $note;
        }

        return self::withLink($lines, $invoiceUrl, self::linkLabel($pack));
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function linkLabel(array $pack): string
    {
        if (($pack['qr'] ?? []) !== []) {
            return 'Open your invoice to scan the QR code or pay another way';
        }

        return ($pack['id'] ?? '') === 'wire'
            ? 'Open your invoice'
            : 'Paying another way? Open your invoice';
    }

    private static function link(string $url, string $label): string
    {
        if ($url === '') {
            return '';
        }

        return '<p style="margin:8px 0 0;font-size:13px;"><a href="' . self::e($url) . '" style="color:#1a5fb4;">' . self::e($label) . '</a></p>';
    }

    /**
     * @param list<string> $lines
     */
    private static function withLink(array $lines, string $url, string $label): string
    {
        if ($url !== '') {
            $lines[] = '';
            $lines[] = $label . ': ' . $url;
        }

        return implode("\n", $lines);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
