<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Capability-driven validation for the Receive Profile wizard.
 * Pure (no DB, no HTTP) so it can be unit-tested; WHMCS currency existence is checked by the caller.
 */
final class ReceiveProfileValidator
{
    public const PAYEE_WARNING = 'Legal beneficiary / account name is still empty. Clients will not see the account holder name; add it when you can.';

    /**
     * @param array<string, mixed> $input raw request input
     * @param array{legacy_missing_payee?: bool} $context legacy_missing_payee: the stored row being edited
     *        never had an account name (pre-wizard data); local/instant stay saveable with a warning
     *        instead of blocking every edit. Wire still requires a beneficiary name.
     * @return array{errors: list<string>, warnings: list<string>, profile: array<string, mixed>}
     */
    public static function validate(array $input, array $context = []): array
    {
        $errors = [];
        $warnings = [];
        $legacyMissingPayee = ! empty($context['legacy_missing_payee']);

        $bankName = trim((string) ($input['bank_name'] ?? ''));
        $branchName = trim((string) ($input['branch_name'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency_code'] ?? '')));
        $accountDetails = trim((string) ($input['account_details'] ?? ''));
        $accountName = trim((string) ($input['account_name'] ?? ''));
        $accountNumber = trim((string) ($input['account_number'] ?? ''));
        $invoiceLabel = trim((string) ($input['invoice_label'] ?? ''));
        $beneficiaryAddress = trim((string) ($input['beneficiary_address'] ?? ''));
        $bankAddress = trim((string) ($input['bank_address'] ?? ''));
        $purposeHint = trim((string) ($input['wire_purpose_hint'] ?? ''));
        $intermediary = SchemeRegistry::normalize('swift_bic', (string) ($input['intermediary_bic'] ?? ''));
        $chargeCode = strtoupper(trim((string) ($input['prefer_charge_code'] ?? '')));
        $acceptFx = filter_var($input['accept_fx_receive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $identifiers = self::collectIdentifiers($input, $errors);
        $capabilities = self::collectCapabilities($input['capabilities'] ?? null);

        // Legacy request shape (no wizard fields): infer rather than reject.
        $legacyUpi = trim((string) ($input['upi_id'] ?? ''));
        $legacyIfsc = trim((string) ($input['ifsc_code'] ?? ''));
        if ($legacyUpi !== '' && ! isset($identifiers['upi'])) {
            $identifiers['upi'] = SchemeRegistry::normalize('upi', $legacyUpi);
        }
        if ($legacyIfsc !== '' && ! isset($identifiers['ifsc'])) {
            $identifiers['ifsc'] = SchemeRegistry::normalize('ifsc', $legacyIfsc);
        }

        $country = PayerContext::normalizeCountry($input['country_code'] ?? null);
        if ($country === null && (isset($identifiers['ifsc']) || isset($identifiers['upi']))) {
            $country = 'IN';
        }

        if ($capabilities === []) {
            $capabilities = BankProfile::inferCapabilities($identifiers, $accountNumber);
        }

        if ($bankName === '') {
            $errors[] = 'Bank name is required.';
        }

        if (strlen($currency) !== 3 || ! ctype_alpha($currency)) {
            $errors[] = 'A valid 3-letter currency code is required.';
        }

        if ($capabilities === []) {
            if ($accountDetails === '') {
                $errors[] = 'Choose at least one way clients can pay (local transfer, instant payment, or international wire).';
            }
        } elseif ($country === null) {
            $errors[] = 'Select the country where this bank account is held.';
        }

        $hasAccount = $accountNumber !== '' || isset($identifiers['iban']);

        if (in_array(SchemeRegistry::CAP_LOCAL, $capabilities, true)) {
            if ($accountName === '') {
                if ($legacyMissingPayee) {
                    $warnings[] = self::PAYEE_WARNING;
                } else {
                    $errors[] = 'Account name is required for local transfers.';
                }
            }
            if (! $hasAccount) {
                $errors[] = 'Account number (or IBAN) is required for local transfers.';
            }
            if ($country !== null) {
                foreach (SchemeRegistry::forCapability(SchemeRegistry::CAP_LOCAL, $country) as $scheme) {
                    if ($scheme['id'] === 'iban') {
                        continue;
                    }
                    if (! isset($identifiers[$scheme['id']])) {
                        $errors[] = $scheme['label'] . ' is required for local transfers in ' . CountryList::name($country) . '.';
                    }
                }
            }
        }

        if (in_array(SchemeRegistry::CAP_INSTANT, $capabilities, true) && $country !== null) {
            $hasAlias = false;
            foreach (SchemeRegistry::forCapability(SchemeRegistry::CAP_INSTANT, $country) as $scheme) {
                if (isset($identifiers[$scheme['id']])) {
                    $hasAlias = true;
                }
            }
            if (! $hasAlias) {
                $errors[] = SchemeRegistry::forCapability(SchemeRegistry::CAP_INSTANT, $country) === []
                    ? 'Instant payments are not supported for ' . CountryList::name($country) . ' yet. Use local transfer or international wire.'
                    : 'Enter an instant-payment ID (for example a UPI ID) or turn off instant payments.';
            }
            if ($accountName === '') {
                if ($legacyMissingPayee) {
                    $warnings[] = self::PAYEE_WARNING;
                } else {
                    $errors[] = 'Account name is required for instant payments.';
                }
            }
        }

        if (in_array(SchemeRegistry::CAP_WIRE, $capabilities, true)) {
            if (! isset($identifiers['swift_bic'])) {
                $errors[] = 'SWIFT / BIC is required for international wires.';
            }
            if ($accountName === '') {
                $errors[] = 'Beneficiary (account) name is required for international wires.';
            }
            if (! $hasAccount) {
                $errors[] = 'Account number (or IBAN) is required for international wires.';
            }
            if ($beneficiaryAddress === '') {
                $errors[] = 'Beneficiary address is required for international wires.';
            }
        }

        if ($intermediary !== '' && ! SchemeRegistry::isValid('swift_bic', $intermediary)) {
            $errors[] = 'Intermediary BIC looks invalid.';
        }

        if ($chargeCode === '') {
            $chargeCode = BankProfile::DEFAULT_CHARGE_CODE;
        }
        if (! in_array($chargeCode, ['OUR', 'SHA', 'BEN'], true)) {
            $errors[] = 'Charge code must be OUR, SHA or BEN.';
        }
        if (strlen($purposeHint) > 255) {
            $errors[] = 'Purpose hint must be 255 characters or fewer.';
        }
        if (strlen($beneficiaryAddress) > 1000 || strlen($bankAddress) > 1000) {
            $errors[] = 'Addresses must be 1000 characters or fewer.';
        }

        $packNotes = self::decodeJson($input['pack_notes'] ?? null);
        $packNotes['prefer_instant'] = filter_var($input['prefer_instant'] ?? ($packNotes['prefer_instant'] ?? false), FILTER_VALIDATE_BOOLEAN);

        return [
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'profile' => [
                'bank_name' => $bankName,
                'branch_name' => $branchName,
                'currency_code' => $currency,
                'country_code' => $country ?? '',
                'account_details' => $accountDetails,
                'invoice_label' => $invoiceLabel,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'capabilities' => $capabilities,
                'identifiers' => $identifiers,
                'beneficiary_address' => $beneficiaryAddress,
                'bank_address' => $bankAddress,
                'intermediary_bic' => $intermediary,
                'prefer_charge_code' => $chargeCode,
                'accept_fx_receive' => $acceptFx,
                'wire_purpose_hint' => $purposeHint,
                'pack_notes' => $packNotes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     * @return array<string, string>
     */
    private static function collectIdentifiers(array $input, array &$errors): array
    {
        $raw = self::decodeJson($input['identifiers'] ?? null);
        $identifiers = [];

        foreach ($raw as $scheme => $value) {
            if (! is_string($scheme) || ! is_scalar($value)) {
                continue;
            }
            $definition = SchemeRegistry::get($scheme);
            $trimmed = trim((string) $value);
            if ($definition === null || $trimmed === '') {
                continue;
            }
            if (! SchemeRegistry::isValid($scheme, $trimmed)) {
                $errors[] = $definition['label'] . ' looks invalid.';
                continue;
            }
            $identifiers[$scheme] = SchemeRegistry::normalize($scheme, $trimmed);
        }

        return $identifiers;
    }

    /**
     * @return list<string>
     */
    private static function collectCapabilities(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            $decoded = $trimmed !== '' && $trimmed[0] === '[' ? json_decode($trimmed, true) : null;
            $value = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
        }
        if (! is_array($value)) {
            return [];
        }

        $normalized = array_map(static fn ($item): string => is_string($item) ? trim($item) : '', $value);

        return array_values(array_intersect(SchemeRegistry::capabilities(), $normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
