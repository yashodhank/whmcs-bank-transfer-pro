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
        'version' => '1.1.2',
        'author' => 'Securiace Technologies',
        'language' => 'english',
        'fields' => [],
    ];
}

function banktransferpro_activate(): array
{
    try {
        banktransferpro_bootstrap(true);

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
    banktransferpro_bootstrap(true);
    (new DashboardController())->handle($vars);
}

/**
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function banktransferpro_clientarea(array $vars): array
{
    banktransferpro_bootstrap(true);

    $action = trim((string) ($_REQUEST['action'] ?? ''));
    if ($action === 'upload-proof') {
        (new UploadController())->handle();
    }

    return [];
}

function banktransferpro_bootstrap(bool $runMigrations = false): void
{
    Bootstrap::init();

    if (! $runMigrations) {
        return;
    }

    static $migrationsApplied = false;
    if ($migrationsApplied) {
        return;
    }

    $migrationsApplied = true;

    $runner = new MigrationRunner(
        new WhmcsCapsuleExecutor(),
        __DIR__ . '/migrations'
    );
    $runner->runPending();
}

/**
 * @param array<string, mixed> $vars
 */
function banktransferpro_sidebar(array $vars): string
{
    $link = htmlspecialchars((string) ($vars['modulelink'] ?? ''), ENT_QUOTES, 'UTF-8');
    $lang = is_array($vars['_lang'] ?? null) ? $vars['_lang'] : [];
    $name = htmlspecialchars((string) ($lang['name'] ?? 'Bank Transfer Pro'), ENT_QUOTES, 'UTF-8');
    $dashboard = htmlspecialchars((string) ($lang['dashboard'] ?? 'Dashboard'), ENT_QUOTES, 'UTF-8');
    $info = htmlspecialchars((string) ($lang['info'] ?? 'Info'), ENT_QUOTES, 'UTF-8');
    $docs = htmlspecialchars((string) ($lang['documentation'] ?? 'Documentation'), ENT_QUOTES, 'UTF-8');

    return '<span class="header">'
        . '<img src="images/icons/addonmodules.png" class="absmiddle" alt="" width="16" height="16" /> '
        . $name
        . '</span>'
        . '<ul class="menu">'
        . '<li><a href="' . $link . '&tab=dashboard">' . $dashboard . '</a></li>'
        . '<li><a href="' . $link . '&tab=info">' . $info . '</a></li>'
        . '<li><a href="' . $link . '&tab=documentation">' . $docs . '</a></li>'
        . '</ul>';
}
