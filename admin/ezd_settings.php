<?php
/**
 * Ustawienia modułu Kancelaria EZD.
 * Włącznik modułu, powiadomienia o terminach, przegląd i skróty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia modułu Kancelaria EZD';

$settings_keys = [
    'ezd_enabled',
    'ezd_reminders_enabled',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values = [
        'ezd_enabled'           => isset($_POST['ezd_enabled'])           ? '1' : '0',
        'ezd_reminders_enabled' => isset($_POST['ezd_reminders_enabled']) ? '1' : '0',
    ];
    foreach ($values as $key => $val) {
        try {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?)
                ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$key, $val]);
        } catch (\Throwable $e) {
            $exists = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
            if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
            else         db_insert('settings', ['key_' => $key, 'value' => $val]);
        }
    }
    flash_set('success', 'Ustawienia modułu EZD zostały zapisane.');
    header('Location: ezd_settings.php'); exit;
}

$cfg = [];
foreach ($settings_keys as $k) $cfg[$k] = org_setting($k);
if ($cfg['ezd_reminders_enabled'] === '') $cfg['ezd_reminders_enabled'] = '1';

// Przegląd modułu
$stat = ezd_stats();
$rpw  = ezd_rpw_stats();
$counts = [
    'jrwa'      => (int)(db_one("SELECT COUNT(*) c FROM ezd_jrwa")['c'] ?? 0),
    'dokumenty' => (int)(db_one("SELECT COUNT(*) c FROM ezd_dokumenty")['c'] ?? 0),
    'notatki'   => (int)(db_one("SELECT COUNT(*) c FROM ezd_notatki")['c'] ?? 0),
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Ustawienia modułu Kancelaria EZD</h4>
  <a href="<?= APP_URL ?>/ezd/index.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Przejdź do kancelarii</a>
</div>

<?= flash_html() ?>

<div class="row g-4" style="max-width:920px">
  <div class="col-12">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-gear me-2 text-primary"></i>Ogólne</div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_enabled" name="ezd_enabled" <?= module_enabled('ezd_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_enabled">Moduł włączony</label>
            <div class="form-text">Udostępnia sekcję „Kancelaria EZD" w menu (Teczki, Sprawy, Pisma, Dziennik podawczy, JRWA). To samo ustawienie znajdziesz w <a href="<?= APP_URL ?>/admin/modules_settings.php">Modułach</a>.</div>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_reminders_enabled" name="ezd_reminders_enabled" <?= $cfg['ezd_reminders_enabled'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_reminders_enabled">Powiadomienia e-mail o terminach</label>
            <div class="form-text">Codzienny cron wysyła przypomnienia o terminach dekretacji (do wykonawcy) i spraw (do właściciela): jutro / dziś / po terminie.</div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz ustawienia</button>
    </form>
  </div>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-graph-up me-2 text-primary"></i>Przegląd modułu</div>
      <div class="card-body">
        <div class="row g-3 text-center">
          <?php foreach ([
            ['Teczki otwarte', $stat['teczki_open'], '/ezd/teczki/index.php'],
            ['Sprawy aktywne', $stat['sprawy_open'], '/ezd/sprawy/index.php'],
            ['W koszulce (RPW)', $rpw['koszulka'], '/ezd/rpw/index.php?status=nowa'],
            ['Hasła JRWA', $counts['jrwa'], '/ezd/jrwa/index.php'],
            ['Dokumenty wewn.', $counts['dokumenty'], null],
            ['Notatki', $counts['notatki'], null],
          ] as [$lbl,$val,$url]): ?>
          <div class="col-6 col-md-4 col-lg-2">
            <?php if($url): ?><a href="<?= APP_URL.$url ?>" class="text-decoration-none text-reset d-block"><?php endif; ?>
            <div class="border rounded-3 py-3">
              <div style="font-size:1.6rem;font-weight:700;line-height:1"><?= (int)$val ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= h($lbl) ?></div>
            </div>
            <?php if($url): ?></a><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
