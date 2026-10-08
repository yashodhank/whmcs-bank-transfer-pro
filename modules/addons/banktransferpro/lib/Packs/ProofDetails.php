<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Validates and shapes the pack-aware fields captured with a payment proof, and
 * renders the ticket lines so the admin sees the exact pack the client used.
 */
final class ProofDetails
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $packSet result of InstructionPackEngine::build()
     * @return array{payment_reference: string, pack_id: string, rail_reference: string, declared_amount: ?string, declared_currency: string}
     */
    public static function fromRequest(array $post, array $packSet, int $invoiceId, ?string $invoiceCurrency, bool $acceptFx): array
    {
        $postedReference = trim((string) ($post['payment_reference'] ?? ''));
        if ($postedReference === '') {
            throw new \InvalidArgumentException('The payment reference is required. Reload the invoice and try again.');
        }
        if (! PaymentReference::validate($postedReference, $invoiceId)) {
            throw new \InvalidArgumentException('The payment reference does not match this invoice. Reload the page and try again.');
        }
        $reference = (string) PaymentReference::normalize($postedReference);

        $packs = is_array($packSet['packs'] ?? null) ? $packSet['packs'] : [];
        $packId = trim((string) ($post['pack_id'] ?? ''));
        if ($packId === '' && isset($packSet['recommended']) && is_string($packSet['recommended'])) {
            $packId = $packSet['recommended'];
        }
        if ($packId !== '' && $packs !== [] && ! isset($packs[$packId])) {
            throw new \InvalidArgumentException('Choose which payment method you used from the options shown.');
        }
        if ($packs === []) {
            $packId = '';
        }

        $railReference = trim((string) ($post['rail_reference'] ?? ''));
        if ($railReference !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-_\/.]{0,63}$/', $railReference) !== 1) {
            throw new \InvalidArgumentException('The bank reference (UTR / UETR / RRN) may only contain letters, numbers and - _ / . (max 64 characters).');
        }

        $declaredAmount = null;
        $amountRaw = trim((string) ($post['declared_amount'] ?? ''));
        if ($amountRaw !== '') {
            $clean = str_replace([',', ' '], '', $amountRaw);
            if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $clean) !== 1 || (float) $clean <= 0) {
                throw new \InvalidArgumentException('Enter the amount you sent as a positive number, for example 1500.00.');
            }
            $declaredAmount = number_format((float) $clean, 2, '.', '');
        }

        $declaredCurrency = strtoupper(trim((string) ($post['declared_currency'] ?? '')));
        if ($declaredCurrency !== '' && preg_match('/^[A-Z]{3}$/', $declaredCurrency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a 3-letter code such as USD.');
        }
        if ($declaredCurrency === '' && $declaredAmount !== null && $invoiceCurrency !== null) {
            $declaredCurrency = $invoiceCurrency;
        }
        if (
            $declaredCurrency !== ''
            && $invoiceCurrency !== null
            && $declaredCurrency !== $invoiceCurrency
            && ! $acceptFx
        ) {
            throw new \InvalidArgumentException(
                'This invoice is in ' . $invoiceCurrency . '. Please convert and send in ' . $invoiceCurrency
                . ', or open a support ticket before paying in ' . $declaredCurrency . '.'
            );
        }

        return [
            'payment_reference' => $reference,
            'pack_id' => $packId,
            'rail_reference' => $railReference,
            'declared_amount' => $declaredAmount,
            'declared_currency' => $declaredCurrency,
        ];
    }

    /**
     * @param array{payment_reference: string, pack_id: string, rail_reference: string, declared_amount: ?string, declared_currency: string} $details
     * @param array<string, mixed> $packSet
     * @return list<string>
     */
    public static function ticketLines(array $details, array $packSet): array
    {
        $lines = ['Payment reference: ' . $details['payment_reference']];

        $pack = $details['pack_id'] !== '' ? ($packSet['packs'][$details['pack_id']] ?? null) : null;
        if (is_array($pack)) {
            $lines[] = 'Payment method used: ' . ($pack['title'] ?? $details['pack_id']) . ' (' . $details['pack_id'] . ')';
        }
        if ($details['rail_reference'] !== '') {
            $lines[] = 'Bank reference (UTR / UETR / RRN): ' . $details['rail_reference'];
        }
        if ($details['declared_amount'] !== null) {
            $lines[] = 'Client says they sent: ' . $details['declared_amount'] . ' ' . $details['declared_currency'];
        }
        if (! empty($packSet['amount'])) {
            $lines[] = 'Invoice amount due: ' . $packSet['amount'] . ' ' . ($packSet['currency'] ?? '');
        }

        if (is_array($pack)) {
            $lines[] = '';
            $lines[] = 'Instructions shown to the client (' . ($pack['title'] ?? '') . '):';
            foreach (InstructionPackEngine::packToLines($pack) as $line) {
                $lines[] = '  ' . $line;
            }
        }

        return $lines;
    }
}
