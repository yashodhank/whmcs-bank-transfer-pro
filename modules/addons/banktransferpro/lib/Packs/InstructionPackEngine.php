<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

use BankTransferPro\Packs\Qr\AliasQrPayload;

/**
 * Builds copy-optimised Instruction Packs for one bank + payer + invoice and picks
 * ONE recommended pack. Everything else is an alternative behind "Paying another way?".
 *
 * Invariants (covered by tests):
 *  - the wire pack never carries an instant alias (UPI/PayNow/...)
 *  - the local pack surfaces clearing systems (NEFT/IMPS/RTGS...) only as chips
 *  - foreign payers are only offered the wire pack
 *  - every pack shares the same payment reference
 */
final class InstructionPackEngine
{
    public const PACK_LOCAL = 'local';
    public const PACK_INSTANT = 'instant';
    public const PACK_WIRE = 'wire';

    /**
     * @param array<string, mixed> $bank
     * @param array{id?: int|string, number?: string, amount?: ?string, currency?: ?string} $invoice
     * @return array{
     *   reference: string,
     *   invoice_number: string,
     *   amount: ?string,
     *   currency: ?string,
     *   bank_country: string,
     *   recommended: ?string,
     *   packs: array<string, array<string, mixed>>,
     *   alternatives: list<string>,
     *   legacy_only: bool,
     *   legacy_notes: string,
     *   error: ?string
     * }
     */
    public static function build(array $bank, PayerContext $payer, array $invoice): array
    {
        $invoiceId = (int) ($invoice['id'] ?? 0);
        $reference = $invoiceId > 0 ? self::safeMint($invoiceId) : '';
        $invoiceCurrency = self::currencyOrNull($invoice['currency'] ?? null);
        $bankCurrency = self::currencyOrNull($bank['currency_code'] ?? null);
        $amount = isset($invoice['amount']) && $invoice['amount'] !== '' ? (string) $invoice['amount'] : null;

        $result = [
            'reference' => $reference,
            'invoice_number' => (string) ($invoice['number'] ?? ($invoiceId > 0 ? (string) $invoiceId : '')),
            'amount' => $amount,
            'currency' => $invoiceCurrency ?? $bankCurrency,
            'bank_country' => BankProfile::countryCode($bank),
            'recommended' => null,
            'packs' => [],
            'alternatives' => [],
            'legacy_only' => false,
            'legacy_notes' => trim((string) ($bank['account_details'] ?? '')),
            'error' => null,
        ];

        $country = $result['bank_country'];
        $identifiers = BankProfile::identifiers($bank);
        $capabilities = BankProfile::capabilities($bank);
        $fxAccepted = BankProfile::acceptsFx($bank);
        $fxMismatch = $invoiceCurrency !== null && $bankCurrency !== null && $invoiceCurrency !== $bankCurrency;

        $ready = [];
        if (in_array(SchemeRegistry::CAP_LOCAL, $capabilities, true)) {
            $pack = self::localPack($bank, $country, $identifiers);
            if ($pack !== null) {
                $ready[self::PACK_LOCAL] = $pack;
            }
        }
        if (in_array(SchemeRegistry::CAP_INSTANT, $capabilities, true)) {
            $pack = self::instantPack($bank, $country, $identifiers, $reference, $amount, $invoiceCurrency ?? $bankCurrency);
            if ($pack !== null) {
                $ready[self::PACK_INSTANT] = $pack;
            }
        }
        if (in_array(SchemeRegistry::CAP_WIRE, $capabilities, true)) {
            $pack = self::wirePack($bank, $country, $identifiers, $reference);
            if ($pack !== null) {
                $ready[self::PACK_WIRE] = $pack;
            }
        }

        if ($ready === []) {
            $result['legacy_only'] = true;

            return $result;
        }

        if ($fxMismatch && ! $fxAccepted) {
            $result['error'] = sprintf(
                'This bank account receives %s but your invoice is in %s. Please open a support ticket so we can arrange payment in the right currency.',
                $bankCurrency,
                $invoiceCurrency
            );

            return $result;
        }

        $domestic = $payer->isDomesticTo($country);
        $eligible = $domestic ? $ready : array_intersect_key($ready, [self::PACK_WIRE => true]);

        if ($eligible === []) {
            $result['error'] = 'Bank transfer to this account is not available from your location. Please open a support ticket and we will help you pay this invoice.';

            return $result;
        }

        $recommended = self::recommend($eligible, $payer, $bank);

        if ($fxMismatch) {
            foreach ($eligible as $id => $pack) {
                $eligible[$id]['warnings'][] = sprintf(
                    'This account receives %s while the invoice is in %s. Convert to the invoice currency first, or contact support.',
                    $bankCurrency,
                    $invoiceCurrency
                );
            }
        }

        $ordered = [$recommended => $eligible[$recommended]];
        foreach ([self::PACK_LOCAL, self::PACK_INSTANT, self::PACK_WIRE] as $id) {
            if ($id !== $recommended && isset($eligible[$id])) {
                $ordered[$id] = $eligible[$id];
            }
        }
        $ordered[$recommended]['recommended'] = true;

        $result['packs'] = $ordered;
        $result['recommended'] = $recommended;
        $result['alternatives'] = array_values(array_diff(array_keys($ordered), [$recommended]));

        if (isset($ordered[self::PACK_LOCAL])) {
            $notes = self::dedupedLegacyNotes($result['legacy_notes'], $bank, $identifiers);
            if ($notes !== '') {
                $result['packs'][self::PACK_LOCAL]['notes'][] = $notes;
            }
        }

        return $result;
    }

