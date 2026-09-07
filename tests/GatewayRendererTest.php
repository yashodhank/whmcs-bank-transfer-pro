<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Gateway\GatewayRenderer;
use BankTransferPro\Repository\BankLookup;
use PHPUnit\Framework\TestCase;

final class GatewayRendererTest extends TestCase
{
    public function testLinkRenderResolvesBankFromParamsCurrencyWithoutInvoiceCurrencyColumn(): void
    {
        $banks = new RecordingBankLookup();
        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'USD',
            'invoiceid' => 300003464,
            'invoicenum' => 'INV-300003464',
        ], $banks);

        $this->assertSame(['banktransferpro'], $banks->slugLookups);
        $this->assertSame(['USD'], $banks->currencyLookups);
        $this->assertStringContainsString('Pay via', $html);
        $this->assertStringContainsString('IDBI Bank - Nanded', $html);
        $this->assertStringContainsString('UPI:', $html);
        $this->assertStringContainsString('securiace.com@idbi', $html);
        $this->assertStringContainsString('Account Number:', $html);
        $this->assertStringContainsString('500102000004909', $html);
        $this->assertStringContainsString('IFSC:', $html);
        $this->assertStringContainsString('IBKL0000500', $html);
        $this->assertStringContainsString('INV-300003464', $html);
        $this->assertStringNotContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('Bank Transfer Pro', $html);
    }

    public function testRenderFailsClosedWhenParamsCurrencyCannotSelectABank(): void
    {
        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'USD',
            'invoiceid' => 300003464,
        ], new RecordingBankLookup(activeByCurrency: []));

        $this->assertStringContainsString('btp-bank-details--missing', $html);
        $this->assertStringContainsString('Bank details are temporarily unavailable.', $html);
    }

    public function testRenderDoesNotFiveHundredWhenLookupThrowsPdoException(): void
    {
        $banks = new class implements BankLookup {
            public function findBySlug(string $slug): ?array
            {
                throw new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'currency' in 'SELECT'");
            }

            public function findActiveByCurrencyCode(string $currencyCode): ?array
            {
                return null;
            }
        };

        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'USD',
            'invoiceid' => 300003464,
        ], $banks);

        $this->assertStringContainsString('Bank details are temporarily unavailable.', $html);
    }

    public function testStaticGatewayLinkDelegatesToRenderer(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/modules/gateways/banktransferpro.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('function banktransferpro_link', $source);
        $this->assertStringContainsString("GatewayRenderer::render('banktransferpro', \$params)", $source);
    }
}

final class RecordingBankLookup implements BankLookup
{
    /** @var list<string> */
    public array $slugLookups = [];

    /** @var list<string> */
    public array $currencyLookups = [];

    /**
     * @param array<string, array<string, mixed>> $activeByCurrency
     */
    public function __construct(
        private array $activeByCurrency = [
            'USD' => [
                'display_name' => 'IDBI Bank — Nanded',
                'invoice_label' => 'IDBI Bank - Nanded',
                'upi_id' => 'securiace.com@idbi',
                'account_name' => 'SECURIACE TECHNOLOGIES',
                'account_number' => '500102000004909',
                'ifsc_code' => 'IBKL0000500',
                'account_details' => 'UPI: securiace.com@idbi',
                'bank_name' => 'IDBI Bank',
                'branch_name' => 'NANDED',
                'gateway_slug' => 'banktransferpro',
            ],
        ]
    ) {
    }

    public function findBySlug(string $slug): ?array
    {
        $this->slugLookups[] = $slug;

        return null;
    }

    public function findActiveByCurrencyCode(string $currencyCode): ?array
    {
        $this->currencyLookups[] = $currencyCode;

        return $this->activeByCurrency[strtoupper($currencyCode)] ?? null;
    }
}
