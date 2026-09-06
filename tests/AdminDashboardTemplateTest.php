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
        $this->assertStringContainsString("payload.token = csrfToken;", $script);
        $this->assertStringContainsString("'X-CSRF-Token': csrfToken", $script);
        $this->assertStringContainsString('csrfToken: {$csrfToken|@json_encode nofilter},', $template);
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
}
