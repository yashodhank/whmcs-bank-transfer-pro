<?php

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once dirname(__DIR__) . '/addons/banktransferpro/lib/Bootstrap.php';

BankTransferPro\Bootstrap::init();

/**
 * @return array<string, mixed>
 */
function banktransferpro_MetaData(): array
{
    return [
        'DisplayName' => 'Bank Transfer Pro',
        'APIVersion' => '1.1',
        'gatewayType' => 'Bank',
        'VisibleDefault' => true,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function banktransferpro_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Bank Transfer Pro',
        ],
        'instructions' => [
            'FriendlyName' => 'Bank Transfer Instructions',
            'Type' => 'textarea',
            'Rows' => '5',
            'Value' => 'Bank details are managed in the Bank Transfer Pro addon.',
            'Description' => 'Immutable deployments use this static gateway and resolve the bank account from the invoice currency.',
        ],
    ];
}

/**
 * @param array<string, mixed> $params
 */
function banktransferpro_link(array $params): string
{
    return BankTransferPro\Gateway\GatewayRenderer::render('banktransferpro', $params);
}
