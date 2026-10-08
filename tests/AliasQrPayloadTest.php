<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\Qr\AliasQrPayload;
use PHPUnit\Framework\TestCase;

final class AliasQrPayloadTest extends TestCase
{
    public function testCrcMatchesPublishedVectors(): void
    {
        $this->assertSame('29B1', AliasQrPayload::crc16('123456789'));

        // Banco Central do Brasil static Pix example.
        $this->assertSame('1D3D', AliasQrPayload::crc16(
            '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***6304'
        ));
    }

    public function testUpiDeepLinkCarriesPayeeAmountAndPaymentReference(): void
    {
        $this->assertSame(
            'upi://pay?pa=name@bank&pn=Securiace%20Technologies&am=1500.00&cu=INR&tn=BTP-10482-X',
            AliasQrPayload::upi('name@bank', 'Securiace Technologies', '1500.00', 'BTP-10482-X')
        );
    }

    public function testUpiRejectsMalformedVpa(): void
    {
        $this->assertNull(AliasQrPayload::upi('not a vpa', 'X', null, 'BTP-1-1'));
        $this->assertNull(AliasQrPayload::upi('a@b&pa=evil@bank', 'X', null, 'BTP-1-1'));
    }

    public function testAmountIsOnlyPrefilledInTheRailCurrency(): void
    {
        $inr = AliasQrPayload::build('upi', 'name@bank', 'Securiace', '1500', 'INR', 'BTP-1-1');
        $this->assertNotNull($inr);
        $this->assertStringContainsString('am=1500.00&cu=INR', $inr['payload']);

        $usd = AliasQrPayload::build('upi', 'name@bank', 'Securiace', '1500.00', 'USD', 'BTP-1-1');
        $this->assertNotNull($usd);
        $this->assertStringNotContainsString('am=', $usd['payload']);
        $this->assertStringNotContainsString('cu=', $usd['payload']);

        $junk = AliasQrPayload::build('upi', 'name@bank', 'Securiace', '1,500.00', 'INR', 'BTP-1-1');
        $this->assertNotNull($junk);
        $this->assertStringNotContainsString('am=', $junk['payload']);
    }

    public function testPayNowUenPayloadIsStructurallyValid(): void
    {
        $payload = AliasQrPayload::paynow('201912345a', 'Securiace Technologies Pte', '1000.00', 'BTP-10482-X');

        $this->assertNotNull($payload);
        $this->assertStringStartsWith('000201010212', $payload);
        $this->assertStringContainsString('0009SG.PAYNOW', $payload);
        $this->assertStringContainsString('01012', $payload, 'UEN proxy type');
        $this->assertStringContainsString('0210201912345A', $payload);
        $this->assertStringContainsString('5303702', $payload);
        $this->assertStringContainsString('54071000.00', $payload);
        $this->assertStringContainsString('5802SG', $payload);
        $this->assertStringContainsString('0111BTP-10482-X', $payload);
        $this->assertCrcIsValid($payload);
    }

    public function testPayNowMobileGetsCountryPrefixAndEditableAmountWhenNoAmount(): void
    {
        $payload = AliasQrPayload::paynow('91234567', 'Securiace', null, 'BTP-1-2');

        $this->assertNotNull($payload);
        $this->assertStringContainsString('01010', $payload, 'mobile proxy type');
        $this->assertStringContainsString('0211+6591234567', $payload);
        $this->assertStringContainsString('03011', $payload, 'amount editable when none is supplied');
        $this->assertStringNotContainsString('5406', $payload);
        $this->assertCrcIsValid($payload);
    }

    public function testPixPayloadFollowsBrCodeLayout(): void
    {
        $payload = AliasQrPayload::pix('billing@example.com.br', 'Securiace Ltda', '150.00', 'BTP-10482-X');

        $this->assertNotNull($payload);
        $this->assertStringStartsWith('00020126', $payload);
        $this->assertStringContainsString('0014br.gov.bcb.pix0122billing@example.com.br', $payload);
        $this->assertStringContainsString('5303986', $payload);
        $this->assertStringContainsString('5406150.00', $payload);
        $this->assertStringContainsString('5802BR', $payload);
        $this->assertStringContainsString('5914SECURIACE LTDA', $payload);
        $this->assertStringContainsString('0509BTP10482X', $payload, 'txid is alphanumeric only');
        $this->assertCrcIsValid($payload);
    }

    public function testMerchantTextIsAsciiAndBounded(): void
    {
        $payload = AliasQrPayload::pix('a1b2c3d4e5', 'Sociedade de Serviços Técnicos Brasileiros Ltda', null, 'BTP-1-1');

        $this->assertNotNull($payload);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $payload);
        $this->assertMatchesRegularExpression('/59(\d{2})/', $payload);
        preg_match('/59(\d{2})/', $payload, $m);
        $this->assertLessThanOrEqual(25, (int) $m[1]);
    }

    public function testUnsupportedSchemesAndEmptyAliasesYieldNoQr(): void
    {
        $this->assertNull(AliasQrPayload::build('payid', 'billing@example.com.au', 'X', '1.00', 'AUD', 'BTP-1-1'));
        $this->assertNull(AliasQrPayload::build('upi', '', 'X', '1.00', 'INR', 'BTP-1-1'));
        $this->assertSame(['upi', 'paynow', 'pix'], AliasQrPayload::supportedSchemes());
    }

    private function assertCrcIsValid(string $payload): void
    {
        $this->assertSame('6304', substr($payload, -8, 4), 'CRC tag must be last');
        $this->assertSame(substr($payload, -4), AliasQrPayload::crc16(substr($payload, 0, -4)));
    }
}
