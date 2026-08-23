<?php
/**
 * panel/standalone.php — Panel wolontariusza bez umowy.
 *
 * Pokazuje dane logowania do platform (portal, M365, Moodle), podsumowanie
 * zadań i skróty. Układ i komponenty jak w module „Tożsamość"
 * (tozsamosc/_head.php): nagłówek .tz-h, karta .tz-card z wierszami usług
 * .tz-svc, kafelki .tz-tile — style z panel/includes/pv_styles.php.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once __DIR__ . '/../includes/webmail_clients.php';
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

  <div class="tz-h">
    <div>
      <h1><?= h($greeting) ?>, <?= h($first_name) ?></h1>
      <p>
        <?= h($org_name) ?>
        <?php if ($org_unit_name): ?> · <?= h($org_unit_name) ?><?php endif; ?>
        · <?= date('d.m.Y') ?>
      </p>
    </div>
    <a href="<?= APP_URL ?>/tasks/index.php" class="tz-btn">
      <i class="bi bi-check2-square" aria-hidden="true"></i>Moje zadania
      <?php if ($task_count): ?><span class="tz-badge tz-badge--off" style="background:rgba(255,255,255,.22);color:#fff;border-color:transparent"><?= $task_count ?></span><?php endif; ?>
    </a>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <!-- ── Konta i dostępy ─────────────────────────────────────── -->
  <section class="tz-card" aria-labelledby="sa-accounts">
    <div class="tz-card__hd" id="sa-accounts">
      <i class="bi bi-key-fill" aria-hidden="true"></i>Twoje konta i dostępy
    </div>
    <div class="tz-card__bd">

      <!-- 1. Portal wolontariusza -->
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-house-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Portal wolontariusza</div>
          <div class="tz-kv">
            Login (e-mail): <code><?= h($email) ?></code>
            <button class="tz-copy" type="button" data-copy="<?= h($email) ?>"
                    aria-label="Kopiuj adres e-mail do schowka">
              <i class="bi bi-copy" aria-hidden="true"></i>
            </button>
          </div>
          <div class="tz-kv"><?= h(parse_url(APP_URL, PHP_URL_HOST)) ?></div>
          <div class="tz-svc__foot">
            <a href="<?= APP_URL ?>/panel/password.php">
              <i class="bi bi-key" aria-hidden="true"></i>Zmień hasło
            </a>
          </div>
        </div>
        <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span>
      </div>

      <!-- 2. Microsoft 365 -->
      <?php if ($m365_login || !empty($u_db['microsoft_id'])): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365</div>
          <div class="tz-kv">Outlook, Teams, OneDrive</div>
          <?php if ($m365_login): ?>
          <div class="tz-kv">
            Login: <code><?= h($m365_login) ?></code>
            <button class="tz-copy" type="button" data-copy="<?= h($m365_login) ?>"
                    aria-label="Kopiuj login Microsoft 365 do schowka">
              <i class="bi bi-copy" aria-hidden="true"></i>
            </button>
          </div>
          <?php else: ?>
          <div class="tz-kv">Login: <code><?= h($email) ?></code> <span>(Twój adres e-mail)</span></div>
          <?php endif; ?>
          <?php if (!empty($u_db['m365_security_group_name'])): ?>
          <div class="tz-kv">Grupa dostępu: <code><?= h($u_db['m365_security_group_name']) ?></code></div>
          <?php endif; ?>
          <div class="tz-svc__foot d-flex flex-wrap gap-3">
            <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener"
               aria-label="Otwórz pocztę — <?= h(webmail_chooser_label()) ?> (nowa karta)">
              <i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta: <?= h(webmail_chooser_label()) ?>
            </a>
            <a href="https://portal.office.com" target="_blank" rel="noopener" class="text-muted"
               aria-label="Otwórz portal Microsoft 365 (nowa karta) — hasło i aplikacje">
              <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)
            </a>
          </div>
        </div>
        <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span>
      </div>
      <?php endif; ?>

      <!-- 3. Moodle -->
      <?php if ($moodle_url): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Platforma e-learningowa</div>
          <div class="tz-kv">Moodle — kursy i szkolenia</div>
          <?php if ($moodle_login): ?>
          <div class="tz-kv">
            Login: <code><?= h($moodle_login) ?></code>
            <button class="tz-copy" type="button" data-copy="<?= h($moodle_login) ?>"
                    aria-label="Kopiuj login Moodle do schowka">
              <i class="bi bi-copy" aria-hidden="true"></i>
            </button>
          </div>
          <?php else: ?>
          <div class="tz-kv">Login: <code><?= h($email) ?></code> <span>(Twój adres e-mail)</span></div>
          <?php endif; ?>
          <div class="tz-svc__foot">
            <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener"
               aria-label="Otwórz platformę Moodle (nowa karta)">
              <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><?= h(parse_url($moodle_url, PHP_URL_HOST)) ?>
            </a>
          </div>
        </div>
        <?php if ($moodle_login): ?>
        <span class="tz-badge tz-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span>
        <?php else: ?>
        <span class="tz-badge tz-badge--wait"><i class="bi bi-clock" aria-hidden="true"></i>Oczekuje na synchronizację</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
    <div class="tz-note" style="margin:0;border:0;border-top:1px solid var(--tz-line);border-radius:0">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Hasło do portalu zmieniasz w Ustawieniach konta. Jeśli któryś dostęp nie działa, napisz do administratora.</span>
    </div>
  </section>

  <!-- ── Skróty ──────────────────────────────────────────────── -->
  <h2 class="tz-section-h">Skróty</h2>
  <nav class="tz-tiles" aria-label="Szybkie linki">

    <a href="<?= APP_URL ?>/tasks/index.php" class="tz-tile">
      <?php if ($task_count): ?><span class="tz-tile__badge" aria-hidden="true"><?= $task_count ?></span><?php endif; ?>
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-check2-square"></i></span>
      <span class="tz-tile__ttl">Zadania</span>
      <span class="tz-tile__sub"><?= $task_count ? $task_count . ' aktywnych' : 'Przypisane projekty' ?></span>
    </a>

    <a href="<?= APP_URL ?>/komunikaty/index.php" class="tz-tile">
      <?php if ($notif_count): ?><span class="tz-tile__badge" aria-hidden="true"><?= $notif_count ?></span><?php endif; ?>
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
      <span class="tz-tile__ttl">Ogłoszenia</span>
      <span class="tz-tile__sub"><?= $notif_count ? $notif_count . ' nowych' : 'Komunikaty organizacji' ?></span>
    </a>

    <a href="<?= APP_URL ?>/panel/password.php" class="tz-tile">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-key-fill"></i></span>
      <span class="tz-tile__ttl">Ustawienia konta</span>
      <span class="tz-tile__sub">Hasło, telefon</span>
    </a>

    <?php if ($moodle_url): ?>
    <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="tz-tile"
       aria-label="Kursy — otwórz platformę Moodle (nowa karta)">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
      <span class="tz-tile__ttl">Kursy</span>
      <span class="tz-tile__sub">Platforma Moodle</span>
    </a>
    <?php endif; ?>

    <?php if (module_enabled('procedures_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/procedures.php" class="tz-tile">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
      <span class="tz-tile__ttl">Procedury</span>
      <span class="tz-tile__sub">Dokumenty i instrukcje</span>
    </a>
    <?php endif; ?>

    <?php if (module_enabled('org_documents_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/org_documents.php" class="tz-tile">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-folder2-open"></i></span>
      <span class="tz-tile__ttl">Dokumenty organizacji</span>
      <span class="tz-tile__sub">Statut, regulaminy, wzory</span>
    </a>
    <?php endif; ?>

    <?php if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')): ?>
    <a href="<?= APP_URL ?>/panel/whatsapp_group.php" class="tz-tile">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
      <span class="tz-tile__ttl">WhatsApp — grupa</span>
      <span class="tz-tile__sub">Dołącz do grupy</span>
    </a>
    <?php endif; ?>

    <?php if (module_enabled('helpdesk_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/helpdesk.php" class="tz-tile">
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi bi-headset"></i></span>
      <span class="tz-tile__ttl">Helpdesk IT</span>
      <span class="tz-tile__sub">Zgłoś problem</span>
    </a>
    <?php endif; ?>

  </nav>

</div><!-- /pv-wrap -->

<script>
/* Kopiowanie loginów — te same przyciski .tz-copy co w module Tożsamość */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.tz-copy[data-copy]');
  if (!btn) return;
  navigator.clipboard.writeText(btn.dataset.copy).then(function () {
    var i = btn.querySelector('i');
    if (!i) return;
    i.className = 'bi bi-check-lg';
    btn.style.color = '#16a34a';
    setTimeout(function () { i.className = 'bi bi-copy'; btn.style.color = ''; }, 1800);
  });
});
</script>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
