<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

final class DuplicateGuard
{
    public static function normalize(string $bankName, string $branchName, string $currencyCode): string
    {
        $bank = mb_strtolower(trim($bankName));
        $branch = mb_strtolower(trim($branchName));
        $currency = strtoupper(trim($currencyCode));

        return hash('sha256', $bank . '|' . $branch . '|' . $currency);
    }

    public static function buildDuplicateKey(string $bankName, string $branchName, string $currencyCode): string
    {
        return self::normalize($bankName, $branchName, $currencyCode);
    }
}
