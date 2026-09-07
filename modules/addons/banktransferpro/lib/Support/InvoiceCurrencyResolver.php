<?php

declare(strict_types=1);

namespace BankTransferPro\Support;

use WHMCS\Database\Capsule;

class InvoiceCurrencyResolver
{
    /**
     * @param array<string, mixed> $params
     */
    public function codeForGatewayLink(array $params): ?string
    {
        $fromParams = $this->codeFromGatewayParams($params);
        if ($fromParams !== null) {
            return $fromParams;
        }

        return $this->codeFromInvoiceId((int) ($params['invoiceid'] ?? 0));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function codeFromGatewayParams(array $params): ?string
    {
        return self::normalizeCode($params['currency'] ?? null);
    }

    public function codeFromInvoiceRecord(object $invoice): ?string
    {
        return $this->codeFromClientId((int) ($invoice->userid ?? 0));
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function codeFromHookVars(array $vars): ?string
    {
        $fromParams = $this->codeFromGatewayParams($vars);
        if ($fromParams !== null) {
            return $fromParams;
        }

        $currencyId = is_numeric($vars['currency'] ?? null) ? (int) $vars['currency'] : 0;
        if ($currencyId > 0) {
            $code = $this->codeFromCurrencyId($currencyId);
            if ($code !== null) {
                return $code;
            }
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId > 0) {
            $code = $this->codeFromInvoiceId($invoiceId);
            if ($code !== null) {
                return $code;
            }
        }

        $userId = (int) ($vars['userid'] ?? $vars['user'] ?? 0);

        return $this->codeFromClientId($userId);
    }

    public function codeFromInvoiceId(int $invoiceId): ?string
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $userId = $this->loadInvoiceUserId($invoiceId);
        if ($userId === null || $userId <= 0) {
            return null;
        }

        return $this->codeFromClientId($userId);
    }

    public function codeFromClientId(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $currencyId = $this->loadClientCurrencyId($userId);
        if ($currencyId === null || $currencyId <= 0) {
            return null;
        }

        return $this->codeFromCurrencyId($currencyId);
    }

    public function codeFromCurrencyId(int $currencyId): ?string
    {
        if ($currencyId <= 0) {
            return null;
        }

        return $this->loadCurrencyCode($currencyId);
    }

    public static function normalizeCode(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['code'] ?? $value['iso_code'] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));
        if ($code === '' || strlen($code) !== 3 || ! ctype_alpha($code)) {
            return null;
        }

        return $code;
    }

    protected function loadInvoiceUserId(int $invoiceId): ?int
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid']);
        if ($invoice === null) {
            return null;
        }

        return (int) ($invoice->userid ?? 0);
    }

    protected function loadClientCurrencyId(int $userId): ?int
    {
        $client = Capsule::table('tblclients')->where('id', $userId)->first(['currency']);
        if ($client === null) {
            return null;
        }

        return (int) ($client->currency ?? 0);
    }

    protected function loadCurrencyCode(int $currencyId): ?string
    {
        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->first(['code']);
        if ($currency === null) {
            return null;
        }

        return self::normalizeCode($currency->code ?? null);
    }
}
