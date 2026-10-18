<?php
/**
 * panel/includes/pv_home.php — Pulpit Panelu współpracownika (osoba z umową).
 *
 * Wygląd = System Tożsamości (tozsamosc/index.php): METRO — kafle Start, zakładki
 * „pivot” (jedna sekcja naraz, #hash, klawiatura), grupy i wiersze usług z paskiem
 * koloru. Wspólny zestaw: modules/panel_metro (też panel/standalone.php).
 * Akcent = per-user --vol-color (panel/panel_color.php).
 *
 * Kolejność: nagłówek → „Ważne informacje” (komunikaty admina w trybie 'important')
 * → kafle Start → zakładki: Umowa · Sprawy · Narzędzia · Konta i dostępy ·
 * Wiadomości · Wydarzenia (gdy są). Sprawy i Narzędzia to siatki kafli.
 *
 * Zmienne wejściowe (liczone w panel/index.php PRZED include): $user,
 * $_active_row, $_active_contract, $contract_progress, $contracts, $_vol_color,
 * $_is_guardian, $msg_unread, $my_apps, $my_apps_new, $my_certs,
 * $my_certs_pending, $my_terms, $my_terms_pending, $_active_zwroty,
 * $_zwroty_pending, $my_letters_count, $_sms_login_available.
 *
 * Dostępność: WCAG 2.1 AA — kontrast kafli ≥ 4,5:1, role tablist/tab/tabpanel,
 * widoczny focus, etykiety kafli dla czytników ekranu.
 */
if (!defined('APP_URL')) { return; }
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/modules/panel_metro/lib/panelMetro.php';
$_vol_color = $_vol_color ?? '#0f6fa8';

/* ── Dane platform (login portalu / M365) ─────────────────────────────────── */
$u_db = db_one(
    "SELECT u.*, ou.name AS org_unit_name
       FROM users u LEFT JOIN org_units ou ON ou.id = u.org_unit_id
      WHERE u.id = ?",
    [(int)$user['id']]
) ?: [];
$email      = $user['email'] ?? ($u_db['email'] ?? '');
$m365_login = $u_db['m365_login'] ?? ($_active_row['m365_login'] ?? '');

/* ── Canva: status dostępu + samodzielne konto ───────────────────────────── */
$_canva_module_on = false;
try { require_once $ROOT . '/includes/canva.php'; $_canva_module_on = canva_module_enabled(); } catch (\Throwable $e) {}
$_canva_contract_wolont = ($_active_row && $_active_contract && ($_active_contract['contract_type'] ?? '') === 'wolontariat');
$_canva_has_access = $_canva_module_on
    && (($_canva_contract_wolont && (int)($_active_row['canva_access'] ?? 0) === 1)
        || (function_exists('canva_user_has_access') && canva_user_has_access((int)$user['id'])));
$_canva_requested   = $_canva_module_on && !$_canva_has_access && $_canva_contract_wolont && !empty($_active_row['canva_access_requested_at']);
$_canva_can_request = $_canva_module_on && !$_canva_has_access && !$_canva_requested && $_canva_contract_wolont;
$_canva_own_account = $_canva_contract_wolont && ($_active_row['canva_konto_zrodlo'] ?? '') !== 'admin';

/* ── Konta dzieci (opiekun) ──────────────────────────────────────────────── */
$_my_children = (function_exists('ctx_is_impersonating') && !ctx_is_impersonating() && function_exists('ctx_guardian_children'))
    ? ctx_guardian_children((int)(ctx_real_user()['id'] ?? 0)) : [];

