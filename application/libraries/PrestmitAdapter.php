<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/GiftcardProviderInterface.php';
require_once __DIR__.'/SecureHttpClient.php';

/**
 * Prestmit partner gift-card buy and e-code sell integration. See docs/module-prestmit-provider.md.
 * uniqueIdentifier is a reconciliation key, not an assumed exactly-once API.
 * An uncertain create is held for history reconciliation, NEVER retried/refunded.
 */
class PrestmitAdapter implements GiftcardProviderInterface {
    const SANDBOX_URL = 'https://dev-api.prestmit.io/partners/v1';
    const LIVE_URL = 'https://api.prestmit.io/partners/v1';
    const PENDING_PREFIX = 'prestmit-pending:';

    private $provider;
    private $http;
    private $base;
    private $credentials;
    private $catalogue = null;

    public function __construct($provider, $http = null) {
        $this->provider = $provider;
        $this->base = rtrim(trim((string)($provider->api_url ?? '')), '/');
        $this->http = $http ?: new SecureHttpClient();
    }

    public static function valid_url($url) {
        return in_array(rtrim(trim((string)$url), '/'), array(self::SANDBOX_URL, self::LIVE_URL), true);
    }

    public static function environment_allows_url($url, $environment = null) {
        $environment = $environment ?? (defined('ENVIRONMENT') ? ENVIRONMENT : (getenv('CI_ENV') ?: getenv('APP_ENV')));
        return !in_array(strtolower((string)$environment), array('prod', 'production'), true)
            || rtrim((string)$url, '/') === self::LIVE_URL;
    }

    public function balance() {
        $res = $this->request('GET', '/general/user');
        if (empty($res['ok'])) return $res;
        $data = $res['data']['data'] ?? $res['data'];
        $currency = $this->currency();
        $field = $currency === 'GHS' ? 'cedisBalance' : 'nairaBalance';
        if (!in_array($currency, array('NGN', 'GHS'), true) || !isset($data[$field])
            || !is_numeric($data[$field])) {
            return $this->error('Prestmit balance requires an NGN or GHS provider currency and a valid wallet response.');
        }
        if (!empty($data['isFrozen']) || (isset($data['hasAPIAccess']) && !$data['hasAPIAccess'])) {
            return $this->error('Prestmit partner access is disabled or the account is frozen.');
        }
        return array('ok' => true, 'balance' => $this->number($data[$field]), 'currency' => $currency);
    }

    /** Read-only treasury methods. Values are reported, never converted or settled. */
    public function fiat_wallet() {
        $wallet = $this->fiat_name();
        if ($wallet === null) return $this->error('Treasury requires an NGN or GHS provider.');
        $res = $this->request('GET', '/wallet/fiat/details', array('wallet' => $wallet));
        if (empty($res['ok'])) return $res;
        $body = $res['data'];
        $data = $body['data'] ?? $body;
        if (!is_array($data)) return $this->error('Invalid Prestmit wallet details.');
        $data = $data['wallet'] ?? $data;
        if (!is_array($data) || (isset($data['currency']) && $data['currency'] !== $wallet)) {
            return $this->error('Prestmit returned a different wallet.');
        }
        $field = $wallet === 'NAIRA' ? 'nairaBalance' : 'cedisBalance';
        $balance = self::reported_decimal($data[$field] ?? $data['balance'] ?? null);
        if ($balance === null) return $this->error('Prestmit wallet balance is missing or invalid.');
        $out = array('ok' => true, 'currency' => $this->currency(), 'wallet' => $wallet,
            'reported_balance' => $balance, 'reported_pending' => null, 'reported_total' => null);
        foreach (array('pendingBalance' => 'reported_pending', 'totalBalance' => 'reported_total') as $from => $to) {
            if (array_key_exists($from, $data)) {
                $out[$to] = self::reported_decimal($data[$from]);
                if ($out[$to] === null) return $this->error('Invalid Prestmit wallet totals.');
            }
        }
        return $out;
    }

