<?php
/**
 * crm/calendar_settings.php — Ustawienia synchronizacji kalendarza Outlook dla zalogowanego użytkownika CRM
 *
 * Dostępna tylko dla użytkowników z kontem Office 365 (microsoft_id != '').
 * Pozwala wybrać własny kalendarz Outlooka i włączyć/wyłączyć automatyczny sync → crm_events.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/outlook_sync.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE = 'Mój kalendarz Outlook — sync';
$user       = current_user();
$crm_user_id= (int)$user['id'];
$ms_id      = trim($user['microsoft_id'] ?? '');

// ── Obsługa POST ───────────────────────────────────────────────────────────────

$save_ok  = null;
$save_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $calendar_id   = trim($_POST['calendar_id']   ?? '');
        $calendar_name = trim($_POST['calendar_name'] ?? '');
        $sync_enabled  = !empty($_POST['sync_enabled']);

        OutlookSync::save_user_pref($crm_user_id, $calendar_id, $calendar_name, $sync_enabled);

        // Wyczyść delta jeśli zmienił się kalendarz
        $old = OutlookSync::get_user_pref($crm_user_id);
        if (($old['calendar_id'] ?? '') !== $calendar_id) {
            crm_db()->prepare(
                "UPDATE crm_user_calendar_prefs SET delta_link = NULL WHERE user_id = ?"
            )->execute([$crm_user_id]);
        }

        $save_ok  = true;
        $save_msg = 'Ustawienia kalendarza zapisane.';
    }

    if ($action === 'reset_delta') {
        crm_db()->prepare(
            "UPDATE crm_user_calendar_prefs SET delta_link = NULL WHERE user_id = ?"
        )->execute([$crm_user_id]);
        $save_ok  = true;
        $save_msg = 'Delta-link zresetowany — następna synchronizacja pobierze cały kalendarz.';
    }

    if ($action === 'disable') {
        crm_db()->prepare(
            "UPDATE crm_user_calendar_prefs SET sync_enabled = 0 WHERE user_id = ?"
        )->execute([$crm_user_id]);
        $save_ok  = true;
        $save_msg = 'Synchronizacja kalendarza wyłączona.';
    }
}

// ── Pobierz bieżące prefs ──────────────────────────────────────────────────────

$pref = OutlookSync::get_user_pref($crm_user_id);

// ── Statystyki zsynchronizowanych eventów ─────────────────────────────────────

$synced_count = 0;
$recent_events = [];
if ($ms_id) {
    try {
        $synced_count = (int)(crm_db()->prepare(
            "SELECT COUNT(*) FROM crm_events WHERE created_by = ? AND outlook_id IS NOT NULL AND outlook_id != ''"
        )->execute([$crm_user_id]) ? crm_db()->query(
            "SELECT COUNT(*) FROM crm_events WHERE created_by = {$crm_user_id} AND outlook_id IS NOT NULL AND outlook_id != ''"
        )->fetchColumn() : 0);

        $stmt = crm_db()->prepare(
            "SELECT title, event_date, event_time, color FROM crm_events
             WHERE created_by = ? AND outlook_id IS NOT NULL AND outlook_id != ''
               AND event_date >= DATE('now', '-7 days')
             ORDER BY event_date ASC, event_time ASC
             LIMIT 5"
        );
        $stmt->execute([$crm_user_id]);
        $recent_events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

include __DIR__ . '/includes/header_crm.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item">
      <a href="<?= APP_URL ?>/crm/dashboard.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a>
    </li>
    <li class="breadcrumb-item">
      <a href="<?= APP_URL ?>/crm/calendar.php">Kalendarz</a>
    </li>
    <li class="breadcrumb-item active">Synchronizacja Outlook</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-4">
  <div class="crm-object-icon"><i class="bi bi-calendar-check-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Mój kalendarz Outlook</h1>
    <p class="crm-object-subtitle">Automatyczna synchronizacja Twoich zdarzeń z Outlooka → CRM</p>
  </div>
</div>

<?php if ($save_ok !== null): ?>
<div class="alert alert-<?= $save_ok ? 'success' : 'danger' ?> py-2 d-flex gap-2 align-items-center">
  <i class="bi bi-<?= $save_ok ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
  <?= h($save_msg) ?>
</div>
<?php endif; ?>

<?php if (!$ms_id): ?>
<!-- ── Brak konta Office 365 ─────────────────────────────────────────────── -->
<div class="card border-warning shadow-sm">
  <div class="card-body d-flex gap-3 align-items-start">
    <i class="bi bi-microsoft fs-2 text-warning flex-shrink-0"></i>
    <div>
      <h5 class="card-title mb-1">Brak połączonego konta Office 365</h5>
      <p class="text-muted mb-2">
        Synchronizacja kalendarza Outlook jest dostępna tylko dla użytkowników,
        którzy zalogowali się przez Microsoft 365 lub mają przypisane konto Azure AD.
      </p>
      <p class="mb-0 small text-muted">
        Skontaktuj się z administratorem, aby powiązać Twoje konto
        z kontem Microsoft 365.
      </p>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Formularz ustawień ─────────────────────────────────────────────────── -->
<div class="row g-4">

  <!-- Lewa kolumna: konfiguracja -->
  <div class="col-lg-7">
    <form method="post" id="cal-pref-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="save">

      <div class="card mb-3">
        <div class="card-header fw-semibold d-flex gap-2 align-items-center">
          <i class="bi bi-microsoft text-primary"></i>
          Twoje konto M365
        </div>
        <div class="card-body py-2">
          <dl class="row mb-0 small">
            <dt class="col-4">Konto:</dt>
            <dd class="col-8"><code><?= h($user['email'] ?? '') ?></code></dd>
            <dt class="col-4">Azure AD ID:</dt>
            <dd class="col-8"><code class="text-muted"><?= h($ms_id) ?></code></dd>
          </dl>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header fw-semibold d-flex gap-2 align-items-center">
          <i class="bi bi-calendar3 text-success"></i>
          Wybór kalendarza
        </div>
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label fw-semibold">Kalendarz Outlooka</label>
            <div class="input-group mb-1">
              <input type="text" id="cal-name-display" class="form-control"
                     value="<?= h($pref['calendar_name'] ?? '') ?>"
                     placeholder="Kliknij Wczytaj kalendarze…"
                     readonly style="background:#fff;cursor:default">
              <input type="hidden" name="calendar_id"   id="cal-id-input"   value="<?= h($pref['calendar_id']   ?? '') ?>">
              <input type="hidden" name="calendar_name" id="cal-name-input" value="<?= h($pref['calendar_name'] ?? '') ?>">
              <button type="button" class="btn btn-outline-secondary" id="btn-load-cals">
                <span id="load-cals-spinner" class="spinner-border spinner-border-sm d-none"></span>
                <i class="bi bi-arrow-clockwise" id="load-cals-icon"></i> Wczytaj kalendarze
              </button>
            </div>
            <div id="cal-list" class="mt-2"></div>
            <div class="form-text">
              Puste = domyślny kalendarz Outlooka.
              Kliknij „Wczytaj kalendarze" aby zobaczyć listę Twoich kalendarzy.
            </div>
          </div>

          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="sync-enabled"
                   name="sync_enabled" value="1" role="switch"
                   <?= !empty($pref['sync_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="sync-enabled">
              Automatyczna synchronizacja (co godzinę)
            </label>
          </div>
          <div class="form-text small text-muted">
            Zdarzenia z wybranego kalendarza będą automatycznie pojawiać się w
            <a href="<?= APP_URL ?>/crm/calendar.php">kalendarzu CRM</a> — widoczne tylko dla Ciebie.
          </div>

        </div>
        <div class="card-footer d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
          <button type="button" class="btn btn-outline-success btn-sm" id="btn-sync-now">
            <i class="bi bi-arrow-repeat me-1"></i>Synchronizuj teraz
          </button>
        </div>
      </div>
    </form>

    <!-- Reset delta -->
    <?php if ($pref): ?>
    <div class="card bg-light">
      <div class="card-body py-2 d-flex align-items-center gap-3">
        <form method="post" class="mb-0">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="reset_delta">
          <button type="submit" class="btn btn-sm btn-outline-secondary"
                  onclick="return confirm('Zresetować delta-link? Następna synchronizacja pobierze cały kalendarz od nowa.')">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset delta-link
          </button>
        </form>
        <span class="text-muted small">Usuwa punkt kontrolny; wymusi pełny re-sync.</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prawa kolumna: status -->
  <div class="col-lg-5">

    <!-- Status sync -->
    <div class="card mb-3">
      <div class="card-header fw-semibold d-flex gap-2 align-items-center">
        <i class="bi bi-activity text-success"></i>Status synchronizacji
      </div>
      <div class="card-body">
        <?php if (!$pref): ?>
          <p class="text-muted small mb-0">Brak konfiguracji — zapisz ustawienia, aby włączyć sync.</p>
        <?php else: ?>
          <dl class="row small mb-2">
            <dt class="col-6">Wybrany kalendarz:</dt>
            <dd class="col-6">
              <?= $pref['calendar_name'] ? h($pref['calendar_name']) : '<span class="text-muted">Domyślny</span>' ?>
            </dd>
            <dt class="col-6">Autosync:</dt>
            <dd class="col-6">
              <?php if ($pref['sync_enabled']): ?>
                <span class="badge bg-success-subtle text-success border border-success">Włączony</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border">Wyłączony</span>
              <?php endif; ?>
            </dd>
            <dt class="col-6">Ostatni sync:</dt>
            <dd class="col-6">
              <?= $pref['last_synced_at']
                ? h(date('d.m.Y H:i', strtotime($pref['last_synced_at'])))
                : '<span class="text-muted">—</span>' ?>
            </dd>
            <dt class="col-6">Zsynchronizowanych:</dt>
            <dd class="col-6"><strong><?= $synced_count ?></strong> zdarzeń</dd>
          </dl>
        <?php endif; ?>

        <!-- Wynik sync teraz -->
        <div id="sync-result" class="d-none mt-2">
          <div id="sync-alert" class="alert py-2 mb-0 small"></div>
        </div>
        <div id="sync-loading" class="d-none text-center py-2">
          <span class="spinner-border spinner-border-sm text-success me-2"></span>Synchronizuję…
        </div>
      </div>
    </div>

    <!-- Najbliższe zsynchronizowane eventy -->
    <?php if ($recent_events): ?>
    <div class="card">
      <div class="card-header fw-semibold d-flex gap-2 align-items-center">
        <i class="bi bi-calendar-event text-primary"></i>Ostatnio zsynchronizowane
      </div>
      <ul class="list-group list-group-flush">
        <?php foreach ($recent_events as $ev): ?>
          <li class="list-group-item py-2 px-3">
            <div class="d-flex gap-2 align-items-start">
              <span class="rounded-circle flex-shrink-0 mt-1"
                    style="width:10px;height:10px;background:<?= h($ev['color'] ?? '#2E844A') ?>;display:inline-block"></span>
              <div class="small">
                <div class="fw-semibold"><?= h($ev['title']) ?></div>
                <div class="text-muted">
                  <?= h(date('d.m', strtotime($ev['event_date']))) ?>
                  <?= $ev['event_time'] ? ' ' . h(substr($ev['event_time'],0,5)) : '' ?>
                </div>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

  </div>
</div><!-- /row -->

<?php endif; /* $ms_id */ ?>

