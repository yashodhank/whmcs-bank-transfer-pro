<?php

declare(strict_types=1);

namespace BankTransferPro\Migration;

final class MigrationRunner
{
    public function __construct(
        private readonly SqlExecutorInterface $executor,
        private readonly string $migrationsDirectory
    ) {
    }

    public function runPending(): void
    {
        $current = 0;
        if ($this->executor->tableExists('mod_btp_schema_version')) {
            $current = (int) ($this->executor->fetchScalar(
                'SELECT COALESCE(MAX(version), 0) AS v FROM mod_btp_schema_version'
            ) ?? 0);
        }

        foreach ($this->loadMigrations() as $migration) {
            $version = $migration['version'];
            if ($version <= $current) {
                continue;
            }

            $up = $migration['up'];
            if (! is_callable($up)) {
                throw new \RuntimeException('Migration ' . $migration['name'] . ' has no callable up()');
            }

            $up($this->executor);
            $this->executor->recordMigrationApplied($version, (string) $migration['name']);
            $current = $version;
        }
    }

    /**
     * @return list<array{version: int, name: string, up: callable(SqlExecutorInterface): void}>
     */
    private function loadMigrations(): array
    {
        $dir = $this->migrationsDirectory;
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $def = require $file;
            if (! is_array($def) || ! isset($def['version'], $def['name'], $def['up'])) {
                throw new \RuntimeException('Invalid migration file: ' . $file);
            }
            $migrations[] = $def;
        }

        usort($migrations, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);

        return $migrations;
    }
}
