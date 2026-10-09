<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Cache-bust and addon version must move together, otherwise stale admin/client assets
 * survive a deploy (the class of regression behind earlier "fixed but still broken" reports).
 */
final class ReleaseVersionContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/modules/addons/banktransferpro/' . $relative);
        $this->assertIsString($contents);

        return $contents;
    }

    public function testAddonVersionAndAssetCacheBustAreIdentical(): void
    {
        $this->assertSame(1, preg_match("/'version' => '([0-9.]+)'/", $this->read('banktransferpro.php'), $addon));
        $this->assertSame(1, preg_match("/define\\('BTP_ADDON_ASSET_VERSION', '([0-9.]+)'\\)/", $this->read('hooks.php'), $assets));

        $this->assertSame($addon[1], $assets[1]);
        $this->assertTrue(version_compare($addon[1], '1.2.2', '>='), 'Payee-name hotfix ships as 1.2.2 or later.');
    }

    public function testAdminFlagsLegacyProfilesWithoutPayeeName(): void
    {
        $js = $this->read('assets/js/admin.js');

        $this->assertStringContainsString('No payee name', $js);
        $this->assertStringContainsString('btp-cap-badge--warn', $this->read('assets/css/admin.css'));
    }

    public function testEditAndPreviewShareTheLegacyPayeeContext(): void
    {
        $ajax = $this->read('lib/Admin/AjaxController.php');

        $this->assertStringContainsString("validatedPayload(\$existing)", $ajax);
        $this->assertStringContainsString('legacy_missing_payee', $ajax);
        $this->assertStringContainsString("\$input['id']", $ajax);
    }
}
