<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway;

final class GatewayActivator
{
    public function activateStaticGateway(): void
    {
        $slug = 'banktransferpro';
        $gateway = Gateway::factory($slug);
        if (! $gateway->load($slug)) {
            throw new \RuntimeException('Gateway module file could not be loaded: ' . $slug);
        }

        if (! $gateway->isActiveGateway($slug)) {
            $gateway->activate();
        }

        $this->saveSetting($slug, 'name', 'Bank Transfer Pro');
        $this->saveSetting($slug, 'type', Gateway::GATEWAY_BANK);
        $this->saveSetting($slug, 'visible', 'on');
        Capsule::table('tblpaymentgateways')
            ->where('gateway', $slug)
            ->where('setting', 'convertto')
            ->delete();
    }

    public function activate(string $slug, string $displayName, string $currencyCode): void
    {
        $gateway = Gateway::factory($slug);
        if (! $gateway->load($slug)) {
            throw new \RuntimeException('Gateway module file could not be loaded: ' . $slug);
        }

        if (! $gateway->isActiveGateway($slug)) {
            $gateway->activate();
        }

        $currencyId = $this->resolveCurrencyId($currencyCode);

        $this->saveSetting($slug, 'name', $displayName);
        $this->saveSetting($slug, 'type', Gateway::GATEWAY_BANK);
        $this->saveSetting($slug, 'visible', 'on');

        if ($currencyId !== null) {
            $this->saveSetting($slug, 'convertto', (string) $currencyId);
        }
    }

    public function updateSettings(string $slug, string $displayName, string $currencyCode): void
    {
        $this->saveSetting($slug, 'name', $displayName);

        $currencyId = $this->resolveCurrencyId($currencyCode);
        if ($currencyId !== null) {
            $this->saveSetting($slug, 'convertto', (string) $currencyId);
        }
    }

    public function deactivate(string $slug, ?string $fallbackGateway = null): void
    {
        $fallback = $fallbackGateway ?? $this->resolveFallbackGateway($slug);

        $gateway = Gateway::factory($slug);
        if (! $gateway->load($slug)) {
            $this->deleteGatewaySettings($slug);

            return;
        }

        if ($gateway->isActiveGateway($slug)) {
            $gateway->deactivate([
                'oldGateway' => $slug,
                'newGateway' => $fallback,
                'newGatewayName' => $this->resolveGatewayDisplayName($fallback),
            ]);
        } else {
            $this->deleteGatewaySettings($slug);
        }
    }

    private function saveSetting(string $gateway, string $setting, string $value): void
    {
        Capsule::table('tblpaymentgateways')->updateOrInsert(
            ['gateway' => $gateway, 'setting' => $setting],
            ['value' => $value]
        );
    }

    private function deleteGatewaySettings(string $gateway): void
    {
        Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->delete();
    }

    private function resolveCurrencyId(string $currencyCode): ?int
    {
        $row = Capsule::table('tblcurrencies')
            ->where('code', strtoupper(trim($currencyCode)))
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        return (int) $row->id;
    }

    private function resolveFallbackGateway(string $excludeSlug): string
    {
        $candidates = ['banktransfer', 'mailin'];

        foreach ($candidates as $candidate) {
            if ($candidate !== $excludeSlug && $this->isActiveGateway($candidate)) {
                return $candidate;
            }
        }

        $active = Capsule::table('tblpaymentgateways')
            ->where('setting', 'visible')
            ->where('value', 'on')
            ->pluck('gateway')
            ->all();

        foreach ($active as $gateway) {
            if ($gateway !== $excludeSlug && is_string($gateway)) {
                return $gateway;
            }
        }

        return 'banktransfer';
    }

    private function isActiveGateway(string $slug): bool
    {
        return Capsule::table('tblpaymentgateways')
            ->where('gateway', $slug)
            ->where('setting', 'visible')
            ->where('value', 'on')
            ->exists();
    }

    private function resolveGatewayDisplayName(string $slug): string
    {
        $row = Capsule::table('tblpaymentgateways')
            ->where('gateway', $slug)
            ->where('setting', 'name')
            ->first(['value']);

        if ($row !== null && is_string($row->value) && $row->value !== '') {
            return $row->value;
        }

        return $slug;
    }
}
