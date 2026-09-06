<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Repository\ProofRepository;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\RuntimeEnvironment;

final class PaymentProofUploader
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly ProofRepository $proofs = new ProofRepository()
    ) {
    }

    /**
     * @return array{stored_filename: string, original_filename: string, mime: string, size: int, path: string}
     */
    public function store(int $clientId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('File upload failed.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->settings->maxUploadSizeBytes()) {
            throw new \InvalidArgumentException('File exceeds the maximum allowed size.');
        }

        $mime = $this->detectMimeType((string) ($file['tmp_name'] ?? ''));
        if (! in_array($mime, $this->settings->allowedMimeTypes(), true)) {
            throw new \InvalidArgumentException('File type is not allowed.');
        }

        $original = basename((string) ($file['name'] ?? 'upload.bin'));
        $extension = $this->extensionForMime($mime);
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = $this->storageDirectory($clientId);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create storage directory.');
        }

        $target = $directory . '/' . $stored;
        if (! move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }

        return [
            'stored_filename' => $stored,
            'original_filename' => $original,
            'mime' => $mime,
            'size' => $size,
            'path' => $target,
        ];
    }

    public function hasRecentProof(int $invoiceId): bool
    {
        return $this->proofs->hasRecentProof($invoiceId, $this->settings->uploadCooldownHours());
    }

    public function storageDirectory(int $clientId): string
    {
        return RuntimeEnvironment::proofsBaseDirectory() . '/' . $clientId;
    }

    private function detectMimeType(string $tmpPath): string
    {
        if ($tmpPath === '' || ! is_file($tmpPath)) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $tmpPath) ?: 'application/octet-stream';
        if (PHP_VERSION_ID < 80500) {
            finfo_close($finfo);
        }

        return $mime;
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
