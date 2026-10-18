<?php
/**
 * panel/includes/pv_staff_home.php — „Mój panel” osoby BEZ umowy (kadra: pracownik, edytor, admin).
 *
 * Zastępuje stary widok edytora w panel/index.php (gdy $_metro_home = false). Szata METRO jak
 * System Tożsamości (modules/panel_metro): nagłówek, „Ważne informacje”, kafle Start
 * (zadania, komunikaty, wiadomości, helpdesk, Microsoft 365, dydaktyka, tożsamość, admin),
 * pod nimi sekcje: Moje zadania, Moje pełnomocnictwa, wydarzenia i rezerwacje.
 * Działa pod powłoką SZO (includes/header.php) — kafle i grupy mają własne style .pvm.
 *
 * Wejście (z index.php): $user, $initials, $msg_unread.
 */
if (!defined('APP_URL')) { return; }
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets('#0f6fa8');

$_sh_uid   = (int)($user['id'] ?? 0);
$_sh_first = explode(' ', trim((string)($user['name'] ?? '')))[0] ?: 'Witaj';
$_sh_admin = ($user['role'] ?? '') === 'admin';

/* Liczniki kafli (każdy w try — brak tabeli/modułu nie psuje pulpitu) */
$_sh_tasks = 0;
if (module_enabled('tasks_enabled')) {
    try {
        $_sh_tasks = (int)(db_one(
            "SELECT COUNT(*) AS c FROM tasks t JOIN task_assignments ta ON ta.task_id=t.id
              WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL", [$_sh_uid])['c'] ?? 0);
    } catch (\Throwable $e) {}
}
$_sh_ann = 0;
try {
    require_once $ROOT . '/includes/notifications.php';
    if (function_exists('notif_unread_count')) $_sh_ann += notif_unread_count($_sh_uid);
    if (function_exists('ann_list_for_user')) {
        $_sh_ann += count(array_filter(ann_list_for_user($_sh_uid, (string)($user['role'] ?? '')), fn($a) => empty($a['is_read_by_me'])));
    }
} catch (\Throwable $e) {}
$_sh_hd = 0;
try {
    $_sh_hd = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [$_sh_uid])['c'] ?? 0);
} catch (\Throwable $e) {}
$_sh_m365 = '';
try {
    $_r = db_one("SELECT m365_login, microsoft_id FROM users WHERE id=?", [$_sh_uid]);
    $_sh_m365 = (string)($_r['m365_login'] ?? '') ?: (!empty($_r['microsoft_id']) ? (string)($user['email'] ?? '') : '');
} catch (\Throwable $e) {}

// Żarcik dla admina (z dawnego widoku) — jako podtytuł.
$_sh_quips = [
    "Znowu tu jesteś? Nie masz nic lepszego do roboty? 😄",
    "Panel współpracownika? Serio? Masz przecież cały admin. 🤭",
    "Hej Szefie! Ten panel nie jest dla Ciebie… ale skoro już tu jesteś. 👋",
    "Witaj, człowieku od wszystkiego. Ten panel jest dla innych, ale rozumiem ciekawość. 🔍",
    "Admin w panelu współpracownika? To jak lekarz w kolejce do siebie. 😅",
    "Sprawdzasz jak wygląda panel od środka? Szpiegowanie własnej aplikacji — klasyk. 🕵️",
    "Tu nic ciekawego dla admina. Ale zadania poniżej — jak najbardziej Twoje! 📋",
];
$_sh_lead = $_sh_admin
    ? $_sh_quips[abs(crc32((string)($user['name'] ?? ''))) % count($_sh_quips)]
    : 'Twoje zadania, komunikaty i dostępy w jednym miejscu. Nie masz umowy współpracy powiązanej z kontem — dlatego nie widzisz tu spraw umownych.';
