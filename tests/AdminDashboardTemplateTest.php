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

    public function testDashboardIsAReceiveProfileWizard(): void
    {
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');
        $script = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/assets/js/admin.js');

        $this->assertIsString($template);
        $this->assertIsString($script);

        foreach ([
            'name="country_code"',
            'name="currency_code"',
            'name="account_name"',
            'name="account_number"',
            'name="invoice_label"',
            'name="beneficiary_address"',
            'name="prefer_charge_code"',
            'name="accept_fx_receive"',
            'name="wire_purpose_hint"',
            'data-btp-cap="local_transfer"',
            'data-btp-cap="instant_alias"',
            'data-btp-cap="international_wire"',
            'id="btp-preview"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $template);
        }

        $this->assertStringContainsString('registry: {$registry|@json_encode nofilter}', $template);
        $this->assertStringContainsString("capabilities: enabledCapabilities().join(',')", $script);
        $this->assertStringContainsString("identifiers: JSON.stringify(collectIdentifiers())", $script);
        $this->assertStringContainsString("apiRequest('preview', 'POST', buildPayload())", $script);
    }

    public function testWizardNoLongerShipsAlwaysVisibleSchemeColumns(): void
    {
        $template = file_get_contents(__DIR__ . '/../modules/addons/banktransferpro/templates/admin/dashboard.tpl');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('name="upi_id"', $template);
        $this->assertStringNotContainsString('name="ifsc_code"', $template);
    }
}
