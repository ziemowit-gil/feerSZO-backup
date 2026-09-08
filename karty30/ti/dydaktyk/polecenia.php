<?php
/**
 * karty30/ti/dydaktyk/polecenia.php — Program poleceń (rabaty), widok kierownika.
 *
 * Ustawienia (włącznik, X/Y/Z) + lista wszystkich poleceń z ich statusem.
 * Sama logika naliczania rabatu jest w includes/ti_referrals.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_referrals.php';

$me  = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();
ti_referrals_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    if (($_POST['_op'] ?? '') === 'save_settings') {
        ti_referral_settings_save(
            isset($_POST['enabled']),
            (float)str_replace(',', '.', (string)($_POST['referrer_pct'] ?? '0')),
            (float)str_replace(',', '.', (string)($_POST['referred_pct'] ?? '0')),
            (int)($_POST['referred_periods'] ?? '0')
        );
        flash_set('success', 'Ustawienia programu poleceń zapisane.');
    }
    header('Location: polecenia.php'); exit;
}

$settings   = ti_referral_settings();
$referrals  = ti_referrals_list_all();

$KP_TITLE      = 'Program poleceń — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'polecenia.php'; $KIER_LABEL = 'Program poleceń';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-gift text-primary me-2" aria-hidden="true"></i>Program poleceń</h1>
</div>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold">Ustawienia</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="save_settings">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ref_on" name="enabled" value="1"
                   <?= $settings['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ref_on">Program poleceń włączony</label>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="referrer_pct">Rabat dla polecającego (jednorazowo)</label>
            <div class="input-group">
              <input type="number" class="form-control" id="referrer_pct" name="referrer_pct" min="0" max="100" step="0.5"
                     value="<?= h((string)$settings['referrer_pct']) ?>">
              <span class="input-group-text">%</span>
            </div>
            <div class="form-text">Naliczany na najbliższe rozliczenie polecającego, gdy tylko poleconemu wystawimy pierwsze rozliczenie.</div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="referred_pct">Rabat dla poleconego</label>
            <div class="input-group">
              <input type="number" class="form-control" id="referred_pct" name="referred_pct" min="0" max="100" step="0.5"
                     value="<?= h((string)$settings['referred_pct']) ?>">
              <span class="input-group-text">%</span>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="referred_periods">Liczba okresów rabatu poleconego</label>
            <input type="number" class="form-control" id="referred_periods" name="referred_periods" min="0" step="1"
                   value="<?= h((string)$settings['referred_periods']) ?>">
            <div class="form-text">Okres = miesiąc rozliczeniowy. Np. 2 = rabat na pierwsze dwa rozliczenia poleconego.</div>
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz ustawienia
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        Polecenia
        <span class="badge bg-secondary"><?= count($referrals) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Polecający</th>
              <th>Polecony</th>
              <th>Nagroda polecającego</th>
              <th>Rabat poleconego</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$referrals): ?>
            <tr><td colspan="5" class="text-body-secondary text-center py-3">Brak poleceń — nikt jeszcze nie skorzystał z kodu.</td></tr>
            <?php endif; ?>
            <?php foreach ($referrals as $r): ?>
            <tr>
              <td><?= h($r['referrer_name']) ?></td>
              <td><?= h($r['referred_name']) ?></td>
              <td>
                <?php if ($r['referrer_reward_status'] === 'applied'): ?>
                <span class="badge text-bg-success">naliczona -<?= h((string)$r['referrer_pct']) ?>%</span>
                <?php else: ?>
                <span class="badge text-bg-secondary">oczekuje</span>
                <?php endif; ?>
              </td>
              <td>
                -<?= h((string)$r['referred_pct']) ?>% ·
                <?= (int)$r['referred_periods_used'] ?>/<?= (int)$r['referred_periods_total'] ?> okresów
                <?php if ($r['referred_status'] === 'completed'): ?>
                <span class="badge text-bg-secondary ms-1">zakończony</span>
                <?php endif; ?>
              </td>
              <td class="text-body-secondary small"><?= date('d.m.Y', strtotime((string)$r['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
