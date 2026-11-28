<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/IntegrationHarness.php';
require_once dirname(__DIR__).'/_support/FakeDb.php';

class PrestmitAdapterTest extends TestCase {
    public static function setUpBeforeClass(): void {
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($level,$message){}');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        require_once dirname(__DIR__, 2).'/application/core/MY_Model.php';
        require_once dirname(__DIR__, 2).'/application/helpers/marvy_helper.php';
        require_once dirname(__DIR__, 2).'/application/libraries/PrestmitAdapter.php';
        require_once dirname(__DIR__, 2).'/application/libraries/Provider_manager.php';
        require_once dirname(__DIR__, 2).'/application/libraries/ProviderSyncService.php';
    }

    private function adapter($responses, $overrides = array()) {
        $ci = new stdClass();
        $ci->load = new PrestmitTestLoader();
        $ci->encryptionservice = new PrestmitTestEncryption();
        $GLOBALS['__fake_ci'] = $ci;
        $http = new PrestmitTestHttp($responses);
        $provider = (object)array_merge(array('id' => 42, 'api_url' => PrestmitAdapter::SANDBOX_URL,
            'api_key_encrypted' => json_encode(array('api_key' => 'test-key', 'api_secret' => 'test-secret', 'account_pin' => '0123')),
            'api_type' => 'PRESTMIT', 'currency' => 'NGN', 'timeout_ms' => 15000), $overrides);
        return array(new PrestmitAdapter($provider, $http), $http);
    }
    private function response($data, $code = 200) { return array('http_code' => $code, 'body' => json_encode($data)); }
    private function payload($overrides = array()) {
        return array_merge(array('product_id' => '922', 'unit_price' => '25.00000000', 'quantity' => 1,
            'reference' => 'OUR-REFERENCE', 'max_cost' => '40000.00000000'), $overrides);
    }
    private function buyResponses($last = null) {
        return array($this->response(array('data' => array())),
            $this->response(array('NAIRA' => 35000, 'CEDIS' => 350)),
            $last ?: $this->response(array('data' => array('reference' => 'BGC-123', 'status' => 'COMPLETED',
                'paymentMethod' => 'NAIRA', 'totalPaymentAmount' => 35000))));
    }

