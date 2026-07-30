<?php
/**
 * panel/includes/pv_home.php — Główny widok „Mojego panelu" wolontariusza.
 *
 * Styl i struktura odwzorowują moduł „Tożsamość" (tozsamosc/_head.php): karty
 * .tz-card, kafelki .tz-tile, siatka .tz-dl, zakładki .tz-subnav / .tz-panel.
 * Różnica świadoma: akcent = per-user --vol-color (nie stały #1E6DFF), aby
 * zachować personalizację panelu (panel/panel_color.php).
 *
 * Widok dzieli się na DWIE zakładki (role=tablist, obsługa klawiatury, #hash):
 *   1) „Formalne / Umowa"        — status, dane umowy, sprawy formalne, wnioski.
 *   2) „Narzędzia do codziennej pracy" — konta/dostępy, zadania, narzędzia, skróty.
 *
 * Zmienne wejściowe (liczone w panel/index.php PRZED include): $user,
 * $_active_row, $_active_contract, $contract_progress, $contracts, $_vol_color,
 * $_is_guardian, $msg_unread, $my_apps, $my_apps_new, $my_certs,
 * $my_certs_pending, $my_terms, $my_terms_pending, $_active_zwroty,
 * $_zwroty_pending, $my_letters_count, $_sms_login_available.
 *
 * Dostępność: WCAG 2.1 AA — semantyczne nagłówki, aria dla zakładek/progresu,
 * widoczny focus (outline), duże obszary klikalne, kontrast tekstu ≥ 4.5:1.
 */
if (!defined('APP_URL')) { return; }
$ROOT = dirname(__DIR__, 2);

/* ── Kolory: akcent per-user + warianty do rgba()/gradientów ─────────────── */
$_vol_color = $_vol_color ?? '#1E6DFF';
$_vol_rgb   = (function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
})($_vol_color);
$_vol_dark = (function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return sprintf('#%02x%02x%02x',
        (int)(hexdec(substr($hex,0,2))*.70),
        (int)(hexdec(substr($hex,2,2))*.70),
        (int)(hexdec(substr($hex,4,2))*.70));
})($_vol_color);

/* ── Dane platform (login portalu / M365 / Moodle) ───────────────────────── */
$u_db = db_one(
    "SELECT u.*, ou.name AS org_unit_name
       FROM users u LEFT JOIN org_units ou ON ou.id = u.org_unit_id
      WHERE u.id = ?",
    [(int)$user['id']]
) ?: [];
$email        = $user['email'] ?? ($u_db['email'] ?? '');
$m365_login   = $u_db['m365_login']   ?? ($_active_row['m365_login'] ?? '');
$moodle_login = $u_db['moodle_login'] ?? '';
$moodle_url   = '';
try {
    require_once $ROOT . '/includes/moodle.php';
    $moodle_url = rtrim((function_exists('moodle_setting') ? moodle_setting('url') : '') ?: (org_setting('moodle_url') ?: ''), '/');
} catch (\Throwable $e) {}

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
if (module_enabled('letters_enabled') && $my_letters_count) $_pv_formal[] = ['href'=>APP_URL.'/panel/letters.php','icon'=>'bi-archive','label'=>'Pisma','sub'=>'korespondencja','badge'=>0,'count'=>$my_letters_count];
if (is_file($ROOT.'/panel/zgody.php')) $_pv_formal[] = ['href'=>APP_URL.'/panel/zgody.php','icon'=>'bi-file-earmark-check','label'=>'Zgody i oświadczenia','sub'=>'zgody do umowy'];
if (is_file($ROOT.'/panel/rodo.php')) $_pv_formal[] = ['href'=>APP_URL.'/panel/rodo.php','icon'=>'bi-shield-lock','label'=>'Twoje dane (RODO)','sub'=>'jak przetwarzamy dane'];
if (module_enabled('terminations_enabled')) $_pv_formal[] = ['href'=>APP_URL.'/panel/terminations.php','icon'=>'bi-file-earmark-x','label'=>'Zakończ współpracę','sub'=>count($my_terms) ? 'wnioski złożone' : 'wniosek o rozwiązanie','badge'=>$my_terms_pending];