<script>
(function () {
  'use strict';
  const API = '<?= APP_URL ?>/crm/api/outlook_sync.php';

  // ── Wczytaj kalendarze ─────────────────────────────────────────────────────
  const btnLoad = document.getElementById('btn-load-cals');
  if (btnLoad) {
    btnLoad.addEventListener('click', async function () {
      const spinner = document.getElementById('load-cals-spinner');
      const icon    = document.getElementById('load-cals-icon');
      spinner.classList.remove('d-none');
      icon.classList.add('d-none');
      btnLoad.disabled = true;

      try {
        const r = await fetch(API, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ action: 'get_calendars' }),
        });
        const json = await r.json();

        if (!json.ok || !json.data?.length) {
          document.getElementById('cal-list').innerHTML =
            '<div class="alert alert-warning py-1 small">Nie można pobrać listy kalendarzy. ' +
            'Sprawdź konfigurację M365.</div>';
          return;
        }

        const currentId = document.getElementById('cal-id-input').value;
        let html = '<div class="list-group list-group-flush border rounded">';
        for (const cal of json.data) {
          const isActive = cal.id === currentId;
          html += `<button type="button"
            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1 px-3 small${isActive ? ' active' : ''}"
            data-cal-id="${cal.id}" data-cal-name="${cal.name ?? ''}">
            <span>${cal.name ?? cal.id}${cal.isDefaultCalendar ? ' <span class=\'badge bg-primary ms-1\'>domyślny</span>' : ''}</span>
          </button>`;
        }
        html += '</div>';
        document.getElementById('cal-list').innerHTML = html;

        document.querySelectorAll('#cal-list [data-cal-id]').forEach(btn => {
          btn.addEventListener('click', () => {
            document.querySelectorAll('#cal-list [data-cal-id]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('cal-id-input').value   = btn.dataset.calId;
            document.getElementById('cal-name-input').value = btn.dataset.calName;
            document.getElementById('cal-name-display').value = btn.dataset.calName;
          });
        });
      } catch (e) {
        document.getElementById('cal-list').innerHTML =
          '<div class="alert alert-danger py-1 small">Błąd: ' + e.message + '</div>';
      } finally {
        spinner.classList.add('d-none');
        icon.classList.remove('d-none');
        btnLoad.disabled = false;
      }
    });
  }

  // ── Synchronizuj teraz ─────────────────────────────────────────────────────
  const btnSync = document.getElementById('btn-sync-now');
  if (btnSync) {
    btnSync.addEventListener('click', async function () {
      const resultEl  = document.getElementById('sync-result');
      const alertEl   = document.getElementById('sync-alert');
      const loadingEl = document.getElementById('sync-loading');

      resultEl.classList.add('d-none');
      loadingEl.classList.remove('d-none');
      btnSync.disabled = true;

      try {
        const r = await fetch(API, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ action: 'sync_user_calendar' }),
        });
        const json = await r.json();

        loadingEl.classList.add('d-none');
        resultEl.classList.remove('d-none');

        const ok = json.ok ?? false;
        alertEl.className = 'alert py-2 mb-0 small alert-' + (ok ? 'success' : 'danger');
        alertEl.innerHTML = (ok ? '✓ ' : '✗ ') + (json.message ?? '');
        if (json.data?.skipped) {
          alertEl.innerHTML = '⚠ Synchronizacja pominięta (brak konfiguracji lub konto bez M365).';
          alertEl.className = 'alert py-2 mb-0 small alert-warning';
        }
      } catch (e) {
        loadingEl.classList.add('d-none');
        resultEl.classList.remove('d-none');
        alertEl.className = 'alert py-2 mb-0 small alert-danger';
        alertEl.textContent = 'Błąd sieci: ' + e.message;
      } finally {
        btnSync.disabled = false;
      }
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
