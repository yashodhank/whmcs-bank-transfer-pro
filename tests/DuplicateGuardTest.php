<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Support\DuplicateGuard;
use PHPUnit\Framework\TestCase;

final class DuplicateGuardTest extends TestCase
{
    public function testSameBankBranchCurrencyProducesSameKey(): void
    {
        $first = DuplicateGuard::buildDuplicateKey(' Acme Bank ', ' Main Branch ', 'usd');
        $second = DuplicateGuard::buildDuplicateKey('acme bank', 'main branch', 'USD');

        $this->assertSame($first, $second);
    }

    public function testDifferentCurrencyProducesDifferentKey(): void
    {
        $usd = DuplicateGuard::buildDuplicateKey('Acme Bank', 'Main', 'USD');
        $eur = DuplicateGuard::buildDuplicateKey('Acme Bank', 'Main', 'EUR');

        $this->assertNotSame($usd, $eur);
    }
}
