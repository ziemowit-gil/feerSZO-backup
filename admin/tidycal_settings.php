<?php
/**
 * admin/tidycal_settings.php — Konfiguracja integracji TidyCal (rezerwacja szkoleń).
 * Token API, włączenie modułu, wybór udostępnionych typów szkoleń, tekst wprowadzający,
 * test połączenia oraz lista ostatnich rezerwacji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tidycal.php';

require_role('admin');
$PAGE_TITLE = 'TidyCal — rezerwacja szkoleń';

$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save') {
        org_setting_set('tidycal_api_key', trim($_POST['tidycal_api_key'] ?? ''));
        org_setting_set('tidycal_enabled', isset($_POST['tidycal_enabled']) ? '1' : '0');
        org_setting_set('tidycal_intro',   trim($_POST['tidycal_intro'] ?? ''));
        $exposed = array_values(array_unique(array_map('intval', (array)($_POST['exposed'] ?? []))));
        org_setting_set('tidycal_exposed_types', json_encode($exposed));
        $required = array_values(array_unique(array_map('intval', (array)($_POST['required'] ?? []))));
        org_setting_set('tidycal_required_types', json_encode($required));
        org_setting_set('tidycal_bhp_required', isset($_POST['tidycal_bhp_required']) ? '1' : '0');
        flash_set('success', 'Ustawienia TidyCal zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/tidycal_settings.php');
        exit;
    }

    if ($action === 'send_notifier') {
        $agent = dirname(__DIR__) . '/cron/agents/szkolenia_notifier.php';
        exec(PHP_BINARY . ' ' . escapeshellarg($agent) . ' --force > /dev/null 2>&1 &');
        flash_set('success', 'Raport brakujących szkoleń został uruchomiony. Wyniki pojawią się w kolejce pocztowej.');
        header('Location: ' . APP_URL . '/admin/tidycal_settings.php');
        exit;
    }

    if ($action === 'test') {
        try {
            $api = new TidyCal();
            if (!$api->is_configured()) {
                throw new RuntimeException('Najpierw zapisz token API.');
            }
            $me    = $api->me();
            $slug  = (string)($me['url_slug'] ?? $me['username'] ?? $me['slug'] ?? '');
            $name  = (string)($me['name'] ?? $me['email'] ?? '');
            if ($slug !== '') org_setting_set('tidycal_account_slug', $slug);
            org_setting_set('tidycal_account_name', $name);

            $types = $api->bookingTypes();
            tidycal_set_types_cache($types);

            $test_result = ['ok' => true, 'account' => $name, 'count' => count($types)];
        } catch (\Throwable $e) {
            $test_result = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

$api_key      = org_setting('tidycal_api_key');
$enabled      = org_setting('tidycal_enabled') !== '0';
$intro        = org_setting('tidycal_intro');
$acc_name     = org_setting('tidycal_account_name');
$types        = tidycal_types_cache();
$exposed      = tidycal_exposed_ids();
$bookings     = tidycal_all_bookings(100);
$required_raw = org_setting('tidycal_required_types');
$required_ids = $required_raw ? array_map('intval', (array) json_decode($required_raw, true)) : [];
$bhp_required = org_setting('tidycal_bhp_required') !== '0';

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-calendar2-check text-primary" style="font-size:1.5rem"></i>
  <h4 class="mb-0">TidyCal — rezerwacja szkoleń</h4>
</div>
<?= flash_html() ?>

<?php if ($test_result !== null): ?>
  <?php if ($test_result['ok']): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i> Połączenie OK.
    Konto: <strong><?= h($test_result['account'] ?: '—') ?></strong>,
    pobrano typów szkoleń: <strong><?= (int)$test_result['count'] ?></strong>.
    Zaznacz poniżej, które udostępnić użytkownikom.
  </div>
  <?php else: ?>
  <div class="alert alert-danger">
    <i class="bi bi-x-circle"></i> Błąd połączenia: <?= h($test_result['error']) ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="save">

  <!-- Token + włączenie -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-key"></i> Połączenie z TidyCal</div>
    <div class="card-body">
      <div class="form-check form-switch mb-3">
        <input type="checkbox" class="form-check-input" id="tcEnabled" name="tidycal_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label" for="tcEnabled">Moduł rezerwacji szkoleń włączony</label>
      </div>
      <label class="form-label fw-semibold small">Token API (Personal Access Token)</label>
      <input type="password" name="tidycal_api_key" class="form-control" autocomplete="off"
             value="<?= h($api_key) ?>" placeholder="Wklej token z TidyCal → Settings → API">
      <div class="form-text">
        Token wygenerujesz w TidyCal: <em>Settings → API / For Developers</em>.
        <?php if ($acc_name): ?>Połączone konto: <strong><?= h($acc_name) ?></strong>.<?php endif; ?>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Zapisz</button>
        <button type="submit" form="tcTestForm" class="btn btn-outline-secondary" <?= $api_key ? '' : 'disabled' ?>>
          <i class="bi bi-wifi"></i> Testuj połączenie i pobierz typy
        </button>
      </div>
    </div>
  </div>

  <!-- Udostępnione typy szkoleń -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-list-check"></i> Typy szkoleń udostępnione użytkownikom</div>
    <div class="card-body">
      <?php if (!$types): ?>
        <div class="text-muted small">Brak pobranych typów szkoleń. Kliknij „Testuj połączenie i pobierz typy".</div>
      <?php else: ?>
        <?php foreach ($types as $t): ?>
        <div class="form-check">
          <input type="checkbox" class="form-check-input" id="exp<?= (int)$t['id'] ?>"
                 name="exposed[]" value="<?= (int)$t['id'] ?>"
                 <?= in_array((int)$t['id'], $exposed, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="exp<?= (int)$t['id'] ?>">
            <strong><?= h($t['title']) ?></strong>
            <?php if ((int)$t['duration'] > 0): ?><span class="text-muted small">· <?= (int)$t['duration'] ?> min</span><?php endif; ?>
            <?php if (!empty($t['public_url'])): ?>
              <a href="<?= h($t['public_url']) ?>" target="_blank" rel="noopener" class="small ms-1"><i class="bi bi-box-arrow-up-right"></i></a>
            <?php endif; ?>
          </label>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tekst wprowadzający -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-card-text"></i> Tekst na stronie rezerwacji</div>
    <div class="card-body">
      <textarea name="tidycal_intro" class="form-control" rows="3"
                placeholder="Opcjonalny tekst widoczny nad kreatorem (np. zasady, kontakt)…"><?= h($intro) ?></textarea>
    </div>
  </div>

  <!-- Wymagane szkolenia — konfiguracja notyfikatora -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Wymagane szkolenia (raport miesięczny)</div>
    <div class="card-body">
      <p class="small text-muted mb-3">
        Zaznaczone szkolenia są <strong>wymagane</strong> dla wszystkich aktywnych wolontariuszy.
        System wysyła opiekunom miesięczny raport (po 25. dniu miesiąca) z listą osób, które ich nie ukończyły.
      </p>

      <!-- BHP zawsze wymagane -->
      <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="bhpRequired" name="tidycal_bhp_required" value="1"
               <?= $bhp_required ? 'checked' : '' ?>>
        <label class="form-check-label" for="bhpRequired">
          <strong>Szkolenie BHP</strong>
          <span class="text-muted small">— pole <code>szkolenie_bhp</code> na umowie wolontariatu</span>
        </label>
      </div>

      <!-- Typy TidyCal -->
      <?php if (!$types): ?>
        <div class="text-muted small mt-2">Brak pobranych typów szkoleń. Kliknij „Testuj połączenie i pobierz typy".</div>
      <?php else: ?>
        <hr class="my-2">
        <p class="small fw-semibold mb-2">Typy szkoleń TidyCal:</p>
        <?php foreach ($types as $t): ?>
        <div class="form-check">
          <input type="checkbox" class="form-check-input" id="req<?= (int)$t['id'] ?>"
                 name="required[]" value="<?= (int)$t['id'] ?>"
                 <?= in_array((int)$t['id'], $required_ids, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="req<?= (int)$t['id'] ?>">
            <strong><?= h($t['title']) ?></strong>
            <?php if ((int)$t['duration'] > 0): ?><span class="text-muted small">· <?= (int)$t['duration'] ?> min</span><?php endif; ?>
          </label>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Zapisz ustawienia</button>
      </div>
    </div>
  </div>
</form>

<!-- Trigger ręczny — osobna akcja, nie należy do głównego formularza -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-send"></i> Wyślij raport brakujących szkoleń teraz</div>
  <div class="card-body">
    <p class="small text-muted mb-3">
      Uruchamia notyfikator natychmiast (tryb <code>--force</code>, niezależnie od daty i znacznika miesięcznego).
      Maile trafią do kolejki pocztowej i zostaną dostarczone w ciągu kilku minut.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="send_notifier">
      <button type="submit" class="btn btn-outline-primary">
        <i class="bi bi-arrow-repeat"></i> Wyślij raport teraz
      </button>
    </form>
  </div>
</div>

<!-- Osobny formularz dla akcji test (omija required pól) -->
<form method="post" id="tcTestForm" class="d-none">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="test">
</form>

<!-- Ostatnie rezerwacje -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-calendar-week"></i> Ostatnie rezerwacje (<?= count($bookings) ?>)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <th class="small">Użytkownik</th><th class="small">Szkolenie</th><th class="small">Termin</th>
        <th class="small">Status</th><th class="small">Źródło</th>
      </tr></thead>
      <tbody>
        <?php if (!$bookings): ?>
        <tr><td colspan="5" class="text-muted small text-center py-3">Brak rezerwacji.</td></tr>
        <?php else: foreach ($bookings as $b): ?>
        <tr>
          <td class="small"><?= h($b['user_name'] ?: $b['name']) ?><div class="text-muted" style="font-size:.72rem"><?= h($b['email']) ?></div></td>
          <td class="small fw-semibold"><?= h($b['booking_type_title']) ?></td>
          <td class="small"><?= h(tidycal_fmt_dt($b['starts_at'], $b['timezone'] ?? 'Europe/Warsaw')) ?></td>
          <td><span class="badge bg-success-subtle text-success"><?= h($b['status']) ?></span></td>
          <td class="small text-muted"><?= h($b['source']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
