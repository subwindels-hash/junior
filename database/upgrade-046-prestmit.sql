-- Active Digital Hub: existing-install upgrade from version 044/045 to 046.
-- BACK UP FIRST. For new installations use marvysocials.sql instead.
-- Pause requests/workers during upgrade. Run this in the selected database.
-- Older databases MUST run their missing migrations up to 044 first.
-- The preferred upgrade remains: php index.php migrate latest
-- Every statement below is a no-op unless migrations contains exactly one
-- version row with value 44, 45 or 46. Safe to rerun after partial DDL failure.
-- DDL auto-commits in MySQL: never rely on wrapping this file in a transaction.

SET @prestmit_db := DATABASE();
SET @prestmit_allowed := (SELECT COUNT(*) = 1 AND MIN(version) IN (44,45,46) FROM migrations);
SELECT IF(@prestmit_allowed, 'Applying Prestmit upgrade 045/046',
    'NOT APPLIED: migrate this database to version 044 first (or it is newer than 046)') AS prestmit_upgrade;

SET @prestmit_sql := IF(@prestmit_allowed,
    'CREATE TABLE IF NOT EXISTS giftcard_sales (
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
            currency CHAR(3) NOT NULL DEFAULT ''NGN'',
            status VARCHAR(24) NOT NULL DEFAULT ''QUOTED'',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'SELECT 1');
PREPARE prestmit_stmt FROM @prestmit_sql;
EXECUTE prestmit_stmt;
DEALLOCATE PREPARE prestmit_stmt;

SET @prestmit_sql := IF(@prestmit_allowed AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@prestmit_db AND TABLE_NAME='giftcard_sales' AND COLUMN_NAME='card_form') = 0,
    'ALTER TABLE giftcard_sales ADD COLUMN card_form VARCHAR(16) NOT NULL DEFAULT ''Ecode''', 'SELECT 1');
PREPARE prestmit_stmt FROM @prestmit_sql;
EXECUTE prestmit_stmt;
DEALLOCATE PREPARE prestmit_stmt;

SET @prestmit_sql := IF(@prestmit_allowed AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@prestmit_db AND TABLE_NAME='giftcard_sales' AND COLUMN_NAME='image_count') = 0,
    'ALTER TABLE giftcard_sales ADD COLUMN image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE prestmit_stmt FROM @prestmit_sql;
EXECUTE prestmit_stmt;
DEALLOCATE PREPARE prestmit_stmt;

-- Advance bookkeeping only after the table and both new columns exist.
UPDATE migrations SET version=46
 WHERE @prestmit_allowed AND version IN (44,45)
   AND (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=@prestmit_db AND TABLE_NAME='giftcard_sales'
          AND COLUMN_NAME IN ('card_form','image_count'))=2;
SELECT version AS resulting_migration_version FROM migrations;
