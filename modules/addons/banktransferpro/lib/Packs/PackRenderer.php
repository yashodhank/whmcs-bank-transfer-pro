<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

use BankTransferPro\Packs\Qr\QrCode;

/**
 * Renders an InstructionPackEngine result as invoice/preview HTML:
 * Pay via {bank} -> exact amount -> payment reference -> ONE recommended pack ->
 * "Paying another way?" for the rest. Never prints the module name.
 */
final class PackRenderer
{
    /**
     * @param array<string, mixed> $packSet
     */
    public static function render(array $packSet, string $paymentLabel, string $invoiceRefLabel = 'Invoice Reference'): string
    {
        $label = self::e($paymentLabel);
        $html = '<div class="btp-bank-details btp-packs" data-btp-reference="' . self::e((string) ($packSet['reference'] ?? '')) . '" data-btp-label="' . $label . '">';
        $html .= '<div class="btp-bank-details__summary"><span class="btp-bank-details__summary-label">Pay via</span><strong>' . $label . '</strong></div>';

        if (! empty($packSet['error'])) {
            $html .= '<div class="alert alert-warning btp-pack-error">' . self::e((string) $packSet['error']) . '</div>';
            $html .= self::invoiceReference($packSet, $invoiceRefLabel);

            return $html . '</div>';
        }

        $html .= self::amountStrip($packSet);
        $html .= self::referenceHero($packSet);

        if (! empty($packSet['legacy_only'])) {
            $notes = trim((string) ($packSet['legacy_notes'] ?? ''));
            if ($notes !== '') {
                $html .= '<div class="btp-bank-details__notes">' . nl2br(self::e($notes)) . '</div>';
            }
            $html .= self::invoiceReference($packSet, $invoiceRefLabel);

            return $html . '</div>';
        }

        $packs = is_array($packSet['packs'] ?? null) ? $packSet['packs'] : [];
        $recommended = is_string($packSet['recommended'] ?? null) ? $packSet['recommended'] : '';

        if ($recommended !== '' && isset($packs[$recommended])) {
            $html .= self::pack($packs[$recommended], true, $packSet, $paymentLabel, $invoiceRefLabel);
        }

        $alternatives = array_values(array_filter(
            array_keys($packs),
            static fn (string $id): bool => $id !== $recommended
        ));
        if ($alternatives !== []) {
            $html .= '<details class="btp-pack-alt"><summary>Paying another way?</summary>';
            foreach ($alternatives as $id) {
                $html .= self::pack($packs[$id], false, $packSet, $paymentLabel, $invoiceRefLabel);
            }
            $html .= '</details>';
        }

        $html .= self::invoiceReference($packSet, $invoiceRefLabel);

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $packSet
     */
    private static function amountStrip(array $packSet): string
    {
        $amount = trim((string) ($packSet['amount'] ?? ''));
        if ($amount === '') {
            return '';
        }

        $currency = trim((string) ($packSet['currency'] ?? ''));

        return '<div class="btp-amount-strip"><span>Send exactly</span> <strong>' . self::e(trim($amount . ' ' . $currency)) . '</strong></div>';
    }

    /**
     * @param array<string, mixed> $packSet
     */
    private static function referenceHero(array $packSet): string
    {
        $reference = (string) ($packSet['reference'] ?? '');
        if ($reference === '') {
            return '';
        }

        $safe = self::e($reference);

        return '<div class="btp-reference-hero">'
            . '<span class="btp-reference-hero__label">Payment reference</span>'
            . '<code class="btp-reference-hero__value">' . $safe . '</code>'
            . self::copyButton($reference, 'Payment reference')
            . '<p class="btp-reference-hero__help">Put this exact code in your transfer remarks or remittance information. Banks often shorten long invoice numbers.</p>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $packSet
     */
    private static function pack(array $pack, bool $recommended, array $packSet, string $paymentLabel, string $invoiceRefLabel): string
    {
        $id = (string) ($pack['id'] ?? '');
        $class = 'btp-pack btp-pack--' . self::e($id) . ($recommended ? ' btp-pack--recommended' : '');

        $html = '<section class="' . $class . '" data-btp-pack="' . self::e($id) . '">';
        $html .= '<header class="btp-pack__header"><div class="btp-pack__title">' . self::e((string) ($pack['title'] ?? ''));
        if ($recommended) {
            $html .= ' <span class="label label-success btp-pack__badge">Recommended</span>';
        }
        $html .= '</div>';
        $subtitle = trim((string) ($pack['subtitle'] ?? ''));
        if ($subtitle !== '') {
            $html .= '<div class="btp-pack__subtitle">' . self::e($subtitle) . '</div>';
        }
        $html .= self::actions($pack, $packSet, $paymentLabel);
        $html .= '</header>';

        foreach ($pack['warnings'] ?? [] as $warning) {
            $html .= '<div class="alert alert-warning btp-pack__warning">' . self::e((string) $warning) . '</div>';
        }

        $html .= self::qrCodes($pack);

        foreach ($pack['fields'] ?? [] as $field) {
            $html .= self::fieldRow((string) ($field['label'] ?? ''), (string) ($field['value'] ?? ''));
        }

        $chips = $pack['chips'] ?? [];
        if ($chips !== []) {
            $html .= '<div class="btp-pack__chips"><span class="btp-pack__chips-label">Works with</span>';
            foreach ($chips as $chip) {
                $html .= '<span class="btp-chip">' . self::e((string) $chip) . '</span>';
            }
            $html .= '</div>';
        }

        $notes = $pack['notes'] ?? [];
        if ($notes !== []) {
            $html .= '<ul class="btp-pack__notes">';
            foreach ($notes as $note) {
                $html .= '<li>' . nl2br(self::e((string) $note)) . '</li>';
            }
            $html .= '</ul>';
        }

        $timeline = trim((string) ($pack['timeline'] ?? ''));
        if ($timeline !== '') {
            $html .= '<p class="btp-pack__timeline"><strong>Timeline:</strong> ' . self::e($timeline) . '</p>';
        }

        if ($id === InstructionPackEngine::PACK_WIRE) {
            $html .= PackChecklist::sheet($packSet, $pack, $paymentLabel, $invoiceRefLabel);
        }

        return $html . '</section>';
    }

    /**
     * Treasury helpers: copy every field at once; print a tick-box checklist for the wire pack.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $packSet
     */
    private static function actions(array $pack, array $packSet, string $paymentLabel): string
    {
        if (($pack['fields'] ?? []) === []) {
            return '';
        }

        $html = '<div class="btp-pack__actions">'
            . ' <button type="button" class="btn btn-default btn-xs btp-copy btp-copy--all" data-btp-copy="'
            . self::e(PackChecklist::text($packSet, $pack, $paymentLabel)) . '" aria-label="Copy all payment details">Copy all details</button>';

        if (($pack['id'] ?? '') === InstructionPackEngine::PACK_WIRE) {
            $html .= ' <button type="button" class="btn btn-default btn-xs btp-print" data-btp-print="wire">Print wire checklist</button>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $pack
     */
    private static function qrCodes(array $pack): string
    {
        $html = '';
        foreach ($pack['qr'] ?? [] as $qr) {
            $payload = (string) ($qr['payload'] ?? '');
            if ($payload === '' || ! QrCode::fits($payload)) {
                continue;
            }

            $label = (string) ($qr['label'] ?? 'Payment') . ' QR code';
            $html .= '<div class="btp-qr" data-btp-qr="' . self::e((string) ($qr['scheme'] ?? '')) . '">'
                . '<div class="btp-qr__code">' . QrCode::svg($payload, $label) . '</div>'
                . '<div class="btp-qr__side"><div class="btp-qr__caption">' . self::e((string) ($qr['caption'] ?? '')) . '</div>';

            $deeplink = (string) ($qr['deeplink'] ?? '');
            if ($deeplink !== '') {
                $html .= '<a class="btn btn-default btn-sm btp-qr__open" href="' . self::e($deeplink) . '">Open in UPI app</a>';
            }

            $html .= '</div></div>';
        }

        return $html;
    }

    private static function fieldRow(string $label, string $value): string
    {
        return '<div class="btp-bank-details__row">'
            . '<span class="btp-bank-details__label">' . self::e($label) . ':</span> '
            . '<span class="btp-bank-details__value">' . self::e($value) . '</span>'
            . self::copyButton($value, $label)
            . '</div>';
    }

    private static function copyButton(string $value, string $label): string
    {
        return ' <button type="button" class="btn btn-default btn-xs btp-copy" data-btp-copy="' . self::e($value)
            . '" aria-label="Copy ' . self::e($label) . '">Copy</button>';
    }

    /**
     * @param array<string, mixed> $packSet
     */
    private static function invoiceReference(array $packSet, string $label): string
    {
        $number = trim((string) ($packSet['invoice_number'] ?? ''));
        if ($number === '') {
            return '';
        }

        return '<div class="btp-bank-details__reference"><strong>' . self::e($label) . ':</strong> ' . self::e($number) . '</div>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
