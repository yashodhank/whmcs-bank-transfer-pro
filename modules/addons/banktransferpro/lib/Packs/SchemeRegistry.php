<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Scheme metadata for instruction packs.
 *
 * A scheme is one keyed identifier (ifsc, upi, swift_bic, iban, ...). Schemes are
 * data, not columns: a bank stores them as JSON and the registry declares how each
 * one validates, which capability it serves and where it applies. Growing global
 * coverage means adding a registry entry, not a migration.
 *
 * Country inventory (rail chips) lives here as data and is intentionally never a
 * selectable control in the client UI.
 */
final class SchemeRegistry
{
    public const CAP_LOCAL = 'local_transfer';
    public const CAP_INSTANT = 'instant_alias';
    public const CAP_WIRE = 'international_wire';

    /** Countries that issue IBANs (used to decide when to offer an IBAN field). */
    private const IBAN_COUNTRIES = [
        'AD', 'AE', 'AL', 'AT', 'AZ', 'BA', 'BE', 'BG', 'BH', 'BR', 'BY', 'CH', 'CR', 'CY', 'CZ', 'DE', 'DK', 'DO',
        'EE', 'EG', 'ES', 'FI', 'FO', 'FR', 'GB', 'GE', 'GI', 'GL', 'GR', 'GT', 'HR', 'HU', 'IE', 'IL', 'IQ', 'IS',
        'IT', 'JO', 'KW', 'KZ', 'LB', 'LC', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MR', 'MT', 'MU', 'NL',
        'NO', 'PK', 'PL', 'PS', 'PT', 'QA', 'RO', 'RS', 'SA', 'SC', 'SE', 'SI', 'SK', 'SM', 'ST', 'TL', 'TN', 'TR',
        'UA', 'VA', 'VG', 'XK',
    ];

    /** Educational "works with" chips for the local pack only. */
    private const RAIL_CHIPS = [
        'IN' => ['NEFT', 'IMPS', 'RTGS'],
        'US' => ['ACH', 'Wire'],
        'GB' => ['Faster Payments', 'BACS', 'CHAPS'],
        'AU' => ['NPP / OSKO'],
        'SG' => ['FAST'],
        'CA' => ['EFT'],
        'HK' => ['FPS', 'CHATS'],
        'MX' => ['SPEI'],
        'BR' => ['TED'],
    ];

