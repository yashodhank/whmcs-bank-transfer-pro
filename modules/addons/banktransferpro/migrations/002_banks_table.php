<?php

declare(strict_types=1);

use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 2,
    'name' => '002_banks_table',
    'up' => static function (SqlExecutorInterface $ex): void {
        if ($ex->tableExists('mod_btp_banks')) {
            return;
        }

        $ex->execute(
            'CREATE TABLE `mod_btp_banks` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `gateway_slug` varchar(128) NOT NULL,
              `bank_name` varchar(255) NOT NULL,
              `branch_name` varchar(255) NOT NULL DEFAULT \'\',
              `currency_code` char(3) NOT NULL,
              `account_details` text NOT NULL,
              `display_name` varchar(255) NOT NULL,
              `duplicate_key` varchar(64) NOT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_mod_btp_banks_gateway_slug` (`gateway_slug`),
              UNIQUE KEY `uk_mod_btp_banks_duplicate_key` (`duplicate_key`),
              KEY `idx_mod_btp_banks_currency` (`currency_code`),
              KEY `idx_mod_btp_banks_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
