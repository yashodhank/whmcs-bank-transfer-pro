<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

/**
 * WHMCS HTML-entity-encodes every value in $_GET/$_POST/$_REQUEST during init
 * (quotes become &quot;, & becomes &amp;). That silently breaks JSON payloads and
 * double-encodes free text once we escape on output, so admin input is decoded here
 * and escaped exactly once at render time.
 */
final class WhmcsInput
{
    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public static function decode(array $input): array
    {
        $decoded = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $decoded[$key] = self::decode($value);
            } elseif (is_string($value)) {
                $decoded[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                $decoded[$key] = $value;
            }
        }

        return $decoded;
    }
}