    public function bank_accounts($page = 1) {
        if ($this->fiat_name() === null || !self::treasury_page($page)) return $this->error('Invalid bank-account page or currency.');
        $segment = $this->currency() === 'GHS' ? 'cedis' : 'naira';
        $res = $this->request('GET', '/bank-accounts/'.$segment.'/list', array('page' => (int)$page));
        if (empty($res['ok'])) return $res;
        $body = $res['data'];
        $rows = $body['data'] ?? null;
        if (!self::bounded_rows($rows)) return $this->error('Invalid Prestmit bank-account list.');
        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || self::display_text($row['id'] ?? null) === '') return $this->error('Invalid Prestmit bank-account record.');
            $items[] = self::safe_bank($row);
        }
        return array('ok' => true, 'items' => $items, 'page' => (int)$page,
            'next_page' => self::next_treasury_page($body, (int)$page));
    }

    public function payout_history($page = 1) {
        $wallet = $this->fiat_name();
        if ($wallet === null || !self::treasury_page($page)) return $this->error('Invalid payout-history page or currency.');
        $res = $this->request('GET', '/wallet/fiat/withdrawal-history', array('page' => (int)$page, 'wallet' => $wallet));
        if (empty($res['ok'])) return $res;
        $body = $res['data'];
        $data = $body['data'] ?? null;
        $rows = is_array($data) && array_key_exists('withdrawals', $data) ? $data['withdrawals'] : $data;
        if (!self::bounded_rows($rows)) return $this->error('Invalid Prestmit payout history.');
        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || self::display_text($row['id'] ?? null) === ''
                || self::reported_decimal($row['amount'] ?? null) === null) return $this->error('Invalid Prestmit payout record.');
            $items[] = array('id' => self::display_text($row['id']),
                'reference' => self::display_text($row['reference'] ?? null),
                'reported_amount' => self::reported_decimal($row['amount']),
                'status' => self::display_text($row['status'] ?? null),
                'created_at' => self::display_text($row['createdAt'] ?? null),
                'bank' => self::safe_bank(is_array($row['bankAccount'] ?? null) ? $row['bankAccount'] : array()));
        }
        // Public guide and reference document two different response envelopes.
        $pagination = is_array($data) && isset($data['pagination']) ? $data['pagination'] : null;
        return array('ok' => true, 'items' => $items, 'page' => (int)$page,
            'next_page' => self::next_treasury_page($body, (int)$page, $pagination));
    }

    public static function treasury_page($page) {
        return (is_int($page) || is_string($page)) && preg_match('/^[1-9][0-9]{0,4}$/D', (string)$page)
            && (int)$page <= 10000;
    }
    private function fiat_name() {
        return $this->currency() === 'NGN' ? 'NAIRA' : ($this->currency() === 'GHS' ? 'CEDIS' : null);
    }
    private static function reported_decimal($value) {
        // No float arithmetic or guessed major/minor-unit conversion.
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        $value = (string)$value;
        return preg_match('/^-?[0-9]{1,20}(\.[0-9]{1,8})?$/D', $value) ? $value : null;
    }
    private static function display_text($value) {
        if (!is_string($value) && !is_int($value)) return '';
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string)$value), 0, 160);
    }
    private static function safe_bank(array $row) {
        $number = $row['accountNumber'] ?? null;
        $masked = 'Hidden';
        if (is_string($number) && preg_match('/^[0-9]{5,32}$/D', $number)) $masked = '•••• '.substr($number, -4);
        // Names, full numbers, customer correlation keys, receipt URLs and
        // arbitrary nested provider fields must never escape this projection.
        return array('id' => self::display_text($row['id'] ?? null),
            'bank' => self::display_text($row['bank'] ?? $row['bankName'] ?? null),
            'account_masked' => $masked, 'status' => self::display_text($row['status'] ?? null));
    }
    private static function bounded_rows($rows) {
        return is_array($rows) && count($rows) <= 100 && (!$rows || array_keys($rows) === range(0, count($rows) - 1));
    }
    private static function next_treasury_page(array $body, $page, $pagination = null) {
        if ($page >= 10000) return null;
        $last = is_array($pagination) ? ($pagination['totalPages'] ?? null) : ($body['meta']['last_page'] ?? null);
        if (self::treasury_page($last)) return $page < (int)$last ? $page + 1 : null;
        // Never follow upstream URLs or pass them to a browser with credentials.
        return is_string($body['links']['next'] ?? null) ? $page + 1 : null;
    }

    /** All imports remain inactive/unpriced until an operator reviews them. */
    public function products($country = null) {
        if ($this->catalogue === null) {
            $all = array();
            // Never follow vendor-supplied pagination URLs with our secrets.
            for ($page = 1; $page <= 400; $page++) {
                $res = $this->request('GET', '/giftcard-trade/buy/fetch-buyable-giftcards',
                    array('page' => $page, 'perPage' => 25));
                if (empty($res['ok'])) return $res;
                $body = $res['data'];
                if (!isset($body['data']) || !is_array($body['data'])) {
                    return $this->error('Prestmit returned an invalid catalogue.');
                }
                $all = array_merge($all, $body['data']);
                $last = $body['meta']['last_page'] ?? null;
                if (($last !== null && $page >= (int)$last)
                    || (array_key_exists('next', $body['links'] ?? array()) && $body['links']['next'] === null)
                    || (!$last && count($body['data']) < 25)) break;
                if ($page === 400) return $this->error('Prestmit catalogue exceeded the pagination safety limit.');
            }
            $this->catalogue = $all;
        }
        $products = array();
        foreach ($this->catalogue as $card) {
            if (!is_array($card) || empty($card['sku']) || !empty($card['preOrder'])) continue;
            $min = $card['minPrice'] ?? null;
            $max = $card['maxPrice'] ?? null;
            $currency = strtoupper((string)($card['currency']['code'] ?? ''));
            if (!is_numeric($min) || !is_numeric($max) || $min <= 0 || $max < $min
                || !preg_match('/^[A-Z]{3}$/', $currency)) continue;
            $regions = $card['regions'] ?? array();
            // Do not invent US eligibility for a card without region metadata.
            foreach ($regions as $region) {
                $code = strtoupper((string)($region['code'] ?? ''));
                if (!preg_match('/^[A-Z]{2}$/', $code) || ($country && $code !== strtoupper($country))) continue;
                // Curated fixed amounts fit the existing fixed-denomination checkout.
                // Provider quote validation remains authoritative at purchase time.
                $values = array_unique(array_merge(array((float)$min, (float)$max), array(10, 25, 50, 100)));
                foreach ($values as $value) {
                    if ($value < $min || $value > $max) continue;
                    $products[] = array(
                        'provider_product_id' => (string)$card['sku'],
                        // Keep distinct SKUs/currencies/providers from overwriting each other.
                        'catalogue_key' => 'PRESTMIT-'.($this->provider->id ?? '0').'-'.$card['sku'].'-'.$currency,
                        'name' => ($card['title'] ?? 'Gift card').' '.$value.' '.$currency,
                        'brand_id' => (string)$card['sku'],
                        'brand_name' => (string)($card['title'] ?? 'Gift card'),
                        'country_code' => $code, 'denomination_type' => 'FIXED',
                        'recipient_currency' => $currency, 'face_value' => $this->number($value),
                        'cost' => null, // face value is NOT the partner's NGN/GHS cost
                        'logo_url' => $card['imageUrl'] ?? null,
                        'redeem_instructions' => $card['description']['content'] ?? null,
                    );
                }
            }
        }
        return array('ok' => true, 'products' => $products);
    }

    public function order(array $payload) {
        $id = trim((string)($payload['reference'] ?? ''));
        $sku = (string)($payload['product_id'] ?? '');
        $price = $payload['unit_price'] ?? null;
        $quantity = $payload['quantity'] ?? 1;
        if ($id === '' || !ctype_digit($sku) || (int)$sku < 1 || !is_numeric($price) || $price <= 0
            || !is_numeric($quantity) || (int)$quantity != $quantity || $quantity < 1) {
            return $this->error('Prestmit requires a SKU, positive denomination, quantity and internal reference.');
        }
        $creds = $this->credentials();
        if (!preg_match('/^[0-9]{4,6}$/', $creds['account_pin'])) {
            return $this->error('Configure the Prestmit account PIN before enabling purchases.');
        }
        $currency = $this->currency();
        $base = function_exists('marvy_base_currency') ? marvy_base_currency() : 'NGN';
        if (!in_array($currency, array('NGN', 'GHS'), true) || $base !== $currency) {
            return $this->error('Prestmit purchases require the provider and panel base currency to match (NGN or GHS).');
        }
        $budget = $payload['max_cost'] ?? null;
        if (!is_numeric($budget) || $budget <= 0) return $this->error('A purchase cost ceiling is required.');

        // Read before write: a repeated dispatch must not buy the card again.
        $existing = $this->lookup($id, true);
        if (empty($existing['ok'])) return $existing;
        if (!empty($existing['trade'])) return $this->accepted($existing['trade'], $id);

        $details = array('giftCardSKU' => (int)$sku, 'price' => (float)$price, 'quantity' => (int)$quantity);
        $quote = $this->request('POST', '/giftcard-trade/buy/calculate-payment', $details);
        if (empty($quote['ok'])) return $quote;
        $amounts = $quote['data']['data'] ?? $quote['data'];
        $method = $currency === 'GHS' ? 'CEDIS' : 'NAIRA';
        $cost = $amounts[$method] ?? null;
        if (!is_numeric($cost) || $cost <= 0 || bccomp($this->number($cost), $this->number($budget), 8) > 0) {
            return $this->error('Prestmit quote is unavailable or exceeds the selling price. Review pricing before retrying.');
        }
        $body = $details + array('paymentMethod' => $method, 'uniqueIdentifier' => $id,
            'currentAccountPIN' => $creds['account_pin']);
        $res = $this->request('POST', '/giftcard-trade/buy/create', $body);
        // A timeout/5xx/invalid success may have spent money. Preserve the local
        // key and reconcile via history; never turn this into a refund or retry.
        if (!empty($res['uncertain'])) return $this->accepted(array(), $id);
        if (empty($res['ok'])) return $res;
        $trade = $res['data']['data'] ?? array();
        return $this->accepted(is_array($trade) ? $trade : array(), $id);
    }

    /** Sell-side methods intentionally separate from GiftcardProviderInterface's buy verbs. */
    public function sell_catalogue($include_physical = false) {
        $res = $this->request('GET', '/giftcard-trade/sell/rate-calculator-data');
        if (empty($res['ok'])) return $res;
        $data = $res['data']['data'] ?? $res['data'];
        if (!is_array($data) || !is_array($data['sellableGiftcards'] ?? null) || !is_array($data['sellGiftcardPayoutMethods'] ?? null)) {
            return $this->error('Prestmit returned invalid sell configuration.');
        }
        $available = false;
        foreach ($data['sellGiftcardPayoutMethods'] as $method) {
            if (($method['name'] ?? '') === 'NAIRA' && ($method['available'] ?? false) === true) $available = true;
        }
        $cards = array();
        if ($available) foreach ($data['sellableGiftcards'] as $card) {
            if (!is_array($card)) continue;
            $form = strtolower(trim((string)($card['form'] ?? '')));
            if ($form !== 'ecode' && !($include_physical && $form === 'physical')) continue;
            if (empty($card['id']) || !is_numeric($card['rate'] ?? null) || $card['rate'] <= 0) continue;
            $cards[] = array('id' => (string)$card['id'], 'form' => $form === 'physical' ? 'Physical' : 'Ecode', 'name' => (string)($card['name'] ?? ''),
                'rate' => $card['rate'], 'minimum' => $card['minimum'] ?? 0,
                'terms' => strip_tags((string)($card['terms'] ?? '')),
                'country' => (string)($card['country'] ?? ''));
        }
        return array('ok' => true, 'cards' => $cards);
    }

    public function sell_create(array $payload) {
        if ($this->currency() !== 'NGN' || empty($payload['reference']) || (empty($payload['code']) && empty($payload['attachments']))
            || !ctype_digit((string)($payload['giftcard_id'] ?? ''))
            || !is_numeric($payload['amount'] ?? null) || $payload['amount'] <= 0) {
            return $this->error('Invalid NGN e-code sell request.');
        }
        $body = array('giftcard_id' => (string)$payload['giftcard_id'],
            'amount' => (string)$payload['amount'], 'payoutMethod' => 'NAIRA',
            'comments' => (string)($payload['code'] ?? 'Physical gift-card images'), 'uniqueIdentifier' => (string)$payload['reference']);
        $res = $this->request('POST', '/giftcard-trade/sell/create', $body, true, $payload['attachments'] ?? array());
        if (empty($res['ok'])) return $res;
        $trade = $res['data']['trade'] ?? null;
        // Never expose upstream comments (which contain the code) to callers.
        if (!is_array($trade) || empty($trade['reference'])) {
            return array('ok' => false, 'uncertain' => true, 'error' => 'Sell submission requires reconciliation.');
        }
        return array('ok' => true, 'reference' => (string)$trade['reference']);
    }

    public function sell_status($reference, $internal = false) {
        $filter = $internal ? 'uniqueIdentifier' : 'referenceOrID';
        $res = $this->request('GET', '/giftcard-trade/sell/history', array($filter => (string)$reference, 'perPage' => 25));
        if (empty($res['ok'])) return $res;
        $rows = $res['data']['data'] ?? null;
        if (!is_array($rows)) return $this->error('Invalid Prestmit sell history.');
        if (!$rows) return array('ok' => true, 'found' => false);
        if (count($rows) !== 1 || empty($rows[0]['reference'])) return $this->error('Ambiguous Prestmit sell history.');
        $trade = $rows[0];
        if ((!$internal && (string)$trade['reference'] !== (string)$reference)
            || ($internal && isset($trade['uniqueIdentifier']) && (string)$trade['uniqueIdentifier'] !== (string)$reference)) {
            return $this->error('Prestmit sell history did not match the reference.');
        }
        return array('ok' => true, 'found' => true, 'reference' => (string)$trade['reference'],
            'status' => strtoupper((string)($trade['status'] ?? 'PENDING')),
            'giftcard_id' => (string)($trade['giftcard']['id'] ?? ''),
            'payout_method' => $trade['payoutMethod'] ?? null,
            'amount' => $trade['totalAmount'] ?? null);
    }

    public function order_status($reference) {
        $pending = strpos((string)$reference, self::PENDING_PREFIX) === 0;
        $key = $pending ? substr($reference, strlen(self::PENDING_PREFIX)) : $reference;
        $res = $this->lookup($key, $pending);
        if (empty($res['ok'])) return $res;
        if (empty($res['trade'])) {
            return array('ok' => true, 'status' => 'PROCESSING', 'reference' => $reference, 'cost' => null);
        }
        $trade = $res['trade'];
        return array('ok' => true, 'status' => strtoupper((string)($trade['status'] ?? 'PROCESSING')),
            'reference' => (string)($trade['reference'] ?? $reference), 'cost' => $this->cost($trade));
    }

    public function codes($reference) {
        $status = $this->order_status($reference);
        if (empty($status['ok'])) return $status;
        if (in_array($status['status'], array('FAILED', 'REFUNDED'), true)) {
            return array('ok' => true, 'ready' => false, 'cards' => array(), 'terminal_failure' => true);
        }
        if ($status['status'] !== 'COMPLETED') {
            return array('ok' => true, 'ready' => false, 'cards' => array());
        }
        $res = $this->request('GET', '/giftcard-trade/buy/fetch-codes/'.rawurlencode($status['reference']));
        if (empty($res['ok'])) return $res;
        $rows = $res['data']['data'] ?? $res['data'];
        $cards = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $number = trim((string)($row['cardNumber'] ?? ''));
            $pin = trim((string)($row['pinCode'] ?? ''));
            if ($number === '' && $pin === '') continue;
            $cards[] = array('card_number' => $number, 'pin' => $pin ?: null,
                'redemption_url' => $row['claimUrl'] ?? null,
                'expires_on' => empty($row['expireDate']) ? null : $row['expireDate']);
        }
        return array('ok' => true, 'ready' => count($cards) > 0, 'cards' => $cards);
    }

    private function lookup($reference, $internal) {
        $filter = $internal ? 'uniqueIdentifier' : 'referenceOrID';
        $res = $this->request('GET', '/giftcard-trade/buy/history', array($filter => (string)$reference, 'perPage' => 25));
        if (empty($res['ok'])) return $res;
        $rows = $res['data']['data'] ?? null;
        if (!is_array($rows)) return $this->error('Prestmit returned invalid transaction history.');
        if (!$rows) return array('ok' => true, 'trade' => null);
        // Filtered internal history is documented not to echo uniqueIdentifier;
        // accept only a single result, never guess among several transactions.
        if ($internal) {
            if (count($rows) !== 1 || empty($rows[0]['reference'])) {
                return $this->error('Prestmit returned ambiguous transaction history; manual reconciliation required.');
            }
            if (isset($rows[0]['uniqueIdentifier']) && (string)$rows[0]['uniqueIdentifier'] !== (string)$reference) {
                return $this->error('Prestmit history did not match the internal reference.');
            }
            return array('ok' => true, 'trade' => $rows[0]);
        }
        foreach ($rows as $row) {
            if (isset($row['reference']) && (string)$row['reference'] === (string)$reference) {
                return array('ok' => true, 'trade' => $row);
            }
        }
        return $this->error('Prestmit history did not match the order reference.');
    }

    private function accepted(array $trade, $internal) {
        return array('ok' => true, 'status' => 'PLACED',
            'reference' => !empty($trade['reference']) ? (string)$trade['reference'] : self::PENDING_PREFIX.$internal,
            'cost' => $this->cost($trade));
    }

    private function request($method, $path, array $params = array(), $multipart = false, array $attachments = array()) {
        if (!self::valid_url($this->base)) return $this->error('Use the official Prestmit sandbox or live partner API URL.');
        if (!self::environment_allows_url($this->base)) return $this->error('Prestmit sandbox providers cannot be used in production.');
        $c = $this->credentials();
        if ($c['api_key'] === '' || $c['api_secret'] === '') return $this->error('Prestmit requires an API key and API secret.');
        if (preg_match('/[\r\n]/', $c['api_key'])) return $this->error('Prestmit key contains invalid header characters.');
        $json = json_encode($params ?: (object)array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $wire = $json;
        $content_type = 'application/json';
        if ($multipart) {
            $boundary = 'Prestmit'.bin2hex(random_bytes(16));
            $content_type = 'multipart/form-data; boundary='.$boundary;
            $wire = '';
            foreach ($params as $key => $value) {
                $wire .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".(string)$value."\r\n";
            }
            if (count($attachments) > 20) return $this->error('Too many gift-card images.');
            $total = 0;
            foreach ($attachments as $i => $image) {
                $bytes = $image['bytes'] ?? null;
                $mime = $image['mime'] ?? '';
                if (!is_string($bytes) || strlen($bytes) > 5242880 || strlen($bytes) < 1
                    || !in_array($mime, array('image/jpeg', 'image/png'), true)) return $this->error('Invalid gift-card image.');
                $total += strlen($bytes);
                if ($total > 20971520) return $this->error('Gift-card images exceed the upload budget.');
                // Fixed filenames and field names; no upload-controlled headers.
                $name = 'card-'.($i + 1).($mime === 'image/png' ? '.png' : '.jpg');
                $wire .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"attachments[]\"; filename=\"".$name."\"\r\nContent-Type: ".$mime."\r\n\r\n".$bytes."\r\n";
            }
            $wire .= '--'.$boundary."--\r\n";
        }
        $headers = array('Accept: application/json', 'Content-Type: '.$content_type,
            'API-KEY: '.$c['api_key'], 'API-Hash: '.hash_hmac('sha256', $c['api_key'].':'.$json, $c['api_secret']));
        $url = $this->base.$path;
        if ($method === 'GET' && $params) $url .= '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        try {
            $res = $this->http->request($method, $url, $method === 'GET' ? null : $wire, $headers,
                array('max_retries' => 0, 'follow_redirects' => false,
                    'timeout' => max(1, min(60, (int)ceil(($this->provider->timeout_ms ?? 15000) / 1000)))));
        } catch (Throwable $e) {
            $res = array('http_code' => 0, 'body' => null);
        }
        $code = (int)($res['http_code'] ?? 0);
        $data = json_decode((string)($res['body'] ?? ''), true);
        $uncertain = $code === 0 || $code >= 500 || in_array($code, array(408, 409, 429), true)
            || ($code >= 200 && $code < 300 && !is_array($data));
        if ($code < 200 || $code >= 300 || !is_array($data)
            || (isset($data['success']) && $data['success'] === false)) {
            // Do not return upstream body/errors: they may echo PINs or card codes.
            $error = $this->error('Prestmit request failed (HTTP '.$code.'). Check partner access, credentials, PIN/2FA policy and provider status.');
            $error['uncertain'] = $uncertain;
            return $error;
        }
        return array('ok' => true, 'data' => $data);
    }

    private function credentials() {
        if ($this->credentials !== null) return $this->credentials;
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        $raw = $ci->encryptionservice->open($this->provider->api_key_encrypted ?? '');
        $decoded = json_decode((string)$raw, true);
        $out = array('api_key' => '', 'api_secret' => '', 'account_pin' => '');
        foreach ($out as $key => $unused) {
            if (is_array($decoded) && isset($decoded[$key]) && is_scalar($decoded[$key])) $out[$key] = trim((string)$decoded[$key]);
        }
        return $this->credentials = $out;
    }

    private function currency() { return strtoupper((string)($this->provider->currency ?? 'NGN')); }
    private function cost(array $trade) {
        $method = $this->currency() === 'GHS' ? 'CEDIS' : 'NAIRA';
        $base = function_exists('marvy_base_currency') ? marvy_base_currency() : 'NGN';
        return $base === $this->currency() && ($trade['paymentMethod'] ?? '') === $method
            && isset($trade['totalPaymentAmount']) && is_numeric($trade['totalPaymentAmount'])
            ? $this->number($trade['totalPaymentAmount']) : null;
    }
    private function number($value) { return number_format((float)$value, 8, '.', ''); }
    private function error($message) { return array('ok' => false, 'error' => $message); }
}
