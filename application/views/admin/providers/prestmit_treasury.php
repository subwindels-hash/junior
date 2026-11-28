<?php defined('BASEPATH') OR exit('No direct script access allowed');
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>
<div class="card stack">
  <div class="row justify-between"><h2 class="card-title">Prestmit treasury · read only</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/providers')?>">Providers</a></div>
  <p>This is the business's upstream partner account, not a customer's spending wallet or earnings payout queue.
    <strong>Bank changes and transfers are not available.</strong> Nothing on this page can send money,
    mark a local payout paid, credit a wallet or release reserved earnings. Listing an account is not proof of local customer ownership or authorization to pay it.</p>
  <p class="text-sm muted">Amounts below are reported API values, without conversion. Major/minor units and fee semantics
    are not yet confirmed in sandbox; do not use these values as transfer instructions or customer receipts.</p>
</div>
<?php if (empty($inspection['ok'])): ?>
<div class="card mt-4"><p role="status"><?=$esc($inspection['error'] ?? 'Treasury inspection unavailable.')?></p></div>
<?php else:
$p = $inspection['provider']; $wallet = $inspection['wallet']; $banks = $inspection['banks']; $history = $inspection['history'];
$url = function ($bp, $hp) use ($p) {
    return site_url('admin/providers/'.$p['public_id'].'/treasury').'?'.http_build_query(array('bank_page' => (int)$bp, 'history_page' => (int)$hp));
};
?>
<div class="card stack mt-4">
  <h3 class="card-title"><?=$esc($p['name'])?> · <?=$esc($p['environment'])?> · <?=$esc($p['currency'])?></h3>
  <p class="text-sm muted">Provider <?=$esc($p['status'])?> · Checked <?=$esc($inspection['checked_at'])?> UTC.
    Sensitive access is audited. Bank numbers are masked and account-holder names are not displayed.</p>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/providers/'.$p['public_id'])?>">Provider details</a>
  <?php if (empty($wallet['ok'])): ?><p role="status"><?=$esc($wallet['error'] ?? 'Wallet details unavailable.')?></p>
  <?php else: ?>
  <dl class="grid md:grid-cols-3 gap-4">
    <div><dt>Reported balance</dt><dd class="mono"><?=$esc($wallet['reported_balance'])?></dd></div>
    <div><dt>Reported pending</dt><dd class="mono"><?=$esc($wallet['reported_pending'] ?? 'Not supplied')?></dd></div>
    <div><dt>Reported total</dt><dd class="mono"><?=$esc($wallet['reported_total'] ?? 'Not supplied')?></dd></div>
  </dl>
  <?php endif; ?>
</div>
<div class="card stack mt-4">
  <h3 class="card-title">Upstream bank accounts</h3>
  <?php if (empty($banks['ok'])): ?><p role="status"><?=$esc($banks['error'] ?? 'Bank accounts unavailable.')?></p>
  <?php else: ?>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Provider ID</th><th>Bank</th><th>Account</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($banks['items'] as $bank): ?><tr>
      <td><?=$esc($bank['id'])?></td><td><?=$esc($bank['bank'])?></td>
      <td class="mono"><?=$esc($bank['account_masked'])?></td><td><?=$esc($bank['status'])?></td>
    </tr><?php endforeach; ?>
    <?php if (!$banks['items']): ?><tr><td colspan="4">No accounts on this page.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="row">
    <?php if ($inspection['bank_page'] > 1): ?><a class="btn btn-ghost btn-sm" href="<?=$esc($url($inspection['bank_page'] - 1, $inspection['history_page']))?>">Previous bank page</a><?php endif; ?>
    <span class="text-sm">Page <?=$inspection['bank_page']?></span>
    <?php if ($banks['next_page'] !== null): ?><a class="btn btn-ghost btn-sm" href="<?=$esc($url($banks['next_page'], $inspection['history_page']))?>">Next bank page</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<div class="card stack mt-4">
  <h3 class="card-title">Upstream payout history</h3>
  <p class="text-sm muted">Provider statuses are informational only; they are not applied to any local payment or customer balance.</p>
  <?php if (empty($history['ok'])): ?><p role="status"><?=$esc($history['error'] ?? 'Payout history unavailable.')?></p>
  <?php else: ?>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Provider ID / reference</th><th>Reported amount (units unverified)</th><th>Status</th><th>Bank / account</th><th>Created (provider)</th></tr></thead>
    <tbody><?php foreach ($history['items'] as $entry): ?><tr>
      <td class="mono"><?=$esc($entry['id'])?><br><?=$esc($entry['reference'])?></td>
      <td class="mono"><?=$esc($entry['reported_amount'])?></td><td><?=$esc($entry['status'])?></td>
      <td><?=$esc($entry['bank']['bank'])?><br><span class="mono"><?=$esc($entry['bank']['account_masked'])?></span></td>
      <td><?=$esc($entry['created_at'])?></td>
    </tr><?php endforeach; ?>
    <?php if (!$history['items']): ?><tr><td colspan="5">No payouts on this page.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="row">
    <?php if ($inspection['history_page'] > 1): ?><a class="btn btn-ghost btn-sm" href="<?=$esc($url($inspection['bank_page'], $inspection['history_page'] - 1))?>">Previous history page</a><?php endif; ?>
    <span class="text-sm">Page <?=$inspection['history_page']?></span>
    <?php if ($history['next_page'] !== null): ?><a class="btn btn-ghost btn-sm" href="<?=$esc($url($inspection['bank_page'], $history['next_page']))?>">Next history page</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
