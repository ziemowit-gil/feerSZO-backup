<?php
/**
 * crm/settings/inbox.php — Śledzenie skrzynki współdzielonej (auto-dopisywanie do kartoteki).
 *
 * Admin konfiguruje: włączenie, adres skrzynki, tworzenie nowych kontaktów,
 * pomijanie własnej domeny. Może też uruchomić przebieg ręcznie ("Sprawdź teraz").
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_inbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zmieniać ustawienia śledzenia skrzynki.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();

$PAGE_TITLE = 'CRM — Śledzenie skrzynki';
$BASE_URL   = APP_URL . '/crm/settings/inbox.php';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $mailbox = trim($_POST['mailbox'] ?? '');
        if ($mailbox !== '' && !filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Podaj poprawny adres skrzynki.');
        } else {
            crm_setting_save('crm_inbox_watch_enabled',       !empty($_POST['enabled'])       ? '1' : '0');
            crm_setting_save('crm_inbox_watch_mailbox',       $mailbox ?: 'fundacja@feer.org.pl');
            crm_setting_save('crm_inbox_watch_create',        !empty($_POST['create'])        ? '1' : '0');
            crm_setting_save('crm_inbox_watch_skip_internal', !empty($_POST['skip_internal']) ? '1' : '0');
            flash_set('success', 'Ustawienia śledzenia skrzynki zapisane.');
        }
        header('Location: ' . $BASE_URL);
        exit;
    }

    if ($op === 'run_now') {
        if (crm_setting('crm_inbox_watch_enabled') !== '1') {
            flash_set('warning', 'Najpierw włącz i zapisz śledzenie skrzynki.');
        } else {
            try {
                $r = crm_inbox_watch_run();
                flash_set('success', sprintf(
                    'Sprawdzono skrzynkę %s — pobrano %d, utworzono %d, zalogowano %d, pominięto %d.',
                    $r['mailbox'], $r['fetched'], $r['created'], $r['logged'], $r['skipped']
                ));
                if ($r['errors']) flash_set('warning', 'Uwagi: ' . implode(' | ', array_slice($r['errors'], 0, 3)));
            } catch (\Throwable $e) {
                flash_set('danger', 'Błąd: ' . $e->getMessage());
            }
        }
        header('Location: ' . $BASE_URL);
        exit;
    }

    if ($op === 'reset_cursor') {
        crm_setting_save('crm_inbox_watch_last', '');
        flash_set('success', 'Kursor zresetowany — przy następnym przebiegu zostaną sprawdzone wiadomości z ostatnich 7 dni.');
        header('Location: ' . $BASE_URL);
        exit;
    }
}

$enabled       = crm_setting('crm_inbox_watch_enabled') === '1';
$mailbox       = crm_inbox_mailbox();
$do_create     = crm_setting('crm_inbox_watch_create') !== '0';
$skip_internal = crm_setting('crm_inbox_watch_skip_internal') !== '0';
$last_cursor   = crm_setting('crm_inbox_watch_last');

// Czy Graph skonfigurowany?
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
$graph_ok = (new M365Graph())->is_configured();

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Śledzenie skrzynki</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon" aria-hidden="true"><i class="bi bi-inbox-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Śledzenie skrzynki</h1>
    <div class="crm-object-count">
      Automatyczne dopisywanie nadawców do kartoteki i logowanie wiadomości przychodzących.
    </div>
  </div>
  <div class="crm-object-actions">
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op"   value="run_now">
      <button type="submit" class="btn btn-sm btn-crm-primary" <?= $enabled ? '' : 'disabled' ?>>
        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Sprawdź teraz
      </button>
    </form>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$graph_ok): ?>
<div class="alert alert-warning d-flex gap-2 mb-3" style="font-size:.85rem" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    Microsoft 365 / Graph nie jest skonfigurowany. Śledzenie skrzynki wymaga uprawnienia aplikacji
    <strong>Mail.Read</strong>. Skonfiguruj integrację w
    <a href="<?= APP_URL ?>/admin/m365_settings.php">Ustawieniach M365</a>.
  </div>
</div>
<?php endif; ?>

<div class="cv-panel" style="max-width:640px">
  <div class="cv-panel__body">
    <div class="cv-shead">
      <i class="bi bi-gear cv-shead__icon" aria-hidden="true"></i>
      <h2 class="cv-shead__title">Konfiguracja</h2>
    </div>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op"   value="save">

      <div class="form-check form-switch mb-3">
        <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="enabled">Włącz śledzenie skrzynki</label>
        <div class="form-text" style="font-size:.78rem">Sprawdzanie uruchamia harmonogram (CRON) co ~10 minut.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold small" for="mailbox">Adres śledzonej skrzynki</label>
        <input type="email" class="form-control form-control-sm" id="mailbox" name="mailbox"
               value="<?= h($mailbox) ?>" placeholder="fundacja@feer.org.pl">
        <div class="form-text" style="font-size:.78rem">Skrzynka współdzielona lub użytkownik w Twoim tenancie M365.</div>
      </div>

      <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="create" name="create" value="1" <?= $do_create ? 'checked' : '' ?>>
        <label class="form-check-label small" for="create">Twórz nowe kontakty dla nieznanych nadawców</label>
      </div>
      <div class="form-text mb-2" style="font-size:.76rem;margin-top:-.3rem">
        Gdy wyłączone — wiadomości są logowane tylko do istniejących kontaktów.
      </div>

      <div class="form-check mb-3">
        <input type="checkbox" class="form-check-input" id="skip_internal" name="skip_internal" value="1" <?= $skip_internal ? 'checked' : '' ?>>
        <label class="form-check-label small" for="skip_internal">Pomijaj nadawców z własnej domeny skrzynki</label>
      </div>

      <button type="submit" class="btn btn-crm-primary btn-sm">
        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ustawienia
      </button>
    </form>
  </div>
</div>

<div class="cv-panel" style="max-width:640px">
  <div class="cv-panel__body">
    <div class="cv-shead">
      <i class="bi bi-clock-history cv-shead__icon" aria-hidden="true"></i>
      <h2 class="cv-shead__title">Stan</h2>
    </div>
    <table class="table table-sm table-borderless mb-0" style="font-size:.86rem">
      <tbody>
        <tr><td class="cv-muted pe-2" style="width:40%">Status</td>
            <td><?= $enabled
                ? '<span class="crm-badge crm-badge-aktywny">Włączone</span>'
                : '<span class="crm-badge crm-badge-nieaktywny">Wyłączone</span>' ?></td></tr>
        <tr><td class="cv-muted pe-2">Skrzynka</td><td><?= h($mailbox) ?></td></tr>
        <tr><td class="cv-muted pe-2">Ostatnio przetworzono do</td>
            <td><?= $last_cursor ? h(date('d.m.Y H:i', strtotime($last_cursor))) . ' <span class="cv-muted">(UTC)</span>' : '<span class="cv-muted">— jeszcze nie uruchomiono —</span>' ?></td></tr>
      </tbody>
    </table>
    <form method="post" class="mt-2" onsubmit="return confirm('Zresetować kursor? Następny przebieg sprawdzi wiadomości z ostatnich 7 dni.')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op"   value="reset_cursor">
      <button type="submit" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Resetuj kursor
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
