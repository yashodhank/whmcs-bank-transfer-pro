<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Bootstrap;

final class GatewayFileWriter
{
    public function __construct(
        private readonly GatewayPathResolver $pathResolver = new GatewayPathResolver()
    ) {
    }

    public function write(string $slug, string $displayName): string
    {
        $stubPath = Bootstrap::addonRoot() . '/resources/gateway.stub';
        if (! is_file($stubPath)) {
            throw new \RuntimeException('Gateway stub template missing.');
        }

        $template = (string) file_get_contents($stubPath);
        $content = str_replace(
            ['{{SLUG}}', '{{DISPLAY_NAME}}', '{{DISPLAY_NAME_ESCAPED}}'],
            [
                $slug,
                $displayName,
                addslashes($displayName),
            ],
            $template
        );

        $path = $this->pathResolver->gatewayFilePath($slug);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            throw new \RuntimeException('Gateway directory does not exist: ' . $dir);
        }

        if (! is_writable($dir)) {
            throw new \RuntimeException('Gateway directory is not writable: ' . $dir);
        }

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Failed to write gateway file: ' . $path);
        }

        return $path;
    }

    public function delete(string $slug): void
    {
        $path = $this->pathResolver->gatewayFilePath($slug);
        if (is_file($path)) {
            if (! unlink($path) && is_file($path)) {
                throw new \RuntimeException('Failed to delete gateway file: ' . $path);
            }
        }
    }

    public function exists(string $slug): bool
    {
        return is_file($this->pathResolver->gatewayFilePath($slug));
    }
}
