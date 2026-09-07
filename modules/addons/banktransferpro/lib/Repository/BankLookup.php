<?php

declare(strict_types=1);

namespace BankTransferPro\Repository;

interface BankLookup
{
    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCurrencyCode(string $currencyCode): ?array;
}
