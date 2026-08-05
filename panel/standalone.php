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

<div class="pv-wrap">

  <div class="pv-page-header">
    <a href="<?= APP_URL ?>/auth/logout.php" class="pv-page-back" aria-label="Wyloguj się">
      <i class="bi bi-power" aria-hidden="true"></i>
    </a>
    <h1 class="pv-page-title"><?= h($greeting) ?>, <?= h($first_name) ?> 👋</h1>
    <p class="pv-page-sub">
      <?= h($org_name) ?>
      <?php if ($org_unit_name): ?> · <?= h($org_unit_name) ?><?php endif; ?>
    </p>
  </div>

  <!-- ── Karty platform ──────────────────────────────────────── -->
  <div class="row g-3 mb-4">

    <!-- 1. Portal feerSZO -->
    <div class="col-sm-6 col-xl-4">
      <div class="tz-card h-100">
        <div class="tz-card__hd">
          <div class="tz-card__icon" style="background:var(--vol-bg);color:var(--vol-color)" aria-hidden="true">
            <i class="bi bi-house-fill"></i>
          </div>
          <div>
            <div class="tz-card__title">Portal wolontariusza</div>
            <div class="tz-card__sub"><?= h(parse_url(APP_URL, PHP_URL_HOST)) ?></div>
          </div>
        </div>
        <div class="tz-card__bd">
          <div class="pv-field">
            <div class="pv-field__lbl">Login (e-mail)</div>
            <div class="pv-field__val">
              <?= h($email) ?>
              <button class="pv-copy-btn" type="button"
                      onclick="copyText(<?= json_encode($email) ?>, this)"
                      aria-label="Kopiuj adres e-mail do schowka">
                <i class="bi bi-copy" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <div class="pv-field">
            <div class="pv-field__lbl">Adres platformy</div>
            <div class="pv-field__val">
              <a href="<?= h(APP_URL) ?>/auth/login.php" target="_blank" rel="noopener"
                 aria-label="Otwórz portal <?= h(parse_url(APP_URL, PHP_URL_HOST)) ?> (nowa karta)">
                <?= h(APP_URL) ?>
              </a>
            </div>
          </div>
        </div>
        <div class="tz-card__ft">
          <a href="<?= APP_URL ?>/panel/password.php" class="tz-card__link">
            <i class="bi bi-key" aria-hidden="true"></i> Zmień hasło
          </a>
        </div>
      </div>
    </div>

    <!-- 2. Microsoft 365 (jeśli jest login) -->
    <?php if ($m365_login): ?>
    <div class="col-sm-6 col-xl-4">
      <div class="tz-card h-100">
        <div class="tz-card__hd">
          <div class="tz-card__icon bg-primary bg-opacity-10 text-primary" aria-hidden="true">
            <i class="bi bi-microsoft"></i>
          </div>
          <div>
            <div class="tz-card__title">Microsoft 365</div>
            <div class="tz-card__sub">Outlook, Teams, OneDrive</div>
          </div>
        </div>
        <div class="tz-card__bd">
          <div class="pv-field">
            <div class="pv-field__lbl">Login M365</div>
            <div class="pv-field__val">
              <?= h($m365_login) ?>
              <button class="pv-copy-btn" type="button"
                      onclick="copyText(<?= json_encode($m365_login) ?>, this)"
                      aria-label="Kopiuj login M365 do schowka">
                <i class="bi bi-copy" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <div class="pv-field">
            <div class="pv-field__lbl">Status</div>
            <div>
              <span class="tz-badge tz-badge--success">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywne
              </span>
            </div>
          </div>
          <?php if (!empty($u_db['m365_security_group_name'])): ?>
          <div class="pv-field">
            <div class="pv-field__lbl">Grupa dostępu</div>
            <div class="pv-field__val"><?= h($u_db['m365_security_group_name']) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <div class="tz-card__ft">
          <a href="https://portal.office.com" target="_blank" rel="noopener" class="tz-card__link"
             aria-label="Otwórz portal.office.com (nowa karta)">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz portal.office.com
          </a>
        </div>
      </div>
    </div>
    <?php elseif (!empty($u_db['microsoft_id'])): ?>
    <!-- Ma konto M365 ale bez loginu -->
    <div class="col-sm-6 col-xl-4">
      <div class="tz-card h-100">
        <div class="tz-card__hd">
          <div class="tz-card__icon bg-primary bg-opacity-10 text-primary" aria-hidden="true">
            <i class="bi bi-microsoft"></i>
          </div>
          <div>
            <div class="tz-card__title">Microsoft 365</div>
            <div class="tz-card__sub">Outlook, Teams, OneDrive</div>
          </div>
        </div>
        <div class="tz-card__bd">
          <div class="pv-field">
            <div class="pv-field__lbl">Status</div>
            <div>
              <span class="tz-badge tz-badge--success">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywne
              </span>
            </div>
          </div>
          <div class="pv-field">
            <div class="pv-field__lbl">Login</div>
            <div class="pv-field__val pv-field__val--muted">
              Użyj swojego adresu e-mail: <?= h($email) ?>
            </div>
          </div>
        </div>
        <div class="tz-card__ft">
          <a href="https://portal.office.com" target="_blank" rel="noopener" class="tz-card__link"
             aria-label="Otwórz portal.office.com (nowa karta)">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz portal.office.com
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. Moodle (jeśli skonfigurowane) -->
    <?php if ($moodle_url): ?>
    <div class="col-sm-6 col-xl-4">
      <div class="tz-card h-100">
        <div class="tz-card__hd">
          <div class="tz-card__icon bg-warning bg-opacity-10 text-warning" aria-hidden="true">
            <i class="bi bi-mortarboard-fill"></i>
          </div>
          <div>
            <div class="tz-card__title">Platforma e-learningowa</div>
            <div class="tz-card__sub">Moodle — kursy i szkolenia</div>
          </div>
        </div>
        <div class="tz-card__bd">
          <?php if ($moodle_login): ?>
          <div class="pv-field">
            <div class="pv-field__lbl">Login Moodle</div>
            <div class="pv-field__val">
              <?= h($moodle_login) ?>
              <button class="pv-copy-btn" type="button"
                      onclick="copyText(<?= json_encode($moodle_login) ?>, this)"
                      aria-label="Kopiuj login Moodle do schowka">
                <i class="bi bi-copy" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <div class="pv-field">
            <div class="pv-field__lbl">Status</div>
            <div>
              <span class="tz-badge tz-badge--success">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Konto aktywne
              </span>
            </div>
          </div>
          <?php else: ?>
          <div class="pv-field">
            <div class="pv-field__lbl">Login</div>
            <div class="pv-field__val pv-field__val--muted">
              Użyj swojego adresu e-mail: <?= h($email) ?>
            </div>
          </div>
          <div class="pv-field">
            <div class="pv-field__lbl">Status</div>
            <div>
              <span class="tz-badge tz-badge--muted">
                <i class="bi bi-clock" aria-hidden="true"></i> Oczekuje na synchronizację
              </span>
            </div>
          </div>
          <?php endif; ?>
          <div class="pv-field">
            <div class="pv-field__lbl">Adres platformy</div>
            <div class="pv-field__val">
              <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener"
                 aria-label="Otwórz platformę Moodle: <?= h(parse_url($moodle_url, PHP_URL_HOST)) ?> (nowa karta)">
                <?= h(parse_url($moodle_url, PHP_URL_HOST)) ?>
              </a>
            </div>
          </div>
        </div>
        <div class="tz-card__ft">
          <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="tz-card__link"
             aria-label="Otwórz platformę Moodle (nowa karta)">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz platformę Moodle
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /row (platform cards) -->

  <!-- ── Zadania ─────────────────────────────────────────────── -->
  <div class="tz-card mb-3" role="region" aria-label="Podsumowanie zadań">
    <div class="tz-card__bd d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div>
        <div class="pv-stat__num" aria-label="<?= $task_count ?> aktywnych zadań"><?= $task_count ?></div>
        <div class="pv-stat__lbl" aria-hidden="true">aktywnych zadań</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($notif_count): ?>
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="tz-btn tz-btn--ghost">
          <i class="bi bi-bell-fill me-1" aria-hidden="true"></i><?= $notif_count ?> nowych powiadomień
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/tasks/index.php" class="tz-btn">
          <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Moje zadania
        </a>
      </div>
    </div>
  </div>

  <!-- ── Szybkie linki ───────────────────────────────────────── -->
  <nav class="row g-2" aria-label="Szybkie linki">
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/tasks/index.php" class="pv-quick">
        <i class="bi bi-check2-square" style="color:var(--vol-color)" aria-hidden="true"></i>
        <div><div class="fw-semibold">Zadania</div><div class="text-muted small">Przypisane projekty</div></div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="pv-quick">
        <i class="bi bi-megaphone-fill text-warning" aria-hidden="true"></i>
        <div><div class="fw-semibold">Ogłoszenia</div><div class="text-muted small">Komunikaty organizacji</div></div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/panel/password.php" class="pv-quick">
        <i class="bi bi-key-fill text-secondary" aria-hidden="true"></i>
        <div><div class="fw-semibold">Hasło</div><div class="text-muted small">Zmień hasło</div></div>
      </a>
    </div>
    <?php if ($moodle_url): ?>
    <div class="col-6 col-md-3">
      <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="pv-quick"
         aria-label="Kursy — otwórz platformę Moodle (nowa karta)">
        <i class="bi bi-mortarboard-fill text-warning" aria-hidden="true"></i>
        <div><div class="fw-semibold">Kursy</div><div class="text-muted small">Platforma Moodle</div></div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (module_enabled('procedures_enabled')): ?>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/panel/procedures.php" class="pv-quick">
        <i class="bi bi-journal-text text-info" aria-hidden="true"></i>
        <div><div class="fw-semibold">Procedury</div><div class="text-muted small">Dokumenty i instrukcje</div></div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (module_enabled('org_documents_enabled')): ?>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/panel/org_documents.php" class="pv-quick">
        <i class="bi bi-folder2-open text-success" aria-hidden="true"></i>
        <div><div class="fw-semibold">Dokumenty organizacji</div><div class="text-muted small">Statut, regulaminy, wzory</div></div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')): ?>
    <div class="col-6 col-md-3">
      <a href="<?= APP_URL ?>/panel/whatsapp_group.php" class="pv-quick">
        <i class="bi bi-whatsapp text-success" aria-hidden="true"></i>
        <div><div class="fw-semibold">WhatsApp — grupa</div><div class="text-muted small">Dołącz do grupy</div></div>
      </a>
    </div>
    <?php endif; ?>
  </nav>

</div><!-- /pv-wrap -->

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
