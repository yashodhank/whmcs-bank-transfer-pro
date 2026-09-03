<?php

declare(strict_types=1);

use BankTransferPro\Migration\SqlExecutorInterface;

return [
    'version' => 3,
    'name' => '003_payment_proofs_table',
    'up' => static function (SqlExecutorInterface $ex): void {
        if ($ex->tableExists('mod_btp_payment_proofs')) {
            return;
        }

        $ex->execute(
            'CREATE TABLE `mod_btp_payment_proofs` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `invoice_id` int(10) unsigned NOT NULL,
              `client_id` int(10) unsigned NOT NULL,
              `gateway_slug` varchar(128) NOT NULL,
              `stored_filename` varchar(255) NOT NULL,
              `original_filename` varchar(255) NOT NULL,
              `mime` varchar(128) NOT NULL,
              `size` int(10) unsigned NOT NULL,
              `ticket_id` int(10) unsigned DEFAULT NULL,
              `uploaded_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_mod_btp_proofs_invoice` (`invoice_id`),
              KEY `idx_mod_btp_proofs_client` (`client_id`),
              KEY `idx_mod_btp_proofs_uploaded` (`uploaded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
