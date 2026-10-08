<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Client\TicketFactory;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PackRenderer;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Packs\PaymentReference;
use BankTransferPro\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class InstructionPackContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once __DIR__ . '/fixtures/global-hook-stubs.php';
        require_once __DIR__ . '/../modules/addons/banktransferpro/hooks.php';
    }

    public function testNoLibFileSelectsCurrencyFromInvoices(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/modules/addons/banktransferpro/lib'));
        $checked = 0;
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                "/table\(\s*['\"]tblinvoices['\"]\s*\)(?:(?!table\().){0,200}['\"]currency['\"]/s",
                $source,
                $file->getPathname()
            );
            $this->assertStringNotContainsString('$invoice->currency', $source, $file->getPathname());
            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
        $hooks = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/hooks.php');
        $this->assertStringContainsString('InvoiceCurrencyResolver())->codeFromInvoiceRecord', $hooks);
    }

    public function testUploadControllerValidatesPackFieldsBeforeStoringTheFile(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/lib/Client/UploadController.php');

        $this->assertStringContainsString('ProofDetails::fromRequest(', $source);
        $this->assertLessThan(
            strpos($source, '$this->uploader->store('),
            strpos($source, 'ProofDetails::fromRequest('),
            'Pack-aware validation must run before the upload is written to disk.'
        );
        foreach (['payment_reference', 'pack_id', 'rail_reference', 'declared_amount', 'declared_currency'] as $field) {
            $this->assertStringContainsString("'" . $field . "' => \$details['" . $field . "']", $source);
        }
        $this->assertStringContainsString("'paymentreference' => \$details['payment_reference']", $source);
    }

    public function testProofRepositoryPersistsReconcileFields(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/lib/Repository/ProofRepository.php');

        foreach (['payment_reference', 'pack_id', 'rail_reference', 'declared_amount', 'declared_currency'] as $field) {
            $this->assertStringContainsString("'" . $field . "'", $source);
        }
    }

    public function testTicketSubjectAlwaysCarriesPaymentReference(): void
    {
        $reference = PaymentReference::mint(10482);

        $custom = new TicketFactory(new SubjectSettings('Proof for #{invoiceid}'));
        $this->assertSame(
            'Proof for #10482 [' . $reference . ']',
            $custom->renderSubject(['invoiceid' => '10482', 'paymentreference' => $reference])
        );

        $withPlaceholder = new TicketFactory(new SubjectSettings('Payment proof {paymentreference} — Invoice #{invoiceid}'));
        $this->assertSame(
            'Payment proof ' . $reference . ' — Invoice #10482',
            $withPlaceholder->renderSubject(['invoiceid' => '10482', 'paymentreference' => $reference])
        );
    }

    public function testRendererEscapesBankControlledValues(): void
    {
        $bank = RecordingBankLookup::indiaProfile();
        $bank['account_name'] = '<script>alert(1)</script>';
        $bank['invoice_label'] = '"><img src=x onerror=alert(1)>';

        $set = InstructionPackEngine::build($bank, PayerContext::fromCountry('IN'), ['id' => 1, 'currency' => 'INR']);
        $html = PackRenderer::render($set, $bank['invoice_label']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testProofPanelPostsReferencePackAndDeclaredAmount(): void
    {
        $reference = PaymentReference::mint(10482);
        $html = btp_render_payment_proof_panel([
            'btp_upload_action' => 'index.php?m=banktransferpro&action=upload-proof',
            'btp_invoice_id' => 10482,
            'btp_csrf_token' => 'tok',
            'btp_payment_reference' => $reference,
            'btp_invoice_currency' => 'INR',
            'btp_recommended_pack' => 'local',
            'btp_pack_options' => [
                ['id' => 'local', 'title' => 'Local bank transfer'],
                ['id' => 'wire', 'title' => 'International wire'],
            ],
        ]);

        $this->assertStringContainsString('name="payment_reference" value="' . $reference . '"', $html);
        $this->assertStringContainsString('name="pack_id" value="local" checked', $html);
        $this->assertStringContainsString('name="pack_id" value="wire"', $html);
        $this->assertStringContainsString('name="rail_reference"', $html);
        $this->assertStringContainsString('name="declared_amount"', $html);
        $this->assertStringContainsString('name="declared_currency" value="INR"', $html);
    }

    public function testInvoiceFooterShipsCopyHandler(): void
    {
        $html = btp_render_invoice_footer(['btp_payment_label' => 'IDBI Bank']);

        $this->assertStringContainsString('assets/js/client.js?v=', $html);
        $this->assertStringContainsString('window.BTP_PAYMENT_LABEL = "IDBI Bank"', $html);

        $script = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/assets/js/client.js');
        $this->assertStringContainsString('initCopyButtons', $script);
        $this->assertStringContainsString('.btp-copy', $script);
        $this->assertStringContainsString('dedupeProofPanels', $script);
    }

    public function testGatewayLinkCarriesItsOwnAssetsAndProofPanelForStockThemes(): void
    {
        \BankTransferPro\Client\ClientAssets::reset();
        $html = \BankTransferPro\Gateway\GatewayRenderer::render('banktransferpro', [
            'currency' => 'INR',
            'invoiceid' => 21,
            'systemurl' => 'https://billing.example.test/',
        ], new RecordingBankLookup(['INR' => RecordingBankLookup::indiaProfile()]));

        $this->assertStringContainsString('href="https://billing.example.test/modules/addons/banktransferpro/assets/css/client.css?v=', $html);
        $this->assertStringContainsString('src="https://billing.example.test/modules/addons/banktransferpro/assets/js/client.js?v=', $html);
        $this->assertStringContainsString('data-btp-label="IDBI Bank - Nanded"', $html);

        // Assets are emitted once per request even if several gateway links render.
        $again = \BankTransferPro\Gateway\GatewayRenderer::render('banktransferpro', [
            'currency' => 'INR',
            'invoiceid' => 21,
        ], new RecordingBankLookup(['INR' => RecordingBankLookup::indiaProfile()]));
        $this->assertStringNotContainsString('client.css', $again);
    }

    public function testClientAndAdminStylesCoverPackUi(): void
    {
        $client = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/assets/css/client.css');

        foreach (['.btp-reference-hero', '.btp-amount-strip', '.btp-pack--recommended', '.btp-pack-alt', '.btp-chip', '.btp-copy'] as $selector) {
            $this->assertStringContainsString($selector, $client);
        }
        $this->assertStringContainsString('btp_admin_preview_stylesheet_link_tag', (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/hooks.php'));
    }

    public function testAuthorBrandingStaysSecuriace(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/banktransferpro.php');

        $this->assertStringContainsString("'author' => 'Securiace Technologies'", $config);
    }
}

final class SubjectSettings extends SettingsRepository
{
    public function __construct(private readonly string $template)
    {
    }

    public function ticketSubjectTemplate(): string
    {
        return $this->template;
    }
}
