<?php
/**
 * admin/outlook_sync.php — Panel synchronizacji Outlook / MS365 → CRM
 *
 * Pozwala:
 *  - skonfigurować użytkownika M365 i kalendarz do synch
 *  - uruchomić ręczną synchronizację (AJAX)
 *  - podejrzeć log synchronizacji
 *  - zresetować delta-link (wymuś pełny re-sync)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/outlook_sync.php';

auth_start();
require_role('admin');
crm_migrate();

// ── Zapisz ustawienia ─────────────────────────────────────────────────────────

$save_ok  = null;
$save_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_save'])) {
    $fields = [
        'm365_sync_enabled'  => isset($_POST['m365_sync_enabled'])  ? '1' : '0',
        'm365_sync_scope'    => ($_POST['m365_sync_scope'] ?? 'all') === 'user' ? 'user' : 'all',
        'm365_sync_user_id'  => trim($_POST['m365_sync_user_id']  ?? ''),
        'm365_sync_calendar_id' => trim($_POST['m365_sync_calendar_id'] ?? ''),
        'm365_sync_contacts' => isset($_POST['m365_sync_contacts']) ? '1' : '0',
        'm365_sync_calendar' => isset($_POST['m365_sync_calendar']) ? '1' : '0',
        'm365_sync_messages' => isset($_POST['m365_sync_messages']) ? '1' : '0',
    ];
    foreach ($fields as $k => $v) {
        m365_save_setting($k, $v);
    }
    $save_ok  = true;
    $save_msg = 'Ustawienia zapisane.';
}

// ── Pobierz ustawienia ────────────────────────────────────────────────────────

$sync_user_id     = crm_setting('m365_sync_user_id')  ?: crm_setting('m365_sender_user_id') ?: '';
$sync_calendar_id = crm_setting('m365_sync_calendar_id') ?: '';
$sync_enabled     = OutlookSync::integration_enabled(); // przełącznik główny
$sync_scope       = OutlookSync::sync_scope();           // 'all' | 'user'
$sync_contacts    = crm_setting('m365_sync_contacts')  !== '0';
$sync_calendar    = crm_setting('m365_sync_calendar')  !== '0';
$sync_messages    = crm_setting('m365_sync_messages')  === '1'; // opt-in

// ── Status M365 ───────────────────────────────────────────────────────────────

$m365_ok      = (bool)(crm_setting('m365_tenant_id') && crm_setting('m365_graph_client_id') && crm_setting('m365_graph_client_secret'));
$sync_token   = crm_setting('crm_sync_token');
$cron_cmd     = "php " . dirname(__DIR__) . "/bin/outlook_sync.php";
$api_url      = APP_URL . '/crm/api/outlook_sync.php';

// ── Log synchronizacji ────────────────────────────────────────────────────────

$recent_logs = [];
try {
    $sync_obj    = new OutlookSync($sync_user_id ?: null);
    $recent_logs = $sync_obj->recent_logs(15);
} catch (\Throwable $e) {}

// ── HTML ──────────────────────────────────────────────────────────────────────

$PAGE_TITLE = 'Outlook Sync — MS365 → CRM';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4">
  <div class="row g-4">

    <!-- Lewa kolumna: konfiguracja -->
    <div class="col-lg-6">

      <!-- Karta: M365 status -->
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-cloud-check-fill text-primary"></i>
          <strong>Status połączenia MS365</strong>
        </div>
        <div class="card-body">
          <?php if ($m365_ok): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-check-circle-fill"></i>
              <span>Dane MS365 skonfigurowane — tenant ID, client ID i secret są ustawione.</span>
            </div>
          <?php else: ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-exclamation-triangle-fill"></i>
              <span>Brak pełnej konfiguracji MS365.
                <a href="<?= APP_URL ?>/admin/m365_settings.php">Konfiguracja M365 →</a>
              </span>
            </div>
          <?php endif; ?>
          <dl class="row mb-0 small">
            <dt class="col-5">Uprawnienia aplikacji (Azure):</dt>
            <dd class="col-7">
              <code>Contacts.Read</code>, <code>Calendars.Read</code>,
              <code>Mail.Read</code>, <code>User.Read.All</code>
              <span class="text-muted ms-1">(Application)</span>
            </dd>
            <dt class="col-5">Tenant ID:</dt>
            <dd class="col-7"><code><?= h(crm_setting('m365_tenant_id') ?: '—') ?></code></dd>
          </dl>
        </div>
      </div>

      <!-- Karta: Ustawienia sync -->
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-gear-fill text-secondary"></i>
          <strong>Ustawienia synchronizacji</strong>
        </div>
        <div class="card-body">
          <?php if ($save_ok !== null): ?>
            <div class="alert alert-<?= $save_ok ? 'success' : 'danger' ?> py-2">
              <?= h($save_msg) ?>
            </div>
          <?php endif; ?>

          <form method="post" id="sync-settings-form">
            <input type="hidden" name="_save" value="1">

            <!-- Przełącznik główny -->
            <div class="form-check form-switch mb-1" style="padding-left:3em">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="chk-enabled" name="m365_sync_enabled" value="1"
                     style="width:2.6em;height:1.4em"
                     <?= $sync_enabled ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold fs-6 ms-2" for="chk-enabled">
                <i class="bi bi-toggles me-1 text-primary"></i>Włącz integrację Outlook
              </label>
            </div>
            <p class="form-text mt-0 mb-3">
              Po włączeniu synchronizowane są kontakty, kalendarz i maile. Szczegóły dostosujesz poniżej.
            </p>

            <!-- Szczegóły konfiguracji (widoczne gdy włączone) -->
            <div id="sync-detail" class="<?= $sync_enabled ? '' : 'd-none' ?>">

            <div class="mb-3">
              <label class="form-label fw-semibold mb-2">
                <i class="bi bi-people me-1"></i>Kogo synchronizować
              </label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="m365_sync_scope" id="scope-all"
                       value="all" <?= $sync_scope === 'all' ? 'checked' : '' ?>>
                <label class="form-check-label" for="scope-all">
                  <strong>Wszyscy użytkownicy</strong>
                  <span class="d-block small text-muted">
                    Skrzynki, kalendarze i kontakty wszystkich użytkowników M365.
                    Wymaga uprawnienia aplikacji <code>User.Read.All</code>.
                  </span>
                </label>
              </div>
              <div class="form-check mt-1">
                <input class="form-check-input" type="radio" name="m365_sync_scope" id="scope-user"
                       value="user" <?= $sync_scope === 'user' ? 'checked' : '' ?>>
                <label class="form-check-label" for="scope-user">
                  <strong>Konkretny użytkownik</strong>
                  <span class="d-block small text-muted">Tylko skrzynka/kalendarz/kontakty wskazanego użytkownika.</span>
                </label>
              </div>
            </div>

            <div class="mb-3 <?= $sync_scope === 'all' ? 'd-none' : '' ?>" id="single-user-field">
              <label class="form-label fw-semibold">
                <i class="bi bi-person-badge me-1"></i>Użytkownik M365 do synchronizacji
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope-at"></i></span>
                <input type="text" name="m365_sync_user_id"
                       class="form-control font-monospace"
                       value="<?= h($sync_user_id) ?>"
                       placeholder="np. jan.kowalski@feer.org.pl lub GUID">
              </div>
              <div class="form-text">
                UPN (adres e-mail) lub Azure AD Object ID użytkownika.
                Wymagane uprawnienie aplikacji: <code>Contacts.Read</code>, <code>Calendars.Read</code>.
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">
                <i class="bi bi-calendar3 me-1"></i>ID kalendarza
                <span class="text-muted fw-normal">(puste = domyślny)</span>
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                <input type="text" name="m365_sync_calendar_id"
                       id="calendar-id-input"
                       class="form-control font-monospace"
                       value="<?= h($sync_calendar_id) ?>"
                       placeholder="Zostaw puste dla domyślnego kalendarza">
                <button type="button" class="btn btn-outline-secondary" id="btn-load-calendars">
                  <i class="bi bi-arrow-clockwise"></i> Wczytaj
                </button>
              </div>
              <div id="calendars-list" class="mt-2"></div>
            </div>

            <div class="mb-3 border rounded p-3 bg-light">
              <div class="form-label fw-semibold mb-2">Co synchronizować:</div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-contacts"
                       name="m365_sync_contacts" value="1"
                       <?= $sync_contacts ? 'checked' : '' ?>>
                <label class="form-check-label" for="chk-contacts">
                  <i class="bi bi-people-fill me-1 text-primary"></i>
                  Kontakty (Outlook → crm_contacts)
                </label>
              </div>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="chk-calendar"
                       name="m365_sync_calendar" value="1"
                       <?= $sync_calendar ? 'checked' : '' ?>>
                <label class="form-check-label" for="chk-calendar">
                  <i class="bi bi-calendar-event-fill me-1 text-success"></i>
                  Zdarzenia kalendarza (Outlook → crm_events)
                </label>
              </div>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="chk-messages"
                       name="m365_sync_messages" value="1"
                       <?= $sync_messages ? 'checked' : '' ?>>
                <label class="form-check-label" for="chk-messages">
                  <i class="bi bi-envelope-fill me-1 text-warning"></i>
                  Maile (Outlook → historia komunikacji kontaktu)
                  <span class="d-block small text-muted">
                    Skrzynka odbiorcza i wysłane; dopasowanie po adresie e-mail. Wymaga uprawnienia <code>Mail.Read</code>.
                  </span>
                </label>
              </div>
            </div>

            </div><!-- /#sync-detail -->

            <button type="submit" class="btn btn-primary">
              <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
            </button>
          </form>
        </div>
      </div>

      <!-- Karta: Cron / API -->
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-clock-history text-secondary"></i>
          <strong>Automatyczna synchronizacja (cron)</strong>
        </div>
        <div class="card-body">
          <p class="small text-muted mb-2">
            Dodaj wpis do crontab, aby synchronizacja odbywała się automatycznie co godzinę:
          </p>
          <div class="bg-dark text-light rounded p-2 font-monospace small mb-3">
            0 * * * * <?= h($cron_cmd) ?>
          </div>
          <p class="small text-muted mb-2">
            Możesz też wywołać endpoint API bezpośrednio (np. z zewnętrznego schedulera):
          </p>
          <div class="bg-dark text-light rounded p-2 font-monospace small mb-2">
            curl -s -X POST "<?= h($api_url) ?>" \<br>
            &nbsp;&nbsp;-H "Authorization: Bearer <span class="text-warning"><?= h(mb_substr($sync_token ?: '...', 0, 12)) ?>…</span>" \<br>
            &nbsp;&nbsp;-d "action=sync_all"
          </div>
          <div class="small text-muted">
            Token API znajdziesz w tabeli <code>settings</code> pod kluczem <code>crm_sync_token</code>.
          </div>
        </div>
      </div>

    </div><!-- /lewa kolumna -->

    <!-- Prawa kolumna: sync + log -->
    <div class="col-lg-6">

      <!-- Karta: Ręczna synchronizacja -->
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-arrow-repeat text-success"></i>
          <strong>Ręczna synchronizacja</strong>
        </div>
        <div class="card-body">

          <div class="d-flex flex-wrap gap-2 mb-3">
            <button class="btn btn-success" id="btn-sync-all">
              <i class="bi bi-arrow-repeat me-1"></i>Synchronizuj wszystko
            </button>
            <button class="btn btn-outline-primary" id="btn-sync-contacts">
              <i class="bi bi-people me-1"></i>Tylko kontakty
            </button>
            <button class="btn btn-outline-success" id="btn-sync-calendar">
              <i class="bi bi-calendar3 me-1"></i>Tylko kalendarz
            </button>
            <button class="btn btn-outline-warning" id="btn-sync-messages">
              <i class="bi bi-envelope me-1"></i>Tylko maile
            </button>
          </div>

          <!-- Wynik -->
          <div id="sync-result" class="d-none">
            <div id="sync-alert" class="alert d-flex gap-2 align-items-start">
              <i id="sync-icon" class="bi fs-5 flex-shrink-0"></i>
              <div>
                <div id="sync-message" class="fw-semibold"></div>
                <ul id="sync-errors" class="mb-0 mt-1 small"></ul>
              </div>
            </div>
            <div id="sync-details" class="row g-2 small"></div>
          </div>

          <!-- Loader -->
          <div id="sync-loading" class="d-none text-center py-3">
            <div class="spinner-border text-success" role="status"></div>
            <div class="mt-2 text-muted">Trwa synchronizacja…</div>
          </div>

          <hr class="my-3">

          <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-danger" id="btn-reset-delta">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset delta-link
            </button>
            <span class="text-muted small">
              Usuwa zapisany punkt kontrolny — następna synchronizacja pobierze wszystkie dane od nowa.
            </span>
          </div>

        </div>
      </div>

      <!-- Karta: Add-in Outlook -->
      <?php $addin_manifest = APP_URL . '/outlook-addin/manifest.php'; $addin_token = crm_setting('crm_sync_token'); ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-plugin text-warning"></i>
          <strong>Dodatek (add-in) do Outlooka</strong>
        </div>
        <div class="card-body">
          <p class="small text-muted mb-2">
            Panel boczny w Outlooku pokazujący powiązany kontakt CRM i pozwalający przypiąć
            otwarty mail do kartoteki jednym kliknięciem.
          </p>

          <label class="form-label small fw-semibold mb-1">Adres URL manifestu</label>
          <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control font-monospace" id="addin-manifest"
                   value="<?= h($addin_manifest) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('addin-manifest').value)">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>

          <label class="form-label small fw-semibold mb-1">Token API (wkleić w panelu add-inu przy pierwszym użyciu)</label>
          <div class="input-group input-group-sm mb-3">
            <input type="text" class="form-control font-monospace" id="addin-token"
                   value="<?= h($addin_token) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('addin-token').value)">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>

          <div class="small text-muted">
            <strong>Instalacja (sideload):</strong>
            <ol class="mb-0 ps-3">
              <li>Outlook → <em>Pobierz dodatki</em> → <em>Moje dodatki</em> → <em>Dodaj dodatek niestandardowy</em> → <em>Z adresu URL</em>.</li>
              <li>Wklej powyższy adres URL manifestu i potwierdź.</li>
              <li>Otwórz dowolny mail → wstążka → przycisk <em>Kontakt CRM</em>.</li>
              <li>Przy pierwszym uruchomieniu wklej token API (powyżej).</li>
            </ol>
          </div>
        </div>
      </div>

      <!-- Karta: Log synchronizacji -->
      <div class="card shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-journal-text me-2 text-secondary"></i><strong>Log synchronizacji</strong></span>
          <button class="btn btn-sm btn-outline-secondary" id="btn-refresh-log">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
        <div class="card-body p-0">
          <div id="sync-log-table">
            <?php if (empty($recent_logs)): ?>
              <p class="text-muted text-center py-4 mb-0">Brak wpisów — synchronizacja nie była jeszcze uruchamiana.</p>
            <?php else: ?>
              <?= render_sync_log($recent_logs) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /prawa kolumna -->
  </div><!-- /row -->
</div><!-- /container -->

<?php
// ── Pomocnicza funkcja renderowania tabeli logu ───────────────────────────────
function render_sync_log(array $logs): string {
    $html  = '<div class="table-responsive">';
    $html .= '<table class="table table-sm table-hover mb-0">';
    $html .= '<thead class="table-light"><tr>';
    $html .= '<th>Czas</th><th>Źródło</th><th>Rekordy</th><th>Szczegóły</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($logs as $log) {
        $details = json_decode($log['details'] ?? '{}', true) ?? [];
        $source_label = match($log['source'] ?? '') {
            'outlook_contacts' => '<span class="badge bg-primary">Kontakty</span>',
            'outlook_calendar' => '<span class="badge bg-success">Kalendarz</span>',
            'outlook_messages' => '<span class="badge bg-warning text-dark">Maile</span>',
            default            => '<span class="badge bg-secondary">' . h($log['source'] ?? '') . '</span>',
        };
        $errors = $details['errors'] ?? [];
        $err_html = $errors
            ? ' <span class="badge bg-danger ms-1" title="' . h(implode('; ', $errors)) . '">' . count($errors) . ' błędów</span>'
            : '';
        $detail_html = sprintf(
            '<span class="text-success">+%d</span> <span class="text-primary">~%d</span> <span class="text-danger">-%d</span>%s',
            (int)($details['created'] ?? 0),
            (int)($details['updated'] ?? 0),
            (int)($details['removed'] ?? 0),
            $err_html
        );
        $html .= '<tr>';
        $html .= '<td class="text-nowrap small">' . h($log['synced_at'] ?? '') . '</td>';
        $html .= '<td>' . $source_label . '</td>';
        $html .= '<td class="small">' . (int)$log['records_updated'] . '</td>';
        $html .= '<td class="small">' . $detail_html . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}
?>

<script>
/* ── Przełącznik główny: pokaż/ukryj szczegóły + auto-zaznacz opcje ───────────── */
(function () {
  'use strict';
  var sw     = document.getElementById('chk-enabled');
  var detail = document.getElementById('sync-detail');
  if (!sw || !detail) return;
  sw.addEventListener('change', function () {
    detail.classList.toggle('d-none', !sw.checked);
    if (sw.checked) {
      // Włączenie integracji = synchronizuj wszystko (admin może odznaczyć).
      ['chk-contacts', 'chk-calendar', 'chk-messages'].forEach(function (id) {
        var c = document.getElementById(id);
        if (c) c.checked = true;
      });
    }
  });

  // Zakres: pole „konkretny użytkownik" widoczne tylko dla trybu 'user'.
  var single = document.getElementById('single-user-field');
  function syncScopeUI() {
    var userMode = document.getElementById('scope-user');
    if (single && userMode) single.classList.toggle('d-none', !userMode.checked);
  }
  ['scope-all', 'scope-user'].forEach(function (id) {
    var r = document.getElementById(id);
    if (r) r.addEventListener('change', syncScopeUI);
  });
})();
</script>

