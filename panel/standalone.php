<?php
/**
 * panel/standalone.php — Panel współpracownika bez umowy.
 *
 * Pokazuje „Ważne informacje” (komunikaty admina), kafle Start, a w zakładkach
 * pivot: narzędzia (siatka kafli) oraz konta i dostępy (portal, M365).
 * Wygląd METRO jak System Tożsamości — wspólny zestaw modules/panel_metro.
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
$_vol_color = $_vol_color ?? '#0f6fa8';
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
$initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($display_name)) ?: [], 0, 2)))) ?: '?';

// Skróty (zakładka „Narzędzia”) — ten sam format kafelków co pulpit osoby z umową.
$sa_tools = [];
$sa_tools[] = ['href'=>APP_URL.'/tasks/index.php','icon'=>'bi-check2-square','label'=>'Zadania','sub'=>$task_count ? $task_count . ' aktywnych' : 'przypisane projekty','count'=>$task_count];
$sa_tools[] = ['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-megaphone','label'=>'Komunikaty','sub'=>$notif_count ? $notif_count . ' nowych' : 'ogłoszenia organizacji','badge'=>$notif_count];
if (module_enabled('helpdesk_enabled')) $sa_tools[] = ['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','sub'=>$hd_open ? $hd_open . ' otwartych zgłoszeń' : 'zgłoś problem','badge'=>$hd_open];
if (module_enabled('oswiadczenia_enabled')) $sa_tools[] = ['href'=>APP_URL.'/oswiadczenia/index.php','icon'=>'bi-file-earmark-check','label'=>'Oświadczenia','sub'=>$osw_cnt ? $osw_cnt . ' do podpisania' : 'podpisane i wymagane','badge'=>$osw_cnt];
if ($gc_cnt) $sa_tools[] = ['href'=>APP_URL.'/panel/zgody.php','icon'=>'bi-shield-check','label'=>'Zgody i oświadczenia','sub'=>$gc_cnt . ' do odnowienia','badge'=>$gc_cnt];
if (module_enabled('procedures_enabled')) $sa_tools[] = ['href'=>APP_URL.'/panel/procedures.php','icon'=>'bi-journal-text','label'=>'Procedury','sub'=>'dokumenty i instrukcje'];
if (module_enabled('org_documents_enabled')) $sa_tools[] = ['href'=>APP_URL.'/panel/org_documents.php','icon'=>'bi-folder2-open','label'=>'Dokumenty organizacji','sub'=>'statut, regulaminy, wzory'];
if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')) $sa_tools[] = ['href'=>APP_URL.'/panel/whatsapp_group.php','icon'=>'bi-whatsapp','label'=>'Grupa WhatsApp','sub'=>'dołącz do grupy'];
$sa_tools[] = ['href'=>APP_URL.'/org_intro/panel_guide.php','icon'=>'bi-book','label'=>'Przewodnik po panelu','sub'=>'jak korzystać z systemu'];
if (is_file(dirname(__DIR__) . '/tozsamosc/index.php')) $sa_tools[] = ['href'=>APP_URL.'/tozsamosc/','icon'=>'bi-person-vcard','label'=>'System Tożsamości','sub'=>'hasło, MFA, telefon','color'=>'dark'];
$sa_tools[] = ['href'=>APP_URL.'/panel/password.php','icon'=>'bi-gear','label'=>'Ustawienia konta','sub'=>'hasło, telefon, kolor','color'=>'grey'];

panelMetroAssets($_vol_color);
?>
<div class="pvm">

  <!-- ═══════════ NAGŁÓWEK ═══════════ -->
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · <?= h($org_name) ?><?php if ($org_unit_name): ?> · <?= h($org_unit_name) ?><?php endif; ?> · <?= date('d.m.Y') ?></div>
      <h1 class="mt-title"><?= h($greeting) ?>, <?= h($first_name) ?></h1>
      <p class="mt-lead"><?php if ($task_count): ?>Masz <strong><?= $task_count ?></strong> <?= $task_count === 1 ? 'aktywne zadanie' : ($task_count < 5 ? 'aktywne zadania' : 'aktywnych zadań') ?><?= $notif_count ? ' i <strong>' . $notif_count . '</strong> ' . ($notif_count === 1 ? 'nowe powiadomienie' : 'nowych powiadomień') : '' ?>.<?php else: ?>Brak nowych zadań — wszystko na bieżąco.<?php endif; ?></p>
    </div>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <?php panelMetroImportant($user_id, (string)($user['role'] ?? 'viewer')); ?>

  <!-- ═══════════ KAFLE (Start) ═══════════ -->
  <h2 class="visually-hidden">Skróty</h2>
  <div class="mt-grid">
    <a href="#konta" class="mt-tile mt-tile--l mt-tile--acc" data-pvm-go="konta" aria-label="<?= h($display_name) ?> — konta i dostępy">
      <span class="ava" aria-hidden="true"><?= h($initials) ?></span>
      <span aria-hidden="true">
        <span class="val" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($display_name) ?></span>
        <span class="lbl">Współpracownik<?= $org_unit_name ? ' · ' . h($org_unit_name) : '' ?></span>
        <span class="sub"><?= h($email) ?></span>
      </span>
    </a>
    <?php
    panelMetroTile(['href'=>APP_URL.'/tasks/index.php','icon'=>'bi-check2-square','label'=>'Moje zadania','val'=>(string)$task_count,
                    'sub'=>$task_count ? 'aktywnych' : 'wszystko zrobione','color'=>$task_count ? 'orange' : 'green','size'=>'w']);
    panelMetroTile(['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-megaphone','label'=>'Komunikaty','val'=>(string)$notif_count,
                    'sub'=>$notif_count ? 'nowych' : 'ogłoszenia','color'=>'violet']);
    if (module_enabled('helpdesk_enabled')) {
        panelMetroTile(['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','val'=>$hd_open ? (string)$hd_open : '',
                        'sub'=>$hd_open ? 'otwartych zgłoszeń' : 'zgłoś problem','color'=>'magenta']);
    }
    panelMetroTile(['href'=>'#konta','go'=>'konta','icon'=>'bi-microsoft','label'=>'Microsoft 365 i poczta',
                    'sub'=>$has_m365 ? ($m365_login ?: $email) : 'konto nie zostało założone','color'=>$has_m365 ? 'indigo' : 'grey','size'=>'w',
                    'tag'=>$has_m365 ? '' : 'brak']);
    panelMetroTile(['href'=>'#narzedzia','go'=>'narzedzia','icon'=>'bi-grid-3x3-gap','label'=>'Narzędzia','val'=>(string)count($sa_tools),'color'=>'teal']);
    if (is_file(dirname(__DIR__) . '/tozsamosc/index.php')) {
        panelMetroTile(['href'=>APP_URL.'/tozsamosc/','icon'=>'bi-person-vcard','label'=>'Tożsamość','sub'=>'hasło, MFA, telefon','color'=>'dark']);
    }
    ?>
  </div>

  <!-- ═══════════ PIVOT ═══════════ -->
  <div class="mt-pivot" role="tablist" aria-label="Sekcje panelu" data-pvm-pivot>
    <button type="button" role="tab" id="pvm-tab-narzedzia" data-tab="narzedzia" aria-controls="narzedzia" aria-selected="true" tabindex="0">narzędzia</button>
    <button type="button" role="tab" id="pvm-tab-konta" data-tab="konta" aria-controls="konta" aria-selected="false" tabindex="-1">konta i dostępy</button>
  </div>

  <section id="narzedzia" data-pvm-panel="narzedzia" role="tabpanel" aria-labelledby="pvm-tab-narzedzia" tabindex="-1">
    <h2 class="mt-h2">Narzędzia do <b>codziennej pracy</b></h2>
    <?php panelMetroTileGrid($sa_tools, 'Narzędzia', ['orange','violet','magenta','blue','green','teal','indigo','red']); ?>
  </section>

  <section id="konta" data-pvm-panel="konta" role="tabpanel" aria-labelledby="pvm-tab-konta" tabindex="-1" hidden>
    <h2 class="mt-h2">Konta i <b>dostępy</b></h2>

    <div class="mt-svc" style="--c:var(--pvm-acc)">
      <i class="bi bi-house-door ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Panel współpracownika <span class="mt-badge mt-badge--ok">aktywne</span></h3>
        <p><?= h($app_host) ?> · login (e-mail): <code><?= h($email) ?></code> <button class="mt-copy" type="button" data-copy="<?= h($email) ?>" aria-label="Kopiuj adres e-mail do schowka"><i class="bi bi-copy" aria-hidden="true"></i></button></p>
        <div class="mt-links">
          <a href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a>
          <a href="<?= APP_URL ?>/tozsamosc/index.php"><i class="bi bi-person-vcard" aria-hidden="true"></i>System Tożsamości</a>
        </div>
      </div>
    </div>

    <?php if ($has_m365): $sa_m_login = $m365_login ?: $email; ?>
    <div class="mt-svc" style="--c:var(--m-indigo)">
      <i class="bi bi-microsoft ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Microsoft 365 <span class="mt-badge mt-badge--ok">aktywne</span></h3>
        <p>Outlook, Teams, OneDrive · login: <code><?= h($sa_m_login) ?></code> <button class="mt-copy" type="button" data-copy="<?= h($sa_m_login) ?>" aria-label="Kopiuj login Microsoft 365 do schowka"><i class="bi bi-copy" aria-hidden="true"></i></button></p>
        <?php if (!empty($u_db['m365_security_group_name'])): ?><p>Grupa dostępu: <code><?= h($u_db['m365_security_group_name']) ?></code></p><?php endif; ?>
        <div class="mt-links">
          <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener"><i class="bi bi-envelope" aria-hidden="true"></i>Poczta: <?= h(webmail_chooser_label()) ?><span class="visually-hidden"> — nowa karta</span></a>
          <a href="https://portal.office.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)<span class="visually-hidden"> — nowa karta</span></a>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="mt-svc" style="--c:var(--m-grey)">
      <i class="bi bi-microsoft ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Microsoft 365 <span class="mt-badge">brak konta</span></h3>
        <p>Konto nie zostało jeszcze założone. Jeśli potrzebujesz poczty organizacji lub Teams, napisz do administratora.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="mt-note" style="margin-top:1rem"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Hasło do panelu zmieniasz w Ustawieniach konta. Jeśli któryś dostęp nie działa, napisz do administratora.</span></div>
  </section>

</div><!-- /.pvm -->

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
