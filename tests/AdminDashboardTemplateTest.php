<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

final class AdminDashboardTemplateTest extends TestCase
{
    public function testDeleteRequestsCarryExplicitConfirmationFlag(): void
    {
        $script = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/assets/js/admin.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('confirm_delete: true', $script);
    }

    public function testAdminScriptUsesPlainCsrfTokenConfig(): void
    {
        $script = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/assets/js/admin.js');
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');

        $this->assertIsString($script);
        $this->assertIsString($template);
        $this->assertStringContainsString("var csrfToken = config.csrfToken || config.adminToken || '';", $script);
        $this->assertStringContainsString("body.append('token', csrfToken)", $script);
        $this->assertStringContainsString('csrfToken: {$csrfToken|@json_encode nofilter},', $template);
    }

    public function testSaveErrorsRenderInsideModal(): void
    {
        $script = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/assets/js/admin.js');
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');

        $this->assertIsString($script);
        $this->assertIsString($template);
        $this->assertStringContainsString('id="btp-bank-modal-alert"', $template);
        $this->assertStringContainsString("showAlert('danger', response.error ? response.error.message : 'Save failed.', 'modal');", $script);
        $this->assertStringContainsString("showAlert('danger', error && error.message ? error.message : 'Save failed.', 'modal');", $script);
    }

    public function testAdminScriptPathDoesNotUrlEncodeAssetBase(): void
    {
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            '<script src="{$adminAssetBaseUrl|escape:\'html\'}/js/admin.js?v={$assetVersion|escape:\'url\'}"></script>',
            $template
        );
        $this->assertStringNotContainsString(
            '{$adminAssetBaseUrl|escape:\'url\'}/js/admin.js',
            $template
        );
    }

    public function testDashboardFormIncludesStructuredInvoiceFields(): void
    {
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');
        $script = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/assets/js/admin.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString('name="invoice_label"', $template);
        $this->assertStringContainsString('name="upi_id"', $template);
        $this->assertStringContainsString('name="account_name"', $template);
        $this->assertStringContainsString('name="account_number"', $template);
        $this->assertStringContainsString('name="ifsc_code"', $template);
        $this->assertStringContainsString("invoice_label: document.getElementById('btp-invoice-label').value", $script);
        $this->assertStringContainsString("upi_id: document.getElementById('btp-upi-id').value", $script);
    }
}
