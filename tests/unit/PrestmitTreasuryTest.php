<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

class PrestmitTreasuryTest extends TestCase {
    private $app;
    private $user;
    private $provider;
    private $http;
    public static function setUpBeforeClass(): void {
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($level,$message){}');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        require_once dirname(__DIR__, 2).'/application/core/MY_Model.php';
        require_once dirname(__DIR__, 2).'/application/helpers/marvy_helper.php';
        require_once dirname(__DIR__, 2).'/application/libraries/PrestmitAdapter.php';
    }
    protected function setUp(): void {
        $this->app = new IntegrationHarness();
        $this->app->seed_minimal();
        $this->user = $this->app->register('treasury_admin', 'treasury@example.test');
        $this->app->credit($this->user, '5000');
        $this->app->library('EncryptionService');
        $this->app->model('Provider_model');
        $this->provider = $this->app->Provider_model->create(array('public_id' => marvy_public_id(),
            'name' => 'Prestmit test', 'api_type' => 'PRESTMIT', 'currency' => 'NGN', 'status' => 'INACTIVE',
            'api_url' => PrestmitAdapter::SANDBOX_URL,
            'api_key_encrypted' => $this->app->encryptionservice->encrypt(json_encode(array(
                'api_key' => 'treasury-key', 'api_secret' => 'treasury-secret', 'account_pin' => '0123')))));
        $this->http = new PrestmitTreasuryHttp();
        $this->app->securehttpclient = $this->http;
        $this->app->library('PrestmitTreasuryService');
    }
    private function response($data, $code = 200) { return array('http_code' => $code, 'body' => json_encode($data)); }
    private function bank() {
        return array('id' => 8, 'bank' => 'Test Bank', 'accountNumber' => '0123456789',
            'accountName' => 'PRIVATE HOLDER NAME', 'partnersApiIdentifier' => 'PRIVATE-CUSTOMER-KEY',
            'status' => 'ACTIVE', 'unexpectedSecret' => 'PRIVATE-SECRET');
    }
    private function history($status = 'COMPLETED') {
        return array('id' => 88, 'reference' => 'WTH-EXAMPLE', 'amount' => '2500.00',
            'status' => $status, 'createdAt' => '2026-11-27T10:00:00Z', 'bankAccount' => $this->bank(),
            'rejectReason' => 'PRIVATE REJECTION TEXT', 'receiptUrl' => 'https://evil.test/private.pdf');
    }
    private function queueInspection() {
        $this->http->queue[] = $this->response(array('nairaBalance' => '9876543210123456.12345678'));
        $this->http->queue[] = $this->response(array('data' => array($this->bank()), 'meta' => array('last_page' => 1)));
        $this->http->queue[] = $this->response(array('data' => array($this->history()), 'meta' => array('last_page' => 1)));
    }
    private function adapter() { return new PrestmitAdapter($this->provider, $this->http); }
    private function inspect($bank_page = 1, $history_page = 1) {
        return $this->app->prestmittreasuryservice->inspect($this->provider->public_id, $this->user->id, $bank_page, $history_page);
    }
    private function financialSnapshot() {
        $out = array();
        foreach (array('wallets', 'wallet_transactions', 'ledger_entries', 'earnings', 'payout_requests', 'service_transactions') as $table) {
            $out[$table] = $this->app->db->get($table)->result();
        }
        return json_encode($out);
    }

