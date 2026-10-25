<?php
/**
 * modules/helpdeskNotify/view/index.php — formularz „Moje powiadomienia”. Zmienne: $p (prefs, muted_until), $email.
 */
$PAGE_TITLE = 'Helpdesk — Moje powiadomienia';
include dirname(__DIR__, 3) . '/includes/header.php';
echo hd_admin_nav('notify');
$muted = $p['muted_until'] && date('Y-m-d') <= $p['muted_until'];
?>
<style>
.hdn-card{background:#fff;border:1px solid #e1dfdd;max-width:760px}
.hdn-card h2{font-size:.95rem;font-weight:600;margin:0;padding:.65rem 1rem;border-bottom:1px solid #edebe9}
.hdn-row{display:grid;grid-template-columns:1fr 240px;gap:1rem;align-items:center;padding:.65rem 1rem;border-top:1px solid #f3f2f1;font-size:.88rem}
.hdn-row:first-of-type{border-top:0}
.hdn-row select,.hdn-card input[type=date]{border:1px solid #8a8886;border-radius:2px;padding:.3rem .5rem;font-size:.85rem;width:100%}
@media (max-width:600px){.hdn-row{grid-template-columns:1fr}}
</style>
<h4 class="fw-bold mb-1" style="font-size:1.4rem"><i class="bi bi-bell me-2" style="color:#0f6cbd"></i>Moje powiadomienia</h4>
<p class="text-muted small mb-3">Wiadomości e-mail dla operatora wysyłane na <strong><?= h($email) ?></strong>. Dotyczy tylko Twojego konta; zgłaszający i obserwatorzy mają własne zasady.</p>
<?= flash_html() ?>
<?php if ($muted): ?><div class="alert alert-warning py-2 small" style="max-width:760px"><i class="bi bi-moon me-1"></i>Powiadomienia są wstrzymane do <strong><?= h($p['muted_until']) ?></strong> włącznie.</div><?php endif; ?>
<form method="post" action="<?= h(route('hdnotify.save')) ?>">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="hdn-card mb-3">
    <h2>Które wiadomości chcesz dostawać</h2>
    <?php foreach (HD_NOTIFY_EVENTS as $k => $e): ?>
    <div class="hdn-row">
      <label for="n_<?= h($k) ?>"><?= h($e['label']) ?></label>
      <select id="n_<?= h($k) ?>" name="<?= h($k) ?>">
        <?php foreach ($e['modes'] as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $p['prefs'][$k] === $mk ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="hdn-card mb-3">
    <h2>Urlop — wstrzymaj wszystkie</h2>
    <div class="hdn-row">
      <label for="muted_until">Nie wysyłaj powiadomień do dnia (włącznie)<div class="text-muted" style="font-size:.78rem">Puste pole = powiadomienia aktywne.</div></label>
      <input type="date" id="muted_until" name="muted_until" value="<?= h((string)$p['muted_until']) ?>">
    </div>
  </div>
  <button class="btn btn-primary" style="background:#0f6cbd;border-color:#0f6cbd;border-radius:2px">Zapisz ustawienia</button>
</form>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
