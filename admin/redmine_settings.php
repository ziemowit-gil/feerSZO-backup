<?php
/**
 * admin/redmine_settings.php — konfiguracja integracji Helpdesk → Redmine (REST API).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php'; // HD_CATEGORIES do mapowania

require_role('admin');
$PAGE_TITLE = 'Ustawienia Redmine';

$KEYS = [
    'redmine_enabled', 'redmine_url', 'redmine_api_key',
    'redmine_default_project_id', 'redmine_default_tracker_id',
    'helpdesk_frontend_only', 'redmine_minihelpdesk_enabled', 'redmine_webhook_secret',
    'redmine_oauth_client_id', 'redmine_oauth_client_secret', 'redmine_oauth_scope',
];
$settings = [];
foreach ($KEYS as $k) { $settings[$k] = org_setting($k); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();
    $save = [
        'redmine_enabled'            => isset($_POST['redmine_enabled']) ? '1' : '0',
        'redmine_url'                => rtrim(trim($_POST['redmine_url'] ?? ''), '/'),
        'redmine_default_project_id' => trim($_POST['redmine_default_project_id'] ?? ''),
        'redmine_default_tracker_id' => trim($_POST['redmine_default_tracker_id'] ?? ''),
    ];
    $save['helpdesk_frontend_only']       = isset($_POST['helpdesk_frontend_only']) ? '1' : '0';
    $save['redmine_minihelpdesk_enabled'] = isset($_POST['redmine_minihelpdesk_enabled']) ? '1' : '0';
    $save['redmine_webhook_secret']       = trim($_POST['redmine_webhook_secret'] ?? '');
    $save['redmine_oauth_client_id']      = trim($_POST['redmine_oauth_client_id'] ?? '');
    $save['redmine_oauth_scope']          = trim($_POST['redmine_oauth_scope'] ?? '');
    $osecret = trim($_POST['redmine_oauth_client_secret'] ?? '');
    if ($osecret !== '') $save['redmine_oauth_client_secret'] = $osecret;
    // Klucz API — zachowaj stary, gdy pole puste.
    $key = trim($_POST['redmine_api_key'] ?? '');
    if ($key !== '') $save['redmine_api_key'] = $key;

    foreach ($save as $k => $v) { org_setting_set($k, $v); }

    // Mapa kategoria → tracker (tylko wskazane wartości).
    $catMap = [];
    foreach ((array)($_POST['cat_tracker'] ?? []) as $ck => $tid) {
        $tid = (int)$tid;
        if ($tid > 0 && isset(HD_CATEGORIES[$ck])) $catMap[$ck] = $tid;
    }
    org_setting_set('redmine_category_trackers', $catMap ? json_encode($catMap) : '');

    // Mapa priorytet SZO → priority_id.
    $prioMap = [];
    foreach ((array)($_POST['prio_map'] ?? []) as $pk => $pid) {
        $pid = (int)$pid;
        if ($pid > 0 && isset(HD_PRIORITIES[$pk])) $prioMap[$pk] = $pid;
    }
    org_setting_set('redmine_priority_map', $prioMap ? json_encode($prioMap) : '');

    // Pola niestandardowe: pary (cf_id, cf_source).
    $cfs = [];
    $cf_ids  = (array)($_POST['cf_id'] ?? []);
    $cf_srcs = (array)($_POST['cf_source'] ?? []);
    foreach ($cf_ids as $i => $cid) {
        $cid = (int)$cid;
        $src = (string)($cf_srcs[$i] ?? '');
        if ($cid > 0 && $src !== '') $cfs[] = ['id' => $cid, 'source' => $src];
    }
    org_setting_set('redmine_custom_fields', $cfs ? json_encode($cfs) : '');

    $settings = array_merge($settings, $save);
    flash_set('success', 'Ustawienia Redmine zapisane.');
    header('Location: redmine_settings.php'); exit;
}

$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_test'])) {
    csrf_check();
    $test_result = redmine_test();
}

$configured = $settings['redmine_url'] !== '' && $settings['redmine_api_key'] !== '';
$lib_ok     = class_exists(\Redmine\Client\NativeCurlClient::class);
$trackers   = $configured ? redmine_trackers() : [];   // [id=>nazwa]
$cat_map    = redmine_category_tracker_map();
$priorities = $configured ? redmine_priorities() : []; // [id=>nazwa]
$prio_map   = redmine_priority_map();
$cf_defs    = $configured ? redmine_custom_field_defs() : []; // [id=>nazwa]
$cf_map     = redmine_custom_fields_map();
$CF_SOURCES = [
    'number'          => 'Nr zgłoszenia SZO',
    'title'           => 'Temat',
    'requester_name'  => 'Zgłaszający — imię i nazwisko',
    'requester_email' => 'Zgłaszający — e-mail',
    'category'        => 'Kategoria',
];

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Redmine</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-kanban me-2 text-primary"></i>Integracja Redmine (Helpdesk)</h4>
  <?php if ($settings['redmine_enabled'] === '1' && $configured): ?>
    <span class="badge bg-success">Aktywna</span>
  <?php elseif ($configured): ?>
    <span class="badge bg-warning text-dark">Skonfigurowana — nieaktywna</span>
  <?php else: ?>
    <span class="badge bg-secondary">Nieskonfigurowana</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$lib_ok): ?>
<div class="alert alert-danger">
  <i class="bi bi-exclamation-triangle"></i>
  Biblioteka <code>kbsali/redmine-api</code> nie jest załadowana — uruchom <code>composer install</code> na serwerze.
</div>
<?php endif; ?>

<?php if ($test_result !== null): ?>
<div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> mb-3">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i>
  <?= $test_result['ok']
        ? 'Połączenie z Redmine działa (zalogowano jako: ' . h($test_result['user'] ?? '—') . ').'
        : 'Błąd: ' . h($test_result['error'] ?? 'nieznany') ?>
</div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-7">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Konfiguracja API</div>
<div class="card-body">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="redmine_enabled"
           id="redmine_enabled" value="1" <?= $settings['redmine_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="redmine_enabled">
      Wysyłaj nowe zgłoszenia Helpdesk do Redmine
    </label>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="helpdesk_frontend_only"
           id="helpdesk_frontend_only" value="1" <?= $settings['helpdesk_frontend_only'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="helpdesk_frontend_only">
      Helpdesk w SZO tylko dla zgłaszających — obsługa operatorska w Redmine
      <span class="d-block text-muted small">Ukrywa konsolę operatora w SZO; użytkownicy tylko zgłaszają i śledzą własne zgłoszenia.</span>
    </label>
  </div>

  <hr>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Adres Redmine <span class="text-danger">*</span></label>
    <input type="text" name="redmine_url" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['redmine_url']) ?>" placeholder="https://feer.usermd.net">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Klucz API <span class="text-danger">*</span></label>
    <input type="password" name="redmine_api_key" class="form-control form-control-sm font-monospace"
           autocomplete="new-password"
           placeholder="<?= $settings['redmine_api_key'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Klucz API z Redmine' ?>">
    <div class="form-text">Redmine → <em>Moje konto</em> → panel po prawej „Klucz API" → Pokaż.
      Wymaga włączonego web-serwisu REST (Administracja → Ustawienia → API).</div>
  </div>

  <div class="row g-2">
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Projekt docelowy (ID lub identyfikator) <span class="text-danger">*</span></label>
      <input type="text" name="redmine_default_project_id" class="form-control form-control-sm"
             value="<?= h($settings['redmine_default_project_id']) ?>" placeholder="np. 1 lub helpdesk">
    </div>
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Tracker (ID, opcjonalnie)</label>
      <input type="number" name="redmine_default_tracker_id" class="form-control form-control-sm"
             value="<?= h($settings['redmine_default_tracker_id']) ?>" placeholder="np. 3 (Support)">
      <div class="form-text">Typ zagadnienia z Administracja → Typy zagadnień. Puste = domyślny.</div>
    </div>
  </div>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-window me-1"></i>Mini-helpdesk (publiczny formularz)</div>
  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" role="switch" name="redmine_minihelpdesk_enabled"
           id="redmine_minihelpdesk_enabled" value="1" <?= $settings['redmine_minihelpdesk_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="redmine_minihelpdesk_enabled">
      Włącz publiczny formularz zgłoszeń (bez logowania) tworzący issue w Redmine
    </label>
  </div>
  <div class="form-text mb-3">
    Adres do podlinkowania/osadzenia:
    <code><?= h(rtrim(APP_URL, '/')) ?>/helpdesk/mini.php</code>.
    Można wstawić w Redmine (menu/nagłówek) lub osadzić na stronie w <code>&lt;iframe&gt;</code>.
    Chroniony honeypotem i limitem na sesję — dla pełnej publiczności rozważ Cloudflare.
  </div>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-lightning-charge me-1"></i>Plugin Redmine (webhook — sync natychmiastowy)</div>
  <div class="mb-2">
    <label class="form-label small mb-1">Sekret webhooka (HMAC)</label>
    <input type="text" name="redmine_webhook_secret" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['redmine_webhook_secret']) ?>" placeholder="długi losowy ciąg">
  </div>
  <div class="form-text mb-3">
    Zainstaluj wtyczkę <code>redmine_szo_sync</code> w Redmine i w jej konfiguracji podaj:<br>
    URL: <code><?= h(rtrim(APP_URL, '/')) ?>/api/redmine_webhook.php</code> oraz ten sam sekret.
    Bez wtyczki sync i tak działa przez cron (co 10 min) — webhook tylko przyspiesza.
  </div>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-person-badge me-1"></i>OAuth per użytkownik (opcjonalnie)</div>
  <div class="form-text mb-2">
    Pozwala pracownikom łączyć swoje konto SZO z kontem Redmine — ich zgłoszenia i
    komentarze są wtedy podpisane ich nazwiskiem. W Redmine: <em>Administracja → Applications
    → New Application</em>, Redirect URI:
    <code><?= h(rtrim(APP_URL, '/')) ?>/auth/redmine_callback.php</code>.
  </div>
  <div class="row g-2 mb-3">
    <div class="col-sm-6">
      <label class="form-label small">OAuth Client ID</label>
      <input type="text" name="redmine_oauth_client_id" class="form-control form-control-sm font-monospace"
             value="<?= h($settings['redmine_oauth_client_id']) ?>">
    </div>
    <div class="col-sm-6">
      <label class="form-label small">OAuth Client Secret</label>
      <input type="password" name="redmine_oauth_client_secret" class="form-control form-control-sm font-monospace"
             autocomplete="new-password"
             placeholder="<?= $settings['redmine_oauth_client_secret'] ? '(zapisany — zostaw puste)' : 'Client Secret' ?>">
    </div>
  </div>
  <div class="mb-2">
    <label class="form-label small">Zakresy OAuth (scope)</label>
    <input type="text" name="redmine_oauth_scope" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['redmine_oauth_scope']) ?>" placeholder="view_issues add_issues add_issue_notes">
    <div class="form-text">Uprawnienia żądane od Redmine. <strong>Bez <code>view_issues</code> API zwraca 403.</strong>
      Te same zakresy zaznacz na aplikacji OAuth w Redmine. Po zmianie zakresów użytkownicy muszą
      <strong>rozłączyć i połączyć konto ponownie</strong> (token zapamiętuje stare zakresy).</div>
  </div>
  <div class="form-text mb-3">Użytkownicy łączą konto na stronie <code><?= h(rtrim(APP_URL, '/')) ?>/auth/redmine_account.php</code>.</div>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-diagram-2 me-1"></i>Mapowanie kategorii → tracker Redmine</div>
  <?php if (!$trackers): ?>
    <div class="form-text mb-3">Zapisz URL/klucz API i użyj „Testuj połączenie", aby wczytać listę trackerów z Redmine.</div>
  <?php else: ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th class="small">Kategoria zgłoszenia</th><th class="small" style="max-width:220px">Tracker Redmine</th></tr></thead>
        <tbody>
        <?php foreach (HD_CATEGORIES as $ck => $cl): if ($ck === 'bug_report') continue; ?>
          <tr>
            <td class="small"><?= h($cl) ?> <span class="text-muted">(<?= h($ck) ?>)</span></td>
            <td>
              <select name="cat_tracker[<?= h($ck) ?>]" class="form-select form-select-sm">
                <option value="0">— domyślny —</option>
                <?php foreach ($trackers as $tid => $tname): ?>
                <option value="<?= (int)$tid ?>" <?= (int)($cat_map[$ck] ?? 0) === (int)$tid ? 'selected' : '' ?>><?= h($tname) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-text mb-3">Puste = użyj domyślnego trackera. Dotyczy zgłoszeń z Helpdesku SZO i mini-helpdesku.</div>
  <?php endif; ?>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-flag me-1"></i>Mapowanie priorytetów → Redmine</div>
  <?php if (!$priorities): ?>
    <div class="form-text mb-3">Użyj „Testuj połączenie", aby wczytać priorytety z Redmine.</div>
  <?php else: ?>
    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
      <thead><tr><th class="small">Priorytet SZO</th><th class="small" style="max-width:220px">Priorytet Redmine</th></tr></thead>
      <tbody>
      <?php foreach (HD_PRIORITIES as $pk => $pd): ?>
        <tr>
          <td class="small"><?= h($pd['label']) ?></td>
          <td>
            <select name="prio_map[<?= h($pk) ?>]" class="form-select form-select-sm">
              <option value="0">— domyślny —</option>
              <?php foreach ($priorities as $pid => $pname): ?>
              <option value="<?= (int)$pid ?>" <?= (int)($prio_map[$pk] ?? 0) === (int)$pid ? 'selected' : '' ?>><?= h($pname) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <hr>
  <div class="fw-semibold small mb-2"><i class="bi bi-input-cursor-text me-1"></i>Pola niestandardowe Redmine</div>
  <div class="form-text mb-2">Wypełnij wybrane pola niestandardowe issue danymi ze zgłoszenia SZO.</div>
  <?php for ($r = 0; $r < 3; $r++): $cur = $cf_map[$r] ?? ['id' => 0, 'source' => '']; ?>
    <div class="row g-2 mb-2">
      <div class="col-sm-6">
        <?php if ($cf_defs): ?>
          <select name="cf_id[]" class="form-select form-select-sm">
            <option value="0">— pole Redmine —</option>
            <?php foreach ($cf_defs as $cid => $cname): ?>
            <option value="<?= (int)$cid ?>" <?= (int)$cur['id'] === (int)$cid ? 'selected' : '' ?>><?= h($cname) ?> (#<?= (int)$cid ?>)</option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="number" name="cf_id[]" class="form-control form-control-sm" value="<?= $cur['id'] ?: '' ?>" placeholder="ID pola Redmine">
        <?php endif; ?>
      </div>
      <div class="col-sm-6">
        <select name="cf_source[]" class="form-select form-select-sm">
          <option value="">— źródło ze zgłoszenia —</option>
          <?php foreach ($CF_SOURCES as $sk => $sl): ?>
          <option value="<?= h($sk) ?>" <?= ($cur['source'] ?? '') === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php endfor; ?>

  <div class="d-flex gap-2 mt-3">
    <button type="submit" name="_save" class="btn btn-primary btn-sm"><i class="bi bi-floppy"></i> Zapisz</button>
    <button type="submit" name="_test" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug"></i> Testuj połączenie</button>
  </div>
</form>
</div>
</div>
</div>

<div class="col-lg-5">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Jak to działa</div>
<div class="card-body small">
  <ul class="mb-2 ps-3">
    <li class="mb-2"><strong>Do Redmine:</strong> nowe zgłoszenie w Helpdesku SZO tworzy
      zagadnienie (issue) w Redmine, a jego numer zapisuje się przy zgłoszeniu.</li>
    <li class="mb-2"><strong>Z Redmine (co 10 min, cron):</strong> nowe notatki wracają jako
      wiadomości zgłoszenia, a zamknięcie issue ustawia status „Rozwiązane".</li>
    <li class="mb-2">Nie potrzebujesz OAuth (sekcja „Applications") — wystarczy <strong>klucz API</strong>.</li>
    <li class="mb-2">W Redmine: <em>Administracja → Ustawienia → API</em> → włącz „REST web service";
      klucz API weźmiesz z <em>Moje konto</em>.</li>
    <li class="mb-2">Wskaż projekt docelowy — jego identyfikator widać w adresie
      <code>/projects/&lt;identyfikator&gt;</code>.</li>
  </ul>
  <div class="text-muted">Synchronizacja jest <strong>dwukierunkowa</strong>. Wewnętrzny moduł Helpdesk działa dalej.</div>
</div>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