/* ── Narzędzia codzienne (kafelki zakładki 2) ────────────────────────────── */
$_pv_daily = [];
$_pv_daily[] = ['href'=>APP_URL.'/panel/messages.php','icon'=>'bi-chat-left-text','label'=>'Wiadomości','sub'=>$msg_unread ? "$msg_unread nowych" : 'napisz do nas','badge'=>$msg_unread];
$_pv_daily[] = ['href'=>APP_URL.'/panel/helpdesk.php','icon'=>'bi-headset','label'=>'Helpdesk IT','sub'=>$_hd_my_open ? "$_hd_my_open otwartych" : 'zgłoś problem','badge'=>$_hd_my_open];
if (is_file($ROOT.'/panel/calendar.php')) $_pv_daily[] = ['href'=>APP_URL.'/panel/calendar.php','icon'=>'bi-calendar3','label'=>'Kalendarz','sub'=>'wydarzenia i dyżury'];
if (module_enabled('procedures_enabled')) $_pv_daily[] = ['href'=>APP_URL.'/panel/procedures.php','icon'=>'bi-journal-text','label'=>'Procedury','sub'=>'instrukcje i wzory'];
if (module_enabled('org_documents_enabled')) $_pv_daily[] = ['href'=>APP_URL.'/panel/org_documents.php','icon'=>'bi-folder2-open','label'=>'Dokumenty organizacji','sub'=>'statut, regulaminy'];
if (module_enabled('whatsapp_group_enabled') && org_setting('whatsapp_group_link')) $_pv_daily[] = ['href'=>APP_URL.'/panel/whatsapp_group.php','icon'=>'bi-whatsapp','label'=>'Grupa WhatsApp','sub'=>'dołącz do grupy'];

/* ── Aktywność: ostatnie wnioski ─────────────────────────────────────────── */
$_pv_apps = $my_apps ? array_map(fn($a) => [
    'tytul' => $a['tytul'], 'type_label' => $a['type_label'] ?? '', 'type_icon' => $a['type_icon'] ?? 'bi-file-text',
    'status' => $a['status'], 'created_at' => $a['created_at'], 'odpowiedz' => $a['odpowiedz'] ?? '',
], $my_apps) : [];

/* ── Zakładka „Zadania": tylko gdy moduł włączony (ten sam warunek co widżet) ── */
$_tasks_tab_on = false;
try { $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'"); $_tasks_tab_on = ($_tm['value'] ?? '1') !== '0'; } catch (\Throwable $e) {}
/* Domyślna zakładka: „Zadania" (pierwsza, najważniejsza codziennie); gdy wyłączona → „Formalne". */
$_default_tab = $_tasks_tab_on ? 'zadania' : 'formalne';

/* Helper: render kafelka .tz-tile ----------------------------------------- */
$pv_tile = function (array $t): void {
    $badge = (int)($t['badge'] ?? 0);
    $aria  = $t['label'] . ($badge ? " — {$badge} wymaga uwagi" : '');
    ?>
    <a class="tz-tile" href="<?= h($t['href']) ?>" aria-label="<?= h($aria) ?>">
      <?php if ($badge): ?><span class="tz-tile__badge" aria-hidden="true"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
      <span class="tz-tile__ico" aria-hidden="true"><i class="bi <?= h($t['icon']) ?>"></i></span>
      <span class="tz-tile__ttl"><?= h($t['label']) ?></span>
      <?php if (!empty($t['sub'])): ?><span class="tz-tile__sub"><?= h($t['sub']) ?></span><?php endif; ?>
    </a>
    <?php
};
?>
<style>
/* ══ Panel wolontariusza w stylu „Tożsamość" — akcent = --vol-color ═══════ */
.pvtz{
  --tz:var(--vol-color,#1E6DFF);
  --tz-strong:<?= h($_vol_dark) ?>;
  --tz-rgb:<?= h($_vol_rgb) ?>;
  --tz-50:rgba(var(--tz-rgb),.08);
  --tz-line:#E5E9F0;--tz-muted:#5b6472;--tz-ink:#111827;
  max-width:960px;margin:0 auto;color:var(--tz-ink);
}
.pvtz *:focus-visible{outline:3px solid #FBBF24;outline-offset:2px}
/* Karty Bootstrap (zadania, wydarzenia, rezerwacje) w estetyce tz */
.pvtz .card{border:1px solid var(--tz-line)!important;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08)!important}
.pvtz .card .card-header{border-top-left-radius:14px;border-top-right-radius:14px;background:#fff}
.pvtz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}

/* Nagłówek strony */
.pvtz .tz-h{margin-bottom:1.1rem}
.pvtz .tz-h h1{font-size:1.5rem;font-weight:800;letter-spacing:-.01em;margin:0;line-height:1.2}
.pvtz .tz-h p{color:var(--tz-muted);margin:.2rem 0 0;font-size:.9rem}

/* Karty */
.pvtz .tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08);overflow:hidden;margin-bottom:1.1rem}
.pvtz .tz-card__hd{padding:.9rem 1.15rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:.95rem}
.pvtz .tz-card__hd i{color:var(--tz)}
.pvtz .tz-card__bd{padding:1.15rem}

