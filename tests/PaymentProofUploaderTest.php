<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Client\PaymentProofUploader;
use BankTransferPro\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class PaymentProofUploaderTest extends TestCase
{
    public function testRejectsOversizedUpload(): void
    {
        $settings = new TestSettingsRepository(maxBytes: 1024, allowedMime: ['image/png']);
        $uploader = new PaymentProofUploader($settings);

        $tmp = tempnam(sys_get_temp_dir(), 'btp');
        $this->assertIsString($tmp);
        file_put_contents($tmp, str_repeat('a', 2048));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $uploader->store(1, [
                'error' => UPLOAD_ERR_OK,
                'size' => 2048,
                'tmp_name' => $tmp,
                'name' => 'proof.png',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $settings = new TestSettingsRepository(maxBytes: 5 * 1024 * 1024, allowedMime: ['image/png']);
        $uploader = new PaymentProofUploader($settings);

        $tmp = tempnam(sys_get_temp_dir(), 'btp');
        $this->assertIsString($tmp);
        file_put_contents($tmp, '%PDF-1.4');

        $this->expectException(\InvalidArgumentException::class);
        try {
            $uploader->store(1, [
                'error' => UPLOAD_ERR_OK,
                'size' => 8,
                'tmp_name' => $tmp,
                'name' => 'proof.pdf',
            ]);
        } finally {
            @unlink($tmp);
        }
    }
}

final class TestSettingsRepository extends SettingsRepository
{
    /**
     * @param list<string> $allowedMime
     */
    public function __construct(
        private int $maxBytes,
        private array $allowedMime
    ) {
    }

    public function maxUploadSizeBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return $this->allowedMime;
    }
}
