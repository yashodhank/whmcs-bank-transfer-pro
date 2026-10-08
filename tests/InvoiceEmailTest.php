<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Email\EmailTemplateInjector;
use BankTransferPro\Email\InvoiceEmailRenderer;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PayerContext;
use PHPUnit\Framework\TestCase;

/**
 * Phase B: recommended pack + payment reference in invoice emails.
 */
final class InvoiceEmailTest extends TestCase
{
    private const URL = 'https://billing.example.com/viewinvoice.php?id=10482';

    /**
     * @return array<string, mixed>
     */
    private function packSet(string $country, bool $mobile = false): array
    {
        return InstructionPackEngine::build(
            RecordingBankLookup::indiaProfile(),
            PayerContext::fromCountry($country, $mobile),
            ['id' => 10482, 'number' => '10482', 'amount' => '1500.00', 'currency' => 'INR']
        );
    }

    public function testDomesticEmailCarriesRecommendedLocalPackAndReferenceOnly(): void
    {
        $set = $this->packSet('IN');
        $fields = InvoiceEmailRenderer::mergeFields($set, 'IDBI Bank - Nanded', self::URL);

        $this->assertSame(['btp_payment_reference', 'btp_payment_pack', 'btp_payment_instructions', 'btp_payment_instructions_text'], array_keys($fields));
        $this->assertSame($set['reference'], $fields['btp_payment_reference']);
        $this->assertSame('Local bank transfer', $fields['btp_payment_pack']);

        $html = $fields['btp_payment_instructions'];
        $this->assertStringContainsString('Pay via IDBI Bank - Nanded', $html);
        $this->assertStringContainsString('Send exactly <strong>1500.00 INR</strong>', $html);
        $this->assertStringContainsString($set['reference'], $html);
        $this->assertStringContainsString('IBKL0000500', $html);
        $this->assertStringContainsString('href="' . self::URL . '"', $html);
        $this->assertStringNotContainsString('securiace.com@idbi', $html, 'alternative packs stay on the invoice page');
        $this->assertStringNotContainsString('IBKLINBBXXX', $html);
        $this->assertStringNotContainsString('Bank Transfer Pro', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('class="btp-', str_replace('class="btp-email-instructions"', '', $html), 'inline styles only; no stylesheet dependency');
    }

    public function testClientAbroadGetsTheWirePackWithoutAnyInstantAlias(): void
    {
        $fields = InvoiceEmailRenderer::mergeFields($this->packSet('US'), 'IDBI Bank - Nanded', self::URL);

        $this->assertSame('International wire', $fields['btp_payment_pack']);
        foreach ([$fields['btp_payment_instructions'], $fields['btp_payment_instructions_text']] as $body) {
            $this->assertStringContainsString('IBKLINBBXXX', $body);
            $this->assertStringContainsString('Remittance Information', $body);
            $this->assertStringContainsString('OUR', $body);
            $this->assertStringNotContainsString('securiace.com@idbi', $body);
            $this->assertStringNotContainsString('IBKL0000500', $body);
        }
        $this->assertStringContainsString('Do not use UPI for international payments', $fields['btp_payment_instructions']);
        $this->assertStringContainsString('Open your invoice</a>', $fields['btp_payment_instructions']);
    }

    public function testInstantRecommendationPointsAtTheInvoiceForTheQr(): void
    {
        $fields = InvoiceEmailRenderer::mergeFields($this->packSet('IN', true), 'IDBI Bank - Nanded', self::URL);

        $this->assertSame('Pay in seconds', $fields['btp_payment_pack']);
        $this->assertStringContainsString('securiace.com@idbi', $fields['btp_payment_instructions']);
        $this->assertStringContainsString('scan the QR code', $fields['btp_payment_instructions']);
        $this->assertStringNotContainsString('upi://', $fields['btp_payment_instructions'], 'QR payload is not embedded in email');
    }

    public function testPlainTextVariantMirrorsTheHtml(): void
    {
        $set = $this->packSet('IN');
        $text = InvoiceEmailRenderer::mergeFields($set, 'IDBI Bank - Nanded', self::URL)['btp_payment_instructions_text'];

        $this->assertStringContainsString('Pay via IDBI Bank - Nanded', $text);
        $this->assertStringContainsString('Send exactly 1500.00 INR', $text);
        $this->assertStringContainsString('Payment reference: ' . $set['reference'], $text);
        $this->assertStringContainsString('IFSC: IBKL0000500', $text);
        $this->assertStringContainsString(self::URL, $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function testValuesAreHtmlEscaped(): void
    {
        $bank = RecordingBankLookup::indiaProfile();
        $bank['account_name'] = 'ACME <b>&</b> "Sons"';
        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('IN'), ['id' => 5, 'number' => '5', 'amount' => '10.00', 'currency' => 'INR']);
        $html = InvoiceEmailRenderer::mergeFields($set, 'Bank <x>', 'https://x.test/?a=1&b="2"')['btp_payment_instructions'];

        $this->assertStringNotContainsString('<b>&</b>', $html);
        $this->assertStringContainsString('ACME &lt;b&gt;&amp;&lt;/b&gt; &quot;Sons&quot;', $html);
        $this->assertStringContainsString('Pay via Bank &lt;x&gt;', $html);
        $this->assertStringContainsString('href="https://x.test/?a=1&amp;b=&quot;2&quot;"', $html);
    }

    public function testHonestErrorsAreEmailedInsteadOfBrokenInstructions(): void
    {
        $set = InstructionPackEngine::build(
            RecordingBankLookup::indiaProfile(),
            PayerContext::fromCountry('IN'),
            ['id' => 11, 'number' => '11', 'amount' => '10.00', 'currency' => 'USD']
        );
        $fields = InvoiceEmailRenderer::mergeFields($set, 'IDBI Bank - Nanded');

        $this->assertSame('', $fields['btp_payment_pack']);
        $this->assertStringContainsString('receives INR but your invoice is in USD', $fields['btp_payment_instructions']);
        $this->assertStringNotContainsString('500102000004909', $fields['btp_payment_instructions']);
        $this->assertStringNotContainsString('<a ', $fields['btp_payment_instructions'], 'no link without a URL');
    }

    public function testInjectorInsertsBeforeSignatureAndIsIdempotent(): void
    {
        $original = "<p>Dear {\$client_name},</p>\n<p>Please pay.</p>\n{\$signature}";
        $once = EmailTemplateInjector::inject($original);

        $this->assertTrue(EmailTemplateInjector::isInjected($once));
        $this->assertLessThan(strpos($once, '{$signature}'), strpos($once, EmailTemplateInjector::MARKER_START));
        $this->assertStringContainsString('{if isset($btp_payment_instructions) && $btp_payment_instructions}{$btp_payment_instructions}{/if}', $once);
        $this->assertSame($once, EmailTemplateInjector::inject($once));
        $this->assertSame(1, substr_count($once, EmailTemplateInjector::MARKER_START));
    }

    public function testInjectorRoundTripsExactlyWithAndWithoutSignature(): void
    {
        foreach (["Hello\n{\$signature}", "Hello\r\n{\$signature}\r\n", "No signature here", ''] as $original) {
            $this->assertSame($original, EmailTemplateInjector::remove(EmailTemplateInjector::inject($original)), json_encode($original) ?: '');
        }
    }

    public function testInjectorUsesPlainTextFieldForPlainTextTemplates(): void
    {
        $injected = EmailTemplateInjector::inject("Please pay.\n{\$signature}", true);

        $this->assertStringContainsString('{$btp_payment_instructions_text}', $injected);
        $this->assertStringNotContainsString('{$btp_payment_instructions}', $injected);
    }

    public function testRemoveLeavesUnmarkedTemplatesAlone(): void
    {
        $this->assertSame('Untouched {$signature}', EmailTemplateInjector::remove('Untouched {$signature}'));
    }

    public function testOnlyStockInvoicePaymentEmailsAreTargeted(): void
    {
        $this->assertSame(
            ['Invoice Created', 'Invoice Payment Reminder', 'First Invoice Overdue Notice', 'Second Invoice Overdue Notice', 'Third Invoice Overdue Notice'],
            EmailTemplateInjector::TEMPLATES
        );
    }

    public function testHookIgnoresNonInvoiceMailAndNeverThrows(): void
    {
        if (! defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once __DIR__ . '/fixtures/global-hook-stubs.php';
        require_once __DIR__ . '/../modules/addons/banktransferpro/hooks.php';

        $this->assertSame([], btp_invoice_email_merge_fields([]));
        $this->assertSame([], btp_invoice_email_merge_fields(['messagename' => 'Welcome Email', 'relid' => 0]));
        // No WHMCS database in unit tests: failures must degrade to "no merge fields", never break mail.
        $this->assertSame([], btp_invoice_email_merge_fields(['messagename' => 'Invoice Created', 'relid' => 7]));
    }

    public function testInfoTabExposesOptInControlsAndSnippet(): void
    {
        $info = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/templates/admin/info.tpl');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/lib/Admin/DashboardController.php');

        $this->assertStringContainsString('name="btp_email_action" value="install"', $info);
        $this->assertStringContainsString('name="btp_email_action" value="remove"', $info);
        $this->assertStringContainsString('{$emailSnippet|escape}', $info);
        $this->assertStringContainsString("check_token('WHMCS.admin.default'", $controller);
        $this->assertStringContainsString("isset(\$_POST['btp_email_action'])", $controller);
    }
}