    private const SEPA_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'CH', 'MC', 'SM',
    ];

    /**
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return [self::CAP_LOCAL, self::CAP_INSTANT, self::CAP_WIRE];
    }

    /**
     * @return array<string, array{id: string, label: string, capabilities: list<string>, countries: list<string>, priority: int, placeholder: string, help: string}>
     */
    public static function all(): array
    {
        return [
            'ifsc' => self::scheme('ifsc', 'IFSC', [self::CAP_LOCAL], ['IN'], 10, 'IBKL0000500', 'Indian Financial System Code (11 characters).'),
            'aba' => self::scheme('aba', 'ABA routing number', [self::CAP_LOCAL], ['US'], 10, '021000021', '9-digit ABA / routing transit number.'),
            'sort_code' => self::scheme('sort_code', 'Sort code', [self::CAP_LOCAL], ['GB'], 10, '20-00-00', '6-digit UK sort code.'),
            'bsb' => self::scheme('bsb', 'BSB', [self::CAP_LOCAL], ['AU'], 10, '062-000', '6-digit Australian BSB.'),
            'clabe' => self::scheme('clabe', 'CLABE', [self::CAP_LOCAL], ['MX'], 10, '', '18-digit Mexican CLABE.'),
            'iban' => self::scheme('iban', 'IBAN', [self::CAP_LOCAL, self::CAP_WIRE], self::IBAN_COUNTRIES, 20, 'GB82WEST12345698765432', 'International Bank Account Number.'),
            'swift_bic' => self::scheme('swift_bic', 'SWIFT / BIC', [self::CAP_WIRE], [], 10, 'IBKLINBBXXX', '8 or 11 character BIC. For India this is usually the authorised-dealer bank BIC.'),
            'upi' => self::scheme('upi', 'UPI ID', [self::CAP_INSTANT], ['IN'], 10, 'name@bank', 'UPI virtual payment address.'),
            'payid' => self::scheme('payid', 'PayID', [self::CAP_INSTANT], ['AU'], 10, 'billing@example.com.au', 'Email address or mobile number registered as a PayID.'),
            'paynow' => self::scheme('paynow', 'PayNow', [self::CAP_INSTANT], ['SG'], 10, '201912345A', 'UEN or mobile number registered for PayNow.'),
            'interac' => self::scheme('interac', 'Interac e-Transfer email', [self::CAP_INSTANT], ['CA'], 10, 'billing@example.ca', 'Auto-deposit email for Interac e-Transfer.'),
            'pix' => self::scheme('pix', 'Pix key', [self::CAP_INSTANT], ['BR'], 10, '', 'Pix key (CPF/CNPJ, email, phone or random key).'),
            'fps' => self::scheme('fps', 'FPS ID', [self::CAP_INSTANT], ['HK'], 10, '', 'Hong Kong Faster Payment System identifier.'),
        ];
    }

    /**
     * @return array{id: string, label: string, capabilities: list<string>, countries: list<string>, priority: int, placeholder: string, help: string}|null
     */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @return list<array{id: string, label: string, capabilities: list<string>, countries: list<string>, priority: int, placeholder: string, help: string}>
     */
    public static function forCapability(string $capability, ?string $country): array
    {
        $country = $country !== null ? strtoupper($country) : null;
        $schemes = [];
        foreach (self::all() as $scheme) {
            if (! in_array($capability, $scheme['capabilities'], true)) {
                continue;
            }
            if ($scheme['countries'] !== [] && ($country === null || ! in_array($country, $scheme['countries'], true))) {
                continue;
            }
            $schemes[] = $scheme;
        }

        usort($schemes, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $schemes;
    }

    public static function normalize(string $id, string $value): string
    {
        $value = trim($value);

        return match ($id) {
            'ifsc', 'swift_bic' => strtoupper(preg_replace('/\s+/', '', $value) ?? ''),
            'iban' => strtoupper(preg_replace('/\s+/', '', $value) ?? ''),
            'aba', 'clabe' => preg_replace('/[\s-]+/', '', $value) ?? '',
            'sort_code' => self::dashDigits($value, [2, 2, 2]),
            'bsb' => self::dashDigits($value, [3, 3]),
            'upi', 'interac' => strtolower($value),
            default => $value,
        };
    }

    public static function isValid(string $id, string $value): bool
    {
        $normalized = self::normalize($id, $value);
        if ($normalized === '') {
            return false;
        }

        return match ($id) {
            'ifsc' => preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $normalized) === 1,
            'swift_bic' => preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $normalized) === 1,
            'iban' => self::isValidIban($normalized),
            'aba' => preg_match('/^\d{9}$/', $normalized) === 1,
            'sort_code' => preg_match('/^\d{2}-\d{2}-\d{2}$/', $normalized) === 1,
            'bsb' => preg_match('/^\d{3}-\d{3}$/', $normalized) === 1,
            'clabe' => preg_match('/^\d{18}$/', $normalized) === 1,
            'upi' => preg_match('/^[a-z0-9._\-]{2,256}@[a-z][a-z0-9]{1,63}$/i', $normalized) === 1,
            'payid' => preg_match('/^(\S+@\S+\.\S+|\+?\d{8,15})$/', $normalized) === 1,
            'interac' => preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $normalized) === 1,
            'paynow' => preg_match('/^\S{4,64}$/', $normalized) === 1,
            'pix' => preg_match('/^\S{5,77}$/', $normalized) === 1,
            'fps' => preg_match('/^\S{3,64}$/', $normalized) === 1,
            default => false,
        };
    }

    public static function countryUsesIban(?string $country): bool
    {
        return $country !== null && in_array(strtoupper($country), self::IBAN_COUNTRIES, true);
    }

    /**
     * Educational rail chips for the local pack. Never a selectable control.
     *
     * @return list<string>
     */
    public static function chipsForCountry(?string $country): array
    {
        if ($country === null) {
            return [];
        }

        $country = strtoupper($country);
        if (isset(self::RAIL_CHIPS[$country])) {
            return self::RAIL_CHIPS[$country];
        }

        if (in_array($country, self::SEPA_COUNTRIES, true)) {
            return ['SEPA Credit Transfer', 'SEPA Instant'];
        }

        return [];
    }

    /**
     * Registry export for the admin wizard (field metadata only, no rules logic).
     *
     * @return array{schemes: list<array<string, mixed>>, ibanCountries: list<string>, capabilities: list<string>}
     */
    public static function toClientConfig(): array
    {
        return [
            'schemes' => array_values(self::all()),
            'ibanCountries' => self::IBAN_COUNTRIES,
            'capabilities' => self::capabilities(),
        ];
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $countries
     * @return array{id: string, label: string, capabilities: list<string>, countries: list<string>, priority: int, placeholder: string, help: string}
     */
    private static function scheme(string $id, string $label, array $capabilities, array $countries, int $priority, string $placeholder, string $help): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'capabilities' => $capabilities,
            'countries' => $countries,
            'priority' => $priority,
            'placeholder' => $placeholder,
            'help' => $help,
        ];
    }

    /**
     * @param list<int> $groups
     */
    private static function dashDigits(string $value, array $groups): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== array_sum($groups)) {
            return trim($value);
        }

        $parts = [];
        $offset = 0;
        foreach ($groups as $length) {
            $parts[] = substr($digits, $offset, $length);
            $offset += $length;
        }

        return implode('-', $parts);
    }

    private static function isValidIban(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }
}
