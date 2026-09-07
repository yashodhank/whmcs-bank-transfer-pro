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

        $paymentLabel = htmlspecialchars(BankRepository::buildInvoiceLabel($bank), ENT_QUOTES, 'UTF-8');
        $invoiceRef = htmlspecialchars((string) ($params['invoicenum'] ?? $params['invoiceid'] ?? ''), ENT_QUOTES, 'UTF-8');
        $refLabel = 'Invoice Reference';

        if (class_exists('Lang') && method_exists('Lang', 'trans')) {
            $translated = \Lang::trans('invoicerefnum');
            if (is_string($translated) && $translated !== '' && $translated !== 'invoicerefnum') {
                $refLabel = htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');
            }
        }

        $structuredMarkup = self::structuredDetailsMarkup($bank);
        $legacyDetails = self::legacyDetailsMarkup((string) ($bank['account_details'] ?? ''));

        return <<<HTML
<div class="btp-bank-details">
    <div class="btp-bank-details__summary">
        <span class="btp-bank-details__summary-label">Pay via</span>
        <strong>{$paymentLabel}</strong>
    </div>
    {$structuredMarkup}
    {$legacyDetails}
    <div class="btp-bank-details__reference"><strong>{$refLabel}:</strong> {$invoiceRef}</div>
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

    /**
     * @param array<string, mixed> $bank
     */
    private static function structuredDetailsMarkup(array $bank): string
    {
        $rows = [];

        $upiId = trim((string) ($bank['upi_id'] ?? ''));
        if ($upiId !== '') {
            $rows[] = self::detailRow('UPI', $upiId);
        }

        $accountRows = [];
        $accountName = trim((string) ($bank['account_name'] ?? ''));
        if ($accountName !== '') {
            $accountRows[] = self::detailRow('Account Name', $accountName);
        }

        $accountNumber = trim((string) ($bank['account_number'] ?? ''));
        if ($accountNumber !== '') {
            $accountRows[] = self::detailRow('Account Number', $accountNumber);
        }

        $ifscCode = trim((string) ($bank['ifsc_code'] ?? ''));
        if ($ifscCode !== '') {
            $accountRows[] = self::detailRow('IFSC', $ifscCode);
        }

        $branchName = trim((string) ($bank['branch_name'] ?? ''));
        if ($branchName !== '') {
            $accountRows[] = self::detailRow('Bank Branch', $branchName);
        }

        $bankName = trim((string) ($bank['bank_name'] ?? ''));
        if ($bankName !== '') {
            $accountRows[] = self::detailRow('Bank Name', $bankName);
        }

        if ($accountRows !== []) {
            $rows[] = '<div class="btp-bank-details__section-title">Bank Account</div>' . implode('', $accountRows);
        }

        return implode('', $rows);
    }

    private static function legacyDetailsMarkup(string $details): string
    {
        $details = trim($details);
        if ($details === '') {
            return '';
        }

        $accountDetails = nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'));

        return '<div class="btp-bank-details__notes">' . $accountDetails . '</div>';
    }

    private static function detailRow(string $label, string $value): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="btp-bank-details__row"><span class="btp-bank-details__label">' . $safeLabel . ':</span> <span class="btp-bank-details__value">' . $safeValue . '</span></div>';
    }
}
