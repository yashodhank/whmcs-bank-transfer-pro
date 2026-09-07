<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use BankTransferPro\Admin\DashboardController;
use BankTransferPro\Bootstrap;
use BankTransferPro\Gateway\GatewayPathResolver;
use BankTransferPro\Client\UploadController;
use BankTransferPro\Migration\MigrationRunner;
use BankTransferPro\Migration\WhmcsCapsuleExecutor;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\RuntimeEnvironment;

require_once __DIR__ . '/lib/Bootstrap.php';

function banktransferpro_config(): array
{
    return [
        'name' => 'Bank Transfer Pro',
        'description' => 'Multi-currency bank transfer gateways with auto-generated payment modules, invoice bank details, and client payment proof upload.',
        'version' => '1.1.1',
        'author' => 'Securiace Technologies',
        'language' => 'english',
        'fields' => [],
    ];
}

function banktransferpro_activate(): array
{
    try {
        Bootstrap::init();

        $runner = new MigrationRunner(
            new WhmcsCapsuleExecutor(),
            __DIR__ . '/migrations'
        );
        $runner->runPending();

        (new SettingsRepository())->seedDefaults();

        if (RuntimeEnvironment::usesStaticGatewayMode() && ! is_file((new GatewayPathResolver())->gatewayFilePath('banktransferpro'))) {
            return [
                'status' => 'error',
                'description' => 'Activation succeeded, but the static banktransferpro gateway file is missing from modules/gateways.',
            ];
        }

        if (! RuntimeEnvironment::usesStaticGatewayMode() && ! (new GatewayPathResolver())->isGatewaysDirectoryWritable()) {
            return [
                'status' => 'error',
                'description' => 'Activation succeeded, but modules/gateways is not writable. Bank gateway files cannot be generated until permissions are fixed.',
            ];
        }

        return [
            'status' => 'success',
            'description' => 'Bank Transfer Pro activated successfully.',
        ];
    } catch (Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Bank Transfer Pro activation failed: ' . $e->getMessage());
        }

        return [
            'status' => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function banktransferpro_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Bank Transfer Pro deactivated. Bank records and generated gateway files are preserved.',
    ];
}

/**
 * @param array<string, mixed> $vars
 */
function banktransferpro_output(array $vars): void
{
    Bootstrap::init();
    (new DashboardController())->handle($vars);
}

/**
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function banktransferpro_clientarea(array $vars): array
{
    Bootstrap::init();

    $action = trim((string) ($_REQUEST['action'] ?? ''));
    if ($action === 'upload-proof') {
        (new UploadController())->handle();
    }

    return [];
}