    public function testInspectionUsesOnlySignedGetRequestsAndCannotMoveAnyLocalMoney() {
        $before = $this->financialSnapshot(); $this->queueInspection();
        $res = $this->inspect();
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertFalse($res['transfers_enabled']);
        $this->assertSame('9876543210123456.12345678', $res['wallet']['reported_balance']);
        $this->assertNull($res['wallet']['reported_pending']);
        $this->assertSame('•••• 6789', $res['banks']['items'][0]['account_masked']);
        $this->assertSame('Sandbox', $res['provider']['environment']);
        $this->assertSame('INACTIVE', $res['provider']['status']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertCount(3, $this->http->calls);
        $signed = array(array('wallet' => 'NAIRA'), array('page' => 1), array('page' => 1, 'wallet' => 'NAIRA'));
        foreach ($this->http->calls as $i => $call) {
            $this->assertSame('GET', $call['method']);
            $this->assertNull($call['data']);
            $this->assertContains('API-Hash: '.hash_hmac('sha256', 'treasury-key:'.json_encode($signed[$i]), 'treasury-secret'), $call['headers']);
            $this->assertFalse($call['options']['follow_redirects']);
            $this->assertSame(0, $call['options']['max_retries']);
        }
        $this->assertStringContainsString('/wallet/fiat/details?wallet=NAIRA', $this->http->calls[0]['url']);
        $this->assertStringContainsString('/bank-accounts/naira/list?page=1', $this->http->calls[1]['url']);
    }
    public function testProjectionAndAuditContainNoCredentialsFullAccountsNamesOrProviderBodies() {
        $this->queueInspection(); $res = $this->inspect();
        $public = json_encode($res);
        $audit = json_encode($this->app->db->where('action', 'prestmit.treasury.viewed')->get('audit_logs')->result());
        foreach (array('0123456789', 'PRIVATE HOLDER NAME', 'PRIVATE-CUSTOMER-KEY', 'PRIVATE-SECRET',
            'PRIVATE REJECTION TEXT', 'evil.test', 'treasury-key', 'treasury-secret', 'api_key_encrypted') as $secret) {
            $this->assertStringNotContainsString($secret, $public.$audit);
        }
        $this->assertStringNotContainsString('2500.00', $audit);
        $this->assertCount(1, $this->app->db->where('action', 'prestmit.treasury.viewed')->get('audit_logs')->result());
    }
    public function testGuideWalletEnvelopeAndAbsentTotalsAreNotPretendZeroes() {
        $this->http->queue[] = $this->response(array('success' => true, 'data' => array('wallet' => array(
            'currency' => 'NAIRA', 'balance' => '125000', 'pendingBalance' => 0, 'totalBalance' => '125000'))));
        $wallet = $this->adapter()->fiat_wallet();
        $this->assertTrue($wallet['ok']);
        $this->assertSame('125000', $wallet['reported_balance']);
        $this->assertSame('0', $wallet['reported_pending']);
        $this->assertSame('125000', $wallet['reported_total']);
    }
    public function testReferenceCedisWalletAndBankEndpointAreCurrencySpecific() {
        $this->provider->currency = 'GHS';
        $this->http->queue[] = $this->response(array('cedisBalance' => '76647.73', 'earningHistory' => array('secret' => 'not projected')));
        $this->http->queue[] = $this->response(array('data' => array($this->bank())));
        $wallet = $this->adapter()->fiat_wallet(); $banks = $this->adapter()->bank_accounts(3);
        $this->assertTrue($wallet['ok']); $this->assertTrue($banks['ok']);
        $this->assertSame('76647.73', $wallet['reported_balance']);
        $this->assertStringContainsString('wallet=CEDIS', $this->http->calls[0]['url']);
        $this->assertStringContainsString('/bank-accounts/cedis/list?page=3', $this->http->calls[1]['url']);
        $this->assertArrayNotHasKey('earningHistory', $wallet);
    }
    public function testGuideHistoryEnvelopeAndPaginationAreNormalizedWithoutConversion() {
        $this->http->queue[] = $this->response(array('success' => true, 'data' => array(
            'withdrawals' => array($this->history()), 'pagination' => array('currentPage' => 2, 'totalPages' => 4))));
        $res = $this->adapter()->payout_history(2);
        $this->assertTrue($res['ok']); $this->assertSame(3, $res['next_page']);
        $this->assertSame('2500.00', $res['items'][0]['reported_amount']);
        $this->assertSame('•••• 6789', $res['items'][0]['bank']['account_masked']);
    }
    public function testNextPageComesFromBoundedLocalNumbersNeverAnUpstreamUrl() {
        $this->http->queue[] = $this->response(array('data' => array($this->bank()),
            'links' => array('next' => 'https://evil.test/steal?key=private')));
        $res = $this->adapter()->bank_accounts(2);
        $this->assertSame(3, $res['next_page']);
        $this->assertStringNotContainsString('evil.test', json_encode($res));
        $this->assertCount(1, $this->http->calls);
        $this->http->queue[] = $this->response(array('data' => array(), 'meta' => array('last_page' => 99999)));
        $this->assertNull($this->adapter()->payout_history(10000)['next_page']);
    }
    public function testInvalidPageTypesAndUnsupportedCurrencyMakeNoRequests() {
        foreach (array(0, -1, '1.0', '1e2', '10001', array(1), true, null, '1/receipt', '01') as $bad) {
            $this->assertFalse($this->adapter()->bank_accounts($bad)['ok']);
            $this->assertFalse($this->adapter()->payout_history($bad)['ok']);
            $this->assertFalse($this->inspect($bad)['ok']);
        }
        $this->provider->currency = 'USD';
        $this->assertFalse($this->adapter()->fiat_wallet()['ok']);
        $this->assertFalse($this->adapter()->bank_accounts()['ok']);
        $this->assertCount(0, $this->http->calls);
    }
    public function testMalformedResponsesCannotBeDisplayedAsAnEmptySuccessfulTreasury() {
        foreach (array(array(), array('data' => 'not a list'), array('data' => array('id' => 8)),
            array('data' => array_fill(0, 101, $this->bank())), array('data' => array(null))) as $bad) {
            $this->http->queue[] = $this->response($bad);
            $this->assertFalse($this->adapter()->bank_accounts()['ok']);
        }
        foreach (array(array(), array('data' => array($this->history() + array())), array('data' => array(array('id' => 8, 'amount' => '1e6')))) as $i => $bad) {
            if ($i === 1) $bad['data'][0]['amount'] = array(2500);
            $this->http->queue[] = $this->response($bad);
            $this->assertFalse($this->adapter()->payout_history()['ok']);
        }
    }
    public function testWalletCurrencyMismatchInvalidAmountsAndProviderErrorsFailSafely() {
        foreach (array(array('data' => array('wallet' => array('currency' => 'CEDIS', 'balance' => 10))),
            array('nairaBalance' => 'NaN'), array('nairaBalance' => array(10)), array('balance' => 10, 'pendingBalance' => 'bad')) as $bad) {
            $this->http->queue[] = $this->response($bad);
            $this->assertFalse($this->adapter()->fiat_wallet()['ok']);
        }
        $this->http->queue[] = $this->response(array('message' => 'PRIVATE BANK BODY 0123456789'), 401);
        $res = $this->adapter()->payout_history();
        $this->assertFalse($res['ok']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($res));
        $this->assertStringNotContainsString('0123456789', json_encode($res));
    }
    public function testIndividualSectionOutageDoesNotInventBalancesOrHideOtherResults() {
        $this->http->queue[] = array('http_code' => 0, 'body' => null);
        $this->http->queue[] = $this->response(array('data' => array($this->bank())));
        $this->http->queue[] = $this->response(array('data' => array()));
        $res = $this->inspect();
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['wallet']['ok']);
        $this->assertArrayNotHasKey('reported_balance', $res['wallet']);
        $this->assertTrue($res['banks']['ok']); $this->assertTrue($res['history']['ok']);
        $this->assertCount(3, $this->http->calls);
    }
    public function testUnauditableOrUnauthenticatedInspectionMakesNoProviderCalls() {
        $this->assertFalse($this->app->prestmittreasuryservice->inspect($this->provider->public_id, null)['ok']);
        $this->app->Audit_log_model = new PrestmitTreasuryAuditFailure();
        $this->assertFalse($this->inspect()['ok']);
        $this->assertCount(0, $this->http->calls);
    }
    public function testUnknownWrongTypeAndInvalidUrlProvidersMakeNoRequests() {
        $this->assertFalse($this->app->prestmittreasuryservice->inspect('unknown', $this->user->id)['ok']);
        foreach (array(array('api_type' => 'MOCK_GIFTCARD'), array('api_type' => 'PRESTMIT', 'api_url' => 'https://evil.test')) as $changes) {
            $this->app->db->where('id', $this->provider->id)->update('providers', $changes);
            $this->assertFalse($this->inspect()['ok']);
        }
        $this->assertCount(0, $this->http->calls);
    }
    public function testRefreshBudgetBoundsProviderCallsWithoutSpendingTheLoginBucket() {
        for ($i = 0; $i < 10; $i++) { $this->queueInspection(); $this->assertTrue($this->inspect()['ok']); }
        $this->assertFalse($this->inspect()['ok']);
        $this->assertCount(30, $this->http->calls);
        $this->assertCount(0, $this->app->db->get('login_attempts')->result());
    }
    public function testUpstreamPayoutStatusesNeverSettleLocalMoney() {
        $before = $this->financialSnapshot();
        foreach (array('PENDING', 'COMPLETED', 'REJECTED', 'PENDING_CONFIRM', 'new-provider-status') as $status) {
            $this->http->queue[] = $this->response(array('data' => array($this->history($status))));
            $this->assertSame($status, $this->adapter()->payout_history()['items'][0]['status']);
        }
        $this->assertSame($before, $this->financialSnapshot());
    }
    public function testTreasuryRouteIsReadOnlyPermissionGatedAndNoStore() {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/application/controllers/admin/Prestmit_treasury.php');
        $view = file_get_contents($root.'/application/views/admin/providers/prestmit_treasury.php');
        $service = file_get_contents($root.'/application/libraries/PrestmitTreasuryService.php');
        $routes = file_get_contents($root.'/application/config/routes.php');
        $this->assertStringContainsString("require_perm('providers.view')", $controller);
        $this->assertStringContainsString("require_perm('providers.manage')", $controller);
        $this->assertStringContainsString("!== 'GET'", $controller);
        $this->assertStringContainsString('Cache-Control: no-store, private', $controller);
        $this->assertStringNotContainsString('<form', $view);
        $this->assertStringNotContainsString('marvy_money', $view);
        $this->assertStringContainsString('htmlspecialchars', $view);
        foreach (array('LedgerService', 'PayoutService', 'credit(', 'mark_paid(', 'code_encrypted') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service.$controller.$view);
        }
        $this->assertTrue(strpos($routes, "['admin/providers/(:any)/treasury']") < strpos($routes, "['admin/providers/(:any)']"));
    }
}
class PrestmitTreasuryHttp {
    public $queue = array(); public $calls = array();
    public function request($method, $url, $data, $headers, $options) {
        $this->calls[] = compact('method', 'url', 'data', 'headers', 'options');
        if (!$this->queue) throw new RuntimeException('Unexpected treasury request');
        return array_shift($this->queue);
    }
}
class PrestmitTreasuryAuditFailure { public function record(...$args) { return false; } }