<script>
(function () {
  'use strict';

  const API = '<?= APP_URL ?>/crm/api/outlook_sync.php';

  // ── Helpers ────────────────────────────────────────────────────────────────

  async function callSync(action, extra = {}) {
    const body = new URLSearchParams({ action, ...extra });
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    return r.json();
  }

  function showLoading(on) {
    document.getElementById('sync-loading').classList.toggle('d-none', !on);
    document.getElementById('sync-result').classList.toggle('d-none', on);
  }

  function showResult(json) {
    const wrap   = document.getElementById('sync-result');
    const alert  = document.getElementById('sync-alert');
    const icon   = document.getElementById('sync-icon');
    const msg    = document.getElementById('sync-message');
    const errUl  = document.getElementById('sync-errors');
    const detail = document.getElementById('sync-details');

    wrap.classList.remove('d-none');
    const ok = json.ok ?? false;
    alert.className = 'alert d-flex gap-2 align-items-start alert-' + (ok ? 'success' : 'danger');
    icon.className  = 'bi fs-5 flex-shrink-0 bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill');
    msg.textContent = json.message ?? '';

    errUl.innerHTML = '';
    const allErrors = [
      ...(json.data?.contacts?.errors ?? []),
      ...(json.data?.calendar?.errors ?? []),
    ];
    for (const e of allErrors) {
      const li = document.createElement('li');
      li.textContent = e;
      errUl.appendChild(li);
    }

    // Statystyki
    detail.innerHTML = '';
    const sections = [];
    if (json.data?.contacts) sections.push({ label: 'Kontakty', d: json.data.contacts });
    if (json.data?.calendar) sections.push({ label: 'Kalendarz', d: json.data.calendar });

    for (const s of sections) {
      detail.innerHTML += `
        <div class="col-6">
          <div class="border rounded p-2 bg-light">
            <div class="fw-semibold mb-1">${s.label}</div>
            <span class="text-success me-2">+${s.d.created ?? 0} nowych</span>
            <span class="text-primary me-2">~${s.d.updated ?? 0} aktualizacji</span>
            <span class="text-danger">-${s.d.removed ?? 0} usuniętych</span>
          </div>
        </div>`;
    }
  }

  // ── Sync buttons ───────────────────────────────────────────────────────────

  async function doSync(action) {
    showLoading(true);
    try {
      const json = await callSync(action);
      showLoading(false);
      showResult(json);
      await refreshLog();
    } catch (e) {
      showLoading(false);
      showResult({ ok: false, message: 'Błąd sieci: ' + e.message });
    }
  }

  document.getElementById('btn-sync-all').addEventListener('click', () => doSync('sync_all'));
  document.getElementById('btn-sync-contacts').addEventListener('click', () => doSync('sync_contacts'));
  document.getElementById('btn-sync-calendar').addEventListener('click', () => doSync('sync_calendar'));
  document.getElementById('btn-sync-messages').addEventListener('click', () => doSync('sync_messages'));

  // ── Reset delta ────────────────────────────────────────────────────────────

  document.getElementById('btn-reset-delta').addEventListener('click', async () => {
    if (!confirm('Usunąć delta-link? Następna synchronizacja pobierze wszystkie dane od nowa.')) return;
    const json = await callSync('reset_delta');
    showResult(json);
  });

  // ── Load calendars ─────────────────────────────────────────────────────────

  document.getElementById('btn-load-calendars').addEventListener('click', async () => {
    const list = document.getElementById('calendars-list');
    list.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Ładowanie…';
    try {
      const json = await callSync('get_calendars');
      if (!json.ok || !json.data?.length) {
        list.innerHTML = '<span class="text-danger small">Nie można pobrać listy kalendarzy.</span>';
        return;
      }
      let html = '<div class="list-group list-group-flush border rounded mt-1">';
      for (const cal of json.data) {
        const isSelected = cal.id === document.getElementById('calendar-id-input').value;
        html += `<button type="button"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1 px-2 small ${isSelected ? 'active' : ''}"
                   data-cal-id="${cal.id}">
          <span>${cal.name ?? cal.id}${cal.isDefaultCalendar ? ' <span class=\'badge bg-primary ms-1\'>domyślny</span>' : ''}</span>
          <code class="text-muted">${cal.id.substring(0, 20)}…</code>
        </button>`;
      }
      html += '</div>';
      list.innerHTML = html;

      list.querySelectorAll('[data-cal-id]').forEach(btn => {
        btn.addEventListener('click', () => {
          document.getElementById('calendar-id-input').value = btn.dataset.calId;
          list.querySelectorAll('[data-cal-id]').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
        });
      });
    } catch (e) {
      list.innerHTML = '<span class="text-danger small">Błąd: ' + e.message + '</span>';
    }
  });

  // ── Refresh log ────────────────────────────────────────────────────────────

  async function refreshLog() {
    const json = await callSync('get_status');
    const logs = json.data?.logs ?? [];
    const el = document.getElementById('sync-log-table');
    if (!logs.length) {
      el.innerHTML = '<p class="text-muted text-center py-4 mb-0">Brak wpisów.</p>';
      return;
    }
    const source_label = {
      outlook_contacts: '<span class="badge bg-primary">Kontakty</span>',
      outlook_calendar: '<span class="badge bg-success">Kalendarz</span>',
    };
    let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
    html += '<thead class="table-light"><tr><th>Czas</th><th>Źródło</th><th>Rekordy</th><th>Szczegóły</th></tr></thead><tbody>';
    for (const log of logs) {
      const d = JSON.parse(log.details || '{}');
      const errs = (d.errors ?? []).length;
      const err_html = errs ? ` <span class="badge bg-danger ms-1">${errs} błędów</span>` : '';
      html += `<tr>
        <td class="text-nowrap small">${log.synced_at ?? ''}</td>
        <td>${source_label[log.source] ?? '<span class="badge bg-secondary">' + (log.source ?? '') + '</span>'}</td>
        <td class="small">${log.records_updated ?? 0}</td>
        <td class="small">
          <span class="text-success">+${d.created ?? 0}</span>
          <span class="text-primary ms-1">~${d.updated ?? 0}</span>
          <span class="text-danger ms-1">-${d.removed ?? 0}</span>
          ${err_html}
        </td>
      </tr>`;
    }
    html += '</tbody></table></div>';
    el.innerHTML = html;
  }

  document.getElementById('btn-refresh-log').addEventListener('click', refreshLog);
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
