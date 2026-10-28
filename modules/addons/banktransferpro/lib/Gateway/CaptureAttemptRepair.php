<?php

declare(strict_types=1);

namespace BankTransferPro\Gateway;

use BankTransferPro\Repository\SettingsRepository;
use WHMCS\Database\Capsule;

/**
 * Reclassify BTP gateways as manual (Invoices) and remove false cron-capture
 * history rows written while type was Bank/CC.
 */
final class CaptureAttemptRepair
{
    public const SETTING_HISTORY_CLEANED = 'false_capture_history_cleaned';

    public const FALSE_CAPTURE_DESCRIPTION = 'No Pay Method Available';

    /** @var list<string> */
    private const CAPTURABLE_TYPES = ['Bank', 'CC'];

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly GatewayActivator $activator = new GatewayActivator()
    ) {
    }

    /**
     * Repair gateway types, then run one-shot history cleanup when eligible.
     *
     * @return array{types_repaired: int, history_deleted: int|null, history_skipped: string|null}
     */
    public function run(): array
    {
        $typesRepaired = $this->repairGatewayTypes();
        $history = $this->cleanupFalseHistoryOnce();

        return [
            'types_repaired' => $typesRepaired,
            'history_deleted' => $history['deleted'],
            'history_skipped' => $history['skipped'],
        ];
    }

    /**
     * @return int Number of gateway type rows changed from Bank/CC to Invoices
     */
    public function repairGatewayTypes(): int
    {
        $manualType = GatewayActivator::manualGatewayType();
        $changed = 0;

        $rows = Capsule::table('tblpaymentgateways')
            ->where('setting', 'type')
            ->where('gateway', 'like', 'banktransferpro%')
            ->get(['gateway', 'value']);

        foreach ($rows as $row) {
            $slug = (string) ($row->gateway ?? '');
            $current = (string) ($row->value ?? '');
            if (! self::isBtpGatewaySlug($slug) || ! in_array($current, self::CAPTURABLE_TYPES, true)) {
                continue;
            }

            $this->activator->ensureManualGatewayType($slug);
            $changed++;
        }

        if ($changed > 0 && function_exists('logActivity')) {
            logActivity(sprintf(
                'Bank Transfer Pro: repaired %d gateway type row(s) to %s (stop false capture).',
                $changed,
                $manualType
            ));
        }

        return $changed;
    }

    /**
     * One-shot delete of false capture attempt history. Never touches tblaccounts.
     *
     * @return array{deleted: int|null, skipped: string|null}
     */
    public function cleanupFalseHistoryOnce(): array
    {
        if ($this->settings->get(self::SETTING_HISTORY_CLEANED) !== null) {
            return ['deleted' => null, 'skipped' => 'already_cleaned'];
        }

        if (! $this->historyTableReady()) {
            if (function_exists('logActivity')) {
                logActivity(
                    'Bank Transfer Pro: skipped false-capture history cleanup '
                    . '(tbltransaction_history schema not ready).'
                );
            }

            return ['deleted' => null, 'skipped' => 'schema_missing'];
        }

        $columns = ['id', 'gateway', 'description', 'transaction_id'];
        $hasCompleted = Capsule::schema()->hasColumn('tbltransaction_history', 'completed');
        if ($hasCompleted) {
            $columns[] = 'completed';
        }

        $candidates = Capsule::table('tbltransaction_history')
            ->where('description', self::FALSE_CAPTURE_DESCRIPTION)
            ->where('gateway', 'like', 'banktransferpro%')
            ->get($columns);

        $ids = [];
        foreach ($candidates as $row) {
            $asArray = [
                'gateway' => (string) ($row->gateway ?? ''),
                'description' => (string) ($row->description ?? ''),
                'transaction_id' => $row->transaction_id ?? null,
            ];
            if ($hasCompleted) {
                $asArray['completed'] = $row->completed ?? null;
            }
            if (self::isFalseCaptureHistoryRow($asArray)) {
                $ids[] = (int) $row->id;
            }
        }

        $deleted = 0;
        if ($ids !== []) {
            $deleted = (int) Capsule::table('tbltransaction_history')->whereIn('id', $ids)->delete();
        }

        $this->settings->set(self::SETTING_HISTORY_CLEANED, gmdate('c'));

        if (function_exists('logActivity')) {
            logActivity(sprintf(
                'Bank Transfer Pro: deleted %d false capture history row(s) (%s).',
                $deleted,
                self::FALSE_CAPTURE_DESCRIPTION
            ));
        }

        return ['deleted' => $deleted, 'skipped' => null];
    }

    /**
     * Pure predicate for unit tests / operator review.
     *
     * @param array<string, mixed> $row
     */
    public static function isFalseCaptureHistoryRow(array $row): bool
    {
        if (! self::isBtpGatewaySlug((string) ($row['gateway'] ?? ''))) {
            return false;
        }

        if ((string) ($row['description'] ?? '') !== self::FALSE_CAPTURE_DESCRIPTION) {
            return false;
        }

        $transId = $row['transaction_id'] ?? null;
        if ($transId !== null && $transId !== '' && $transId !== 'N/A') {
            return false;
        }

        if (array_key_exists('completed', $row)) {
            $completed = $row['completed'];
            if ($completed === true || $completed === 1 || $completed === '1') {
                return false;
            }
        }

        return true;
    }

    public static function isBtpGatewaySlug(string $gateway): bool
    {
        return $gateway === 'banktransferpro' || str_starts_with($gateway, 'banktransferpro_');
    }

    private function historyTableReady(): bool
    {
        $schema = Capsule::schema();
        if (! $schema->hasTable('tbltransaction_history')) {
            return false;
        }

        foreach (['gateway', 'description', 'transaction_id'] as $column) {
            if (! $schema->hasColumn('tbltransaction_history', $column)) {
                return false;
            }
        }

        return true;
    }
}