/* ── Helpdesk: liczba otwartych zgłoszeń ─────────────────────────────────── */
$_hd_my_open = 0;
try {
    $_hd_my_open = (int)(db_one(
        "SELECT COUNT(*) AS c FROM helpdesk_tickets
          WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')",
        [(int)$user['id']]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

/* ── Nudge: profil w katalogu / logowanie SMS ────────────────────────────── */
if (function_exists('auth_start')) auth_start();
$_show_dir_invite = false;
if (empty($_SESSION['_panel_dir_invited'])) {
    $_dir_profile = null;
    try { $_dir_profile = db_one("SELECT bio, avatar_file FROM user_profiles WHERE user_id=?", [(int)$user['id']]); } catch (\Throwable $e) {}
    if (empty($_dir_profile['bio']) && empty($_dir_profile['avatar_file'])) {
        $_show_dir_invite = true;
        $_SESSION['_panel_dir_invited'] = true;
    }
}
$_show_sms_nudge  = false;
$_nudge_has_phone = false;
if (!empty($_sms_login_available)) {
    try { db()->exec("ALTER TABLE users ADD COLUMN sms_nudge_dismissed TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    $_nudge_dismissed = (int)(db_one("SELECT sms_nudge_dismissed FROM users WHERE id=?", [(int)$user['id']])['sms_nudge_dismissed'] ?? 0);
    if (!$_nudge_dismissed) {
        $_nudge_has_phone = !empty($user['phone_number']) || !empty($_active_row['telefon']);
        $_show_sms_nudge  = true;
    }
}

/* ── Powitanie ───────────────────────────────────────────────────────────── */
$_fname       = trim(($_active_row['imie_nazwisko'] ?? $user['name'] ?? ''));
$_fname_first = explode(' ', $_fname)[0] ?: 'Wolontariuszu';
$_hour        = (int)date('G');
$_greet       = $_hour < 12 ? 'Dzień dobry' : ($_hour < 18 ? 'Witaj' : 'Dobry wieczór');

/* ── Status umowy: etykiety + ikony ──────────────────────────────────────── */
$_status_labels = ['projekt'=>'W przygotowaniu','podpisana'=>'Aktywne','w realizacji'=>'Aktywne','zakończona'=>'Zakończone','rozwiązana'=>'Zakończone','anulowana'=>'Anulowane'];
$_status_icons  = ['podpisana'=>'bi-check-circle','w realizacji'=>'bi-play-circle','zakończona'=>'bi-flag','rozwiązana'=>'bi-x-circle','anulowana'=>'bi-slash-circle','projekt'=>'bi-clock'];
$_st    = $_active_contract['status'] ?? '';
$_ended = !empty($contract_progress['ended']);

/* ── Sprawy formalne (kafelki zakładki 1) ────────────────────────────────── */
$_pv_formal = [];
$_pv_formal[] = ['href'=>APP_URL.'/panel/apply.php','icon'=>'bi-send','label'=>'Złóż wniosek / pismo','sub'=>$my_apps_new ? "$my_apps_new w toku" : 'do organizacji'];
if (module_enabled('certificates_enabled')) $_pv_formal[] = ['href'=>APP_URL.'/panel/certificates.php','icon'=>'bi-award','label'=>'Zaświadczenia','sub'=>$my_certs_pending ? "$my_certs_pending w toku" : 'pobierz / poproś','badge'=>$my_certs_pending];
if ($_active_zwroty) $_pv_formal[] = ['href'=>APP_URL.'/panel/zwroty.php','icon'=>'bi-receipt','label'=>'Rozliczenie kosztów','sub'=>$_zwroty_pending ? 'oczekuje zwrotu' : 'złóż rozliczenie','badge'=>$_zwroty_pending];
if (module_enabled('timesheets_enabled')) $_pv_formal[] = ['href'=>APP_URL.'/panel/timesheets.php','icon'=>'bi-clock-history','label'=>'Ewidencja godzin','sub'=>'arkusze czasu'];
if (array_filter($contracts ?? [], fn($c) => ($c['contract_type'] ?? '') === 'zlecenie')) $_pv_formal[] = ['href'=>APP_URL.'/panel/rozliczenia_zlecenie.php','icon'=>'bi-calculator','label'=>'Godziny i rozliczenia','sub'=>'umowa zlecenie: saldo'];
if (module_enabled('letters_enabled') && $my_letters_count) $_pv_formal[] = ['href'=>APP_URL.'/panel/letters.php','icon'=>'bi-archive','label'=>'Pisma','sub'=>'korespondencja','badge'=>0,'count'=>$my_letters_count];
if (is_file($ROOT.'/panel/zgody.php')) $_pv_formal[] = ['href'=>APP_URL.'/panel/zgody.php','icon'=>'bi-file-earmark-check','label'=>'Zgody i oświadczenia','sub'=>'zgody do umowy'];
if (is_file($ROOT.'/panel/rodo.php')) $_pv_formal[] = ['href'=>APP_URL.'/panel/rodo.php','icon'=>'bi-shield-lock','label'=>'Twoje dane (RODO)','sub'=>'jak przetwarzamy dane'];
if (module_enabled('terminations_enabled')) $_pv_formal[] = ['href'=>APP_URL.'/panel/terminations.php','icon'=>'bi-file-earmark-x','label'=>'Zakończ współpracę','sub'=>count($my_terms) ? 'wnioski złożone' : 'wniosek o rozwiązanie','badge'=>$my_terms_pending];

/* ── Narzędzia codzienne (kafelki zakładki 2) ────────────────────────────── */
$_pv_daily = [];
if (panel_visible('asystent') && function_exists('asai_enabled') && asai_enabled())
    $_pv_daily[] = ['href'=>APP_URL.'/panel/asystent.php','icon'=>'bi-stars','label'=>'Asystent AI','sub'=>'zapytaj o cokolwiek'];
$_pv_daily[] = ['href'=>APP_URL.'/panel/messages.php','icon'=>'bi-chat-left-text','label'=>'Wiadomości','sub'=>$msg_unread ? "$msg_unread nowych" : 'napisz do nas','badge'=>$msg_unread];
$_pv_daily[] = ['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','sub'=>$_hd_my_open ? "$_hd_my_open otwartych" : 'zgłoś problem','badge'=>$_hd_my_open];
if (is_file($ROOT.'/panel/calendar.php')) $_pv_daily[] = ['href'=>APP_URL.'/panel/calendar.php','icon'=>'bi-calendar3','label'=>'Kalendarz','sub'=>'wydarzenia i dyżury'];
if (module_enabled('procedures_enabled')) $_pv_daily[] = ['href'=>APP_URL.'/panel/procedures.php','icon'=>'bi-journal-text','label'=>'Procedury','sub'=>'instrukcje i wzory'];
if (module_enabled('org_documents_enabled')) $_pv_daily[] = ['href'=>APP_URL.'/panel/org_documents.php','icon'=>'bi-folder2-open','label'=>'Dokumenty organizacji','sub'=>'statut, regulaminy'];
if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')) $_pv_daily[] = ['href'=>APP_URL.'/panel/whatsapp_group.php','icon'=>'bi-whatsapp','label'=>'Grupa WhatsApp','sub'=>'dołącz do grupy'];
if (module_enabled('ezd_enabled') && can_read('ezd')) $_pv_daily[] = ['href'=>APP_URL.'/ezd/index.php','icon'=>'bi-folder2-open','label'=>'Wirtualne biurko','sub'=>'sprawy i pisma'];

/* ── Poczta: klienty do wyboru (komunikat: od 1 IX 2026) ──────────────────
   Lista i opisy z katalogu includes/webmail_clients.php — TEGO SAMEGO, z którego
   korzysta publiczna strona rozjazdu (webmail/index.php pod poczta.feer.org.pl
   i aliasem szo.feer.org.pl/poczta) oraz moduł Poczta. Klient bez ustawionego
   adresu (Admin → Poczta → Webmail) po prostu się tu nie pojawia. */
require_once $ROOT . '/includes/webmail_clients.php';
$_mail_clients       = webmail_clients();
$_mail_chooser       = webmail_chooser_url();
$_mail_chooser_label = webmail_chooser_label();

/* ── Aktywność: ostatnie wnioski ─────────────────────────────────────────── */
$_pv_apps = $my_apps ? array_map(fn($a) => [
    'tytul' => $a['tytul'], 'type_label' => $a['type_label'] ?? '', 'type_icon' => $a['type_icon'] ?? 'bi-file-text',
    'status' => $a['status'], 'created_at' => $a['created_at'], 'odpowiedz' => $a['odpowiedz'] ?? '',
], $my_apps) : [];

/* ── Zakładka „Zadania": tylko gdy moduł włączony (ten sam warunek co widżet) ── */
$_tasks_tab_on = false;
try { $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'"); $_tasks_tab_on = ($_tm['value'] ?? '1') !== '0'; } catch (\Throwable $e) {}

/* ── Wiadomości: ostatnie 5 z wątku umowy ─────────────────────────────────── */
$_pv_msgs = [];
if ($_active_contract) {
    try {
        $_pv_msgs = db_all(
            "SELECT id, sender_type, sender_name, body, created_at,
                    CASE WHEN sender_type='admin' AND is_read=0 THEN 1 ELSE 0 END AS is_unread
             FROM messages
             WHERE context_type='contract' AND context_id=?
             ORDER BY created_at DESC LIMIT 5",
            [(int)$_active_contract['id']]
        ) ?: [];
    } catch (\Throwable $e) {}
}

/* ── Liczniki do kafli ───────────────────────────────────────────────────── */
$_pending_total = (int)$my_apps_new + (int)$my_certs_pending + (int)$_zwroty_pending + (int)$my_terms_pending;
$_ann_unread = 0;
try { $_ann_unread = function_exists('notif_unread_count_type') ? notif_unread_count_type((int)$user['id'], 'announcement') : 0; } catch (\Throwable $e) {}
$_trello_url = 'https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u';
// Zadania są tymczasowo w Trello (kafel „Zadania”) — wspólny widżet zadań z panel/index.php pomijamy.
if ($_tasks_tab_on) $GLOBALS['_pv_tasks_section_done'] = true;

$_pv_initials = function (string $name): string {
    $words = preg_split('/\s+/u', trim($name)) ?: [];
    return mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1, 'UTF-8'), array_slice($words, 0, 2))), 'UTF-8') ?: '?';
};
$_pv_rel_time = function (string $dt): string {
    $ts = strtotime($dt); $diff = time() - $ts;
    if ($diff < 86400)  return date('H:i', $ts);
    if ($diff < 172800) return 'wczoraj';
    if ($diff < 604800) return (int)($diff / 86400) . ' dni temu';
    return date('d.m.Y', $ts);
};

/* ── Umowa: etykiety ─────────────────────────────────────────────────────── */
$_ct_type  = $_active_contract['contract_type'] ?? 'wolontariat';
$_is_wol   = $_ct_type === 'wolontariat';
$_ct_label = ['wolontariat'=>'Wolontariat','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'][$_ct_type] ?? ucfirst((string)$_ct_type);
$_pct      = ($contract_progress && !$_ended) ? min(100, max(0, (int)$contract_progress['pct'])) : null;
$_pesel    = $_active_row['pesel'] ?? '';
$_pesel_msk = $_pesel ? (substr($_pesel, 0, 2) . '·····' . substr($_pesel, 7)) : '';
$_fmt_d    = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';

/* ── Narzędzia: na końcu zawsze Tożsamość i Ustawienia konta ─────────────── */
$_pv_tools = $_pv_daily;
$_pv_tools[] = ['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-megaphone','label'=>'Komunikaty','sub'=>$_ann_unread ? "$_ann_unread nowych" : 'ogłoszenia organizacji','badge'=>$_ann_unread];
if (is_file($ROOT . '/tozsamosc/index.php')) $_pv_tools[] = ['href'=>APP_URL.'/tozsamosc/','icon'=>'bi-person-vcard','label'=>'System Tożsamości','sub'=>'hasło, MFA, telefon','color'=>'dark'];
$_pv_tools[] = ['href'=>APP_URL.'/panel/password.php','icon'=>'bi-gear','label'=>'Ustawienia konta','sub'=>'hasło, telefon, kolor','color'=>'grey'];

/* ── Wydarzenia / rezerwacje: zakładka tylko, gdy jest treść ─────────────── */
ob_start();
include __DIR__ . '/pv_extra_sections.php';
$_pv_extra_html = trim((string)ob_get_clean());

/* ── Zakładki (klucz => [etykieta, licznik]) ─────────────────────────────── */
$_tabs = ['umowa' => ['umowa', 0], 'sprawy' => ['sprawy', $_pending_total], 'narzedzia' => ['narzędzia', 0],
          'konta' => ['konta i dostępy', 0], 'wiadomosci' => ['wiadomości', (int)$msg_unread]];
if ($_pv_extra_html !== '') $_tabs['wydarzenia'] = ['wydarzenia', 0];

/* ── Pełnomocnictwa: zakładka zamiast starego widżetu pod pulpitem (pv_pelnomocnictwa.php) ── */
$_pv_peln = [];
if (module_enabled('pelnomocnictwa_enabled')) {
    try {
        require_once $ROOT . '/includes/pelnomocnictwa.php';
        $_pv_peln = pelnomocnictwa_for_user((int)$user['id'], (string)($user['name'] ?? ''), true);
    } catch (\Throwable $e) { $_pv_peln = []; }
}
if ($_pv_peln) {
    $_tabs['pelnomocnictwa'] = ['pełnomocnictwa', 0];
    $GLOBALS['_pv_pelnomocnictwa_done'] = true;
}
$_tab_first = array_key_first($_tabs);

panelMetroAssets($_vol_color);
?>
<div class="pvm pvtz" id="pvtz-root">

  <!-- ═══════════ NAGŁÓWEK ═══════════ -->
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · <?= h(ORG_NAME) ?> · <?= date('d.m.Y') ?></div>
      <h1 class="mt-title"><?= h($_greet) ?>, <?= h($_fname_first) ?></h1>
      <p class="mt-lead">Twoja współpraca w jednym miejscu: umowa, sprawy do załatwienia, narzędzia i dostępy do kont.</p>
    </div>
    <?php if (count($contracts) > 1): ?>
    <button type="button" class="mt-btn mt-btn--ghost" data-bs-toggle="modal" data-bs-target="#contractPickerModal" aria-haspopup="dialog">
      <i class="bi bi-arrow-left-right" aria-hidden="true"></i>Zmień umowę
    </button>
    <?php endif; ?>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <?php panelMetroImportant((int)$user['id'], (string)($user['role'] ?? 'viewer')); ?>

  <?php if ($_show_dir_invite): ?>
  <div class="mt-note" role="complementary" aria-label="Zaproszenie do uzupełnienia profilu">
    <i class="bi bi-people-fill" aria-hidden="true"></i>
    <span style="flex:1"><strong>Uzupełnij swój profil w katalogu.</strong> Dodaj zdjęcie i krótki opis — łatwiej Cię znajdą i dopasują zadania.
      <a href="<?= APP_URL ?>/directory/profile_edit.php" class="fw-semibold ms-1" style="color:inherit">Uzupełnij profil →</a></span>
    <button type="button" class="mt-copy" aria-label="Zamknij zaproszenie" onclick="this.closest('.mt-note').remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <?php endif; ?>
  <?php if ($_show_sms_nudge): include __DIR__ . '/pv_sms_nudge.php'; endif; ?>

  <?php if (!$contracts): ?>
  <div class="mt-empty">
    <i class="bi bi-file-earmark-x" style="font-size:2.5rem;display:block;margin-bottom:.6rem" aria-hidden="true"></i>
    <p class="fw-semibold mb-1" style="color:var(--pvm-ink)">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small mb-0">Skontaktuj się z administratorem, aby powiązać umowę z adresem <?= h($email) ?>.</p>
  </div>
  <?php else: ?>

  <!-- ═══════════ KAFLE (Start) ═══════════ -->
  <main id="pvtz-main">
  <h2 class="visually-hidden">Skróty</h2>
  <div class="mt-grid">
    <a href="#umowa" class="mt-tile mt-tile--l mt-tile--acc" data-pvm-go="umowa"
       aria-label="<?= h(trim(($_active_row['imie_nazwisko'] ?? $user['name'] ?? '') . ' — ' . $_ct_label . ', ' . ($_status_labels[$_st] ?? $_st) . ($_pct !== null ? ', pozostało ' . (int)$contract_progress['days_left'] . ' dni' : ''))) ?>">
      <span class="ava" aria-hidden="true"><?= h($_pv_initials($_active_row['imie_nazwisko'] ?? $user['name'] ?? '')) ?></span>
      <span aria-hidden="true">
        <span class="val" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($_active_row['imie_nazwisko'] ?? $user['name'] ?? '') ?></span>
        <span class="lbl"><?= h($_ct_label) ?> · <?= h($_status_labels[$_st] ?? ucfirst((string)$_st)) ?><?= $_is_guardian ? ' · opiekun' : '' ?></span>
        <span class="sub"><?= h(implode(' · ', array_filter([
          $_active_contract['numer_umowy'] ?? '',
          $_pct !== null ? 'pozostało ' . (int)$contract_progress['days_left'] . ' dni'
            : ($_ended ? 'umowa zakończona' : (!empty($_active_row['bezterminowa']) ? 'bezterminowo' : '')),
        ]))) ?></span>
        <?php if ($_pct !== null): ?><span class="bar"><span style="width:<?= $_pct ?>%"></span></span><?php endif; ?>
      </span>
    </a>
    <?php
    panelMetroTile(['href'=>APP_URL.'/panel/messages.php','go'=>'wiadomosci','icon'=>'bi-chat-dots','label'=>'Wiadomości','val'=>(string)(int)$msg_unread,
                    'sub'=>$msg_unread ? 'nowych od organizacji' : 'napisz do nas','color'=>$msg_unread ? 'indigo' : 'blue','size'=>'w']);
    panelMetroDydaktykTile();
    panelMetroTile(['href'=>APP_URL.'/panel/apply.php','go'=>'sprawy','icon'=>'bi-inboxes','label'=>'Sprawy w toku','val'=>(string)$_pending_total,
                    'sub'=>$_pending_total ? 'czeka na odpowiedź' : 'wnioski, zaświadczenia','color'=>$_pending_total ? 'orange' : 'teal']);
    if ($_tasks_tab_on) {
        panelMetroTile(['href'=>$_trello_url,'external'=>true,'icon'=>'bi-trello','label'=>'Zadania','sub'=>'tymczasowo w Trello','color'=>'#0052cc']);
    }
    panelMetroTile(['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','val'=>$_hd_my_open ? (string)$_hd_my_open : '',
                    'sub'=>$_hd_my_open ? 'otwartych zgłoszeń' : 'zgłoś problem','color'=>'magenta']);
    panelMetroTile(['href'=>'#konta','go'=>'konta','icon'=>'bi-microsoft','label'=>'Microsoft 365 i poczta',
                    'sub'=>$m365_login ?: ($email ?: 'konta i dostępy'),'color'=>'indigo','size'=>'w']);
    panelMetroTile(['href'=>APP_URL.'/komunikaty/index.php','icon'=>'bi-megaphone','label'=>'Komunikaty','val'=>$_ann_unread ? (string)$_ann_unread : '',
                    'sub'=>$_ann_unread ? 'nowych ogłoszeń' : 'ogłoszenia organizacji','color'=>'violet']);
    if (is_file($ROOT . '/panel/calendar.php')) {
        panelMetroTile(['href'=>APP_URL.'/panel/calendar.php','icon'=>'bi-calendar3','label'=>'Kalendarz','sub'=>'wydarzenia i dyżury','color'=>'green']);
    }
    panelMetroTile(['href'=>'#narzedzia','go'=>'narzedzia','icon'=>'bi-grid-3x3-gap','label'=>'Narzędzia','val'=>(string)count($_pv_tools),'color'=>'teal']);
    if (is_file($ROOT . '/tozsamosc/index.php')) {
        panelMetroTile(['href'=>APP_URL.'/tozsamosc/','icon'=>'bi-person-vcard','label'=>'Tożsamość','sub'=>'hasło, MFA, telefon','color'=>'dark']);
    }
    if ($_pv_peln) {
        panelMetroTile(['href'=>'#pelnomocnictwa','go'=>'pelnomocnictwa','icon'=>'bi-person-badge','label'=>'Pełnomocnictwa',
                        'val'=>(string)count($_pv_peln),'sub'=>'ważne, udzielone Tobie','color'=>'indigo']);
    }
    ?>
  </div>

  <!-- ═══════════ PIVOT ═══════════ -->
  <div class="mt-pivot" role="tablist" aria-label="Sekcje panelu" data-pvm-pivot>
    <?php foreach ($_tabs as $_tk => [$_tl, $_tc]): $_on = $_tk === $_tab_first; ?>
    <button type="button" role="tab" id="pvm-tab-<?= $_tk ?>" data-tab="<?= $_tk ?>" aria-controls="<?= $_tk ?>"
            aria-selected="<?= $_on ? 'true' : 'false' ?>" tabindex="<?= $_on ? 0 : -1 ?>"><?= h($_tl) ?><?php if ($_tc): ?><span class="cnt" aria-label="<?= (int)$_tc ?> wymaga uwagi"><?= $_tc > 99 ? '99+' : (int)$_tc ?></span><?php endif; ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════ UMOWA ═══════════ -->
  <section id="umowa" data-pvm-panel="umowa" role="tabpanel" aria-labelledby="pvm-tab-umowa" tabindex="-1"<?= $_tab_first !== 'umowa' ? ' hidden' : '' ?>>
    <h2 class="mt-h2"><?= h($_ct_label) ?> <b><?= h($_status_labels[$_st] ?? ucfirst((string)$_st)) ?></b></h2>
    <?php if ($_active_row): ?>
    <div class="mt-group">
      <dl class="mt-kv">
        <?php if (!empty($_active_contract['numer_umowy'])): ?><div><dt>Nr umowy</dt><dd class="big"><?= h($_active_contract['numer_umowy']) ?></dd></div><?php endif; ?>
        <div><dt>Okres</dt><dd><?= h($_fmt_d($_active_row['data_zawarcia'] ?? '')) ?: '—' ?> → <?= !empty($_active_contract['data_zakonczenia']) ? h($_fmt_d($_active_contract['data_zakonczenia'])) : (!empty($_active_row['bezterminowa']) ? 'bezterminowo' : '—') ?></dd></div>
        <?php if (!empty($_active_row['imie_nazwisko'])): ?><div><dt>Osoba</dt><dd><?= h($_active_row['imie_nazwisko']) ?><?= $_is_guardian ? ' <span class="mt-badge mt-badge--warn">opiekun</span>' : '' ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && !empty($_active_row['miejsce_wolontariatu'])): ?><div><dt>Miejsce</dt><dd><?= h($_active_row['miejsce_wolontariatu']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && !empty($_active_row['godzin_tygodniowo'])): ?><div><dt>Godzin tygodniowo</dt><dd><?= h($_active_row['godzin_tygodniowo']) ?> h</dd></div><?php endif; ?>
        <?php if ($_is_wol && !empty($_active_row['godzin_przepracowanych'])): ?>
        <div><dt>Godziny przepracowane</dt><dd><?= h(number_format((float)$_active_row['godzin_przepracowanych'], 2, ',', ' ')) ?> h<?php if ((float)($_active_row['godzin_z_zadan'] ?? 0) > 0): ?> <small class="mt-no">(<?= h(number_format((float)$_active_row['godzin_z_zadan'], 2, ',', ' ')) ?> h z zadań)</small><?php endif; ?></dd></div>
        <?php endif; ?>
        <?php if ($_is_wol && !empty($_active_row['opiekun'])): ?><div><dt>Opiekun</dt><dd><?= h($_active_row['opiekun']) ?></dd></div><?php endif; ?>
        <?php if (!empty($_active_row['projekt_program'])): ?><div><dt>Projekt / program</dt><dd><?= h($_active_row['projekt_program']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol): ?>
        <div><dt>Szkolenie BHP</dt><dd><?= !empty($_active_row['szkolenie_bhp']) ? '<span class="mt-yes"><i class="bi bi-check-square-fill" aria-hidden="true"></i> Tak</span>' : '<span class="mt-no">Nie</span>' ?></dd></div>
        <div><dt>Ubezpieczenie NNW</dt><dd><?= !empty($_active_row['ubezpieczenie_nnw']) ? '<span class="mt-yes"><i class="bi bi-check-square-fill" aria-hidden="true"></i> Tak</span>' : '<span class="mt-no">Nie</span>' ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php if ($_pct !== null): ?>
      <div class="mt-progress" role="progressbar" aria-label="Postęp umowy" aria-valuenow="<?= $_pct ?>" aria-valuemin="0" aria-valuemax="100"
           aria-valuetext="Upłynęło <?= $_pct ?>% okresu umowy, pozostało <?= (int)$contract_progress['days_left'] ?> dni"><span style="width:<?= $_pct ?>%"></span></div>
      <div class="mt-progress-lbl" aria-hidden="true"><span><?= h($_fmt_d($_active_row['data_zawarcia'] ?? '')) ?: 'start' ?></span><span>pozostało <strong><?= (int)$contract_progress['days_left'] ?></strong> dni</span><span><?= h($_fmt_d($_active_contract['data_zakonczenia'] ?? '')) ?></span></div>
      <?php elseif ($_ended): ?>
      <p class="mt-3 mb-0 mt-no"><i class="bi bi-flag" aria-hidden="true"></i> Umowa zakończona.</p>
      <?php endif; ?>
    </div>

    <?php if ($_pesel || !empty($_active_row['telefon']) || !empty($_active_row['adres']) || !empty($_active_row['data_urodzenia'])): ?>
    <h3 class="mt-h3">Dane osobowe w umowie</h3>
    <div class="mt-group">
      <dl class="mt-kv">
        <?php if ($_pesel): ?><div><dt>PESEL</dt><dd style="font-family:ui-monospace,monospace"><?= h($_pesel_msk) ?></dd></div><?php endif; ?>
        <?php if (!empty($_active_row['data_urodzenia'])): ?><div><dt>Data urodzenia</dt><dd><?= h($_fmt_d($_active_row['data_urodzenia'])) ?></dd></div><?php endif; ?>
        <?php if (!empty($_active_row['telefon'])): ?><div><dt>Telefon</dt><dd><?= h($_active_row['telefon']) ?></dd></div><?php endif; ?>
        <?php if (!empty($_active_row['adres'])): ?><div style="grid-column:1/-1"><dt>Adres korespondencyjny</dt><dd><?= h($_active_row['adres']) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="mt-empty"><p class="mb-0">Brak aktywnej umowy do wyświetlenia.</p></div>
    <?php endif; ?>
  </section>

  <!-- ═══════════ SPRAWY ═══════════ -->
  <section id="sprawy" data-pvm-panel="sprawy" role="tabpanel" aria-labelledby="pvm-tab-sprawy" tabindex="-1"<?= $_tab_first !== 'sprawy' ? ' hidden' : '' ?>>
    <h2 class="mt-h2">Sprawy <b>formalne</b></h2>
    <?php panelMetroTileGrid($_pv_formal, 'Sprawy formalne', ['orange','blue','teal','green','violet','indigo','magenta','dark','red','grey']); ?>
    <h3 class="mt-h3" style="margin-top:1.75rem">Ostatnie wnioski</h3>
    <?php if ($_pv_apps): ?>
    <?php include __DIR__ . '/pv_apps_activity.php'; ?>
    <?php else: ?>
    <div class="mt-empty">
      <p class="mb-2">Nie masz jeszcze żadnych wniosków.</p>
      <a href="<?= APP_URL ?>/panel/apply.php" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i>Złóż wniosek / pismo</a>
    </div>
    <?php endif; ?>
  </section>

  <!-- ═══════════ NARZĘDZIA ═══════════ -->
  <section id="narzedzia" data-pvm-panel="narzedzia" role="tabpanel" aria-labelledby="pvm-tab-narzedzia" tabindex="-1"<?= $_tab_first !== 'narzedzia' ? ' hidden' : '' ?>>
    <h2 class="mt-h2">Narzędzia do <b>codziennej pracy</b></h2>
    <?php panelMetroTileGrid($_pv_tools, 'Narzędzia', ['violet','blue','magenta','green','teal','indigo','orange','red']); ?>
  </section>

  <!-- ═══════════ KONTA I DOSTĘPY ═══════════ -->
  <section id="konta" data-pvm-panel="konta" role="tabpanel" aria-labelledby="pvm-tab-konta" tabindex="-1"<?= $_tab_first !== 'konta' ? ' hidden' : '' ?>>
    <h2 class="mt-h2">Konta i <b>dostępy</b></h2>

    <?php if ($_my_children): ?>
    <h3 class="mt-h3">Konta dzieci</h3>
    <?php foreach ($_my_children as $_ch): ?>
    <form method="post" action="<?= APP_URL ?>/auth/enter_child.php" class="mt-svc" style="--c:var(--m-orange);align-items:center">
      <i class="bi bi-person-hearts ic" aria-hidden="true"></i>
      <div class="mt-svc__bd"><h3><?= h($_ch['name']) ?></h3><p><?= h($_ch['email']) ?></p></div>
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="child" value="<?= (int)$_ch['id'] ?>">
      <button class="mt-btn"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź na konto</button>
    </form>
    <?php endforeach; ?>
    <h3 class="mt-h3">Twoje konta</h3>
    <?php endif; ?>

    <div class="mt-svc" style="--c:var(--pvm-acc)">
      <i class="bi bi-house-door ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Panel współpracownika <span class="mt-badge mt-badge--ok">aktywne</span></h3>
        <p>Login: <code><?= h($email) ?></code> <button class="mt-copy" type="button" data-copy="<?= h($email) ?>" aria-label="Kopiuj login do panelu"><i class="bi bi-copy" aria-hidden="true"></i></button></p>
        <div class="mt-links"><a href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a></div>
      </div>
    </div>

    <?php if ($m365_login || !empty($user['microsoft_id']) || !empty($u_db['microsoft_id'])): $_m_login = $m365_login ?: $email; ?>
    <div class="mt-svc" style="--c:var(--m-indigo)">
      <i class="bi bi-microsoft ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Microsoft 365 <span class="mt-badge mt-badge--ok">aktywne</span></h3>
        <p>Login: <code><?= h($_m_login) ?></code> <button class="mt-copy" type="button" data-copy="<?= h($_m_login) ?>" aria-label="Kopiuj login Microsoft 365"><i class="bi bi-copy" aria-hidden="true"></i></button></p>
        <?php if (!empty($u_db['m365_security_group_name'])): ?><p>Grupa dostępu: <?= h($u_db['m365_security_group_name']) ?></p><?php endif; ?>
        <div class="mt-links">
          <a href="https://portal.office.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (aplikacje)<span class="visually-hidden"> — nowa karta</span></a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($_canva_contract_wolont && $_canva_has_access): ?>
    <div class="mt-svc" style="--c:var(--m-teal)">
      <i class="bi bi-palette ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Canva Pro <span class="mt-badge mt-badge--ok">aktywne</span></h3>
        <?php if (!empty($_active_row['canva_login'])): ?>
        <p>Login: <code><?= h($_active_row['canva_login']) ?></code> <button class="mt-copy" type="button" data-copy="<?= h($_active_row['canva_login']) ?>" aria-label="Kopiuj login Canva"><i class="bi bi-copy" aria-hidden="true"></i></button></p>
        <?php endif; ?>
        <?php if ($_canva_own_account): ?>
        <form method="post" class="d-flex gap-2 mt-2" style="max-width:440px">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_save_canva_account" value="1">
          <input type="hidden" name="canva_contract_id" value="<?= (int)$_active_contract['id'] ?>">
          <label for="canvaOwnLogin" class="visually-hidden">Login Canva (e-mail)</label>
          <input type="email" id="canvaOwnLogin" name="canva_login" class="form-control form-control-sm" placeholder="np. imie.nazwisko@feer.org.pl" value="<?= h($_active_row['canva_login'] ?? '') ?>">
          <button class="mt-btn mt-btn--ghost" style="min-height:0;white-space:nowrap"><i class="bi bi-save" aria-hidden="true"></i>Zapisz</button>
        </form>
        <?php endif; ?>
        <div class="mt-links"><a href="https://www.canva.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Otwórz canva.com — „Continue with Microsoft”<span class="visually-hidden"> — nowa karta</span></a></div>
      </div>
    </div>
    <?php elseif ($_canva_requested): ?>
    <div class="mt-svc" style="--c:var(--m-grey)">
      <i class="bi bi-palette ic" aria-hidden="true"></i>
      <div class="mt-svc__bd"><h3>Canva Pro <span class="mt-badge mt-badge--warn">wniosek wysłany</span></h3><p>Oczekuje na zatwierdzenie przez administratora.</p></div>
    </div>
    <?php elseif ($_canva_can_request): ?>
    <div class="mt-svc" style="--c:var(--m-grey)">
      <i class="bi bi-palette ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Canva Pro</h3><p>Dostęp do zespołu graficznego organizacji.</p>
        <form method="post" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_request_canva" value="1">
          <input type="hidden" name="canva_contract_id" value="<?= (int)$_active_contract['id'] ?>">
          <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-send" aria-hidden="true"></i>Poproś o dostęp</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Poczta — klienci do wyboru (katalog includes/webmail_clients.php) -->
    <div class="mt-svc" style="--c:var(--m-blue)">
      <i class="bi bi-envelope ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Poczta organizacji</h3>
        <p>Ta sama skrzynka w kilku programach do wyboru<?= $_mail_clients ? ' (' . count($_mail_clients) . ')' : '' ?> — wchodzisz zawsze przez <strong><?= h($_mail_chooser_label) ?></strong>.</p>
        <a class="mt-btn mt-2" href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-envelope-open" aria-hidden="true"></i>Otwórz pocztę<span class="visually-hidden"> — nowa karta</span></a>
        <details class="mt-2">
          <summary class="fw-semibold" style="cursor:pointer">Czym różnią się programy?</summary>
          <dl class="mt-2 mb-0" style="font-size:.88rem">
            <dt>Outlook w przeglądarce — do codziennej pracy</dt>
            <dd class="mt-no">Poczta z kalendarzem, Teams, skrzynki współdzielone, reguły i autoodpowiedź, aplikacja na telefon.</dd>
            <dt>Roundcube / SnappyMail — lekko i szybko</dt>
            <dd class="mt-no">Tylko poczta — bez kalendarza i skrzynek współdzielonych; działa na słabym łączu i starszym sprzęcie, logowanie przyciskiem „Microsoft 365”.</dd>
            <dt>Co jest wspólne</dt>
            <dd class="mt-no mb-0">Adres, hasło (konto Microsoft), foldery i wszystkie wiadomości.</dd>
          </dl>
        </details>
      </div>
    </div>
  </section>

  <!-- ═══════════ WIADOMOŚCI ═══════════ -->
  <section id="wiadomosci" data-pvm-panel="wiadomosci" role="tabpanel" aria-labelledby="pvm-tab-wiadomosci" tabindex="-1"<?= $_tab_first !== 'wiadomosci' ? ' hidden' : '' ?>>
    <h2 class="mt-h2">Wiadomości <b>z organizacją</b></h2>
    <?php if ($_pv_msgs): ?>
    <ul class="mt-feed" aria-label="Ostatnie wiadomości">
      <?php foreach ($_pv_msgs as $_m): $_adm = ($_m['sender_type'] ?? '') === 'admin'; ?>
      <li class="<?= $_m['is_unread'] ? 'unread' : '' ?>">
        <span class="av<?= $_adm ? ' av--admin' : '' ?>" aria-hidden="true"><?= h($_pv_initials($_m['sender_name'] ?: 'A')) ?></span>
        <div style="flex:1;min-width:0">
          <div class="meta"><strong><?= h($_m['sender_name'] ?: 'Administrator') ?><?= $_m['is_unread'] ? ' <span class="mt-badge mt-badge--info">nowa</span>' : '' ?></strong><span><?= h($_pv_rel_time((string)$_m['created_at'])) ?></span></div>
          <div class="txt"><?= h($_m['body']) ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="mt-empty"><p class="mb-0">Brak wiadomości w wątku tej umowy.</p></div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <a href="<?= APP_URL ?>/panel/messages.php" class="mt-btn"><i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz wiadomość</a>
      <a href="<?= APP_URL ?>/panel/messages.php" class="mt-btn mt-btn--ghost">Wszystkie wiadomości</a>
    </div>
  </section>

  <?php if ($_pv_extra_html !== ''): ?>
  <?php if ($_pv_peln): ?>
  <!-- ═══════════ PEŁNOMOCNICTWA ═══════════ -->
  <section id="pelnomocnictwa" data-pvm-panel="pelnomocnictwa" role="tabpanel" aria-labelledby="pvm-tab-pelnomocnictwa" tabindex="-1" hidden>
    <h2 class="mt-h2">Moje <b>pełnomocnictwa</b> <span class="mt-no" style="font-size:1rem">(<?= count($_pv_peln) ?> ważne)</span></h2>
    <?php foreach ($_pv_peln as $_p):
      $_kor  = ($_p['rodzaj'] ?? 'ogolne') === 'korespondencja';
      $_zakr = $_kor ? pelnomocnictwo_kor_opis($_p) : implode('; ', pelnomocnictwo_zakres_items($_p));
    ?>
    <div class="mt-svc" style="--c:var(--m-indigo)">
      <i class="bi <?= $_kor ? 'bi-envelope' : 'bi-person-badge' ?> ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3><?= h($_p['numer']) ?> <span class="mt-no" style="font-weight:400">· od <?= h($_p['mocodawca']) ?></span>
          <span class="mt-badge mt-badge--ok">ważne do <?= $_p['data_waznosci'] ? h(date_pl($_p['data_waznosci'])) : 'bezterminowo' ?></span></h3>
        <?php if ($_zakr !== ''): ?><p><?= h($_zakr) ?></p><?php endif; ?>
        <?php if (!empty($_p['dokument_plik'])): ?>
        <div class="mt-links"><a href="<?= APP_URL ?>/pelnomocnictwa/dokument_download.php?id=<?= (int)$_p['id'] ?>"><i class="bi bi-download" aria-hidden="true"></i>Pobierz dokument<span class="visually-hidden"> pełnomocnictwa <?= h($_p['numer']) ?></span></a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ═══════════ WYDARZENIA I REZERWACJE ═══════════ -->
  <section id="wydarzenia" data-pvm-panel="wydarzenia" role="tabpanel" aria-labelledby="pvm-tab-wydarzenia" tabindex="-1" hidden>
    <h2 class="mt-h2">Wydarzenia i <b>rezerwacje</b></h2>
    <?= $_pv_extra_html ?>
  </section>
  <?php endif; ?>
  </main>

  <?php endif; /* $contracts */ ?>
</div><!-- /.pvm -->
