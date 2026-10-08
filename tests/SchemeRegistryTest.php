<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\SchemeRegistry;
use PHPUnit\Framework\TestCase;

final class SchemeRegistryTest extends TestCase
{
    public function testFirstClassSchemesAreRegistered(): void
    {
        foreach (['ifsc', 'upi', 'swift_bic', 'iban', 'aba', 'sort_code', 'bsb', 'payid', 'paynow', 'interac', 'pix', 'clabe', 'fps'] as $id) {
            $this->assertNotNull(SchemeRegistry::get($id), $id);
        }
    }

    /**
     * @dataProvider validValues
     */
    public function testValidValues(string $scheme, string $value): void
    {
        $this->assertTrue(SchemeRegistry::isValid($scheme, $value), $scheme . ' ' . $value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validValues(): array
    {
        return [
            'ifsc' => ['ifsc', 'ibkl0000500'],
            'upi' => ['upi', 'securiace.com@idbi'],
            'bic8' => ['swift_bic', 'IBKLINBB'],
            'bic11' => ['swift_bic', 'ibklinbbxxx'],
            'iban gb' => ['iban', 'GB82 WEST 1234 5698 7654 32'],
            'iban de' => ['iban', 'DE89370400440532013000'],
            'aba' => ['aba', '021000021'],
            'sort code' => ['sort_code', '200000'],
            'bsb' => ['bsb', '062000'],
            'clabe' => ['clabe', '012345678901234567'],
            'payid' => ['payid', 'billing@example.com.au'],
            'interac' => ['interac', 'Billing@Example.ca'],
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testInvalidValues(string $scheme, string $value): void
    {
        $this->assertFalse(SchemeRegistry::isValid($scheme, $value), $scheme . ' ' . $value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'ifsc missing zero' => ['ifsc', 'IBKL1000500'],
            'upi no handle' => ['upi', 'securiace.com'],
            'bic bad length' => ['swift_bic', 'IBKLINB'],
            'iban bad checksum' => ['iban', 'GB82WEST12345698765433'],
            'aba short' => ['aba', '12345'],
            'sort code short' => ['sort_code', '2000'],
            'unknown scheme' => ['nope', 'x'],
            'empty' => ['ifsc', ''],
        ];
    }

    public function testNormalizationCanonicalisesValues(): void
    {
        $this->assertSame('IBKL0000500', SchemeRegistry::normalize('ifsc', ' ibkl0000500 '));
        $this->assertSame('20-00-00', SchemeRegistry::normalize('sort_code', '200000'));
        $this->assertSame('062-000', SchemeRegistry::normalize('bsb', '062000'));
        $this->assertSame('GB82WEST12345698765432', SchemeRegistry::normalize('iban', 'gb82 west 1234 5698 7654 32'));
        $this->assertSame('name@bank', SchemeRegistry::normalize('upi', ' Name@Bank '));
    }

    public function testCapabilitySchemesAreCountryScoped(): void
    {
        $ids = static fn (array $schemes): array => array_column($schemes, 'id');

        $this->assertSame(['ifsc'], $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_LOCAL, 'IN')));
        $this->assertSame(['sort_code', 'iban'], $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_LOCAL, 'GB')));
        $this->assertSame(['upi'], $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_INSTANT, 'IN')));
        $this->assertSame(['paynow'], $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_INSTANT, 'SG')));
        $this->assertContains('swift_bic', $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_WIRE, 'ZZ')));
        $this->assertNotContains('upi', $ids(SchemeRegistry::forCapability(SchemeRegistry::CAP_WIRE, 'IN')));
    }

    public function testRailChipsAreEducationalDataNotSchemes(): void
    {
        $this->assertSame(['NEFT', 'IMPS', 'RTGS'], SchemeRegistry::chipsForCountry('IN'));
        $this->assertSame(['ACH', 'Wire'], SchemeRegistry::chipsForCountry('US'));
        $this->assertSame(['Faster Payments', 'BACS', 'CHAPS'], SchemeRegistry::chipsForCountry('GB'));
        $this->assertSame(['SEPA Credit Transfer', 'SEPA Instant'], SchemeRegistry::chipsForCountry('DE'));
        $this->assertSame([], SchemeRegistry::chipsForCountry('ZZ'));
        $this->assertNull(SchemeRegistry::get('neft'));
    }
}
