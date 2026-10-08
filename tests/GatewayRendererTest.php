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
        $this->assertStringContainsString('UPI ID:', $html);
        $this->assertStringContainsString('securiace.com@idbi', $html);
        $this->assertStringContainsString('Account Number:', $html);
        $this->assertStringContainsString('500102000004909', $html);
        $this->assertStringContainsString('IFSC:', $html);
        $this->assertStringContainsString('IBKL0000500', $html);
        $this->assertStringContainsString('INV-300003464', $html);
        $this->assertStringNotContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('Bank Transfer Pro', $html);
    }

    public function testRendersOneRecommendedPackWithPayingAnotherWayEscapeHatch(): void
    {
        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'USD',
            'amount' => '1500.00',
            'invoiceid' => 10482,
            'invoicenum' => 'INV-10482',
        ], new RecordingBankLookup());

        $this->assertSame(1, substr_count($html, 'btp-pack--recommended'));
        $this->assertStringContainsString('Paying another way?', $html);
        $this->assertStringContainsString('Send exactly', $html);
        $this->assertStringContainsString('1500.00 USD', $html);
        $this->assertStringContainsString('Payment reference', $html);
        $this->assertStringContainsString('BTP-10482-', $html);
        $this->assertStringContainsString('data-btp-copy="500102000004909"', $html);

        // Local pack is recommended; UPI sits behind the escape hatch, never in the recommended pack.
        $recommended = $this->between($html, '<section class="btp-pack btp-pack--local btp-pack--recommended"', '</section>');
        $this->assertStringContainsString('NEFT', $recommended);
        $this->assertStringNotContainsString('UPI', $recommended);
    }

    public function testForeignClientOnlySeesWirePackAndNeverUpi(): void
    {
        $bank = RecordingBankLookup::indiaProfile();
        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'INR',
            'amount' => '1000.00',
            'invoiceid' => 7,
            'clientdetails' => ['country' => 'US'],
        ], new RecordingBankLookup(['INR' => $bank]));

        $this->assertStringContainsString('btp-pack--wire', $html);
        $this->assertStringContainsString('International wire', $html);
        $this->assertStringContainsString('IBKLINBBXXX', $html);
        $this->assertStringNotContainsString('btp-pack--local', $html);
        $this->assertStringNotContainsString('btp-pack--instant', $html);
        // The only UPI mention allowed on a wire pack is the explicit "do not use UPI" warning.
        $this->assertStringNotContainsString('UPI ID', $html);
        $this->assertStringNotContainsString('securiace.com@idbi', $html);
        $this->assertStringContainsString('Do not use UPI for international payments', $html);
        $this->assertStringNotContainsString('Paying another way?', $html);
        $this->assertStringNotContainsString('NEFT', $html);
    }

    public function testMobileUserAgentRecommendsInstantPackForDomesticClient(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
        try {
            $html = GatewayRenderer::render('banktransferpro', [
                'currency' => 'INR',
                'invoiceid' => 9,
                'clientdetails' => ['country' => 'IN'],
            ], new RecordingBankLookup(['INR' => RecordingBankLookup::indiaProfile()]));
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }

        $this->assertStringContainsString('btp-pack--instant btp-pack--recommended', $html);
        $this->assertStringContainsString('Pay in seconds', $html);
    }

    public function testCurrencyMismatchWithoutFxAcceptanceFailsHonestly(): void
    {
        $bank = RecordingBankLookup::indiaProfile();
        $html = GatewayRenderer::render('banktransferpro', [
            'currency' => 'USD',
            'amount' => '10.00',
            'invoiceid' => 11,
        ], new RecordingBankLookup(['USD' => $bank]));

        $this->assertStringContainsString('btp-pack-error', $html);
        $this->assertStringContainsString('receives INR but your invoice is in USD', $html);
        $this->assertStringNotContainsString('500102000004909', $html);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'start marker not found: ' . $start);
        $to = strpos($html, $end, (int) $from);
        $this->assertNotFalse($to);

        return substr($html, (int) $from, (int) $to - (int) $from);
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
                return null;
            }

            public function findActiveBySlug(string $slug): ?array
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

    /**
     * One INR profile exposing local + UPI + wire on a single row.
     *
     * @return array<string, mixed>
     */
    public static function indiaProfile(): array
    {
        return [
            'gateway_slug' => 'banktransferpro',
            'bank_name' => 'IDBI Bank',
            'branch_name' => 'NANDED',
            'currency_code' => 'INR',
            'country_code' => 'IN',
            'invoice_label' => 'IDBI Bank - Nanded',
            'display_name' => 'IDBI Bank — NANDED',
            'account_name' => 'SECURIACE TECHNOLOGIES',
            'account_number' => '500102000004909',
            'account_details' => '',
            'capabilities' => ['local_transfer', 'instant_alias', 'international_wire'],
            'identifiers' => ['ifsc' => 'IBKL0000500', 'upi' => 'securiace.com@idbi', 'swift_bic' => 'IBKLINBBXXX'],
            'beneficiary_address' => 'Nanded, Maharashtra, India',
            'bank_address' => 'IDBI Bank, Nanded',
            'prefer_charge_code' => 'OUR',
            'wire_purpose_hint' => 'P0802 - Software consultancy',
            'pack_notes' => [],
        ];
    }

    public function findBySlug(string $slug): ?array
    {
        return null;
    }

    public function findActiveBySlug(string $slug): ?array
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
