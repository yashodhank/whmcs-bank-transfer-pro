<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

final class AssetUrl
{
    private const ADMIN_BASE = '../modules/addons/banktransferpro/assets';
    private const CLIENT_BASE = 'modules/addons/banktransferpro/assets';

    public static function admin(string $relativePath = ''): string
    {
        return self::build(self::ADMIN_BASE, $relativePath);
    }

    public static function client(string $relativePath = ''): string
    {
        return self::build(self::CLIENT_BASE, $relativePath);
    }

    private static function build(string $base, string $relativePath): string
    {
        $normalized = ltrim($relativePath, '/');
        if ($normalized === '') {
            return $base;
        }

        return $base . '/' . $normalized;
    }
}
