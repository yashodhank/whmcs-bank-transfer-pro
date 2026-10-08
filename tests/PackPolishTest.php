<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PackChecklist;
use BankTransferPro\Packs\PackRenderer;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\PaymentReference;
use PHPUnit\Framework\TestCase;

/**
 * Phase B: alias QR codes, copy-all and the printable wire checklist.
 */
final class PackPolishTest extends TestCase
{
    /**
     * @return array{id: int, number: string, amount: string, currency: string}
     */
    private function invoice(string $currency = 'INR'): array
    {
        return ['id' => 10482, 'number' => '10482', 'amount' => '1500.00', 'currency' => $currency];
    }

    /**
     * @return array<string, mixed>
     */
    private function singapore(): array
    {
        return [
            'bank_name' => 'DBS Bank',
            'branch_name' => 'Marina Bay',
            'currency_code' => 'SGD',
            'country_code' => 'SG',
            'account_name' => 'Securiace Technologies Pte Ltd',
            'account_number' => '0721234567',
            'capabilities' => ['local_transfer', 'instant_alias', 'international_wire'],
            'identifiers' => ['paynow' => '201912345A', 'swift_bic' => 'DBSSSGSGXXX'],
            'prefer_charge_code' => 'OUR',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brazil(): array
    {
        return [
            'bank_name' => 'Banco Inter',
            'branch_name' => 'Sao Paulo',
            'currency_code' => 'BRL',
            'country_code' => 'BR',
            'account_name' => 'Securiace Ltda',
            'account_number' => '1234567-8',
            'capabilities' => ['instant_alias'],
            'identifiers' => ['pix' => 'billing@example.com.br'],
        ];
    }

    public function testIndianInstantPackCarriesUpiQrWithAmountAndReference(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());
        $qr = $set['packs']['instant']['qr'];

        $this->assertCount(1, $qr);
        $this->assertSame('upi', $qr[0]['scheme']);
        $this->assertSame(
            'upi://pay?pa=securiace.com@idbi&pn=SECURIACE%20TECHNOLOGIES&am=1500.00&cu=INR&tn=' . PaymentReference::mint(10482),
            $qr[0]['payload']
        );
        $this->assertSame($qr[0]['payload'], $qr[0]['deeplink']);
    }

    public function testPayNowAndPixAliasesGetTheirOwnQr(): void
    {
        $sg = InstructionPackEngine::build($this->singapore(), PayerContext::fromCountry('SG'), $this->invoice('SGD'));
        $this->assertSame('paynow', $sg['packs']['instant']['qr'][0]['scheme']);
        $this->assertNull($sg['packs']['instant']['qr'][0]['deeplink']);
        $this->assertStringContainsString('0009SG.PAYNOW', $sg['packs']['instant']['qr'][0]['payload']);

        $br = InstructionPackEngine::build($this->brazil(), PayerContext::fromCountry('BR'), $this->invoice('BRL'));
        $this->assertSame('pix', $br['packs']['instant']['qr'][0]['scheme']);
        $this->assertStringContainsString('0014br.gov.bcb.pix', $br['packs']['instant']['qr'][0]['payload']);
    }

    public function testAliasesWithoutAQrFormatGetNoQr(): void
    {
        $bank = [
            'bank_name' => 'CBA',
            'currency_code' => 'AUD',
            'country_code' => 'AU',
            'account_name' => 'Securiace Pty',
            'account_number' => '12345678',
            'capabilities' => ['instant_alias'],
            'identifiers' => ['payid' => 'billing@example.com.au'],
        ];
        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('AU'), $this->invoice('AUD'));

        $this->assertSame([], $set['packs']['instant']['qr']);
        $this->assertStringNotContainsString('<svg', PackRenderer::render($set, 'CBA'));
    }

    public function testLocalAndWirePacksNeverCarryAQr(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());

        $this->assertSame([], $set['packs']['local']['qr']);
        $this->assertSame([], $set['packs']['wire']['qr']);