    public function testRegistryExposesPrestmitOnlyForGiftcards() {
        $this->assertContains('PRESTMIT', Provider_manager::supported_types('GIFTCARD'));
        $this->assertNotContains('PRESTMIT', Provider_manager::supported_types('VTU'));
        $this->assertSame('GIFTCARD', ProviderSyncService::family('PRESTMIT'));
    }
    public function testBalanceSignsEmptyObjectAndNeverFollowsRedirects() {
        list($adapter, $http) = $this->adapter(array($this->response(array('data' => array('nairaBalance' => 1234.50)))));
        $result = $adapter->balance();
        $this->assertTrue($result['ok']);
        $this->assertSame('1234.50000000', $result['balance']);
        $this->assertContains('API-KEY: test-key', $http->calls[0]['headers']);
        $this->assertContains('API-Hash: '.hash_hmac('sha256', 'test-key:{}', 'test-secret'), $http->calls[0]['headers']);
        $this->assertFalse($http->calls[0]['options']['follow_redirects']);
        $this->assertSame(0, $http->calls[0]['options']['max_retries']);
    }
    public function testOfficialUrlsOnly() {
        foreach (array('https://prestmit.com', 'https://api.prestmit.io.evil.test/partners/v1',
            'http://api.prestmit.io/partners/v1', 'https://api.prestmit.io/partners/v1?x=1') as $url) {
            list($adapter, $http) = $this->adapter(array(), array('api_url' => $url));
            $this->assertFalse($adapter->balance()['ok']);
            $this->assertCount(0, $http->calls);
        }
        $this->assertTrue(PrestmitAdapter::valid_url(PrestmitAdapter::LIVE_URL.'/'));
    }
    public function testBuyQuotesBeforeCreateAndKeepsPinAsString() {
        list($adapter, $http) = $this->adapter($this->buyResponses());
        $result = $adapter->order($this->payload());
        $this->assertTrue($result['ok']);
        $this->assertSame('PLACED', $result['status']);
        $this->assertSame('BGC-123', $result['reference']);
        $this->assertSame('35000.00000000', $result['cost']);
        $body = json_decode($http->calls[2]['data'], true);
        $this->assertSame('0123', $body['currentAccountPIN']);
        $this->assertSame('OUR-REFERENCE', $body['uniqueIdentifier']);
        $this->assertSame('NAIRA', $body['paymentMethod']);
        $this->assertContains('API-Hash: '.hash_hmac('sha256', 'test-key:'.$http->calls[2]['data'], 'test-secret'), $http->calls[2]['headers']);
        $this->assertStringContainsString('uniqueIdentifier=OUR-REFERENCE', $http->calls[0]['url']);
    }
    public function testExpiredQuoteCannotSpendMoreThanSaleProceeds() {
        list($adapter, $http) = $this->adapter($this->buyResponses());
        $this->assertFalse($adapter->order($this->payload(array('max_cost' => '30000')))['ok']);
        $this->assertCount(2, $http->calls);
    }
    public function testExistingOrderIsReconciledWithoutBuyingAgain() {
        list($adapter, $http) = $this->adapter(array($this->response(array('data' => array(array('reference' => 'BGC-EXISTS'))))));
        $result = $adapter->order($this->payload());
        $this->assertTrue($result['ok']);
        $this->assertSame('BGC-EXISTS', $result['reference']);
        $this->assertCount(1, $http->calls);
    }
    public function testTimeoutAndServerErrorsHoldForReconciliation() {
        foreach (array(0, 500, 408, 409, 429) as $code) {
            list($adapter, $http) = $this->adapter($this->buyResponses(array('http_code' => $code, 'body' => 'unavailable')));
            $result = $adapter->order($this->payload());
            $this->assertTrue($result['ok']);
            $this->assertSame('prestmit-pending:OUR-REFERENCE', $result['reference']);
            $this->assertCount(3, $http->calls);
        }
    }
    public function testMalformedSuccessIsNotAnAutomaticRefund() {
        list($adapter) = $this->adapter($this->buyResponses(array('http_code' => 200, 'body' => '<html>oops</html>')));
        $this->assertTrue($adapter->order($this->payload())['ok']);
    }
    public function testExplicitRejectionDoesNotEchoSecrets() {
        list($adapter) = $this->adapter($this->buyResponses($this->response(array('message' => 'PIN=0123; code=secret-card'), 422)));
        $result = $adapter->order($this->payload());
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('0123', $result['error']);
        $this->assertStringNotContainsString('secret-card', $result['error']);
    }
    public function testMissingCredentialsAndCurrencyMismatchMakeNoCalls() {
        foreach (array(array('api_key_encrypted' => '{}'), array('currency' => 'USD')) as $override) {
            list($adapter, $http) = $this->adapter(array(), $override);
            $this->assertFalse($adapter->order($this->payload())['ok']);
            $this->assertCount(0, $http->calls);
        }
    }
    public function testPendingOrderReconcilesUsingInternalIdentifier() {
        list($adapter, $http) = $this->adapter(array($this->response(array('data' => array()))));
        $result = $adapter->codes('prestmit-pending:OUR-REFERENCE');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['ready']);
        $this->assertCount(1, $http->calls);
        $this->assertStringContainsString('uniqueIdentifier=OUR-REFERENCE', $http->calls[0]['url']);
    }
    public function testCompletedTradeFetchesCodesOnlyAfterMatchingStatus() {
        list($adapter, $http) = $this->adapter(array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'CARD-123', 'pinCode' => '4567', 'expireDate' => '')))));
        $result = $adapter->codes('BGC-123');
        $this->assertTrue($result['ready']);
        $this->assertSame('CARD-123', $result['cards'][0]['card_number']);
        $this->assertSame('4567', $result['cards'][0]['pin']);
        $this->assertNull($result['cards'][0]['expires_on']);
        $this->assertStringContainsString('/fetch-codes/BGC-123', $http->calls[1]['url']);
    }
    public function testMismatchedHistoryCannotDeliverAnotherOrdersCodes() {
        list($adapter, $http) = $this->adapter(array($this->response(array('data' => array(array('reference' => 'OTHER', 'status' => 'COMPLETED'))))));
        $this->assertFalse($adapter->codes('BGC-123')['ok']);
        $this->assertCount(1, $http->calls);
    }
    public function testTerminalFailureDoesNotFetchCodes() {
        list($adapter, $http) = $this->adapter(array($this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'REFUNDED'))))));
        $result = $adapter->codes('BGC-123');
        $this->assertFalse($result['ready']);
        $this->assertTrue($result['terminal_failure']);
        $this->assertCount(1, $http->calls);
    }
    public function testCataloguePaginatesLocallyAndCreatesReviewableDenominations() {
        $card = array('sku' => 4611, 'title' => 'Amazon', 'minPrice' => 10, 'maxPrice' => 50,
            'currency' => array('code' => 'USD'), 'regions' => array(array('code' => 'US')));
        list($adapter, $http) = $this->adapter(array(
            $this->response(array('data' => array($card), 'meta' => array('last_page' => 2), 'links' => array('next' => 'https://evil.test'))),
            $this->response(array('data' => array(array_merge($card, array('sku' => 99, 'preOrder' => true))), 'meta' => array('last_page' => 2)))));
        $res = $adapter->products('US');
        $this->assertTrue($res['ok']);
        $this->assertCount(3, $res['products']);
        $this->assertSame('PRESTMIT-42-4611-USD', $res['products'][0]['catalogue_key']);
        $this->assertNull($res['products'][0]['cost']);
        $this->assertSame('FIXED', $res['products'][0]['denomination_type']);
        $this->assertStringContainsString(PrestmitAdapter::SANDBOX_URL, $http->calls[1]['url']);
        $this->assertCount(0, $adapter->products('GB')['products']);
        $this->assertCount(2, $http->calls); // cached between country syncs
    }
    private function purchaseApp($responses) {
        $app = new IntegrationHarness();
        $app->seed_minimal(); $app->seed_giftcards();
        $user = $app->register('prestmit_user', 'prestmit@x.test');
        $app->credit($user, '500000');
        $app->library('EncryptionService');
        $app->securehttpclient = new PrestmitTestHttp($responses);
        $product = $app->db->where('code', 'AMAZON-US-25')->get('giftcard_products')->row();
        $app->db->where('id', $product->provider_id)->update('providers', array(
            'api_type' => 'PRESTMIT', 'api_url' => PrestmitAdapter::SANDBOX_URL,
            'api_key_encrypted' => $app->encryptionservice->encrypt(json_encode(array(
                'api_key' => 'test-key', 'api_secret' => 'test-secret', 'account_pin' => '0123')))));
        $app->library('GiftcardService');
        return array($app, $user);
    }
    public function testRealPurchaseStackEncryptsCodesAndChargesOnlyOnce() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'REAL-CARD-123', 'pinCode' => '4567')))));
        list($app, $user) = $this->purchaseApp($responses);
        $input = array('product' => 'AMAZON-US-25', 'idempotency_key' => 'prestmit-test-key');
        $res = $app->giftcardservice->purchase($user, $input);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('SUCCESSFUL', $res['transaction']->status);
        $this->assertSame('DELIVERED', $res['order']->status);
        $this->assertSame('458000.00000000', $app->balance($user));
        $stored = $app->Giftcard_code_model->for_order($res['order']->id);
        $this->assertSame('REAL-CARD-123', $app->encryptionservice->open($stored[0]->card_number_encrypted));
        $this->assertNotSame('REAL-CARD-123', $stored[0]->card_number_encrypted);
        $again = $app->giftcardservice->purchase($user, $input);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('458000.00000000', $app->balance($user));
        $this->assertCount(5, $app->securehttpclient->calls);
    }
    public function testTimedOutCreateCannotBeRefundedWithoutConfirmedFailure() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())), // immediate collect
            $this->response(array('data' => array())), // admin write-off check
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'REFUNDED'))))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $refused = $app->giftcardservice->abandon($res['order'], 'ADMIN');
        $this->assertFalse($refused['ok']);
        $this->assertSame('RECONCILIATION_REQUIRED', $refused['code']);
        $this->assertSame('458000.00000000', $app->balance($user));
        $refunded = $app->giftcardservice->abandon($res['order'], 'ADMIN');
        $this->assertTrue($refunded['ok']);
        $this->assertSame('500000.00000000', $app->balance($user));
    }
    public function testPartialQuantityCannotSettleAMultiCardOrder() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'ONLY-ONE')))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'quantity' => 2));
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('PLACED', $res['order']->status);
        $this->assertCount(0, $res['cards']);
    }
    public function testGenericRecoveryAndDirectAdminRefundCannotRefundAnUnknownPurchase() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())),
            $this->response(array('data' => array())),
            $this->response(array('data' => array())),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'LATE-CARD')))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $app->db->where('id', $res['transaction']->id)->update('service_transactions', array('created_at' => gmdate('Y-m-d H:i:s', time() - 172800)));
        $app->library('CronWorkers');
        $recovery = $app->cronworkers->service_recovery();
        $this->assertSame(0, $recovery['refunded']);
        $admin = $app->transactionengine->transition($res['transaction']->id, 'REFUNDED', 'ADMIN');
        $this->assertFalse($admin['ok']);
        $this->assertSame('RECONCILIATION_REQUIRED', $admin['code']);
        $this->assertSame('PLACED', $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
        $this->assertSame('458000.00000000', $app->balance($user));
        $late = $app->giftcardservice->collect($res['order']);
        $this->assertTrue($late['ready']);
        $this->assertSame('SUCCESSFUL', $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
        $this->assertSame('458000.00000000', $app->balance($user));
    }
    public function testDeliveredPrestmitCardsCannotBeRefundedByTheGenericEngine() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'ISSUED-CARD')))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $this->assertSame('DELIVERED', $res['order']->status);
        foreach (array('FAILED', 'CANCELLED', 'REFUNDED') as $state) {
            $this->assertFalse($app->transactionengine->transition($res['transaction']->id, $state, 'ADMIN')['ok']);
        }
        $this->assertSame('458000.00000000', $app->balance($user));
        $this->assertCount(5, $app->securehttpclient->calls);
    }
    public function testConfirmedRefundClosesOrderAndStaleCollectorCannotDeliver() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'REFUNDED'))))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $this->assertTrue($app->transactionengine->transition($res['transaction']->id, 'FAILED', 'SYSTEM')['ok']);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertSame('FAILED', $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
        $late = $app->giftcardservice->collect($res['order']);
        $this->assertFalse($late['ok']);
        $this->assertSame('TERMINAL', $late['code']);
        $this->assertCount(0, $app->Giftcard_code_model->for_order($res['order']->id));
        $this->assertCount(5, $app->securehttpclient->calls);
    }
    public function testCollectorRechecksParentAfterNetworkCallBeforeStoringAnyCodes() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array())),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'SHOULD-NOT-STORE')))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $app->securehttpclient->hook = function ($url) use ($app, $res) {
            if (strpos($url, '/fetch-codes/') !== false) {
                // Controlled interleaving: another writer closes the parent
                // while this worker is waiting for the provider's code reply.
                $app->db->where('id', $res['transaction']->id)->update('service_transactions', array('status' => 'REFUNDED', 'refunded_amount' => '42000'));
            }
        };
        $late = $app->giftcardservice->collect($res['order']);
        $this->assertFalse($late['ok']);
        $this->assertSame('TERMINAL', $late['code']);
        $this->assertCount(0, $app->Giftcard_code_model->for_order($res['order']->id));
        $this->assertSame('PLACED', $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
    }
    public function testDispatchKeySurvivesLoggingFailureAfterProviderAcceptsPurchase() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'RECOVERED-CARD')))));
        list($app, $user) = $this->purchaseApp($responses);
        $logger = $app->Provider_transaction_model;
        $app->Provider_transaction_model = new PrestmitExplodingLog();
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $this->assertFalse($res['ok']);
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertStringContainsString('prestmit-pending:', $res['order']->provider_order_id);
        $this->assertTrue($res['uncertain']);
        $this->assertSame('RECONCILIATION_REQUIRED', $res['code']);
        $this->assertStringContainsString('Do not submit a new purchase', $res['error']);
        $this->assertSame('458000.00000000', $app->balance($user));
        $app->Provider_transaction_model = $logger;
        $this->assertTrue($app->giftcardservice->collect($res['order'])['ready']);
        $this->assertSame('SUCCESSFUL', $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
    }
    public function testProviderEnvironmentAndTypeCannotBeChangedToBypassRefundPolicy() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array($this->response(array('data' => array()))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $app->db->where('id', $res['transaction']->provider_id)->update('providers', array('api_type' => 'MOCK_GIFTCARD'));
        $this->assertFalse($app->transactionengine->transition($res['transaction']->id, 'REFUNDED', 'ADMIN')['ok']);
        $this->assertSame('PROVIDER_CHANGED', $app->giftcardservice->collect($res['order'])['code']);
        $this->assertSame('458000.00000000', $app->balance($user));
        $this->assertCount(4, $app->securehttpclient->calls);
    }
    public function testBuyWebhookFetchesRealCodesAndDuplicateDoesNotRepeatProviderCalls() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'HOOK-CARD')))));
        list($app, $user) = $this->purchaseApp($responses);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
        $providerId = $res['transaction']->provider_id;
        $app->config->set_item('prestmit_webhook_secrets', array($providerId => 'webhook-test-secret-123'));
        $app->library('PrestmitWebhookService');
        $raw = json_encode(array('event' => 'giftcard-trade.buy.approved', 'data' => array(
            'partnersApiIdentifier' => $res['transaction']->public_id, 'status' => 'COMPLETED')));
        $sig = base64_encode(hash_hmac('sha256', $raw, 'webhook-test-secret-123', true));
        $this->assertSame(200, $app->prestmitwebhookservice->receive($providerId, $raw, $sig)['status']);
        $this->assertSame('SUCCESSFUL', $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
        $this->assertSame('DELIVERED', $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
        $this->assertSame(200, $app->prestmitwebhookservice->receive($providerId, $raw, $sig)['status']);
        $this->assertSame('458000.00000000', $app->balance($user));
        $this->assertCount(6, $app->securehttpclient->calls);
    }
    private function coupon($app) {
        $app->model('Coupon_model');
        $app->db->insert('coupons', array('public_id' => marvy_public_id(), 'code' => 'PRESTMIT10',
            'discount_type' => 'PERCENT', 'discount_value' => '10.00000000', 'usage_limit_per_user' => 1,
            'times_used' => 0, 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')));
        return $app->Coupon_model->find_by_id($app->db->insert_id());
    }
    public function testUncertainDispatchFailureCannotReturnTheCouponWhileThePurchaseIsCharged() {
        $responses = array_merge($this->buyResponses(), array(
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'COMPLETED')))),
            $this->response(array(array('cardNumber' => 'DISCOUNTED-LATE-CARD')))));
        list($app, $user) = $this->purchaseApp($responses);
        $coupon = $this->coupon($app);
        $logger = $app->Provider_transaction_model;
        $app->Provider_transaction_model = new PrestmitExplodingLog();
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'coupon_code' => $coupon->code));
        $this->assertFalse($res['ok']);
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('462200.00000000', $app->balance($user));
        $rows = $app->Coupon_model->for_reference('GIFTCARD', $res['transaction']->public_id);
        $this->assertCount(1, $rows, 'A still-charged purchase must retain its linked coupon slot.');
        $this->assertSame('4200.00000000', $rows[0]->discount_amount);
        $again = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'coupon_code' => $coupon->code));
        $this->assertFalse($again['ok']);
        $this->assertSame('ALREADY_USED', $again['code']);
        $app->Provider_transaction_model = $logger;
        $this->assertTrue($app->giftcardservice->collect($res['order'])['ready']);
        $this->assertSame(1, (int)$app->Coupon_model->find_by_id($coupon->id)->times_used);
    }
    public function testConfirmedDelayedFailureRefundsAndReturnsTheCouponTogether() {
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())),
            $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'REFUNDED'))))));
        list($app, $user) = $this->purchaseApp($responses);
        $coupon = $this->coupon($app);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'coupon_code' => $coupon->code));
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertCount(1, $app->Coupon_model->for_reference('GIFTCARD', $res['transaction']->public_id));
        $refunded = $app->transactionengine->transition($res['transaction']->id, 'FAILED', 'SYSTEM');
        $this->assertTrue($refunded['ok']);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertCount(0, $app->Coupon_model->for_reference('GIFTCARD', $res['transaction']->public_id));
        $this->assertSame(0, (int)$app->Coupon_model->find_by_id($coupon->id)->times_used);
        $this->assertNotNull($app->Coupon_model->find_valid($coupon->code, $user->id));
    }
    public function testCouponReleaseFailureRollsBackTheRefundAndCanBeReconciledAgain() {
        $confirmed = $this->response(array('data' => array(array('reference' => 'BGC-123', 'status' => 'REFUNDED'))));
        $responses = array_merge($this->buyResponses(array('http_code' => 0, 'body' => null)), array(
            $this->response(array('data' => array())), $confirmed, $confirmed));
        list($app, $user) = $this->purchaseApp($responses);
        $coupon = $this->coupon($app);
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'coupon_code' => $coupon->code));
        $model = $app->Coupon_model;
        $app->Coupon_model = new PrestmitCouponFailure($model, 'release');
        $failed = $app->transactionengine->transition($res['transaction']->id, 'FAILED', 'SYSTEM');
        $this->assertFalse($failed['ok']);
        $this->assertSame('PROCESSING', $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
        $this->assertSame('PLACED', $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
        $this->assertSame('462200.00000000', $app->balance($user));
        $this->assertCount(1, $model->for_reference('GIFTCARD', $res['transaction']->public_id));
        $app->Coupon_model = $model;
        $this->assertTrue($app->transactionengine->transition($res['transaction']->id, 'FAILED', 'SYSTEM')['ok']);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertSame(0, (int)$model->find_by_id($coupon->id)->times_used);
        list($debit, $credit) = $app->ledger_is_balanced();
        $this->assertSame($debit, $credit);
    }
    public function testUnlinkableCouponStopsBeforeChargeOrProviderDispatch() {
        list($app, $user) = $this->purchaseApp(array());
        $coupon = $this->coupon($app);
        $model = $app->Coupon_model;
        $app->Coupon_model = new PrestmitCouponFailure($model, 'attach');
        $res = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25', 'coupon_code' => $coupon->code));
        $this->assertFalse($res['ok']);
        $this->assertSame('COUPON_FAILED', $res['code']);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertSame(0, (int)$model->find_by_id($coupon->id)->times_used);
        $this->assertCount(0, $app->securehttpclient->calls);
    }
    public function testZeroChargePendingGiftcardStillNeedsProviderConfirmationBeforeClosing() {
        list($app, $user) = $this->purchaseApp(array($this->response(array('data' => array()))));
        $coupon = $this->coupon($app);
        $app->db->where('id', $coupon->id)->update('coupons', array('discount_value' => '100.00000000'));
        $product = $app->db->where('code', 'AMAZON-US-25')->get('giftcard_products')->row();
        $res = $app->transactionengine->execute($user, array('service_domain' => 'GIFTCARD', 'service_type' => 'PURCHASE',
            'provider_id' => $product->provider_id, 'amount' => '42000', 'coupon_code' => $coupon->code,
            'detail' => function ($id) use ($app, $product) {
                $app->Giftcard_order_model->create(array('service_transaction_id' => $id, 'product_id' => $product->id,
                    'brand_id' => $product->brand_id, 'quantity' => 1, 'face_value' => $product->face_value,
                    'recipient_currency' => $product->recipient_currency, 'status' => 'PENDING', 'created_at' => gmdate('Y-m-d H:i:s')));
            },
            // A deliberately synthetic domain callback exercises the shared
            // lifecycle boundary, not Prestmit's cost-checked checkout.
            'dispatch' => function ($tx) use ($app) {
                $ref = 'prestmit-pending:'.$tx->public_id;
                $app->Giftcard_order_model->update_for_transaction($tx->id, array('status' => 'PLACED',
                    'provider_order_id' => $ref, 'placed_at' => gmdate('Y-m-d H:i:s')));
                return array('ok' => true, 'status' => 'PROCESSING', 'reference' => $ref);
            }));
        $this->assertTrue($res['ok']);
        $this->assertSame('0.00000000', $res['transaction']->amount);
        $this->assertSame('500000.00000000', $app->balance($user));
        $closed = $app->transactionengine->transition($res['transaction']->id, 'FAILED', 'ADMIN');
        $this->assertFalse($closed['ok']);
        $this->assertSame('RECONCILIATION_REQUIRED', $closed['code']);
        $this->assertCount(1, $app->Coupon_model->for_reference('GIFTCARD', $res['transaction']->public_id));
        $this->assertSame('PROCESSING', $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
    }
    public function testProviderCredentialValidationAndPayload() {
        $service = (new ReflectionClass('ProviderSyncService'))->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod($service, 'validate'); $validate->setAccessible(true);
        $payload = new ReflectionMethod($service, 'credential_payload'); $payload->setAccessible(true);
        $input = array('name' => 'Prestmit', 'api_type' => 'PRESTMIT', 'api_url' => PrestmitAdapter::SANDBOX_URL,
            'api_key' => 'key', 'prestmit_api_secret' => 'secret', 'prestmit_account_pin' => '0123');
        $this->assertSame(array(), $validate->invoke($service, $input));
        $stored = json_decode($payload->invoke($service, $input), true);
        $this->assertSame('0123', $stored['account_pin']);
        unset($input['prestmit_api_secret']);
        $this->assertNotEmpty($validate->invoke($service, $input));
    }
}

class PrestmitTestLoader { public function library($name) {} }
class PrestmitTestEncryption { public function open($value) { return $value; } }
class PrestmitTestHttp {
    public $calls = array(); public $hook; private $responses;
    public function __construct($responses) { $this->responses = $responses; }
    public function request($method, $url, $data, $headers, $options) {
        $this->calls[] = compact('method', 'url', 'data', 'headers', 'options');
        if ($this->hook) ($this->hook)($url);
        if (!$this->responses) throw new RuntimeException('Unexpected HTTP call');
        return array_shift($this->responses);
    }
}

class PrestmitExplodingLog { public function record($data) { throw new RuntimeException("Log storage down"); } }

class PrestmitCouponFailure {
    private $model; private $failure;
    public function __construct($model, $failure) { $this->model = $model; $this->failure = $failure; }
    public function attach_redemption(...$args) { return $this->failure === 'attach' ? false : $this->model->attach_redemption(...$args); }
    public function release_redemption(...$args) { return $this->failure === 'release' ? false : $this->model->release_redemption(...$args); }
    public function __call($method, $args) { return $this->model->$method(...$args); }
}
