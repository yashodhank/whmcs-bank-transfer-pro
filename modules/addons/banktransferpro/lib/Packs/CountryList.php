<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Compact ISO 3166-1 alpha-2 list for the Receive Profile wizard.
 * Any valid two-letter code is accepted server-side; this list only feeds the select.
 */
final class CountryList
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'AE' => 'United Arab Emirates', 'AR' => 'Argentina', 'AT' => 'Austria', 'AU' => 'Australia',
            'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BR' => 'Brazil',
            'CA' => 'Canada', 'CH' => 'Switzerland', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
            'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'EE' => 'Estonia',
            'EG' => 'Egypt', 'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France', 'GB' => 'United Kingdom',
            'GR' => 'Greece', 'HK' => 'Hong Kong', 'HR' => 'Croatia', 'HU' => 'Hungary', 'ID' => 'Indonesia',
            'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India', 'IS' => 'Iceland', 'IT' => 'Italy',
            'JP' => 'Japan', 'KE' => 'Kenya', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'LK' => 'Sri Lanka',
            'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'MT' => 'Malta', 'MX' => 'Mexico',
            'MY' => 'Malaysia', 'NG' => 'Nigeria', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal',
            'NZ' => 'New Zealand', 'OM' => 'Oman', 'PE' => 'Peru', 'PH' => 'Philippines', 'PK' => 'Pakistan',
            'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania', 'SA' => 'Saudi Arabia',
            'SE' => 'Sweden', 'SG' => 'Singapore', 'SI' => 'Slovenia', 'SK' => 'Slovakia', 'TH' => 'Thailand',
            'TR' => 'Turkey', 'TW' => 'Taiwan', 'UA' => 'Ukraine', 'US' => 'United States', 'VN' => 'Vietnam',
            'ZA' => 'South Africa',
        ];
    }

    public static function name(?string $code): string
    {
        if ($code === null) {
            return '';
        }

        return self::names()[strtoupper($code)] ?? strtoupper($code);
    }
}
