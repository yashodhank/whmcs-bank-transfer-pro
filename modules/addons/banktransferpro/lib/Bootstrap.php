<?php

declare(strict_types=1);

namespace BankTransferPro;

final class Bootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $candidates = [
            dirname(__DIR__, 4) . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                self::$initialized = true;

                return;
            }
        }

        throw new \RuntimeException(
            'Bank Transfer Pro: Composer autoload not found. Run `composer install` at the repository root.'
        );
    }

    public static function addonRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function whmcsRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
