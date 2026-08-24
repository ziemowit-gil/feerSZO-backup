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

/* Helper: render kafelka .tz-tile (zachowany dla zgodności wstecznej) */
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

/* ── BENTO: feed wiadomości (ostatnie 4 z wątku kontraktu) ─────────────── */
$_bento_msgs = [];
if ($_active_contract) {
    try {
        $_bento_msgs = db_all(
            "SELECT id, sender_type, sender_name, body, created_at,
                    CASE WHEN sender_type='admin' AND is_read=0 THEN 1 ELSE 0 END AS is_unread
             FROM messages
             WHERE context_type='contract' AND context_id=?
             ORDER BY created_at DESC LIMIT 4",
            [(int)$_active_contract['id']]
        ) ?: [];
    } catch (\Throwable $e) {}
}

/* ── BENTO: licznik aktywnych zadań ────────────────────────────────────── */
$_bento_tasks_pending = 0;
if ($_tasks_tab_on) {
    try {
        $_bento_tasks_pending = (int)(db_one(
            "SELECT COUNT(*) AS c FROM tasks t JOIN task_assignments ta ON ta.task_id=t.id
             WHERE ta.user_id=? AND t.deleted_at IS NULL AND t.completed_at IS NULL",
            [(int)$user['id']]
        )['c'] ?? 0);
    } catch (\Throwable $e) {}
}

/* ── BENTO: łączna liczba spraw wymagających uwagi ─────────────────────── */
$_pending_total = $my_apps_new + $my_certs_pending + (int)$_zwroty_pending + $my_terms_pending;

/* ── BENTO: wszystkie kafelki akcji (formalne + narzędzia) ─────────────── */
$_pv_all_actions = array_merge($_pv_formal, $_pv_daily);

/* ── BENTO: helper – inicjały (maks. 2 znaki UTF-8) ────────────────────── */
$_pv_initials = function (string $name): string {
    $words = preg_split('/\s+/u', trim($name));
    return mb_strtoupper(
        implode('', array_map(fn($w) => mb_substr($w, 0, 1, 'UTF-8'), array_slice($words, 0, 2))),
        'UTF-8'
    ) ?: '?';
};

