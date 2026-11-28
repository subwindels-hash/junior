<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Signed notifications are hints, never proof of delivery, failure or money. */
class PrestmitWebhookService {
    private $ci;
    public function __construct() { $this->ci =& get_instance(); }

    public static function valid_signature($raw, $signature, $secret) {
        return is_string($secret) && strlen($secret) >= 16 && is_string($signature)
            && strlen($signature) === 44
            && hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $signature);
    }

    public function receive($provider_id, $raw, $signature) {
        $secrets = $this->ci->config->item('prestmit_webhook_secrets');
        $secret = is_array($secrets) ? ($secrets[(string)$provider_id] ?? '') : '';
        if (!self::valid_signature($raw, $signature, $secret)) return array('status' => 401);
        if (strlen($raw) > 65536) return array('status' => 413);
        $body = json_decode($raw, true, 32);
        if (!is_array($body) || !is_string($body['event'] ?? null) || !is_array($body['data'] ?? null)) {
            return array('status' => 400);
        }
        $event = $body['event'];
        if (!in_array($event, array('giftcard-trade.buy.approved', 'giftcard-trade.buy.rejected',
            'giftcard-trade.sell.approved', 'giftcard-trade.sell.rejected'), true)) {
            // Other notifications cannot mark manual payouts PAID.
            return array('status' => 200);
        }
        $this->ci->load->model('Provider_model');
        $provider = $this->ci->Provider_model->find_by_id((int)$provider_id);
        if (!$provider || $provider->api_type !== 'PRESTMIT') return array('status' => 200);
        $key = $body['data']['partnersApiIdentifier'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key)) return array('status' => 200);
        try {
            if (strpos($event, 'giftcard-trade.sell.') === 0) {
                $this->ci->load->model('Giftcard_sale_model');
                $sale = $this->ci->Giftcard_sale_model->public_row($key);
                if (!$sale || (int)$sale->provider_id !== (int)$provider_id
                    || !in_array($sale->status, array('SUBMITTING', 'PENDING'), true)) return array('status' => 200);
                $this->ci->load->library('GiftcardSellService');
                $res = $this->ci->giftcardsellservice->reconcile($sale->id);
            } else {
                $this->ci->load->model(array('Service_transaction_model', 'Giftcard_order_model'));
                $tx = $this->ci->Service_transaction_model->find_by_public_id($key);
                if (!$tx || $tx->service_domain !== 'GIFTCARD' || (int)$tx->provider_id !== (int)$provider_id
                    || !in_array($tx->status, array('PROCESSING', 'SUCCESSFUL'), true)) return array('status' => 200);
                $order = $this->ci->Giftcard_order_model->for_transaction($tx->id);
                if (!$order || $order->status === 'DELIVERED') return array('status' => 200);
                $this->ci->load->library('GiftcardService');
                $res = $this->ci->giftcardservice->collect($order, 'WEBHOOK');
            }
            // A non-2xx asks the provider to retry; the cron remains the fallback.
            return array('status' => !empty($res['ok']) ? 200 : 503);
        } catch (Throwable $e) {
            log_message('error', 'Prestmit webhook reconciliation failed for provider '.(int)$provider_id);
            return array('status' => 503);
        }
    }
}
