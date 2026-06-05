<?php
/**
 * strategy/admin/settings.php — Ustawienia modułu Strategii.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/strategy.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia modułu Strategii';

function _strat_setting(string $key): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    return $r['value'] ?? '';
}
function _strat_save(string $key, string $value): void {
    $e = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
    if ($e) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value,$key]);
    else    db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key,$value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();
    $token = trim($_POST['strategy_webhook_token'] ?? '');
    if ($token) _strat_save('strategy_webhook_token', $token);
    flash_set('success', 'Ustawienia zapisane.');
    header('Location: ' . APP_URL . '/strategy/admin/settings.php'); exit;
}

$webhook_token = _strat_setting('strategy_webhook_token');

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<h1 style="font-size:1.35rem;font-weight:800;margin:0 0 1.5rem">
  <i class="bi bi-gear me-2" style="color:var(--strat-accent)"></i>Ustawienia modułu
</h1>
<?= flash_html() ?>

<div class="row g-4">
<div class="col-lg-6">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-webhook me-1"></i>Webhook n8n</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold small">Token autoryzacyjny</label>
          <input type="password" name="strategy_webhook_token" class="form-control form-control-sm font-monospace"
                 autocomplete="new-password"
                 placeholder="<?= $webhook_token ? '(zapisany — zostaw puste by nie zmieniać)' : 'Wpisz losowy token' ?>">
          <div class="form-text">
            URL webhooka:
            <code><?= h(APP_URL) ?>/api/strategy_webhook.php</code><br>
            Nagłówek: <code>Authorization: Bearer TOKEN</code>
          </div>
        </div>
        <button type="submit" name="_save" class="btn btn-sm"
                style="background:var(--strat-accent);color:#fff;border:none">
          <i class="bi bi-floppy me-1"></i>Zapisz
        </button>
      </form>
    </div>
  </div>
</div>

<div class="col-lg-6">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Zdarzenia n8n</div>
    <div class="card-body small">
      <table class="table table-sm mb-0">
        <thead><tr><th>Zdarzenie</th><th>Opis</th></tr></thead>
        <tbody>
          <tr><td><code>payment_added</code></td><td>Dodaje płatność do snapshotu budżetu</td></tr>
          <tr><td><code>progress_update</code></td><td>Pełna aktualizacja postępu i budżetu</td></tr>
          <tr><td><code>contract_linked</code></td><td>Powiązuje umowę z celem</td></tr>
          <tr><td><code>grant_linked</code></td><td>Powiązuje grant z celem</td></tr>
          <tr><td><code>snapshot_all</code></td><td>Auto-snapshot dla wszystkich aktywnych celów</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