/* ── BENTO: helper – względny czas ─────────────────────────────────────── */
$_pv_rel_time = function (string $dt): string {
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 86400)  return date('H:i', $ts);
    if ($diff < 172800) return 'wczoraj';
    if ($diff < 604800) return (int)($diff / 86400) . ' dni temu';
    return date('d.m', $ts);
};
?>
<style>
/* ══ Panel wolontariusza — bento grid + komponenty ════════════════════════ */
/* Zmienne tokenu (światło/ciemność) */
.pvtz{
  --tz:var(--vol-color,#1E6DFF);
  --tz-strong:<?= h($_vol_dark) ?>;
  --tz-rgb:<?= h($_vol_rgb) ?>;
  --tz-50:rgba(var(--tz-rgb),.08);
  --tz-line:#E5E9F0;--tz-muted:#5b6472;--tz-ink:#111827;
  --tz-bg:#fff;--tz-bg-page:#F9FAFB;
  max-width:960px;margin:0 auto;color:var(--tz-ink);
}
@media(prefers-color-scheme:dark){
  .pvtz{--tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#0a0f1e;--tz-50:rgba(var(--tz-rgb),.16)}
}
:root[data-theme="dark"] .pvtz{--tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#0a0f1e;--tz-50:rgba(var(--tz-rgb),.16)}
:root[data-theme="light"] .pvtz{--tz-line:#E5E9F0;--tz-muted:#5b6472;--tz-ink:#111827;--tz-bg:#fff;--tz-bg-page:#F9FAFB;--tz-50:rgba(var(--tz-rgb),.08)}

.pvtz *:focus-visible{outline:3px solid #FBBF24;outline-offset:2px}
.pvtz .card{border:1px solid var(--tz-line)!important;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08)!important}
.pvtz .card .card-header{border-top-left-radius:14px;border-top-right-radius:14px;background:var(--tz-bg)}
.pvtz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}

/* Nagłówek strony */
.pvtz .tz-h{margin-bottom:1.1rem}
.pvtz .tz-h h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em;margin:0;line-height:1.25}
.pvtz .tz-h p{color:var(--tz-muted);margin:.1rem 0 0;font-size:.85rem}

/* Zachowane komponenty ─────────────────────────────────────────────────── */
.pvtz .tz-card{background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08);overflow:hidden;margin-bottom:1.1rem}
.pvtz .tz-card__hd{padding:.9rem 1.15rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:.95rem}
.pvtz .tz-card__hd i{color:var(--tz)}
.pvtz .tz-card__bd{padding:1.15rem}
.pvtz .tz-section-h{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--tz-muted);margin:1.4rem 0 .6rem}
.pvtz .tz-section-h:first-child{margin-top:0}
.pvtz .tz-dl{display:grid;grid-template-columns:1fr;margin:0}
@media(min-width:576px){.pvtz .tz-dl{grid-template-columns:repeat(2,1fr)}}
@media(min-width:992px){.pvtz .tz-dl{grid-template-columns:repeat(3,1fr)}}
.pvtz .tz-dl>div{padding:.8rem 1.1rem;border-top:1px solid var(--tz-line)}
.pvtz .tz-dl dt{font-size:.7rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.03em;font-weight:600}
.pvtz .tz-dl dd{font-weight:600;margin:.2rem 0 0;font-size:.94rem;word-break:break-word;color:var(--tz-ink)}
.pvtz .tz-yes{color:#047857}.pvtz .tz-no{color:var(--tz-muted);font-weight:500}
.pvtz .tz-tiles{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
.pvtz .tz-tile{position:relative;display:flex;flex-direction:column;gap:.15rem;background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;padding:1.05rem;text-decoration:none;color:inherit;min-height:112px;transition:transform .15s,border-color .15s,box-shadow .15s}
.pvtz .tz-tile:hover,.pvtz .tz-tile:focus-visible{transform:translateY(-2px);border-color:var(--tz);box-shadow:0 8px 24px -6px rgba(var(--tz-rgb),.28);color:inherit}
.pvtz .tz-tile__ico{width:42px;height:42px;border-radius:11px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.55rem}
.pvtz .tz-tile__ttl{font-weight:700;font-size:.9rem;line-height:1.25;color:var(--tz-ink)}
.pvtz .tz-tile__sub{font-size:.76rem;color:var(--tz-muted)}
.pvtz .tz-tile__badge{position:absolute;top:.6rem;right:.6rem;min-width:20px;height:20px;padding:0 .35rem;border-radius:999px;background:#dc2626;color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.pvtz .tz-svc{display:flex;align-items:flex-start;gap:.9rem;padding:.95rem 0;border-top:1px solid var(--tz-line)}
.pvtz .tz-svc:first-child{border-top:0;padding-top:.3rem}
.pvtz .tz-svc__ico{width:44px;height:44px;border-radius:12px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.pvtz .tz-svc__bd{flex:1;min-width:0}
.pvtz .tz-svc__ttl{font-weight:700;font-size:.95rem}
.pvtz .tz-kv{font-size:.85rem;color:var(--tz-muted);margin-top:.2rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.pvtz .tz-kv code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--tz-ink);background:var(--tz-50);padding:.1rem .4rem;border-radius:6px;font-size:.85rem;word-break:break-all}
.pvtz .tz-copy{background:none;border:none;padding:.15rem .3rem;cursor:pointer;color:var(--tz-muted);border-radius:6px;line-height:1}
.pvtz .tz-copy:hover{color:var(--tz);background:var(--tz-50)}
.pvtz .tz-svc__foot{margin-top:.5rem}
.pvtz .tz-svc__foot a{font-size:.83rem;color:var(--tz-strong);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:.35rem}
.pvtz .tz-svc__foot a:hover{text-decoration:underline}
.pvtz .tz-badge{font-size:.72rem;font-weight:600;padding:.18rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem;border:1px solid transparent}
.pvtz .tz-badge--ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.pvtz .tz-badge--wait{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
.pvtz .tz-note{background:var(--tz-bg-page);border:1px solid var(--tz-line);border-radius:14px;padding:.85rem 1.1rem;font-size:.85rem;color:var(--tz-muted);display:flex;gap:.55rem;align-items:flex-start;margin-bottom:1.1rem}
.pvtz .tz-note i{color:var(--tz);font-size:1.05rem;margin-top:.1rem}
.pvtz .tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-weight:600;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none;cursor:pointer;min-height:44px}
.pvtz .tz-btn:hover{filter:brightness(.94);color:#fff}
.pvtz .tz-btn--ghost{background:var(--tz-bg);color:var(--tz-strong);border:1px solid var(--tz-line)}
.pvtz .tz-btn--ghost:hover{background:var(--tz-50);filter:none}
.pvtz .tz-empty{background:var(--tz-bg);border:2px dashed var(--tz-line);border-radius:14px;text-align:center;padding:2rem 1rem;color:var(--tz-muted)}
/* Wybór klienta poczty (sekcja „Konta i dostępy") */
.pvtz .tz-badge--new{background:var(--tz-50);color:var(--tz-strong);border-color:rgba(var(--tz-rgb),.35)}
.pvtz .pv-mail-pick{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:.75rem 0 .2rem}
.pvtz .pv-mail-btn{display:flex;flex-direction:column;gap:.1rem;padding:.7rem .9rem;border:1px solid var(--tz-line);border-radius:12px;background:var(--tz-bg);text-decoration:none;color:var(--tz-ink);font-weight:700;font-size:.9rem;min-height:44px;transition:border-color .15s,transform .15s,box-shadow .15s}
.pvtz .pv-mail-btn:hover,.pvtz .pv-mail-btn:focus-visible{border-color:var(--tz);transform:translateY(-1px);box-shadow:0 6px 18px -8px rgba(var(--tz-rgb),.35);color:var(--tz-ink)}
.pvtz .pv-mail-btn span{font-weight:400;font-size:.78rem;color:var(--tz-muted)}
.pvtz .pv-mail-pick--single{grid-template-columns:1fr}
.pvtz .pv-mail-btn--main{border-color:var(--tz);background:var(--tz-50)}
.pvtz .pv-mail-btn--main:hover{background:var(--tz-bg)}
.pvtz .pv-mail-btn i{color:var(--tz-strong);margin-right:.35rem}
.pvtz .pv-mail-diff{margin-top:.55rem;font-size:.84rem}
.pvtz .pv-mail-diff>summary{cursor:pointer;font-weight:600;color:var(--tz-strong);list-style:none;display:inline-flex;align-items:center;gap:.35rem;min-height:32px}
.pvtz .pv-mail-diff>summary::-webkit-details-marker{display:none}
.pvtz .pv-mail-diff>summary::after{content:'\203A';transition:transform .15s;display:inline-block}
.pvtz .pv-mail-diff[open]>summary::after{transform:rotate(90deg)}
.pvtz .pv-mail-diff dl{margin:.5rem 0 0;display:grid;gap:.45rem}
.pvtz .pv-mail-diff dt{font-weight:700;color:var(--tz-ink);font-size:.84rem}
.pvtz .pv-mail-diff dd{margin:0 0 .25rem;color:var(--tz-muted)}
.pvtz .pv-status-badge{font-size:.76rem;font-weight:700;padding:.25rem .7rem;border-radius:999px;white-space:nowrap;border:1px solid transparent}
.pvtz .pv-status-badge.is-active{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.pvtz .pv-status-badge.is-ended{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.pvtz .vol-activity{background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,.08)}
.pvtz .vol-activity-header{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--tz-line)}
.pvtz .vol-activity-title{font-size:.95rem;font-weight:700;color:var(--tz-ink);margin:0}
.pvtz .vol-activity-row{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.1rem;border-bottom:1px solid var(--tz-line);font-size:.86rem}
.pvtz .vol-activity-row:last-child{border-bottom:none}
.pvtz .vol-activity-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.pvtz .pv-filter-chips{display:flex;gap:.35rem;flex-wrap:wrap;padding:.6rem 1.1rem;border-bottom:1px solid var(--tz-line)}
/* Teaser zadań */
.pv-tasks-teaser{display:flex;align-items:center;gap:1.1rem;padding:1.4rem 1.6rem;background:linear-gradient(135deg,#9A3412,#EA580C);border-radius:16px;text-decoration:none;color:#fff;transition:transform .13s,box-shadow .13s}
.pv-tasks-teaser:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(154,52,18,.35);color:#fff}
.pv-tasks-teaser__ic{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.65rem;flex-shrink:0}
.pv-tasks-teaser__body{flex:1;min-width:0}
.pv-tasks-teaser__label{display:block;font-size:1.1rem;font-weight:800;line-height:1.2}
.pv-tasks-teaser__sub{display:block;font-size:.82rem;color:rgba(255,255,255,.82);margin-top:.2rem}
.pv-tasks-teaser__arrow{display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;font-weight:700;white-space:nowrap;color:rgba(255,255,255,.95)}
@media(max-width:540px){.pv-tasks-teaser{flex-direction:column;align-items:flex-start;gap:.75rem}.pv-tasks-teaser__arrow{align-self:flex-end}}

/* ── Bento grid ─────────────────────────────────────────────────────────── */
.pv-bento{display:grid;gap:1rem;grid-template-columns:1fr;grid-template-areas:"welcome" "stats" "tasks" "messages" "actions";margin-bottom:1.5rem}
@media(min-width:640px){
  .pv-bento{grid-template-columns:2fr 1fr;grid-template-areas:"welcome stats" "tasks messages" "actions actions"}
}
.b-welcome{grid-area:welcome}
.b-stats{grid-area:stats}
.b-tasks{grid-area:tasks}
.b-messages{grid-area:messages}
.b-actions{grid-area:actions}

/* Bento tile (karta) */
.b-tile{background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 1px 3px rgba(16,24,40,.07);overflow:hidden;display:flex;flex-direction:column}
.b-tile__hd{display:flex;align-items:center;gap:.6rem;padding:.85rem 1.15rem;border-bottom:1px solid var(--tz-line);font-weight:700;font-size:.92rem;flex-shrink:0;color:var(--tz-ink)}
.b-tile__hd>i{color:var(--tz);flex-shrink:0}
.b-tile__more{margin-left:auto;font-size:.78rem;font-weight:600;color:var(--tz-muted);text-decoration:none;display:flex;align-items:center;gap:.3rem;padding:.2rem .4rem;border-radius:6px;white-space:nowrap}
.b-tile__more:hover{color:var(--tz);background:var(--tz-50)}
.b-tile__bd{padding:1.15rem;flex:1}
.b-tile__bd--flush{padding:0;flex:1}

/* Stats aside — 3 kafelki statystyk */
.b-stats{display:flex;flex-direction:column;gap:1rem}
@media(max-width:639px){.b-stats{flex-direction:row;flex-wrap:wrap}}
.pv-stat-tile{flex:1;min-width:0;background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 1px 3px rgba(16,24,40,.07);padding:1rem 1.1rem;display:flex;flex-direction:column;gap:.25rem;text-decoration:none;color:inherit;transition:border-color .15s,box-shadow .15s}
@media(max-width:639px){.pv-stat-tile{flex:1 1 calc(33.3% - .7rem);min-width:100px}}
.pv-stat-tile:is(a):hover,.pv-stat-tile:is(a):focus-visible{border-color:var(--tz);box-shadow:0 6px 18px rgba(var(--tz-rgb),.18);color:inherit}
.pv-stat-tile__ico{color:var(--tz);font-size:1.15rem;margin-bottom:.1rem}
.pv-stat-tile__val{font-size:1.75rem;font-weight:800;color:var(--tz-ink);line-height:1;font-variant-numeric:tabular-nums}
.pv-stat-tile__lbl{font-size:.75rem;color:var(--tz-muted);font-weight:500;line-height:1.3}
.pv-stat-tile--accent{background:var(--tz);border-color:var(--tz)}
.pv-stat-tile--accent .pv-stat-tile__ico,.pv-stat-tile--accent .pv-stat-tile__val,.pv-stat-tile--accent .pv-stat-tile__lbl{color:#fff}
.pv-stat-tile--warn{background:#fff7ed;border-color:#fed7aa}
.pv-stat-tile--warn .pv-stat-tile__ico{color:#c2410c}
.pv-stat-tile--warn .pv-stat-tile__val{color:#9a3412}
.pv-stat-tile--warn .pv-stat-tile__lbl{color:#c2410c}
@media(prefers-color-scheme:dark){.pv-stat-tile--warn{background:#2a1500;border-color:#7c2d12}}
:root[data-theme="dark"] .pv-stat-tile--warn{background:#2a1500;border-color:#7c2d12}

/* Feed wiadomości */
.pv-msgfeed{list-style:none;padding:0;margin:0}
.pv-msg{display:flex;gap:.65rem;padding:.9rem 1.15rem;border-bottom:1px solid var(--tz-line)}
.pv-msg:last-child{border-bottom:none}
.pv-msg__av{width:34px;height:34px;border-radius:50%;background:var(--tz-50);color:var(--tz-strong);font-size:.73rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-variant-numeric:tabular-nums}
.pv-msg__av--admin{background:var(--tz);color:#fff}
.pv-msg__body{flex:1;min-width:0}
.pv-msg__meta{font-size:.72rem;color:var(--tz-muted);display:flex;justify-content:space-between;gap:.4rem;margin-bottom:.2rem}
.pv-msg__meta strong{color:var(--tz-ink);font-weight:600}
.pv-msg__text{font-size:.86rem;color:var(--tz-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.4}
.pv-msg--unread .pv-msg__text{font-weight:600}
.pv-msg--unread .pv-msg__av--admin{box-shadow:0 0 0 2px var(--tz)}

/* Pasek szybkich akcji */
.pv-qa-strip{display:flex;flex-wrap:wrap;gap:.5rem;padding:1rem 1.15rem}
.pv-qa-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem .95rem;border-radius:10px;background:var(--tz-bg);border:1px solid var(--tz-line);text-decoration:none;color:var(--tz-ink);font-size:.86rem;font-weight:600;transition:border-color .15s,box-shadow .13s,transform .1s;min-height:44px;position:relative;white-space:nowrap}
.pv-qa-btn:hover,.pv-qa-btn:focus-visible{border-color:var(--tz);color:var(--tz-strong);transform:translateY(-1px);box-shadow:0 4px 12px rgba(var(--tz-rgb),.15)}
.pv-qa-btn>i{color:var(--tz);font-size:1rem}
.pv-qa-btn__bdg{min-width:17px;height:17px;padding:0 .28rem;border-radius:999px;background:#dc2626;color:#fff;font-size:.65rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;margin-left:.1rem}
.pv-qa-btn--always{background:var(--tz-50);border-color:rgba(var(--tz-rgb),.2)}
.pv-qa-btn--always>i{color:var(--tz-strong)}

/* Timeline na kafelku Welcome */
.pv-tl{margin:1rem 0 .4rem;padding:0 .25rem}
.pv-tl__track{height:5px;background:var(--tz-line);border-radius:999px;position:relative}
.pv-tl__fill{position:absolute;left:0;top:0;height:5px;background:var(--tz);border-radius:999px;transition:width .4s ease}
.pv-tl__dot{position:absolute;top:-5px;width:14px;height:14px;border-radius:50%;background:var(--tz);border:3px solid var(--tz-bg);box-shadow:0 0 0 2px var(--tz);transform:translateX(-50%);transition:left .4s ease}
.pv-tl__labels{display:flex;justify-content:space-between;margin-top:.4rem;font-size:.72rem;color:var(--tz-muted)}
.pv-tl__today{position:absolute;top:2.1rem;transform:translateX(-50%);font-size:.68rem;font-weight:700;color:var(--tz);white-space:nowrap}

@media(prefers-reduced-motion:reduce){.pv-tl__fill,.pv-tl__dot{transition:none}}
</style>

<a class="visually-hidden focusable" href="#pvtz-main">Przejdź do treści głównej</a>

<div class="pvtz" id="pvtz-root">

  <!-- Nagłówek: powitanie + przełącznik umowy -->
  <div class="tz-h d-flex flex-wrap align-items-start justify-content-between gap-2">
    <div>
      <h1><?= h($_greet) ?>, <?= h($_fname_first) ?></h1>
      <p><?= h(ORG_NAME) ?> · Twój panel współpracy · <?= date('d.m.Y') ?></p>
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
      <strong style="color:var(--tz-ink)">Uzupełnij swój profil w katalogu.</strong>
      Dodaj zdjęcie i krótki opis — łatwiej Cię znajdą i dopasują zadania.
      <a href="<?= APP_URL ?>/directory/profile_edit.php" class="ms-1 fw-semibold" style="color:var(--tz-strong)">Uzupełnij profil →</a>
    </span>
    <button type="button" class="tz-copy" aria-label="Zamknij" onclick="this.closest('.tz-note').remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <?php endif; ?>
  <?php if ($_show_sms_nudge): include __DIR__ . '/pv_sms_nudge.php'; endif; ?>

  <?php if (!$contracts): ?>
  <div class="tz-empty">
    <i class="bi bi-file-earmark-x" style="font-size:2.5rem;display:block;margin-bottom:.6rem" aria-hidden="true"></i>
    <p class="fw-semibold mb-1" style="color:var(--tz-ink)">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small mb-0">Skontaktuj się z administratorem, aby powiązać umowę z adresem <?= h($email) ?>.</p>
  </div>
  <?php else: ?>

  <!-- ════════════════════════════════════════════════════════════════════
       BENTO GRID
       ════════════════════════════════════════════════════════════════════ -->
  <main id="pvtz-main" class="pv-bento" aria-label="Panel współpracownika">

    <!-- ── B1: Kafelek powitalny (umowa + timeline) ───────────────────── -->
    <section class="b-tile b-welcome" aria-labelledby="bw-heading">
      <?php if ($_active_row):
        $_ct_type = $_active_contract['contract_type'] ?? 'wolontariat';
        $_is_wol  = $_ct_type === 'wolontariat';
        $_pesel   = $_active_row['pesel'] ?? '';
        $_pesel_msk = $_pesel ? (substr($_pesel,0,2).'·····'.substr($_pesel,7)) : '';
        $_ct_icons = ['wolontariat'=>'bi-heart-fill','zlecenie'=>'bi-person-workspace','dzielo'=>'bi-brush','praca'=>'bi-briefcase-fill'];
        $_ct_icon  = $_ct_icons[$_ct_type] ?? 'bi-file-earmark-text';
        $_ct_labels= ['wolontariat'=>'Wolontariat','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'];
        $_ct_label = $_ct_labels[$_ct_type] ?? ucfirst($_ct_type);
      ?>
      <div class="b-tile__hd">
        <span style="width:36px;height:36px;border-radius:10px;background:var(--tz-50);color:var(--tz);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem" aria-hidden="true">
          <i class="bi <?= $_status_icons[$_st] ?? 'bi-circle' ?>"></i>
        </span>
        <div style="flex:1;min-width:0">
          <div id="bw-heading" style="font-size:.93rem;font-weight:700;line-height:1.2;color:var(--tz-ink)">
            <?= h($_ct_label) ?><?php if ($_active_row['imie_nazwisko'] ?? null): ?><span style="color:var(--tz-muted);font-weight:500"> · <?= h($_active_row['imie_nazwisko']) ?></span><?php endif; ?>
            <?php if ($_is_guardian): ?><span class="tz-badge tz-badge--wait ms-1"><i class="bi bi-person-hearts" aria-hidden="true"></i>Opiekun</span><?php endif; ?>
          </div>
          <?php if (($_active_row['data_zawarcia'] ?? null) || ($_active_contract['data_zakonczenia'] ?? null) || !empty($_active_row['bezterminowa'])): ?>
          <div style="font-size:.76rem;color:var(--tz-muted);margin-top:.1rem">
            <?php if ($_active_row['data_zawarcia'] ?? null): ?>od <?= date('d.m.Y', strtotime($_active_row['data_zawarcia'])) ?><?php endif; ?>
            <?php if ($_active_contract['data_zakonczenia'] ?? null): ?> → <?= date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) ?><?php elseif (!empty($_active_row['bezterminowa'])): ?> → bezterminowo<?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <span class="pv-status-badge <?= $_ended ? 'is-ended' : 'is-active' ?>"><?= h($_status_labels[$_st] ?? ucfirst($_st)) ?></span>
      </div>
      <div class="b-tile__bd">
        <?php if (!empty($_active_contract['numer_umowy']) || ($_is_wol && ($_active_row['miejsce_wolontariatu'] ?? null))
               || ($_is_wol && ($_active_row['godzin_tygodniowo'] ?? null)) || ($_is_wol && ($_active_row['opiekun'] ?? null))
               || $_pesel || ($_active_row['telefon'] ?? null)): ?>
        <dl class="tz-dl" style="margin:-1.15rem -1.15rem 0">
          <?php if (!empty($_active_contract['numer_umowy'])): ?><div><dt>Nr umowy</dt><dd><?= h($_active_contract['numer_umowy']) ?></dd></div><?php endif; ?>
          <?php if ($_is_wol && ($_active_row['miejsce_wolontariatu'] ?? null)): ?><div><dt>Miejsce</dt><dd><?= h($_active_row['miejsce_wolontariatu']) ?></dd></div><?php endif; ?>
          <?php if ($_is_wol && ($_active_row['godzin_tygodniowo'] ?? null)): ?><div><dt>Godzin tyg.</dt><dd><?= h($_active_row['godzin_tygodniowo']) ?> h</dd></div><?php endif; ?>
          <?php if ($_is_wol && ($_active_row['opiekun'] ?? null)): ?><div><dt>Opiekun</dt><dd><?= h($_active_row['opiekun']) ?></dd></div><?php endif; ?>
          <?php if ($_pesel): ?><div><dt>PESEL</dt><dd style="font-family:ui-monospace,monospace;font-size:.88rem"><?= h($_pesel_msk) ?></dd></div><?php endif; ?>
          <?php if ($_active_row['telefon'] ?? null): ?><div><dt>Telefon</dt><dd><?= h($_active_row['telefon']) ?></dd></div><?php endif; ?>
          <?php if ($_is_wol && ($_active_row['godzin_przepracowanych'] ?? null)): ?>
          <div><dt>Godz. przepracowane</dt><dd><?= h(number_format((float)$_active_row['godzin_przepracowanych'], 2, ',', ' ')) ?> h
            <?php if ((float)($_active_row['godzin_z_zadan'] ?? 0) > 0): ?><small class="text-muted fw-normal">(<?= h(number_format((float)$_active_row['godzin_z_zadan'], 2, ',', ' ')) ?> h z zadań)</small><?php endif; ?></dd></div>
          <?php endif; ?>
          <?php if ($_is_wol): ?>
          <div><dt>BHP</dt><dd><?= !empty($_active_row['szkolenie_bhp']) ? '<span class="tz-yes"><i class="bi bi-check-circle-fill"></i> Tak</span>' : '<span class="tz-no">Nie</span>' ?></dd></div>
          <div><dt>NNW</dt><dd><?= !empty($_active_row['ubezpieczenie_nnw']) ? '<span class="tz-yes"><i class="bi bi-check-circle-fill"></i> Tak</span>' : '<span class="tz-no">Nie</span>' ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php endif; ?>

        <?php if ($contract_progress && !$_ended):
          $pct = min(100, max(0, (int)$contract_progress['pct']));
        ?>
        <div class="pv-tl" aria-hidden="true">
          <div class="pv-tl__track">
            <div class="pv-tl__fill" style="width:<?= $pct ?>%"></div>
            <div class="pv-tl__dot" style="left:<?= $pct ?>%"></div>
            <div class="pv-tl__today" style="left:<?= $pct ?>%">dziś</div>
          </div>
          <div class="pv-tl__labels">
            <span><?= ($_active_row['data_zawarcia'] ?? null) ? date('d.m.Y', strtotime($_active_row['data_zawarcia'])) : 'start' ?></span>
            <span>pozostało <strong><?= (int)$contract_progress['days_left'] ?></strong> dni</span>
            <span><?= ($_active_contract['data_zakonczenia'] ?? null) ? date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) : '' ?></span>
          </div>
        </div>
        <div role="progressbar"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
             aria-valuetext="Wykorzystano <?= $pct ?>% umowy, pozostało <?= (int)$contract_progress['days_left'] ?> dni"
             aria-label="Postęp umowy" class="visually-hidden"></div>
        <?php elseif ($_ended): ?>
        <p class="small mt-3 mb-0" style="color:var(--tz-muted)"><i class="bi bi-flag me-1" aria-hidden="true"></i>Umowa zakończona.</p>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="b-tile__hd"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span id="bw-heading">Twoja umowa</span></div>
      <div class="b-tile__bd">
        <div class="tz-empty" style="border:none;padding:.5rem 0">
          <p class="mb-0">Brak aktywnej umowy do wyświetlenia.</p>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <!-- ── B2: Kafelki statystyk ──────────────────────────────────────── -->
    <aside class="b-stats" aria-label="Twoje statystyki">
      <?php if ($_tasks_tab_on): ?>
      <a href="https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u"
         target="_blank" rel="noopener"
         class="pv-stat-tile" aria-label="Otwórz tablicę Trello — zadania tymczasowo tam">
        <i class="bi bi-trello pv-stat-tile__ico" aria-hidden="true"></i>
        <div class="pv-stat-tile__val" style="font-size:1rem;line-height:1.3;margin-top:.15rem">Trello</div>
        <div class="pv-stat-tile__lbl">Zadania tymczasowo</div>
      </a>
      <?php else: ?>
      <div class="pv-stat-tile">
        <i class="bi bi-kanban pv-stat-tile__ico" aria-hidden="true"></i>
        <div class="pv-stat-tile__val">—</div>
        <div class="pv-stat-tile__lbl">Moduł zadań wyłączony</div>
      </div>
      <?php endif; ?>

      <a href="<?= APP_URL ?>/panel/apply.php" class="pv-stat-tile<?= $_pending_total > 0 ? ' pv-stat-tile--warn' : '' ?>" aria-label="Sprawy w toku: <?= $_pending_total ?>">
        <i class="bi bi-bell pv-stat-tile__ico" aria-hidden="true"></i>
        <div class="pv-stat-tile__val"><?= $_pending_total ?></div>
        <div class="pv-stat-tile__lbl">Spraw do uwagi</div>
      </a>

      <a href="<?= APP_URL ?>/panel/messages.php" class="pv-stat-tile<?= $msg_unread > 0 ? ' pv-stat-tile--accent' : '' ?>" aria-label="Wiadomości: <?= $msg_unread ?> nowych">
        <i class="bi bi-chat-dots pv-stat-tile__ico" aria-hidden="true"></i>
        <div class="pv-stat-tile__val"><?= $msg_unread ?></div>
        <div class="pv-stat-tile__lbl">Nowych wiadomości</div>
      </a>
    </aside>

    <!-- ── B3: Kafelek zadań ──────────────────────────────────────────── -->
    <section class="b-tile b-tasks" aria-labelledby="bt-heading">
      <?php if ($_tasks_tab_on):
        $GLOBALS['_pv_tasks_section_done'] = true;
      ?>
      <div class="b-tile__hd">
        <i class="bi bi-kanban-fill" aria-hidden="true"></i>
        <span id="bt-heading">Zadania</span>
      </div>
      <div class="b-tile__bd d-flex flex-column justify-content-center gap-2" style="padding:1.25rem">
        <div class="d-flex align-items-start gap-2">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" style="color:#f59e0b;font-size:1.1rem"></i>
          <div>
            <strong style="display:block;margin-bottom:.25rem;color:#92400e;font-size:.88rem">Moduł Zadań tymczasowo niedostępny</strong>
            <span style="color:#78350f;font-size:.8rem;line-height:1.45">
              Tymczasowo wracamy do Trello — korzystaj z tablicy zespołu do czasu przywrócenia modułu.
            </span>
          </div>
        </div>
        <a href="https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u"
           target="_blank" rel="noopener"
           class="btn btn-sm btn-warning fw-semibold mt-1" style="align-self:flex-start">
          <i class="bi bi-trello me-1"></i>Otwórz tablicę Trello
        </a>
      </div>
      <?php else: ?>
      <div class="b-tile__hd">
        <i class="bi bi-send" aria-hidden="true"></i>
        <span id="bt-heading">Ostatnie wnioski</span>
        <a href="<?= APP_URL ?>/panel/apply.php" class="b-tile__more">Złóż nowy <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
      <div class="b-tile__bd">
        <?php if ($_pv_apps): ?>
        <?php include __DIR__ . '/pv_apps_activity.php'; ?>
        <?php else: ?>
        <div class="tz-empty" style="border:none;padding:1rem 0">
          <i class="bi bi-send" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.55" aria-hidden="true"></i>
          <p class="fw-semibold mb-2" style="font-size:.9rem">Brak ostatnich wniosków.</p>
          <a href="<?= APP_URL ?>/panel/apply.php" class="tz-btn" style="font-size:.85rem"><i class="bi bi-send" aria-hidden="true"></i>Wyślij pismo / złóż wniosek</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ── B4: Kafelek wiadomości ─────────────────────────────────────── -->
    <section class="b-tile b-messages" aria-labelledby="bm-heading">
      <div class="b-tile__hd">
        <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
        <span id="bm-heading">Wiadomości</span>
        <?php if ($msg_unread): ?>
        <span class="badge rounded-pill bg-danger ms-1" aria-label="<?= $msg_unread ?> nowych"><?= $msg_unread > 99 ? '99+' : $msg_unread ?></span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/panel/messages.php" class="b-tile__more">Wszystkie <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
      <div class="b-tile__bd--flush">
        <?php if ($_bento_msgs): ?>
        <ul class="pv-msgfeed" aria-label="Ostatnie wiadomości" role="list">
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
        <div class="px-3 py-2 border-top" style="border-color:var(--tz-line)!important">
          <a href="<?= APP_URL ?>/panel/messages.php" class="d-flex align-items-center gap-1 text-decoration-none" style="font-size:.82rem;font-weight:600;color:var(--tz-strong)">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz nową wiadomość
          </a>
        </div>
        <?php else: ?>
        <div class="tz-empty" style="border:none;margin:1rem">
          <i class="bi bi-chat-dots" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.45" aria-hidden="true"></i>
          <p class="mb-2" style="font-size:.88rem">Brak wiadomości w tym wątku.</p>
          <a href="<?= APP_URL ?>/panel/messages.php" class="tz-btn" style="font-size:.82rem"><i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz do nas</a>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ── B5: Pasek szybkich akcji (pełna szerokość) ─────────────────── -->
    <section class="b-tile b-actions" aria-labelledby="bqa-heading">
      <div class="b-tile__hd">
        <i class="bi bi-grid-1x2" aria-hidden="true"></i>
        <span id="bqa-heading">Szybkie akcje</span>
      </div>
      <nav class="pv-qa-strip" aria-label="Szybkie akcje" role="navigation">
        <?php foreach ($_pv_all_actions as $_qa):
          $_qa_badge = (int)($_qa['badge'] ?? 0);
          $_qa_aria  = h($_qa['label']) . ($_qa_badge ? " — {$_qa_badge} wymaga uwagi" : '');
        ?>
        <a href="<?= h($_qa['href']) ?>" class="pv-qa-btn" aria-label="<?= $_qa_aria ?>">
          <i class="bi <?= h($_qa['icon']) ?>" aria-hidden="true"></i>
          <?= h($_qa['label']) ?>
          <?php if ($_qa_badge): ?><span class="pv-qa-btn__bdg" aria-hidden="true"><?= $_qa_badge > 99 ? '99+' : $_qa_badge ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (is_file(dirname(__DIR__, 2) . '/tozsamosc/index.php')): ?>
        <a href="<?= APP_URL ?>/tozsamosc/" class="pv-qa-btn pv-qa-btn--always">
          <i class="bi bi-fingerprint" aria-hidden="true"></i>Tożsamość
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/panel/password.php" class="pv-qa-btn pv-qa-btn--always">
          <i class="bi bi-gear" aria-hidden="true"></i>Ustawienia konta
        </a>
      </nav>
    </section>

  </main><!-- /.pv-bento -->

  <!-- ════════════════════════════════════════════════════════════════════
       POD BENTO — Konta i dostępy (M365, Moodle, Canva, Portal)
       ════════════════════════════════════════════════════════════════════ -->
  <section class="tz-card" aria-labelledby="pv-accounts-heading">
    <div class="tz-card__hd">
      <i class="bi bi-key-fill" aria-hidden="true"></i>
      <span id="pv-accounts-heading">Konta i dostępy do systemów</span>
    </div>
    <div class="tz-card__bd" style="padding-top:.3rem">

      <?php if ($_my_children): ?>
      <h3 class="tz-section-h">Konta dzieci</h3>
      <?php foreach ($_my_children as $_ch): ?>
      <form method="post" action="<?= APP_URL ?>/auth/enter_child.php" class="tz-svc justify-content-between" style="align-items:center">
        <span style="min-width:0"><strong><?= h($_ch['name']) ?></strong><br><span class="text-muted" style="font-size:.78rem"><?= h($_ch['email']) ?></span></span>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="child" value="<?= (int)$_ch['id'] ?>">
        <button class="tz-btn" style="white-space:nowrap"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź na konto</button>
      </form>
      <?php endforeach; ?>
      <?php endif; ?>

      <h3 class="tz-section-h">Logowania</h3>

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

      <!-- Microsoft 365 -->
      <?php if ($m365_login): ?>
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
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
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Microsoft 365 <span class="tz-badge tz-badge--ok ms-1"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Aktywne</span></div>
          <div class="tz-kv">Zaloguj adresem e-mail: <code><?= h($email) ?></code></div>
          <div class="tz-svc__foot d-flex flex-wrap gap-3">
            <a href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta: <?= h($_mail_chooser_label) ?></a>
            <a href="https://portal.office.com" target="_blank" rel="noopener" class="text-muted"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Portal Microsoft (hasło, aplikacje)</a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Moodle -->
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

      <!-- Canva -->
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

      <!-- Poczta — dwa klienty do wyboru (komunikat: od 1 IX 2026) -->
      <div class="tz-svc">
        <span class="tz-svc__ico" aria-hidden="true"><i class="bi bi-envelope-fill"></i></span>
        <div class="tz-svc__bd">
          <div class="tz-svc__ttl">Poczta organizacji
            <span class="tz-badge tz-badge--new ms-1"><i class="bi bi-stars" aria-hidden="true"></i>Nowość: wybór klienta</span>
          </div>
          <div class="tz-kv" style="display:block">
            <strong>Od 1 września 2026</strong> sam wybierasz, w czym czytasz służbową pocztę —
            <strong>korzystać możesz już teraz</strong>, bez zgłaszania czegokolwiek.
            To ta sama skrzynka i te same wiadomości; różni się tylko widok i to, co program
            dodatkowo potrafi. Wybór jest odwracalny — możesz używać kilku klientów równolegle.
          </div>

          <div class="pv-mail-pick pv-mail-pick--single">
            <a class="pv-mail-btn pv-mail-btn--main" href="<?= h($_mail_chooser) ?>" target="_blank" rel="noopener noreferrer">
              <span style="font-weight:700;color:inherit"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Otwórz pocztę — <?= h($_mail_chooser_label) ?></span>
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

  <!-- Ostatnie wnioski (gdy tasks_tab_on = true, activity pokazywana poza kafelkiem) -->
  <?php if ($_tasks_tab_on && $_pv_apps): ?>
  <section class="tz-card" aria-labelledby="pv-act-heading">
    <div class="tz-card__hd"><i class="bi bi-activity" aria-hidden="true"></i><span id="pv-act-heading">Ostatnie wnioski</span></div>
    <div class="tz-card__bd"><?php include __DIR__ . '/pv_apps_activity.php'; ?></div>
  </section>
  <?php endif; ?>

  <!-- Dane wrażliwe umowy (pełny formularz — zgody, RODO, szczegóły) -->
  <?php if ($_active_row && !empty($_active_row['adres'])): ?>
  <section class="tz-card" aria-labelledby="pv-addr-heading">
    <div class="tz-card__hd"><i class="bi bi-person-vcard" aria-hidden="true"></i><span id="pv-addr-heading">Dane kontaktowe</span></div>
    <dl class="tz-dl">
      <div style="grid-column:1/-1"><dt>Adres korespondencyjny</dt><dd style="font-weight:500"><?= h($_active_row['adres']) ?></dd></div>
      <?php if ($_active_row['data_urodzenia'] ?? null): ?><div><dt>Data urodzenia</dt><dd><?= h(date('d.m.Y', strtotime($_active_row['data_urodzenia']))) ?></dd></div><?php endif; ?>
      <?php if ($_active_row['projekt_program'] ?? null): ?><div><dt>Projekt / program</dt><dd><?= h($_active_row['projekt_program']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <?php endif; ?>

  <!-- Wydarzeinia i rezerwacje zasobów -->
  <?php include __DIR__ . '/pv_extra_sections.php'; ?>

  <?php endif; /* $contracts */ ?>
</div><!-- /pvtz -->

<script>
/* Kopiowanie do schowka */
function pvCopy(text, btn) {
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(text).then(function () {
    var i = btn.querySelector('i'), old = i ? i.className : '';
    if (i) { i.className = 'bi bi-check-lg'; btn.style.color = '#16a34a'; }
    setTimeout(function () {
      if (i) { i.className = old || 'bi bi-copy'; btn.style.color = ''; }
    }, 1600);
  });
}
/* Wiadomości: klik → link do pełnego widoku */
(function () {
  var feed = document.querySelector('.pv-msgfeed');
  if (!feed) return;
  var href = feed.closest('.b-tile') && feed.closest('.b-tile').querySelector('.b-tile__more');
  if (!href) return;
  feed.querySelectorAll('.pv-msg').forEach(function (li) {
    li.style.cursor = 'pointer';
    li.setAttribute('role', 'link');
    li.setAttribute('tabindex', '0');
    li.setAttribute('aria-label', (li.querySelector('.pv-msg__text') || {}).textContent || 'Wiadomość');
    li.addEventListener('click', function () { location.href = href.href; });
    li.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); location.href = href.href; } });
  });
}());
</script>
