<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Gateway\GatewayFileWriter;
use BankTransferPro\Gateway\GatewayPathResolver;
use PHPUnit\Framework\TestCase;

final class GatewayFileWriterTest extends TestCase
{
    private string $tempGatewaysDir;

    protected function setUp(): void
    {
        $this->tempGatewaysDir = sys_get_temp_dir() . '/btp-gw-' . bin2hex(random_bytes(4));
        mkdir($this->tempGatewaysDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempGatewaysDir . '/*.php') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempGatewaysDir);
    }

    public function testStubRendersValidGatewayFunctions(): void
    {
        $resolver = new TestGatewayPathResolver($this->tempGatewaysDir);
        $writer = new GatewayFileWriter($resolver);
        $slug = 'banktransferpro_acme_usd';
        $path = $writer->write($slug, 'Bank Transfer Pro — Acme — Main');

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString("function {$slug}_MetaData", $content);
        $this->assertStringContainsString("function {$slug}_config", $content);
        $this->assertStringContainsString("function {$slug}_link", $content);
        $this->assertStringContainsString('GatewayRenderer::render', $content);
    }

    public function testDeleteRemovesGeneratedGatewayFile(): void
    {
        $resolver = new TestGatewayPathResolver($this->tempGatewaysDir);
        $writer = new GatewayFileWriter($resolver);
        $slug = 'banktransferpro_delete_me';
        $path = $writer->write($slug, 'Bank Transfer Pro — Delete Me');

        $writer->delete($slug);

        $this->assertFileDoesNotExist($path);
    }
}

final class TestGatewayPathResolver extends GatewayPathResolver
{
    public function __construct(private string $dir)
    {
    }

    public function gatewaysDirectory(): string
    {
        return $this->dir;
    }
}
