<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Admin-only inspection of the PARTNER account; never a customer payout engine. */
class PrestmitTreasuryService {
    private $ci;
    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Provider_model', 'Audit_log_model'));
        $this->ci->load->library('Provider_manager');
        require_once __DIR__.'/PrestmitAdapter.php';
    }
    /** Trusted staff caller must enforce providers.view + providers.manage. */
    public function inspect($public_id, $actor_id, $bank_page = 1, $history_page = 1) {
        if ((int)$actor_id <= 0) return $this->err('Staff authentication is required.');
        if (!is_string($public_id) || !PrestmitAdapter::treasury_page($bank_page)
            || !PrestmitAdapter::treasury_page($history_page)) return $this->err('Invalid treasury page.');
        $provider = $this->ci->Provider_model->find_by_public_id($public_id);
        if (!$provider || $provider->api_type !== 'PRESTMIT' || !in_array($provider->currency, array('NGN', 'GHS'), true)
            || !PrestmitAdapter::valid_url($provider->api_url)
            || !PrestmitAdapter::environment_allows_url($provider->api_url)) return $this->err('Select a valid Prestmit provider in this environment.');
        // Operational refresh budget, not an atomic security or money limit.
        $recent = $this->ci->db->where('actor_id', (int)$actor_id)->where('action', 'prestmit.treasury.viewed')
            ->where('created_at >=', gmdate('Y-m-d H:i:s', time() - 60))->count_all_results('audit_logs');
        if ($recent >= 10) return $this->err('Treasury refresh limit reached. Wait one minute before trying again.');
        try {
            // Fail closed if the access cannot be audited. No bank data or
            // upstream body is written to the audit trail, sessions or cache.
            if (!$this->ci->Audit_log_model->record((int)$actor_id, 'prestmit.treasury.viewed', 'providers',
                $provider->public_id, null, array('bank_page' => (int)$bank_page, 'history_page' => (int)$history_page))) {
                return $this->err('Treasury access could not be audited.');
            }
            $adapter = $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_GIFTCARD);
            $wallet = $adapter->fiat_wallet();
            $banks = $adapter->bank_accounts((int)$bank_page);
            $history = $adapter->payout_history((int)$history_page);
        } catch (Throwable $e) {
            log_message('error', 'Prestmit treasury inspection failed for provider '.(int)$provider->id);
            return $this->err('Prestmit treasury is unavailable. No transfer was attempted.');
        }
        return array('ok' => true, 'transfers_enabled' => false,
            // Never send the full provider object (contains encrypted credentials) to a view.
            'provider' => array('public_id' => $provider->public_id, 'name' => $provider->name,
                'currency' => $provider->currency, 'status' => $provider->status,
                'environment' => rtrim($provider->api_url, '/') === PrestmitAdapter::LIVE_URL ? 'Live' : 'Sandbox'),
            'wallet' => $wallet, 'banks' => $banks, 'history' => $history,
            'bank_page' => (int)$bank_page, 'history_page' => (int)$history_page,
            'checked_at' => gmdate('Y-m-d H:i:s'));
    }
    private function err($message) { return array('ok' => false, 'error' => $message); }
}
