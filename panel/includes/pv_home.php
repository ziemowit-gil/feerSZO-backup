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
<style>
/* ══ Pulpit wolontariusza — Metro UI jak System Tożsamości ═══════════════════
   Kafle „Start” (.mt-grid/.mt-tile, kolor kafla = --c) + nawigacja pivot
   (.mt-pivot, jedna sekcja naraz, #hash) + płaskie grupy (.mt-group).
   Akcent = per-user --vol-color (--m-acc z pv_metro.php). 0 zaokrągleń/cieni. */
.pvtz{--tz:var(--m-acc,var(--vol-color,#1ba1e2));--tz-strong:<?= h($_vol_dark) ?>;--tz-rgb:<?= h($_vol_rgb) ?>;color:var(--m-ink,#111827)}
.pvtz .mt-kicker{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--m-muted);margin-bottom:.35rem}
.pvtz .mt-title{margin:0;font-size:2.5rem;font-weight:300;line-height:1.05;letter-spacing:-.02em;color:var(--m-ink)}
.pvtz .mt-lead{margin:.5rem 0 0;font-size:.95rem;font-weight:300;color:var(--m-muted);max-width:70ch}
.pvtz .mt-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem}
/* kafle */
.pvtz .mt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));grid-auto-rows:150px;gap:8px;grid-auto-flow:dense;margin:0}
@media(max-width:480px){.pvtz .mt-grid{grid-template-columns:repeat(2,1fr);grid-auto-rows:140px}}
.pvtz .mt-tile{position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:14px 16px;color:#fff;text-decoration:none;background:var(--c,var(--m-acc));overflow:hidden;border:0;text-align:left;font:inherit;cursor:pointer;transition:transform .08s,filter .12s;min-width:0}
.pvtz .mt-tile:hover{filter:brightness(1.1);color:#fff;text-decoration:none}
.pvtz .mt-tile:active{transform:scale(.97)}
.pvtz .mt-tile:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.pvtz .mt-tile--w{grid-column:span 2}
.pvtz .mt-tile--l{grid-column:span 2;grid-row:span 2}
.pvtz .mt-tile .ic{font-size:2rem;line-height:1}
.pvtz .mt-tile--l .ic{font-size:2.6rem}
.pvtz .mt-tile .lbl{display:block;font-size:.82rem;font-weight:600;line-height:1.2}
.pvtz .mt-tile .val{display:block;font-size:1.85rem;font-weight:300;line-height:1;letter-spacing:-.02em}
.pvtz .mt-tile--l .val{font-size:2.1rem}
.pvtz .mt-tile .sub{display:block;font-size:.72rem;opacity:.9;margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pvtz .mt-tile .tag{position:absolute;top:10px;right:12px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:rgba(0,0,0,.3);padding:2px 7px}
.pvtz .mt-tile .bar{display:block;height:4px;background:rgba(255,255,255,.3);margin-top:.6rem}
.pvtz .mt-tile .bar>span{display:block;height:100%;background:#fff}
/* pivot */
.pvtz .mt-pivot{display:flex;gap:1.75rem;overflow-x:auto;border-bottom:1px solid var(--m-line);margin:1.75rem 0 1.5rem;scrollbar-width:none}
.pvtz .mt-pivot::-webkit-scrollbar{display:none}
.pvtz .mt-pivot button{background:none;border:0;border-bottom:3px solid transparent;margin-bottom:-1px;padding:.35rem 0 .6rem;font:inherit;font-size:1.3rem;font-weight:300;color:var(--m-muted);cursor:pointer;white-space:nowrap;letter-spacing:-.01em;display:inline-flex;align-items:center;gap:.4rem}
.pvtz .mt-pivot button:hover{color:var(--m-ink)}
.pvtz .mt-pivot button[aria-selected="true"]{color:var(--m-ink);font-weight:600;border-bottom-color:var(--m-acc)}
.pvtz .mt-pivot button:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.pvtz .mt-pivot .cnt{font-size:.66rem;font-weight:700;background:var(--m-red);color:#fff;padding:1px 6px;letter-spacing:.04em}
.pvtz [data-pvm-panel][hidden]{display:none!important}
/* sekcje */
.pvtz .mt-h2{margin:0 0 .75rem;font-size:1.35rem;font-weight:300;letter-spacing:-.01em;color:var(--m-ink)}
.pvtz .mt-h2 b{font-weight:600}
.pvtz .mt-h2 + .mt-group,.pvtz .mt-h2 + .tz-card{margin-top:0}
.pvtz .mt-gap{margin-top:2rem}
.pvtz .mt-group{background:var(--m-light);padding:1.25rem}
.pvtz .mt-group .tz-dl{margin:0 -1.25rem}
.pvtz .mt-group .tz-dl>div{border-top:0;padding:.7rem 1.25rem}
.pvtz .mt-row{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;margin-bottom:1rem}
.pvtz .mt-row .t{font-size:1.1rem;font-weight:600;color:var(--m-ink)}
.pvtz .mt-row .d{font-size:.85rem;color:var(--m-muted)}
/* oś czasu umowy */
.pvtz .pv-tl{margin:1.4rem 0 .2rem}
.pvtz .pv-tl__track{height:6px;background:var(--m-line);position:relative}
.pvtz .pv-tl__fill{position:absolute;left:0;top:0;height:6px;background:var(--m-acc)}
.pvtz .pv-tl__labels{display:flex;justify-content:space-between;gap:.5rem;margin-top:.5rem;font-size:.75rem;color:var(--m-muted)}
.pvtz .pv-tl__labels strong{color:var(--m-ink)}
/* wiadomości */
.pvtz .pv-msgfeed{list-style:none;padding:0;margin:0;background:var(--m-light)}
.pvtz .pv-msg{display:flex;gap:.8rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--m-bg)}
.pvtz .pv-msg:last-child{border-bottom:0}
.pvtz .pv-msg[role="link"]:hover{background:var(--m-light-h)}
.pvtz .pv-msg__av{width:38px;height:38px;background:var(--m-grey);color:#fff;font-size:.75rem;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pvtz .pv-msg__av--admin{background:var(--m-acc)}
.pvtz .pv-msg__body{flex:1;min-width:0}
.pvtz .pv-msg__meta{font-size:.74rem;color:var(--m-muted);display:flex;justify-content:space-between;gap:.4rem;margin-bottom:.15rem}
.pvtz .pv-msg__meta strong{color:var(--m-ink);font-weight:600}
.pvtz .pv-msg__text{font-size:.9rem;color:var(--m-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pvtz .pv-msg--unread .pv-msg__text{font-weight:600}
.pvtz .pv-msg--unread{border-left:6px solid var(--m-acc)}
/* usługi: wiersze z grubym paskiem koloru (.mt-svc) */
.pvtz .mt-svcs{display:grid;gap:8px}
.pvtz .mt-svc{display:flex;align-items:flex-start;gap:1rem;background:var(--m-light);padding:1rem 1.1rem;border-left:8px solid var(--c,var(--m-acc));min-width:0}
.pvtz .mt-svc>.ic{font-size:1.6rem;color:var(--m-ink);width:2.25rem;text-align:center;flex-shrink:0;line-height:1.2}
.pvtz .mt-svc .tz-svc__bd{flex:1;min-width:0}
.pvtz .mt-svc .tz-svc__ttl{font-weight:600;font-size:1rem;color:var(--m-ink);display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
.pvtz .tz-kv{font-size:.85rem;color:var(--m-muted);margin-top:.25rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.pvtz .tz-kv code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--m-ink);background:var(--m-bg);padding:.1rem .4rem;font-size:.85rem;word-break:break-all}
.pvtz .tz-copy{background:none;border:0;padding:.15rem .3rem;cursor:pointer;color:var(--m-muted);line-height:1}
.pvtz .tz-copy:hover{color:var(--m-ink);background:var(--m-bg)}
.pvtz .tz-svc__foot{margin-top:.55rem}
.pvtz .tz-svc__foot a{font-size:.85rem;color:var(--m-ink);text-decoration:underline;text-underline-offset:2px;font-weight:600;display:inline-flex;align-items:center;gap:.35rem}
.pvtz .tz-svc__foot a.text-muted{color:var(--m-muted)!important;font-weight:400}
.pvtz .tz-btn{background:var(--m-acc);color:#fff;border:2px solid var(--m-acc);padding:.5rem 1.1rem;font-weight:600;font-size:.87rem;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none;cursor:pointer;min-height:40px}
.pvtz .tz-btn:hover{background:var(--m-dark);border-color:var(--m-dark);color:#fff}
.pvtz .tz-btn--ghost{background:transparent;color:var(--m-ink);border:2px solid var(--m-ink)}
.pvtz .tz-btn--ghost:hover{background:var(--m-ink);color:var(--m-bg);border-color:var(--m-ink)}
.pvtz .tz-yes{color:var(--m-green)}.pvtz .tz-no{color:var(--m-muted);font-weight:400}
.pvtz .tz-empty{background:var(--m-light);text-align:center;padding:2rem 1rem;color:var(--m-muted)}
/* wybór klienta poczty */
.pvtz .pv-mail-pick{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:.75rem 0 .2rem}
.pvtz .pv-mail-pick--single{grid-template-columns:1fr}
.pvtz .pv-mail-btn{display:flex;flex-direction:column;gap:.1rem;padding:.75rem 1rem;background:var(--m-acc);color:#fff;text-decoration:none;font-weight:600;font-size:.92rem;min-height:44px}
.pvtz .pv-mail-btn:hover,.pvtz .pv-mail-btn:focus-visible{filter:brightness(1.1);color:#fff}
.pvtz .pv-mail-btn span{font-weight:400;font-size:.78rem;color:rgba(255,255,255,.9)}
.pvtz .pv-mail-btn i{margin-right:.35rem}
.pvtz .pv-mail-diff{margin-top:.6rem;font-size:.85rem}
.pvtz .pv-mail-diff>summary{cursor:pointer;font-weight:600;color:var(--m-ink);list-style:none;display:inline-flex;align-items:center;gap:.5rem;min-height:32px}
.pvtz .pv-mail-diff>summary::-webkit-details-marker{display:none}
.pvtz .pv-mail-diff>summary::before{content:'+';width:1.4rem;height:1.4rem;border:2px solid var(--m-ink);display:inline-flex;align-items:center;justify-content:center;font-weight:700;line-height:1}
.pvtz .pv-mail-diff[open]>summary::before{content:'\2212';background:var(--m-ink);color:var(--m-bg)}
.pvtz .pv-mail-diff dl{margin:.6rem 0 0;display:grid;gap:.45rem;background:var(--m-bg);padding:1rem}
.pvtz .pv-mail-diff dt{font-weight:600;color:var(--m-ink)}
.pvtz .pv-mail-diff dd{margin:0 0 .3rem;color:var(--m-muted)}
.pvtz .vol-activity{background:var(--m-surface)}
/* wysoki kontrast */
[data-theme="hc"] .pvtz .mt-tile{background:#000!important;outline:2px solid #000}
[data-theme="hc"] .pvtz .mt-group,[data-theme="hc"] .pvtz .mt-svc,[data-theme="hc"] .pvtz .pv-msgfeed{border:2px solid #000}
[data-theme="hc"] .pvtz .mt-svc{border-left-width:8px;border-left-color:#000}
@media(prefers-reduced-motion:reduce){.pvtz .mt-tile{transition:none}}
</style>

<?php
/* ── Kafle „Start”: kolory palety Metro (odcienie z kontrastem ≥ 4.5:1 dla białego tekstu) ── */
$_mt_pal = ['#007a78', '#0050ef', '#6a00ff', '#b0005e', '#0e7bb3', '#a85b00', '#008a00', '#1d1d1d'];
$_mt_c   = function (int $i) use ($_mt_pal): string { return $_mt_pal[$i % count($_mt_pal)]; };

/* ── Pivot: sekcje (klucz = id/#hash). „Wydarzenia” tylko gdy coś się renderuje. ── */
$_pvm_extra = '';
if ($contracts) {
    ob_start();
    include __DIR__ . '/pv_extra_sections.php';
    $_pvm_extra = trim((string)ob_get_clean());
}
$_pvm_tabs = [
    'umowa'      => 'umowa',
    'wiadomosci' => 'wiadomości',
    'dostepy'    => 'konta i dostępy',
    'wnioski'    => 'wnioski',
];
if ($_pvm_extra !== '') $_pvm_tabs['wydarzenia'] = 'wydarzenia i rezerwacje';
if ($_tasks_tab_on) $GLOBALS['_pv_tasks_section_done'] = true;
?>

<a class="visually-hidden-focusable" href="#pvtz-main">Przejdź do treści głównej</a>

<div class="pvtz" id="pvtz-root">

  <!-- ═══════════ NAGŁÓWEK ═══════════ -->
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel wolontariusza · <?= h(ORG_NAME) ?></div>
      <h1 class="mt-title"><?= h($_greet) ?>, <?= h($_fname_first) ?></h1>
      <p class="mt-lead">Twój panel współpracy — umowa, sprawy, wiadomości i dostępy w jednym miejscu · <?= date('d.m.Y') ?></p>
    </div>
    <?php if (count($contracts) > 1): ?>
    <button type="button" class="tz-btn tz-btn--ghost" data-bs-toggle="modal" data-bs-target="#contractPickerModal" aria-haspopup="dialog">
      <i class="bi bi-arrow-left-right" aria-hidden="true"></i>Zmień umowę
    </button>
    <?php endif; ?>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <?php if ($_show_dir_invite): ?>
  <div class="tz-note" role="complementary" aria-label="Zaproszenie do uzupełnienia profilu">
    <i class="bi bi-people-fill" aria-hidden="true"></i>
    <span style="flex:1">
      <strong style="color:var(--m-ink)">Uzupełnij swój profil w katalogu.</strong>
      Dodaj zdjęcie i krótki opis — łatwiej Cię znajdą i dopasują zadania.
      <a href="<?= APP_URL ?>/directory/profile_edit.php" class="ms-1 fw-semibold" style="color:var(--m-ink)">Uzupełnij profil →</a>
    </span>
    <button type="button" class="tz-copy" aria-label="Zamknij" onclick="this.closest('.tz-note').remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <?php endif; ?>
  <?php if ($_show_sms_nudge): include __DIR__ . '/pv_sms_nudge.php'; endif; ?>

  <?php if (!$contracts): ?>
  <div class="tz-empty">
    <i class="bi bi-file-earmark-x" style="font-size:2.5rem;display:block;margin-bottom:.6rem" aria-hidden="true"></i>
    <p class="fw-semibold mb-1" style="color:var(--m-ink)">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small mb-0">Skontaktuj się z administratorem, aby powiązać umowę z adresem <?= h($email) ?>.</p>
  </div>
  <?php else:
    if ($_active_row) {
        $_ct_type  = $_active_contract['contract_type'] ?? 'wolontariat';
        $_is_wol   = $_ct_type === 'wolontariat';
        $_pesel    = $_active_row['pesel'] ?? '';
        $_pesel_msk = $_pesel ? (substr($_pesel,0,2).'·····'.substr($_pesel,7)) : '';
        $_ct_icons = ['wolontariat'=>'bi-heart-fill','zlecenie'=>'bi-person-workspace','dzielo'=>'bi-brush','praca'=>'bi-briefcase-fill'];
        $_ct_icon  = $_ct_icons[$_ct_type] ?? 'bi-file-earmark-text';
        $_ct_labels= ['wolontariat'=>'Wolontariat','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'];
        $_ct_label = $_ct_labels[$_ct_type] ?? ucfirst($_ct_type);
    }
    $_pct = ($contract_progress && !$_ended) ? min(100, max(0, (int)$contract_progress['pct'])) : null;
  ?>

  <!-- ═══════════ KAFLE (Start) ═══════════ -->
  <div id="pvtz-main" role="region" aria-label="Panel współpracownika">
  <div class="mt-grid" aria-label="Skróty">
    <?php if ($_active_row): ?>
    <a href="#umowa" class="mt-tile mt-tile--l" style="--c:var(--m-acc)" data-pvm-go="umowa"
       aria-label="<?= h($_ct_label) ?>: <?= h($_status_labels[$_st] ?? ucfirst($_st)) ?><?= $_pct !== null ? ', pozostało ' . (int)$contract_progress['days_left'] . ' dni' : '' ?> — szczegóły umowy">
      <span class="tag"><?= h($_status_labels[$_st] ?? ucfirst($_st)) ?></span>
      <i class="bi <?= $_ct_icon ?> ic" aria-hidden="true"></i>
      <span>
        <span class="val tw-truncate"><?= h($_ct_label) ?></span>
        <span class="lbl" style="margin-top:.35rem"><?= h($_active_row['imie_nazwisko'] ?? '') ?><?= $_is_guardian ? ' · opiekun' : '' ?></span>
        <span class="sub">
          <?= !empty($_active_contract['numer_umowy']) ? 'Nr ' . h($_active_contract['numer_umowy']) . ' · ' : '' ?>
          <?php if ($_active_row['data_zawarcia'] ?? null): ?>od <?= date('d.m.Y', strtotime($_active_row['data_zawarcia'])) ?><?php endif; ?>
          <?php if ($_active_contract['data_zakonczenia'] ?? null): ?> do <?= date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) ?><?php elseif (!empty($_active_row['bezterminowa'])): ?> · bezterminowo<?php endif; ?>
        </span>
        <?php if ($_pct !== null): ?>
        <span class="sub">pozostało <b><?= (int)$contract_progress['days_left'] ?></b> dni · <?= $_pct ?>%</span>
        <span class="bar" aria-hidden="true"><span style="width:<?= $_pct ?>%"></span></span>
        <?php elseif ($_ended): ?>
        <span class="sub">umowa zakończona</span>
        <?php endif; ?>
      </span>
    </a>
    <?php else: ?>
    <a href="#umowa" class="mt-tile mt-tile--l" style="--c:var(--m-grey)" data-pvm-go="umowa">
      <i class="bi bi-file-earmark-text ic" aria-hidden="true"></i>
      <span><span class="val">Twoja umowa</span><span class="sub">brak aktywnej umowy do wyświetlenia</span></span>
    </a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/panel/apply.php" class="mt-tile mt-tile--w" style="--c:<?= $_pending_total > 0 ? '#b84d00' : '#008a00' ?>" aria-label="Sprawy do uwagi: <?= $_pending_total ?>">
      <span class="tag"><?= $_pending_total > 0 ? 'do uwagi' : 'ok' ?></span>
      <i class="bi bi-bell ic" aria-hidden="true"></i>
      <span><span class="val"><?= $_pending_total ?></span><span class="lbl" style="margin-top:.25rem">Spraw do uwagi</span><span class="sub">wnioski, zaświadczenia, zwroty, rozwiązania</span></span>
    </a>

    <a href="<?= APP_URL ?>/panel/messages.php" class="mt-tile" style="--c:<?= $msg_unread > 0 ? '#a20025' : '#0050ef' ?>" aria-label="Wiadomości: <?= $msg_unread ?> nowych">
      <?php if ($msg_unread): ?><span class="tag">nowe</span><?php endif; ?>
      <i class="bi bi-chat-dots ic" aria-hidden="true"></i>
      <span><span class="val"><?= $msg_unread > 99 ? '99+' : $msg_unread ?></span><span class="lbl">Nowych wiadomości</span></span>
    </a>

    <?php if ($_tasks_tab_on): ?>
    <a href="https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u" target="_blank" rel="noopener"
       class="mt-tile" style="--c:#a85b00" aria-label="Otwórz tablicę Trello — moduł Zadań tymczasowo niedostępny, zadania tymczasowo w Trello">
      <span class="tag">tymczasowo</span>
      <i class="bi bi-trello ic" aria-hidden="true"></i>
      <span><span class="lbl">Zadania — Trello</span><span class="sub">moduł Zadań tymczasowo niedostępny</span></span>
    </a>
    <?php endif; ?>

    <?php foreach ($_pv_all_actions as $_i => $_qa):
      $_qa_badge = (int)($_qa['badge'] ?? 0);
      $_qa_aria  = $_qa['label'] . ($_qa_badge ? " — {$_qa_badge} wymaga uwagi" : '');
    ?>
    <a href="<?= h($_qa['href']) ?>" class="mt-tile" style="--c:<?= $_mt_c($_i) ?>" aria-label="<?= h($_qa_aria) ?>">
      <?php if ($_qa_badge): ?><span class="tag"><?= $_qa_badge > 99 ? '99+' : $_qa_badge ?></span><?php elseif (!empty($_qa['count'])): ?><span class="tag"><?= (int)$_qa['count'] ?></span><?php endif; ?>
      <i class="bi <?= h($_qa['icon']) ?> ic" aria-hidden="true"></i>
      <span><span class="lbl"><?= h($_qa['label']) ?></span><?php if (!empty($_qa['sub'])): ?><span class="sub"><?= h($_qa['sub']) ?></span><?php endif; ?></span>
    </a>
    <?php endforeach; ?>

    <?php if (is_file(dirname(__DIR__, 2) . '/tozsamosc/index.php')): ?>
    <a href="<?= APP_URL ?>/tozsamosc/" class="mt-tile" style="--c:#1d1d1d">
      <i class="bi bi-fingerprint ic" aria-hidden="true"></i>
      <span><span class="lbl">Tożsamość</span><span class="sub">hasło, telefon, MFA</span></span>
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/panel/password.php" class="mt-tile" style="--c:#4d4d4d">
      <i class="bi bi-gear ic" aria-hidden="true"></i>
      <span><span class="lbl">Ustawienia konta</span><span class="sub">hasło i logowanie</span></span>
    </a>
  </div>

  <!-- ═══════════ PIVOT ═══════════ -->
  <div class="mt-pivot" role="tablist" aria-label="Sekcje panelu" data-pvm-pivot>
    <?php foreach ($_pvm_tabs as $_tk => $_tl): ?>
    <button type="button" role="tab" id="pvm-tab-<?= $_tk ?>" aria-controls="<?= $_tk ?>"
            aria-selected="<?= $_tk === 'umowa' ? 'true' : 'false' ?>" tabindex="<?= $_tk === 'umowa' ? '0' : '-1' ?>"
            data-pvm-tab="<?= $_tk ?>"><?= h($_tl) ?><?php if ($_tk === 'wiadomosci' && $msg_unread): ?><span class="cnt" aria-label="<?= $msg_unread ?> nowych"><?= $msg_unread > 99 ? '99+' : $msg_unread ?></span><?php endif; ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════ UMOWA ═══════════ -->
  <section id="umowa" role="tabpanel" aria-labelledby="pvm-tab-umowa" tabindex="-1" data-pvm-panel>
    <h2 class="mt-h2">Twoja <b>umowa</b></h2>
    <div class="mt-group">
      <?php if ($_active_row): ?>
      <div class="mt-row">
        <span class="t" id="bw-heading"><?= h($_ct_label) ?><?php if ($_active_row['imie_nazwisko'] ?? null): ?><span style="color:var(--m-muted);font-weight:400"> · <?= h($_active_row['imie_nazwisko']) ?></span><?php endif; ?></span>
        <span class="pv-status-badge <?= $_ended ? 'is-ended' : 'is-active' ?>"><?= h($_status_labels[$_st] ?? ucfirst($_st)) ?></span>
        <?php if ($_is_guardian): ?><span class="tz-badge tz-badge--wait"><i class="bi bi-person-hearts" aria-hidden="true"></i>Opiekun</span><?php endif; ?>
        <?php if (($_active_row['data_zawarcia'] ?? null) || ($_active_contract['data_zakonczenia'] ?? null) || !empty($_active_row['bezterminowa'])): ?>
        <span class="d">
          <?php if ($_active_row['data_zawarcia'] ?? null): ?>od <?= date('d.m.Y', strtotime($_active_row['data_zawarcia'])) ?><?php endif; ?>
          <?php if ($_active_contract['data_zakonczenia'] ?? null): ?> → <?= date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) ?><?php elseif (!empty($_active_row['bezterminowa'])): ?> → bezterminowo<?php endif; ?>
        </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($_active_contract['numer_umowy']) || ($_is_wol && ($_active_row['miejsce_wolontariatu'] ?? null))
             || ($_is_wol && ($_active_row['godzin_tygodniowo'] ?? null)) || ($_is_wol && ($_active_row['opiekun'] ?? null))
             || $_pesel || ($_active_row['telefon'] ?? null)): ?>
      <dl class="tz-dl">
        <?php if (!empty($_active_contract['numer_umowy'])): ?><div><dt>Nr umowy</dt><dd><?= h($_active_contract['numer_umowy']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['miejsce_wolontariatu'] ?? null)): ?><div><dt>Miejsce</dt><dd><?= h($_active_row['miejsce_wolontariatu']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['godzin_tygodniowo'] ?? null)): ?><div><dt>Godzin tyg.</dt><dd><?= h($_active_row['godzin_tygodniowo']) ?> h</dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['opiekun'] ?? null)): ?><div><dt>Opiekun</dt><dd><?= h($_active_row['opiekun']) ?></dd></div><?php endif; ?>
        <?php if ($_pesel): ?><div><dt>PESEL</dt><dd style="font-family:ui-monospace,monospace;font-size:.92rem"><?= h($_pesel_msk) ?></dd></div><?php endif; ?>
        <?php if ($_active_row['telefon'] ?? null): ?><div><dt>Telefon</dt><dd><?= h($_active_row['telefon']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['godzin_przepracowanych'] ?? null)): ?>
        <div><dt>Godz. przepracowane</dt><dd><?= h(number_format((float)$_active_row['godzin_przepracowanych'], 2, ',', ' ')) ?> h
          <?php if ((float)($_active_row['godzin_z_zadan'] ?? 0) > 0): ?><small style="color:var(--m-muted)">(<?= h(number_format((float)$_active_row['godzin_z_zadan'], 2, ',', ' ')) ?> h z zadań)</small><?php endif; ?></dd></div>
        <?php endif; ?>
        <?php if ($_is_wol): ?>
        <div><dt>BHP</dt><dd><?= !empty($_active_row['szkolenie_bhp']) ? '<span class="tz-yes"><i class="bi bi-check-square-fill"></i> Tak</span>' : '<span class="tz-no">Nie</span>' ?></dd></div>
        <div><dt>NNW</dt><dd><?= !empty($_active_row['ubezpieczenie_nnw']) ? '<span class="tz-yes"><i class="bi bi-check-square-fill"></i> Tak</span>' : '<span class="tz-no">Nie</span>' ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php endif; ?>

      <?php if ($_pct !== null): ?>
      <div class="pv-tl" aria-hidden="true">
        <div class="pv-tl__track"><div class="pv-tl__fill" style="width:<?= $_pct ?>%"></div></div>
        <div class="pv-tl__labels">
          <span><?= ($_active_row['data_zawarcia'] ?? null) ? date('d.m.Y', strtotime($_active_row['data_zawarcia'])) : 'start' ?></span>
          <span>pozostało <strong><?= (int)$contract_progress['days_left'] ?></strong> dni · <?= $_pct ?>%</span>
          <span><?= ($_active_contract['data_zakonczenia'] ?? null) ? date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) : '' ?></span>
        </div>
      </div>
      <div role="progressbar"
           aria-valuenow="<?= $_pct ?>" aria-valuemin="0" aria-valuemax="100"
           aria-valuetext="Wykorzystano <?= $_pct ?>% umowy, pozostało <?= (int)$contract_progress['days_left'] ?> dni"
           aria-label="Postęp umowy" class="visually-hidden"></div>
      <?php elseif ($_ended): ?>
      <p class="small mt-3 mb-0" style="color:var(--m-muted)"><i class="bi bi-flag me-1" aria-hidden="true"></i>Umowa zakończona.</p>
      <?php endif; ?>
      <?php else: ?>
      <p class="mb-0" style="color:var(--m-muted)" id="bw-heading">Brak aktywnej umowy do wyświetlenia.</p>
      <?php endif; ?>
    </div>

    <?php if ($_active_row && !empty($_active_row['adres'])): ?>
    <h2 class="mt-h2 mt-gap" id="pv-addr-heading">Dane <b>kontaktowe</b></h2>
    <div class="mt-group" aria-labelledby="pv-addr-heading">
      <dl class="tz-dl">
        <div style="grid-column:1/-1"><dt>Adres korespondencyjny</dt><dd><?= h($_active_row['adres']) ?></dd></div>
        <?php if ($_active_row['data_urodzenia'] ?? null): ?><div><dt>Data urodzenia</dt><dd><?= h(date('d.m.Y', strtotime($_active_row['data_urodzenia']))) ?></dd></div><?php endif; ?>
        <?php if ($_active_row['projekt_program'] ?? null): ?><div><dt>Projekt / program</dt><dd><?= h($_active_row['projekt_program']) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <?php endif; ?>
  </section>

  <!-- ═══════════ WIADOMOŚCI ═══════════ -->
  <section id="wiadomosci" role="tabpanel" aria-labelledby="pvm-tab-wiadomosci" tabindex="-1" data-pvm-panel>
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
      <h2 class="mt-h2" id="bm-heading">Ostatnie <b>wiadomości</b></h2>
      <a href="<?= APP_URL ?>/panel/messages.php" class="tz-btn tz-btn--ghost" style="min-height:34px;padding:.3rem .8rem;font-size:.8rem">Wszystkie <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($_bento_msgs): ?>
    <ul class="pv-msgfeed" aria-label="Ostatnie wiadomości" role="list" data-href="<?= APP_URL ?>/panel/messages.php">
      <?php foreach ($_bento_msgs as $_bmsg): ?>
      <li class="pv-msg<?= $_bmsg['is_unread'] ? ' pv-msg--unread' : '' ?>">
        <span class="pv-msg__av<?= $_bmsg['sender_type'] === 'admin' ? ' pv-msg__av--admin' : '' ?>" aria-hidden="true"><?= $_pv_initials($_bmsg['sender_name'] ?: 'A') ?></span>
        <div class="pv-msg__body">
          <div class="pv-msg__meta">
            <strong><?= h($_bmsg['sender_name'] ?: 'Administrator') ?></strong>
            <span><?= $_pv_rel_time($_bmsg['created_at']) ?></span>
          </div>
          <div class="pv-msg__text"><?= h($_bmsg['body']) ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="mt-3 mb-0">
      <a href="<?= APP_URL ?>/panel/messages.php" class="tz-btn"><i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz nową wiadomość</a>
    </p>
    <?php else: ?>
    <div class="tz-empty">
      <i class="bi bi-chat-dots" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.6" aria-hidden="true"></i>
      <p class="mb-2" style="font-size:.9rem">Brak wiadomości w tym wątku.</p>
      <a href="<?= APP_URL ?>/panel/messages.php" class="tz-btn"><i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz do nas</a>
    </div>
    <?php endif; ?>
  </section>

  <!-- ═══════════ KONTA I DOSTĘPY ═══════════ -->
  <section id="dostepy" role="tabpanel" aria-labelledby="pvm-tab-dostepy" tabindex="-1" data-pvm-panel>
    <?php if ($_my_children): ?>
    <h2 class="mt-h2">Konta <b>dzieci</b></h2>
    <div class="mt-svcs" style="margin-bottom:2rem">
      <?php foreach ($_my_children as $_ch): ?>
      <form method="post" action="<?= APP_URL ?>/auth/enter_child.php" class="mt-svc" style="--c:#b0005e;align-items:center">
        <i class="bi bi-person-hearts ic" aria-hidden="true"></i>
        <span class="tz-svc__bd"><strong><?= h($_ch['name']) ?></strong><br><span style="color:var(--m-muted);font-size:.8rem"><?= h($_ch['email']) ?></span></span>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="child" value="<?= (int)$_ch['id'] ?>">
        <button class="tz-btn" style="white-space:nowrap"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź na konto</button>
      </form>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="mt-h2" id="pv-accounts-heading">Konta i <b>dostępy</b> do systemów</h2>
    <div class="mt-svcs" aria-labelledby="pv-accounts-heading">

      <!-- Portal wolontariusza -->
      <div class="mt-svc" style="--c:#008a00">
        <i class="bi bi-house-door-fill ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Portal wolontariusza <span class="tz-badge tz-badge--ok"><i class="bi bi-check-lg" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Login: <code><?= h($email) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($email)) ?>, this)" aria-label="Kopiuj login portalu"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <div class="tz-svc__foot"><a href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a></div>
        </div>
      </div>

      <!-- Microsoft 365 -->
      <?php if ($m365_login): ?>
      <div class="mt-svc" style="--c:#0050ef">
        <i class="bi bi-microsoft ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok"><i class="bi bi-check-lg" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Login: <code><?= h($m365_login) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($m365_login)) ?>, this)" aria-label="Kopiuj login M365"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <?php if (!empty($u_db['m365_security_group_name'])): ?><div class="tz-kv">Grupa dostępu: <?= h($u_db['m365_security_group_name']) ?></div><?php endif; ?>
          <div class="tz-svc__foot d-flex flex-wrap gap-3">
            <a href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta: <?= h($_mail_chooser_label) ?></a>
            <a href="https://portal.office.com" target="_blank" rel="noopener" class="text-muted"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)</a>
          </div>
        </div>
      </div>
      <?php elseif (!empty($user['microsoft_id']) || !empty($u_db['microsoft_id'])): ?>
      <div class="mt-svc" style="--c:#0050ef">
        <i class="bi bi-microsoft ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok"><i class="bi bi-check-lg" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Zaloguj adresem e-mail: <code><?= h($email) ?></code></div>
          <div class="tz-svc__foot d-flex flex-wrap gap-3">
            <a href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta: <?= h($_mail_chooser_label) ?></a>
            <a href="https://portal.office.com" target="_blank" rel="noopener" class="text-muted"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)</a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Canva -->
      <?php if ($_canva_contract_wolont && $_canva_has_access): ?>
      <div class="mt-svc" style="--c:#007a78">
        <i class="bi bi-palette-fill ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Canva Pro <span class="tz-badge tz-badge--ok"><i class="bi bi-check-lg" aria-hidden="true"></i>Aktywne</span></div>
          <?php if (!empty($_active_row['canva_login'])): ?>
          <div class="tz-kv">Login: <code><?= h($_active_row['canva_login']) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($_active_row['canva_login'])) ?>, this)" aria-label="Kopiuj login Canva"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <?php endif; ?>
          <?php if ($_canva_own_account): ?>
          <form method="post" class="d-flex gap-2 mt-2" style="max-width:420px">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_save_canva_account" value="1">
            <input type="hidden" name="canva_contract_id" value="<?= (int)$_active_contract['id'] ?>">
            <label for="canvaOwnLogin" class="visually-hidden">Login Canva (e-mail)</label>
            <input type="email" id="canvaOwnLogin" name="canva_login" class="form-control form-control-sm" placeholder="np. imie.nazwisko@feer.org.pl" value="<?= h($_active_row['canva_login'] ?? '') ?>">
            <button class="tz-btn tz-btn--ghost" style="white-space:nowrap"><i class="bi bi-save" aria-hidden="true"></i>Zapisz</button>
          </form>
          <?php endif; ?>
          <div class="tz-svc__foot"><a href="https://www.canva.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Otwórz canva.com — zaloguj przez „Continue with Microsoft"</a></div>
        </div>
      </div>
      <?php elseif ($_canva_requested): ?>
      <div class="mt-svc" style="--c:#b84d00">
        <i class="bi bi-palette-fill ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Canva Pro <span class="tz-badge tz-badge--wait"><i class="bi bi-clock" aria-hidden="true"></i>Wniosek wysłany</span></div>
          <div class="tz-kv">Oczekuje na zatwierdzenie przez administratora.</div>
        </div>
      </div>
      <?php elseif ($_canva_can_request): ?>
      <div class="mt-svc" style="--c:#666666">
        <i class="bi bi-palette-fill ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Canva Pro</div>
          <div class="tz-kv">Dostęp do zespołu graficznego organizacji.</div>
          <form method="post" class="tz-svc__foot">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_request_canva" value="1">
            <input type="hidden" name="canva_contract_id" value="<?= (int)$_active_contract['id'] ?>">
            <button type="submit" class="tz-btn tz-btn--ghost"><i class="bi bi-send" aria-hidden="true"></i>Poproś o dostęp</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- Poczta — dwa klienty do wyboru (komunikat: od 1 IX 2026) -->
      <div class="mt-svc" style="--c:#6a00ff">
        <i class="bi bi-envelope-fill ic" aria-hidden="true"></i>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Poczta organizacji
            <span class="tz-badge tz-badge--new"><i class="bi bi-stars" aria-hidden="true"></i>Nowość: wybór klienta</span>
          </div>
          <div class="tz-kv" style="display:block">
            <strong>Od 1 września 2026</strong> sam wybierasz, w czym czytasz służbową pocztę —
            <strong>korzystać możesz już teraz</strong>, bez zgłaszania czegokolwiek.
            To ta sama skrzynka i te same wiadomości; różni się tylko widok i to, co program
            dodatkowo potrafi. Wybór jest odwracalny — możesz używać kilku klientów równolegle.
          </div>

          <div class="pv-mail-pick pv-mail-pick--single">
            <a class="pv-mail-btn pv-mail-btn--main" href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer">
              <span style="font-weight:600;color:inherit;font-size:.92rem"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Otwórz pocztę — <?= h($_mail_chooser_label) ?></span>
              <span>jeden adres do zapamiętania; tam wybierasz klienta<?php
                if ($_mail_clients) { echo ' (' . count($_mail_clients) . ' do wyboru)'; } ?></span>
            </a>
          </div>

          <details class="pv-mail-diff">
            <summary>Czym się różnią?</summary>
            <dl>
              <dt><i class="bi bi-microsoft" aria-hidden="true"></i> Outlook w przeglądarce — zalecany do codziennej pracy</dt>
              <dd>Poczta razem z <strong>kalendarzem</strong> i spotkaniami, <strong>Teams</strong>,
                  <strong>skrzynki współdzielone</strong> (np. fundacja@feer.org.pl) i dostęp w zastępstwie,
                  <strong>reguły i autoodpowiedź</strong>, aplikacja na telefon z powiadomieniami,
                  wyszukiwanie w całej skrzynce.</dd>
              <dt><i class="bi bi-envelope-open" aria-hidden="true"></i> Roundcube / SnappyMail — gdy chcesz lekko i szybko</dt>
              <dd>Tylko poczta: <strong>bez kalendarza, Teams i skrzynek współdzielonych</strong>.
                  W zamian otwierają się na <strong>słabym łączu i starszym sprzęcie</strong>, logujesz się
                  jednym przyciskiem „Microsoft 365" (bez osobnego hasła). Roundcube dokłada
                  załączniki z <strong>OneDrive i ownCloud</strong> oraz kontakty kopiowane z Outlooka;
                  SnappyMail ma najlżejszy interfejs i da się go „zainstalować" na telefonie.</dd>
              <dt><i class="bi bi-signpost-split" aria-hidden="true"></i> Skąd wchodzić</dt>
              <dd>Zawsze z <strong><?= h($_mail_chooser_label) ?></strong> — to nasz własny adres i sam
                  kieruje dalej. Nie musisz pamiętać adresów Microsoftu ani Roundcube; jeśli kiedyś
                  zmienimy klienta, ten adres zostanie ten sam.</dd>
              <dt><i class="bi bi-info-circle" aria-hidden="true"></i> Co jest wspólne</dt>
              <dd>Adres, hasło (konto Microsoft), foldery i wszystkie wiadomości. Reguły, podpis
                  i autoodpowiedź ustawione w Outlooku działają na serwerze, więc obowiązują też
                  w Roundcube.</dd>
            </dl>
          </details>

          <div class="tz-svc__foot"><a href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Pełne zestawienie i wybór: <?= h(preg_replace('~^https?://~', '', $_mail_chooser)) ?></a></div>
        </div>
      </div>

    </div>
  </section>

  <!-- ═══════════ WNIOSKI ═══════════ -->
  <section id="wnioski" role="tabpanel" aria-labelledby="pvm-tab-wnioski" tabindex="-1" data-pvm-panel>
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
      <h2 class="mt-h2" id="pv-act-heading">Ostatnie <b>wnioski</b></h2>
      <a href="<?= APP_URL ?>/panel/apply.php" class="tz-btn" style="min-height:34px;padding:.3rem .8rem;font-size:.8rem"><i class="bi bi-send" aria-hidden="true"></i>Złóż nowy</a>
    </div>
    <?php if ($_pv_apps): ?>
    <?php include __DIR__ . '/pv_apps_activity.php'; ?>
    <?php else: ?>
    <div class="tz-empty">
      <i class="bi bi-send" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.6" aria-hidden="true"></i>
      <p class="fw-semibold mb-2" style="font-size:.9rem">Brak ostatnich wniosków.</p>
      <a href="<?= APP_URL ?>/panel/apply.php" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i>Wyślij pismo / złóż wniosek</a>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($_pvm_extra !== ''): ?>
  <!-- ═══════════ WYDARZENIA I REZERWACJE ═══════════ -->
  <section id="wydarzenia" role="tabpanel" aria-labelledby="pvm-tab-wydarzenia" tabindex="-1" data-pvm-panel>
    <?= $_pvm_extra ?>
  </section>
  <?php endif; ?>
  </div><!-- /#pvtz-main -->

  <?php endif; /* $contracts */ ?>
