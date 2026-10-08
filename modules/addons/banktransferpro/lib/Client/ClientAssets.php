<?php

declare(strict_types=1);

namespace BankTransferPro\Client;

use BankTransferPro\Support\AssetUrl;

/**
 * Stock WHMCS viewinvoice templates only print {$paymentbutton}; they never emit
 * head/footer hook output. The gateway link therefore carries its own stylesheet
 * and script so the module stays independently deployable (no theme overrides).
 */
final class ClientAssets
{
    private static bool $emitted = false;

    public static function reset(): void
    {
        self::$emitted = false;
    }

    /**
     * @param array<string, mixed> $params WHMCS gateway link params
     */
    public static function tags(array $params, string $version): string
    {
        if (self::$emitted) {
            return '';
        }
        self::$emitted = true;

        $root = trim((string) ($params['systemurl'] ?? ''));
        $prefix = $root !== '' ? rtrim($root, '/') . '/' : '';
        $v = rawurlencode($version);
        $css = htmlspecialchars($prefix . AssetUrl::client('css/client.css') . '?v=' . $v, ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars($prefix . AssetUrl::client('js/client.js') . '?v=' . $v, ENT_QUOTES, 'UTF-8');

        return '<link rel="stylesheet" href="' . $css . '" />' . "\n"
            . '<script src="' . $js . '" defer></script>' . "\n";
    }
}
