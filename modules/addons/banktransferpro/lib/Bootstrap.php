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
            // Prefer addon-local vendor so a stock WHMCS root autoload does not
            // shadow BankTransferPro when the module is overlaid into an install.
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
        ];

        $loaded = false;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                $loaded = true;
                break;
            }
        }

        // Stock WHMCS vendor/autoload.php never maps BankTransferPro; always
        // register a PSR-4 fallback for this addon's lib/ tree.
        self::registerPsr4Fallback();

        if (!$loaded && !is_dir(dirname(__DIR__) . '/lib')) {
            throw new \RuntimeException(
                'Bank Transfer Pro: Composer autoload not found. Run `composer install` at the repository root.'
            );
        }

        self::$initialized = true;
    }

    private static function registerPsr4Fallback(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $base = dirname(__DIR__) . '/lib/';
        spl_autoload_register(static function (string $class) use ($base): void {
            $prefix = 'BankTransferPro\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $base . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public static function addonRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function whmcsRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
