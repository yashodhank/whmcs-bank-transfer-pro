<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Repository\BankLookup;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Support\InvoiceCurrencyResolver;

final class GatewayRenderer
{
    /**
     * @param array<string, mixed> $params
     */
    public static function render(string $gatewaySlug, array $params, ?BankLookup $banks = null, ?InvoiceCurrencyResolver $currencies = null): string
    {
        if (! class_exists(BankRepository::class)) {
            require_once dirname(__DIR__) . '/Bootstrap.php';
            \BankTransferPro\Bootstrap::init();
        }

        $bank = self::lookupBank($gatewaySlug, $params, $banks, $currencies);
        if ($bank === null) {
            return self::missingDetailsMarkup();
        }

        $displayName = htmlspecialchars((string) $bank['display_name'], ENT_QUOTES, 'UTF-8');
        $accountDetails = nl2br(htmlspecialchars((string) $bank['account_details'], ENT_QUOTES, 'UTF-8'));
        $invoiceRef = htmlspecialchars((string) ($params['invoicenum'] ?? $params['invoiceid'] ?? ''), ENT_QUOTES, 'UTF-8');
        $refLabel = 'Invoice Reference';

        if (class_exists('Lang') && method_exists('Lang', 'trans')) {
            $translated = \Lang::trans('invoicerefnum');
            if (is_string($translated) && $translated !== '' && $translated !== 'invoicerefnum') {
                $refLabel = htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');
            }
        }

        return <<<HTML
<div class="btp-bank-details">
    <p><strong>{$displayName}</strong></p>
    <p>{$accountDetails}</p>
    <p><strong>{$refLabel}:</strong> {$invoiceRef}</p>
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function lookupBank(
        string $gatewaySlug,
        array $params,
        ?BankLookup $banks,
        ?InvoiceCurrencyResolver $currencies
    ): ?array {
        try {
            $repo = $banks ?? new BankRepository();
            $resolver = $currencies ?? new InvoiceCurrencyResolver();
            $bank = $repo->findBySlug($gatewaySlug);
            if ($bank === null && $gatewaySlug === 'banktransferpro') {
                $bank = self::findByInvoiceCurrency($repo, $params, $resolver);
            }

            return $bank;
        } catch (\Throwable $exception) {
            if (function_exists('logActivity')) {
                logActivity('Bank Transfer Pro: unable to load invoice bank details: ' . $exception->getMessage());
            }

            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function findByInvoiceCurrency(BankLookup $repo, array $params, InvoiceCurrencyResolver $resolver): ?array
    {
        $code = $resolver->codeForGatewayLink($params);
        if ($code === null) {
            return null;
        }

        return $repo->findActiveByCurrencyCode($code);
    }

    private static function missingDetailsMarkup(): string
    {
        return '<p class="btp-bank-details btp-bank-details--missing">Bank details are temporarily unavailable.</p>';
    }
}
