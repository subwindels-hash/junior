<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Customer-to-business sales, separate from prepaid gift-card purchases. */
class Migration_Giftcard_sales extends CI_Migration {
    public static function tables() { return array('giftcard_sales'); }
    public static function statements() {
        return array("CREATE TABLE IF NOT EXISTS giftcard_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            wallet_id BIGINT UNSIGNED NOT NULL,
            provider_id BIGINT UNSIGNED NOT NULL,
            provider_url VARCHAR(255) NOT NULL,
            provider_reference VARCHAR(128) NULL,
            giftcard_id VARCHAR(64) NOT NULL,
            card_name VARCHAR(255) NOT NULL,
            terms_text TEXT NULL,
            face_value DECIMAL(20,8) NOT NULL,
            quoted_rate DECIMAL(20,8) NOT NULL,
            margin_percent DECIMAL(8,4) NOT NULL,
            estimated_payout DECIMAL(20,8) NOT NULL,
            provider_amount DECIMAL(20,8) NULL,
            credited_amount DECIMAL(20,8) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            status VARCHAR(24) NOT NULL DEFAULT 'QUOTED',
            code_encrypted MEDIUMTEXT NULL,
            code_fingerprint CHAR(64) NULL UNIQUE,
            quote_expires_at DATETIME NOT NULL,
            last_checked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gc_sale_provider_ref (provider_id, provider_reference),
            INDEX idx_gc_sale_user (user_id, created_at),
            INDEX idx_gc_sale_status (status, last_checked_at),
            CONSTRAINT fk_gc_sale_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_gc_sale_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
            CONSTRAINT fk_gc_sale_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function up() { foreach (self::statements() as $sql) $this->db->query($sql); }
    public function down() { $this->db->query('DROP TABLE IF EXISTS giftcard_sales'); }
}
