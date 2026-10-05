<?php
/**
 * panel/standalone.php — Panel wolontariusza bez umowy.
 *
 * Pokazuje dane logowania do platform (portal, M365), podsumowanie
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
require_once dirname(__DIR__) . '/includes/notifications.php';

require_login();
$user    = current_user();
$user_id = (int)$user['id'];

// Pobierz pełne dane ze świeżymi kolumnami (m365_login, org_unit)
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

// Liczniki do kafli (część liczy już powłoka: $_hd_open, $_osw_pending, $_gc_pending — bierzemy, gdy są)
include __DIR__ . '/includes/header_panel.php';
$hd_open  = (int)($_hd_open ?? 0);
$osw_cnt  = is_array($_osw_pending ?? null) ? count($_osw_pending) : 0;
$gc_cnt   = (int)($_gc_pending ?? 0);
$has_m365 = $m365_login || !empty($u_db['microsoft_id']);
$app_host = parse_url(APP_URL, PHP_URL_HOST) ?: APP_URL;
?>
<style type="text/tailwindcss">
/* ── Panel wolontariusza bez umowy — kafle (Tailwind bez preflightu: przy tw-border zawsze tw-border-solid).
   Kolory przez tokeny motywu (--tz-*, --vol-*), więc działają tryby jasny / ciemny / wysoki kontrast. ── */
