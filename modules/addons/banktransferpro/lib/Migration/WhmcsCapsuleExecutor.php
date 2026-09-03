<?php

declare(strict_types=1);

namespace BankTransferPro\Migration;

use WHMCS\Database\Capsule;

final class WhmcsCapsuleExecutor implements SqlExecutorInterface
{
    public function execute(string $sql): void
    {
        Capsule::connection()->unprepared($sql);
    }

    public function recordMigrationApplied(int $version, string $migrationName): void
    {
        Capsule::connection()->insert(
            'INSERT INTO `mod_btp_schema_version` (`version`, `migration_name`, `applied_at`) VALUES (?, ?, NOW())',
            [$version, $migrationName]
        );
    }

    public function fetchScalar(string $sql)
    {
        $row = Capsule::connection()->selectOne($sql);
        if ($row === null) {
            return null;
        }

        $arr = (array) $row;

        return reset($arr);
    }

    public function tableExists(string $table): bool
    {
        $db = Capsule::connection()->getDatabaseName();
        $row = Capsule::connection()->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$db, $table]
        );
        if ($row === null) {
            return false;
        }

        $arr = (array) $row;

        return (int) reset($arr) > 0;
    }

    public function nowExpression(): string
    {
        return 'NOW()';
    }
}
