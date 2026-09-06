<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Support\AssetUrl;
use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
    public function testAdminAssetsResolveRelativeToAdminDirectory(): void
    {
        $this->assertSame(
            '../modules/addons/banktransferpro/assets/js/admin.js',
            AssetUrl::admin('js/admin.js')
        );
    }

    public function testClientAssetsResolveRelativeToWhmcsRoot(): void
    {
        $this->assertSame(
            'modules/addons/banktransferpro/assets/css/client.css',
            AssetUrl::client('css/client.css')
        );
    }
}
