<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

class GiftcardSellTest extends TestCase {
    private $app;
    private $user;
    private $provider;
    private $http;
    public static function setUpBeforeClass(): void {
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($level,$message){}');
        require_once dirname(__DIR__, 2).'/application/core/MY_Model.php';
        require_once dirname(__DIR__, 2).'/application/helpers/marvy_helper.php';
        require_once dirname(__DIR__, 2).'/application/libraries/PrestmitAdapter.php';
    }
    protected function setUp(): void {
        unset($GLOBALS['__marvy_base_currency']);
        $this->app = new IntegrationHarness(array('prestmit_sell_enabled' => true,
            'prestmit_sell_margin_percent' => '5'));
        $this->app->seed_minimal();
        $this->user = $this->app->register('seller', 'seller@x.test');
        $this->app->library('EncryptionService');
        $this->app->model('Provider_model');
        $this->provider = $this->app->Provider_model->create(array('public_id' => marvy_public_id(),
            'name' => 'Prestmit sandbox', 'api_type' => 'PRESTMIT', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'api_url' => PrestmitAdapter::SANDBOX_URL, 'created_at' => gmdate('Y-m-d H:i:s'),
            'api_key_encrypted' => $this->app->encryptionservice->encrypt(json_encode(array(
                'api_key' => 'test-key', 'api_secret' => 'test-secret', 'account_pin' => '0123')))));
        $this->app->config->set_item('prestmit_sell_provider_id', $this->provider->id);
        $this->http = new GiftcardSellTestHttp();
        $this->app->securehttpclient = $this->http;
        $this->app->library('GiftcardSellService');
    }
    protected function tearDown(): void { unset($GLOBALS['__marvy_base_currency']); }
    private function response($data, $code = 200) { return array('http_code' => $code, 'body' => json_encode($data)); }
    private function catalogue() {
        return array('sellGiftcardPayoutMethods' => array(array('name' => 'NAIRA', 'available' => true)),
            'sellableGiftcards' => array(
                array('id' => 12, 'name' => 'Apple US eCode', 'form' => 'Ecode', 'country' => 'USA',
                    'rate' => 900, 'minimum' => 10, 'terms' => '<b>US only</b>'),
                array('id' => 13, 'name' => 'Physical Apple', 'form' => 'Physical', 'rate' => 950, 'minimum' => 10)));
    }
    private function quote() {
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '12', 'amount' => '100'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        return $res['sale'];
    }
    private function submit($code = 'PRIVATE-CARD-CODE-123') {
        $q = $this->quote();
        $res = $this->app->giftcardsellservice->submit($this->user, $q->public_id, $code, '1');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        return $q;
    }
    private function dispatch($code = 'PRIVATE-CARD-CODE-123') {
        $q = $this->submit($code);
        $this->http->queue[] = $this->response(array('success' => true, 'trade' => array('reference' => 'SGC-123', 'comments' => 'PRIVATE-CARD-CODE-123')));
        $res = $this->app->giftcardsellservice->dispatch($q->id, $this->user->id);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        return $q;
    }
    private function status($status = 'COMPLETED', $amount = '90000', $overrides = array()) {
        $this->http->queue[] = $this->response(array('data' => array(array_merge(array(
            'reference' => 'SGC-123', 'status' => $status, 'totalAmount' => $amount,
            'giftcard' => array('id' => 12), 'comments' => 'PRIVATE-CARD-CODE-123',
            'payoutMethod' => 'NAIRA'), $overrides))));
    }
    private function row($q) { return $this->app->Giftcard_sale_model->private_row($q->id); }

