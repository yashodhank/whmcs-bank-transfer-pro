<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Repository\BankRepository;

final class GatewayRenderer
{
    public static function render(string $gatewaySlug, array $params): string
    {
        if (! class_exists(BankRepository::class)) {
            require_once dirname(__DIR__) . '/Bootstrap.php';
            \BankTransferPro\Bootstrap::init();
        }

        $repo = new BankRepository();
        $bank = $repo->findBySlug($gatewaySlug);

        if ($bank === null) {
            return '<p class="btp-bank-details btp-bank-details--missing">Bank details are temporarily unavailable.</p>';
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
    <div class="btp-bank-details__header">Bank Account Details — {$displayName}</div>
    <div class="btp-bank-details__body">{$accountDetails}</div>
    <div class="btp-bank-details__reference"><strong>{$refLabel}:</strong> {$invoiceRef}</div>
</div>
HTML;
    }
}
