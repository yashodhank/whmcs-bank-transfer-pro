<?php

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once dirname(__DIR__) . '/addons/banktransferpro/lib/Bootstrap.php';

BankTransferPro\Bootstrap::init();

/**
 * @return array<string, mixed>
 */
function {{SLUG}}_MetaData(): array
{
    return [
        'DisplayName' => '{{DISPLAY_NAME}}',
        'APIVersion' => '1.1',
        'gatewayType' => 'Bank',
        'VisibleDefault' => true,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function {{SLUG}}_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => '{{DISPLAY_NAME_ESCAPED}}',
        ],
        'instructions' => [
            'FriendlyName' => 'Bank Transfer Instructions',
            'Type' => 'textarea',
            'Rows' => '5',
            'Value' => 'Bank details are managed in the Bank Transfer Pro addon.',
            'Description' => 'Legacy WHMCS setting — bank details are loaded from the addon database.',
        ],
    ];
}

/**
 * @param array<string, mixed> $params
 */
function {{SLUG}}_link(array $params): string
{
    return BankTransferPro\Gateway\GatewayRenderer::render('{{SLUG}}', $params);
}