/* Pasek statusu umowy */
.pvtz .pv-status{background:#fff;border:1px solid var(--tz-line);border-left:4px solid var(--tz);border-radius:14px;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(16,24,40,.08);margin-bottom:1.1rem}
.pvtz .pv-status-row{display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}
.pvtz .pv-status-ic{width:40px;height:40px;border-radius:11px;background:var(--tz-50);color:var(--tz);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.pvtz .pv-status-txt{flex:1;min-width:0}
.pvtz .pv-status-name{font-size:.98rem;font-weight:700;color:var(--tz-ink);line-height:1.25}
.pvtz .pv-status-meta{font-size:.8rem;color:var(--tz-muted);margin-top:.15rem}
.pvtz .pv-status-badge{font-size:.76rem;font-weight:700;padding:.25rem .7rem;border-radius:999px;white-space:nowrap;border:1px solid transparent}
.pvtz .pv-status-badge.is-active{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.pvtz .pv-status-badge.is-ended{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.pvtz .pv-status-prog{margin-top:.85rem}
.pvtz .pv-status-prog-lbl{display:flex;justify-content:space-between;font-size:.78rem;color:var(--tz-muted);margin-bottom:.3rem}
.pvtz .pv-status-prog-bar{height:7px;background:var(--tz-line);border-radius:999px;overflow:hidden}
.pvtz .pv-status-prog-fill{height:7px;background:var(--tz);border-radius:999px}

/* Zakładki */
.pvtz .tz-subnav{position:sticky;top:0;z-index:5;background:#F4F6F9;padding:.55rem 0 .7rem;margin-bottom:1.1rem}
.pvtz .tz-subnav .seg{display:flex;gap:.25rem;padding:.3rem;background:#fff;border:1px solid var(--tz-line);border-radius:14px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(16,24,40,.05)}
.pvtz .tz-subnav a{flex:1 1 auto;justify-content:center;font-size:.9rem;padding:.6rem 1rem;border-radius:10px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.45rem;font-weight:600;min-height:44px}
.pvtz .tz-subnav a:hover{background:var(--tz-50);color:var(--tz-strong)}
.pvtz .tz-subnav a.on{background:var(--tz);color:#fff}
.pvtz .tz-subnav a.on i{color:#fff}
.pvtz .tz-panel{display:none}
.pvtz .tz-panel.active{display:block;animation:pvtzfade .2s ease}
@media(prefers-reduced-motion:reduce){.pvtz .tz-panel.active{animation:none}}
@keyframes pvtzfade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.pvtz .tz-section-h{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--tz-muted);margin:1.4rem 0 .6rem}
.pvtz .tz-section-h:first-child{margin-top:0}

/* Siatka danych umowy */
.pvtz .tz-dl{display:grid;grid-template-columns:1fr;margin:0}
@media(min-width:576px){.pvtz .tz-dl{grid-template-columns:repeat(2,1fr)}}
@media(min-width:992px){.pvtz .tz-dl{grid-template-columns:repeat(3,1fr)}}
.pvtz .tz-dl>div{padding:.8rem 1.1rem;border-top:1px solid var(--tz-line)}
.pvtz .tz-dl dt{font-size:.7rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.03em;font-weight:600}
.pvtz .tz-dl dd{font-weight:600;margin:.2rem 0 0;font-size:.94rem;word-break:break-word;color:#0f172a}
.pvtz .tz-yes{color:#047857}.pvtz .tz-no{color:var(--tz-muted);font-weight:500}

/* Kafelki */
.pvtz .tz-tiles{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
.pvtz .tz-tile{position:relative;display:flex;flex-direction:column;gap:.15rem;background:#fff;border:1px solid var(--tz-line);border-radius:14px;padding:1.05rem;text-decoration:none;color:inherit;min-height:112px;transition:transform .15s,border-color .15s,box-shadow .15s}
.pvtz .tz-tile:hover,.pvtz .tz-tile:focus-visible{transform:translateY(-2px);border-color:var(--tz);box-shadow:0 8px 24px -6px rgba(var(--tz-rgb),.28);color:inherit}
.pvtz .tz-tile__ico{width:42px;height:42px;border-radius:11px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.55rem}
.pvtz .tz-tile__ttl{font-weight:700;font-size:.9rem;line-height:1.25;color:var(--tz-ink)}
.pvtz .tz-tile__sub{font-size:.76rem;color:var(--tz-muted)}
.pvtz .tz-tile__badge{position:absolute;top:.6rem;right:.6rem;min-width:20px;height:20px;padding:0 .35rem;border-radius:999px;background:#dc2626;color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center}

/* Konta / dostępy (usługi) */
.pvtz .tz-svc{display:flex;align-items:flex-start;gap:.9rem;padding:.95rem 0;border-top:1px solid var(--tz-line)}
.pvtz .tz-svc:first-child{border-top:0;padding-top:.3rem}
.pvtz .tz-svc__ico{width:44px;height:44px;border-radius:12px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.pvtz .tz-svc__bd{flex:1;min-width:0}
.pvtz .tz-svc__ttl{font-weight:700;font-size:.95rem}
.pvtz .tz-kv{font-size:.85rem;color:var(--tz-muted);margin-top:.2rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.pvtz .tz-kv code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#0f172a;background:var(--tz-50);padding:.1rem .4rem;border-radius:6px;font-size:.85rem;word-break:break-all}
.pvtz .tz-copy{background:none;border:none;padding:.15rem .3rem;cursor:pointer;color:var(--tz-muted);border-radius:6px;line-height:1}
.pvtz .tz-copy:hover{color:var(--tz);background:var(--tz-50)}
.pvtz .tz-svc__foot{margin-top:.5rem}
.pvtz .tz-svc__foot a{font-size:.83rem;color:var(--tz-strong);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:.35rem}
.pvtz .tz-svc__foot a:hover{text-decoration:underline}
.pvtz .tz-badge{font-size:.72rem;font-weight:600;padding:.18rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem;border:1px solid transparent}
.pvtz .tz-badge--ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.pvtz .tz-badge--wait{background:#fff7ed;color:#c2410c;border-color:#fed7aa}

/* Karta „Uwaga/nudge" i przyciski */
.pvtz .tz-note{background:#F4F6F9;border:1px solid var(--tz-line);border-radius:14px;padding:.85rem 1.1rem;font-size:.85rem;color:var(--tz-muted);display:flex;gap:.55rem;align-items:flex-start;margin-bottom:1.1rem}
.pvtz .tz-note i{color:var(--tz);font-size:1.05rem;margin-top:.1rem}
.pvtz .tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-weight:600;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none;cursor:pointer;min-height:44px}
.pvtz .tz-btn:hover{filter:brightness(.94);color:#fff}
.pvtz .tz-btn--ghost{background:#fff;color:var(--tz-strong);border:1px solid var(--tz-line)}
.pvtz .tz-btn--ghost:hover{background:var(--tz-50);filter:none}
.pvtz .tz-empty{background:#fff;border:2px dashed var(--tz-line);border-radius:14px;text-align:center;padding:2rem 1rem;color:var(--tz-muted)}

/* Aktywność (pv_apps_activity.php) — w palecie tz */
.pvtz .vol-activity{background:#fff;border:1px solid var(--tz-line);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,.08)}
.pvtz .vol-activity-header{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--tz-line)}
.pvtz .vol-activity-title{font-size:.95rem;font-weight:700;color:var(--tz-ink);margin:0}
.pvtz .vol-activity-row{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.1rem;border-bottom:1px solid #F3F4F6;font-size:.86rem}
.pvtz .vol-activity-row:last-child{border-bottom:none}
.pvtz .vol-activity-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.pvtz .pv-filter-chips{display:flex;gap:.35rem;flex-wrap:wrap;padding:.6rem 1.1rem;border-bottom:1px solid #F3F4F6}
</style>

<div class="pvtz">

  <!-- ══ Nagłówek + powitanie ══ -->
  <div class="tz-h d-flex flex-wrap align-items-start justify-content-between gap-2">
    <div>
      <h1><?= h($_greet) ?>, <?= h($_fname_first) ?> 👋</h1>
      <p><?= h(ORG_NAME) ?> · Twój panel współpracy · <?= date('d.m.Y') ?></p>
    </div>
    <?php if (count($contracts) > 1): ?>
    <button type="button" class="tz-btn tz-btn--ghost" data-bs-toggle="modal" data-bs-target="#contractPickerModal" aria-haspopup="dialog">
      <i class="bi bi-arrow-left-right" aria-hidden="true"></i>Zmień umowę
    </button>
    <?php endif; ?>
  </div>

  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <?php if (!$contracts): ?>
  <!-- Brak umów -->
  <div class="tz-empty">
    <i class="bi bi-file-earmark-x" style="font-size:2.5rem;display:block;margin-bottom:.6rem" aria-hidden="true"></i>
    <p class="fw-semibold mb-1" style="color:#374151">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small mb-0">Skontaktuj się z administratorem, aby powiązać umowę z adresem <?= h($email) ?>.</p>
  </div>
  <?php else: ?>

  <?php /* ── Pasek statusu umowy — pełna szerokość, nad zakładkami ── */ ?>
  <?php if ($_active_row): ?>
  <section aria-labelledby="pvp-contract-heading">
    <h2 id="pvp-contract-heading" class="visually-hidden">Status Twojej umowy</h2>
    <div class="pv-status">
      <div class="pv-status-row">
        <span class="pv-status-ic" aria-hidden="true"><i class="bi <?= $_status_icons[$_st] ?? 'bi-circle' ?>"></i></span>
        <div class="pv-status-txt">
          <div class="pv-status-name">
            Porozumienie wolontariackie<?php if ($_active_row['imie_nazwisko'] ?? null): ?> · <?= h($_active_row['imie_nazwisko']) ?><?php endif; ?>
            <?php if ($_is_guardian): ?><span class="tz-badge tz-badge--wait ms-1"><i class="bi bi-person-hearts" aria-hidden="true"></i>Opiekun</span><?php endif; ?>
          </div>
          <?php if (($_active_row['data_zawarcia'] ?? null) || ($_active_contract['data_zakonczenia'] ?? null) || !empty($_active_row['bezterminowa'])): ?>
          <div class="pv-status-meta">
            <?php if ($_active_row['data_zawarcia'] ?? null): ?><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Od <?= date('d.m.Y', strtotime($_active_row['data_zawarcia'])) ?><?php endif; ?>
            <?php if ($_active_contract['data_zakonczenia'] ?? null): ?> → <?= date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) ?><?php elseif (!empty($_active_row['bezterminowa'])): ?> → <i class="bi bi-infinity" aria-hidden="true"></i> bezterminowo<?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <span class="pv-status-badge <?= $_ended ? 'is-ended' : 'is-active' ?>"><?= h($_status_labels[$_st] ?? ucfirst($_st)) ?></span>
      </div>
      <?php if ($contract_progress && !$_ended): ?>
      <div class="pv-status-prog">
        <div class="pv-status-prog-lbl">
          <span>Pozostało <strong><?= (int)$contract_progress['days_left'] ?></strong> dni</span>
          <span><?= (int)$contract_progress['pct'] ?>%</span>
        </div>
        <div class="pv-status-prog-bar" role="progressbar"
             aria-valuenow="<?= (int)$contract_progress['pct'] ?>" aria-valuemin="0" aria-valuemax="100"
             aria-valuetext="Wykorzystano <?= (int)$contract_progress['pct'] ?>%, pozostało <?= (int)$contract_progress['days_left'] ?> dni"
             aria-label="Postęp umowy">
          <div class="pv-status-prog-fill" style="width:<?= (int)$contract_progress['pct'] ?>%"></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* ── Jednorazowe zachęty (nad zakładkami, gdy aktywne) ── */ ?>
  <?php if ($_show_dir_invite): ?>
  <div class="tz-note" role="complementary" aria-label="Zaproszenie do uzupełnienia profilu">
    <i class="bi bi-people-fill" aria-hidden="true"></i>
    <span style="flex:1">
      <strong style="color:#374151">Uzupełnij swój profil w katalogu.</strong>
      Dodaj zdjęcie i krótki opis — łatwiej Cię znajdą i dopasują zadania.
      <a href="<?= APP_URL ?>/directory/profile_edit.php" class="ms-1 fw-semibold" style="color:var(--tz-strong)">Uzupełnij profil →</a>
    </span>
    <button type="button" class="tz-copy" aria-label="Zamknij" onclick="this.closest('.tz-note').remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <?php endif; ?>
  <?php if ($_show_sms_nudge): include __DIR__ . '/pv_sms_nudge.php'; endif; ?>

  <?php /* ══════════════ ZAKŁADKI ══════════════ */ ?>
  <nav class="tz-subnav" aria-label="Sekcje panelu">
    <span class="seg" role="tablist">
      <?php if ($_tasks_tab_on): ?>
      <a href="#zadania" class="<?= $_default_tab==='zadania'?'on':'' ?>" role="tab" id="tab-zadania"
         aria-controls="zadania" aria-selected="<?= $_default_tab==='zadania'?'true':'false' ?>" <?= $_default_tab==='zadania'?'':'tabindex="-1"' ?>>
        <i class="bi bi-list-check" aria-hidden="true"></i>Zadania
      </a>
      <?php endif; ?>
      <a href="#formalne" class="<?= $_default_tab==='formalne'?'on':'' ?>" role="tab" id="tab-formalne"
         aria-controls="formalne" aria-selected="<?= $_default_tab==='formalne'?'true':'false' ?>" <?= $_default_tab==='formalne'?'':'tabindex="-1"' ?>>
        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Formalne / Umowa
      </a>
      <a href="#narzedzia" class="<?= $_default_tab==='narzedzia'?'on':'' ?>" role="tab" id="tab-narzedzia"
         aria-controls="narzedzia" aria-selected="<?= $_default_tab==='narzedzia'?'true':'false' ?>" <?= $_default_tab==='narzedzia'?'':'tabindex="-1"' ?>>
        <i class="bi bi-tools" aria-hidden="true"></i>Narzędzia do codziennej pracy
      </a>
    </span>
  </nav>

  <?php if ($_tasks_tab_on): ?>
  <!-- ═══════════ ZAKŁADKA — ZADANIA ═══════════ -->
  <section class="tz-panel <?= $_default_tab==='zadania'?'active':'' ?>" id="zadania" role="tabpanel" aria-labelledby="tab-zadania" tabindex="-1">
    <?php include __DIR__ . '/pv_tasks_section.php'; ?>
  </section>
  <?php endif; ?>

  <!-- ═══════════ ZAKŁADKA — FORMALNE / UMOWA ═══════════ -->
  <section class="tz-panel <?= $_default_tab==='formalne'?'active':'' ?>" id="formalne" role="tabpanel" aria-labelledby="tab-formalne" tabindex="-1">

    <?php if ($_active_row):
      $_ct_type   = $_active_contract['contract_type'] ?? 'wolontariat';
      $_is_wol    = $_ct_type === 'wolontariat';
      $_pesel     = $_active_row['pesel'] ?? '';
      $_pesel_msk = $_pesel ? (substr($_pesel,0,2).'·····'.substr($_pesel,7)) : '';
    ?>
    <h3 class="tz-section-h">Dane umowy</h3>
    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-clipboard-check" aria-hidden="true"></i><span>Szczegóły porozumienia</span></div>
      <dl class="tz-dl">
        <?php if (!empty($_active_contract['numer_umowy'])): ?><div><dt>Numer umowy</dt><dd><?= h($_active_contract['numer_umowy']) ?></dd></div><?php endif; ?>
        <?php if ($_pesel): ?><div><dt>PESEL</dt><dd style="font-family:ui-monospace,monospace"><?= h($_pesel_msk) ?></dd></div><?php endif; ?>
        <?php if ($_active_row['data_urodzenia'] ?? null): ?><div><dt>Data urodzenia</dt><dd><?= h(date('d.m.Y', strtotime($_active_row['data_urodzenia']))) ?></dd></div><?php endif; ?>
        <?php if ($_active_row['telefon'] ?? null): ?><div><dt>Telefon</dt><dd><?= h($_active_row['telefon']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['miejsce_wolontariatu'] ?? null)): ?><div><dt>Miejsce wolontariatu</dt><dd><?= h($_active_row['miejsce_wolontariatu']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['godzin_tygodniowo'] ?? null)): ?><div><dt>Godzin tygodniowo</dt><dd><?= h($_active_row['godzin_tygodniowo']) ?> h</dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['godzin_przepracowanych'] ?? null)): ?>
        <div><dt>Godziny przepracowane</dt><dd><?= h(number_format((float)$_active_row['godzin_przepracowanych'], 2, ',', ' ')) ?> h
          <?php if ((float)($_active_row['godzin_z_zadan'] ?? 0) > 0): ?><small class="text-muted fw-normal">(<?= h(number_format((float)$_active_row['godzin_z_zadan'], 2, ',', ' ')) ?> h z zadań)</small><?php endif; ?></dd></div>
        <?php endif; ?>
        <?php if ($_is_wol && ($_active_row['opiekun'] ?? null)): ?><div><dt>Opiekun</dt><dd><?= h($_active_row['opiekun']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol && ($_active_row['projekt_program'] ?? null)): ?><div><dt>Projekt / program</dt><dd><?= h($_active_row['projekt_program']) ?></dd></div><?php endif; ?>
        <?php if ($_is_wol): ?>
        <div><dt>Szkolenie BHP</dt><dd><?php if (!empty($_active_row['szkolenie_bhp'])): ?><span class="tz-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tak<?= ($_active_row['data_szkolenia_bhp'] ?? '') ? ' · '.date('d.m.Y', strtotime($_active_row['data_szkolenia_bhp'])) : '' ?></span><?php else: ?><span class="tz-no">Nie</span><?php endif; ?></dd></div>
        <div><dt>Ubezpieczenie NNW</dt><dd><?php if (!empty($_active_row['ubezpieczenie_nnw'])): ?><span class="tz-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tak<?= ($_active_row['numer_polisy_nnw'] ?? '') ? ' · '.h($_active_row['numer_polisy_nnw']) : '' ?></span><?php else: ?><span class="tz-no">Nie</span><?php endif; ?></dd></div>
        <div><dt>Ubezpieczenie OC</dt><dd><?php if (!empty($_active_row['ubezpieczenie_oc'])): ?><span class="tz-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tak</span><?php else: ?><span class="tz-no">Nie</span><?php endif; ?></dd></div>
        <?php if (!empty($_active_row['zwrot_kosztow'])): ?><div><dt>Zwrot kosztów</dt><dd class="tz-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tak</dd></div><?php endif; ?>
        <?php if ($_active_row['m365_login'] ?? null): ?><div><dt>Login Microsoft 365</dt><dd style="font-size:.85rem"><?= h($_active_row['m365_login']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($_active_row['adres'] ?? null): ?><div style="grid-column:1/-1"><dt>Adres</dt><dd style="font-weight:500"><?= h($_active_row['adres']) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <?php endif; // _active_row ?>

    <h3 class="tz-section-h">Sprawy i dokumenty</h3>
    <div class="tz-tiles">
      <?php foreach ($_pv_formal as $t) $pv_tile($t); ?>
    </div>

    <?php /* ── Ostatnie wnioski (aktywność) ── */ ?>
    <h3 class="tz-section-h">Ostatnie wnioski</h3>
    <?php if ($_pv_apps): ?>
    <?php include __DIR__ . '/pv_apps_activity.php'; ?>
    <?php else: ?>
    <div class="tz-empty">
      <i class="bi bi-send" style="font-size:2rem;display:block;margin-bottom:.6rem;opacity:.6" aria-hidden="true"></i>
      <p class="fw-semibold mb-1" style="color:#374151">Masz pytanie lub prośbę?</p>
      <p class="small mb-3">Złóż wniosek lub wyślij pismo bezpośrednio do organizacji.</p>
      <a href="<?= APP_URL ?>/panel/apply.php" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i>Wyślij pismo / złóż wniosek</a>
    </div>
    <?php endif; ?>
  </section>

  <!-- ═══════════ ZAKŁADKA 2 — NARZĘDZIA DO CODZIENNEJ PRACY ═══════════ -->
  <section class="tz-panel <?= $_default_tab==='narzedzia'?'active':'' ?>" id="narzedzia" role="tabpanel" aria-labelledby="tab-narzedzia" tabindex="-1">

    <?php if ($_my_children): ?>
    <h3 class="tz-section-h">Konta dzieci</h3>
    <div class="tz-card"><div class="tz-card__bd">
      <?php foreach ($_my_children as $_ch): ?>
      <form method="post" action="<?= APP_URL ?>/auth/enter_child.php"
            class="tz-svc justify-content-between" style="align-items:center">
        <span style="min-width:0"><strong><?= h($_ch['name']) ?></strong><br><span class="text-muted" style="font-size:.78rem"><?= h($_ch['email']) ?></span></span>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="child" value="<?= (int)$_ch['id'] ?>">
        <button class="tz-btn" style="white-space:nowrap"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź na konto</button>
      </form>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <?php /* ── Konta i dostępy ── */ ?>
    <h3 class="tz-section-h">Konta i dostępy</h3>
    <div class="tz-card"><div class="tz-card__bd">

      <!-- Portal wolontariusza -->
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-house-door-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Portal wolontariusza <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Login: <code><?= h($email) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($email)) ?>, this)" aria-label="Kopiuj login portalu"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <div class="tz-svc__foot"><a href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a></div>
        </div>
      </div>

      <?php /* Microsoft 365 */ ?>
      <?php if ($m365_login): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Login: <code><?= h($m365_login) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($m365_login)) ?>, this)" aria-label="Kopiuj login M365"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <?php if (!empty($u_db['m365_security_group_name'])): ?><div class="tz-kv">Grupa dostępu: <?= h($u_db['m365_security_group_name']) ?></div><?php endif; ?>
          <div class="tz-svc__foot"><a href="https://portal.office.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Otwórz portal.office.com</a></div>
        </div>
      </div>
      <?php elseif (!empty($user['microsoft_id']) || !empty($u_db['microsoft_id'])): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Zaloguj adresem e-mail: <code><?= h($email) ?></code></div>
          <div class="tz-svc__foot"><a href="https://portal.office.com" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Otwórz portal.office.com</a></div>
        </div>
      </div>
      <?php endif; ?>

      <?php /* Moodle */ ?>
      <?php if ($moodle_url): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Platforma e-learningowa (Moodle)
            <?php if ($moodle_login): ?><span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span><?php else: ?><span class="tz-badge tz-badge--wait ms-1"><i class="bi bi-clock" aria-hidden="true"></i>Synchronizacja</span><?php endif; ?>
          </div>
          <div class="tz-kv">Login: <code><?= h($moodle_login ?: $email) ?></code>
            <button class="tz-copy" type="button" onclick="pvCopy(<?= h(json_encode($moodle_login ?: $email)) ?>, this)" aria-label="Kopiuj login Moodle"><i class="bi bi-copy" aria-hidden="true"></i></button>
          </div>
          <div class="tz-svc__foot"><a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Otwórz <?= h(parse_url($moodle_url, PHP_URL_HOST)) ?></a></div>
        </div>
      </div>
      <?php endif; ?>

      <?php /* Canva */ ?>
      <?php if ($_canva_contract_wolont && $_canva_has_access): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-palette-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Canva Pro <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
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
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-palette-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Canva Pro <span class="tz-badge tz-badge--wait ms-1"><i class="bi bi-clock" aria-hidden="true"></i>Wniosek wysłany</span></div>
          <div class="tz-kv">Oczekuje na zatwierdzenie przez administratora.</div>
        </div>
      </div>
      <?php elseif ($_canva_can_request): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-palette-fill"></i></span>
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

      <!-- Poczta -->
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-envelope-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Poczta organizacji</div>
          <div class="tz-kv">Skrzynka służbowa organizacji.</div>
          <div class="tz-svc__foot"><a href="https://poczta.feer.org.pl" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>poczta.feer.org.pl</a></div>
        </div>
      </div>

    </div></div>

    <?php /* ── Narzędzia (kafelki) ── */ ?>
    <h3 class="tz-section-h">Narzędzia i pomoc</h3>
    <div class="tz-tiles">
      <?php foreach ($_pv_daily as $t) $pv_tile($t); ?>
    </div>

    <?php /* ── Wydarzenia i rezerwacje (sekcje wspólne) ── */ ?>
    <h3 class="tz-section-h">Wydarzenia i rezerwacje</h3>
    <?php include __DIR__ . '/pv_extra_sections.php'; ?>

  </section>
  <?php endif; // $contracts ?>

</div><!-- /pvtz -->

<script>
/* Kopiowanie do schowka */
function pvCopy(text, btn){
  if(!navigator.clipboard) return;
  navigator.clipboard.writeText(text).then(function(){
    var i=btn.querySelector('i'), old=i?i.className:'';
    if(i){i.className='bi bi-check-lg';btn.style.color='#16a34a';}
    setTimeout(function(){ if(i){i.className=old||'bi bi-copy';btn.style.color='';} },1600);
  });
}
/* Zakładki: role=tab z obsługą klawiatury (strzałki/Home/End) + #hash */
(function(){
  var root=document.querySelector('.pvtz'); if(!root) return;
  var tabs=[].slice.call(root.querySelectorAll('.tz-subnav .seg a[role="tab"]'));
  var panels=[].slice.call(root.querySelectorAll('.tz-panel'));
  if(!tabs.length) return;
  function activate(id, focusPanel){
    var found=false;
    panels.forEach(function(p){var on=p.id===id;p.classList.toggle('active',on);found=found||on;});
    if(!found){id=panels[0].id;panels.forEach(function(p){p.classList.toggle('active',p.id===id);});}
    tabs.forEach(function(t){
      var on=t.getAttribute('aria-controls')===id;
      t.classList.toggle('on',on);
      t.setAttribute('aria-selected',on?'true':'false');
      t.tabIndex=on?0:-1;
    });
    if(history.replaceState) history.replaceState(null,'','#'+id);
    if(focusPanel){var pl=document.getElementById(id);if(pl)pl.focus({preventScroll:true});}
  }
  root.querySelectorAll('a[href^="#"]').forEach(function(a){
    var id=a.getAttribute('href').slice(1), el=document.getElementById(id);
    if(!el||!el.classList.contains('tz-panel')) return;
    a.addEventListener('click',function(e){e.preventDefault();activate(id,true);});
  });
  tabs.forEach(function(t,i){
    t.addEventListener('keydown',function(e){
      var n=null;
      if(e.key==='ArrowRight'||e.key==='ArrowDown')n=tabs[(i+1)%tabs.length];
      else if(e.key==='ArrowLeft'||e.key==='ArrowUp')n=tabs[(i-1+tabs.length)%tabs.length];
      else if(e.key==='Home')n=tabs[0];
      else if(e.key==='End')n=tabs[tabs.length-1];
      if(n){e.preventDefault();activate(n.getAttribute('aria-controls'),false);n.focus();}
    });
  });
  var initial=(location.hash||'').slice(1);
  if(initial && document.getElementById(initial) && document.getElementById(initial).classList.contains('tz-panel')) activate(initial,false);
})();
</script>