</div><!-- /pvtz -->

<script>
/* Kopiowanie do schowka */
function pvCopy(text, btn) {
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(text).then(function () {
    var i = btn.querySelector('i'), old = i ? i.className : '';
    if (i) { i.className = 'bi bi-check-lg'; btn.style.color = '#008a00'; }
    setTimeout(function () {
      if (i) { i.className = old || 'bi bi-copy'; btn.style.color = ''; }
    }, 1600);
  });
}
/* Pivot: jedna sekcja naraz, #hash, klawiatura (strzałki/Home/End). Bez JS widać wszystkie sekcje. */
(function () {
  var piv = document.querySelector('[data-pvm-pivot]');
  if (!piv) return;
  var tabs = Array.prototype.slice.call(piv.querySelectorAll('[data-pvm-tab]'));
  var keys = tabs.map(function (t) { return t.dataset.pvmTab; });
  function show(key, focus, push) {
    if (keys.indexOf(key) < 0) key = keys[0];
    tabs.forEach(function (t) {
      var on = t.dataset.pvmTab === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      if (on && focus) t.focus();
    });
    keys.forEach(function (k) { var p = document.getElementById(k); if (p) p.hidden = (k !== key); });
    if (push && history.replaceState) history.replaceState(null, '', '#' + key);
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.dataset.pvmTab, false, true); });
    t.addEventListener('keydown', function (e) {
      var n = null;
      if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
      else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
      else if (e.key === 'Home') n = 0;
      else if (e.key === 'End') n = tabs.length - 1;
      if (n !== null) { e.preventDefault(); show(keys[n], true, true); }
    });
  });
  document.querySelectorAll('[data-pvm-go]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      show(a.dataset.pvmGo, false, true);
      piv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  window.addEventListener('hashchange', function () { show(location.hash.slice(1), false, false); });
  show(location.hash.slice(1), false, false);
}());
/* Wiadomości: klik → pełny widok */
(function () {
  var feed = document.querySelector('.pv-msgfeed[data-href]');
  if (!feed) return;
  var href = feed.dataset.href;
  feed.querySelectorAll('.pv-msg').forEach(function (li) {
    li.style.cursor = 'pointer';
    li.setAttribute('role', 'link');
    li.setAttribute('tabindex', '0');
    li.setAttribute('aria-label', (li.querySelector('.pv-msg__text') || {}).textContent || 'Wiadomość');
    li.addEventListener('click', function () { location.href = href; });
    li.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); location.href = href; } });
  });
}());
</script>
