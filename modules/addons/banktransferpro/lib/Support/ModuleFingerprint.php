<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

/**
 * Install/runtime capability marker so stale overlays fail activation and validate-install.
 */
final class ModuleFingerprint
{
    public const VERSION = '1.1.3';

    public const CAPABILITY_INVOICE_CURRENCY_VIA_CLIENT = 'invoice_currency_via_client';
    public const CAPABILITY_INSTRUCTION_PACKS = 'instruction_packs';

    /**
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return [
            self::CAPABILITY_INVOICE_CURRENCY_VIA_CLIENT,
            self::CAPABILITY_INSTRUCTION_PACKS,
        ];
    }

    public static function hasCapability(string $capability): bool
    {
        return in_array($capability, self::capabilities(), true);
    }

    public static function isHealthy(): bool
    {
        return self::failureReason() === null;
    }

    public static function failureReason(): ?string
    {
        if (! class_exists(InvoiceCurrencyResolver::class)) {
            return 'InvoiceCurrencyResolver is missing; redeploy the full modules/addons/banktransferpro tree.';
        }

        if (! self::hasCapability(self::CAPABILITY_INVOICE_CURRENCY_VIA_CLIENT)) {
            return 'Capability marker invoice_currency_via_client is missing; module tree is incomplete or stale.';
        }

        $rendererPath = dirname(__DIR__) . '/Gateway/GatewayRenderer.php';
        if (! is_file($rendererPath)) {
            return 'GatewayRenderer.php is missing; redeploy the full addon tree.';
        }

        $renderer = file_get_contents($rendererPath);
        if (! is_string($renderer) || $renderer === '') {
            return 'GatewayRenderer.php could not be read.';
        }

        if (
            str_contains($renderer, "first(['currency'])")
            || str_contains($renderer, 'first(["currency"])')
            || preg_match(
                "/table\(\s*['\"]tblinvoices['\"]\s*\)(?:(?!table\().){0,200}first\(\s*\[\s*['\"]currency['\"]/",
                $renderer
            ) === 1
        ) {
            return 'GatewayRenderer still contains the banned tblinvoices.currency lookup; redeploy a current artifact (>= '
                . self::VERSION . ').';
        }

        if (! str_contains($renderer, 'InvoiceCurrencyResolver')) {
            return 'GatewayRenderer is not wired to InvoiceCurrencyResolver; redeploy a current artifact (>= '
                . self::VERSION . ').';
        }

        $configPath = dirname(__DIR__, 2) . '/banktransferpro.php';
        if (! is_file($configPath)) {
            return 'banktransferpro.php is missing.';
        }

        $config = file_get_contents($configPath);
        if (! is_string($config) || ! preg_match("/'version'\\s*=>\\s*'([^']+)'/", $config, $match)) {
            return 'Addon version string could not be read from banktransferpro_config.';
        }

        if (version_compare($match[1], self::VERSION, '<')) {
            return 'Addon version ' . $match[1] . ' is older than required ' . self::VERSION . '; pin and redeploy the release artifact.';
        }

        return null;
    }
}