.sa-card    { @apply tw-rounded-2xl tw-border tw-border-solid tw-shadow-sm tw-overflow-hidden; background: var(--tz-bg); border-color: var(--tz-line); }
.sa-card-hd { @apply tw-flex tw-items-center tw-gap-2 tw-px-5 tw-py-3.5 tw-border-b tw-border-solid tw-text-[.95rem] tw-font-bold; border-color: var(--tz-line); color: var(--tz-ink); }
.sa-card-hd i { color: var(--tz); }
.sa-kpi     { @apply tw-flex tw-items-center tw-gap-3 tw-rounded-2xl tw-border tw-border-solid tw-p-4 tw-no-underline tw-transition hover:tw--translate-y-0.5 hover:tw-shadow-md focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; background: var(--tz-bg); border-color: var(--tz-line); color: var(--tz-ink); }
.sa-kpi:hover { border-color: var(--tz); color: var(--tz-ink); }
.sa-kpi-ico { @apply tw-w-11 tw-h-11 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-xl tw-shrink-0; background: var(--tz-50); color: var(--tz-strong); }
.sa-kpi-num { @apply tw-text-2xl tw-font-bold tw-leading-none tw-tracking-tight; color: var(--tz-ink); }
.sa-kpi-lbl { @apply tw-text-xs tw-mt-0.5; color: var(--tz-muted); }
.sa-tile    { @apply tw-relative tw-flex tw-flex-col tw-gap-1 tw-rounded-2xl tw-border tw-border-solid tw-p-4 tw-min-h-[118px] tw-no-underline tw-transition hover:tw--translate-y-0.5 hover:tw-shadow-md focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; background: var(--tz-bg); border-color: var(--tz-line); color: var(--tz-ink); }
.sa-tile:hover { border-color: var(--tz); color: var(--tz-ink); }
.sa-tile-ico { @apply tw-w-10 tw-h-10 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-lg tw-mb-2; background: var(--tz); color: #fff; }
.sa-tile-ttl { @apply tw-text-[.9rem] tw-font-bold tw-leading-tight; }
.sa-tile-sub { @apply tw-text-xs; color: var(--tz-muted); }
.sa-tile-badge { @apply tw-absolute tw-top-3 tw-right-3 tw-min-w-[22px] tw-h-[22px] tw-px-1.5 tw-rounded-full tw-bg-rose-600 tw-text-white tw-text-[.7rem] tw-font-bold tw-flex tw-items-center tw-justify-center; }
.sa-acc     { @apply tw-flex tw-flex-col tw-gap-2 tw-rounded-2xl tw-border tw-border-solid tw-p-4; background: var(--tz-bg); border-color: var(--tz-line); }
.sa-acc-ico { @apply tw-w-11 tw-h-11 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-xl tw-shrink-0; background: var(--tz-50); color: var(--tz-strong); }
.sa-kv      { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-1.5 tw-text-[.85rem]; color: var(--tz-muted); }
.sa-kv code { @apply tw-rounded-md tw-px-1.5 tw-py-0.5 tw-text-[.84rem] tw-break-all; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--tz-ink); background: var(--tz-50); }
.sa-link    { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-text-[.83rem] tw-font-semibold tw-no-underline hover:tw-underline; color: var(--tz-strong); }
.sa-link--muted { color: var(--tz-muted); }
.sa-badge   { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[.7rem] tw-font-semibold tw-border tw-border-solid tw-whitespace-nowrap; }
.sa-badge--ok { @apply tw-bg-emerald-50 tw-text-emerald-700 tw-border-emerald-200; }
.sa-badge--warn { @apply tw-bg-amber-50 tw-text-amber-700 tw-border-amber-200; }
.sa-btn     { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-xl tw-px-4 tw-py-2.5 tw-text-[.88rem] tw-font-semibold tw-no-underline tw-border-0 tw-cursor-pointer tw-transition tw-shadow-sm focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-offset-2 focus-visible:tw-outline-amber-400; background: var(--tz-strong); color: #fff; }
.sa-btn:hover { filter: brightness(.94); color: #fff; }
.sa-btn--ghost { @apply tw-shadow-none tw-border tw-border-solid; background: var(--tz-bg); color: var(--tz-strong); border-color: var(--tz-line); }
.sa-btn--ghost:hover { background: var(--tz-50); filter: none; }
.sa-eyebrow { @apply tw-text-[.68rem] tw-font-bold tw-uppercase tw-tracking-[.08em]; color: var(--tz-muted); }
.sa-note    { @apply tw-flex tw-items-start tw-gap-2 tw-rounded-2xl tw-border tw-border-solid tw-px-4 tw-py-3 tw-text-[.84rem]; background: var(--tz-bg-page); border-color: var(--tz-line); color: var(--tz-muted); }
.sa-note i  { color: var(--tz); margin-top: .1rem; }
.tz-copy    { @apply tw-bg-transparent tw-border-0 tw-rounded-md tw-px-1.5 tw-py-0.5 tw-cursor-pointer tw-leading-none; color: var(--tz-muted); }
.tz-copy:hover { color: var(--tz); background: var(--tz-50); }
[data-theme="hc"] .sa-card, [data-theme="hc"] .sa-kpi, [data-theme="hc"] .sa-tile, [data-theme="hc"] .sa-acc, [data-theme="hc"] .sa-note { border-width: 2px; border-color: #000; box-shadow: none; }
[data-theme="hc"] .sa-tile:hover, [data-theme="hc"] .sa-kpi:hover { background: #000; color: #fff; }
[data-theme="hc"] .sa-tile:hover .sa-tile-sub, [data-theme="hc"] .sa-kpi:hover .sa-kpi-lbl, [data-theme="hc"] .sa-kpi:hover .sa-kpi-num { color: #fff; }
[data-theme="hc"] .sa-btn { border: 2px solid #000; }
</style>

<div class="pv-wrap">

  <!-- ═══════════ POWITANIE ═══════════ -->
  <div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-4">
    <div>
      <div class="sa-eyebrow tw-mb-1"><?= h($org_name) ?><?php if ($org_unit_name): ?> · <?= h($org_unit_name) ?><?php endif; ?> · <?= date('d.m.Y') ?></div>
      <h1 class="tw-m-0 tw-text-[1.45rem] tw-font-bold tw-tracking-tight" style="color:var(--tz-ink)"><?= h($greeting) ?>, <?= h($first_name) ?> 👋</h1>
      <p class="tw-m-0 tw-mt-0.5 tw-text-sm" style="color:var(--tz-muted)">
        <?php if ($task_count): ?>Masz <strong style="color:var(--tz-ink)"><?= $task_count ?></strong> <?= $task_count === 1 ? 'aktywne zadanie' : ($task_count < 5 ? 'aktywne zadania' : 'aktywnych zadań') ?><?= $notif_count ? ' i <strong style="color:var(--tz-ink)">' . $notif_count . '</strong> ' . ($notif_count === 1 ? 'nowe powiadomienie' : 'nowych powiadomień') : '' ?>.<?php else: ?>Brak nowych zadań — wszystko na bieżąco.<?php endif; ?>
      </p>
    </div>
    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
      <a href="<?= APP_URL ?>/tasks/index.php" class="sa-btn"><i class="bi bi-check2-square" aria-hidden="true"></i>Moje zadania<?php if ($task_count): ?> <span class="tw-rounded-full tw-bg-white/25 tw-px-1.5 tw-text-[.72rem]"><?= $task_count ?></span><?php endif; ?></a>
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="sa-btn sa-btn--ghost"><i class="bi bi-megaphone" aria-hidden="true"></i>Komunikaty<?php if ($notif_count): ?> <span class="tw-rounded-full tw-bg-rose-600 tw-text-white tw-px-1.5 tw-text-[.72rem]"><?= $notif_count ?></span><?php endif; ?></a>
    </div>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <!-- ═══════════ KPI ═══════════ -->
  <div class="tw-grid tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-3 tw-mb-5" role="list" aria-label="Twoje liczby">
    <a href="<?= APP_URL ?>/tasks/index.php" class="sa-kpi" role="listitem">
      <span class="sa-kpi-ico" aria-hidden="true"><i class="bi bi-check2-square"></i></span>
      <span><span class="sa-kpi-num"><?= $task_count ?></span><span class="sa-kpi-lbl tw-block"><?= $task_count === 1 ? 'aktywne zadanie' : 'aktywnych zadań' ?></span></span>
    </a>
    <a href="<?= APP_URL ?>/komunikaty/index.php" class="sa-kpi" role="listitem">
      <span class="sa-kpi-ico" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
      <span><span class="sa-kpi-num"><?= $notif_count ?></span><span class="sa-kpi-lbl tw-block">nowych komunikatów</span></span>
    </a>
    <?php if (module_enabled('helpdesk_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/helpdesk.php" class="sa-kpi" role="listitem">
      <span class="sa-kpi-ico" aria-hidden="true"><i class="bi bi-headset"></i></span>
      <span><span class="sa-kpi-num"><?= $hd_open ?></span><span class="sa-kpi-lbl tw-block">otwartych zgłoszeń IT</span></span>
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="sa-kpi" role="listitem">
      <span class="sa-kpi-ico" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
      <span><span class="sa-kpi-num"><?= $has_m365 ? 2 : 1 ?></span><span class="sa-kpi-lbl tw-block"><?= $has_m365 ? 'konta: portal + M365' : 'konto: portal'; ?></span></span>
    </a>
  </div>

  <!-- ═══════════ KONTA I DOSTĘPY ═══════════ -->
  <section class="sa-card tw-mb-5" aria-labelledby="sa-accounts">
    <div class="sa-card-hd" id="sa-accounts"><i class="bi bi-key-fill" aria-hidden="true"></i>Twoje konta i dostępy</div>
    <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-3 tw-p-4">

      <!-- 1. Portal wolontariusza -->
      <article class="sa-acc">
        <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
          <div class="tw-flex tw-items-center tw-gap-3">
            <span class="sa-acc-ico" aria-hidden="true"><i class="bi bi-house-fill"></i></span>
            <div><div class="tw-font-bold tw-text-[.95rem]" style="color:var(--tz-ink)">Portal wolontariusza</div><div class="sa-kv"><?= h($app_host) ?></div></div>
          </div>
          <span class="sa-badge sa-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span>
        </div>
        <div class="sa-kv">Login (e-mail): <code><?= h($email) ?></code>
          <button class="tz-copy" type="button" data-copy="<?= h($email) ?>" aria-label="Kopiuj adres e-mail do schowka"><i class="bi bi-copy" aria-hidden="true"></i></button>
        </div>
        <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mt-1">
          <a href="<?= APP_URL ?>/panel/password.php" class="sa-link"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a>
          <a href="<?= APP_URL ?>/tozsamosc/index.php" class="sa-link sa-link--muted"><i class="bi bi-person-vcard" aria-hidden="true"></i>System Tożsamości</a>
        </div>
      </article>

      <!-- 2. Microsoft 365 -->
      <?php if ($has_m365): ?>
      <article class="sa-acc">
        <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
          <div class="tw-flex tw-items-center tw-gap-3">
            <span class="sa-acc-ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
            <div><div class="tw-font-bold tw-text-[.95rem]" style="color:var(--tz-ink)">Microsoft 365</div><div class="sa-kv">Outlook, Teams, OneDrive</div></div>
          </div>
          <span class="sa-badge sa-badge--ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span>
        </div>
        <?php if ($m365_login): ?>
        <div class="sa-kv">Login: <code><?= h($m365_login) ?></code>
          <button class="tz-copy" type="button" data-copy="<?= h($m365_login) ?>" aria-label="Kopiuj login Microsoft 365 do schowka"><i class="bi bi-copy" aria-hidden="true"></i></button>
        </div>
        <?php else: ?>
        <div class="sa-kv">Login: <code><?= h($email) ?></code> <span>(Twój adres e-mail)</span></div>
        <?php endif; ?>
        <?php if (!empty($u_db['m365_security_group_name'])): ?>
        <div class="sa-kv">Grupa dostępu: <code><?= h($u_db['m365_security_group_name']) ?></code></div>
        <?php endif; ?>
        <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mt-1">
          <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener" class="sa-link" aria-label="Otwórz pocztę — <?= h(webmail_chooser_label()) ?> (nowa karta)"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta: <?= h(webmail_chooser_label()) ?></a>
          <a href="https://portal.office.com" target="_blank" rel="noopener" class="sa-link sa-link--muted" aria-label="Otwórz portal Microsoft 365 (nowa karta) — hasło i aplikacje"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)</a>
        </div>
      </article>
      <?php else: ?>
      <article class="sa-acc tw-opacity-80">
        <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
          <div class="tw-flex tw-items-center tw-gap-3">
            <span class="sa-acc-ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
            <div><div class="tw-font-bold tw-text-[.95rem]" style="color:var(--tz-ink)">Microsoft 365</div><div class="sa-kv">Konto nie zostało jeszcze założone</div></div>
          </div>
          <span class="sa-badge sa-badge--warn"><i class="bi bi-dash-circle" aria-hidden="true"></i>Brak konta</span>
        </div>
        <div class="sa-kv">Jeśli potrzebujesz poczty organizacji lub Teams, napisz do administratora.</div>
      </article>
      <?php endif; ?>
    </div>
    <div class="sa-note tw-rounded-none tw-border-0 tw-border-t" style="border-color:var(--tz-line)">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Hasło do portalu zmieniasz w Ustawieniach konta. Jeśli któryś dostęp nie działa, napisz do administratora.</span>
    </div>
  </section>

  <!-- ═══════════ SKRÓTY ═══════════ -->
  <h2 class="sa-eyebrow tw-mb-2">Skróty</h2>
  <nav class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 xl:tw-grid-cols-4 tw-gap-3 tw-mb-5" aria-label="Szybkie linki">
    <a href="<?= APP_URL ?>/tasks/index.php" class="sa-tile">
      <?php if ($task_count): ?><span class="sa-tile-badge" aria-hidden="true"><?= $task_count ?></span><?php endif; ?>
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-check2-square"></i></span>
      <span class="sa-tile-ttl">Zadania</span>
      <span class="sa-tile-sub"><?= $task_count ? $task_count . ' aktywnych' : 'Przypisane projekty' ?></span>
    </a>
    <a href="<?= APP_URL ?>/komunikaty/index.php" class="sa-tile">
      <?php if ($notif_count): ?><span class="sa-tile-badge" aria-hidden="true"><?= $notif_count ?></span><?php endif; ?>
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
      <span class="sa-tile-ttl">Ogłoszenia</span>
      <span class="sa-tile-sub"><?= $notif_count ? $notif_count . ' nowych' : 'Komunikaty organizacji' ?></span>
    </a>
    <a href="<?= APP_URL ?>/panel/password.php" class="sa-tile">
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-key-fill"></i></span>
      <span class="sa-tile-ttl">Ustawienia konta</span>
      <span class="sa-tile-sub">Hasło, telefon</span>
    </a>
    <?php if (module_enabled('oswiadczenia_enabled')): ?>
    <a href="<?= APP_URL ?>/oswiadczenia/index.php" class="sa-tile">
      <?php if ($osw_cnt): ?><span class="sa-tile-badge" aria-hidden="true"><?= $osw_cnt ?></span><?php endif; ?>
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-file-earmark-check"></i></span>
      <span class="sa-tile-ttl">Oświadczenia</span>
      <span class="sa-tile-sub"><?= $osw_cnt ? $osw_cnt . ' do podpisania' : 'Podpisane i wymagane' ?></span>
    </a>
    <?php endif; ?>
    <?php if ($gc_cnt): ?>
    <a href="<?= APP_URL ?>/panel/zgody.php" class="sa-tile">
      <span class="sa-tile-badge" aria-hidden="true"><?= $gc_cnt ?></span>
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
      <span class="sa-tile-ttl">Zgody i oświadczenia</span>
      <span class="sa-tile-sub"><?= $gc_cnt ?> do odnowienia</span>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('procedures_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/procedures.php" class="sa-tile">
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
      <span class="sa-tile-ttl">Procedury</span>
      <span class="sa-tile-sub">Dokumenty i instrukcje</span>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('org_documents_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/org_documents.php" class="sa-tile">
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-folder2-open"></i></span>
      <span class="sa-tile-ttl">Dokumenty organizacji</span>
      <span class="sa-tile-sub">Statut, regulaminy, wzory</span>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')): ?>
    <a href="<?= APP_URL ?>/panel/whatsapp_group.php" class="sa-tile">
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
      <span class="sa-tile-ttl">WhatsApp — grupa</span>
      <span class="sa-tile-sub">Dołącz do grupy</span>
    </a>
    <?php endif; ?>
    <?php if (module_enabled('helpdesk_enabled')): ?>
    <a href="<?= APP_URL ?>/panel/helpdesk.php" class="sa-tile">
      <?php if ($hd_open): ?><span class="sa-tile-badge" aria-hidden="true"><?= $hd_open ?></span><?php endif; ?>
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-headset"></i></span>
      <span class="sa-tile-ttl">Helpdesk IT</span>
      <span class="sa-tile-sub"><?= $hd_open ? $hd_open . ' otwartych zgłoszeń' : 'Zgłoś problem' ?></span>
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/org_intro/panel_guide.php" class="sa-tile">
      <span class="sa-tile-ico" aria-hidden="true"><i class="bi bi-book"></i></span>
      <span class="sa-tile-ttl">Przewodnik po panelu</span>
      <span class="sa-tile-sub">Jak korzystać z systemu</span>
    </a>
  </nav>

</div><!-- /pv-wrap -->

<script>
/* Kopiowanie loginów — te same przyciski .tz-copy co w module Tożsamość */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.tz-copy[data-copy]');
  if (!btn || !navigator.clipboard) return;
  navigator.clipboard.writeText(btn.dataset.copy).then(function () {
    var i = btn.querySelector('i');
    if (!i) return;
    i.className = 'bi bi-check-lg';
    btn.style.color = '#16a34a';
    var live = document.getElementById('pv-live'); if (live) live.textContent = 'Skopiowano do schowka.';
    setTimeout(function () { i.className = 'bi bi-copy'; btn.style.color = ''; }, 1800);
  });
});
</script>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
