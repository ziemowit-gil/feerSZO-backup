<?php
/**
 * crm/inbox.php — Skrzynka odbiorcza współdzielona w CRM.
 *
 * Ta sama baza wiadomości co Poczta EZD (crm_communications zasilane przez
 * PocztaScanService) — jeden zbiór danych, dwa widoki: kancelaria pracuje w EZD,
 * sprzedaż w CRM. „Przeczytane" i przypisanie są wspólne, bo to te same kolumny.
 *
 * Wiadomość można obsłużyć na trzy sposoby, bez wychodzenia z ekranu:
 *   • w CRM        — odpowiedź, sprawa CRM, oferta,
 *   • w EZD        — dopięcie do koszulki albo założenie nowej (rejestr pism),
 *   • przekazaniem — e-mail do osoby/skrzynki z cytatem i notatką.
 *
 * Układ master-detail, bez modali — działa z klawiatury i czytnikiem ekranu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE = 'Skrzynka CRM';
$can_write  = can_write('crm') || is_admin();
$uid        = (int)(current_user()['id'] ?? 0);
$ready      = crm_mailbox_ready();

// ── Akcje (POST → redirect, żeby odświeżenie nie powtarzało operacji) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('danger', 'Brak uprawnień.'); }
    else {
        $op   = (string)($_POST['_op'] ?? '');
        $mid  = (int)($_POST['msg_id'] ?? 0);
        $back = 'inbox.php?' . http_build_query(array_filter([
            'view'       => $_POST['view'] ?? null,
            'mailbox_id' => $_POST['mailbox_id'] ?? null,
            'q'          => $_POST['q'] ?? null,
            'msg'        => $mid ?: null,
        ]));

        switch ($op) {
            case 'read':      crm_mailbox_mark_read($mid, true);       flash_set('success', 'Oznaczono jako przeczytane.'); break;
            case 'unread':    crm_mailbox_mark_read($mid, false);      flash_set('success', 'Oznaczono jako nieprzeczytane.'); break;
            case 'archive':   crm_mailbox_set_status($mid, 'archived'); flash_set('success', 'Wiadomość załatwiona.'); break;
            case 'spam':      crm_mailbox_set_status($mid, 'spam');     flash_set('success', 'Oznaczono jako spam.'); break;
            case 'restore':   crm_mailbox_set_status($mid, 'active');   flash_set('success', 'Przywrócono do skrzynki.'); break;
            case 'assign_me': crm_mailbox_assign($mid, $uid);           flash_set('success', 'Przypisano Tobie.'); break;
            case 'hide':      crm_mailbox_set_hidden($mid, true);
                              flash_set('success', 'Wiadomość nie będzie już pokazywana w Skrzynce CRM (widok „Ukryte").');
                              $back = 'inbox.php?' . http_build_query(array_filter([
                                  'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                              ]));
                              break;
            case 'unhide':    crm_mailbox_set_hidden($mid, false); flash_set('success', 'Wiadomość wróciła do Skrzynki CRM.'); break;

            case 'bulk_hide':
            case 'bulk_unhide':
                $ids  = array_slice(array_unique(array_map('intval', (array)($_POST['msg_ids'] ?? []))), 0, 500);
                $hide = $op === 'bulk_hide';
                $n = 0;
                foreach ($ids as $bid) { if ($bid > 0 && crm_mailbox_set_hidden($bid, $hide)) $n++; }
                if (!$n) flash_set('warning', 'Nie zaznaczono żadnej wiadomości.');
                else flash_set('success', $hide
                    ? 'Porzucono ' . $n . ' wiad. — zniknęły ze Skrzynki CRM (widok „Ukryte").'
                    : 'Przywrócono ' . $n . ' wiad. do Skrzynki CRM.');
                $back = 'inbox.php?' . http_build_query(array_filter([
                    'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                    'q'    => $_POST['q'] ?? null,
                ]));
                break;

            case 'assign':
                crm_mailbox_assign($mid, (int)($_POST['user_id'] ?? 0) ?: null);
                flash_set('success', 'Przypisanie zmienione.');
                break;

            case 'case':
                $r = crm_mailbox_create_case($mid, [
                    'title'     => (string)($_POST['case_title'] ?? ''),
                    'priority'  => (string)($_POST['case_priority'] ?? 'medium'),
                    'assign_to' => (int)($_POST['case_owner'] ?? 0),
                    'keep_open' => !empty($_POST['case_keep_open']),
                ]);
                if (!empty($r['ok'])) {
                    flash_set('success', 'Sprawa utworzona z wiadomości.');
                    header('Location: ' . $r['url']); exit;
                }
                flash_set('danger', $r['error'] ?? 'Nie udało się utworzyć sprawy.');
                break;

            case 'ezd':
                $r = crm_mailbox_to_ezd(
                    $mid,
                    (int)($_POST['sprawa_id'] ?? 0) ?: null,
                    (int)($_POST['teczka_id'] ?? 0) ?: null
                );
                if (!empty($r['ok'])) {
                    flash_set('success', 'Wiadomość przekazana do EZD' . ($r['znak'] ? ' — ' . $r['znak'] : '') . '.');
                    header('Location: ' . ($r['url'] ?: $back)); exit;
                }
                flash_set('danger', $r['error'] ?: 'Nie udało się przekazać do EZD.');
                break;

            case 'forward':
                $r = crm_mailbox_forward($mid, (string)($_POST['fwd_to'] ?? ''), (string)($_POST['fwd_note'] ?? ''));
                flash_set(!empty($r['ok']) ? 'success' : 'danger',
                    !empty($r['ok']) ? 'Wiadomość przekazana — trafiła do kolejki wysyłki.' : ($r['error'] ?: 'Nie udało się przekazać.'));
                break;

            case 'scan':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                $r = crm_mailbox_scan((int)($_POST['scan_mailbox'] ?? 0) ?: null);
                if (empty($r['ok'])) flash_set('danger', $r['error'] ?? 'Skanowanie nieudane.');
                else flash_set($r['errors'] ? 'warning' : 'success', sprintf(
                    'Pobrano %d, dopasowano %d, zapisano %d, pominięto %d.%s',
                    $r['fetched'], $r['matched'], $r['created'], $r['skipped'],
                    $r['errors'] ? ' Uwagi: ' . implode(' | ', array_slice($r['errors'], 0, 2)) : ''
                ));
                break;

            case 'autocreate':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                crm_setting_save('poczta_autocreate_contacts', !empty($_POST['on']) ? '1' : '0');
                flash_set('success', 'Ustawienie zapisane.');
                break;
        }
        header('Location: ' . $back); exit;
    }
}

// ── Filtry i dane ───────────────────────────────────────────────────────────
$view   = isset(CRM_MAILBOX_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'new';
$mbox_f = (int)($_GET['mailbox_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$sel_id = (int)($_GET['msg'] ?? 0);

$inbox  = crm_mailbox_inbox(['view' => $view, 'mailbox_id' => $mbox_f, 'q' => $search, 'page' => $page, 'per_page' => 20]);
$counts = crm_mailbox_counts();
$boxes  = crm_mailbox_list(false);
$users  = [];
try { $users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}

if (!$sel_id && $inbox['rows']) $sel_id = (int)$inbox['rows'][0]['id'];
$msg = $sel_id ? crm_mailbox_message($sel_id) : null;
if ($msg && !(int)$msg['is_read'] && $can_write) {
    crm_mailbox_mark_read($sel_id, true);   // otwarcie = przeczytanie
    $msg['is_read'] = 1;
}

$ezd_on     = crm_mailbox_ezd_available();
$ezd_sprawy = $msg ? crm_mailbox_ezd_sprawy('', 30) : [];
$ezd_teczki = $msg ? crm_mailbox_ezd_teczki() : [];

$qs = static function (array $over = []) use ($view, $mbox_f, $search, $sel_id): string {
    return http_build_query(array_filter(array_merge([
        'view' => $view, 'mailbox_id' => $mbox_f ?: null, 'q' => $search ?: null, 'msg' => $sel_id ?: null,
    ], $over), static fn($v) => $v !== null && $v !== ''));
};

/** Nagłówek grupy dat na liście — „Dziś", „Wczoraj", inaczej data. */
$day_label = static function (string $ts): string {
    $d = date('Y-m-d', strtotime($ts));
    if ($d === date('Y-m-d')) return 'Dziś';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Wczoraj';
    return date('j.m.Y', strtotime($ts));
};

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.ib-wrap { display:grid; grid-template-columns:minmax(280px, 340px) minmax(0, 1fr); gap:1.1rem;
  align-items:start; min-height:26rem }
