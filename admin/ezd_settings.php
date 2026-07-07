<?php
/**
 * Ustawienia modułu Wirtualne biurko.
 * Włącznik modułu, powiadomienia o terminach, przegląd i skróty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia modułu Wirtualne biurko';

$settings_keys = [
    'ezd_enabled',
    'ezd_mini',
    'ezd_reminders_enabled',
    'ezd_peln_jrwa',
    'ezd_cert_jrwa',
    'ezd_kdok_jrwa',
    'corr_ezd_auto',
    'corr_ezd_jrwa',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values = [
        'ezd_enabled'           => isset($_POST['ezd_enabled'])           ? '1' : '0',
        'ezd_mini'              => isset($_POST['ezd_mini'])              ? '1' : '0',
        'ezd_reminders_enabled' => isset($_POST['ezd_reminders_enabled']) ? '1' : '0',
        'ezd_peln_jrwa'         => trim($_POST['ezd_peln_jrwa'] ?? '') ?: '013',
        'ezd_cert_jrwa'         => trim($_POST['ezd_cert_jrwa'] ?? '') ?: '53',
        'ezd_kdok_jrwa'         => trim($_POST['ezd_kdok_jrwa'] ?? '') ?: 'KSG',
        'corr_ezd_auto'         => isset($_POST['corr_ezd_auto']) ? '1' : '0',
        'corr_ezd_jrwa'         => trim($_POST['corr_ezd_jrwa'] ?? '') ?: 'KOR',
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
  <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Ustawienia modułu Wirtualne biurko</h4>
  <a href="<?= APP_URL ?>/ezd/index.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Przejdź do EZD</a>
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
            <div class="form-text">Udostępnia sekcję „Wirtualne biurko" w menu (Koszulki, Segregatory, Pisma, Dziennik podawczy, JRWA). To samo ustawienie znajdziesz w <a href="<?= APP_URL ?>/admin/modules_settings.php">Modułach</a>.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_mini" name="ezd_mini" <?= $cfg['ezd_mini'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_mini">Tryb „mini" (uproszczony)</label>
            <div class="form-text">Uproszczony rejestr spraw i dokumentów — ukrywa formalne elementy postępowania w widoku sprawy: <strong>metrykę</strong> oraz <strong>obieg/workflow (BPM)</strong>. Pozostają: dane sprawy, pisma, dokumenty wewnętrzne, notatki, repozytorium plików i dekretacja.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_reminders_enabled" name="ezd_reminders_enabled" <?= $cfg['ezd_reminders_enabled'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_reminders_enabled">Powiadomienia e-mail o terminach</label>
            <div class="form-text">Codzienny cron wysyła przypomnienia o terminach dekretacji (do wykonawcy) i spraw (do właściciela): jutro / dziś / po terminie.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="corr_ezd_auto" name="corr_ezd_auto" <?= $cfg['corr_ezd_auto'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="corr_ezd_auto">Automatyczna rejestracja korespondencji w EZD Wirtualne biurko</label>
            <div class="form-text">Każda nowa korespondencja przychodząca/wychodząca trafia automatycznie do sprawy ciągłej „Korespondencja przychodząca/wychodząca {rok}" jako pismo (z przeniesieniem załącznika). Po wyłączeniu pozostaje ręczne „Zarejestruj w EZD".</div>
          </div>
          <div class="row g-3">
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_peln_jrwa">Symbol JRWA pełnomocnictw</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_peln_jrwa" name="ezd_peln_jrwa" value="<?= h($cfg['ezd_peln_jrwa'] ?: '013') ?>" placeholder="013">
              <div class="form-text"><a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php">Rejestr pełnomocnictw</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_cert_jrwa">Symbol JRWA zaświadczeń</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_cert_jrwa" name="ezd_cert_jrwa" value="<?= h($cfg['ezd_cert_jrwa'] ?: '53') ?>" placeholder="53">
              <div class="form-text"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Rejestr zaświadczeń</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="corr_ezd_jrwa">Symbol JRWA korespondencji</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="corr_ezd_jrwa" name="corr_ezd_jrwa" value="<?= h($cfg['corr_ezd_jrwa'] ?: 'KOR') ?>" placeholder="KOR">
              <div class="form-text"><a href="<?= APP_URL ?>/correspondence/index.php">Korespondencja</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_kdok_jrwa">Symbol JRWA dok. księgowych</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_kdok_jrwa" name="ezd_kdok_jrwa" value="<?= h($cfg['ezd_kdok_jrwa'] ?: 'KSG') ?>" placeholder="KSG">
              <div class="form-text">JRWA „Dokumenty księgowe - obieg od zapłaty". Dokumenty zatwierdzone do wypłaty w <a href="<?= APP_URL ?>/ksiegowosc/index.php">EOD Dok. Księgowych</a> trafiają tu automatycznie.</div>
            </div>
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
            ['Segregatory otwarte', $stat['teczki_open'], '/ezd/teczki/index.php'],
            ['Koszulki aktywne', $stat['sprawy_open'], '/ezd/sprawy/index.php'],
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
