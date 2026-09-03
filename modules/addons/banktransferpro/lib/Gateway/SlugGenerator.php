<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

final class SlugGenerator
{
    private const PREFIX = 'banktransferpro_';
    private const MAX_LENGTH = 64;

    /**
     * @param list<string> $existingSlugs
     */
    public function generate(string $bankName, string $branchName, string $currencyCode, array $existingSlugs = []): string
    {
        $parts = array_filter([
            $this->sanitizeFragment($bankName),
            $this->sanitizeFragment($branchName),
            $this->sanitizeFragment($currencyCode),
        ]);

        $base = self::PREFIX . implode('_', $parts);
        $base = $this->trimToValidLength($base);

        if ($base === self::PREFIX) {
            $base = self::PREFIX . 'bank';
        }

        $slug = $base;
        $suffix = 2;
        $existing = array_flip($existingSlugs);

        while (isset($existing[$slug]) || ! self::isValidGatewayName($slug)) {
            $suffixPart = '_' . $suffix;
            $slug = $this->trimToValidLength($base . $suffixPart);
            ++$suffix;
        }

        return $slug;
    }

    public static function isValidGatewayName(string $gateway): bool
    {
        if ($gateway === '' || ! ctype_alnum(str_replace(['_', '-'], '', $gateway))) {
            return false;
        }

        return true;
    }

    private function sanitizeFragment(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value;
    }

    private function trimToValidLength(string $slug): string
    {
        if (strlen($slug) <= self::MAX_LENGTH) {
            return $slug;
        }

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '_');
    }
}