/* Trzecia szpalta (sprawy/oferty/wątek) tylko tam, gdzie jest na nią miejsce —
   niżej ląduje pod wiadomością, a nie obok listy. */
@media (min-width:1400px) {
  .ib-wrap.has-rail { grid-template-columns:minmax(280px, 330px) minmax(0, 1fr) minmax(230px, 270px) }
}
@media (max-width:1099px) { .ib-wrap { grid-template-columns:1fr; min-height:0 } }
/* Panel bez wybranej wiadomości: treść wyśrodkowana, a nie przyklejona do góry
   cienkiego paska — inaczej obok wysokiej listy wygląda jak błąd układu. */
.ib-empty { display:flex; align-items:center; justify-content:center; padding:2.5rem 1.15rem }
/* Lista ma się rozciągać na wysokość kolumny, żeby ramki obu paneli kończyły
   się na tej samej linii. */
.ib-wrap > div { min-width:0 }
.ib-wrap > div > .ib-list { height:100% }

/* ── Lista ─────────────────────────────────────────────────────────────── */
.ib-list { background:#fff; border:1px solid #E5E7EB; border-radius:12px; overflow:hidden }
.ib-day { padding:.35rem .85rem; background:#F9FAFB; border-bottom:1px solid #F1F2F4;
  font-size:.68rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#9CA3AF }
.ib-item { display:flex; gap:.65rem; padding:.7rem .85rem; border-bottom:1px solid #F3F4F6;
  text-decoration:none; color:#111827; align-items:flex-start }
.ib-item:last-child { border-bottom:none }
.ib-item:hover { background:#FAFBFC }
.ib-item.active { background:#F2F7FF; box-shadow:inset 3px 0 0 var(--crm-primary) }
.ib-av { width:34px; height:34px; flex-shrink:0; border-radius:50%; background:#E8EDF4; color:#3B4A5A;
  display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700 }
.ib-item.unread .ib-av { background:var(--crm-primary); color:#fff }
.ib-mid { min-width:0; flex:1 }
.ib-top { display:flex; align-items:baseline; gap:.5rem }
.ib-subj { font-size:.87rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1 }
.ib-item.unread .ib-subj { font-weight:700 }
.ib-time { font-size:.7rem; color:#9CA3AF; white-space:nowrap }
.ib-who { font-size:.78rem; color:#4B5563; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ib-snip { font-size:.76rem; color:#9CA3AF; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ib-tags { display:flex; gap:.3rem; margin-top:.15rem; flex-wrap:wrap }
.ib-tag { font-size:.66rem; font-weight:700; letter-spacing:.03em; padding:.05rem .4rem; border-radius:3px }

/* ── Panel wiadomości ──────────────────────────────────────────────────── */
.ib-pane { background:#fff; border:1px solid #E5E7EB; border-radius:12px }
.ib-head { padding:1rem 1.15rem .8rem }
.ib-h1 { font-size:1.08rem; font-weight:700; line-height:1.3; margin:0 0 .3rem }
.ib-meta { font-size:.8rem; color:#6B7280 }
.ib-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:2rem;
  background:#F3F4F6; font-size:.78rem; color:#374151; text-decoration:none }
.ib-chip:hover { background:#E9EDF3; color:#111827 }
.ib-bar { display:flex; gap:.4rem; flex-wrap:wrap; padding:.55rem 1.15rem; background:#FAFBFC;
  border-top:1px solid #F1F2F4; border-bottom:1px solid #F1F2F4 }
.ib-body { padding:1.15rem; font-size:.9rem; line-height:1.6; overflow-wrap:anywhere }
.ib-body img { max-width:100%; height:auto }
.ib-sep { width:1px; align-self:stretch; background:#E5E7EB; margin:0 .15rem }
/* Formularze akcji przeniesione do modali — z dawnych szuflad zostaje tylko
   wypełnienie treści, używane w oknach. */
.ib-dbody { padding:.2rem 0 0 }
.ib-ctx { padding:.9rem 1.15rem; border-top:1px solid #F1F2F4; font-size:.82rem }
.ib-ctx a { text-decoration:none }
.ib-lbl { font-size:.68rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#9CA3AF; margin-bottom:.25rem }
.ib-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .5rem .3rem .8rem; border-radius:2rem;
  font-size:.78rem; font-weight:600; text-decoration:none; border:2px solid transparent }
/* Licznik jako osobna plakietka — inaczej „Nowe 23" czyta się jak jedno wyrażenie. */
.ib-cnt { display:inline-block; min-width:1.5rem; padding:0 .35rem; border-radius:2rem;
  font-size:.72rem; font-weight:700; line-height:1.4; text-align:center }

/* ── Nagłówek strony (lokalny, jednowierszowy) ──────────────────────────── */
.ib-header { display:flex; align-items:center; justify-content:space-between;
  gap:1rem; flex-wrap:wrap; margin-bottom:.9rem }
.ib-header-title { display:flex; align-items:center; gap:.5rem;
  font-size:1.22rem; font-weight:700; color:#111827; line-height:1.2 }
.ib-header-actions { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; flex-shrink:0 }
.ib-help { color:#9CA3AF; font-size:.9rem; cursor:help; display:inline-flex }
.ib-help:hover, .ib-help:focus-visible { color:var(--crm-primary) }

/* ── Pasek narzędzi (widoki + filtry) ───────────────────────────────────
   Wcześniej pola i przyciski miały różne wysokości i „Sprawdź teraz" łamał
   się na dwie linie. Teraz jedna wysokość (32 px) i wspólny promień. */
.ib-toolbar { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem }
.ib-views { display:flex; gap:.4rem; flex-wrap:wrap; min-width:0 }
.ib-field, .ib-toolbar .form-select, .ib-toolbar .form-control {
  height:32px; font-size:.8rem; border:1px solid #E5E7EB; border-radius:8px;
  background:#fff; color:#111827; padding:0 .6rem }
.ib-field:focus, .ib-toolbar .form-select:focus, .ib-toolbar .form-control:focus {
  border-color:var(--crm-primary); box-shadow:0 0 0 3px rgba(1,118,211,.12); outline:none }
.ib-search { position:relative; min-width:200px }
.ib-search input { width:100%; padding-left:2rem }
.ib-search i { position:absolute; left:.6rem; top:50%; transform:translateY(-50%); color:#9CA3AF; font-size:.85rem }
.ib-tbtn { height:32px; display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap;
  padding:0 .7rem; border-radius:8px; border:1px solid #E5E7EB; background:#fff; color:#374151;
  font-size:.8rem; font-weight:500; text-decoration:none; cursor:pointer; transition:background .12s,border-color .12s }
.ib-tbtn:hover { background:#F3F4F6; border-color:#D1D5DB; color:#111827 }
.ib-tbtn:focus-visible { outline:2px solid var(--crm-primary); outline-offset:1px }
.ib-tbtn[disabled] { opacity:.5; cursor:not-allowed }
.ib-tbtn--primary { border-color:var(--crm-primary); color:var(--crm-primary); background:var(--crm-primary-bg) }
.ib-tbtn--primary:hover { background:#DCEBFA; color:var(--crm-primary) }
.ib-tbtn--on { border-color:var(--crm-primary); color:var(--crm-primary) }

/* ── Filtry w rozwijanym panelu ─────────────────────────────────────────── */
.ib-filter { position:relative; margin-left:auto }
.ib-filter > summary { list-style:none; user-select:none }
.ib-filter > summary::-webkit-details-marker { display:none }
.ib-filter-dot { width:6px; height:6px; border-radius:50%; background:var(--crm-primary) }
.ib-filter-panel { position:absolute; right:0; top:calc(100% + .4rem); z-index:20; width:min(320px, 90vw);
  display:flex; flex-direction:column; gap:.45rem; padding:.7rem; background:#fff;
  border:1px solid #E5E7EB; border-radius:10px; box-shadow:0 8px 24px rgba(16,24,40,.12) }
.ib-filter-panel .ib-field { width:100% }
@media (max-width:575px) { .ib-filter { margin-left:0; width:100% } .ib-filter-panel { right:auto; left:0 } }

/* ── Okno „Przekaż do EZD" ─────────────────────────────────────────────── */
.ib-ezd-subject { padding:.1rem 0 .7rem; border-bottom:1px solid #F1F2F4; margin-bottom:.8rem }
.ib-ezd-h2 { font-size:1.15rem; font-weight:700; line-height:1.35; margin:.15rem 0 .25rem; color:#111827;
  overflow-wrap:anywhere }
.ib-ezd-note { display:flex; gap:.6rem; align-items:flex-start; background:#ECFDF5; border:1px solid #A7F3D0;
  border-radius:10px; padding:.7rem .85rem; font-size:.82rem; line-height:1.5; color:#065F46; margin-bottom:1rem }
.ib-ezd-note i { font-size:1rem; color:#0F766E; flex-shrink:0; margin-top:.1rem }

/* ── Masowe działania na liście ─────────────────────────────────────────── */
.ib-bulk { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; padding:0 .2rem .35rem;
  font-size:.73rem; color:#9CA3AF }
.ib-bulk label { display:inline-flex; align-items:center; gap:.3rem; margin:0; cursor:pointer; color:#9CA3AF }
.ib-bulk label:hover { color:#4B5563 }
.ib-bulk input[type=checkbox] { width:.85rem; height:.85rem; cursor:pointer }
.ib-bulk-n { color:#4B5563; font-weight:600 }
/* Przycisk akcji chowa się, dopóki nic nie jest zaznaczone — pasek ma nie krzyczeć. */
.ib-bulk-act { display:none; margin-left:auto; align-items:center; gap:.25rem; border:none; background:transparent;
  padding:.1rem .35rem; border-radius:5px; font-size:.73rem; font-weight:600; color:#B91C1C; cursor:pointer }
.ib-bulk-act:hover { background:#FEF2F2 }
.ib-bulk-act:focus-visible { outline:2px solid var(--crm-primary); outline-offset:1px }
.ib-bulk.is-armed .ib-bulk-act { display:inline-flex }
.ib-bulk.is-armed { color:#4B5563 }
.ib-bulk--restore .ib-bulk-act { color:#0F766E }
.ib-bulk--restore .ib-bulk-act:hover { background:#ECFDF5 }
/* ── Pasek skrzynki (kolor = skrzynka, klik = filtr) ───────────────────── */
.ib-stripe { flex-shrink:0; width:4px; align-self:stretch; border:none; padding:0; display:block;
  cursor:pointer; transition:width .12s }
.ib-stripe:hover, .ib-stripe:focus-visible { width:7px; outline:none }
.ib-mbox-legend { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; padding:0 .2rem .4rem;
  font-size:.72rem }
.ib-mbox-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.1rem .45rem; border-radius:2rem;
  border:1px solid #E5E7EB; background:#fff; color:#6B7280; text-decoration:none; white-space:nowrap;
  transition:border-color .12s, color .12s }
.ib-mbox-chip:hover { color:#111827; border-color:#D1D5DB }
.ib-mbox-chip.is-on { color:#111827; font-weight:600 }
.ib-mbox-dot { width:8px; height:8px; border-radius:2px; flex-shrink:0 }

.ib-row { display:flex; align-items:stretch; border-bottom:1px solid #F3F4F6 }
.ib-row:last-child { border-bottom:none }
.ib-row .ib-item { flex:1; min-width:0; border-bottom:none }
.ib-row.is-checked { background:#FAFAFA }
.ib-check { flex-shrink:0; margin:.95rem .15rem .95rem .65rem; width:.9rem; height:.9rem; cursor:pointer;
  opacity:.45; transition:opacity .12s }
.ib-row:hover .ib-check, .ib-check:checked, .ib-check:focus-visible { opacity:1 }

/* ── Numer wiadomości ───────────────────────────────────────────────────── */
/* Na liście numer nie walczy o miejsce z tematem: w rogu siedzi sama ikonka,
   cyfry pokazują się dopiero po najechaniu (i zawsze są w title dla czytników). */
.ib-item { position:relative }
.ib-id { position:absolute; right:.55rem; bottom:.4rem; display:inline-flex; align-items:center; gap:.25rem;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.66rem; font-weight:700;
  color:#C3C8D0; background:transparent; border-radius:4px; padding:.05rem .25rem; pointer-events:none;
  transition:color .12s, background .12s }
.ib-id-no { max-width:0; overflow:hidden; white-space:nowrap; opacity:0; transition:max-width .16s, opacity .12s }
.ib-item .ib-snip { padding-right:1.8rem }   /* miejsce na ikonkę numeru */
.ib-item:hover .ib-id, .ib-item:focus-visible .ib-id, .ib-item.active .ib-id { color:#6B7280; background:#F3F4F6 }
.ib-item:hover .ib-id-no, .ib-item:focus-visible .ib-id-no, .ib-item.active .ib-id-no { max-width:6rem; opacity:1 }
.ib-no { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.7rem; font-weight:700;
  color:#6B7280; background:#F3F4F6; border-radius:4px; padding:.05rem .3rem; letter-spacing:.02em }
.ib-h1-no { font-size:.75rem; color:#9CA3AF; font-weight:600; letter-spacing:.04em }

/* ── Prawa szpalta: sprawy, oferty, wątek (rozwijane) ───────────────────── */
.ib-rail { display:flex; flex-direction:column; gap:.6rem; min-width:0 }
.ib-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; overflow:hidden }
.ib-card > summary { list-style:none; cursor:pointer; padding:.6rem .8rem; display:flex; align-items:center;
  gap:.45rem; font-size:.76rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#6B7280 }
.ib-card > summary::-webkit-details-marker { display:none }
.ib-card > summary:hover { background:#FAFBFC; color:#374151 }
.ib-card > summary:focus-visible { outline:2px solid var(--crm-primary); outline-offset:-2px }
.ib-card > summary .ib-caret { margin-left:auto; transition:transform .15s; color:#9CA3AF }
.ib-card[open] > summary .ib-caret { transform:rotate(90deg) }
.ib-card > summary .ib-cnt2 { font-size:.7rem; font-weight:700; color:#374151; background:#F3F4F6;
  border-radius:2rem; padding:0 .4rem; text-transform:none; letter-spacing:0 }
.ib-card-body { padding:.2rem .8rem .7rem; font-size:.82rem }
.ib-card-body a { text-decoration:none }
.ib-rail-item { display:block; padding:.3rem 0; border-top:1px solid #F3F4F6; overflow-wrap:anywhere }
.ib-rail-item:first-child { border-top:none }
@media (max-width:1399px) { .ib-wrap.has-rail > .ib-rail { grid-column:1 / -1 } }
</style>

<?php /* Nagłówek LOKALNY, jednowierszowy — .crm-page-header jest komponentem
         współdzielonym przez 16 stron CRM i układa tytuł nad podtytułem, co na tym
         ekranie kosztowało cały wiersz przed listą. Opis modułu nie znika: siedzi
         w podpowiedzi przy tytule, bo przydaje się raz, a zabierał miejsce zawsze. */ ?>
<div class="ib-header">
  <div class="ib-header-title">
    <i class="bi bi-inbox-fill" style="color:#0176D3" aria-hidden="true"></i>
    <span>Skrzynka CRM</span>
    <span class="ib-help" tabindex="0" role="note"
          aria-label="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, przekaż do EZD albo dalej e-mailem"
          title="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, przekaż do EZD albo dalej e-mailem">
      <i class="bi bi-question-circle" aria-hidden="true"></i>
    </span>
  </div>
  <div class="ib-header-actions">
    <?php if (is_admin()): ?>
    <form method="post" class="d-flex gap-1 align-items-center">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="scan">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <?php if (count($boxes) > 1): ?>
      <select name="scan_mailbox" class="ib-field" style="max-width:200px" aria-label="Skrzynka do sprawdzenia">
        <option value="">wszystkie skrzynki</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>"><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="ib-tbtn ib-tbtn--primary" <?= $ready && $boxes ? '' : 'disabled' ?>>
        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Sprawdź teraz
      </button>
    </form>
    <a href="<?= APP_URL ?>/poczta/index.php" class="ib-tbtn" title="Konfiguracja skrzynek" aria-label="Konfiguracja skrzynek"><i class="bi bi-gear" aria-hidden="true"></i></a>
    <?php if (crm_mailbox_autocreate()): ?>
    <!-- Stan „włączone" nie zasługuje na osobny wiersz nad listą — to potwierdzenie,
         nie ostrzeżenie. Wariant WYŁĄCZONY zostaje pełnym alertem niżej, bo mówi
         o wiadomościach, które są pomijane. -->
    <form method="post" class="d-inline-flex align-items-center gap-1" style="font-size:.76rem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="autocreate">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <span class="text-success text-nowrap" title="Wiadomości od nieznanych nadawców zakładają kartotekę kontaktu">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i> auto-kartoteka
      </span>
      <button class="btn btn-link btn-sm p-0 text-muted" name="on" value="" style="font-size:.76rem">wyłącz</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warning">
  <strong>Moduł poczty nie jest gotowy.</strong> Skrzynka CRM czyta wiadomości pobierane przez moduł poczty.
  Skonfiguruj skrzynkę w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a>.
</div>
<?php elseif (!$boxes): ?>
<?php $any_mailbox = 0; try { $any_mailbox = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes")['n'] ?? 0); } catch (\Throwable $e) {} ?>
<div class="alert alert-info">
  <?php if ($any_mailbox && !is_admin()): ?>
  <strong>Nie masz dostępu do żadnej skrzynki.</strong>
  Skrzynki współdzielone przydziela administrator (Poczta → Skrzynki → Edytuj → „Kto ma dostęp"),
  a skrzynka osobista jest widoczna dla swojego właściciela.
  <?php else: ?>
  Nie dodano jeszcze żadnej skrzynki — dodaj ją w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a>.
  Wiadomości pojawią się tu po pierwszym skanowaniu.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_admin() && !crm_mailbox_autocreate()): ?>
<form method="post" class="alert alert-light border d-flex align-items-center gap-2 flex-wrap py-2">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="autocreate">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <input type="hidden" name="on" value="1">
  <i class="bi bi-info-circle" aria-hidden="true"></i>
  <div class="flex-grow-1" style="font-size:.85rem">
    Wiadomości od nadawców spoza kartoteki są <strong>pomijane</strong> — zapytania nowych klientów nie trafiają do CRM.
  </div>
  <button class="btn btn-sm btn-outline-dark">Zakładaj kartotekę dla nieznanych nadawców</button>
</form>
<?php endif; ?>

<!-- Widoki + filtry (jeden pasek, pola tej samej wysokości) -->
<div class="ib-toolbar">
  <div class="ib-views">
  <?php foreach (CRM_MAILBOX_VIEWS as $vk => $vv): $n = (int)($counts[$vk] ?? 0);
        if ($vk === 'hidden' && !$n && $view !== 'hidden') continue;   // pusty widok „Ukryte" nie zajmuje miejsca ?>
  <a href="?<?= $qs(['view' => $vk, 'msg' => null, 'page' => null]) ?>" class="ib-pill"
     style="background:<?= $view === $vk ? 'var(--crm-primary-bg)' : '#F3F4F6' ?>;
            color:<?= $view === $vk ? 'var(--crm-primary)' : '#374151' ?>;
            border-color:<?= $view === $vk ? 'var(--crm-primary)' : 'transparent' ?>">
    <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
    <?php
      // Licznik pokazujemy ZAWSZE, wyszarzony przy zerze. Ukrywanie zera sprawiało,
      // że „Spam" wyglądał na widok bez licznika, a nie na widok pusty.
      $cnt_style = $n
        ? ($view === $vk ? 'background:var(--crm-primary);color:#fff' : 'background:#E5E7EB;color:#374151')
        : 'background:transparent;color:#9CA3AF;border:1px solid #E5E7EB';
    ?>
    <span class="ib-cnt" style="<?= $cnt_style ?>"><?= $n ?></span>
  </a>
  <?php endforeach; ?>
  </div>

  <?php $filters_on = ($search !== '' || $mbox_f > 0); ?>
  <?php /* Filtry domyślnie schowane — pasek widoków ma zostać czysty. <details>
           zamiast JS-a: otwiera się z klawiatury i działa bez skryptu, a gdy filtr
           jest ustawiony, panel jest od razu otwarty (żeby nie ukrywać stanu). */ ?>
  <details class="ib-filter" <?= $filters_on ? 'open' : '' ?>>
    <summary class="ib-tbtn<?= $filters_on ? ' ib-tbtn--on' : '' ?>" role="button"
             aria-label="Filtry skrzynki<?= $filters_on ? ' (aktywne)' : '' ?>">
      <i class="bi bi-funnel<?= $filters_on ? '-fill' : '' ?>" aria-hidden="true"></i>Filtry
      <?php if ($filters_on): ?><span class="ib-filter-dot" aria-hidden="true"></span><?php endif; ?>
    </summary>
    <form method="get" class="ib-filter-panel" role="search">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <div class="ib-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input name="q" class="ib-field" value="<?= h($search) ?>" aria-label="Szukaj w skrzynce"
               placeholder="Temat, nadawca, kontakt, nr wiadomości…">
      </div>
      <?php if (count($boxes) > 1): ?>
      <select name="mailbox_id" class="ib-field" aria-label="Skrzynka">
        <option value="">wszystkie skrzynki</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $mbox_f === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <div class="d-flex gap-2">
        <button class="ib-tbtn ib-tbtn--primary flex-grow-1"><i class="bi bi-funnel" aria-hidden="true"></i>Filtruj</button>
        <?php if ($filters_on): ?>
        <a href="?view=<?= h($view) ?>" class="ib-tbtn" title="Wyczyść filtry">
          <i class="bi bi-x-lg" aria-hidden="true"></i>Wyczyść
        </a>
        <?php endif; ?>
      </div>
    </form>
  </details>
</div>

<?php
  // Klasa has-rail włącza trzecią szpaltę dopiero, gdy jest co w niej pokazać.
  $has_rail = $msg && (!empty($msg['ctx']['cases']) || !empty($msg['ctx']['offers'])
                       || count($msg['ctx']['thread'] ?? []) > 1);
?>
<div class="ib-wrap<?= $has_rail ? ' has-rail' : '' ?>">

  <!-- ══ LISTA ═══════════════════════════════════════════════════════════ -->
  <div>
    <?php $bulk_view = $view === 'hidden';
          $abandon_days = (int)(crm_setting('crm_inbox_abandon_days') ?: 90); ?>
    <?php if ($bulk_view && $abandon_days > 0): ?>
    <div class="text-muted mb-2" style="font-size:.75rem">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
      Wiadomości bez akcji (aktywne, bez opiekuna, poza EZD) starsze niż <strong><?= $abandon_days ?></strong> dni
      są porzucane automatycznie. Zostają tutaj — w Poczcie i EZD bez zmian.
    </div>
    <?php endif; ?>
    <?php if (count($boxes) > 1): ?>
    <!-- Legenda skrzynek = filtr. Ten sam kolor ma pasek przy każdej wiadomości,
         więc widać z listy, na którą skrzynkę wpłynęła. -->
    <div class="ib-mbox-legend">
      <a class="ib-mbox-chip<?= $mbox_f ? '' : ' is-on' ?>" href="?<?= $qs(['mailbox_id' => null, 'msg' => null, 'page' => null]) ?>">
        wszystkie
      </a>
      <?php foreach ($boxes as $b): $bid = (int)$b['id']; $bc = crm_mailbox_color($bid); ?>
      <a class="ib-mbox-chip<?= $mbox_f === $bid ? ' is-on' : '' ?>"
         style="<?= $mbox_f === $bid ? 'border-color:' . h($bc) : '' ?>"
         href="?<?= $qs(['mailbox_id' => $bid, 'msg' => null, 'page' => null]) ?>"
         title="Pokaż tylko wiadomości ze skrzynki <?= h($b['mailbox']) ?>">
        <span class="ib-mbox-dot" style="background:<?= h($bc) ?>" aria-hidden="true"></span>
        <?= h($b['mailbox']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($can_write && $inbox['rows']): ?>
    <!-- Masowe porzucanie: zaznaczenie działa na tym, co widać w bieżącym widoku. -->
    <form method="post" id="ibBulkForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" id="ibBulkOp" value="<?= $bulk_view ? 'bulk_unhide' : 'bulk_hide' ?>">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <input type="hidden" name="mailbox_id" value="<?= $mbox_f ?: '' ?>">
      <input type="hidden" name="q" value="<?= h($search) ?>">
      <div class="ib-bulk<?= $bulk_view ? ' ib-bulk--restore' : '' ?>" id="ibBulkBar">
        <label>
          <input type="checkbox" id="ibCheckAll"
                 aria-label="Zaznacz wszystkie widoczne wiadomości (<?= count($inbox['rows']) ?>)">
          <span>Zaznacz widoczne (<?= count($inbox['rows']) ?>)</span>
        </label>
        <span id="ibBulkInfo" hidden>· <span class="ib-bulk-n" id="ibBulkN">0</span> zazn.</span>
        <button class="ib-bulk-act" id="ibBulkBtn" disabled
                <?= $bulk_view ? '' : 'onclick="return confirm(\'Porzucić zaznaczone wiadomości? Znikną ze Skrzynki CRM — zostaną w widoku Ukryte, w Poczcie i EZD bez zmian.\')"' ?>>
          <?php if ($bulk_view): ?>
          <i class="bi bi-eye" aria-hidden="true"></i>Przywróć
          <?php else: ?>
          <i class="bi bi-hand-thumbs-down" aria-hidden="true"></i>Porzuć zaznaczone
          <?php endif; ?>
        </button>
      </div>
    </form>
    <?php endif; ?>
    <div class="ib-list" role="list" aria-label="Wiadomości">
      <?php if (!$inbox['rows']): ?>
      <div class="text-center text-muted d-flex flex-column justify-content-center" style="min-height:22rem;padding:1.15rem">
        <div>
          <i class="bi bi-inbox display-6 d-block mb-2 opacity-25" aria-hidden="true"></i>
          <div style="font-size:.9rem">Brak wiadomości w tym widoku</div>
          <div style="font-size:.78rem" class="mt-1">Zmień filtr albo sprawdź skrzynkę ponownie.</div>
        </div>
      </div>
      <?php else: $last_day = ''; foreach ($inbox['rows'] as $r):
        $day = $day_label((string)$r['sent_at']);
        if ($day !== $last_day) { $last_day = $day; echo '<div class="ib-day">' . h($day) . '</div>'; }
        $act    = (int)$r['id'] === $sel_id;
        $who    = (string)($r['from_name'] ?: $r['from_email'] ?: $r['contact_name'] ?: '—');
        $unread = !(int)$r['is_read'];
        $no     = crm_msg_no((int)$r['id'], $r['msg_no'] ?? null);
      ?>
      <div class="ib-row">
      <?php if (!empty($r['mailbox_id'])): $rc = crm_mailbox_color((int)$r['mailbox_id']); ?>
      <a class="ib-stripe" style="background:<?= h($rc) ?>"
         href="?<?= $qs(['mailbox_id' => (int)$r['mailbox_id'], 'msg' => null, 'page' => null]) ?>"
         title="Wpłynęło na: <?= h($r['mailbox_name'] ?: '—') ?> — kliknij, aby filtrować"
         aria-label="Filtruj: skrzynka <?= h($r['mailbox_name'] ?: '—') ?>"></a>
      <?php else: ?>
      <span class="ib-stripe" style="background:#E5E7EB" aria-hidden="true"></span>
      <?php endif; ?>
      <?php if ($can_write): ?>
      <input type="checkbox" class="ib-check" form="ibBulkForm" name="msg_ids[]" value="<?= (int)$r['id'] ?>"
             aria-label="Zaznacz wiadomość: <?= h($r['subject'] ?: '(bez tematu)') ?>">
      <?php endif; ?>
      <a role="listitem" class="ib-item<?= $act ? ' active' : '' ?><?= $unread ? ' unread' : '' ?>"
         href="?<?= $qs(['msg' => (int)$r['id']]) ?>" aria-current="<?= $act ? 'true' : 'false' ?>">
        <span class="ib-av" aria-hidden="true"><?= h(CrmManager::makeInitials($who)) ?></span>
        <span class="ib-mid">
          <span class="ib-top">
            <span class="ib-subj"><?= h($r['subject'] ?: '(bez tematu)') ?></span>
            <?php if ((int)$r['has_attachments']): ?><i class="bi bi-paperclip text-muted" aria-label="Załącznik"></i><?php endif; ?>
            <span class="ib-time"><?= h(date('H:i', strtotime((string)$r['sent_at']))) ?></span>
          </span>
          <span class="ib-who d-block"><?= h($who) ?></span>
          <span class="ib-snip d-block"><?= h(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 90)) ?></span>
          <?php if (!empty($r['assigned_name']) || $r['inbox_status'] !== 'active' || $unread): ?>
          <span class="ib-tags">
            <?php if ($unread): ?><span class="ib-tag" style="background:var(--crm-primary-bg);color:var(--crm-primary)">nowa</span><?php endif; ?>
            <?php if (!empty($r['assigned_name'])): ?>
            <span class="ib-tag" style="background:#EEF2FF;color:#4338CA"><?= h($r['assigned_name']) ?></span>
            <?php endif; ?>
            <?php if ($r['inbox_status'] === 'archived'): ?>
            <span class="ib-tag" style="background:#EFF7ED;color:#2E844A">załatwione</span>
            <?php elseif ($r['inbox_status'] === 'spam'): ?>
            <span class="ib-tag" style="background:#FEF2F2;color:#DC2626">spam</span>
            <?php endif; ?>
          </span>
          <?php endif; ?>
        </span>
        <?php if ($no !== ''): ?>
        <span class="ib-id" title="Numer wiadomości <?= h($no) ?>">
          <i class="bi bi-upc" aria-hidden="true"></i><span class="ib-id-no">#<?= h($no) ?></span>
        </span>
        <?php endif; ?>
      </a>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <?php $pages = (int)ceil($inbox['total'] / max(1, $inbox['per_page'])); if ($pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-2">
      <small class="text-muted">Łącznie: <strong><?= (int)$inbox['total'] ?></strong></small>
      <div class="d-flex gap-1 flex-wrap">
        <?php for ($p = 1; $p <= min($pages, 12); $p++): ?>
        <a href="?<?= $qs(['page' => $p, 'msg' => null]) ?>"
           class="btn btn-sm <?= $p === (int)$inbox['page'] ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ WIADOMOŚĆ ═══════════════════════════════════════════════════════ -->
  <div>
  <?php if (!$msg): ?>
    <div class="ib-pane ib-empty">
      <div class="text-center text-muted">
        <i class="bi bi-envelope-open display-6 d-block mb-2 opacity-25" aria-hidden="true"></i>
        <div style="font-size:.9rem">Wybierz wiadomość z listy</div>
        <div style="font-size:.78rem" class="mt-1">
          <?= $inbox['rows'] ? 'Treść, załączniki i akcje pokażą się tutaj.' : 'W tym widoku nie ma wiadomości.' ?>
        </div>
      </div>
    </div>
  <?php else:
    $hidden = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="msg_id" value="' . (int)$msg['id'] . '">'
            . '<input type="hidden" name="view" value="' . h($view) . '">'
            . '<input type="hidden" name="q" value="' . h($search) . '">';
  ?>
    <?php
      $rail_cases  = $msg['ctx']['cases']  ?? [];
      $rail_offers = $msg['ctx']['offers'] ?? [];
      $rail_thread = count($msg['ctx']['thread'] ?? []) > 1 ? $msg['ctx']['thread'] : [];
    ?>
    <div class="ib-pane">

      <div class="ib-head">
        <?php $msg_no = crm_msg_no((int)$msg['id'], $msg['msg_no'] ?? null); ?>
        <?php if ($msg_no !== ''): ?>
        <div class="ib-h1-no">WIADOMOŚĆ NR <span class="ib-no">#<?= h($msg_no) ?></span></div>
        <?php endif; ?>
        <h1 class="ib-h1"><?= h($msg['subject'] ?: '(bez tematu)') ?></h1>
        <div class="ib-meta">
          <strong><?= h($msg['from_name'] ?: ($msg['from_email'] ?: '—')) ?></strong>
          <?php if (!empty($msg['from_email']) && $msg['from_name']): ?>
          &lt;<?= h($msg['from_email']) ?>&gt;
          <?php endif; ?>
          · <?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?>
          <?php if (!empty($msg['mailbox_name'])): ?>
          · na <span class="ib-mbox-dot d-inline-block align-middle"
                    style="background:<?= h(crm_mailbox_color((int)($msg['mailbox_id'] ?? 0))) ?>"
                    aria-hidden="true"></span>
          <?= h($msg['mailbox_name']) ?>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-2">
          <?php if (!empty($msg['contact_id'])): ?>
          <a class="ib-chip" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$msg['contact_id'] ?>">
            <i class="bi <?= h(CRM_CONTACT_TYPES[$msg['contact_type']]['icon'] ?? 'bi-person') ?>"></i>
            <?= h($msg['contact_name']) ?>
          </a>
          <?php endif; ?>
          <?php if (!empty($msg['assigned_name'])): ?>
          <span class="ib-chip" style="background:#EEF2FF;color:#4338CA">
            <i class="bi bi-person-check-fill"></i><?= h($msg['assigned_name']) ?>
          </span>
          <?php endif; ?>
          <?php if (!empty($msg['ezd_sprawa_id'])): ?>
          <a class="ib-chip" style="background:#ECFDF5;color:#0F766E"
             href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$msg['ezd_sprawa_id'] ?>">
            <i class="bi bi-folder-symlink"></i>w EZD
          </a>
          <?php endif; ?>
          <?php if ($msg['inbox_status'] !== 'active'): ?>
          <span class="ib-chip"><?= $msg['inbox_status'] === 'archived' ? 'załatwione' : h($msg['inbox_status']) ?></span>
          <?php endif; ?>
          <?php if ((int)($msg['crm_hidden'] ?? 0) === 1): ?>
          <?php $auto = empty($msg['crm_hidden_by']); ?>
          <span class="ib-chip" style="background:#FEF3C7;color:#92400E"
                title="<?= $auto ? 'Porzucona automatycznie — leżała bez akcji dłużej niż próg z ustawień' : 'Ktoś schował tę wiadomość ze Skrzynki CRM' ?>">
            <i class="bi bi-eye-slash"></i><?= $auto ? 'porzucona automatycznie' : 'ukryta w CRM' ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($can_write): ?>
      <div class="ib-bar">
        <?php if ((int)($msg['assigned_to'] ?? 0) !== $uid): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="assign_me">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-person-check me-1"></i>Wezmę to</button></form>
        <?php endif; ?>
        <?php if (!empty($msg['contact_id'])): ?>
        <button class="btn btn-crm-outline btn-sm" onclick="openCommModal(<?= (int)$msg['contact_id'] ?>,'email')">
          <i class="bi bi-reply me-1"></i>Odpowiedz
        </button>
        <?php endif; ?>
        <?php if ($msg['inbox_status'] === 'active'): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="archive">
          <button class="btn btn-crm-outline btn-sm"><i class="bi bi-check2-all me-1"></i>Załatwione</button></form>
        <?php else: ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="restore">
          <button class="btn btn-crm-outline btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć</button></form>
        <?php endif; ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unread">
          <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako nieprzeczytane"><i class="bi bi-envelope"></i></button></form>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="spam">
          <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako spam"><i class="bi bi-slash-circle"></i></button></form>

        <?php if ((int)($msg['crm_hidden'] ?? 0) === 1): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unhide">
          <button class="btn btn-crm-outline btn-sm" title="Wiadomość wróci do widoków Skrzynki CRM">
            <i class="bi bi-eye me-1"></i>Pokazuj w CRM Inbox</button></form>
        <?php else: ?>
          <?php if (empty($msg['assigned_to'])): ?>
          <!-- „Porzuć" — dla wiadomości bez opiekuna: nikt jej nie prowadzi i nie
               będzie. Nie kasujemy nic z poczty, chowamy tylko ze Skrzynki CRM. -->
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="hide">
            <button class="btn btn-crm-ghost btn-sm text-danger" title="Nikt tego nie poprowadzi — schowaj ze Skrzynki CRM">
              <i class="bi bi-hand-thumbs-down me-1"></i>Porzuć</button></form>
          <?php endif; ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="hide">
          <button class="btn btn-crm-ghost btn-sm"
                  title="Wiadomość zniknie z widoków Skrzynki CRM — zostanie w widoku Ukryte, w Poczcie i EZD bez zmian">
            <i class="bi bi-eye-slash me-1"></i>Nie pokazuj więcej w CRM Inbox</button></form>
        <?php endif; ?>
        <?php if ($can_write): ?>
        <!-- Skróty do trzech głównych akcji. Formularze zostają niżej, bo wymagają
             pól, ale przy długiej wiadomości były poza ekranem — a to one są
             powodem, dla którego ktoś tu wchodzi. Klik rozwija i przewija do
             właściwej szuflady. -->
        <span class="ib-sep" aria-hidden="true"></span>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdCase"
                title="Załóż sprawę CRM z tej wiadomości">
          <i class="bi bi-briefcase-fill me-1" style="color:#1D4ED8" aria-hidden="true"></i>Sprawa
        </button>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdEzd"
                title="Przekaż wiadomość do EZD">
          <i class="bi bi-folder-symlink-fill me-1" style="color:#0F766E" aria-hidden="true"></i>EZD
        </button>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdFwd"
                title="Przekaż wiadomość e-mailem">
          <i class="bi bi-forward-fill me-1" style="color:#B45309" aria-hidden="true"></i>Przekaż
        </button>
        <?php endif; ?>
        <form method="post" class="ms-auto"><?= $hidden ?><input type="hidden" name="_op" value="assign">
          <select name="user_id" class="form-select form-select-sm" style="max-width:200px"
                  aria-label="Przypisz osobę" onchange="this.form.submit()">
            <option value="">— przypisz osobę —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($msg['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!empty($msg['attachments'])): ?>
      <div class="ib-ctx" style="border-top:none">
        <div class="ib-lbl">Załączniki</div>
        <?php foreach ($msg['attachments'] as $a): ?>
          <?php if (!empty($a['stored_path'])): ?>
          <a href="<?= h(upload_link($a['stored_path'])) ?>" target="_blank" class="me-2">
            <i class="bi bi-paperclip"></i> <?= h($a['original_name']) ?>
          </a>
          <?php else: ?>
          <span class="text-muted me-2" title="Plik nie został pobrany na serwer">
            <i class="bi bi-paperclip"></i> <?= h($a['original_name']) ?>
          </span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="ib-body">
        <?php if (!empty($msg['body_html'])):
          // Treść z zewnątrz — zostawiamy formatowanie, wycinamy wykonywalne elementy.
          $html = (string)$msg['body_html'];
          $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
          $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html);
          $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
          $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '$1="#"', $html);
          echo $html;
        else: ?>
          <?= nl2br(h((string)$msg['body'])) ?>
        <?php endif; ?>
      </div>

      <?php if ($can_write): ?>
      <?php
        /* Formularze akcji buforujemy TUTAJ, a pokazujemy w modalach na końcu
           strony. Znaczniki żyją w jednym miejscu (bez duplikacji), a okno nie
           zależy od przewinięcia długiej wiadomości. Modale muszą stać POZA
           .ib-wrap — modal w kontenerze z overflow potrafi się źle pozycjonować. */
        ob_start(); ?>
        <div class="ib-dbody">
          <form method="post" class="row g-2 align-items-end"><?= $hidden ?>
            <input type="hidden" name="_op" value="case">
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1">Tytuł sprawy</label>
              <input name="case_title" class="form-control form-control-sm"
                     value="<?= h($msg['subject'] ?: 'Wiadomość e-mail') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Priorytet</label>
              <select name="case_priority" class="form-select form-select-sm">
                <option value="low">Niski</option>
                <option value="medium" selected>Średni</option>
                <option value="high">Wysoki</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Prowadzi</label>
              <select name="case_owner" class="form-select form-select-sm">
                <option value="">ja</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="case_keep_open" value="1" id="ckopen">
                <label class="form-check-label small" for="ckopen">Zostaw wiadomość w skrzynce (domyślnie idzie do „załatwionych")</label>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <button class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Utwórz sprawę</button>
            </div>
          </form>
        </div>
      <?php $mdl_case = ob_get_clean(); ob_start(); ?>
        <div class="ib-dbody">
          <?php /* EZD to rejestr korespondencji formalnej, nie archiwum wszystkiego —
                   dlatego zanim ktoś kliknie „Dopnij", pokazujemy czego dotyczy decyzja
                   (pełny temat) i po co w ogóle jest EZD. */ ?>
          <div class="ib-ezd-subject">
            <div class="ib-lbl">Wiadomość przekazywana do EZD</div>
            <h2 class="ib-ezd-h2"><?= h($msg['subject'] ?: '(bez tematu)') ?></h2>
            <div class="text-muted" style="font-size:.8rem">
              <?= h($msg['from_name'] ?: ($msg['from_email'] ?: '—')) ?>
              · <?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?>
              <?php $ezd_no = crm_msg_no((int)$msg['id'], $msg['msg_no'] ?? null); ?>
              <?php if ($ezd_no !== ''): ?> · <span class="ib-no">#<?= h($ezd_no) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="ib-ezd-note">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <div>
              <strong>EZD prowadzi obieg korespondencji formalnej</strong> — takiej, która wszczyna
              albo dokumentuje sprawę urzędową: pismo z urzędu wzywające do działania, wniosek
              beneficjenta o umowę, wezwanie, skarga, decyzja. Taka wiadomość dostaje znak sprawy
              i trafia do rejestru pism.
              <div class="mt-1">
                Zwykłe zapytanie handlowe, ustalenia z klientem czy korespondencja robocza
                <strong>nie idą do EZD</strong> — zostają w CRM (sprawa CRM albo oferta).
              </div>
            </div>
          </div>
          <?php if (!$ezd_on): ?>
            <div class="text-muted" style="font-size:.85rem">
              Moduł EZD jest wyłączony albo nie masz w nim uprawnień do zapisu — przekazanie niedostępne.
            </div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-6">
                <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="ezd">
                  <div class="ib-lbl">Dopnij do istniejącej koszulki</div>
                  <div class="input-group input-group-sm">
                    <select name="sprawa_id" class="form-select" required>
                      <option value="">— wybierz koszulkę —</option>
                      <?php foreach ($ezd_sprawy as $sp): ?>
                      <option value="<?= (int)$sp['id'] ?>">
                        <?= h($sp['znak_sprawy']) ?> — <?= h(mb_substr((string)$sp['title'], 0, 60)) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-crm-outline"><i class="bi bi-link-45deg"></i> Dopnij</button>
                  </div>
                  <div class="form-text" style="font-size:.72rem">
                    Powstanie pismo w rejestrze EZD, wiadomość trafi do „załatwionych".
                  </div>
                </form>
              </div>
              <div class="col-md-6">
                <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="ezd">
                  <div class="ib-lbl">Albo załóż nową koszulkę</div>
                  <div class="input-group input-group-sm">
                    <select name="teczka_id" class="form-select" required>
                      <option value="">— segregator (teczka) —</option>
                      <?php foreach ($ezd_teczki as $t): ?>
                      <option value="<?= (int)$t['id'] ?>">
                        <?= h($t['symbol']) ?> — <?= h(mb_substr((string)$t['title'], 0, 50)) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-crm-outline"><i class="bi bi-folder-plus"></i> Załóż</button>
                  </div>
                  <div class="form-text" style="font-size:.72rem">
                    Znak sprawy nadaje EZD — temat wiadomości staje się tytułem koszulki.
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php $mdl_ezd = ob_get_clean(); ob_start(); ?>
        <div class="ib-dbody">
          <form method="post" class="row g-2 align-items-end"><?= $hidden ?>
            <input type="hidden" name="_op" value="forward">
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1">Adres odbiorcy</label>
              <input name="fwd_to" type="email" class="form-control form-control-sm" required
                     placeholder="np. ksiegowosc@feer.org.pl" list="fwdList">
              <datalist id="fwdList">
                <?php foreach ($users as $u): $ue = db_one("SELECT email FROM users WHERE id=?", [(int)$u['id']])['email'] ?? '';
                  if ($ue): ?><option value="<?= h($ue) ?>"><?= h($u['name']) ?></option><?php endif; endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1">Notatka dla odbiorcy</label>
              <input name="fwd_note" class="form-control form-control-sm" placeholder="np. proszę o wycenę do piątku">
            </div>
            <div class="col-md-2 text-end">
              <button class="btn btn-crm-outline btn-sm w-100"><i class="bi bi-send me-1"></i>Przekaż</button>
            </div>
            <div class="col-12 form-text" style="font-size:.72rem">
              Wysyłamy oryginał z cytatem i notatką; wpis o przekazaniu trafia do historii kontaktu.
            </div>
          </form>
        </div>
      <?php $mdl_fwd = ob_get_clean(); ?>
      <?php endif; ?>

    </div>

    <?php /* Sprawy, oferty i wątek trzymamy w prawej szpalcie — przy długiej
             wiadomości sekcje na dole były poza ekranem. <details> zamiast JS-a:
             rozwijanie działa z klawiatury i bez skryptu. */ ?>
    <?php if ($rail_cases || $rail_offers || $rail_thread): ?>
    <div class="ib-rail d-xxl-none mt-3">
      <?php include __DIR__ . '/includes/inbox_rail.php'; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  </div>

  <?php if ($msg && ($rail_cases || $rail_offers || $rail_thread)): ?>
  <!-- ══ PRAWA SZPALTA (od 1400 px) ══════════════════════════════════════ -->
  <div class="ib-rail d-none d-xxl-flex">
    <?php include __DIR__ . '/includes/inbox_rail.php'; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($msg && $can_write): ?>
<?php
  /* Modale akcji — POZA .ib-wrap, bo w kontenerze siatki z overflow Bootstrap
     pozycjonuje je względem rodzica i okno potrafi zostać przycięte. */
  $mdls = [
      'mdCase' => ['Załóż sprawę CRM',  'bi-briefcase-fill',       '#1D4ED8', $mdl_case ?? ''],
      'mdEzd'  => ['Przekaż do EZD',    'bi-folder-symlink-fill',  '#0F766E', $mdl_ezd  ?? ''],
      'mdFwd'  => ['Przekaż e-mailem',  'bi-forward-fill',         '#B45309', $mdl_fwd  ?? ''],
  ];
?>
<?php foreach ($mdls as $mid => [$mtitle, $micon, $mcolor, $mbody]): if ($mbody === '') continue; ?>
<div class="modal fade" id="<?= h($mid) ?>" tabindex="-1" aria-labelledby="<?= h($mid) ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fw-bold" id="<?= h($mid) ?>Label" style="font-size:1rem">
          <i class="bi <?= h($micon) ?> me-2" style="color:<?= h($mcolor) ?>" aria-hidden="true"></i><?= h($mtitle) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pt-2">
        <?php if ($mid !== 'mdEzd'): /* EZD ma własny, duży nagłówek tematu */ ?>
        <div class="text-muted mb-2" style="font-size:.8rem">
          <?= h(mb_strimwidth((string)($msg['subject'] ?: '(bez tematu)'), 0, 90, '…')) ?>
        </div>
        <?php endif; ?>
        <?= $mbody ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<script>
// Kursor w pierwszym polu po otwarciu okna — bez tego trzeba klikać w formularz.
['mdCase','mdEzd','mdFwd'].forEach(function (id) {
  var m = document.getElementById(id);
  if (!m) return;
  m.addEventListener('shown.bs.modal', function () {
    var f = m.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
    if (f) f.focus();
  });
});
</script>
<?php endif; ?>

<script>
// Panel filtrów: kursor w wyszukiwarce po otwarciu, Esc i klik obok zamykają.
(function () {
  var d = document.querySelector('.ib-filter');
  if (!d) return;
  d.addEventListener('toggle', function () {
    if (!d.open) return;
    var f = d.querySelector('input[name=q]');
    if (f) f.focus();
  });
  document.addEventListener('click', function (e) {
    if (d.open && !d.contains(e.target)) d.open = false;
  });
  d.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { d.open = false; d.querySelector('summary').focus(); }
  });
})();

// Masowe porzucanie — checkboxy są poza <form> (form="ibBulkForm"), więc liczymy je sami.
(function () {
  var bar = document.getElementById('ibBulkBar');
  if (!bar) return;
  var all   = document.getElementById('ibCheckAll');
  var btn   = document.getElementById('ibBulkBtn');
  var out   = document.getElementById('ibBulkN');
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.ib-check'));

  var info = document.getElementById('ibBulkInfo');

  function refresh() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    out.textContent = n;
    info.hidden = n === 0;
    btn.disabled = n === 0;
    bar.classList.toggle('is-armed', n > 0);
    boxes.forEach(function (b) { b.closest('.ib-row').classList.toggle('is-checked', b.checked); });
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
  }

  all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    refresh();
  });
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