        $abroad = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('US'), $this->invoice());
        $html = PackRenderer::render($abroad, 'IDBI Bank - Nanded');

        $this->assertSame(['wire'], array_keys($abroad['packs']));
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('btp-qr', $html);
        $this->assertStringNotContainsString('upi://', $html);
        $this->assertStringNotContainsString('securiace.com@idbi', $html);
    }

    public function testMobileInvoiceRendersRecommendedUpiQrAndOpenInAppLink(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN', true), $this->invoice());
        $html = PackRenderer::render($set, 'IDBI Bank - Nanded');

        $this->assertSame('instant', $set['recommended']);
        $this->assertStringContainsString('<div class="btp-qr" data-btp-qr="upi">', $html);
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg" class="btp-qr__svg"', $html);
        $this->assertStringContainsString('Scan with any UPI app', $html);
        $this->assertStringContainsString('href="upi://pay?pa=securiace.com@idbi&amp;pn=SECURIACE%20TECHNOLOGIES&amp;am=1500.00', $html);
        $this->assertStringNotContainsString('Bank Transfer Pro', $html);
        $this->assertStringContainsString('IDBI Bank - Nanded', $html);
    }

    public function testQrAlsoAppearsUnderPayingAnotherWay(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());
        $html = PackRenderer::render($set, 'IDBI Bank - Nanded');

        $this->assertSame('local', $set['recommended']);
        $this->assertGreaterThan(strpos($html, 'Paying another way?'), strpos($html, 'btp-qr'));
    }

    public function testEveryPackOffersCopyAllWithTheReferenceInside(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());
        $html = PackRenderer::render($set, 'IDBI Bank - Nanded');

        $this->assertSame(3, substr_count($html, 'btp-copy--all'));
        $this->assertSame(3, preg_match_all('/btp-copy--all" data-btp-copy="[^"]*BTP-10482-/', $html));
    }

    public function testCopyAllTextForWireRepeatsNoReferenceAndNeverMentionsUpi(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('US'), $this->invoice());
        $text = PackChecklist::text($set, $set['packs']['wire'], 'IDBI Bank - Nanded');

        $this->assertStringStartsWith('IDBI Bank - Nanded - International wire', $text);
        $this->assertStringContainsString('Invoice: #10482', $text);
        $this->assertStringContainsString('Amount: 1500.00 INR', $text);
        $this->assertStringContainsString('SWIFT / BIC: IBKLINBBXXX', $text);
        $this->assertStringContainsString('Remittance Information: ' . $set['reference'], $text);
        $this->assertSame(1, substr_count($text, $set['reference']), 'reference is already a field; do not repeat it');
        $this->assertStringNotContainsStringIgnoringCase('upi', $text);
        $this->assertStringNotContainsString('securiace.com@idbi', $text);
        $this->assertStringNotContainsString('IBKL0000500', $text, 'no local IFSC in a wire checklist');
    }

    public function testCopyAllTextForLocalPackAddsTheReferenceLine(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());
        $text = PackChecklist::text($set, $set['packs']['local'], 'IDBI Bank - Nanded');

        $this->assertStringContainsString('Payment reference: ' . $set['reference'], $text);
        $this->assertStringContainsString('IFSC: IBKL0000500', $text);
    }

    public function testOnlyTheWirePackGetsAPrintChecklist(): void
    {
        $set = InstructionPackEngine::build(RecordingBankLookup::indiaProfile(), PayerContext::fromCountry('IN'), $this->invoice());
        $html = PackRenderer::render($set, 'IDBI Bank - Nanded');

        $this->assertSame(1, substr_count($html, 'data-btp-print="wire"'));
        $this->assertSame(1, substr_count($html, 'class="btp-print-sheet"'));
        $this->assertSame(1, substr_count($html, 'data-btp-print-sheet="wire"'));
    }

    public function testPrintSheetIsAnEscapedTickListBuiltFromTheWirePackOnly(): void
    {
        $bank = RecordingBankLookup::indiaProfile();
        $bank['beneficiary_address'] = 'Unit 5 <script>alert(1)</script> & Co';
        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('US'), $this->invoice());
        $sheet = PackChecklist::sheet($set, $set['packs']['wire'], 'IDBI Bank - Nanded', 'Invoice Reference');

        $this->assertStringContainsString('aria-hidden="true"', $sheet);
        $this->assertStringContainsString('International wire checklist', $sheet);
        $this->assertStringContainsString('Send exactly <strong>1500.00 INR</strong>', $sheet);
        $this->assertStringContainsString('<code>' . $set['reference'] . '</code>', $sheet);
        $this->assertStringContainsString('&#9744;', $sheet);
        $this->assertStringContainsString('IBKLINBBXXX', $sheet);
        $this->assertStringContainsString('Before you release the payment', $sheet);
        $this->assertStringContainsString('Do not use UPI for international payments', $sheet);
        $this->assertStringNotContainsString('<script>', $sheet);
        $this->assertStringContainsString('&lt;script&gt;', $sheet);
        $this->assertStringNotContainsString('securiace.com@idbi', $sheet);
        $this->assertStringNotContainsString('IBKL0000500', $sheet);
    }

    public function testClientAssetsShipPrintAndQrSupport(): void
    {
        $root = dirname(__DIR__) . '/modules/addons/banktransferpro/assets';
        $css = (string) file_get_contents($root . '/css/client.css');
        $js = (string) file_get_contents($root . '/js/client.js');

        $this->assertStringContainsString('.btp-print-sheet {', $css);
        $this->assertStringContainsString('display: none;', substr($css, (int) strpos($css, '.btp-print-sheet {'), 60));
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('body.btp-printing > :not(.btp-print-sheet)', $css);
        $this->assertStringContainsString('.btp-qr__svg', $css);
        $this->assertStringContainsString("closest('.btp-print')", $js);
        $this->assertStringContainsString("addEventListener('afterprint'", $js);
        $this->assertStringContainsString('initPrintChecklist();', $js);
    }
}
