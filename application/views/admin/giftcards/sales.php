<?php defined('BASEPATH') OR exit('No direct script access allowed');
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$csrf = '<input type="hidden" name="'.$esc($this->security->get_csrf_token_name()).'" value="'.$esc($this->security->get_csrf_hash()).'">';
$can_manage = in_array('*', $permissions, true) || in_array('giftcards.manage', $permissions, true);
$can_credit = in_array('*', $permissions, true) || in_array('giftcards.refund', $permissions, true);
?>
<div class="card stack">
  <div class="row justify-between"><h2 class="card-title">Gift-card sales review</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/giftcards')?>">Purchase orders</a></div>
  <?php $this->load->view('dashboard/giftcards/_flash'); ?>
  <p class="text-sm muted">Codes are encrypted and never displayed here. Check the customer and variant before dispatching.
    Physical images require gift-card management permission to view. Submitting transfers the code or images to Prestmit for review; it does not pay the customer.
    Reconciliation credits the wallet only after a matching COMPLETED trade with valid NGN proceeds.</p>
  <?php if (!$selling_enabled): ?><p class="text-sm">New sales are disabled. Reconciliation remains available for existing submissions.</p><?php endif; ?>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Sale / customer</th><th>Card / face value</th><th>Status</th><th>Quote / credited</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($sales as $sale): ?><tr>
      <td class="mono"><?=$esc($sale->public_id)?><br>User #<?=$esc($sale->user_id)?><br><?=$esc($sale->provider_reference ?? 'No upstream reference')?></td>
      <td><?=$esc($sale->card_name)?><br><?=$esc($sale->face_value)?></td>
      <td><?=$esc($sale->status)?><br><small><?=$esc($sale->last_checked_at ?? '')?></small></td>
      <td><?=marvy_money($sale->estimated_payout, $sale->currency)?><br>
        <?=$sale->credited_amount !== null ? marvy_money($sale->credited_amount, $sale->currency) : 'Not credited'?></td>
      <td>
        <?php if ($sale->card_form === 'Physical' && $can_manage): ?>
          <?php for ($image = 0; $image < (int)$sale->image_count; $image++): ?>
            <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener noreferrer" href="<?=site_url('admin/giftcards/sales/'.$sale->public_id.'/images/'.$image)?>">Image <?=$image + 1?></a>
          <?php endfor; ?>
        <?php endif; ?>
        <?php if ($sale->status === 'REVIEW' && $can_manage): ?>
          <?php if ($selling_enabled): ?><form method="post" action="<?=site_url('admin/giftcards/sales/'.$sale->public_id.'/dispatch')?>" data-confirm="Send this encrypted submission to Prestmit? This cannot be undone.">
            <?=$csrf?><button class="btn btn-primary btn-sm" type="submit">Submit to Prestmit</button>
          </form><?php endif; ?>
          <form method="post" action="<?=site_url('admin/giftcards/sales/'.$sale->public_id.'/reject')?>" class="mt-2" data-confirm="Decline this unsubmitted sale?">
            <?=$csrf?><button class="btn btn-ghost btn-sm" type="submit">Decline locally</button>
          </form>
        <?php elseif (in_array($sale->status, array('SUBMITTING', 'PENDING'), true) && $can_credit): ?>
          <form method="post" action="<?=site_url('admin/giftcards/sales/'.$sale->public_id.'/reconcile')?>" data-confirm="Check Prestmit and credit the wallet only if completion is confirmed?">
            <?=$csrf?><button class="btn btn-secondary btn-sm" type="submit">Reconcile</button>
          </form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr><?php endforeach; ?>
    <?php if (!$sales): ?><tr><td colspan="5">No gift-card sales yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="row">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/giftcards/sales?page='.($page-1))?>">Previous</a><?php endif; ?>
    <?php if ($page * 25 < $total): ?><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/giftcards/sales?page='.($page+1))?>">Next</a><?php endif; ?>
  </div>
</div>
