<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\ReceiveProfileValidator;
use PHPUnit\Framework\TestCase;

final class ReceiveProfileValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function indiaInput(array $overrides = []): array
    {
        return array_merge([
            'bank_name' => 'IDBI Bank',
            'branch_name' => 'Nanded',
            'currency_code' => 'inr',
            'country_code' => 'in',
            'account_name' => 'SECURIACE TECHNOLOGIES',
            'account_number' => '500102000004909',
            'capabilities' => 'local_transfer,instant_alias,international_wire',
            'identifiers' => json_encode(['ifsc' => 'ibkl0000500', 'upi' => 'securiace.com@idbi', 'swift_bic' => 'ibklinbbxxx']),
            'beneficiary_address' => 'Nanded, India',
        ], $overrides);
    }

    public function testValidIndiaProfileNormalisesAndKeepsOneRow(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput());

        $this->assertSame([], $result['errors']);
        $profile = $result['profile'];
        $this->assertSame('INR', $profile['currency_code']);
        $this->assertSame('IN', $profile['country_code']);
        $this->assertSame(['local_transfer', 'instant_alias', 'international_wire'], $profile['capabilities']);
        $this->assertEquals(['ifsc' => 'IBKL0000500', 'upi' => 'securiace.com@idbi', 'swift_bic' => 'IBKLINBBXXX'], $profile['identifiers']);
        $this->assertSame('OUR', $profile['prefer_charge_code']);
    }

    public function testWireRequiresBicAddressAndAccountIdentity(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput([
            'capabilities' => 'international_wire',
            'identifiers' => '{}',
            'beneficiary_address' => '',
            'account_number' => '',
        ]));

        $this->assertContains('SWIFT / BIC is required for international wires.', $result['errors']);
        $this->assertContains('Beneficiary address is required for international wires.', $result['errors']);
        $this->assertContains('Account number (or IBAN) is required for international wires.', $result['errors']);
    }

    public function testLocalIndiaRequiresIfsc(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput([
            'capabilities' => 'local_transfer',
            'identifiers' => '{}',
        ]));

        $this->assertContains('IFSC is required for local transfers in India.', $result['errors']);
    }

    public function testInstantRequiresAnAliasAndRejectsUnsupportedCountries(): void
    {
        $noAlias = ReceiveProfileValidator::validate($this->indiaInput([
            'capabilities' => 'instant_alias',
            'identifiers' => '{}',
        ]));
        $this->assertContains('Enter an instant-payment ID (for example a UPI ID) or turn off instant payments.', $noAlias['errors']);

        $unsupported = ReceiveProfileValidator::validate($this->indiaInput([
            'country_code' => 'DE',
            'capabilities' => 'instant_alias',
            'identifiers' => '{}',
        ]));
        $this->assertStringContainsString('not supported for Germany yet', implode(' ', $unsupported['errors']));
    }

    public function testInvalidSchemeValuesAreReportedByLabel(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput([
            'identifiers' => json_encode(['ifsc' => 'BAD', 'upi' => 'nope', 'swift_bic' => 'IBKLINBBXXX']),
        ]));

        $this->assertContains('IFSC looks invalid.', $result['errors']);
        $this->assertContains('UPI ID looks invalid.', $result['errors']);
    }

    public function testRequiresCountryWhenCapabilitiesAreChosen(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput([
            'country_code' => '',
            'capabilities' => 'international_wire',
            'identifiers' => json_encode(['swift_bic' => 'IBKLINBBXXX']),
        ]));

        $this->assertContains('Select the country where this bank account is held.', $result['errors']);
    }

    public function testLegacyRequestShapeStillSavesAndInfersCapabilities(): void
    {
        $result = ReceiveProfileValidator::validate([
            'bank_name' => 'IDBI Bank',
            'currency_code' => 'INR',
            'upi_id' => 'securiace.com@idbi',
            'account_number' => '500102000004909',
            'account_name' => 'SECURIACE TECHNOLOGIES',
            'ifsc_code' => 'ibkl0000500',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('IN', $result['profile']['country_code']);
        $this->assertSame(['local_transfer', 'instant_alias'], $result['profile']['capabilities']);
        $this->assertSame('IBKL0000500', $result['profile']['identifiers']['ifsc']);
    }

    public function testFreeTextOnlyLegacyBankIsStillAllowed(): void
    {
        $result = ReceiveProfileValidator::validate([
            'bank_name' => 'Old Bank',
            'currency_code' => 'USD',
            'account_details' => 'Pay to ACME, acct 99',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['profile']['capabilities']);
    }

    public function testRejectsEmptyProfile(): void
    {
        $result = ReceiveProfileValidator::validate(['bank_name' => 'X', 'currency_code' => 'USD']);

        $this->assertNotEmpty($result['errors']);
    }

    public function testPolicyFieldsAreValidated(): void
    {
        $bad = ReceiveProfileValidator::validate($this->indiaInput(['prefer_charge_code' => 'XXX', 'intermediary_bic' => 'bad']));
        $this->assertContains('Charge code must be OUR, SHA or BEN.', $bad['errors']);
        $this->assertContains('Intermediary BIC looks invalid.', $bad['errors']);

        $ok = ReceiveProfileValidator::validate($this->indiaInput([
            'prefer_charge_code' => 'sha',
            'accept_fx_receive' => '1',
            'prefer_instant' => '1',
            'wire_purpose_hint' => 'P0802',
        ]));
        $this->assertSame([], $ok['errors']);
        $this->assertSame('SHA', $ok['profile']['prefer_charge_code']);
        $this->assertTrue($ok['profile']['accept_fx_receive']);
        $this->assertTrue($ok['profile']['pack_notes']['prefer_instant']);
    }

    public function testExistingPackNotesSurviveUpdates(): void
    {
        $result = ReceiveProfileValidator::validate($this->indiaInput([
            'pack_notes' => json_encode(['wire' => 'Quote the PO number']),
        ]));

        $this->assertSame('Quote the PO number', $result['profile']['pack_notes']['wire']);
    }
}
