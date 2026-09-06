<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Client\PaymentProofUploader;
use BankTransferPro\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class PaymentProofUploaderTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WHMCS_MUTABLE_APP');
        putenv('BTP_PROOFS_DIR');
    }

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

    public function testUsesConfiguredProofsDirectoryOverride(): void
    {
        putenv('BTP_PROOFS_DIR=/srv/btp-proofs');

        $uploader = new PaymentProofUploader(new TestSettingsRepository(maxBytes: 1024, allowedMime: ['image/png']));

        $this->assertSame('/srv/btp-proofs/42', $uploader->storageDirectory(42));
    }

    public function testUsesImmutableDefaultProofsDirectoryWhenAppIsReadOnly(): void
    {
        putenv('WHMCS_MUTABLE_APP=false');

        $uploader = new PaymentProofUploader(new TestSettingsRepository(maxBytes: 1024, allowedMime: ['image/png']));

        $this->assertSame('/var/www/storage/banktransferpro/proofs/7', $uploader->storageDirectory(7));
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
