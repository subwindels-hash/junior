<?php
// Offline audit only: fake provider, fake DB, fake card, no network or live funds.
// Run: node tools/devserver/php_run.mjs tools/audits/prestmit_recovery_repro.php
$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap.php';
require $root.'/tests/_support/IntegrationHarness.php';
if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
function &get_instance() { return $GLOBALS['__fake_ci']; }
function log_message($level, $message) {}
require $root.'/application/core/MY_Model.php';
require $root.'/application/helpers/marvy_helper.php';
class AuditPendingHttp {
    public $deliver = false;
    public function request($method, $url, $data, $headers, $options) {
        if ($this->deliver && strpos($url, '/fetch-codes/') !== false) return array('http_code' => 200, 'body' => '[{"cardNumber":"FAKE-LATE-CARD"}]');
        if ($this->deliver && strpos($url, '/history') !== false) return array('http_code' => 200, 'body' => '{"data":[{"reference":"BGC-AUDIT-LATE","status":"COMPLETED"}]}');
        if (strpos($url, '/calculate-payment') !== false) return array('http_code' => 200, 'body' => '{"NAIRA":35000}');
        if (strpos($url, '/buy/create') !== false) return array('http_code' => 0, 'body' => null);
        return array('http_code' => 200, 'body' => '{"data":[]}');
    }
}
$app = new IntegrationHarness();
$app->seed_minimal(); $app->seed_giftcards();
$user = $app->register('audit_buyer', 'audit-buyer@example.test');
$app->credit($user, '500000');
$app->library('EncryptionService');
$app->securehttpclient = new AuditPendingHttp();
$product = $app->db->where('code', 'AMAZON-US-25')->get('giftcard_products')->row();
$app->db->where('id', $product->provider_id)->update('providers', array(
    'api_type' => 'PRESTMIT', 'api_url' => 'https://dev-api.prestmit.io/partners/v1',
    'api_key_encrypted' => $app->encryptionservice->encrypt('{"api_key":"dummy-key","api_secret":"dummy-secret","account_pin":"0123"}')
));
$app->library(array('GiftcardService', 'CronWorkers'));
$result = $app->giftcardservice->purchase($user, array('product' => 'AMAZON-US-25'));
$id = $result['transaction']->id;
$before = array('status' => $result['transaction']->status, 'balance' => $app->balance($user));
$app->db->where('id', $id)->update('service_transactions', array('created_at' => gmdate('Y-m-d H:i:s', time() - 48*3600)));
$recovery = $app->cronworkers->service_recovery();
$tx = $app->Service_transaction_model->find_by_id($id);
$order = $app->Giftcard_order_model->for_transaction($id);
echo json_encode(array('before' => $before, 'recovery' => $recovery,
    'after' => array('status' => $tx->status, 'balance' => $app->balance($user), 'order_status' => $order->status)), JSON_PRETTY_PRINT)."\n";

$app->securehttpclient->deliver = true;
$late = $app->giftcardservice->collect($order, 'CRON');
$finalOrder = $app->Giftcard_order_model->for_transaction($id);
echo json_encode(array('late_collection' => array('ok' => $late['ok'],
    'ready' => $late['ready'] ?? null, 'transaction_status' => $app->Service_transaction_model->find_by_id($id)->status,
    'order_status' => $finalOrder->status,
    'stored_card_count' => count($app->Giftcard_code_model->for_order($order->id)),
    'wallet_balance' => $app->balance($user))), JSON_PRETTY_PRINT)."\n";

$unsafe = (int)$recovery['refunded'] > 0 || (!empty($late['ready']) && $tx->status === 'FAILED');
echo $unsafe ? "AUDIT FAIL: unresolved Prestmit purchase was refunded or delivered after refund.\n"
             : "AUDIT PASS: unresolved purchase was not refunded or delivered after refund.\n";
exit($unsafe ? 1 : 0);
