<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Image bytes remain encrypted outside the document root, never in this table. */
class Migration_Giftcard_sale_images extends CI_Migration {
    public static function tables() { return array(); }
    public static function statements() {
        return array("ALTER TABLE giftcard_sales ADD COLUMN card_form VARCHAR(16) NOT NULL DEFAULT 'Ecode',
            ADD COLUMN image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0");
    }
    public function up() { foreach (self::statements() as $sql) $this->db->query($sql); }
    public function down() { $this->db->query('ALTER TABLE giftcard_sales DROP COLUMN image_count, DROP COLUMN card_form'); }
}
