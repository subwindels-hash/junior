<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer e-code sales. No customer debit and no credit on submission.
 * REVIEW -> SUBMITTING is committed before the single upstream POST. Unknown
 * submissions are only polled, never re-sent. Settlement locks the sale row
 * around LedgerService's idempotent credit and the final state update.
 */
class GiftcardSellService {
    private $ci;
    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Giftcard_sale_model', 'Provider_model', 'Wallet_model', 'Audit_log_model'));
        $this->ci->load->library(array('Provider_manager', 'EncryptionService', 'LedgerService'));
    }
    public function enabled() {
        return $this->ci->config->item('prestmit_sell_enabled') === true
            && (int)$this->ci->config->item('prestmit_sell_provider_id') > 0;
    }
    public function catalogue() {
        if (!$this->enabled()) return $this->err('Gift-card selling is not enabled yet.');
        $provider = $this->provider();
        if (!$provider) return $this->err('The configured NGN sell provider is unavailable.');
        return $this->adapter($provider)->sell_catalogue($this->ci->config->item('prestmit_physical_enabled') === true);
    }
    public function quote($user, array $input) {
        if (!$this->enabled() || marvy_base_currency() !== 'NGN') return $this->err('Gift-card selling is not available.');
        $amount = is_scalar($input['amount'] ?? null) ? (string)$input['amount'] : '';
        if (!self::positive_decimal($amount) || bccomp($amount, '10000', 8) > 0) {
            return $this->err('Enter a face value greater than zero and no more than 10,000.');
        }
        $margin = (string)$this->ci->config->item('prestmit_sell_margin_percent');
        if (!preg_match('/^\d{1,2}(\.\d{1,4})?$/', $margin) || bccomp($margin, '50', 4) > 0) {
            return $this->err('Sell pricing is not configured correctly.');
        }
        $today = $this->ci->db->where('user_id', (int)$user->id)
            ->where('created_at >=', gmdate('Y-m-d 00:00:00'))->count_all_results('giftcard_sales');
        if ($today >= 20) return $this->err('Daily gift-card quote limit reached. Please contact support.');
        $provider = $this->provider();
        if (!$provider) return $this->err('The configured NGN sell provider is unavailable.');
        $wallet = $this->ci->Wallet_model->for_user($user->id);
        if (!$wallet || $wallet->currency !== 'NGN') return $this->err('Selling currently requires an NGN wallet.');
        $res = $this->adapter($provider)->sell_catalogue($this->ci->config->item('prestmit_physical_enabled') === true);
        if (empty($res['ok'])) return $res;
        $chosen = null;
        foreach ($res['cards'] as $card) {
            if ($card['id'] === (string)($input['giftcard_id'] ?? '')) $chosen = $card;
        }
        if (!$chosen || !self::positive_decimal((string)$chosen['rate'])
            || !is_numeric($chosen['minimum']) || bccomp($amount, (string)$chosen['minimum'], 8) < 0) {
            return $this->err('Select an available e-code card and meet its minimum face value.');
        }
        $gross = bcmul($amount, (string)$chosen['rate'], 8);
        $estimated = $this->net($gross, $margin);
        if (!self::positive_decimal($estimated)) return $this->err('The quoted payout is invalid.');
        $row = $this->ci->Giftcard_sale_model->create(array(
            'public_id' => marvy_public_id(), 'user_id' => (int)$user->id, 'wallet_id' => $wallet->id,
            'provider_id' => $provider->id, 'provider_url' => rtrim($provider->api_url, '/'),
            'giftcard_id' => $chosen['id'], 'card_name' => mb_substr($chosen['name'], 0, 255),
            'card_form' => $chosen['form'],
            'terms_text' => mb_substr($chosen['terms'], 0, 10000),
            'face_value' => bcadd($amount, '0', 8), 'quoted_rate' => bcadd((string)$chosen['rate'], '0', 8),
            'margin_percent' => $margin, 'estimated_payout' => $estimated, 'currency' => 'NGN',
            'status' => 'QUOTED', 'quote_expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        if (!$row) return $this->err('Could not save this quote.');
        return array('ok' => true, 'sale' => $this->ci->Giftcard_sale_model->public_row($row->public_id, $user->id));
    }
    public function submit($user, $public_id, $code, $accepted) {
        if (!$this->enabled()) return $this->err('Gift-card selling is not enabled.');
        $sale = $this->ci->Giftcard_sale_model->public_row($public_id, $user->id);
        if (!$sale) return $this->err('Sale not found.');
        if ($sale->status !== 'QUOTED') return array('ok' => true, 'duplicate' => true);
        if ($sale->quote_expires_at < gmdate('Y-m-d H:i:s')) return $this->err('This quote expired. Request a new quote.');
        if ((string)$accepted !== '1') return $this->err('Accept the payout and ownership terms first.');
        if (($sale->card_form ?? 'Ecode') !== 'Ecode') return $this->err('This quote requires physical card images.');
        // One digital card only, no attachments or comments that might leak to logs.
        if (!is_string($code)) return $this->err('Enter one digital gift-card code.');
        $code = trim($code);
        if (strlen($code) < 4 || strlen($code) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $code)) {
            return $this->err('Enter one valid digital code (4–2,000 characters, without control characters).');
        }
        $fingerprint = hash_hmac('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)), EncryptionService::resolve_key());
        $existing = $this->ci->db->where('code_fingerprint', $fingerprint)->get('giftcard_sales')->row();
        if ($existing) return $this->err('This code has already been submitted. Contact support if you need help.');
        $saved = $this->audited_local_change(function () use ($sale, $user, $code, $fingerprint) {
            return $this->ci->Giftcard_sale_model->accept_quote($sale->id, $user->id, 'Ecode', array(
                'code_encrypted' => $this->ci->encryptionservice->encrypt($code), 'code_fingerprint' => $fingerprint));
        }, $user->id, 'giftcard.sale.submitted', $sale->id);
        if (empty($saved['ok'])) return $this->err('The submission could not be saved and audited. Check sale history before trying again; the quote may have expired.');
        return array('ok' => true);
    }

    public function submit_images($user, $public_id, $uploads, $accepted) {
        if (!$this->enabled() || $this->ci->config->item('prestmit_physical_enabled') !== true) return $this->err('Physical card sales are disabled.');
        $sale = $this->ci->Giftcard_sale_model->public_row($public_id, $user->id);
        if (!$sale || ($sale->card_form ?? '') !== 'Physical') return $this->err('Physical card quote not found.');
        if ($sale->status !== 'QUOTED') return array('ok' => true, 'duplicate' => true);
        if ($sale->quote_expires_at < gmdate('Y-m-d H:i:s') || (string)$accepted !== '1') return $this->err('Quote expired or terms not accepted.');
        $this->ci->load->library('GiftcardImageStore');
        $manifest = array();
        try {
            $manifest = $this->ci->giftcardimagestore->ingest($uploads);
            $hashes = array_column($manifest, 'sha256'); sort($hashes);
            $fingerprint = hash_hmac('sha256', 'physical:'.implode(':', $hashes), EncryptionService::resolve_key());
            if ($this->ci->db->where('code_fingerprint', $fingerprint)->get('giftcard_sales')->row()) throw new RuntimeException('Duplicate images.');
            $encrypted = $this->ci->encryptionservice->encrypt(json_encode($manifest));
        } catch (Throwable $e) {
            $this->discard_images($manifest, $sale->id);
            return $this->err('Images could not be accepted. Use 1–20 JPEG/PNG images, at most 5 MiB each and 20 MiB total. Do not resubmit previously submitted images.');
        }
        $saved = $this->audited_local_change(function () use ($sale, $user, $manifest, $fingerprint, $encrypted) {
            return $this->ci->Giftcard_sale_model->accept_quote($sale->id, $user->id, 'Physical', array(
                'image_count' => count($manifest), 'code_fingerprint' => $fingerprint, 'code_encrypted' => $encrypted));
        }, $user->id, 'giftcard.sale.images_submitted', $sale->id);
        if (empty($saved['ok'])) {
            // Never delete a possibly committed sale's files after an ambiguous
            // COMMIT/rollback. Keep them for reference-based operator recovery.
            if (!empty($saved['cleanup_safe'])) $this->discard_images($manifest, $sale->id);
            return $this->err('Images could not be saved and audited. Check sale history and contact support before resubmitting.');
        }
        return array('ok' => true);
    }
    /** Staff-only callers must check giftcards.manage before requesting image bytes. */
    public function image($id, $index, $actor_id) {
        $sale = $this->ci->Giftcard_sale_model->private_row($id);
        if (!$sale || $sale->card_form !== 'Physical' || $index < 0 || $index >= $sale->image_count) return null;
        $manifest = json_decode((string)$this->ci->encryptionservice->open($sale->code_encrypted), true);
        if (!is_array($manifest) || !isset($manifest[$index])) return null;
        $this->ci->load->library('GiftcardImageStore');
        try {
            $image = $this->ci->giftcardimagestore->read($manifest[$index]);
            $this->audit($actor_id, 'giftcard.sale.image_viewed', $id, array('index' => $index));
            return $image;
        } catch (Throwable $e) { return null; }
    }
    public function dispatch($id, $actor_id) {
        if (!$this->enabled()) return $this->err('New sell dispatches are disabled.');
        $sale = $this->ci->Giftcard_sale_model->private_row($id);
        if (!$sale || $sale->status !== 'REVIEW') return $this->err('Only a sale awaiting review can be submitted.');
        $provider = $this->provider_for_sale($sale, true);
        if (!$provider) return $this->err('Provider changed, is disabled or is unavailable. Reconcile its configuration first.');
        $code = $this->ci->encryptionservice->open($sale->code_encrypted);
        if ($code === null || $code === '') return $this->err('The submission cannot be decrypted.');
        $attachments = array();
        if (($sale->card_form ?? 'Ecode') === 'Physical') {
            if ($this->ci->config->item('prestmit_physical_enabled') !== true) return $this->err('Physical dispatches are disabled.');
            $manifest = json_decode($code, true);
            if (!is_array($manifest) || count($manifest) < 1 || count($manifest) !== (int)$sale->image_count) return $this->err('Card image manifest is invalid.');
            $this->ci->load->library('GiftcardImageStore');
            try { foreach ($manifest as $item) $attachments[] = $this->ci->giftcardimagestore->read($item); }
            catch (Throwable $e) { return $this->err('Card images cannot be decrypted; no provider submission was made.'); }
            $code = '';
        }
        $claimed = $this->audited_local_change(function () use ($id) {
            return $this->ci->Giftcard_sale_model->change($id, 'REVIEW', array('status' => 'SUBMITTING'));
        }, $actor_id, 'giftcard.sale.dispatched', $id);
        if (empty($claimed['ok'])) {
            return $this->err('Dispatch could not be committed and audited. No provider request was made by this attempt. Check the current sale status before retrying.');
        }
        // The audited claim is committed before HTTP. From here onward every
        // exception/outage is uncertain. Never reset SUBMITTING to REVIEW.
        try {
            $res = $this->adapter($provider)->sell_create(array('giftcard_id' => $sale->giftcard_id,
                'amount' => $sale->face_value, 'code' => $code, 'attachments' => $attachments, 'reference' => $sale->public_id));
            if (!empty($res['ok']) && !empty($res['reference'])) {
                $this->ci->Giftcard_sale_model->change($id, 'SUBMITTING', array(
                    'status' => 'PENDING', 'provider_reference' => $res['reference']));
                return array('ok' => true);
            }
            if (empty($res['uncertain'])) {
                $this->ci->Giftcard_sale_model->change($id, 'SUBMITTING', array('status' => 'REJECTED'));
                return $this->err('Prestmit rejected the submission. No wallet credit was made.');
            }
        } catch (Throwable $e) { log_message('error', 'Gift-card sell dispatch requires reconciliation: '.(int)$id); }
        return array('ok' => true, 'uncertain' => true);
    }
    public function reject($id, $actor_id) {
        $saved = $this->audited_local_change(function () use ($id) {
            return $this->ci->Giftcard_sale_model->change($id, 'REVIEW', array('status' => 'REJECTED'));
        }, $actor_id, 'giftcard.sale.declined', $id);
        if (empty($saved['ok'])) return $this->err('Only an unsubmitted sale can be declined, and its audit must be saved. Check the current sale status before retrying.');
        return array('ok' => true);
    }
    /** Reconciliation continues even when the new-sales feature/provider is disabled. */
    public function reconcile($id, $actor_id = null) {
        $sale = $this->ci->Giftcard_sale_model->private_row($id);
        if (!$sale) return $this->err('Sale not found.');
        if (in_array($sale->status, array('CREDITED', 'REJECTED'), true)) return array('ok' => true, 'duplicate' => true);
        if (!in_array($sale->status, array('SUBMITTING', 'PENDING'), true)) return $this->err('This sale has not been submitted.');
        if (!$this->ci->Giftcard_sale_model->record_poll_attempt($id)) {
            return $this->err('Could not record the reconciliation attempt. Please retry later.');
        }
        $provider = $this->provider_for_sale($sale, false);
        if (!$provider) return $this->err('Provider configuration no longer matches this sale.');
        $res = $this->adapter($provider)->sell_status($sale->provider_reference ?: $sale->public_id, !$sale->provider_reference);
        if (empty($res['ok'])) return $res;
        if (!$this->ci->db->trans_begin()) return $this->err('Could not start settlement. Please retry later.');
        try {
            $locked = $this->ci->Giftcard_sale_model->lock_for_update($id);
            if (!$locked || !in_array($locked->status, array('SUBMITTING', 'PENDING'), true)) {
                if (!$this->ci->db->trans_commit()) throw new RuntimeException('Could not finish reconciliation.');
                return array('ok' => true, 'duplicate' => true);
            }
            $fields = array('last_checked_at' => gmdate('Y-m-d H:i:s'));
            if (!empty($res['found'])) {
                if ($res['giftcard_id'] !== (string)$locked->giftcard_id
                    || (!empty($locked->provider_reference) && $locked->provider_reference !== $res['reference'])
                    || (!empty($res['payout_method']) && $res['payout_method'] !== 'NAIRA')) {
                    throw new RuntimeException('Provider trade identity or payout method mismatch.');
                }
                $fields['provider_reference'] = $res['reference'];
                $fields['status'] = 'PENDING';
                if ($res['status'] === 'REJECTED') $fields['status'] = 'REJECTED';
                if ($res['status'] === 'COMPLETED') {
                    if (!self::positive_decimal((string)$res['amount']) || marvy_base_currency() !== $locked->currency) {
                        throw new RuntimeException('Completed trade has no valid settled NGN amount.');
                    }
                    $wallet = $this->ci->db->query('SELECT * FROM wallets WHERE id=? FOR UPDATE', array($locked->wallet_id))->row();
                    if (!$wallet || (int)$wallet->user_id !== (int)$locked->user_id || $wallet->currency !== $locked->currency) {
                        throw new RuntimeException('The receiving wallet changed; manual reconciliation required.');
                    }
                    $net = $this->net((string)$res['amount'], $locked->margin_percent);
                    // Customer agreed to a maximum quote; never issue an unexpectedly large credit.
                    if (bccomp($net, $locked->estimated_payout, 8) > 0) $net = $locked->estimated_payout;
                    if (!self::positive_decimal($net)) throw new RuntimeException('The settled payout is not positive.');
                    $credit = $this->ci->ledgerservice->credit($wallet->id, $net, 'GIFTCARD_SALE',
                        'GiftcardSale', (string)$locked->id, 'giftcard-sale:'.$locked->public_id,
                        array('sale' => $locked->public_id));
                    if (empty($credit['ok'])) throw new RuntimeException('Wallet credit could not be completed.');
                    $fields['provider_amount'] = bcadd((string)$res['amount'], '0', 8);
                    $fields['credited_amount'] = $net;
                    $fields['status'] = 'CREDITED';
                    $this->audit($actor_id, 'giftcard.sale.credited', $id, array('amount' => $net, 'currency' => $locked->currency));
                }
            }
            if (!$this->ci->Giftcard_sale_model->update_locked($id, $fields)
                || !$this->ci->db->trans_status()) throw new RuntimeException('Could not save reconciliation.');
            if (!$this->ci->db->trans_commit()) throw new RuntimeException('Could not commit settlement.');
            return array('ok' => true);
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            // Only locally authored messages here; never append a provider body/code.
            return $this->err('Sale could not be settled. Check provider identity, currency, amount and wallet configuration.');
        }
    }
    public function settle_open($limit = 50) {
        $processed = 0; $failed = 0;
        foreach ($this->ci->Giftcard_sale_model->open_sales($limit) as $sale) {
            try { $res = $this->reconcile($sale->id); }
            catch (Throwable $e) { $res = array('ok' => false); }
            $processed++; if (empty($res['ok'])) $failed++;
        }
        $this->purge_terminal();
        return array('processed' => $processed, 'failed' => $failed, 'message' => 'Gift-card sales reconciled; unknown trades remain pending.');
    }
    /** Remove sensitive codes/images 30 days after terminal settlement; leave audit/ledger intact. */
    public function purge_terminal($limit = 25) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $limit = max(1, min(100, (int)$limit));
        $rows = $this->ci->db->select('id')->where_in('status', array('CREDITED', 'REJECTED'))
            ->where('updated_at <', $cutoff)
            ->where('code_encrypted IS NOT NULL', null, false)->order_by('id', 'ASC')->limit($limit)->get('giftcard_sales')->result();
        $purged = 0;
        foreach ($rows as $row) {
            if (!$this->ci->db->trans_begin()) continue;
            $changed = false;
            try {
                $sale = $this->ci->Giftcard_sale_model->lock_for_update($row->id);
                if ($sale && in_array($sale->status, array('CREDITED', 'REJECTED'), true)
                    && $sale->updated_at < $cutoff && $sale->code_encrypted !== null) {
                    $this->audit(null, 'giftcard.sale.secrets_purged', $sale->id);
                    if (($sale->card_form ?? 'Ecode') === 'Physical') {
                        $manifest = json_decode((string)$this->ci->encryptionservice->open($sale->code_encrypted), true);
                        if (!is_array($manifest)) throw new RuntimeException('Image manifest unavailable.');
                        $this->ci->load->library('GiftcardImageStore');
                        $this->ci->giftcardimagestore->delete($manifest);
                    }
                    if (!$this->ci->Giftcard_sale_model->update_locked($sale->id, array('code_encrypted' => null))) throw new RuntimeException('Purge failed.');
                    if (!$this->ci->db->trans_status()) throw new RuntimeException('Purge failed.');
                    $changed = true;
                }
                if (!$this->ci->db->trans_commit()) throw new RuntimeException('Purge commit failed.');
                if ($changed) $purged++;
            } catch (Throwable $e) { $this->ci->db->trans_rollback(); log_message('error', 'Gift-card secrets purge requires attention: '.(int)$row->id); }
        }
        return $purged;
    }
    private function provider() {
        $p = $this->ci->Provider_model->find_by_id((int)$this->ci->config->item('prestmit_sell_provider_id'));
        return $p && $p->api_type === 'PRESTMIT' && $p->status === 'ACTIVE' && $p->currency === 'NGN' ? $p : null;
    }
    private function provider_for_sale($sale, $active) {
        $p = $this->ci->Provider_model->find_by_id($sale->provider_id);
        return $p && $p->api_type === 'PRESTMIT' && $p->currency === $sale->currency
            && rtrim($p->api_url, '/') === $sale->provider_url && (!$active || $p->status === 'ACTIVE') ? $p : null;
    }
    private function adapter($p) { return $this->ci->provider_manager->adapter($p, Provider_manager::FAMILY_GIFTCARD); }
    private function net($gross, $margin) { return bcmul($gross, bcdiv(bcsub('100', $margin, 4), '100', 8), 8); }
    public static function positive_decimal($value) {
        return is_string($value) && preg_match('/^\d{1,10}(\.\d{1,8})?$/D', $value) && bccomp($value, '0', 8) > 0;
    }
    /** Local-only state and audit must commit together. Never put HTTP or
     * filesystem deletion inside this callback. A failed COMMIT is ambiguous:
     * it must not authorize an external call or deletion of attached files.
     */
    private function audited_local_change(callable $write, $actor, $action, $id) {
        if (!$this->ci->db->trans_begin()) return array('ok' => false, 'cleanup_safe' => true);
        $commit_started = false;
        try {
            if (!$write()) throw new RuntimeException('Sale changed.');
            $this->audit($actor, $action, $id);
            if (!$this->ci->db->trans_status()) throw new RuntimeException('Sale audit transaction failed.');
            $commit_started = true;
            if (!$this->ci->db->trans_commit()) throw new RuntimeException('Sale audit commit failed.');
            return array('ok' => true);
        } catch (Throwable $e) {
            try { $rolled_back = $this->ci->db->trans_rollback(); }
            catch (Throwable $ignored) { $rolled_back = false; }
            if ($commit_started || !$rolled_back) {
                log_message('error', 'Gift-card local state requires reconciliation: '.(int)$id);
            }
            return array('ok' => false, 'cleanup_safe' => !$commit_started && $rolled_back);
        }
    }
    private function discard_images(array $manifest, $id) {
        try { $this->ci->giftcardimagestore->delete($manifest); }
        catch (Throwable $e) { log_message('error', 'Gift-card rejected-image cleanup requires attention: '.(int)$id); }
    }
    private function audit($actor, $action, $id, $safe = null) {
        if (!$this->ci->Audit_log_model->record($actor, $action, 'giftcard_sales', (string)$id, null, $safe)) {
            throw new RuntimeException('Gift-card audit could not be saved.');
        }
    }
    private function err($message) { return array('ok' => false, 'error' => $message); }
}
