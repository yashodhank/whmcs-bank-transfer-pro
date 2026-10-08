<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\BankProfile;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\PaymentReference;
use PHPUnit\Framework\TestCase;

final class InstructionPackEngineTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function india(array $overrides = []): array
    {
        return array_merge(RecordingBankLookup::indiaProfile(), $overrides);
    }

    /**
     * @return array{id: int, number: string, amount: string, currency: string}
     */
    private function invoice(string $currency = 'INR'): array
    {
        return ['id' => 10482, 'number' => 'INV-10482', 'amount' => '1000.00', 'currency' => $currency];
    }

    public function testOneIndianBankExposesLocalInstantAndWireOnOneRow(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());

        $this->assertNull($set['error']);
        $this->assertSame('local', $set['recommended']);
        $this->assertSame(['local', 'instant', 'wire'], array_keys($set['packs']));
        $this->assertSame(['instant', 'wire'], $set['alternatives']);
    }

    public function testEveryPackSharesTheSamePaymentReference(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());

        $this->assertSame(PaymentReference::mint(10482), $set['reference']);
        $this->assertSame(
            $set['reference'],
            $this->fieldValue($set['packs']['wire'], 'remittance')
        );
    }

    public function testWirePackNeverIncludesUpiOrOtherInstantAliases(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());
        $wire = $set['packs']['wire'];

        $keys = array_column($wire['fields'], 'key');
        $this->assertNotContains('upi', $keys);
        $this->assertNotContains('ifsc', $keys);
        foreach ($wire['fields'] as $field) {
            $this->assertStringNotContainsString('securiace.com@idbi', $field['value']);
            $this->assertStringNotContainsStringIgnoringCase('upi', $field['label']);
        }
        $this->assertSame([], $wire['chips']);
    }

    public function testLocalPackShowsClearingSystemsAsChipsOnly(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());
        $local = $set['packs']['local'];

        $this->assertSame(['NEFT', 'IMPS', 'RTGS'], $local['chips']);
        foreach ($local['fields'] as $field) {
            $this->assertNotContains($field['label'], ['NEFT', 'IMPS', 'RTGS']);
        }
        $this->assertContains('ifsc', array_column($local['fields'], 'key'));
        $this->assertNotContains('upi', array_column($local['fields'], 'key'));
    }

    public function testInstantPackContainsOnlyAliasAndPayee(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());
        $instant = $set['packs']['instant'];

        $this->assertSame(['upi', 'account_name'], array_column($instant['fields'], 'key'));
        $this->assertNotContains('account_number', array_column($instant['fields'], 'key'));
    }

    public function testForeignPayerOnlyGetsWirePack(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('US'), $this->invoice());

        $this->assertSame('wire', $set['recommended']);
        $this->assertSame(['wire'], array_keys($set['packs']));
        $this->assertSame([], $set['alternatives']);
    }

    public function testMobileOrMerchantPreferenceRecommendsInstantForDomesticPayer(): void
    {
        $mobile = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN', true), $this->invoice());
        $this->assertSame('instant', $mobile['recommended']);

        $preferred = InstructionPackEngine::build(
            $this->india(['pack_notes' => ['prefer_instant' => true]]),
            PayerContext::fromCountry('IN'),
            $this->invoice()
        );
        $this->assertSame('instant', $preferred['recommended']);
    }

    public function testMobileForeignPayerStillGetsWire(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('GB', true), $this->invoice());

        $this->assertSame('wire', $set['recommended']);
    }

    public function testUnknownPayerCountryFallsBackToDomesticBehaviour(): void
    {
        $set = InstructionPackEngine::build($this->india(), new PayerContext(null), $this->invoice());

        $this->assertSame('local', $set['recommended']);
    }

    public function testCurrencyMismatchIsBlockedUnlessFxAccepted(): void
    {
        $blocked = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('US'), $this->invoice('USD'));
        $this->assertNotNull($blocked['error']);
        $this->assertSame([], $blocked['packs']);

        $accepted = InstructionPackEngine::build(
            $this->india(['accept_fx_receive' => true]),
            PayerContext::fromCountry('US'),
            $this->invoice('USD')
        );
        $this->assertNull($accepted['error']);
        $this->assertSame('wire', $accepted['recommended']);
        $this->assertNotEmpty($accepted['packs']['wire']['warnings']);
    }

    public function testLocalOnlyBankHonestlyRefusesForeignPayers(): void
    {
        $bank = $this->india([
            'capabilities' => ['local_transfer'],
            'identifiers' => ['ifsc' => 'IBKL0000500'],
        ]);

        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('US'), $this->invoice());

        $this->assertNull($set['recommended']);
        $this->assertNotNull($set['error']);
        $this->assertStringContainsString('not available from your location', $set['error']);
    }

    public function testWireNotesCarryIndiaGuidance(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('US'), $this->invoice());
        $wire = $set['packs']['wire'];

        $this->assertSame('OUR', $this->fieldValue($wire, 'charges'));
        $this->assertStringContainsString('P0802', implode("\n", $wire['notes']));
        $this->assertStringContainsString('MT103', implode("\n", $wire['notes']));
        $this->assertStringContainsString('Do not use UPI', implode("\n", $wire['warnings']));
        $this->assertNotNull($wire['timeline']);
    }

    public function testWirePackRequiresBicAndAnAccountIdentifier(): void
    {
        $noBic = $this->india(['identifiers' => ['ifsc' => 'IBKL0000500', 'upi' => 'a@b']]);
        $set = InstructionPackEngine::build($noBic, PayerContext::fromCountry('IN'), $this->invoice());

        $this->assertArrayNotHasKey('wire', $set['packs']);
    }

    public function testNonIndianProfilesUseGlobalSchemes(): void
    {
        $uk = [
            'bank_name' => 'Barclays', 'branch_name' => '', 'currency_code' => 'GBP', 'country_code' => 'GB',
            'account_name' => 'ACME LTD', 'account_number' => '12345678',
            'capabilities' => ['local_transfer', 'international_wire'],
            'identifiers' => ['sort_code' => '20-00-00', 'iban' => 'GB82WEST12345698765432', 'swift_bic' => 'BARCGB22'],
            'beneficiary_address' => '1 High St, London',
        ];

        $domestic = InstructionPackEngine::build($uk, PayerContext::fromCountry('GB'), ['id' => 5, 'currency' => 'GBP', 'amount' => '10.00']);
        $this->assertSame('local', $domestic['recommended']);
        $this->assertSame('20-00-00', $this->fieldValue($domestic['packs']['local'], 'sort_code'));
        $this->assertSame(['Faster Payments', 'BACS', 'CHAPS'], $domestic['packs']['local']['chips']);

        $abroad = InstructionPackEngine::build($uk, PayerContext::fromCountry('AU'), ['id' => 5, 'currency' => 'GBP']);
        $this->assertSame('wire', $abroad['recommended']);
        $this->assertSame('BARCGB22', $this->fieldValue($abroad['packs']['wire'], 'swift_bic'));
        $this->assertSame('GB82WEST12345698765432', $this->fieldValue($abroad['packs']['wire'], 'iban'));
    }

    public function testLegacyRowsAreReadThroughFallbacks(): void
    {
        $legacy = [
            'bank_name' => 'IDBI Bank', 'branch_name' => 'NANDED', 'currency_code' => 'INR',
            'account_name' => 'SECURIACE TECHNOLOGIES', 'account_number' => '500102000004909',
            'upi_id' => 'securiace.com@idbi', 'ifsc_code' => 'ibkl0000500', 'account_details' => '',
        ];

        $this->assertSame('IN', BankProfile::countryCode($legacy));
        $this->assertEquals(['ifsc' => 'IBKL0000500', 'upi' => 'securiace.com@idbi'], BankProfile::identifiers($legacy));
        $this->assertSame(['local_transfer', 'instant_alias'], BankProfile::capabilities($legacy));

        $set = InstructionPackEngine::build($legacy, PayerContext::fromCountry('IN'), $this->invoice());
        $this->assertSame(['local', 'instant'], array_keys($set['packs']));
    }

    public function testLegacyFreeTextOnlyBankFallsBackToLegacyMode(): void
    {
        $bank = ['bank_name' => 'Old Bank', 'currency_code' => 'USD', 'account_details' => "Pay to ACME\nAcct 99"];
        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('US'), ['id' => 3, 'currency' => 'USD']);

        $this->assertTrue($set['legacy_only']);
        $this->assertSame([], $set['packs']);
        $this->assertSame("Pay to ACME\nAcct 99", $set['legacy_notes']);
    }

    public function testLegacyNotesAreScopedToLocalPackAndDedupedAgainstStructuredValues(): void
    {
        $bank = $this->india(['account_details' => "UPI: securiace.com@idbi\nUse IMPS for amounts under 2 lakh\nAccount SECURIACE TECHNOLOGIES"]);
        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('IN'), $this->invoice());

        $this->assertStringContainsString('IMPS for amounts under 2 lakh', implode("\n", $set['packs']['local']['notes']));
        $this->assertStringNotContainsString('securiace.com@idbi', implode("\n", $set['packs']['local']['notes']));
        $this->assertStringNotContainsString('IMPS for amounts', implode("\n", $set['packs']['wire']['notes']));
        $this->assertStringNotContainsString('IMPS for amounts', implode("\n", $set['packs']['instant']['notes']));
    }

    public function testPackToLinesMirrorsFieldsForTickets(): void
    {
        $set = InstructionPackEngine::build($this->india(), PayerContext::fromCountry('IN'), $this->invoice());
        $lines = InstructionPackEngine::packToLines($set['packs']['local']);

        $this->assertContains('IFSC: IBKL0000500', $lines);
        $this->assertContains('Account Number: 500102000004909', $lines);
    }

    /**
     * @param array<string, mixed> $pack
     */
    private function fieldValue(array $pack, string $key): ?string
    {
        foreach ($pack['fields'] as $field) {
            if ($field['key'] === $key) {
                return $field['value'];
            }
        }

        return null;
    }
}
