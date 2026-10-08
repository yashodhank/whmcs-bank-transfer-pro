<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\PaymentReference;
use PHPUnit\Framework\TestCase;

final class PaymentReferenceTest extends TestCase
{
    public function testMintIsDeterministicAndShort(): void
    {
        $this->assertSame(PaymentReference::mint(10482), PaymentReference::mint(10482));
        $this->assertMatchesRegularExpression('/^BTP-10482-[0-9A-Z]$/', PaymentReference::mint(10482));
    }

    public function testReferenceStaysWithinSwiftBudgetForRealisticInvoiceIds(): void
    {
        foreach ([1, 42, 10482, 999999, 300003464, 99999999999999] as $invoiceId) {
            $reference = PaymentReference::mint($invoiceId);

            $this->assertLessThanOrEqual(PaymentReference::MAX_LENGTH, strlen($reference), $reference);
            $this->assertLessThanOrEqual(PaymentReference::SWIFT_LINE_LENGTH, strlen($reference));
            $this->assertTrue(PaymentReference::isSwiftSafe($reference), $reference);
            $this->assertMatchesRegularExpression('/^[A-Z0-9-]+$/', $reference);
        }
    }

    public function testMintRejectsIdsThatCannotFitTheBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentReference::mint(999999999999999);
    }

    public function testMintRejectsNonPositiveIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentReference::mint(0);
    }

    public function testValidateRoundTripsAndBindsToInvoice(): void
    {
        $reference = PaymentReference::mint(10482);

        $this->assertTrue(PaymentReference::validate($reference));
        $this->assertTrue(PaymentReference::validate($reference, 10482));
        $this->assertFalse(PaymentReference::validate($reference, 10483));
        $this->assertSame(10482, PaymentReference::invoiceIdFrom($reference));
    }

    public function testCheckCharacterCatchesTyposAndTranspositions(): void
    {
        $reference = PaymentReference::mint(10482);
        [$prefix, $id, $check] = explode('-', $reference);

        $this->assertFalse(PaymentReference::validate($prefix . '-10483-' . $check));
        $this->assertFalse(PaymentReference::validate($prefix . '-10428-' . $check));
        $this->assertFalse(PaymentReference::validate('XYZ-' . $id . '-' . $check));
        $this->assertFalse(PaymentReference::validate(''));
        $this->assertFalse(PaymentReference::validate('INV-10482'));
    }

    public function testNormalizeToleratesBankMangling(): void
    {
        $reference = PaymentReference::mint(10482);
        $mangled = [
            strtolower($reference),
            str_replace('-', '', $reference),
            str_replace('-', ' ', $reference),
            str_replace('-', "\u{2013}", $reference),
            '  ' . $reference . "\n",
        ];

        foreach ($mangled as $input) {
            $this->assertSame($reference, PaymentReference::normalize($input), $input);
        }
    }

    public function testExtractFindsReferenceInsideBankNarration(): void
    {
        $reference = PaymentReference::mint(10482);
        $narration = 'NEFT/IBKLN123/SECURIACE INV PAYMENT ' . strtolower(str_replace('-', ' ', $reference)) . ' ACME LTD';

        $this->assertSame($reference, PaymentReference::extractFrom($narration));
        $this->assertNull(PaymentReference::extractFrom('NEFT/ACME LTD/INVOICE 10482'));
    }

    public function testSwiftSafetyRejectsUnsupportedCharactersAndLongValues(): void
    {
        $this->assertFalse(PaymentReference::isSwiftSafe('BTP_10482#1'));
        $this->assertFalse(PaymentReference::isSwiftSafe(str_repeat('A', 36)));
        $this->assertFalse(PaymentReference::isSwiftSafe(''));
    }
}
