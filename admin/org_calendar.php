<?php
/**
 * admin/org_calendar.php — Ustawienia Kalendarza Organizacji (ICS)
 *
 * Konfiguracja kanału ICS widocznego w panelu wolontariusza.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ics_parser.php';

auth_start();
require_role('admin');
$PAGE_TITLE = 'Kalendarz organizacji — ustawienia';

$save_ok  = null;
$save_msg = '';
$test_result = null;

// ── Zapis ustawień ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'test') {
        // Test połączenia ICS
        $test_url = trim($_POST['org_calendar_ics_url'] ?? '');
        if (!$test_url) {
            $test_result = ['ok' => false, 'msg' => 'Podaj adres ICS do przetestowania.'];
        } else {
            IcsParser::invalidate_cache($test_url);
            $ics = IcsParser::fetch($test_url, 60);
            if ($ics === null) {
                $test_result = ['ok' => false, 'msg' => 'Nie można pobrać pliku ICS. Sprawdź URL i uprawnienia.'];
            } else {
                $events = IcsParser::parse($ics);
                $test_result = [
                    'ok'  => true,
                    'msg' => 'Połączenie OK — znaleziono ' . count($events) . ' wydarzeń.',
                    'preview' => array_slice($events, 0, 5),
                ];
            }
        }
    } else {
        // Zwykły zapis
        $fields = [
            'org_calendar_ics_url'    => trim($_POST['org_calendar_ics_url'] ?? ''),
            'org_calendar_cache_ttl'  => max(60, (int)($_POST['org_calendar_cache_ttl'] ?? 3600)),
            'org_calendar_past_days'  => max(0, (int)($_POST['org_calendar_past_days'] ?? 7)),
            'org_calendar_future_days'=> max(30, (int)($_POST['org_calendar_future_days'] ?? 365)),
        ];

        foreach ($fields as $key => $val) {
            try {
                $exists = db_one("SELECT id FROM settings WHERE key_=?", [$key]);
                if ($exists) {
                    db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
                } else {
                    db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $val]);
                }
            } catch (\Throwable $e) {
                $save_ok  = false;
                $save_msg = 'Błąd zapisu: ' . $e->getMessage();
                break;
            }
        }

        // Wymuś odświeżenie cache jeśli URL się zmienił
        if ($save_ok !== false) {
            $new_url = $fields['org_calendar_ics_url'];
            if ($new_url) IcsParser::invalidate_cache($new_url);
            $save_ok  = true;
            $save_msg = 'Ustawienia zostały zapisane.';
        }
    }
}

// ── Odczyt bieżących ustawień ──────────────────────────────────────────────────
$cfg = [
    'ics_url'     => org_setting('org_calendar_ics_url'),
    'cache_ttl'   => org_setting('org_calendar_cache_ttl')   ?: '3600',
    'past_days'   => org_setting('org_calendar_past_days')   ?: '7',
    'future_days' => org_setting('org_calendar_future_days') ?: '365',
];

$module_on = module_enabled('org_calendar_enabled');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:860px">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="mb-0">
      <i class="bi bi-calendar3 text-primary me-2"></i>Kalendarz organizacji
    </h4>
    <a href="<?= APP_URL ?>/panel/calendar.php" target="_blank"
       class="btn btn-sm btn-outline-primary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Podgląd w panelu wolontariusza
    </a>
  </div>

  <?php if ($save_ok !== null && !isset($test_result)): ?>
    <div class="alert alert-<?= $save_ok ? 'success' : 'danger' ?> py-2">
      <?= h($save_msg) ?>
    </div>
  <?php endif; ?>

  <!-- Status modułu -->
  <div class="card shadow-sm mb-4">
    <div class="card-body py-2 d-flex align-items-center gap-3">
      <?php if ($module_on): ?>
        <span class="badge bg-success-subtle text-success border border-success">
          <i class="bi bi-check-circle me-1"></i>Moduł włączony
        </span>
      <?php else: ?>
        <span class="badge bg-danger-subtle text-danger border border-danger">
          <i class="bi bi-x-circle me-1"></i>Moduł wyłączony
        </span>
      <?php endif; ?>
      <span class="text-muted small">
        Włącz/wyłącz w
        <a href="<?= APP_URL ?>/admin/modules_settings.php">Ustawienia → Moduły</a>
        (klucz: <code>org_calendar_enabled</code>)
      </span>
    </div>
  </div>

  <!-- Konfiguracja ICS -->
  <form method="post" id="cal-settings-form">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">

    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">
        <i class="bi bi-link-45deg me-2"></i>Źródło kalendarza (ICS)
      </div>
      <div class="card-body">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="ics-url">
            Adres URL kalendarza ICS
          </label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            <input type="url" id="ics-url" name="org_calendar_ics_url"
                   class="form-control font-monospace"
                   value="<?= h($cfg['ics_url']) ?>"
                   placeholder="https://outlook.office365.com/owa/calendar/…/calendar.ics">
          </div>
          <div class="form-text">
            Publiczny lub prywatny URL do pliku <code>.ics</code>.
            Dla kalendarza Outlook 365: <em>Kalendarze</em> → <em>Udostępnij</em> → <em>Publikuj do Internetu</em> → skopiuj link ICS.<br>
            Możesz też użyć kalendarza Google: <em>Ustawienia kalendarza</em> → <em>Adres iCal</em>.<br>
            <strong>Obsługiwane:</strong> HTTP, HTTPS. Adresy z uwierzytelnieniem: <code>https://user:haslo@serwer/cal.ics</code>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label" for="cache-ttl">Cache (sekundy)</label>
            <input type="number" id="cache-ttl" name="org_calendar_cache_ttl"
                   class="form-control" min="60" max="86400"
                   value="<?= h($cfg['cache_ttl']) ?>">
            <div class="form-text">Min. 60 s. Zalecane: 3600 (1h).</div>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="past-days">Pokaż przeszłe (dni)</label>
            <input type="number" id="past-days" name="org_calendar_past_days"
                   class="form-control" min="0" max="365"
                   value="<?= h($cfg['past_days']) ?>">
            <div class="form-text">0 = tylko przyszłe.</div>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="future-days">Pokaż przyszłe (dni)</label>
            <input type="number" id="future-days" name="org_calendar_future_days"
                   class="form-control" min="30" max="3650"
                   value="<?= h($cfg['future_days']) ?>">
            <div class="form-text">Zalecane: 365.</div>
          </div>
        </div>

      </div>
      <div class="card-footer d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-floppy me-1"></i>Zapisz
        </button>
        <button type="button" class="btn btn-outline-secondary" id="btn-test">
          <i class="bi bi-wifi me-1"></i>Testuj połączenie
        </button>
      </div>
    </div>
  </form>

  <!-- Wynik testu -->
  <?php if (isset($test_result)): ?>
    <div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> d-flex gap-2 align-items-start">
      <i class="bi bi-<?= $test_result['ok'] ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-5 flex-shrink-0"></i>
      <div>
        <strong><?= h($test_result['msg']) ?></strong>
        <?php if (!empty($test_result['preview'])): ?>
          <div class="mt-2 small">
            <strong>Pierwsze 5 wydarzeń:</strong>
            <ul class="mb-0 mt-1">
              <?php foreach ($test_result['preview'] as $pev): ?>
                <?php
                  $pstart = $pev['dtstart'];
                  $pdate  = $pstart instanceof \DateTimeInterface ? $pstart->format('d.m.Y H:i') : '?';
                ?>
                <li>
                  <strong><?= h($pev['summary']) ?></strong>
                  <span class="text-muted">&mdash; <?= h($pdate) ?></span>
                  <?php if ($pev['location']): ?>
                    <span class="text-muted"> · <?= h($pev['location']) ?></span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Skąd wziąć ICS z Outlook -->
  <div class="card shadow-sm mb-4 border-info-subtle">
    <div class="card-header text-info-emphasis bg-info-subtle fw-semibold">
      <i class="bi bi-microsoft me-2"></i>Jak uzyskać URL ICS z Outlook 365?
    </div>
    <div class="card-body small">
      <ol class="mb-0">
        <li>Otwórz <a href="https://outlook.office.com" target="_blank" rel="noopener">outlook.office.com</a> → <strong>Kalendarz</strong></li>
        <li>Kliknij ikonę ⚙️ (Ustawienia) → <strong>Wyświetl wszystkie ustawienia Outlooka</strong></li>
        <li>Przejdź do: <strong>Kalendarz → Udostępnione kalendarze → Publikuj kalendarz</strong></li>
        <li>Wybierz kalendarz, ustaw dostęp, kliknij <strong>Publikuj</strong></li>
        <li>Skopiuj link <strong>ICS</strong> i wklej go powyżej</li>
      </ol>
      <div class="alert alert-warning py-2 mt-3 mb-0">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>Uwaga:</strong> Publiczny URL ICS nie wymaga logowania — każdy z linkiem może pobrać kalendarz.
        Jeśli chcesz ograniczyć dostęp, użyj prywatnego URL (z tokenem w adresie).
      </div>
    </div>
  </div>

</div>

<script>
document.getElementById('btn-test').addEventListener('click', function () {
  const form = document.getElementById('cal-settings-form');
  const hidden = form.querySelector('[name="_action"]');
  const orig = hidden.value;
  hidden.value = 'test';
  form.submit();
  hidden.value = orig;
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
