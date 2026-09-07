<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('WHMCS')) {
            define('WHMCS', true);
        }

        require_once __DIR__ . '/fixtures/global-hook-stubs.php';
        require_once __DIR__ . '/../modules/addons/banktransferpro/hooks.php';
    }

    public function testClientStylesheetLinkRendersWithoutBootstrapInit(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="modules/addons/banktransferpro/assets/css/client.css?v=' . urlencode(BTP_ADDON_ASSET_VERSION) . '" />' . "\n",
            btp_client_stylesheet_link_tag()
        );
    }

    public function testAdminStylesheetLinkUsesAdminRelativePath(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="../modules/addons/banktransferpro/assets/css/admin.css?v=' . urlencode(BTP_ADDON_ASSET_VERSION) . '" />' . "\n",
            btp_stylesheet_link_tag()
        );
    }

    public function testAssetVersionConstantMatchesAddonVersion(): void
    {
        $config = file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/banktransferpro.php');
        $this->assertIsString($config);
        $this->assertStringContainsString("'version' => '" . BTP_ADDON_ASSET_VERSION . "'", $config);
    }
}
