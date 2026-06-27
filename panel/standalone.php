<?php
/**
 * panel/standalone.php — Panel wolontariusza bez umowy.
 *
 * Pokazuje karty z danymi logowania do platform (portal, M365, Moodle)
 * oraz podsumowanie zadań. Wzorowany na wyświetlaniu konta M365
 * w panelu wolontariusza z umową.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/moodle.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

require_login();
$user    = current_user();
$user_id = (int)$user['id'];

// Pobierz pełne dane ze świeżymi kolumnami (m365_login, moodle_login, org_unit)
$u_db = db_one(
    "SELECT u.*, ou.name AS org_unit_name
     FROM users u
     LEFT JOIN org_units ou ON ou.id = u.org_unit_id
     WHERE u.id=?",
    [$user_id]
);

// Guard: tylko standalone volunteers
if (empty($u_db['is_standalone_volunteer'])) {
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

$PAGE_TITLE   = 'Mój panel';
$display_name = trim(($u_db['first_name'] ?? '') . ' ' . ($u_db['last_name'] ?? '')) ?: ($u_db['name'] ?? '');
$email        = $u_db['email'] ?? '';

// Dane platform
$m365_login    = $u_db['m365_login']    ?? '';
$moodle_login  = $u_db['moodle_login']  ?? '';
$moodle_url    = rtrim(moodle_setting('url') ?: org_setting('moodle_url') ?: '', '/');
$org_name      = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$org_unit_name = $u_db['org_unit_name'] ?? '';

// Liczba zadań
$task_count = 0;
try {
    $task_count = (int)(db_one(
        "SELECT COUNT(*) AS c FROM task_assignments ta
         JOIN tasks t ON t.id = ta.task_id AND t.status NOT IN ('done','cancelled','archived')
         WHERE ta.user_id=?",
        [$user_id]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

// Powiadomienia
notif_migrate();
$notif_count = notif_unread_count($user_id);

// Godzina powitania
$h = (int)date('G');
$greeting = $h < 5 ? 'Dobranoc' : ($h < 12 ? 'Dzień dobry' : ($h < 18 ? 'Witaj' : 'Dobry wieczór'));
$first_name = trim($u_db['first_name'] ?? '') ?: explode(' ', $display_name)[0];

include __DIR__ . '/includes/header_panel.php';
?>

<style>
/* ── Panel wolontariusza bez umowy — kolor wg --vol-color ─────── */
.sv-panel       { max-width: 880px; }
.sv-welcome     { margin-bottom: 1.5rem; }
.sv-greeting    { font-size: 1.3rem; font-weight: 800; color: #0f172a; letter-spacing: -.01em; }
.sv-sub         { font-size: .85rem; color: #64748b; margin-top: .2rem; }

/* Karty platform */
.sv-platforms   { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.sv-card        { border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.25rem; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.sv-card-head   { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
.sv-card-icon   { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.sv-card-title  { font-weight: 700; font-size: .95rem; color: #0f172a; }
.sv-card-sub    { font-size: .76rem; color: #94a3b8; }
.sv-field       { margin-bottom: .65rem; }
.sv-field-lbl   { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin-bottom: .2rem; }
.sv-field-val   { font-size: .88rem; font-weight: 600; color: #1e293b; font-family: 'Courier New', monospace; word-break: break-all; }
.sv-field-val a { color: var(--vol-color); text-decoration: none; font-family: system-ui, sans-serif; font-size: .85rem; font-weight: 500; }
.sv-field-val a:hover { text-decoration: underline; }
.sv-copy-btn    { background: none; border: none; padding: 0 0 0 .4rem; cursor: pointer; color: #94a3b8; font-size: .8rem; vertical-align: middle; }
.sv-copy-btn:hover { color: var(--vol-color); }
.sv-badge-ok    { display: inline-flex; align-items: center; gap: .3rem; background: #dcfce7; color: #166534; border-radius: 20px; padding: .15rem .6rem; font-size: .75rem; font-weight: 600; }
.sv-badge-none  { display: inline-flex; align-items: center; gap: .3rem; background: #f1f5f9; color: #64748b; border-radius: 20px; padding: .15rem .6rem; font-size: .75rem; }
.sv-card-footer { margin-top: 1rem; padding-top: .75rem; border-top: 1px solid #f1f5f9; }
.sv-card-footer a { font-size: .8rem; color: var(--vol-color); text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
.sv-card-footer a:hover { text-decoration: underline; }

/* Sekcja zadań */
.sv-tasks-bar   { display: flex; align-items: center; justify-content: space-between; gap: 1rem; background: var(--vol-bg); border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.sv-tasks-num   { font-size: 2rem; font-weight: 800; color: var(--vol-color); line-height: 1; }
.sv-tasks-lbl   { font-size: .82rem; color: #64748b; }

/* Szybkie linki */
.sv-quick       { display: flex; align-items: center; gap: .6rem; padding: .85rem; border: 1px solid #e5e7eb; border-radius: 12px; text-decoration: none; color: #1e293b; background: #fff; font-size: .84rem; height: 100%; transition: border-color .12s, box-shadow .12s; }
.sv-quick:hover { border-color: var(--vol-color); box-shadow: 0 4px 14px rgba(0,0,0,.06); color: #1e293b; }
.sv-quick i     { font-size: 1.25rem; }
</style>

<div class="sv-panel mx-auto">

  <!-- Powitanie -->
  <div class="sv-welcome">
    <div class="sv-greeting"><?= h($greeting) ?>, <?= h($first_name) ?> 👋</div>
    <div class="sv-sub">
      <?= h($org_name) ?>
      <?php if ($org_unit_name): ?>
        · <?= h($org_unit_name) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Karty platform ──────────────────────────────────────── -->
  <div class="sv-platforms">

    <!-- 1. Portal feerSZO -->
    <div class="sv-card">
      <div class="sv-card-head">
        <div class="sv-card-icon" style="background:var(--vol-bg);color:var(--vol-color)">
          <i class="bi bi-house-fill"></i>
        </div>
        <div>
          <div class="sv-card-title">Portal wolontariusza</div>
          <div class="sv-card-sub"><?= h(parse_url(APP_URL, PHP_URL_HOST)) ?></div>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Login (e-mail)</div>
        <div class="sv-field-val">
          <?= h($email) ?>
          <button class="sv-copy-btn" onclick="copyText(<?= json_encode($email) ?>, this)" title="Kopiuj">
            <i class="bi bi-copy"></i>
          </button>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Adres platformy</div>
        <div class="sv-field-val"><a href="<?= h(APP_URL) ?>/auth/login.php" target="_blank"><?= h(APP_URL) ?></a></div>
      </div>
      <div class="sv-card-footer">
        <a href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-key"></i> Zmień hasło</a>
      </div>
    </div>

    <!-- 2. Microsoft 365 (jeśli jest login) -->
    <?php if ($m365_login): ?>
    <div class="sv-card">
      <div class="sv-card-head">
        <div class="sv-card-icon" style="background:#f0f4ff;color:#0078d4">
          <i class="bi bi-microsoft"></i>
        </div>
        <div>
          <div class="sv-card-title">Microsoft 365</div>
          <div class="sv-card-sub">Outlook, Teams, OneDrive</div>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Login M365</div>
        <div class="sv-field-val">
          <?= h($m365_login) ?>
          <button class="sv-copy-btn" onclick="copyText(<?= json_encode($m365_login) ?>, this)" title="Kopiuj">
            <i class="bi bi-copy"></i>
          </button>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Status</div>
        <div><span class="sv-badge-ok"><i class="bi bi-check-circle-fill"></i> Aktywne</span></div>
      </div>
      <?php if (!empty($u_db['m365_security_group_name'])): ?>
      <div class="sv-field">
        <div class="sv-field-lbl">Grupa dostępu</div>
        <div class="sv-field-val" style="font-family:system-ui"><?= h($u_db['m365_security_group_name']) ?></div>
      </div>
      <?php endif; ?>
      <div class="sv-card-footer">
        <a href="https://portal.office.com" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right"></i> Otwórz portal.office.com
        </a>
      </div>
    </div>
    <?php elseif (!empty($u_db['microsoft_id'])): ?>
    <!-- Ma konto M365 ale bez loginu -->
    <div class="sv-card">
      <div class="sv-card-head">
        <div class="sv-card-icon" style="background:#f0f4ff;color:#0078d4">
          <i class="bi bi-microsoft"></i>
        </div>
        <div>
          <div class="sv-card-title">Microsoft 365</div>
          <div class="sv-card-sub">Outlook, Teams, OneDrive</div>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Status</div>
        <div><span class="sv-badge-ok"><i class="bi bi-check-circle-fill"></i> Aktywne</span></div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Login</div>
        <div class="sv-field-val" style="font-family:system-ui;font-size:.82rem;color:#64748b">
          Użyj swojego adresu e-mail: <?= h($email) ?>
        </div>
      </div>
      <div class="sv-card-footer">
        <a href="https://portal.office.com" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right"></i> Otwórz portal.office.com
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. Moodle (jeśli skonfigurowane) -->
    <?php if ($moodle_url): ?>
    <div class="sv-card">
      <div class="sv-card-head">
        <div class="sv-card-icon" style="background:#fff3e0;color:#f57c00">
          <i class="bi bi-mortarboard-fill"></i>
        </div>
        <div>
          <div class="sv-card-title">Platforma e-learningowa</div>
          <div class="sv-card-sub">Moodle — kursy i szkolenia</div>
        </div>
      </div>
      <?php if ($moodle_login): ?>
      <div class="sv-field">
        <div class="sv-field-lbl">Login Moodle</div>
        <div class="sv-field-val">
          <?= h($moodle_login) ?>
          <button class="sv-copy-btn" onclick="copyText(<?= json_encode($moodle_login) ?>, this)" title="Kopiuj">
            <i class="bi bi-copy"></i>
          </button>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Status</div>
        <div><span class="sv-badge-ok"><i class="bi bi-check-circle-fill"></i> Konto aktywne</span></div>
      </div>
      <?php else: ?>
      <div class="sv-field">
        <div class="sv-field-lbl">Login</div>
        <div class="sv-field-val" style="font-family:system-ui;font-size:.82rem;color:#64748b">
          Użyj swojego adresu e-mail: <?= h($email) ?>
        </div>
      </div>
      <div class="sv-field">
        <div class="sv-field-lbl">Status</div>
        <div><span class="sv-badge-none"><i class="bi bi-clock"></i> Oczekuje na synchronizację</span></div>
      </div>
      <?php endif; ?>
      <div class="sv-field">
        <div class="sv-field-lbl">Adres platformy</div>
        <div class="sv-field-val"><a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener"><?= h(parse_url($moodle_url, PHP_URL_HOST)) ?></a></div>
      </div>
      <div class="sv-card-footer">
        <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right"></i> Otwórz platformę Moodle
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /sv-platforms -->

  <!-- ── Zadania ─────────────────────────────────────────────── -->
  <div class="sv-tasks-bar">
    <div>
      <div class="sv-tasks-num"><?= $task_count ?></div>
      <div class="sv-tasks-lbl">aktywnych zadań</div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($notif_count): ?>
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-bell-fill me-1"></i><?= $notif_count ?> nowych powiadomień
      </a>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-primary">
        <i class="bi bi-check2-square me-1"></i>Moje zadania
      </a>
    </div>
  </div>

  <!-- ── Szybkie linki ───────────────────────────────────────── -->
  <div class="row g-2">
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/tasks/index.php" class="sv-quick">
        <i class="bi bi-check2-square" style="color:var(--vol-color)"></i>
        <div><div class="fw-semibold">Zadania</div><div class="text-muted small">Przypisane projekty</div></div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="sv-quick">
        <i class="bi bi-megaphone-fill text-warning"></i>
        <div><div class="fw-semibold">Ogłoszenia</div><div class="text-muted small">Komunikaty organizacji</div></div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/panel/password.php" class="sv-quick">
        <i class="bi bi-key-fill text-secondary"></i>
        <div><div class="fw-semibold">Hasło</div><div class="text-muted small">Zmień hasło</div></div>
      </a>
    </div>
    <?php if ($moodle_url): ?>
    <div class="col-6 col-md-3">
      <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="sv-quick">
        <i class="bi bi-mortarboard-fill" style="color:#f57c00"></i>
        <div><div class="fw-semibold">Kursy</div><div class="text-muted small">Platforma Moodle</div></div>
      </a>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(function() {
    var i = btn.querySelector('i');
    i.className = 'bi bi-check-lg';
    btn.style.color = '#16a34a';
    setTimeout(function() {
      i.className = 'bi bi-copy';
      btn.style.color = '';
    }, 1800);
  });
}
</script>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
