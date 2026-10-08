<?php

declare(strict_types=1);

namespace BankTransferPro\Packs\Qr;

/**
 * Builds the text a bank app expects inside an instant-alias QR code:
 *
 *  - UPI    NPCI deep link (upi://pay?...)             India, INR only
 *  - PayNow EMVCo merchant-presented payload (SGQR)    Singapore, SGD
 *  - Pix    EMVCo BR Code (static)                     Brazil, BRL
 *
 * Only the instant pack ever carries a QR. The international wire pack never does, so a
 * UPI/PayNow/Pix alias can not leak into a SWIFT instruction.
 *
 * The invoice amount is pre-filled only when the invoice currency equals the rail currency;
 * otherwise the QR carries the payee alone and the pack's "Send exactly" strip stays
 * authoritative.
 */
final class AliasQrPayload
{
    /** scheme id => rail currency */
    private const RAIL_CURRENCY = [
        'upi' => 'INR',
        'paynow' => 'SGD',
        'pix' => 'BRL',
    ];

    private const ISO_NUMERIC = [
        'SGD' => '702',
        'BRL' => '986',
    ];

    /**
     * @return list<string> scheme ids that can render a QR
     */
    public static function supportedSchemes(): array
    {
        return array_keys(self::RAIL_CURRENCY);
    }

    /**
     * @return array{scheme: string, payload: string, deeplink: ?string}|null
     */
    public static function build(
        string $scheme,
        string $alias,
        string $payeeName,
        ?string $amount,
        ?string $currency,
        string $reference
    ): ?array {
        $alias = trim($alias);
        if ($alias === '' || ! isset(self::RAIL_CURRENCY[$scheme])) {
            return null;
        }

        $railCurrency = self::RAIL_CURRENCY[$scheme];
        $amount = self::amountFor($amount, $currency, $railCurrency);

        $payload = match ($scheme) {
            'upi' => self::upi($alias, $payeeName, $amount, $reference),
            'paynow' => self::paynow($alias, $payeeName, $amount, $reference),
            default => self::pix($alias, $payeeName, $amount, $reference),
        };

        if ($payload === null || ! QrCode::fits($payload)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'payload' => $payload,
            'deeplink' => $scheme === 'upi' ? $payload : null,
        ];
    }

    public static function upi(string $vpa, string $payeeName, ?string $amount, string $reference): ?string
    {
        if (preg_match('/^[A-Za-z0-9._\-]{2,256}@[A-Za-z][A-Za-z0-9]{1,63}$/', $vpa) !== 1) {
            return null;
        }

        $params = ['pa' => $vpa];
        $name = trim(self::printable($payeeName));
        if ($name !== '') {
            $params['pn'] = $name;
        }
        if ($amount !== null) {
            $params['am'] = $amount;
            $params['cu'] = 'INR';
        }
        if ($reference !== '') {
            $params['tn'] = $reference;
        }

        $pairs = [];
        foreach ($params as $key => $value) {
            // NPCI examples keep "@" literal in the VPA; every other value is percent-encoded.
            $pairs[] = $key . '=' . ($key === 'pa' ? str_replace('%40', '@', rawurlencode($value)) : rawurlencode($value));
        }

        return 'upi://pay?' . implode('&', $pairs);
    }

    public static function paynow(string $proxy, string $payeeName, ?string $amount, string $reference): ?string
    {
        $proxy = preg_replace('/\s+/', '', $proxy) ?? '';
        if (preg_match('/^\+?\d{8,15}$/', $proxy) === 1) {
            $type = '0';
            $proxy = str_starts_with($proxy, '+') ? $proxy : '+65' . $proxy;
        } elseif (preg_match('/^[A-Za-z0-9]{9,10}$/', $proxy) === 1) {
            $type = '2';
            $proxy = strtoupper($proxy);
        } else {
            return null;
        }

        $account = self::tlv('00', 'SG.PAYNOW')
            . self::tlv('01', $type)
            . self::tlv('02', $proxy)
            . self::tlv('03', $amount !== null ? '0' : '1');

        $body = self::tlv('00', '01')
            . self::tlv('01', '12')
            . self::tlv('26', $account)
            . self::tlv('52', '0000')
            . self::tlv('53', self::ISO_NUMERIC['SGD'])
            . ($amount !== null ? self::tlv('54', $amount) : '')
            . self::tlv('58', 'SG')
            . self::tlv('59', self::merchantText($payeeName, 25, 'PAYEE'))
            . self::tlv('60', 'Singapore')
            . ($reference !== '' ? self::tlv('62', self::tlv('01', self::merchantText($reference, 25, '***'))) : '');

        return self::withCrc($body);
    }

    public static function pix(string $key, string $payeeName, ?string $amount, string $reference): ?string
    {
        if (preg_match('/^\S{5,77}$/', $key) !== 1) {
            return null;
        }

        $account = self::tlv('00', 'br.gov.bcb.pix') . self::tlv('01', $key);

        $txid = $reference !== '' ? self::billNumber($reference, 25) : '***';

        $body = self::tlv('00', '01')
            . self::tlv('26', $account)
            . self::tlv('52', '0000')
            . self::tlv('53', self::ISO_NUMERIC['BRL'])
            . ($amount !== null ? self::tlv('54', $amount) : '')
            . self::tlv('58', 'BR')
            . self::tlv('59', self::merchantText($payeeName, 25, 'RECEBEDOR'))
            . self::tlv('60', 'BRASIL')
            . self::tlv('62', self::tlv('05', $txid));

        return self::withCrc($body);
    }

    /**
     * CRC16/CCITT-FALSE (poly 0x1021, init 0xFFFF) as used by EMVCo, uppercase hex.
     */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public static function tlv(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    private static function withCrc(string $body): string
    {
        $body .= '6304';

        return $body . self::crc16($body);
    }

    private static function amountFor(?string $amount, ?string $currency, string $railCurrency): ?string
    {
        if ($amount === null || $currency === null || strtoupper($currency) !== $railCurrency) {
            return null;
        }

        $amount = trim($amount);
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) !== 1) {
            return null;
        }

        $value = (float) $amount;
        if ($value <= 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    /**
     * EMVCo merchant text is ASCII; strip diacritics where iconv can, then keep safe characters.
     */
    private static function merchantText(string $value, int $max, string $fallback): string
    {
        $clean = strtoupper(self::printable($value));
        $clean = trim(preg_replace('/[^A-Z0-9 .\-]/', '', $clean) ?? '');
        $clean = substr($clean, 0, $max);

        return $clean !== '' ? $clean : $fallback;
    }

    private static function printable(string $value): string
    {
        if (function_exists('iconv') && preg_match('/[^\x20-\x7E]/', $value) === 1) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }

    /**
     * EMVCo reference labels / Pix txid are alphanumeric only.
     */
    private static function billNumber(string $reference, int $max): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '';

        return $clean === '' ? '***' : substr($clean, 0, $max);
    }
}
