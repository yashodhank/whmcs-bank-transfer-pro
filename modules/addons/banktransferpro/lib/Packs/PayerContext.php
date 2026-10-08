<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Who is paying: the WHMCS client country, plus a device hint.
 * Clients are never asked to know payment-rail names; geography decides the pack.
 */
final class PayerContext
{
    public function __construct(
        public readonly ?string $countryCode = null,
        public readonly bool $mobile = false
    ) {
    }

    public static function fromCountry(mixed $country, bool $mobile = false): self
    {
        return new self(self::normalizeCountry($country), $mobile);
    }

    /**
     * @param array<string, mixed> $details WHMCS clientdetails / clientsdetails
     */
    public static function fromClientDetails(array $details, ?string $userAgent = null): self
    {
        $country = self::normalizeCountry($details['country'] ?? null)
            ?? self::normalizeCountry($details['countrycode'] ?? null);

        return new self($country, self::isMobileUserAgent($userAgent));
    }

    public static function normalizeCountry(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    public static function isMobileUserAgent(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return false;
        }

        return preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $userAgent) === 1;
    }

    /**
     * Unknown payer or bank country is treated as domestic (legacy single-country behaviour).
     */
    public function isDomesticTo(string $bankCountry): bool
    {
        if ($this->countryCode === null || $bankCountry === '') {
            return true;
        }

        return $this->countryCode === strtoupper($bankCountry);
    }
}
