<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

final class AdminDashboardTemplateTest extends TestCase
{
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
