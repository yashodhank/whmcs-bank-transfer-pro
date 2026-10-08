<?php

declare(strict_types=1);

namespace BankTransferPro\Migration;

/**
 * Idempotent ADD COLUMN helper shared by additive migrations.
 */
final class ColumnEnsurer
{
    /**
     * @param array<string, string> $columns column name => full ALTER TABLE ... ADD COLUMN statement
     */
    public static function ensure(SqlExecutorInterface $ex, string $table, array $columns): void
    {
        if (! $ex->tableExists($table)) {
            return;
        }

        $database = (string) ($ex->fetchScalar('SELECT DATABASE()') ?? '');
        if ($database === '') {
            return;
        }

        foreach ($columns as $column => $sql) {
            $exists = (int) ($ex->fetchScalar(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '"
                . addslashes($database)
                . "' AND table_name = '"
                . addslashes($table)
                . "' AND column_name = '"
                . addslashes($column)
                . "'"
            ) ?? 0);

            if ($exists === 0) {
                $ex->execute($sql);
            }
        }
    }
}
