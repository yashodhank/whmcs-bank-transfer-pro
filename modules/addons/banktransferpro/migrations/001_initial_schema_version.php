<?php

declare(strict_types=1);

use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 1,
    'name' => '001_initial_schema_version',
    'up' => static function (SqlExecutorInterface $ex): void {
        if ($ex->tableExists('mod_btp_schema_version')) {
            return;
        }

        $ex->execute(
            'CREATE TABLE `mod_btp_schema_version` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `version` int(10) unsigned NOT NULL,
              `migration_name` varchar(255) NOT NULL DEFAULT \'\',
              `applied_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
