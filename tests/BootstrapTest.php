<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testWhmcsRootResolvesProjectRoot(): void
    {
        $expected = dirname(__DIR__);

        $this->assertSame($expected, Bootstrap::whmcsRoot());
    }

    public function testAddonRootResolvesAddonDirectory(): void
    {
        $expected = __DIR__ . '/../modules/addons/banktransferpro';

        $this->assertSame(realpath($expected), realpath(Bootstrap::addonRoot()));
    }
}