    public function testQuoteUsesLiveRateFrozenMarginAndSafeProjection() {
        $q = $this->quote();
        $this->assertSame('QUOTED', $q->status);
        $this->assertSame('85500.00000000', $q->estimated_payout);
        $this->assertSame('US only', $q->terms_text);
        $this->assertFalse(property_exists($q, 'code_encrypted'));
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testFeatureIsOffByDefaultAndDisabledQuoteMakesNoHttpCalls() {
        $defaults = new HarnessConfig();
        $this->assertFalse($defaults->item('prestmit_sell_enabled'));
        $this->app->config->set_item('prestmit_sell_enabled', false);
        $this->assertFalse($this->app->giftcardsellservice->quote($this->user, array('amount' => 100))['ok']);
        $this->assertCount(0, $this->http->calls);
    }
    public function testPhysicalCardsAreNotAcceptedThroughEcodeFlow() {
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '13', 'amount' => '100'));
        $this->assertFalse($res['ok']);
        $this->assertSame(0, $this->app->Giftcard_sale_model->count_listing());
    }
    public function testBelowMinimumAndInvalidAmountsAreRejected() {
        $this->http->queue[] = $this->response($this->catalogue());
        $this->assertFalse($this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '12', 'amount' => '5'))['ok']);
        foreach (array('-1', '0', '10001', '1e5', 'NaN', '1.123456789') as $amount) {
            $this->assertFalse($this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '12', 'amount' => $amount))['ok']);
        }
    }
    public function testExpiredQuoteAndMissingAcceptanceCannotStoreCode() {
        $q = $this->quote();
        $this->assertFalse($this->app->giftcardsellservice->submit($this->user, $q->public_id, 'TEST-CODE', '0')['ok']);
        $this->app->db->where('id', $q->id)->update('giftcard_sales', array('quote_expires_at' => '2020-01-01 00:00:00'));
        $this->assertFalse($this->app->giftcardsellservice->submit($this->user, $q->public_id, 'TEST-CODE', '1')['ok']);
        $this->assertNull($this->row($q)->code_encrypted);
    }
    public function testQuoteExpiryIsRecheckedAtTheDigitalSubmissionWrite() {
        $q = $this->quote();
        $this->expireAtSubmissionWrite();
        $res = $this->app->giftcardsellservice->submit($this->user, $q->public_id, 'PRIVATE-LATE-CODE', '1');
        $this->assertFalse($res['ok']);
        $this->assertSame('QUOTED', $this->row($q)->status);
        $this->assertNull($this->row($q)->code_encrypted);
        $this->assertNull($this->row($q)->code_fingerprint);
        $this->assertCount(1, $this->http->calls);
    }
    public function testQuoteExpiringDuringImageIntakeDeletesNewFilesWithoutAcceptingSale() {
        $this->app->config->set_item('prestmit_physical_enabled', true);
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '13', 'amount' => '100'));
        $this->assertTrue($res['ok']); $q = $res['sale'];
        $this->app->library('GiftcardImageStore');
        $store = $this->app->giftcardimagestore;
        $item = $store->store(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUAAAAABJRU5ErkJggg=='));
        try {
            $this->app->giftcardimagestore = new GiftcardSellImageIntake($store, array($item));
            $this->expireAtSubmissionWrite();
            $res = $this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1');
            $this->assertFalse($res['ok']);
            $this->assertSame('QUOTED', $this->row($q)->status);
            $this->assertNull($this->row($q)->code_encrypted);
            $this->assertNull($this->row($q)->code_fingerprint);
            $this->assertSame(0, (int)$this->row($q)->image_count);
            $missing = false;
            try { $store->read($item); } catch (RuntimeException $e) { $missing = true; }
            $this->assertTrue($missing, 'Rejected upload must not leave a newly stored encrypted image.');
            $this->assertCount(1, $this->http->calls);
        } finally { $store->delete(array($item)); }
    }
    private function expireAtSubmissionWrite() {
        $this->app->Giftcard_sale_model = new GiftcardSellExpiryHook($this->app->Giftcard_sale_model, $this->app->db);
    }
    public function testSubmitEncryptsCodeAndNeitherViewsNorAuditExposeIt() {
        $q = $this->submit();
        $row = $this->row($q);
        $this->assertSame('REVIEW', $row->status);
        $this->assertNotSame('PRIVATE-CARD-CODE-123', $row->code_encrypted);
        $this->assertSame('PRIVATE-CARD-CODE-123', $this->app->encryptionservice->open($row->code_encrypted));
        $this->assertStringNotContainsString('PRIVATE-CARD-CODE-123', json_encode($this->app->db->get('audit_logs')->result()));
        $safe = $this->app->Giftcard_sale_model->listing($this->user->id);
        $this->assertFalse(property_exists($safe[0], 'code_encrypted'));
        $this->assertFalse(property_exists($safe[0], 'code_fingerprint'));
    }
    public function testAnotherCustomerCannotUseQuoteAndDuplicateSubmitDoesNotDispatch() {
        $q = $this->submit();
        $other = $this->app->register('other', 'other@x.test');
        $this->assertNull($this->app->Giftcard_sale_model->public_row($q->public_id, $other->id));
        $this->assertFalse($this->app->giftcardsellservice->submit($other, $q->public_id, 'STOLEN-CODE', '1')['ok']);
        $again = $this->app->giftcardsellservice->submit($this->user, $q->public_id, 'REPLACEMENT-CODE', '1');
        $this->assertTrue($again['duplicate']);
        $this->assertCount(1, $this->http->calls);
    }
    public function testCodeFingerprintPreventsResubmittingSameCodeOnAnotherQuote() {
        $this->submit(); $q = $this->quote();
        $res = $this->app->giftcardsellservice->submit($this->user, $q->public_id, 'privatecardcode123', '1');
        $this->assertFalse($res['ok']);
        $this->assertSame('QUOTED', $this->row($q)->status);
    }
    public function testDispatchUsesMultipartHmacAndNeverCreditsOrResends() {
        $q = $this->dispatch();
        $call = $this->http->calls[1];
        $this->assertStringContainsString('/giftcard-trade/sell/create', $call['url']);
        $this->assertStringContainsString('PRIVATE-CARD-CODE-123', $call['data']);
        $this->assertStringContainsString('name="uniqueIdentifier"', $call['data']);
        $this->assertSame(0, $call['options']['max_retries']);
        $expectedBody = array('giftcard_id' => '12', 'amount' => '100.00000000', 'payoutMethod' => 'NAIRA',
            'comments' => 'PRIVATE-CARD-CODE-123', 'uniqueIdentifier' => $q->public_id);
        $this->assertContains('API-Hash: '.hash_hmac('sha256', 'test-key:'.json_encode($expectedBody), 'test-secret'), $call['headers']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
        $this->assertCount(2, $this->http->calls);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testTimedOutSubmissionIsReconciledByLocalIdAndNeverResent() {
        $q = $this->submit();
        $this->http->queue[] = array('http_code' => 0, 'body' => null);
        $this->assertTrue($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['uncertain']);
        $this->assertSame('SUBMITTING', $this->row($q)->status);
        $this->http->queue[] = $this->response(array('data' => array()));
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertStringContainsString('uniqueIdentifier='.$q->public_id, $this->http->calls[2]['url']);
        $this->assertSame('SUBMITTING', $this->row($q)->status);
        $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
    }
    public function testCompletedSaleCreditsNetAmountExactlyOnceAndBalancesLedger() {
        $q = $this->dispatch(); $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('CREDITED', $this->row($q)->status);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
        $this->assertSame('90000.00000000', $this->row($q)->provider_amount);
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['duplicate']);
        $this->assertCount(3, $this->http->calls);
        list($d, $c) = $this->app->ledger_is_balanced(); $this->assertSame($d, $c);
        $credits = $this->app->db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result();
        $this->assertCount(1, $credits);
    }
    public function testLowerActualProceedsReducePayoutAndChangedMarginCannotRepriceSale() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_sell_margin_percent', '50');
        $this->status('COMPLETED', '80000');
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('76000.00000000', $this->app->balance($this->user));
    }
    public function testHighProviderAmountCannotExceedCustomerQuote() {
        $q = $this->dispatch(); $this->status('COMPLETED', '1000000');
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
    }
    public function testInvalidCompletionAmountOrIdentityNeverCredits() {
        $q = $this->dispatch();
        foreach (array(array('COMPLETED', '-5', array()), array('COMPLETED', '90000', array('reference' => 'OTHER')),
            array('COMPLETED', '90000', array('giftcard' => array('id' => 99))),
            array('COMPLETED', '90000', array('payoutMethod' => 'CEDIS'))) as $case) {
            $this->status($case[0], $case[1], $case[2]);
            $this->assertFalse($this->app->giftcardsellservice->reconcile($q->id)['ok']);
            $this->assertSame('0.00000000', $this->app->balance($this->user));
        }
    }
    public function testRejectedAndPendingTradesNeverCredit() {
        $q = $this->dispatch(); $this->status('PENDING');
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->status('REJECTED');
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('REJECTED', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testDisabledNewSalesStillSettleExistingTrades() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_sell_enabled', false);
        $this->app->db->where('id', $this->provider->id)->update('providers', array('status' => 'INACTIVE'));
        $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('CREDITED', $this->row($q)->status);
    }
    public function testEnvironmentSwitchCannotReconcileAgainstWrongAccount() {
        $q = $this->dispatch();
        $this->app->db->where('id', $this->provider->id)->update('providers', array('api_url' => PrestmitAdapter::LIVE_URL));
        $this->assertFalse($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertCount(2, $this->http->calls);
    }
    public function testLocalRejectionOnlyAppliesBeforeDispatch() {
        $q = $this->submit();
        $this->assertTrue($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
        $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
        $this->assertSame('REJECTED', $this->row($q)->status);
    }
    public function testLedgerFailureRollsBackSettlementAndCanBeRetried() {
        $q = $this->dispatch(); $this->status();
        $original = $this->app->ledgerservice;
        $this->app->ledgerservice = new GiftcardSellFailingLedger();
        $this->assertFalse($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->app->ledgerservice = $original; $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
    }
    public function testFailedPollCannotStarveTheNextSaleInSmallCronBatches() {
        $first = $this->dispatch();
        $second = $this->dispatch('OTHER-PRIVATE-CARD-CODE');
        $this->http->queue[] = array('http_code' => 0, 'body' => null);
        $run = $this->app->giftcardsellservice->settle_open(1);
        $this->assertSame(1, $run['processed']);
        $this->assertSame(1, $run['failed']);
        $this->assertNotNull($this->row($first)->last_checked_at);
        $this->assertNull($this->row($second)->last_checked_at);
        $this->status('PENDING');
        $run = $this->app->giftcardsellservice->settle_open(1);
        $this->assertSame(1, $run['processed']);
        $this->assertSame(0, $run['failed']);
        $this->assertNotNull($this->row($second)->last_checked_at);
        $this->assertSame('PENDING', $this->row($first)->status);
        $this->assertSame('PENDING', $this->row($second)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testConfigurationFailureAlsoRotatesThePollingQueue() {
        $first = $this->dispatch(); $second = $this->dispatch('SECOND-CONFIG-CODE');
        $this->app->db->where('id', $this->provider->id)->update('providers', array('api_url' => PrestmitAdapter::LIVE_URL));
        $this->assertSame(1, $this->app->giftcardsellservice->settle_open(1)['failed']);
        $this->assertNotNull($this->row($first)->last_checked_at);
        $this->assertNull($this->row($second)->last_checked_at);
        $this->assertSame(1, $this->app->giftcardsellservice->settle_open(1)['failed']);
        $this->assertNotNull($this->row($second)->last_checked_at);
        $this->assertCount(4, $this->http->calls); // Quotes + creates only, no status request.
    }
    public function testFailedTransactionStartCannotSettleASaleOutsideTheTransaction() {
        $q = $this->dispatch(); $this->status();
        $db = $this->app->db;
        $this->app->db = new GiftcardSellTransactionFailure($db, 'trans_begin');
        $res = $this->app->giftcardsellservice->reconcile($q->id);
        $this->assertFalse($res['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
        $this->app->db = $db; $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
    }
    public function testFailedSettlementCommitCannotReportSuccessAndCanBeRetried() {
        $q = $this->dispatch(); $this->status();
        $db = $this->app->db;
        $this->app->db = new GiftcardSellTransactionFailure($db, 'trans_commit');
        $res = $this->app->giftcardsellservice->reconcile($q->id);
        $this->assertFalse($res['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
        $this->app->db = $db; $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
        $this->assertCount(1, $db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
    }
    public function testCronOnlyReconcilesSubmittedSales() {
        $q = $this->dispatch(); $this->status();
        $this->app->library('CronWorkers');
        $result = $this->app->cronworkers->giftcard_sales();
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('CREDITED', $this->row($q)->status);
    }
    public function testFalseAuditResultRollsBackSettlementAndCanBeRetried() {
        $q = $this->dispatch(); $this->status();
        $audit = $this->app->Audit_log_model;
        $this->app->Audit_log_model = new GiftcardSellFalseAudit();
        $this->assertFalse($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $this->app->db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
        $this->app->Audit_log_model = $audit; $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
        $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.credited')->get('audit_logs')->result());
    }
    public function testFailedAuditCannotAcceptADigitalSubmission() {
        $q = $this->quote(); $audit = $this->app->Audit_log_model;
        foreach (array(new GiftcardSellFalseAudit(), new GiftcardSellExplodingAudit()) as $failed) {
            $this->app->Audit_log_model = $failed;
            $this->assertFalse($this->app->giftcardsellservice->submit($this->user, $q->public_id, 'AUDIT-PROTECTED-CODE', '1')['ok']);
            $this->assertSame('QUOTED', $this->row($q)->status);
            $this->assertNull($this->row($q)->code_encrypted);
            $this->assertNull($this->row($q)->code_fingerprint);
        }
        $this->app->Audit_log_model = $audit;
        $this->assertTrue($this->app->giftcardsellservice->submit($this->user, $q->public_id, 'AUDIT-PROTECTED-CODE', '1')['ok']);
        $this->assertSame('REVIEW', $this->row($q)->status);
        $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.submitted')->get('audit_logs')->result());
        $this->assertCount(1, $this->http->calls);
    }
    public function testFailedAuditCannotDispatchCodeOrConsumeTheReviewState() {
        $q = $this->submit(); $audit = $this->app->Audit_log_model;
        foreach (array(new GiftcardSellFalseAudit(), new GiftcardSellExplodingAudit()) as $failed) {
            $this->app->Audit_log_model = $failed;
            $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
            $this->assertSame('REVIEW', $this->row($q)->status);
            $this->assertCount(1, $this->http->calls);
        }
        $this->app->Audit_log_model = $audit;
        $this->http->queue[] = $this->response(array('success' => true, 'trade' => array('reference' => 'SGC-AUDITED')));
        $this->assertTrue($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertCount(2, $this->http->calls);
        $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.dispatched')->get('audit_logs')->result());
    }
    public function testFailedAuditCannotDeclineAnUnsubmittedSale() {
        $q = $this->submit(); $audit = $this->app->Audit_log_model;
        foreach (array(new GiftcardSellFalseAudit(), new GiftcardSellExplodingAudit()) as $failed) {
            $this->app->Audit_log_model = $failed;
            $this->assertFalse($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
            $this->assertSame('REVIEW', $this->row($q)->status);
        }
        $this->app->Audit_log_model = $audit;
        $this->assertTrue($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
        $this->assertSame('REJECTED', $this->row($q)->status);
        $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.declined')->get('audit_logs')->result());
    }
    public function testFalseAuditResultKeepsTheTerminalSecretManifestForRetry() {
        $q = $this->submit();
        $this->assertTrue($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
        $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)));
        $audit = $this->app->Audit_log_model;
        $this->app->Audit_log_model = new GiftcardSellFalseAudit();
        $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
        $this->assertNotNull($this->row($q)->code_encrypted);
        $this->app->Audit_log_model = $audit;
        $this->assertSame(1, $this->app->giftcardsellservice->purge_terminal());
        $this->assertNull($this->row($q)->code_encrypted);
        $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.secrets_purged')->get('audit_logs')->result());
    }
    public function testFalseAuditResultCannotDisclosePhysicalImageBytes() {
        list($q, $store, $item) = $this->physicalAuditFixture();
        try {
            $this->assertTrue($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $audit = $this->app->Audit_log_model;
            foreach (array(new GiftcardSellFalseAudit(), new GiftcardSellExplodingAudit()) as $failed) {
                $this->app->Audit_log_model = $failed;
                $this->assertNull($this->app->giftcardsellservice->image($q->id, 0, $this->user->id));
            }
            $this->app->Audit_log_model = $audit;
            $this->assertNotNull($this->app->giftcardsellservice->image($q->id, 0, $this->user->id));
            $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.image_viewed')->get('audit_logs')->result());
        } finally { $store->delete(array($item)); }
    }
    public function testFailedAuditRollsBackPhysicalSubmissionAndCleansTheNewImage() {
        list($q, $store, $item) = $this->physicalAuditFixture();
        try {
            $this->app->Audit_log_model = new GiftcardSellFalseAudit();
            $this->assertFalse($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertSame('QUOTED', $this->row($q)->status);
            $this->assertNull($this->row($q)->code_encrypted);
            $this->assertNull($this->row($q)->code_fingerprint);
            $this->assertSame(0, (int)$this->row($q)->image_count);
            $missing = false;
            try { $store->read($item); } catch (RuntimeException $e) { $missing = true; }
            $this->assertTrue($missing);
            $this->assertCount(1, $this->http->calls);
        } finally { $store->delete(array($item)); }
    }
    public function testPhysicalPurgeDoesNotDeleteFilesBeforeItsAuditCanBeSaved() {
        list($q, $store, $item) = $this->physicalAuditFixture();
        try {
            $this->assertTrue($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertTrue($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
            $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)));
            $audit = $this->app->Audit_log_model;
            foreach (array(new GiftcardSellFalseAudit(), new GiftcardSellExplodingAudit()) as $failed) {
                $this->app->Audit_log_model = $failed;
                $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
                $this->assertNotNull($this->row($q)->code_encrypted);
                $this->assertNotNull($store->read($item));
            }
            $this->app->Audit_log_model = $audit;
            $this->assertSame(1, $this->app->giftcardsellservice->purge_terminal());
            $this->assertNull($this->row($q)->code_encrypted);
            $missing = false;
            try { $store->read($item); } catch (RuntimeException $e) { $missing = true; }
            $this->assertTrue($missing);
            $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.secrets_purged')->get('audit_logs')->result());
        } finally { $store->delete(array($item)); }
    }
    public function testLostDispatchCommitAcknowledgementCannotSendOrBlindlyRetry() {
        $q = $this->submit(); $db = $this->app->db;
        $this->app->db = new GiftcardSellLostCommitAck($db);
        $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
        $this->assertSame('SUBMITTING', $this->row($q)->status);
        $this->assertCount(1, $this->http->calls); // Only catalogue: no create after an unacknowledged commit.
        $this->assertCount(1, $db->where('action', 'giftcard.sale.dispatched')->get('audit_logs')->result());
        $this->app->db = $db;
        $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
        $this->assertCount(1, $this->http->calls);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testLostPhysicalCommitAcknowledgementKeepsTheCommittedImages() {
        list($q, $store, $item) = $this->physicalAuditFixture();
        $db = $this->app->db;
        try {
            $this->app->db = new GiftcardSellLostCommitAck($db);
            $this->assertFalse($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertSame('REVIEW', $this->row($q)->status);
            $this->assertNotNull($this->row($q)->code_encrypted);
            $this->assertNotNull($store->read($item));
            $this->assertCount(1, $db->where('action', 'giftcard.sale.images_submitted')->get('audit_logs')->result());
            $this->app->db = $db;
            $this->assertTrue($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['duplicate']);
            $this->assertNotNull($this->app->giftcardsellservice->image($q->id, 0, $this->user->id));
            $this->assertCount(1, $this->http->calls);
        } finally { $this->app->db = $db; $store->delete(array($item)); }
    }
    public function testRefusedPhysicalCommitStillKeepsFilesForUnknownOutcomeReview() {
        list($q, $store, $item) = $this->physicalAuditFixture();
        $db = $this->app->db;
        try {
            $this->app->db = new GiftcardSellTransactionFailure($db, 'trans_commit');
            $this->assertFalse($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertSame('QUOTED', $this->row($q)->status);
            $this->assertNull($this->row($q)->code_encrypted);
            // A false return cannot distinguish refusal from an ACK lost after
            // commit. Do not delete until an operator reconnects and verifies.
            $this->assertNotNull($store->read($item));
            $this->assertCount(0, $db->where('action', 'giftcard.sale.images_submitted')->get('audit_logs')->result());
        } finally { $this->app->db = $db; $store->delete(array($item)); }
    }
    private function physicalAuditFixture() {
        $this->app->config->set_item('prestmit_physical_enabled', true);
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '13', 'amount' => '100'));
        $this->assertTrue($res['ok']);
        $this->app->library('GiftcardImageStore'); $store = $this->app->giftcardimagestore;
        $item = $store->store(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUAAAAABJRU5ErkJggg=='));
        $this->app->giftcardimagestore = new GiftcardSellImageIntake($store, array($item));
        return array($res['sale'], $store, $item);
    }
    public function testAuditFailureAfterLedgerWriteRollsBackCreditAndSaleTogether() {
        $q = $this->dispatch(); $this->status();
        $original = $this->app->Audit_log_model;
        $this->app->Audit_log_model = new GiftcardSellExplodingAudit();
        $this->assertFalse($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $this->app->db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
        $this->app->Audit_log_model = $original; $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
    }
    public function testProductionRejectsSandboxButAllowsApprovedLiveUrl() {
        $this->assertFalse(PrestmitAdapter::environment_allows_url(PrestmitAdapter::SANDBOX_URL, 'production'));
        $this->assertTrue(PrestmitAdapter::environment_allows_url(PrestmitAdapter::LIVE_URL, 'production'));
        $this->assertTrue(PrestmitAdapter::environment_allows_url(PrestmitAdapter::SANDBOX_URL, 'testing'));
    }
    public function testSaleHistoryPreventsProviderDeletionAndOpenSalesPreventEnvironmentSwitch() {
        $q = $this->submit();
        require_once dirname(__DIR__, 2).'/application/libraries/ProviderSyncService.php';
        $service = new ProviderSyncService();
        $result = $service->delete_provider($this->provider);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->app->Provider_model->find_by_id($this->provider->id));
        $result = $service->update_credentials($this->provider, array('api_url' => PrestmitAdapter::LIVE_URL,
            'api_key' => 'other-key', 'prestmit_api_secret' => 'other-secret', 'prestmit_account_pin' => '0123'));
        $this->assertFalse($result['ok']);
        $this->assertSame(PrestmitAdapter::SANDBOX_URL, $this->app->Provider_model->find_by_id($this->provider->id)->api_url);
    }
    public function testQuoteFloodIsBoundedAndControlCharactersAreRejected() {
        for ($i = 0; $i < 20; $i++) $q = $this->quote();
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '12', 'amount' => '100'));
        $this->assertFalse($res['ok']);
        $this->assertCount(20, $this->http->calls);
        $this->assertFalse($this->app->giftcardsellservice->submit($this->user, $q->public_id, "CODE\r\nINVALID", '1')['ok']);
    }
    public function testSignedWebhookReconcilesAuthoritativeStatusAndIsMoneyIdempotent() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_webhook_secrets', array($this->provider->id => 'webhook-test-secret-123'));
        $this->app->library('PrestmitWebhookService');
        $raw = json_encode(array('event' => 'giftcard-trade.sell.approved', 'data' => array(
            'partnersApiIdentifier' => $q->public_id, 'amount' => '999999999', 'status' => 'COMPLETED')));
        $signature = base64_encode(hash_hmac('sha256', $raw, 'webhook-test-secret-123', true));
        $this->status('PENDING');
        $res = $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, $signature);
        $this->assertSame(200, $res['status']);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
        $this->status();
        $this->assertSame(200, $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, $signature)['status']);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
        $this->assertSame(200, $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, $signature)['status']);
        $this->assertCount(4, $this->http->calls);
        $this->assertCount(1, $this->app->db->where('type', 'GIFTCARD_SALE')->get('wallet_transactions')->result());
    }
    public function testWebhookAuthenticationUsesRawBodyAndProviderSpecificSecret() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_webhook_secrets', array($this->provider->id => 'webhook-test-secret-123'));
        $this->app->library('PrestmitWebhookService');
        $raw = '{"event":"giftcard-trade.sell.approved", "data":{"partnersApiIdentifier":"'.$q->public_id.'"}}';
        $sig = base64_encode(hash_hmac('sha256', $raw, 'webhook-test-secret-123', true));
        foreach (array('', hash_hmac('sha256', $raw, 'webhook-test-secret-123'), str_repeat('x', 44)) as $bad) {
            $this->assertSame(401, $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, $bad)['status']);
        }
        $this->assertSame(401, $this->app->prestmitwebhookservice->receive($this->provider->id, str_replace(', ', ',', $raw), $sig)['status']);
        $this->assertSame(401, $this->app->prestmitwebhookservice->receive(9999, $raw, $sig)['status']);
        $this->assertCount(2, $this->http->calls);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testWebhookOutageRequestsRetryWithoutCredit() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_webhook_secrets', array($this->provider->id => 'webhook-test-secret-123'));
        $this->app->library('PrestmitWebhookService');
        $raw = json_encode(array('event' => 'giftcard-trade.sell.approved', 'data' => array('partnersApiIdentifier' => $q->public_id)));
        $this->http->queue[] = array('http_code' => 0, 'body' => null);
        $res = $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, base64_encode(hash_hmac('sha256', $raw, 'webhook-test-secret-123', true)));
        $this->assertSame(503, $res['status']);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testUnknownAndWithdrawalWebhooksCannotMutateCustomerMoney() {
        $q = $this->dispatch();
        $this->app->config->set_item('prestmit_webhook_secrets', array($this->provider->id => 'webhook-test-secret-123'));
        $this->app->library('PrestmitWebhookService');
        foreach (array('withdrawal-request.approved', 'something.new', 'giftcard-trade.sell.approved') as $event) {
            $raw = json_encode(array('event' => $event, 'data' => array('partnersApiIdentifier' => 'UNKNOWN')));
            $this->assertSame(200, $this->app->prestmitwebhookservice->receive($this->provider->id, $raw, base64_encode(hash_hmac('sha256', $raw, 'webhook-test-secret-123', true)))['status']);
        }
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertCount(2, $this->http->calls);
    }
    public function testPhysicalQuoteRequiresImagesAndDispatchIncludesBinaryOutsideSignedFields() {
        $this->app->config->set_item('prestmit_physical_enabled', true);
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '13', 'amount' => '100'));
        $this->assertTrue($res['ok']); $q = $res['sale'];
        $this->assertSame('Physical', $q->card_form);
        $this->assertFalse($this->app->giftcardsellservice->submit($this->user, $q->public_id, 'NOT-AN-IMAGE', '1')['ok']);
        $this->app->library('GiftcardImageStore');
        $store = $this->app->giftcardimagestore;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUAAAAABJRU5ErkJggg==');
        $item = $store->store($png);
        try {
            // Simulate PHP's accepted upload boundary, keeping real encrypted
            // storage, service CAS, dispatch, adapter and multipart transport.
            $this->app->giftcardimagestore = new GiftcardSellImageIntake($store, array($item));
            $this->assertTrue($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertSame('REVIEW', $this->row($q)->status);
            $this->assertSame(1, (int)$this->row($q)->image_count);
            $this->http->queue[] = $this->response(array('success' => true, 'trade' => array('reference' => 'SGC-PHYSICAL')));
            $this->assertTrue($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
            $call = $this->http->calls[1];
            $this->assertStringContainsString('name="attachments[]"; filename="card-1.png"', $call['data']);
            $this->assertStringContainsString($png, $call['data']);
            $fields = array('giftcard_id' => '13', 'amount' => '100.00000000', 'payoutMethod' => 'NAIRA',
                'comments' => '', 'uniqueIdentifier' => $q->public_id);
            $this->assertContains('API-Hash: '.hash_hmac('sha256', 'test-key:'.json_encode($fields), 'test-secret'), $call['headers']);
            $this->assertSame(0, $call['options']['max_retries']);
            $this->assertFalse($this->app->giftcardsellservice->dispatch($q->id, $this->user->id)['ok']);
            $this->assertSame('0.00000000', $this->app->balance($this->user));
            $this->assertSame($png, $this->app->giftcardsellservice->image($q->id, 0, $this->user->id)['bytes']);
            $this->assertNull($this->app->giftcardsellservice->image($q->id, 1, $this->user->id));
        } finally { $store->delete(array($item)); }
    }
    public function testPhysicalUploadBoundaryRejectsLocalPathsInsteadOfReadingThem() {
        $this->app->library('GiftcardImageStore');
        $caught = false;
        try { $this->app->giftcardimagestore->ingest(array('tmp_name' => array(__FILE__), 'error' => array(UPLOAD_ERR_OK))); }
        catch (RuntimeException $e) { $caught = true; }
        $this->assertTrue($caught);
        foreach (array('<?php echo "evil";', '<svg></svg>', str_repeat('x', 5242881)) as $invalid) {
            $caught = false;
            try { $this->app->giftcardimagestore->store($invalid); } catch (RuntimeException $e) { $caught = true; }
            $this->assertTrue($caught);
        }
    }
    public function testEncryptedImageStoreRejectsTamperingAndTraversalAndDeletes() {
        $this->app->library('GiftcardImageStore');
        $store = $this->app->giftcardimagestore;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUAAAAABJRU5ErkJggg==');
        $item = $store->store($png);
        try {
            $this->assertSame($png, $store->read($item)['bytes']);
            $bad = $item; $bad['sha256'] = str_repeat('0', 64);
            $caught = false; try { $store->read($bad); } catch (RuntimeException $e) { $caught = true; } $this->assertTrue($caught);
            $bad['key'] = '../logs/error';
            $caught = false; try { $store->read($bad); } catch (RuntimeException $e) { $caught = true; } $this->assertTrue($caught);
        } finally { $store->delete(array($item)); }
        $caught = false; try { $store->read($item); } catch (RuntimeException $e) { $caught = true; } $this->assertTrue($caught);
    }
    public function testOldTerminalSecretsArePurgedWithoutChangingFinancialHistory() {
        $q = $this->dispatch(); $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)));
        $this->assertSame(1, $this->app->giftcardsellservice->purge_terminal());
        $this->assertNull($this->row($q)->code_encrypted);
        $this->assertNotNull($this->row($q)->code_fingerprint);
        $this->assertSame('CREDITED', $this->row($q)->status);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
        $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
    }
    public function testPartialPhysicalPurgeKeepsManifestForAnIdempotentCleanupRetry() {
        $this->app->config->set_item('prestmit_physical_enabled', true);
        $this->http->queue[] = $this->response($this->catalogue());
        $res = $this->app->giftcardsellservice->quote($this->user, array('giftcard_id' => '13', 'amount' => '100'));
        $this->assertTrue($res['ok']); $q = $res['sale'];
        $this->app->library('GiftcardImageStore'); $store = $this->app->giftcardimagestore;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUAAAAABJRU5ErkJggg==');
        $items = array($store->store($png), $store->store($png));
        try {
            $this->app->giftcardimagestore = new GiftcardSellImageIntake($store, $items);
            $this->assertTrue($this->app->giftcardsellservice->submit_images($this->user, $q->public_id, array(), '1')['ok']);
            $this->assertTrue($this->app->giftcardsellservice->reject($q->id, $this->user->id)['ok']);
            $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)));
            $this->app->giftcardimagestore = new GiftcardSellPartialDelete($store);
            $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
            $this->assertNotNull($this->row($q)->code_encrypted, 'Keep the cleanup manifest after a partial filesystem failure.');
            $this->assertSame($png, $store->read($items[1])['bytes']);
            $this->assertCount(0, $this->app->db->where('action', 'giftcard.sale.secrets_purged')->get('audit_logs')->result());
            $this->app->giftcardimagestore = $store;
            $this->assertSame(1, $this->app->giftcardsellservice->purge_terminal());
            $this->assertNull($this->row($q)->code_encrypted);
            foreach ($items as $item) {
                $missing = false;
                try { $store->read($item); } catch (RuntimeException $e) { $missing = true; }
                $this->assertTrue($missing);
            }
            $this->assertCount(1, $this->app->db->where('action', 'giftcard.sale.secrets_purged')->get('audit_logs')->result());
            $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
            $this->assertSame('REJECTED', $this->row($q)->status);
            $this->assertNotNull($this->row($q)->code_fingerprint);
            $this->assertSame('0.00000000', $this->app->balance($this->user));
        } finally { $store->delete($items); }
    }
    public function testRetentionRechecksTheLockedAgeWhenAnotherWorkerRefreshesTheSale() {
        $q = $this->dispatch(); $this->status();
        $this->assertTrue($this->app->giftcardsellservice->reconcile($q->id)['ok']);
        $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)));
        $model = $this->app->Giftcard_sale_model;
        $app = $this->app;
        $this->app->Giftcard_sale_model = new GiftcardSellRetentionHook($model, function ($id) use ($app) {
            // The purge selected an old candidate, but another worker touched
            // it before this worker acquired the authoritative row lock.
            $app->db->where('id', $id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s')));
        });
        $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
        $this->assertNotNull($model->private_row($q->id)->code_encrypted);
        $this->assertSame('85500.00000000', $this->app->balance($this->user));
    }
    public function testRetentionNeverPurgesOldUnresolvedSubmissions() {
        $q = $this->dispatch();
        $this->app->db->where('id', $q->id)->update('giftcard_sales', array('updated_at' => gmdate('Y-m-d H:i:s', time() - 100 * 86400)));
        $this->assertSame(0, $this->app->giftcardsellservice->purge_terminal());
        $this->assertNotNull($this->row($q)->code_encrypted);
        $this->assertSame('PENDING', $this->row($q)->status);
        $this->assertSame('0.00000000', $this->app->balance($this->user));
    }
    public function testCpanelIncrementalUpgradeContainsTheMigrationAndDoesNotSkipOlderVersions() {
        $root = dirname(__DIR__, 2);
        $sql = file_get_contents($root.'/database/upgrade-046-prestmit.sql');
        $this->assertStringContainsString(str_replace("'", "''", Migration_Giftcard_sales::statements()[0]), $sql);
        $this->assertStringContainsString('MIN(version) IN (44,45,46)', $sql);
        $this->assertStringContainsString("ADD COLUMN card_form VARCHAR(16) NOT NULL DEFAULT ''Ecode''", $sql);
        $this->assertStringContainsString('ADD COLUMN image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('WHERE @prestmit_allowed AND version IN (44,45)', $sql);
    }
    public function testControllersUsePostGuardsOwnershipPermissionsAndNoDecryption() {
        $root = dirname(__DIR__, 2);
        $admin = file_get_contents($root.'/application/controllers/admin/Giftcard_sales.php');
        $customer = file_get_contents($root.'/application/controllers/dashboard/Giftcard_sales.php');
        $this->assertStringContainsString("'giftcards.refund'", $admin);
        $this->assertStringContainsString("'giftcards.manage'", $admin);
        $this->assertStringContainsString("!== 'POST'", $admin);
        $this->assertStringContainsString('$this->current_user->id', $customer);
        $this->assertStringNotContainsString('encryptionservice', $admin.$customer);
        $this->assertStringContainsString("require_perm('giftcards.manage')", $admin);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $admin);
        $this->assertStringContainsString('$_FILES', $customer);
        $routes = file_get_contents($root.'/application/config/routes.php');
        $this->assertTrue(strpos($routes, "['dashboard/giftcards/sell']") < strpos($routes, "['dashboard/giftcards/(:any)']"));
        $this->assertTrue(strpos($routes, "['admin/giftcards/sales']") < strpos($routes, "['admin/giftcards/(:any)']"));
    }
}
class GiftcardSellTestHttp {
    public $queue = array(); public $calls = array();
    public function request($method, $url, $data, $headers, $options) {
        $this->calls[] = compact('method', 'url', 'data', 'headers', 'options');
        if (!$this->queue) throw new RuntimeException('Unexpected HTTP request');
        return array_shift($this->queue);
    }
}
class GiftcardSellFailingLedger { public function credit(...$args) { return array('ok' => false); } }

