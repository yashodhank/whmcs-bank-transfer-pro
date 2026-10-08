<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Treasury-friendly renderings of ONE instruction pack:
 *  - text(): a plain-text block for "Copy all details" (paste into a bank portal or email)
 *  - sheet(): a print-only wire checklist (tick each field as it is keyed into the bank)
 *
 * Both are derived from the pack the engine already built, so a wire checklist can never
 * contain an instant alias, and the payment reference is always present.
 */
final class PackChecklist
{
    /**
     * @param array<string, mixed> $packSet
     * @param array<string, mixed> $pack
     */
    public static function text(array $packSet, array $pack, string $paymentLabel): string
    {
        $lines = [];
        $lines[] = trim($paymentLabel . ' - ' . (string) ($pack['title'] ?? ''), ' -');

        $invoiceNumber = trim((string) ($packSet['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            $lines[] = 'Invoice: #' . $invoiceNumber;
        }

        $amount = trim((string) ($packSet['amount'] ?? ''));
        if ($amount !== '') {
            $lines[] = 'Amount: ' . trim($amount . ' ' . (string) ($packSet['currency'] ?? ''));
        }

        $reference = (string) ($packSet['reference'] ?? '');
        $fieldValues = [];
        foreach ($pack['fields'] ?? [] as $field) {
            $fieldValues[] = (string) ($field['value'] ?? '');
        }
        if ($reference !== '' && ! in_array($reference, $fieldValues, true)) {
            $lines[] = 'Payment reference: ' . $reference;
        }

        foreach ($pack['fields'] ?? [] as $field) {
            $lines[] = (string) ($field['label'] ?? '') . ': ' . (string) ($field['value'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * Print-only sheet. Hidden on screen; the client script prints just this element.
     *
     * @param array<string, mixed> $packSet
     * @param array<string, mixed> $pack
     */
    public static function sheet(array $packSet, array $pack, string $paymentLabel, string $invoiceRefLabel): string
    {
        $id = self::e((string) ($pack['id'] ?? ''));
        $html = '<div class="btp-print-sheet" data-btp-print-sheet="' . $id . '" aria-hidden="true">';
        $html .= '<h2>' . self::e((string) ($pack['title'] ?? 'Payment')) . ' checklist</h2>';
        $html .= '<p class="btp-print-sheet__meta">Pay via <strong>' . self::e($paymentLabel) . '</strong>';

        $invoiceNumber = trim((string) ($packSet['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            $html .= ' &middot; ' . self::e($invoiceRefLabel) . ' <strong>' . self::e($invoiceNumber) . '</strong>';
        }
        $html .= '</p>';

        $amount = trim((string) ($packSet['amount'] ?? ''));
        if ($amount !== '') {
            $html .= '<p class="btp-print-sheet__amount">Send exactly <strong>' . self::e(trim($amount . ' ' . (string) ($packSet['currency'] ?? ''))) . '</strong></p>';
        }

        $reference = (string) ($packSet['reference'] ?? '');
        if ($reference !== '') {
            $html .= '<p class="btp-print-sheet__reference">Payment reference (must appear in the remittance information): <code>' . self::e($reference) . '</code></p>';
        }

        $html .= '<table class="btp-print-sheet__table"><thead><tr><th scope="col">Done</th><th scope="col">Field</th><th scope="col">Value</th></tr></thead><tbody>';
        foreach ($pack['fields'] ?? [] as $field) {
            $html .= '<tr><td class="btp-print-sheet__box">&#9744;</td><th scope="row">' . self::e((string) ($field['label'] ?? ''))
                . '</th><td>' . nl2br(self::e((string) ($field['value'] ?? ''))) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        foreach ($pack['warnings'] ?? [] as $warning) {
            $html .= '<p class="btp-print-sheet__warning"><strong>Important:</strong> ' . self::e((string) $warning) . '</p>';
        }

        $notes = $pack['notes'] ?? [];
        if ($notes !== []) {
            $html .= '<h3>Before you release the payment</h3><ul>';
            foreach ($notes as $note) {
                $html .= '<li>' . nl2br(self::e((string) $note)) . '</li>';
            }
            $html .= '</ul>';
        }

        $timeline = trim((string) ($pack['timeline'] ?? ''));
        if ($timeline !== '') {
            $html .= '<p><strong>Timeline:</strong> ' . self::e($timeline) . '</p>';
        }

        return $html . '</div>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
