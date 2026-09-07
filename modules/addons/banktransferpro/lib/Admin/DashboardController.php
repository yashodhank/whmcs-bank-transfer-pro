<?php

declare(strict_types=1);

namespace BankTransferPro\Admin;

use BankTransferPro\Bootstrap;
use BankTransferPro\Repository\SettingsRepository;
use BankTransferPro\Support\AssetUrl;
use WHMCS\Database\Capsule;
use WHMCS\Smarty;

final class DashboardController
{
    public function handle(array $vars): void
    {
        $tab = (string) ($_GET['tab'] ?? 'dashboard');

        if (isset($_GET['action']) && $_GET['action'] === 'api') {
            (new AjaxController())->handle();

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'info') {
            $this->saveSettings();
        }

        $smarty = new Smarty();
        $smarty->caching = false;
        $smarty->assign('modulelink', $vars['modulelink'] ?? '');
        $smarty->assign('csrfToken', generate_token('plain'));
        $smarty->assign('activeTab', $tab);
        $smarty->assign('currencies', $this->loadCurrencies());
        $smarty->assign('settings', $this->loadSettings());
        $smarty->assign('departments', $this->loadDepartments());
        $smarty->assign('adminAssetBaseUrl', AssetUrl::admin());
        $smarty->assign('assetVersion', defined('BTP_ADDON_ASSET_VERSION') ? BTP_ADDON_ASSET_VERSION : ($vars['version'] ?? '1.0.0'));

        $template = match ($tab) {
            'info' => 'info',
            'documentation' => 'documentation',
            default => 'dashboard',
        };

        echo '<div class="btp-admin-scope">';
        $smarty->display(Bootstrap::addonRoot() . '/templates/admin/' . $template . '.tpl');
        echo '</div>';
    }

    private function saveSettings(): void
    {
        if (function_exists('check_token') && ! check_token('WHMCS.default', $_POST['token'] ?? null)) {
            echo '<div class="alert alert-danger">Invalid security token. Settings were not saved.</div>';

            return;
        }

        $settings = new SettingsRepository();

        $settings->set('ticket_department_id', (string) max(1, (int) ($_POST['ticket_department_id'] ?? 1)));
        $settings->set('ticket_priority', trim((string) ($_POST['ticket_priority'] ?? 'Medium')));
        $settings->set('ticket_subject_template', trim((string) ($_POST['ticket_subject_template'] ?? '')));
        $settings->set('enable_proof_upload', isset($_POST['enable_proof_upload']) ? 'on' : '');
        $settings->set('max_upload_size_mb', (string) max(1, (int) ($_POST['max_upload_size_mb'] ?? 5)));
        $settings->set('allowed_mime_types', trim((string) ($_POST['allowed_mime_types'] ?? '')));
        $settings->set('upload_cooldown_hours', (string) max(0, (int) ($_POST['upload_cooldown_hours'] ?? 24)));
        $settings->set('auto_select_gateway', isset($_POST['auto_select_gateway']) ? 'on' : '');

        echo '<div class="alert alert-success">Settings saved.</div>';
    }

    /**
     * @return list<array{id: int, code: string}>
     */
    private function loadCurrencies(): array
    {
        return Capsule::table('tblcurrencies')
            ->orderBy('code')
            ->get(['id', 'code'])
            ->map(static fn ($row) => ['id' => (int) $row->id, 'code' => (string) $row->code])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function loadDepartments(): array
    {
        return Capsule::table('tblticketdepartments')
            ->orderBy('order')
            ->get(['id', 'name'])
            ->map(static fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function loadSettings(): array
    {
        $repo = new SettingsRepository();

        return [
            'ticket_department_id' => $repo->get('ticket_department_id', '1'),
            'ticket_priority' => $repo->get('ticket_priority', 'Medium'),
            'ticket_subject_template' => $repo->get('ticket_subject_template', 'Payment proof — Invoice #{invoiceid}'),
            'enable_proof_upload' => $repo->get('enable_proof_upload', 'on'),
            'max_upload_size_mb' => $repo->get('max_upload_size_mb', '5'),
            'allowed_mime_types' => $repo->get('allowed_mime_types', 'image/jpeg,image/png,application/pdf'),
            'upload_cooldown_hours' => $repo->get('upload_cooldown_hours', '24'),
            'auto_select_gateway' => $repo->get('auto_select_gateway', 'on'),
        ];
    }
}
