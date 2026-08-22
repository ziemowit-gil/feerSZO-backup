<?php
/**
 * crm/settings/office.php — Integracja CRM ↔ Microsoft 365 (Outlook).
 *
 * Dwie strony tej samej integracji:
 *   • przychodząca (istniejąca): OutlookSync — kontakty, kalendarz i maile z Outlooka
 *     do CRM, konfigurowana w Administracja → Synchronizacja Outlook,
 *   • wychodząca (ta strona): kontakty CRM → książka adresowa Outlooka oraz
 *     pobieranie korespondencji konkretnego kontaktu do kartoteki.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_office.php';
require_once dirname(dirname(__DIR__)) . '/includes/outlook_sync.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_office_migrate();

$PAGE_TITLE = 'CRM — Microsoft 365';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['_action'] ?? 'save');

    if ($act === 'save') {
        crm_setting_save('crm_office_push_enabled', !empty($_POST['push_enabled']) ? '1' : '0');
        crm_setting_save('crm_office_auto_push',    !empty($_POST['auto_push']) ? '1' : '0');
        crm_setting_save('crm_office_mailbox',      trim((string)($_POST['mailbox'] ?? '')));
        crm_setting_save('crm_office_mail_days',    (string)max(1, (int)($_POST['mail_days'] ?? 365)));
        crm_setting_save('crm_office_mail_max',     (string)max(1, min(200, (int)($_POST['mail_max'] ?? 50))));
        $folder = trim((string)($_POST['folder_id'] ?? ''));
        crm_setting_save('crm_office_folder_id', $folder);
        crm_setting_save('crm_office_folder_name', $folder === '' ? '' : trim((string)($_POST['folder_name'] ?? '')));
        flash_set('success', 'Ustawienia integracji zapisane.');
        header('Location: ' . APP_URL . '/crm/settings/office.php'); exit;
    }

    if ($act === 'push_all') {
        $r = crm_office_push_pending((int)($_POST['limit'] ?? 200));
        flash_set($r['failed'] ? 'warning' : 'success',
            'Książka adresowa: ' . $r['created'] . ' nowych, ' . $r['updated'] . ' zaktualizowanych, '
            . $r['failed'] . ' błędów.' . ($r['errors'] ? ' Pierwszy błąd: ' . $r['errors'][0] : ''));
        header('Location: ' . APP_URL . '/crm/settings/office.php'); exit;
    }
}

$st = crm_office_status();

// Foldery kontaktów skrzynki — do wyboru, gdzie zapisywać kontakty CRM
$folders = [];
if ($st['graph_configured'] && $st['mailbox'] !== '') {
    try { $folders = (new M365Graph())->get_contact_folders($st['mailbox']); }
    catch (\Throwable $e) { $msg = 'Nie udało się pobrać folderów kontaktów: ' . $e->getMessage(); }
}

$stats = crm_one(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN outlook_id IS NOT NULL AND outlook_id <> '' THEN 1 ELSE 0 END) AS linked,
            SUM(CASE WHEN office_push_error IS NOT NULL AND office_push_error <> '' THEN 1 ELSE 0 END) AS failed
     FROM crm_contacts WHERE crm_active = 1"
) ?: [];

$errors_list = crm_all(
    "SELECT id, imie_nazwisko, office_push_error FROM crm_contacts
     WHERE crm_active=1 AND office_push_error IS NOT NULL AND office_push_error <> ''
     ORDER BY id DESC LIMIT 10"
);

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Microsoft 365</li>
  </ol>
</nav>

<h1 class="fw-bold mb-3" style="font-size:1.25rem">
  <i class="bi bi-microsoft me-2" style="color:var(--crm-primary)"></i>Integracja z Microsoft 365
</h1>

<?php if ($msg): ?><div class="alert alert-warning py-2" style="font-size:.85rem"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header py-2 fw-semibold" style="font-size:.9rem">
        <i class="bi bi-arrow-down-left-circle me-1"></i>Outlook → CRM (istniejące)
      </div>
      <div class="card-body" style="font-size:.85rem">
        <div class="d-flex justify-content-between"><span class="text-muted">Synchronizacja przychodząca</span>
          <strong class="<?= $st['inbound_sync'] ? 'text-success' : 'text-muted' ?>">
            <?= $st['inbound_sync'] ? 'włączona' : 'wyłączona' ?></strong></div>
        <p class="text-muted mt-2 mb-2">
          Kontakty, kalendarz i wiadomości (skrzynka odbiorcza + elementy wysłane) zaciągane
          delta-syncem do CRM; maile trafiają do historii komunikacji kontaktu po dopasowaniu adresu.
        </p>
        <a href="<?= APP_URL ?>/admin/outlook_sync.php" class="btn btn-crm-outline btn-sm">
          <i class="bi bi-gear me-1"></i>Konfiguracja synchronizacji
        </a>
        <a href="<?= APP_URL ?>/crm/settings/inbox.php" class="btn btn-crm-ghost btn-sm">Śledzenie skrzynki</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header py-2 fw-semibold" style="font-size:.9rem">
        <i class="bi bi-arrow-up-right-circle me-1"></i>CRM → Outlook (ta strona)
      </div>
      <div class="card-body" style="font-size:.85rem">
        <div class="d-flex justify-content-between"><span class="text-muted">Graph skonfigurowany</span>
          <strong class="<?= $st['graph_configured'] ? 'text-success' : 'text-danger' ?>">
            <?= $st['graph_configured'] ? 'tak' : 'nie' ?></strong></div>
        <?php $app_name = crm_office_app_name(); ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Aplikacja w Entra ID</span>
          <strong><?= h($app_name ?: '—') ?></strong></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Client ID</span>
          <code style="font-size:.75rem"><?= h($st['client_id'] ?: '—') ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Tenant ID</span>
          <code style="font-size:.75rem"><?= h($st['tenant_id'] ?: '—') ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Skrzynka docelowa</span>
          <strong><?= h($st['mailbox'] ?: '—') ?></strong></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Kontakty powiązane</span>
          <strong><?= (int)($stats['linked'] ?? 0) ?> / <?= (int)($stats['total'] ?? 0) ?></strong></div>
        <?php if ((int)($stats['failed'] ?? 0) > 0): ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Z błędem zapisu</span>
          <strong class="text-danger"><?= (int)$stats['failed'] ?></strong></div>
        <?php endif; ?>
        <?php $perms = crm_office_permissions(); ?>
        <div class="mt-2" style="font-size:.8rem">
          <div class="of-label" style="font-size:.7rem;letter-spacing:.08em;color:#8A9099;text-transform:uppercase">
            Uprawnienia aplikacji w Entra ID
          </div>
          <?php foreach ($perms as $pname => $pok): ?>
          <div class="d-flex justify-content-between py-1">
            <span><code><?= h($pname) ?></code>
              <span class="text-muted"><?= $pname === 'Mail.Read' ? '— pobieranie korespondencji' : '— zapis kontaktów' ?></span>
            </span>
            <strong class="<?= $pok ? 'text-success' : 'text-danger' ?>">
              <?= $pok ? 'nadane' : 'BRAK' ?>
            </strong>
          </div>
          <?php endforeach; ?>
          <?php if (in_array(false, $perms, true)): ?>
          <div class="alert alert-warning py-2 mt-1 mb-0" style="font-size:.78rem">
            Brakujące uprawnienie trzeba dodać <strong>w tej rejestracji</strong>:
            <?= h($app_name ?: 'aplikacja') ?> — <code><?= h($st['client_id'] ?: '?') ?></code>
            (Entra ID → App registrations → ta aplikacja → API permissions → Microsoft Graph →
            Application permissions), a potem kliknąć <strong>Grant admin consent</strong>.
            Uprawnienie dodane bez zgody administratora nie działa i nie widać go na tej liście.
            Bez <code>Mail.Read</code> pobieranie maili zwraca HTTP 403 — dotyczy też
            synchronizacji przychodzącej i śledzenia skrzynki.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<form method="post" class="card shadow-sm mb-3">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save">
<div class="card-header py-2 fw-semibold" style="font-size:.9rem"><i class="bi bi-sliders me-1"></i>Ustawienia</div>
<div class="card-body">
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label small fw-semibold mb-1">Skrzynka / książka adresowa</label>
      <input name="mailbox" class="form-control form-control-sm"
             value="<?= h(crm_setting('crm_office_mailbox')) ?>"
             placeholder="<?= h(crm_setting('m365_sync_user_id') ?: 'np. fundacja@feer.org.pl') ?>">
      <div class="form-text" style="font-size:.75rem">
        Puste = ta sama skrzynka, co synchronizacja przychodząca
        (<code><?= h(crm_setting('m365_sync_user_id') ?: crm_setting('m365_sender_user_id') ?: 'nie ustawiono') ?></code>).
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label small fw-semibold mb-1">Folder kontaktów</label>
      <?php if ($folders): ?>
      <select name="folder_id" class="form-select form-select-sm"
              onchange="document.getElementById('fname').value = this.options[this.selectedIndex].text">
        <option value="">— domyślny folder Kontakty —</option>
        <?php foreach ($folders as $f): ?>
        <option value="<?= h($f['id'] ?? '') ?>" <?= crm_setting('crm_office_folder_id') === ($f['id'] ?? '') ? 'selected' : '' ?>>
          <?= h($f['displayName'] ?? '(bez nazwy)') ?>
        </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="folder_name" id="fname" value="<?= h(crm_setting('crm_office_folder_name')) ?>">
      <?php else: ?>
      <input name="folder_id" class="form-control form-control-sm" value="<?= h(crm_setting('crm_office_folder_id')) ?>"
             placeholder="ID folderu (opcjonalnie)">
      <div class="form-text" style="font-size:.75rem">Listy folderów nie udało się pobrać — wpisz ID albo zostaw puste.</div>
      <?php endif; ?>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold mb-1">Korespondencja — dni wstecz</label>
      <input type="number" min="1" name="mail_days" class="form-control form-control-sm"
             value="<?= h((string)crm_office_mail_days()) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold mb-1">Limit wiadomości na pobranie</label>
      <input type="number" min="1" max="200" name="mail_max" class="form-control form-control-sm"
             value="<?= h((string)crm_office_mail_max()) ?>">
    </div>
    <div class="col-md-6 d-flex flex-column justify-content-end">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" name="push_enabled" value="1" id="pe"
               <?= crm_setting('crm_office_push_enabled') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="pe">Pozwól zapisywać kontakty CRM do książki adresowej</label>
      </div>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" name="auto_push" value="1" id="ap"
               <?= crm_setting('crm_office_auto_push') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="ap">Zapisuj automatycznie każdy nowy kontakt</label>
      </div>
    </div>
  </div>
</div>
<div class="card-footer py-2 d-flex gap-2">
  <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
</div>
</form>

<form method="post" class="card shadow-sm mb-4"
      onsubmit="return confirm('Zapisać do książki adresowej Outlooka wszystkie kontakty, które jeszcze tam nie trafiły lub zmieniły się od ostatniego zapisu?')">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="push_all">
  <div class="card-body d-flex align-items-center gap-3 flex-wrap">
    <div style="flex:1;min-width:240px;font-size:.85rem">
      <strong>Zapis zaległych kontaktów</strong>
      <div class="text-muted">Obejmuje kontakty bez powiązania oraz zmienione po ostatnim zapisie.</div>
    </div>
    <div class="input-group input-group-sm" style="max-width:140px">
      <span class="input-group-text">limit</span>
      <input type="number" name="limit" class="form-control" value="200" min="1" max="1000">
    </div>
    <button class="btn btn-crm-outline btn-sm" <?= crm_office_push_enabled() ? '' : 'disabled title="Włącz zapis kontaktów powyżej"' ?>>
      <i class="bi bi-upload me-1"></i>Zapisz teraz
    </button>
  </div>
</form>

<?php if ($errors_list): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header py-2 fw-semibold text-danger" style="font-size:.9rem">
    <i class="bi bi-exclamation-triangle me-1"></i>Ostatnie błędy zapisu
  </div>
  <ul class="list-group list-group-flush" style="font-size:.82rem">
    <?php foreach ($errors_list as $e): ?>
    <li class="list-group-item">
      <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$e['id'] ?>"><?= h($e['imie_nazwisko']) ?></a>
      — <span class="text-muted"><?= h($e['office_push_error']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
