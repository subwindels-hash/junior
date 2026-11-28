<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Giftcard_sale_model extends MY_Model {
    protected $table = 'giftcard_sales';
    // Explicit safe projection: controllers never receive the bearer code or its fingerprint.
    const PUBLIC_FIELDS = 'id, public_id, user_id, provider_id, provider_reference, card_name, terms_text, card_form, image_count,
        face_value, quoted_rate, margin_percent, estimated_payout, provider_amount,
        credited_amount, currency, status, quote_expires_at, last_checked_at, created_at, updated_at';

    public function public_row($public_id, $user_id = null) {
        $this->db->select(self::PUBLIC_FIELDS)->where('public_id', $public_id);
        if ($user_id !== null) $this->db->where('user_id', (int)$user_id);
        return $this->db->get($this->table)->row();
    }
    /** Service-only accessor. Never pass the result to a view. */
    public function private_row($id) { return $this->db->where('id', (int)$id)->get($this->table)->row(); }
    /** Service-only lifecycle/retention lock; never return this row to a view. */
    public function lock_for_update($id) {
        return $this->db->query('SELECT * FROM giftcard_sales WHERE id=? FOR UPDATE', array((int)$id))->row();
    }
    public function listing($user_id = null, $page = 1) {
        $this->db->select(self::PUBLIC_FIELDS);
        if ($user_id !== null) $this->db->where('user_id', (int)$user_id);
        return $this->db->order_by('created_at', 'DESC')->order_by('id', 'DESC')
            ->limit(25, (max(1, (int)$page) - 1) * 25)->get($this->table)->result();
    }
    public function count_listing($user_id = null) {
        if ($user_id !== null) $this->db->where('user_id', (int)$user_id);
        return (int)$this->db->count_all_results($this->table);
    }
    public function create(array $fields) {
        if (!$this->db->insert($this->table, $fields)) return null;
        return $this->private_row($this->db->insert_id());
    }
    /** Accept only a still-owned, unexpired quote at the final write boundary.
     * Image intake/encryption can outlive the service's initial expiry check.
     */
    public function accept_quote($id, $user_id, $card_form, array $fields) {
        $now = gmdate('Y-m-d H:i:s');
        $fields['status'] = 'REVIEW';
        $fields['updated_at'] = $now;
        $ok = $this->db->where('id', (int)$id)->where('user_id', (int)$user_id)
            ->where('card_form', $card_form)->where('status', 'QUOTED')
            ->where('quote_expires_at >=', $now)->update($this->table, $fields);
        return $ok && $this->db->affected_rows() === 1;
    }
    /** Record attempted checks, including HTTP/configuration failures, so a
     * broken head of the queue cannot starve the rest of a bounded cron batch.
     * Zero affected rows can mean a same-second poll or concurrent settlement.
     */
    public function record_poll_attempt($id) {
        return $this->db->where('id', (int)$id)->where_in('status', array('SUBMITTING', 'PENDING'))
            ->update($this->table, array('last_checked_at' => gmdate('Y-m-d H:i:s')));
    }
    /** Compare-and-set used before external submission: exactly one dispatcher wins. */
    public function change($id, $from, array $fields) {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $ok = $this->db->where('id', (int)$id)->where_in('status', (array)$from)->update($this->table, $fields);
        return $ok && $this->db->affected_rows() === 1;
    }
    /** Only after SELECT ... FOR UPDATE. MySQL may report zero for a no-op poll. */
    public function update_locked($id, array $fields) {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }
    public function open_sales($limit = 50) {
        return $this->db->select('id, last_checked_at')->where_in('status', array('SUBMITTING', 'PENDING'))
            ->order_by('last_checked_at', 'ASC')->order_by('id', 'ASC')
            ->limit(max(1, min(100, (int)$limit)))->get($this->table)->result();
    }
}
