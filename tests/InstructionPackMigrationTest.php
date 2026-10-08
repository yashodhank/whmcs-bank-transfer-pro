<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Migration\MigrationRunner;
use BankTransferPro\Migration\SqlExecutorInterface;
use PHPUnit\Framework\TestCase;

final class InstructionPackMigrationTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../modules/addons/banktransferpro/migrations';

    public function testBankMigrationAddsAllPackColumnsOnce(): void
    {
        $executor = new RecordingExecutor(existingColumns: []);
        $this->run005($executor);

        $alters = $executor->statementsMatching('/^ALTER TABLE `mod_btp_banks` ADD COLUMN/');
        $this->assertCount(10, $alters);
        foreach ([
            'country_code', 'capabilities', 'identifiers', 'beneficiary_address', 'bank_address',
            'intermediary_bic', 'prefer_charge_code', 'accept_fx_receive', 'wire_purpose_hint', 'pack_notes',
        ] as $column) {
            $this->assertNotEmpty($executor->statementsMatching('/ADD COLUMN `' . $column . '`/'), $column);
        }
        $this->assertStringContainsString("DEFAULT 'OUR'", implode("\n", $alters));
    }

    public function testBankMigrationIsIdempotentAndStillBackfills(): void
    {
        $executor = new RecordingExecutor(existingColumns: ['*']);
        $this->run005($executor);

        $this->assertSame([], $executor->statementsMatching('/ADD COLUMN/'));
        $this->assertCount(3, $executor->statementsMatching('/^UPDATE `mod_btp_banks`/'));
    }

    public function testBackfillTargetsOnlyUnmigratedLegacyRows(): void
    {
        $executor = new RecordingExecutor(existingColumns: ['*']);
        $this->run005($executor);
        $updates = $executor->statementsMatching('/^UPDATE `mod_btp_banks`/');

        $this->assertStringContainsString("`country_code` = 'IN'", $updates[0]);
        $this->assertStringContainsString("`country_code` = '' AND (`ifsc_code` <> '' OR `upi_id` <> '')", $updates[0]);
        $this->assertStringContainsString('"ifsc"', $updates[1]);
        $this->assertStringContainsString('"upi"', $updates[1]);
        $this->assertStringContainsString('JSON_QUOTE', $updates[1]);
        $this->assertStringContainsString("`identifiers` IS NULL OR `identifiers` = ''", $updates[1]);
        $this->assertStringContainsString('"local_transfer"', $updates[2]);
        $this->assertStringContainsString('"instant_alias"', $updates[2]);
        $this->assertStringContainsString("`capabilities` IS NULL OR `capabilities` = ''", $updates[2]);
    }

    public function testProofMigrationAddsReconcileColumnsAndReferenceIndex(): void
    {
        $executor = new RecordingExecutor(existingColumns: []);
        $def = require self::MIGRATIONS . '/006_instruction_packs_proofs.php';
        $def['up']($executor);

        foreach (['payment_reference', 'pack_id', 'rail_reference', 'declared_amount', 'declared_currency'] as $column) {
            $this->assertNotEmpty($executor->statementsMatching('/ADD COLUMN `' . $column . '`/'), $column);
        }
        $this->assertNotEmpty($executor->statementsMatching('/ADD KEY `idx_mod_btp_proofs_reference`/'));
    }

    public function testMigrationsAreOrderedAndPreserveLegacyColumns(): void
    {
        $versions = [];
        foreach (glob(self::MIGRATIONS . '/*.php') ?: [] as $file) {
            $def = require $file;
            $versions[] = $def['version'];
            $this->assertStringNotContainsString('DROP COLUMN', (string) file_get_contents($file), $file);
        }
        sort($versions);

        $this->assertSame([1, 2, 3, 4, 5, 6], $versions);
    }

    public function testRunnerAppliesPendingPackMigrationsAfterVersionFour(): void
    {
        $executor = new RecordingExecutor(existingColumns: [], schemaVersion: 4);
        (new MigrationRunner($executor, self::MIGRATIONS))->runPending();

        $this->assertSame([5, 6], $executor->recordedVersions);
    }

    private function run005(RecordingExecutor $executor): void
    {
        $def = require self::MIGRATIONS . '/005_instruction_packs_banks.php';
        $def['up']($executor);
    }
}

final class RecordingExecutor implements SqlExecutorInterface
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<int> */
    public array $recordedVersions = [];

    /**
     * @param list<string> $existingColumns '*' means every column already exists
     */
    public function __construct(
        private readonly array $existingColumns,
        private readonly int $schemaVersion = 0
    ) {
    }

    public function execute(string $sql): void
    {
        $this->statements[] = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    public function recordMigrationApplied(int $version, string $migrationName): void
    {
        $this->recordedVersions[] = $version;
    }

    public function fetchScalar(string $sql)
    {
        if (str_contains($sql, 'SELECT DATABASE()')) {
            return 'whmcs';
        }
        if (str_contains($sql, 'COALESCE(MAX(version)')) {
            return $this->schemaVersion;
        }
        if (str_contains($sql, 'information_schema.statistics')) {
            return 0;
        }
        if (preg_match("/column_name = '([^']+)'/", $sql, $m) === 1) {
            return in_array('*', $this->existingColumns, true) || in_array($m[1], $this->existingColumns, true) ? 1 : 0;
        }

        return null;
    }

    public function tableExists(string $table): bool
    {
        return true;
    }

    public function nowExpression(): string
    {
        return 'NOW()';
    }

    /**
     * @return list<string>
     */
    public function statementsMatching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }
}
