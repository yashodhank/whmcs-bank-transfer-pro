<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Bootstrap;

class GatewayPathResolver
{
    public function gatewaysDirectory(): string
    {
        return Bootstrap::whmcsRoot() . '/modules/gateways';
    }

    public function gatewayFilePath(string $slug): string
    {
        return $this->gatewaysDirectory() . '/' . $slug . '.php';
    }

    public function isGatewaysDirectoryWritable(): bool
    {
        $dir = $this->gatewaysDirectory();

        if (! is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }
}
