<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Support\InvoiceCurrencyResolver;
use PHPUnit\Framework\TestCase;

final class InvoiceCurrencyResolverTest extends TestCase
{
    public function testGatewayParamsPreferThreeLetterCurrencyCode(): void
    {
        $resolver = new InvoiceCurrencyResolver();

        $this->assertSame('USD', $resolver->codeForGatewayLink([
            'currency' => 'usd',
            'invoiceid' => 300003464,
        ]));
    }

    public function testGatewayParamsRejectCurrencyIdsAndShortCodes(): void
    {
        $resolver = new InvoiceCurrencyResolver();

        $this->assertNull($resolver->codeForGatewayLink([
            'currency' => 1,
            'invoiceid' => 0,
        ]));
        $this->assertNull($resolver->codeForGatewayLink([
            'currency' => 'US',
            'invoiceid' => 0,
        ]));
        $this->assertNull($resolver->codeForGatewayLink([
            'currency' => '',
            'invoiceid' => 0,
        ]));
    }

    public function testClientCurrencyFallbackResolvesWithoutLiveCapsule(): void
    {
        $resolver = new class extends InvoiceCurrencyResolver {
            protected function loadInvoiceUserId(int $invoiceId): ?int
            {
                TestCase::assertSame(300003464, $invoiceId);

                return 99;
            }

            protected function loadClientCurrencyId(int $userId): ?int
            {
                TestCase::assertSame(99, $userId);

                return 2;
            }

            protected function loadCurrencyCode(int $currencyId): ?string
            {
                TestCase::assertSame(2, $currencyId);

                return 'EUR';
            }
        };

        $this->assertSame('EUR', $resolver->codeForGatewayLink([
            'invoiceid' => 300003464,
        ]));
    }

    public function testInvoiceRecordUsesUseridNotInvoiceCurrency(): void
    {
        $resolver = new class extends InvoiceCurrencyResolver {
            protected function loadClientCurrencyId(int $userId): ?int
            {
                TestCase::assertSame(42, $userId);

                return 7;
            }

            protected function loadCurrencyCode(int $currencyId): ?string
            {
                TestCase::assertSame(7, $currencyId);

                return 'INR';
            }
        };

        $invoice = (object) [
            'id' => 300003464,
            'userid' => 42,
        ];

        $this->assertSame('INR', $resolver->codeFromInvoiceRecord($invoice));
    }

    public function testHookVarsAcceptCurrencyIdThenInvoiceFallback(): void
    {
        $resolver = new class extends InvoiceCurrencyResolver {
            protected function loadCurrencyCode(int $currencyId): ?string
            {
                return $currencyId === 3 ? 'GBP' : null;
            }

            protected function loadInvoiceUserId(int $invoiceId): ?int
            {
                return null;
            }
        };

        $this->assertSame('GBP', $resolver->codeFromHookVars([
            'currency' => 3,
            'invoiceid' => 11,
        ]));
        $this->assertSame('USD', $resolver->codeFromHookVars([
            'currency' => 'usd',
        ]));
    }
}
