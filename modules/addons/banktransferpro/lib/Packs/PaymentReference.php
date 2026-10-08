<?php

declare(strict_types=1);

namespace BankTransferPro\Packs;

/**
 * Short, SWIFT-safe payment reference shared by every instruction pack.
 *
 * Format: BTP-{invoiceId}-{check}
 *   - at most MAX_LENGTH characters (MT103 field 70 is 4x35; domestic remark
 *     fields are often 20-30), so it survives truncation far better than a
 *     full invoice number.
 *   - characters limited to A-Z, 0-9 and "-" (inside the SWIFT X charset).
 *   - the trailing check character catches typos and bank scrubbing, and
 *     lets support validate a reference without a database lookup.
 */
final class PaymentReference
{
    public const PREFIX = 'BTP';
    public const MAX_LENGTH = 20;
    public const SWIFT_LINE_LENGTH = 35;

    /** Crockford-style base32 (no I, L, O, U) so the check char is unambiguous. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function mint(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invoice id must be positive to mint a payment reference.');
        }

        $reference = self::PREFIX . '-' . $invoiceId . '-' . self::checkCharacter($invoiceId);
        if (strlen($reference) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Invoice id is too large for a SWIFT-safe payment reference.');
        }

        return $reference;
    }

    /**
     * Canonicalise user/bank-mangled input (case, spaces, unicode dashes, missing hyphens).
     * Returns null when the input is not a structurally valid reference.
     */
    public static function normalize(string $input): ?string
    {
        $clean = strtoupper($input);
        $clean = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $clean);
        $clean = preg_replace('/[\s_]+/u', '', $clean) ?? '';

        if (preg_match('/^' . self::PREFIX . '-?(\d{1,14})-?([0-9A-Z])$/', $clean, $m) !== 1) {
            return null;
        }

        $invoiceId = (int) $m[1];
        if ($invoiceId <= 0 || self::checkCharacter($invoiceId) !== $m[2]) {
            return null;
        }

        return self::mint($invoiceId);
    }

    public static function validate(string $input, ?int $invoiceId = null): bool
    {
        $canonical = self::normalize($input);
        if ($canonical === null) {
            return false;
        }

        return $invoiceId === null || self::invoiceIdFrom($canonical) === $invoiceId;
    }

    public static function invoiceIdFrom(string $input): ?int
    {
        $canonical = self::normalize($input);
        if ($canonical === null) {
            return null;
        }

        $parts = explode('-', $canonical);

        return (int) $parts[1];
    }

    /**
     * Find a reference inside free-form remittance text (e.g. a bank narration).
     */
    public static function extractFrom(string $text): ?string
    {
        if (preg_match('/BTP[\s\-\x{2010}-\x{2015}]?\d{1,14}[\s\-\x{2010}-\x{2015}]?[0-9A-Za-z]/iu', $text, $m) !== 1) {
            return null;
        }

        return self::normalize($m[0]);
    }

    /**
     * True when the value fits a single SWIFT field-70 line using only the SWIFT "X" charset.
     */
    public static function isSwiftSafe(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::SWIFT_LINE_LENGTH
            && preg_match("~^[A-Za-z0-9/\\-?:().,'+ ]+$~", $value) === 1;
    }

    private static function checkCharacter(int $invoiceId): string
    {
        $sum = 0;
        foreach (str_split(strrev((string) $invoiceId)) as $position => $digit) {
            $sum += ((int) $digit + 1) * ($position + 3);
        }

        return self::ALPHABET[$sum % 32];
    }
}
