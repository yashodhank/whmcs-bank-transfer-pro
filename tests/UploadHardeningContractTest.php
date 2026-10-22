<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Defense-in-depth contracts for CVE-2026-109618 / CVE-2026-109619 exposure
 * via the client payment-proof upload path and admin JSON API.
 */
final class UploadHardeningContractTest extends TestCase
{
    public function testUploadControllerRejectsOversizedContentLengthBeforeProcessing(): void
    {
        $source = $this->readModule('lib/Client/UploadController.php');

        $this->assertStringContainsString('assertRequestSizeWithinLimit', $source);
        $this->assertStringContainsString('CONTENT_LENGTH', $source);
        $this->assertStringContainsString('maxUploadRequestBytes', $source);
        $this->assertStringContainsString("PAYLOAD_TOO_LARGE", $source);
        $this->assertLessThan(
            (int) strpos($source, 'assertClientLogin'),
            (int) strpos($source, 'assertRequestSizeWithinLimit'),
            'Size gate must run before login/invoice work.'
        );
    }

    public function testUploadControllerRejectsMultiFileProofFields(): void
    {
        $source = $this->readModule('lib/Client/UploadController.php');

        $this->assertStringContainsString('requireSingleProofFile', $source);
        $this->assertStringContainsString('Only one payment proof file may be uploaded.', $source);
    }

    public function testUploadControllerAndClientAreaCatchThrowable(): void
    {
        $controller = $this->readModule('lib/Client/UploadController.php');
        $entry = $this->readModule('banktransferpro.php');

        $this->assertMatchesRegularExpression('/catch\s*\(\s*\\\\Throwable/', $controller);
        $this->assertStringContainsString("JsonResponse::error('UPLOAD_FAILED'", $controller);
        $this->assertStringContainsString('Payment proof upload failed.', $controller);
        $this->assertMatchesRegularExpression(
            '/upload-proof[\s\S]{0,400}catch\s*\(\s*Throwable/',
            $entry
        );
    }

    public function testAdminApiEntryCatchesThrowable(): void
    {
        $dashboard = $this->readModule('lib/Admin/DashboardController.php');
        $ajax = $this->readModule('lib/Admin/AjaxController.php');

        $this->assertMatchesRegularExpression(
            '/action\'\]\s*===\s*\'api\'[\s\S]{0,300}catch\s*\(\s*\\\\Throwable/',
            $dashboard
        );
        $this->assertMatchesRegularExpression(
            '/function handle\(\): void\s*\{\s*try\s*\{/',
            $ajax
        );
        $this->assertStringContainsString("JsonResponse::error('SERVER_ERROR'", $ajax);
    }

    public function testUploadSizeHardCapIsEnforced(): void
    {
        $this->assertSame(10, SettingsRepository::MAX_UPLOAD_SIZE_MB_HARD_CAP);

        $settings = new class extends SettingsRepository {
            public function get(string $key, ?string $default = null): ?string
            {
                return $key === 'max_upload_size_mb' ? '99' : $default;
            }
        };

        $this->assertSame(10, $settings->maxUploadSizeMb());
        $this->assertSame(10 * 1024 * 1024, $settings->maxUploadSizeBytes());
        $this->assertSame(
            (10 * 1024 * 1024) + SettingsRepository::UPLOAD_REQUEST_OVERHEAD_BYTES,
            $settings->maxUploadRequestBytes()
        );

        $dashboard = $this->readModule('lib/Admin/DashboardController.php');
        $this->assertStringContainsString('MAX_UPLOAD_SIZE_MB_HARD_CAP', $dashboard);
    }

    public function testComposerAndCiDropUnpatchedPhp81(): void
    {
        $composer = (string) file_get_contents(dirname(__DIR__) . '/composer.json');
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/phpunit.yml');

        $this->assertStringContainsString('"php": ">=8.2"', $composer);
        $this->assertStringNotContainsString("'8.1'", $workflow);
        $this->assertStringContainsString("'8.2'", $workflow);
        $this->assertStringContainsString("'8.3'", $workflow);
        $this->assertStringContainsString("'8.4'", $workflow);
    }

    private function readModule(string $relativePath): string
    {
        $path = dirname(__DIR__) . '/modules/addons/banktransferpro/' . $relativePath;
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