?>
<style>
.pvm .sh-sec{margin-top:2rem}
.pvm .sh-sec .card{border-radius:0;box-shadow:none!important;border:0}
.pvm .sh-sec .card-header{background:var(--pvm-light);border:0;border-radius:0!important}
.pvm .sh-sec .btn{border-radius:0}
.pvm.sh-wide{max-width:1400px}
.pvm .sh-start{display:grid;gap:1.5rem;grid-template-columns:1fr;align-items:start}
@media(min-width:1100px){.pvm .sh-start{grid-template-columns:minmax(0,1fr) 320px}}
</style>
<div class="pvm sh-wide">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Mój panel · <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · <?= date('d.m.Y') ?></div>
      <h1 class="mt-title">Dzień dobry, <?= h($_sh_first) ?></h1>
      <p class="mt-lead"><?= h($_sh_lead) ?></p>
    </div>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>
  <?php panelMetroImportant($_sh_uid, (string)($user['role'] ?? 'viewer')); ?>

  <div class="sh-start">
  <div>
  <h2 class="visually-hidden">Skróty</h2>
  <div class="mt-grid">
    <a href="<?= APP_URL ?>/tozsamosc/" class="mt-tile mt-tile--l mt-tile--acc" aria-label="<?= h(($user['name'] ?? '') . ' — ' . ($user['email'] ?? '') . ' — System Tożsamości') ?>">
      <span class="ava" aria-hidden="true"><?= h($initials) ?></span>
      <span aria-hidden="true">
        <span class="val" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($user['name'] ?? '') ?></span>
        <span class="lbl"><?= h(function_exists('ctx_role_label') ? ctx_role_label((string)($user['role'] ?? '')) : ($user['role'] ?? '')) ?></span>
        <span class="sub"><?= h($user['email'] ?? '') ?></span>
      </span>
    </a>
    <?php
    if (module_enabled('tasks_enabled')) {
        panelMetroTile(['href'=>APP_URL.'/tasks/index.php','icon'=>'bi-check2-square','label'=>'Moje zadania','val'=>(string)$_sh_tasks,
                        'sub'=>$_sh_tasks ? 'aktywnych' : 'wszystko zrobione','color'=>$_sh_tasks ? 'orange' : 'green','size'=>'w']);
    }
    panelMetroDydaktykTile();
    panelMetroTile(['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-megaphone','label'=>'Komunikaty','val'=>$_sh_ann ? (string)$_sh_ann : '',
                    'sub'=>$_sh_ann ? 'nowych' : 'ogłoszenia organizacji','color'=>'violet']);
    panelMetroTile(['href'=>APP_URL.'/directory/','icon'=>'bi-person-lines-fill','label'=>'Katalog','sub'=>'kto jest kim · jednostki i działy','color'=>'indigo']);
    if (module_enabled('messages_enabled')) {
        panelMetroTile(['href'=>APP_URL.'/panel/messages.php','icon'=>'bi-chat-dots','label'=>'Wiadomości','val'=>!empty($msg_unread) ? (string)(int)$msg_unread : '',
                        'sub'=>!empty($msg_unread) ? 'nowych' : 'korespondencja','color'=>'blue']);
    }
    panelMetroTile(['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','val'=>$_sh_hd ? (string)$_sh_hd : '',
                    'sub'=>$_sh_hd ? 'otwartych zgłoszeń' : 'zgłoś problem','color'=>'magenta']);
    panelMetroTile(['href'=>APP_URL.'/panel/m365.php','icon'=>'bi-microsoft','label'=>'Microsoft 365 i poczta',
                    'sub'=>$_sh_m365 ?: 'konto nie zostało założone','color'=>$_sh_m365 ? 'indigo' : 'grey','size'=>'w','tag'=>$_sh_m365 ? '' : 'brak']);
    if (is_file($ROOT . '/panel/calendar.php') && module_enabled('org_calendar_enabled')) {
        panelMetroTile(['href'=>APP_URL.'/panel/calendar.php','icon'=>'bi-calendar3','label'=>'Kalendarz','sub'=>'wydarzenia i dyżury','color'=>'teal']);
    }
    if (is_file($ROOT . '/tozsamosc/index.php')) {
        panelMetroTile(['href'=>APP_URL.'/tozsamosc/','icon'=>'bi-person-vcard','label'=>'Tożsamość','sub'=>'hasło, MFA, telefon','color'=>'dark']);
    }
    if ($_sh_admin) {
        panelMetroTile(['href'=>APP_URL.'/admin/index.php','icon'=>'bi-gear','label'=>'Panel admina','sub'=>'administrator systemu','color'=>'dark']);
        if (module_enabled('tasks_enabled')) {
            panelMetroTile(['href'=>APP_URL.'/tasks/dashboard.php','icon'=>'bi-table','label'=>'Moduł Zadania','sub'=>'pulpit zadań','color'=>'green']);
        }
    }
    ?>
  </div>
  </div>
  <aside aria-label="Komunikaty i zadania"><?php panelMetroSideFeed($_sh_uid, (string)($user['role'] ?? '')); ?></aside>
  </div>

  <?php if (module_enabled('tasks_enabled')): ?>
  <section class="sh-sec" aria-label="Moje zadania"><?php include __DIR__ . '/pv_tasks_section.php'; ?></section>
  <?php endif; ?>
  <?php
  ob_start(); include __DIR__ . '/pv_pelnomocnictwa.php'; $_sh_pel = trim((string)ob_get_clean());
  ob_start(); include __DIR__ . '/pv_extra_sections.php'; $_sh_extra = trim((string)ob_get_clean());
  $GLOBALS['_pv_pelnomocnictwa_done'] = true;
  ?>
  <?php if ($_sh_pel !== ''): ?><section class="sh-sec"><?= $_sh_pel ?></section><?php endif; ?>
  <?php if ($_sh_extra !== ''): ?>
  <section class="sh-sec" aria-labelledby="sh-extra-h">
    <h2 class="mt-h2" id="sh-extra-h">Wydarzenia i <b>rezerwacje</b></h2>
    <div class="mt-group"><?= $_sh_extra ?></div>
  </section>
  <?php endif; ?>
</div>
