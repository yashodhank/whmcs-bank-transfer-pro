<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\PaymentReference;
use BankTransferPro\Packs\ProofDetails;
use PHPUnit\Framework\TestCase;

final class ProofDetailsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function packSet(string $country = 'IN'): array
    {
        return InstructionPackEngine::build(
            RecordingBankLookup::indiaProfile(),
            PayerContext::fromCountry($country),
            ['id' => 10482, 'number' => 'INV-10482', 'amount' => '1000.00', 'currency' => 'INR']
        );
    }

    public function testRequiresPaymentReferenceMatchingTheInvoice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payment reference is required');

        ProofDetails::fromRequest([], $this->packSet(), 10482, 'INR', false);
    }

    public function testRejectsReferenceForAnotherInvoice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match this invoice');

        ProofDetails::fromRequest(['payment_reference' => PaymentReference::mint(999)], $this->packSet(), 10482, 'INR', false);
    }

    public function testAcceptsFullEnrichmentAndCanonicalisesValues(): void
    {
        $details = ProofDetails::fromRequest([
            'payment_reference' => strtolower(str_replace('-', ' ', PaymentReference::mint(10482))),
            'pack_id' => 'wire',
            'rail_reference' => 'UTR123456789',
            'declared_amount' => '1,000.5',
            'declared_currency' => 'inr',
        ], $this->packSet(), 10482, 'INR', false);

        $this->assertSame(PaymentReference::mint(10482), $details['payment_reference']);
        $this->assertSame('wire', $details['pack_id']);
        $this->assertSame('UTR123456789', $details['rail_reference']);
        $this->assertSame('1000.50', $details['declared_amount']);
        $this->assertSame('INR', $details['declared_currency']);
    }

    public function testPackDefaultsToRecommendedAndMustBeAnOfferedPack(): void
    {
        $post = ['payment_reference' => PaymentReference::mint(10482)];
        $this->assertSame('local', ProofDetails::fromRequest($post, $this->packSet(), 10482, 'INR', false)['pack_id']);

        $this->expectException(\InvalidArgumentException::class);
        ProofDetails::fromRequest($post + ['pack_id' => 'bogus'], $this->packSet(), 10482, 'INR', false);
    }

    public function testForeignPayerCannotClaimLocalPack(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProofDetails::fromRequest(
            ['payment_reference' => PaymentReference::mint(10482), 'pack_id' => 'local'],
            $this->packSet('US'),
            10482,
            'INR',
            false
        );
    }

    public function testDeclaredCurrencyDifferingFromInvoiceIsBlockedUnlessFxAccepted(): void
    {
        $post = [
            'payment_reference' => PaymentReference::mint(10482),
            'pack_id' => 'wire',
            'declared_amount' => '12.00',
            'declared_currency' => 'USD',
        ];

        try {
            ProofDetails::fromRequest($post, $this->packSet(), 10482, 'INR', false);
            $this->fail('Expected FX block');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('convert and send in INR', $e->getMessage());
        }

        $details = ProofDetails::fromRequest($post, $this->packSet(), 10482, 'INR', true);
        $this->assertSame('USD', $details['declared_currency']);
    }

    public function testDeclaredAmountDefaultsCurrencyAndRejectsGarbage(): void
    {
        $post = ['payment_reference' => PaymentReference::mint(10482), 'declared_amount' => '500'];
        $this->assertSame('INR', ProofDetails::fromRequest($post, $this->packSet(), 10482, 'INR', false)['declared_currency']);

        foreach (['abc', '-5', '0', '1.234'] as $bad) {
            try {
                ProofDetails::fromRequest(['declared_amount' => $bad] + $post, $this->packSet(), 10482, 'INR', false);
                $this->fail('Expected rejection for ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRailReferenceCharsetIsRestricted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProofDetails::fromRequest(
            ['payment_reference' => PaymentReference::mint(10482), 'rail_reference' => "<script>alert(1)</script>"],
            $this->packSet(),
            10482,
            'INR',
            false
        );
    }

    public function testTicketLinesMirrorThePackAndReference(): void
    {
        $packSet = $this->packSet();
        $details = ProofDetails::fromRequest([
            'payment_reference' => PaymentReference::mint(10482),
            'pack_id' => 'local',
            'rail_reference' => 'UTR1',
            'declared_amount' => '1000',
        ], $packSet, 10482, 'INR', false);

        $text = implode("\n", ProofDetails::ticketLines($details, $packSet));

        $this->assertStringContainsString('Payment reference: ' . PaymentReference::mint(10482), $text);
        $this->assertStringContainsString('Payment method used: Local bank transfer (local)', $text);
        $this->assertStringContainsString('Bank reference (UTR / UETR / RRN): UTR1', $text);
        $this->assertStringContainsString('Client says they sent: 1000.00 INR', $text);
        $this->assertStringContainsString('Invoice amount due: 1000.00 INR', $text);
        $this->assertStringContainsString('IFSC: IBKL0000500', $text);
        $this->assertStringNotContainsString('securiace.com@idbi', $text);
    }

    public function testBankWithoutPacksStillAcceptsReferenceOnlyProof(): void
    {
        $details = ProofDetails::fromRequest(
            ['payment_reference' => PaymentReference::mint(5), 'pack_id' => 'wire'],
            ['packs' => [], 'recommended' => null],
            5,
            'USD',
            false
        );

        $this->assertSame('', $details['pack_id']);
    }
}
