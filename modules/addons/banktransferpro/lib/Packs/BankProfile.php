<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Read-model over a mod_btp_banks row. Prefers the new JSON columns and falls back
 * to the legacy upi_id / ifsc_code / account_* columns so un-migrated rows still work.
 */
final class BankProfile
{
    public const DEFAULT_CHARGE_CODE = 'OUR';

    public static function countryCode(array $bank): string
    {
        $stored = PayerContext::normalizeCountry($bank['country_code'] ?? null);
        if ($stored !== null) {
            return $stored;
        }

        $identifiers = self::identifiers($bank);
        if (isset($identifiers['ifsc']) || isset($identifiers['upi'])) {
            return 'IN';
        }

        return '';
    }

    /**
     * @return array<string, string> scheme id => normalised value (empty values dropped)
     */
    public static function identifiers(array $bank): array
    {
        $raw = self::decodeMap($bank['identifiers'] ?? null);

        foreach (['upi' => 'upi_id', 'ifsc' => 'ifsc_code'] as $scheme => $legacyColumn) {
            if (! isset($raw[$scheme]) || trim((string) $raw[$scheme]) === '') {
                $legacy = trim((string) ($bank[$legacyColumn] ?? ''));
                if ($legacy !== '') {
                    $raw[$scheme] = $legacy;
                }
            }
        }

        $identifiers = [];
        foreach ($raw as $scheme => $value) {
            if (! is_string($scheme) || SchemeRegistry::get($scheme) === null || ! is_scalar($value)) {
                continue;
            }
            $normalized = SchemeRegistry::normalize($scheme, (string) $value);
            if ($normalized !== '') {
                $identifiers[$scheme] = $normalized;
            }
        }

        return $identifiers;
    }

    /**
     * @return list<string>
     */
    public static function capabilities(array $bank): array
    {
        $declared = self::decodeList($bank['capabilities'] ?? null);
        $capabilities = array_values(array_intersect(SchemeRegistry::capabilities(), $declared));
        if ($capabilities !== []) {
            return $capabilities;
        }

        return self::inferCapabilities(self::identifiers($bank), trim((string) ($bank['account_number'] ?? '')));
    }

    /**
     * @param array<string, string> $identifiers
     * @return list<string>
     */
    public static function inferCapabilities(array $identifiers, string $accountNumber): array
    {
        $capabilities = [];

        $hasLocalScheme = false;
        foreach (['ifsc', 'aba', 'sort_code', 'bsb', 'clabe', 'iban'] as $scheme) {
            if (isset($identifiers[$scheme])) {
                $hasLocalScheme = true;
            }
        }
        if ($hasLocalScheme || $accountNumber !== '') {
            $capabilities[] = SchemeRegistry::CAP_LOCAL;
        }

        foreach (['upi', 'payid', 'paynow', 'interac', 'pix', 'fps'] as $scheme) {
            if (isset($identifiers[$scheme])) {
                $capabilities[] = SchemeRegistry::CAP_INSTANT;
                break;
            }
        }

        if (isset($identifiers['swift_bic']) && ($accountNumber !== '' || isset($identifiers['iban']))) {
            $capabilities[] = SchemeRegistry::CAP_WIRE;
        }

        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    public static function packNotes(array $bank): array
    {
        return self::decodeMap($bank['pack_notes'] ?? null);
    }

    public static function chargeCode(array $bank): string
    {
        $code = strtoupper(trim((string) ($bank['prefer_charge_code'] ?? '')));

        return in_array($code, ['OUR', 'SHA', 'BEN'], true) ? $code : self::DEFAULT_CHARGE_CODE;
    }

    public static function payeeName(array $bank): string
    {
        return trim((string) ($bank['account_name'] ?? ''));
    }

    /**
     * Display name carried in instant-alias QR codes (UPI `pn`). Falls back to the merchant's
     * WHMCS company name when no legal account name was stored, so the deep link still names a payee.
     * Never used for the printed Account Name / Beneficiary rows: those must be the legal name.
     */
    public static function qrPayeeName(array $bank): string
    {
        $name = self::payeeName($bank);

        return $name !== '' ? $name : trim((string) ($bank['payee_fallback'] ?? ''));
    }

    public static function acceptsFx(array $bank): bool
    {
        return filter_var($bank['accept_fx_receive'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function prefersInstant(array $bank): bool
    {
        return filter_var(self::packNotes($bank)['prefer_instant'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeMap(mixed $value): array
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

    /**
     * @return list<string>
     */
    private static function decodeList(mixed $value): array
    {
        $decoded = self::decodeMap($value);
        $list = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
