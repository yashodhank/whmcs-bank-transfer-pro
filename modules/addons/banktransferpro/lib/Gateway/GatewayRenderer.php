<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Client\ClientAssets;
use BankTransferPro\Client\ProofPanel;
use BankTransferPro\Packs\InstructionPackEngine;
use BankTransferPro\Packs\PackRenderer;
use BankTransferPro\Packs\PayerContext;
use BankTransferPro\Repository\BankLookup;
use BankTransferPro\Repository\BankRepository;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\InvoiceCurrencyResolver;
use BankTransferPro\Support\ModuleFingerprint;

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

        $paymentLabel = BankRepository::buildInvoiceLabel($bank);
        $refLabel = 'Invoice Reference';

        if (class_exists('Lang') && method_exists('Lang', 'trans')) {
            $translated = \Lang::trans('invoicerefnum');
            if (is_string($translated) && $translated !== '' && $translated !== 'invoicerefnum') {
                $refLabel = $translated;
            }
        }

        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        $packSet = InstructionPackEngine::build(
            $bank,
            PayerContext::fromClientDetails(
                is_array($params['clientdetails'] ?? null) ? $params['clientdetails'] : [],
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            ),
            [
                'id' => $invoiceId,
                'number' => (string) ($params['invoicenum'] ?? '') !== '' ? (string) $params['invoicenum'] : (string) $invoiceId,
                'amount' => isset($params['amount']) ? (string) $params['amount'] : null,
                'currency' => InvoiceCurrencyResolver::normalizeCode($params['currency'] ?? null),
            ]
        );

        $html = PackRenderer::render($packSet, $paymentLabel, $refLabel);

        return ClientAssets::tags($params, self::assetVersion()) . $html . self::proofPanel($params, $packSet);
    }

    private static function assetVersion(): string
    {
        return defined('BTP_ADDON_ASSET_VERSION') ? (string) BTP_ADDON_ASSET_VERSION : ModuleFingerprint::VERSION;
    }

    /**
     * Upload-proof form delivered through the gateway link (the only output stock invoice
     * templates print). Silently omitted when settings/CSRF cannot be resolved.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $packSet
     */
    private static function proofPanel(array $params, array $packSet): string
    {
        try {
            if (! function_exists('generate_token')) {
                return '';
            }

            $settings = new SettingsRepository();
            if (! $settings->isProofUploadEnabled()) {
                return '';
            }

            $invoiceId = (int) ($params['invoiceid'] ?? 0);
            if ($invoiceId <= 0) {
                return '';
            }

            return ProofPanel::render(ProofPanel::context($packSet, $invoiceId, $settings, (string) generate_token('plain')));
        } catch (\Throwable) {
            return '';
        }
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
            $bank = $repo->findActiveBySlug($gatewaySlug);
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
