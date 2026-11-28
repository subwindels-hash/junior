<?php defined('BASEPATH') OR exit('No direct script access allowed');
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$csrf = '<input type="hidden" name="'.$esc($this->security->get_csrf_token_name()).'" value="'.$esc($this->security->get_csrf_hash()).'">';
?>
<div class="card stack">
  <div class="row justify-between"><h2 class="card-title">Sell a gift card</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/giftcards')?>">Buy gift cards</a></div>
  <?php $this->load->view('dashboard/giftcards/_flash'); ?>
  <p class="text-sm muted">Trade a gift card for NGN wallet credit. Staff review every submission before sending it to the provider.
    Only catalogue-listed forms are accepted. Only NGN wallet credit is offered; bank and crypto payouts are not supported here.</p>
  <?php if (empty($catalogue['ok'])): ?>
    <p role="status"><?=$esc($catalogue['error'] ?? 'Selling is unavailable.')?></p>
  <?php elseif (!$catalogue['cards']): ?>
    <p>No gift cards are available for sale right now.</p>
  <?php else: ?>
    <form method="post" action="<?=site_url('dashboard/giftcards/sell/quote')?>" class="stack">
      <?=$csrf?>
      <label class="field"><span class="label">Card variant</span>
        <select class="select" name="giftcard_id" required>
          <option value="">Choose the exact card and country</option>
          <?php foreach ($catalogue['cards'] as $card): ?>
            <option value="<?=$esc($card['id'])?>"><?=$esc($card['name'].' · '.$card['form'].' · '.$card['country'].' · minimum face value '.$card['minimum'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">Face value printed on this one card</span>
        <input class="input" name="amount" type="number" min="0.01" max="10000" step="0.01" required inputmode="decimal">
      </label>
      <button class="btn btn-primary" type="submit">Get a quote</button>
    </form>
    <details class="text-sm"><summary>Variant terms</summary>
      <?php foreach ($catalogue['cards'] as $card): ?>
        <p><strong><?=$esc($card['name'])?></strong> — <?=$esc($card['terms'])?></p>
      <?php endforeach; ?>
    </details>
  <?php endif; ?>
</div>
<?php if ($quote && $quote->status === 'QUOTED' && !empty($catalogue['ok']) && $quote->quote_expires_at >= gmdate('Y-m-d H:i:s')): ?>
<div class="card stack mt-4">
  <h3 class="card-title">Review your quote</h3>
  <p><?=$esc($quote->card_name)?> · face value <?=$esc($quote->face_value)?></p>
  <p>Estimated maximum wallet payout: <strong><?=marvy_money($quote->estimated_payout, $quote->currency)?></strong>.
    Service margin: <?=$esc($quote->margin_percent)?>%. Quote expires <?=$esc($quote->quote_expires_at)?> UTC.</p>
  <p class="text-sm muted">Final credit is based on the provider's accepted proceeds after the stated margin,
    capped at this quote. A reduced accepted card value may reduce your payout. Rejected cards receive no credit.</p>
  <p class="text-sm"><?=$esc($quote->terms_text ?? '')?></p>
  <form method="post" action="<?=site_url('dashboard/giftcards/sell/'.$quote->public_id.'/submit')?>" class="stack" autocomplete="off" enctype="multipart/form-data">
    <?=$csrf?>
    <?php if ($quote->card_form === 'Physical'): ?>
    <label class="field"><span class="label">Physical card images</span>
      <input class="input" type="file" name="images[]" accept="image/jpeg,image/png" multiple required>
      <span class="hint">1–20 clear JPEG/PNG images, at most 5 MiB each, 20 MiB total. Stored encrypted and visible only to authorized reviewers. No PDFs or screenshots of codes for e-code variants.</span>
    </label>
    <?php else: ?>
    <label class="field"><span class="label">One digital gift-card code</span>
      <input class="input" name="code" type="password" minlength="4" maxlength="2000" required autocomplete="new-password" spellcheck="false">
      <span class="hint">Stored encrypted. Never put a code in a support ticket or message. Do not submit a physical card.</span>
    </label>
    <?php endif; ?>
    <label><input type="checkbox" name="accepted" value="1" required>
      I own this card, am authorized to sell it, accept the variant terms and the final-payout policy above,
      and understand that it cannot be cancelled after submission.</label>
    <button class="btn btn-primary" type="submit" data-loading-text="Submitting…">Submit for review</button>
  </form>
</div>
<?php endif; ?>
<div class="card mt-4">
  <h3 class="card-title">My gift-card sales</h3>
  <p class="text-sm muted">No money is credited for QUOTED, REVIEW, SUBMITTING or PENDING sales. CREDITED means your wallet was paid.</p>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Reference / card</th><th>Status</th><th>Maximum quote</th><th>Credited</th></tr></thead>
    <tbody><?php foreach ($sales as $sale): ?><tr>
      <td><span class="mono"><?=$esc($sale->public_id)?></span><br><?=$esc($sale->card_name)?></td>
      <td><?=$esc($sale->status)?></td><td><?=marvy_money($sale->estimated_payout, $sale->currency)?></td>
      <td><?=$sale->credited_amount !== null ? marvy_money($sale->credited_amount, $sale->currency) : '—'?></td>
    </tr><?php endforeach; ?>
    <?php if (!$sales): ?><tr><td colspan="4">You have not requested any quotes yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="row mt-4">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/giftcards/sell?page='.($page-1))?>">Previous</a><?php endif; ?>
    <?php if ($page * 25 < $total): ?><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/giftcards/sell?page='.($page+1))?>">Next</a><?php endif; ?>
  </div>
</div>