class GiftcardSellExplodingAudit { public function record(...$args) { throw new RuntimeException('Audit unavailable'); } }

class GiftcardSellImageIntake {
    private $store; private $manifest;
    public function __construct($store, $manifest) { $this->store = $store; $this->manifest = $manifest; }
    public function ingest($uploads) { return $this->manifest; }
    public function read($item) { return $this->store->read($item); }
    public function delete($manifest) { return $this->store->delete($manifest); }
}

class GiftcardSellRetentionHook {
    private $model; private $hook;
    public function __construct($model, $hook) { $this->model = $model; $this->hook = $hook; }
    public function lock_for_update($id) { ($this->hook)($id); return $this->model->lock_for_update($id); }
    public function __call($method, $args) { return $this->model->$method(...$args); }
}

/** Controlled interleaving after public prechecks, before the acceptance CAS. */
class GiftcardSellExpiryHook {
    private $model; private $db;
    public function __construct($model, $db) { $this->model = $model; $this->db = $db; }
    public function __call($method, $args) {
        if ($method === 'change' || $method === 'accept_quote') {
            $this->db->where('id', $args[0])->update('giftcard_sales', array('quote_expires_at' => '2020-01-01 00:00:00'));
        }
        return $this->model->$method(...$args);
    }
}

/** Driver fails the outer service boundary; model/ledger keep the real FakeDb. */
class GiftcardSellTransactionFailure {
    private $db; private $operation;
    public function __construct($db, $operation) { $this->db = $db; $this->operation = $operation; }
    public function __call($method, $args) {
        if ($method === $this->operation) return false;
        return $this->db->$method(...$args);
    }
}

class GiftcardSellPartialDelete {
    private $store;
    public function __construct($store) { $this->store = $store; }
    public function delete($manifest) {
        $this->store->delete(array($manifest[0]));
        throw new RuntimeException('Simulated disk failure after one deletion.');
    }
}

class GiftcardSellFalseAudit { public function record(...$args) { return false; } }

/** Simulate COMMIT persisted but its acknowledgement was lost. */
class GiftcardSellLostCommitAck {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function trans_commit() { $this->db->trans_commit(); return false; }
    public function __call($method, $args) { return $this->db->$method(...$args); }
}
