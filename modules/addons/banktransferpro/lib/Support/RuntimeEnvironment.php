<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

use BankTransferPro\Bootstrap;

final class RuntimeEnvironment
{
    public static function isMutableApp(): bool
    {
        $value = getenv('WHMCS_MUTABLE_APP');
        if ($value === false) {
            return true;
        }

        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    public static function usesStaticGatewayMode(): bool
    {
        return ! self::isMutableApp();
    }

    public static function proofsBaseDirectory(): string
    {
        $override = trim((string) (getenv('BTP_PROOFS_DIR') ?: ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        if (self::usesStaticGatewayMode()) {
            return '/var/www/storage/banktransferpro/proofs';
        }

        return Bootstrap::addonRoot() . '/storage/proofs';
    }
}