    /**
     * Plain-text rendering of a pack for support-ticket bodies.
     *
     * @param array<string, mixed> $pack
     * @return list<string>
     */
    public static function packToLines(array $pack): array
    {
        $lines = [];
        foreach ($pack['fields'] ?? [] as $field) {
            $lines[] = ($field['label'] ?? '') . ': ' . ($field['value'] ?? '');
        }

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $eligible
     * @param array<string, mixed> $bank
     */
    private static function recommend(array $eligible, PayerContext $payer, array $bank): string
    {
        $country = BankProfile::countryCode($bank);
        $domestic = $payer->isDomesticTo($country);

        if ($domestic) {
            if (isset($eligible[self::PACK_INSTANT]) && ($payer->mobile || BankProfile::prefersInstant($bank))) {
                return self::PACK_INSTANT;
            }
            foreach ([self::PACK_LOCAL, self::PACK_INSTANT, self::PACK_WIRE] as $id) {
                if (isset($eligible[$id])) {
                    return $id;
                }
            }
        }

        return self::PACK_WIRE;
    }

    /**
     * @param array<string, mixed> $bank
     * @param array<string, string> $identifiers
     * @return array<string, mixed>|null
     */
    private static function localPack(array $bank, string $country, array $identifiers): ?array
    {
        $accountNumber = trim((string) ($bank['account_number'] ?? ''));
        if ($accountNumber === '' && ! isset($identifiers['iban'])) {
            return null;
        }

        $fields = [];
        self::addField($fields, 'account_name', 'Account Name', (string) ($bank['account_name'] ?? ''));
        self::addField($fields, 'account_number', 'Account Number', $accountNumber);
        self::addField($fields, 'iban', 'IBAN', $identifiers['iban'] ?? '');

        foreach (SchemeRegistry::forCapability(SchemeRegistry::CAP_LOCAL, $country) as $scheme) {
            if ($scheme['id'] === 'iban') {
                continue;
            }
            self::addField($fields, $scheme['id'], $scheme['label'], $identifiers[$scheme['id']] ?? '');
        }

        self::addField($fields, 'bank_name', 'Bank Name', (string) ($bank['bank_name'] ?? ''));
        self::addField($fields, 'branch_name', 'Bank Branch', (string) ($bank['branch_name'] ?? ''));

        $notes = [];
        $extra = self::noteFor($bank, 'local');
        if ($extra !== '') {
            $notes[] = $extra;
        }

        return self::pack(self::PACK_LOCAL, 'Local bank transfer', 'Domestic transfer from your own bank', $fields, SchemeRegistry::chipsForCountry($country), $notes);
    }

    /**
     * @param array<string, mixed> $bank
     * @param array<string, string> $identifiers
     * @return array<string, mixed>|null
     */
    private static function instantPack(array $bank, string $country, array $identifiers, string $reference, ?string $amount, ?string $currency): ?array
    {
        $fields = [];
        $labels = [];
        foreach (SchemeRegistry::forCapability(SchemeRegistry::CAP_INSTANT, $country) as $scheme) {
            $value = $identifiers[$scheme['id']] ?? '';
            if ($value === '') {
                continue;
            }
            self::addField($fields, $scheme['id'], $scheme['label'], $value);
            $labels[] = $scheme['label'];
        }

        if ($fields === []) {
            return null;
        }

        self::addField($fields, 'account_name', 'Payee Name', (string) ($bank['account_name'] ?? ''));

        $notes = ['Check that the payee name shown in your app matches before you confirm.'];
        $extra = self::noteFor($bank, 'instant');
        if ($extra !== '') {
            $notes[] = $extra;
        }

        $pack = self::pack(self::PACK_INSTANT, 'Pay in seconds', implode(' / ', $labels), $fields, [], $notes);
        $pack['qr'] = self::qrCodes($bank, $identifiers, $reference, $amount, $currency);

        return $pack;
    }

    /**
     * Scannable QR codes for the instant aliases that have a published payload format
     * (UPI, PayNow, Pix). Instant pack only: the wire pack never carries a QR or alias.
     *
     * @param array<string, mixed> $bank
     * @param array<string, string> $identifiers
     * @return list<array{scheme: string, label: string, caption: string, payload: string, deeplink: ?string}>
     */
    private static function qrCodes(array $bank, array $identifiers, string $reference, ?string $amount, ?string $currency): array
    {
        $captions = [
            'upi' => 'Scan with any UPI app',
            'paynow' => 'Scan with the PayNow option in your banking app',
            'pix' => 'Scan with the Pix option in your banking app',
        ];

        $codes = [];
        foreach (AliasQrPayload::supportedSchemes() as $scheme) {
            if (! isset($identifiers[$scheme])) {
                continue;
            }
            $built = AliasQrPayload::build($scheme, $identifiers[$scheme], (string) ($bank['account_name'] ?? ''), $amount, $currency, $reference);
            if ($built === null) {
                continue;
            }
            $codes[] = [
                'scheme' => $scheme,
                'label' => SchemeRegistry::get($scheme)['label'] ?? strtoupper($scheme),
                'caption' => $captions[$scheme],
                'payload' => $built['payload'],
                'deeplink' => $built['deeplink'],
            ];
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $bank
     * @param array<string, string> $identifiers
     * @return array<string, mixed>|null
     */
    private static function wirePack(array $bank, string $country, array $identifiers, string $reference): ?array
    {
        $accountNumber = trim((string) ($bank['account_number'] ?? ''));
        if (! isset($identifiers['swift_bic']) || ($accountNumber === '' && ! isset($identifiers['iban']))) {
            return null;
        }

        $fields = [];
        self::addField($fields, 'beneficiary_name', 'Beneficiary Name', (string) ($bank['account_name'] ?? ''));
        self::addField($fields, 'beneficiary_address', 'Beneficiary Address', (string) ($bank['beneficiary_address'] ?? ''));
        self::addField($fields, 'account_number', 'Account Number', $accountNumber);
        self::addField($fields, 'iban', 'IBAN', $identifiers['iban'] ?? '');
        self::addField($fields, 'bank_name', 'Bank Name', (string) ($bank['bank_name'] ?? ''));
        self::addField($fields, 'bank_address', 'Bank Address', (string) ($bank['bank_address'] ?? ''));
        self::addField($fields, 'swift_bic', 'SWIFT / BIC', $identifiers['swift_bic']);
        self::addField($fields, 'intermediary_bic', 'Intermediary BIC', (string) ($bank['intermediary_bic'] ?? ''));
        self::addField($fields, 'remittance', 'Remittance Information', $reference);
        self::addField($fields, 'charges', 'Charges', BankProfile::chargeCode($bank));

        $charge = BankProfile::chargeCode($bank);
        $notes = [
            $charge === 'OUR'
                ? 'Choose charge code OUR so we receive the full invoice amount. With SHA or BEN, intermediary fees may reduce the credited amount.'
                : 'Intermediary and receiving bank fees may reduce the credited amount.',
            'Put the payment reference in the remittance information (field 70) exactly as shown.',
            'If your payment is delayed, ask your bank for the MT103 copy and UETR to trace it.',
        ];

        $purpose = trim((string) ($bank['wire_purpose_hint'] ?? ''));
        if ($purpose !== '') {
            $notes[] = 'If your bank asks for the purpose of remittance, use: ' . $purpose;
        }

        $extra = self::noteFor($bank, 'wire');
        if ($extra !== '') {
            $notes[] = $extra;
        }

        $warnings = [];
        if ($country === 'IN') {
            $warnings[] = 'Do not use UPI for international payments. Send this as a SWIFT wire from your bank.';
        }

        $pack = self::pack(self::PACK_WIRE, 'International wire', 'SWIFT transfer from a bank abroad', $fields, [], $notes);
        $pack['warnings'] = $warnings;
        $pack['timeline'] = 'Usually 1-3 business days, depending on your bank and intermediaries.';

        return $pack;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $fields
     * @param list<string> $chips
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private static function pack(string $id, string $title, string $subtitle, array $fields, array $chips, array $notes): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'fields' => $fields,
            'chips' => $chips,
            'notes' => $notes,
            'warnings' => [],
            'timeline' => null,
            'qr' => [],
            'recommended' => false,
        ];
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $fields
     */
    private static function addField(array &$fields, string $key, string $label, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $fields[] = ['key' => $key, 'label' => $label, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $bank
     */
    private static function noteFor(array $bank, string $packId): string
    {
        $note = BankProfile::packNotes($bank)[$packId] ?? '';

        return is_string($note) ? trim($note) : '';
    }

    /**
     * Legacy free-form instructions are kept for the local pack only, minus any line
     * that merely repeats a structured value (so UPI/IFSC never leak across packs).
     *
     * @param array<string, mixed> $bank
     * @param array<string, string> $identifiers
     */
    private static function dedupedLegacyNotes(string $legacy, array $bank, array $identifiers): string
    {
        if ($legacy === '') {
            return '';
        }

        $known = array_filter(array_merge(
            array_values($identifiers),
            [
                trim((string) ($bank['account_number'] ?? '')),
                trim((string) ($bank['account_name'] ?? '')),
            ]
        ), static fn (string $value): bool => $value !== '');

        $kept = [];
        foreach (preg_split('/\R/', $legacy) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach ($known as $value) {
                if (stripos($line, $value) !== false) {
                    continue 2;
                }
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private static function currencyOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }

    private static function safeMint(int $invoiceId): string
    {
        try {
            return PaymentReference::mint($invoiceId);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }
}
