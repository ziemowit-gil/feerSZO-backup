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
require_once dirname(__DIR__) . '/includes/crm_sender_trust.php';
require_once dirname(__DIR__) . '/includes/nozbe.php';
require_once dirname(__DIR__) . '/includes/crm_case_extras.php';

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

            case 'attach_case':
                $r = crm_case_attach_message($mid, (int)($_POST['case_id'] ?? 0));
                flash_set(!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok'])
                    ? (!empty($r['detached'])
                        ? 'Wiadomość odpięta od sprawy.'
                        : 'Wiadomość dopięta do sprawy „' . ($r['case']['title'] ?? '') . '".')
                    : ($r['error'] ?: 'Nie udało się dopiąć wiadomości.'));
                break;

            case 'nozbe':
                if (!nozbe_configured()) { flash_set('warning', 'Integracja z Nozbe nie jest skonfigurowana.'); break; }
                $nz = nozbe_push_crm_message($mid);
                if (!empty($nz['ok'])) {
                    flash_set('success', !empty($nz['existing'])
                        ? 'Ta wiadomość jest już w Nozbe.'
                        : 'Zadanie utworzone w Nozbe.');
                } else {
                    flash_set('danger', 'Nozbe: ' . ($nz['error'] ?: 'nie udało się utworzyć zadania.'));
                }
                break;

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
                    flash_set('success', 'Wiadomość przekazana do EZD' . ($r['znak'] ? ' — ' . $r['znak'] : '') . '.'
                        . (!empty($r['notified']) ? ' Nadawca dostał e-mail o zarejestrowaniu sprawy.' : ''));
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

// Autoryzacja nadawców dla całej listy naraz (domena organizacji + adresy z umów)
$trust_map = crm_sender_trust_bulk(array_merge(
    array_column($inbox['rows'], 'from_email'),
    [$msg['from_email'] ?? '']
));
$trust_of  = static function (?string $email) use ($trust_map): array {
    $e = strtolower(trim((string)$email));
    return $trust_map[$e] ?? ['level' => '', 'label' => '', 'title' => '', 'contract' => null];
};

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
/* Wiersz listy: dwie linie zamiast kafla z awatarem — więcej wiadomości na ekran,
   temat i początek treści w jednej linii, stan czytania jako kropka. */
.ib-item { display:flex; flex-direction:column; gap:.1rem; padding:.5rem .75rem;
  text-decoration:none; color:#111827; min-width:0 }
.ib-item:hover { background:#FAFBFC }
.ib-item.active { background:#F2F7FF; box-shadow:inset 3px 0 0 var(--crm-primary) }
.ib-l1 { display:flex; align-items:center; gap:.4rem; min-width:0 }
.ib-l2 { display:flex; align-items:baseline; gap:.4rem; min-width:0 }
.ib-udot { width:7px; height:7px; border-radius:50%; background:transparent; flex-shrink:0 }
.ib-item.unread .ib-udot { background:var(--crm-primary) }
.ib-who { font-size:.78rem; color:#4B5563; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1 }
.ib-item.unread .ib-who { color:#111827; font-weight:600 }
.ib-clip { font-size:.72rem; color:#9CA3AF; flex-shrink:0 }
.ib-time { font-size:.7rem; color:#9CA3AF; white-space:nowrap; flex-shrink:0 }
.ib-subj { font-size:.84rem; color:#111827; white-space:nowrap; flex-shrink:0; max-width:60%;
  overflow:hidden; text-overflow:ellipsis }
.ib-item.unread .ib-subj { font-weight:700 }
.ib-snip { font-size:.76rem; color:#9CA3AF; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1 }
.ib-tags { display:flex; gap:.3rem; margin-top:.2rem; flex-wrap:wrap }
.ib-tag { font-size:.66rem; font-weight:700; letter-spacing:.03em; padding:.05rem .4rem; border-radius:3px }

/* ── Panel wiadomości ──────────────────────────────────────────────────── */
.ib-pane { background:#fff; border:1px solid #E5E7EB; border-radius:12px }
.ib-head { padding:1rem 1.15rem .8rem }
.ib-h1 { font-size:1.08rem; font-weight:700; line-height:1.3; margin:0 0 .3rem }
.ib-meta { font-size:.8rem; color:#6B7280 }
.ib-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:2rem;
  background:#F3F4F6; font-size:.78rem; color:#374151; text-decoration:none }
.ib-chip:hover { background:#E9EDF3; color:#111827 }
.ib-bar { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; padding:.5rem 1.15rem;
  background:#FAFBFC; border-top:1px solid #F1F2F4; border-bottom:1px solid #F1F2F4 }
.ib-bar form { margin:0 }
/* Ten sam język co pasek narzędzi nad listą: 30 px wysokości, 8 px promienia. */
.ib-act { height:30px; display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap;
  padding:0 .65rem; border-radius:8px; border:1px solid #E5E7EB; background:#fff; color:#374151;
  font-size:.78rem; font-weight:500; cursor:pointer; transition:background .12s, border-color .12s, color .12s }
.ib-act:hover { background:#F3F4F6; border-color:#D1D5DB; color:#111827 }
.ib-act:focus-visible { outline:2px solid var(--crm-primary); outline-offset:1px }
.ib-act--primary { border-color:var(--crm-primary); background:var(--crm-primary-bg); color:var(--crm-primary) }
.ib-act--primary:hover { background:#DCEBFA; color:var(--crm-primary) }
.ib-act--danger { color:#B91C1C }
.ib-act--danger:hover { background:#FEF2F2; border-color:#FCA5A5; color:#991B1B }
/* Żadnych pól „jak z przeglądarki" — selecty i inputy w panelu wiadomości
   (przypisanie, formularze w oknach) mają ten sam język co przyciski. */
.ib-bar .ib-field { height:30px }
.ib-pane .form-select, .ib-pane .form-control, .ib-pane .ib-field,
.modal .ib-dbody .form-select, .modal .ib-dbody .form-control {
  height:32px; font-size:.8rem; border:1px solid #E5E7EB; border-radius:8px;
  background-color:#fff; color:#111827; padding:0 .6rem; box-shadow:none }
.ib-pane .form-select:focus, .ib-pane .form-control:focus, .ib-pane .ib-field:focus,
.modal .ib-dbody .form-select:focus, .modal .ib-dbody .form-control:focus {
  border-color:var(--crm-primary); box-shadow:0 0 0 3px rgba(1,118,211,.12); outline:none }
.modal .ib-dbody .input-group > .form-select { border-top-right-radius:0; border-bottom-right-radius:0 }
.modal .ib-dbody .input-group > .btn { border-radius:0 8px 8px 0 }
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
/* Wariant wypełniony — jedyna akcja tworząca coś nowego, ma wygrywać z „Sprawdź teraz”. */
.ib-tbtn--cta { border-color:var(--crm-primary); background:var(--crm-primary); color:#fff; font-weight:600 }
.ib-tbtn--cta:hover { background:#0165B8; border-color:#0165B8; color:#fff }
.ib-tbtn--on { border-color:var(--crm-primary); color:var(--crm-primary) }

/* ── Załączniki ──────────────────────────────────────────────────────────── */
.ib-atts { display:flex; flex-wrap:wrap; gap:.4rem }
.ib-att { display:inline-flex; align-items:center; gap:.4rem; max-width:100%;
  padding:.3rem .6rem; border:1px solid #E5E7EB; border-radius:8px; background:#fff;
  font-size:.8rem; color:#111827; cursor:pointer; text-align:left;
  transition:background .12s, border-color .12s }
.ib-att:hover { background:#F9FAFB; border-color:#D1D5DB }
.ib-att:focus-visible { outline:2px solid var(--crm-primary); outline-offset:1px }
.ib-att--missing { cursor:default; color:#9CA3AF; background:#F9FAFB }
.ib-att--missing:hover { background:#F9FAFB; border-color:#E5E7EB }
.ib-att-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:22rem }
.ib-att-size { font-size:.72rem; color:#9CA3AF; white-space:nowrap }

/* ── Asystent AI ─────────────────────────────────────────────────────────── */
.ib-assist { margin:.6rem 1.15rem 0; border:1px solid #E9D5FF; border-radius:10px;
  background:linear-gradient(180deg,#FAF5FF,#fff); overflow:hidden }
.ib-assist-head { display:flex; align-items:center; gap:.45rem; padding:.5rem .8rem;
  border-bottom:1px solid #F3E8FF; font-size:.85rem; color:#6B21A8 }
.ib-assist-note { font-size:.73rem; color:#9CA3AF; font-weight:400 }
.ib-assist-body { padding:.7rem .8rem }
.ib-assist-row  { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; margin-bottom:.5rem }
.ib-assist-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.15rem .55rem;
  border-radius:2rem; font-size:.74rem; font-weight:700 }
.ib-assist-draft { border:1px solid #E5E7EB; border-radius:8px; background:#fff;
  padding:.6rem .7rem; font-size:.86rem; white-space:pre-wrap; line-height:1.5 }
.ib-assist-lbl { font-size:.68rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
  color:#9CA3AF; margin:.6rem 0 .25rem }

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
/* Skrzynka jako rozwijana lista w pasku narzędzi — pasek zakładek przy kilku
   adresach zajmował cały wiersz i pokazywał poziomy suwak. */
.ib-mboxsel { margin-left:auto }
.ib-mboxsel ~ .ib-filter { margin-left:0 }
.ib-mboxsel > summary .ib-mbox-name { max-width:11rem }
.ib-mbox-list { padding:.35rem; gap:1px; width:min(300px, 90vw) }
.ib-mbox-opt { --c:#9CA3AF; display:flex; align-items:center; gap:.45rem;
  padding:.35rem .5rem; border-radius:7px; font-size:.78rem; color:#374151; text-decoration:none;
  transition:background .12s, color .12s }
.ib-mbox-opt:hover { background:#F3F4F6; color:#111827 }
.ib-mbox-opt:focus-visible { outline:2px solid var(--crm-primary); outline-offset:-2px }
.ib-mbox-opt.is-on { background:#F9FAFB; color:#111827; font-weight:600; box-shadow:inset 2px 0 0 var(--c) }
.ib-mbox-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ib-mbox-n { font-size:.68rem; font-weight:700; color:#6B7280; background:#E5E7EB;
  border-radius:2rem; padding:0 .35rem; min-width:1.3rem; text-align:center; flex-shrink:0 }
.ib-mbox-opt.is-on .ib-mbox-n, .ib-tbtn--on .ib-mbox-n { color:#fff; background:var(--c, var(--crm-primary)) }
.ib-mbox-dot { width:8px; height:8px; border-radius:2px; flex-shrink:0; background:var(--c) }

.ib-row { display:flex; align-items:stretch; border-bottom:1px solid #F3F4F6 }
.ib-row:last-child { border-bottom:none }
.ib-row .ib-item { flex:1; min-width:0; border-bottom:none }
.ib-row.is-checked { background:#FAFAFA }
.ib-check { flex-shrink:0; margin:.75rem .15rem .75rem .55rem; width:.9rem; height:.9rem; cursor:pointer;
  opacity:.4; transition:opacity .12s }
.ib-row:hover .ib-check, .ib-check:checked, .ib-check:focus-visible { opacity:1 }

/* ── Autoryzowany nadawca ───────────────────────────────────────────────── */
/* Podpowiedź dla człowieka, nie zabezpieczenie — From da się podrobić. */
.ib-trust { display:inline-flex; align-items:center; gap:.25rem; flex-shrink:0; font-size:.72rem; line-height:1 }
.ib-trust--int { color:#2E844A }
.ib-trust--doc { color:#1D4ED8 }
.ib-trust--lg { padding:.25rem .6rem; border-radius:2rem; font-size:.75rem; font-weight:600 }
.ib-trust--lg.ib-trust--int { background:#EFF7ED; border:1px solid #CDE8C6 }
.ib-trust--lg.ib-trust--doc { background:#EFF6FF; border:1px solid #BFDBFE }

/* ── Pasek „przyszło coś nowego" ────────────────────────────────────────── */
.ib-new { display:none; align-items:center; gap:.45rem; width:100%; margin-bottom:.5rem;
  padding:.4rem .7rem; border-radius:10px; font-size:.78rem; font-weight:600;
  background:var(--crm-primary-bg); color:var(--crm-primary); border:1px solid #BFDBFE;
  cursor:pointer; text-align:left }
.ib-new.is-on { display:flex }
.ib-new:hover { background:#DCEBFA }

/* ── Numer wiadomości ───────────────────────────────────────────────────── */
/* Na liście numer nie walczy o miejsce z tematem: w rogu siedzi sama ikonka,
   cyfry pokazują się dopiero po najechaniu (i zawsze są w title dla czytników). */
.ib-item { position:relative }
.ib-id { position:absolute; right:.55rem; bottom:.4rem; display:inline-flex; align-items:center; gap:.25rem;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.66rem; font-weight:700;
  color:#C3C8D0; background:transparent; border-radius:4px; padding:.05rem .25rem; pointer-events:none;
  transition:color .12s, background .12s }
.ib-id-no { max-width:0; overflow:hidden; white-space:nowrap; opacity:0; transition:max-width .16s, opacity .12s }
.ib-item .ib-l2 { padding-right:1.8rem }   /* miejsce na ikonkę numeru */
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
    <?php if ($can_write): ?>
    <?php /* Skrzynka miała tylko „Odpowiedz” przy wybranym mailu — nie dało się
             zacząć rozmowy. Kompozytor jest ten sam (crm/compose_modal.php),
             tylko otwierany bez kontaktu: odbiorcę wybiera się w oknie. */ ?>
    <button type="button" class="ib-tbtn ib-tbtn--cta" onclick="openCommModal(0,'email')"
            title="Napisz nową wiadomość do kontaktu z CRM — wyśle się z CRM i zapisze w historii kontaktu">
      <i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz
    </button>
    <?php endif; ?>
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

  <?php if (count($boxes) > 1):
        $mbox_counts = crm_mailbox_counts_by_mailbox($view);
        $mbox_cur    = null;
        foreach ($boxes as $b) { if ((int)$b['id'] === $mbox_f) { $mbox_cur = $b; break; } } ?>
  <?php /* Skrzynka jako rozwijana lista, a nie pasek zakładek — przy kilku
           adresach pasek zjadał cały wiersz i pokazywał poziomy suwak. */ ?>
  <details class="ib-filter ib-mboxsel">
    <summary class="ib-tbtn<?= $mbox_cur ? ' ib-tbtn--on' : '' ?>" role="button"
             aria-label="Skrzynka, na którą wpłynęła wiadomość"
             style="--c:<?= h($mbox_cur ? crm_mailbox_color($mbox_f) : '#6B7280') ?>">
      <?php if ($mbox_cur): ?>
      <span class="ib-mbox-dot" aria-hidden="true"></span>
      <span class="ib-mbox-name"><?= h($mbox_cur['mailbox']) ?></span>
      <?php else: ?>
      <i class="bi bi-collection" aria-hidden="true"></i>Wszystkie skrzynki
      <?php endif; ?>
      <span class="ib-mbox-n"><?= $mbox_cur ? (int)($mbox_counts[$mbox_f] ?? 0) : array_sum($mbox_counts) ?></span>
      <i class="bi bi-chevron-down" style="font-size:.68rem" aria-hidden="true"></i>
    </summary>
    <div class="ib-filter-panel ib-mbox-list">
      <a class="ib-mbox-opt<?= $mbox_f ? '' : ' is-on' ?>" style="--c:#6B7280"
         href="?<?= $qs(['mailbox_id' => null, 'msg' => null, 'page' => null]) ?>"
         <?= $mbox_f ? '' : 'aria-current="true"' ?>>
        <i class="bi bi-collection" aria-hidden="true"></i>
        <span class="ib-mbox-name">Wszystkie skrzynki</span>
        <span class="ib-mbox-n"><?= array_sum($mbox_counts) ?></span>
      </a>
      <?php foreach ($boxes as $b): $bid = (int)$b['id']; $on = $mbox_f === $bid; ?>
      <a class="ib-mbox-opt<?= $on ? ' is-on' : '' ?>" style="--c:<?= h(crm_mailbox_color($bid)) ?>"
         href="?<?= $qs(['mailbox_id' => $bid, 'msg' => null, 'page' => null]) ?>"
         title="<?= h($b['mailbox']) ?>" <?= $on ? 'aria-current="true"' : '' ?>>
        <span class="ib-mbox-dot" aria-hidden="true"></span>
        <span class="ib-mbox-name"><?= h($b['mailbox']) ?></span>
        <span class="ib-mbox-n"><?= (int)($mbox_counts[$bid] ?? 0) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <button type="button" class="ib-tbtn" id="ibSoundBtn" aria-pressed="false"
          title="Sygnał dźwiękowy przy nowej wiadomości">
    <i class="bi bi-bell" id="ibSoundIco" aria-hidden="true"></i><span id="ibSoundTxt">Sygnał</span>
  </button>

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
    <button type="button" class="ib-new" id="ibNewBar" hidden>
      <i class="bi bi-arrow-down-circle-fill" aria-hidden="true"></i>
      <span id="ibNewTxt">Nowe wiadomości</span>
      <span class="ms-auto text-decoration-underline">pokaż</span>
    </button>

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
    <div id="ibListWrap">
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
        <span class="ib-l1">
          <span class="ib-udot" aria-hidden="true" title="<?= $unread ? 'Nieprzeczytana' : '' ?>"></span>
          <span class="ib-who"><?= h($who) ?></span>
          <?= crm_sender_trust_badge($trust_of($r['from_email'] ?? ''), 'sm') ?>
          <?php if ((int)$r['has_attachments']): ?>
          <i class="bi bi-paperclip ib-clip" title="Wiadomość ma załącznik" aria-label="Załącznik"></i>
          <?php endif; ?>
          <span class="ib-time"><?= h(date('H:i', strtotime((string)$r['sent_at']))) ?></span>
        </span>
        <span class="ib-l2">
          <span class="ib-subj"><?= h($r['subject'] ?: '(bez tematu)') ?></span>
          <span class="ib-snip"><?= h(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 120)) ?></span>
        </span>
        <?php if (!empty($r['assigned_name']) || $r['inbox_status'] !== 'active'): ?>
        <span class="ib-tags">
          <?php if (!empty($r['assigned_name'])): ?>
          <span class="ib-tag" style="background:#EEF2FF;color:#4338CA"
                title="Prowadzi: <?= h($r['assigned_name']) ?>"><?= h($r['assigned_name']) ?></span>
          <?php endif; ?>
          <?php if ($r['inbox_status'] === 'archived'): ?>
          <span class="ib-tag" style="background:#EFF7ED;color:#2E844A" title="Sprawa załatwiona">załatwione</span>
          <?php elseif ($r['inbox_status'] === 'spam'): ?>
          <span class="ib-tag" style="background:#FEF2F2;color:#DC2626" title="Oznaczone jako spam">spam</span>
          <?php endif; ?>
        </span>
        <?php endif; ?>
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
    </div><!-- /#ibListWrap -->
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
                    style="--c:<?= h(crm_mailbox_color((int)($msg['mailbox_id'] ?? 0))) ?>"
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
          <?= crm_sender_trust_badge($trust_of($msg['from_email'] ?? ''), 'md') ?>
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
      <?php /* Pasek akcji: każdy przycisk ma widoczną etykietę i podpowiedź mówiącą,
               co się STANIE po kliknięciu — same ikonki (koperta, przekreślone kółko)
               nie mówiły nic. Styl wspólny z paskiem narzędzi nad listą. */ ?>
      <div class="ib-bar">
        <?php if ((int)($msg['assigned_to'] ?? 0) !== $uid): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="assign_me">
          <button class="ib-act ib-act--primary" title="Przypisz tę wiadomość do siebie — trafi do widoku „Przypisane mi”">
            <i class="bi bi-person-check" aria-hidden="true"></i>Wezmę to</button></form>
        <?php endif; ?>

        <?php if (!empty($msg['contact_id'])): ?>
        <button class="ib-act" onclick="openCommModal(<?= (int)$msg['contact_id'] ?>,'email')"
                title="Napisz odpowiedź do nadawcy — wyśle się z CRM i zapisze w historii kontaktu">
          <i class="bi bi-reply" aria-hidden="true"></i>Odpowiedz
        </button>
        <?php endif; ?>

        <?php /* Asystent NIE odpowiada za nas — czyta wiadomość, kwalifikuje ją
                 i przygotowuje PROPOZYCJĘ, którą trzeba przeczytać i poprawić. */ ?>
        <button type="button" class="ib-act" id="ibAssistBtn" data-msg="<?= (int)$msg['id'] ?>"
                title="Kwalifikacja zgłoszenia i propozycja odpowiedzi — do przeczytania przed wysłaniem">
          <i class="bi bi-stars" style="color:#7C3AED" aria-hidden="true"></i>Asystent AI
        </button>

        <?php if ($msg['inbox_status'] === 'active'): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="archive">
          <button class="ib-act" title="Sprawa zamknięta — wiadomość przejdzie do widoku „Załatwione”">
            <i class="bi bi-check2-all" aria-hidden="true"></i>Załatwione</button></form>
        <?php else: ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="restore">
          <button class="ib-act" title="Wróć do obsługi — wiadomość znów pojawi się wśród aktywnych">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Przywróć do obsługi</button></form>
        <?php endif; ?>

        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unread">
          <button class="ib-act" title="Cofnij odczytanie — wiadomość wróci do widoku „Nowe” jako nieprzeczytana">
            <i class="bi bi-envelope" aria-hidden="true"></i>Oznacz jako nieprzeczytaną</button></form>

        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="spam">
          <button class="ib-act" title="Przenieś do widoku „Spam” — zniknie z listy roboczej">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>Spam</button></form>

        <?php if ((int)($msg['crm_hidden'] ?? 0) === 1): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unhide">
          <button class="ib-act" title="Cofnij porzucenie — wiadomość wróci do widoków Skrzynki CRM">
            <i class="bi bi-eye" aria-hidden="true"></i>Przywróć do skrzynki</button></form>
        <?php else: ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="hide">
          <button class="ib-act ib-act--danger"
                  title="<?= empty($msg['assigned_to'])
                      ? 'Nikt tego nie poprowadzi — wiadomość zniknie ze Skrzynki CRM (zostanie w widoku Ukryte; Poczta i EZD bez zmian)'
                      : 'Wiadomość zniknie ze Skrzynki CRM (zostanie w widoku Ukryte; Poczta i EZD bez zmian)' ?>">
            <i class="bi bi-eye-slash" aria-hidden="true"></i>Porzuć</button></form>
        <?php endif; ?>

        <?php if ($can_write): ?>
        <!-- Trzy główne ścieżki obsługi. Formularze żyją w oknach, bo wymagają pól. -->
        <span class="ib-sep" aria-hidden="true"></span>
        <button type="button" class="ib-act" data-bs-toggle="modal" data-bs-target="#mdCase"
                title="Załóż sprawę CRM z tej wiadomości — do prowadzenia tematu handlowego">
          <i class="bi bi-briefcase-fill" style="color:#1D4ED8" aria-hidden="true"></i>Załóż sprawę CRM
        </button>
        <?php if (!empty($msg['contact_id'])):
                $case_list = crm_cases_for_contact((int)$msg['contact_id']);
                $cur_case  = (int)($msg['case_id'] ?? 0); ?>
        <?php if ($case_list): ?>
        <form method="post" class="d-inline-flex align-items-center gap-1"><?= $hidden ?>
          <input type="hidden" name="_op" value="attach_case">
          <select name="case_id" class="ib-field" style="max-width:220px" onchange="this.form.submit()"
                  aria-label="Dopnij wiadomość do sprawy"
                  title="Dopnij tę wiadomość do sprawy — korespondencja będzie widoczna przy sprawie">
            <option value="0">— dopnij do sprawy —</option>
            <?php foreach ($case_list as $cs): ?>
            <option value="<?= (int)$cs['id'] ?>" <?= $cur_case === (int)$cs['id'] ? 'selected' : '' ?>>
              <?= h(mb_strimwidth((string)$cs['title'], 0, 40, '…')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="ib-act" data-bs-toggle="modal" data-bs-target="#mdEzd"
                title="Zarejestruj w EZD — dla korespondencji formalnej (pismo z urzędu, wniosek o umowę)">
          <i class="bi bi-folder-symlink-fill" style="color:#0F766E" aria-hidden="true"></i>Przekaż do EZD
        </button>
        <button type="button" class="ib-act" data-bs-toggle="modal" data-bs-target="#mdFwd"
                title="Wyślij tę wiadomość dalej e-mailem — z cytatem oryginału i notatką">
          <i class="bi bi-forward-fill" style="color:#B45309" aria-hidden="true"></i>Przekaż e-mailem
        </button>
        <?php if (nozbe_configured()): $nz_link = nozbe_link_for('crm_message', (int)$msg['id']); ?>
          <?php if ($nz_link): ?>
          <a class="ib-act" href="<?= h($nz_link['url']) ?>" target="_blank" rel="noopener"
             title="Zadanie z tej wiadomości powstało już w Nozbe — otwórz je">
            <i class="bi bi-check2-square" style="color:#2E844A" aria-hidden="true"></i>Otwórz w Nozbe
          </a>
          <?php else: ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="nozbe">
            <button class="ib-act" title="Utwórz w Nozbe zadanie „odpowiedz na tę wiadomość” — z linkiem do niej i treścią w komentarzu">
              <i class="bi bi-check2-square" style="color:#2E844A" aria-hidden="true"></i>Do Nozbe</button></form>
          <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <form method="post" class="ms-auto"><?= $hidden ?><input type="hidden" name="_op" value="assign">
          <select name="user_id" class="ib-field" style="max-width:210px"
                  aria-label="Przypisz wiadomość innej osobie"
                  title="Wskaż osobę, która poprowadzi tę wiadomość"
                  onchange="this.form.submit()">
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

      <?php if ($can_write): ?>
      <!-- Panel asystenta — pusty do czasu kliknięcia, żeby nie zajmował miejsca -->
      <div class="ib-assist" id="ibAssist" hidden>
        <div class="ib-assist-head">
          <i class="bi bi-stars" aria-hidden="true"></i>
          <strong>Asystent AI</strong>
          <span class="ib-assist-note">propozycja do przeczytania i poprawienia — nic nie zostało wysłane</span>
          <button type="button" class="btn-close btn-sm ms-auto" id="ibAssistClose" aria-label="Zamknij"></button>
        </div>
        <div id="ibAssistBody"></div>
      </div>
      <?php endif; ?>

      <?php if (!empty($msg['attachments'])): ?>
      <?php
        /* Ikona i sposób podglądu wynikają z typu MIME, a gdy go brak — z
           rozszerzenia. Poczta bywa niechlujna: część serwerów wysyła
           application/octet-stream dla wszystkiego, więc oparcie się wyłącznie
           na MIME kończyło się „nie da się pokazać" na zwykłym PDF-ie. */
        $ib_att_kind = function (array $a): string {
            $mime = strtolower((string)($a['mime_type'] ?? ''));
            $ext  = strtolower(pathinfo((string)($a['original_name'] ?? ''), PATHINFO_EXTENSION));
            if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true)) return 'image';
            if ($mime === 'application/pdf' || $ext === 'pdf')                                                            return 'pdf';
            if (str_starts_with($mime, 'text/') || in_array($ext, ['txt','csv','log','md','json','xml'], true))           return 'text';
            return 'other';
        };
        $ib_att_icon = ['image' => 'bi-file-earmark-image', 'pdf' => 'bi-file-earmark-pdf',
                        'text'  => 'bi-file-earmark-text',  'other' => 'bi-file-earmark'];
        $ib_att_color= ['image' => '#7C3AED', 'pdf' => '#B42318', 'text' => '#0F766E', 'other' => '#6B7280'];
      ?>
      <div class="ib-ctx" style="border-top:none">
        <div class="ib-lbl">Załączniki (<?= count($msg['attachments']) ?>)</div>
        <div class="ib-atts">
        <?php foreach ($msg['attachments'] as $a):
          $kind = $ib_att_kind($a);
          $size = (int)($a['size_bytes'] ?? 0);
          $human= $size >= 1048576 ? round($size / 1048576, 1) . ' MB'
                : ($size >= 1024   ? round($size / 1024) . ' kB' : ($size ?: '') . ($size ? ' B' : ''));
          $url  = !empty($a['stored_path']) ? APP_URL . '/uploads/' . ltrim((string)$a['stored_path'], '/') : '';
        ?>
          <?php if ($url): ?>
          <button type="button" class="ib-att" data-att-url="<?= h($url) ?>"
                  data-att-kind="<?= h($kind) ?>" data-att-name="<?= h((string)$a['original_name']) ?>"
                  title="Pokaż podgląd — <?= h((string)$a['original_name']) ?>">
            <i class="bi <?= h($ib_att_icon[$kind]) ?>" style="color:<?= h($ib_att_color[$kind]) ?>" aria-hidden="true"></i>
            <span class="ib-att-name"><?= h((string)$a['original_name']) ?></span>
            <?php if ($human): ?><span class="ib-att-size"><?= h($human) ?></span><?php endif; ?>
          </button>
          <?php else: ?>
          <span class="ib-att ib-att--missing" title="Plik nie został pobrany na serwer — jest tylko w skrzynce pocztowej">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            <span class="ib-att-name"><?= h((string)$a['original_name']) ?></span>
            <span class="ib-att-size">niepobrany</span>
          </span>
          <?php endif; ?>
        <?php endforeach; ?>
        </div>
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
                    Nadawca dostanie e-mail „Informacja o zarejestrowaniu sprawy w systemie EZD FEER"
                    (nie wysyłamy go przy dopinaniu do istniejącej koszulki ani do nadawców automatycznych).
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
/* ── Dynamiczne odświeżanie + sygnał przy nowej wiadomości ──────────────────
   Odpytujemy lekki endpoint (crm/api/inbox_poll.php); pełny HTML listy
   dociągamy dopiero, gdy faktycznie coś przyszło. Otwarta wiadomość zostaje
   nietknięta — podmieniamy wyłącznie lewą kolumnę i liczniki widoków. */
(function () {
  var wrap = document.getElementById('ibListWrap');
  if (!wrap) return;

  var POLL_MS = 30000;
  var API  = <?= json_encode(rtrim(APP_URL, '/') . '/crm/api/inbox_poll.php') ?>;
  var PARAMS = <?= json_encode(['view' => $view, 'mailbox_id' => $mbox_f ?: '', 'q' => $search]) ?>;

  var bar    = document.getElementById('ibNewBar');
  var barTxt = document.getElementById('ibNewTxt');
  var lastId = <?= (int)max(array_merge([0], array_column($inbox['rows'], 'id'))) ?>;
  var busy   = false;

  /* Sygnał dźwiękowy — Web Audio, bez pliku na serwerze. Domyślnie włączony,
     stan pamiętany w przeglądarce. Przeglądarki blokują dźwięk przed pierwszym
     kliknięciem na stronie, więc AudioContext tworzymy leniwie. */
  var soundOn = localStorage.getItem('crmInboxSound') !== '0';
  var actx = null;

  function ding() {
    if (!soundOn) return;
    try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      [880, 1175].forEach(function (freq, i) {
        var o = actx.createOscillator(), g = actx.createGain();
        var t = actx.currentTime + i * 0.13;
        o.type = 'sine'; o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.16, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
        o.connect(g); g.connect(actx.destination);
        o.start(t); o.stop(t + 0.24);
      });
    } catch (e) {}
  }

  var sBtn = document.getElementById('ibSoundBtn');
  function paintSound() {
    if (!sBtn) return;
    sBtn.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
    sBtn.classList.toggle('ib-tbtn--on', soundOn);
    document.getElementById('ibSoundIco').className = soundOn ? 'bi bi-bell-fill' : 'bi bi-bell-slash';
    document.getElementById('ibSoundTxt').textContent = soundOn ? 'Sygnał' : 'Cisza';
    sBtn.title = soundOn
      ? 'Sygnał dźwiękowy przy nowej wiadomości jest włączony — kliknij, aby wyciszyć'
      : 'Sygnał dźwiękowy jest wyciszony — kliknij, aby włączyć';
  }
  if (sBtn) {
    sBtn.addEventListener('click', function () {
      soundOn = !soundOn;
      localStorage.setItem('crmInboxSound', soundOn ? '1' : '0');
      paintSound();
      if (soundOn) ding();               // od razu słychać, co się włączyło
    });
    paintSound();
  }

  /** Podmienia listę świeżym HTML-em tej samej strony. */
  function refreshList(then) {
    if (busy) return;
    busy = true;
    fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc  = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('ibListWrap');
        if (fresh) wrap.innerHTML = fresh.innerHTML;

        // liczniki widoków w pasku
        var oldPills = document.querySelectorAll('.ib-views .ib-pill .ib-cnt');
        var newPills = doc.querySelectorAll('.ib-views .ib-pill .ib-cnt');
        if (oldPills.length === newPills.length) {
          oldPills.forEach(function (el, i) {
            el.textContent = newPills[i].textContent;
            el.setAttribute('style', newPills[i].getAttribute('style') || '');
          });
        }
        busy = false;
        document.dispatchEvent(new CustomEvent('ib:list-refreshed'));
        if (typeof then === 'function') then();
      })
      .catch(function () { busy = false; });
  }

  function poll() {
    var qs = Object.keys(PARAMS)
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(PARAMS[k] || ''); })
        .join('&');
    fetch(API + '?' + qs, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.max_id) return;
        if (lastId && d.max_id > lastId) {
          lastId = d.max_id;
          ding();
          if (document.querySelector('.ib-check:checked')) {
            // Ktoś zaznacza wiadomości — nie wyrywamy mu listy spod kursora
            barTxt.textContent = 'Przyszły nowe wiadomości';
            bar.hidden = false; bar.classList.add('is-on');
          } else {
            refreshList();
          }
        } else if (!lastId) {
          lastId = d.max_id;
        }
      })
      .catch(function () {});
  }

  if (bar) bar.addEventListener('click', function () {
    bar.hidden = true; bar.classList.remove('is-on');
    refreshList();
  });

  // Nie odpytujemy w tle nieaktywnej karty; po powrocie sprawdzamy od razu.
  var timer = setInterval(function () { if (!document.hidden) poll(); }, POLL_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  window.addEventListener('beforeunload', function () { clearInterval(timer); });
})();

// Rozwijane panele paska (skrzynka, filtry): jednocześnie otwarty tylko jeden,
// kursor w wyszukiwarce po otwarciu, Esc i klik obok zamykają.
(function () {
  var panels = Array.prototype.slice.call(document.querySelectorAll('.ib-filter'));
  if (!panels.length) return;

  panels.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      panels.forEach(function (o) { if (o !== d) o.open = false; });
      var f = d.querySelector('input[name=q]');
      if (f) f.focus();
    });
    d.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { d.open = false; d.querySelector('summary').focus(); }
    });
  });

  document.addEventListener('click', function (e) {
    panels.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });
})();

// Masowe porzucanie — checkboxy są poza <form> (form="ibBulkForm"), więc liczymy je sami.
// Lista bywa podmieniana przez odświeżanie w tle, dlatego init jest wywoływalny ponownie.
function ibBulkInit() {
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

  // „Zaznacz widoczne" żyje poza podmienianym fragmentem — podpinamy je raz
  if (!all.dataset.bound) {
    all.dataset.bound = '1';
    all.addEventListener('change', function () {
      document.querySelectorAll('.ib-check').forEach(function (b) { b.checked = all.checked; });
      refresh();
    });
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
}
ibBulkInit();
document.addEventListener('ib:list-refreshed', ibBulkInit);

/* ── Podgląd załączników ────────────────────────────────────────────────────
   Obraz i PDF pokazujemy na miejscu, tekst wczytujemy i wypisujemy jako tekst
   (nie jako HTML — załącznik z poczty to treść z zewnątrz i nie ma prawa
   niczego wykonać). Reszta dostaje uczciwe „tego nie pokażemy" i pobieranie,
   zamiast pustej ramki, po której nie wiadomo, czy to błąd, czy pusty plik. */
(function () {
  var modalEl = document.getElementById('ibAttModal');
  if (!modalEl) return;
  var body  = document.getElementById('ibAttBody');
  var title = document.getElementById('ibAttTitle');
  var dl    = document.getElementById('ibAttDownload');
  var open  = document.getElementById('ibAttOpen');

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function show(url, kind, name) {
    title.textContent = name;
    dl.href   = url;
    dl.setAttribute('download', name);
    open.href = url;

    if (kind === 'image') {
      body.innerHTML = '<img src="' + esc(url) + '" alt="' + esc(name) + '" '
                     + 'style="max-width:100%;max-height:72vh;display:block;margin:0 auto">';
    } else if (kind === 'pdf') {
      body.innerHTML = '<iframe src="' + esc(url) + '" title="' + esc(name) + '" '
                     + 'style="width:100%;height:72vh;border:0"></iframe>';
    } else if (kind === 'text') {
      body.innerHTML = '<div class="text-muted small">Wczytuję…</div>';
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (t) {
          body.innerHTML = '<pre style="max-height:72vh;overflow:auto;white-space:pre-wrap;'
                         + 'font-size:.82rem;margin:0">' + esc(t.slice(0, 200000)) + '</pre>';
        })
        .catch(function () {
          body.innerHTML = '<div class="alert alert-warning mb-0">Nie udało się wczytać pliku.</div>';
        });
    } else {
      body.innerHTML = '<div class="text-center text-muted py-5">'
                     + '<i class="bi bi-file-earmark" style="font-size:2rem" aria-hidden="true"></i>'
                     + '<div class="mt-2">Tego typu pliku nie pokażemy w przeglądarce.</div>'
                     + '<div class="small">Pobierz go albo otwórz w nowej karcie.</div></div>';
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  // Delegacja: lista wiadomości bywa podmieniana bez przeładowania strony.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ib-att[data-att-url]');
    if (!btn) return;
    show(btn.dataset.attUrl, btn.dataset.attKind, btn.dataset.attName || 'załącznik');
  });

  // Zwolnij ramkę po zamknięciu — inaczej PDF zostaje wczytany w tle.
  modalEl.addEventListener('hidden.bs.modal', function () { body.innerHTML = ''; });
})();

/* ── Asystent AI ────────────────────────────────────────────────────────────
   Wynik jest PROPOZYCJĄ. „Użyj tej odpowiedzi" otwiera kompozytor z wklejoną
   treścią — świadomie nie wysyła, bo w imieniu organizacji nie wychodzi nic,
   czego nikt nie przeczytał. */
(function () {
  var btn = document.getElementById('ibAssistBtn');
  if (!btn) return;
  var box  = document.getElementById('ibAssist');
  var body = document.getElementById('ibAssistBody');
  var close= document.getElementById('ibAssistClose');
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var CONTACT = <?= (int)($msg['contact_id'] ?? 0) ?>;
  var cache = null;

  if (close) close.addEventListener('click', function () { box.hidden = true; });

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function render(d) {
    if (!d.ok) {
      body.innerHTML = '<div class="ib-assist-body"><div class="alert alert-warning py-2 mb-0 small">'
                     + esc(d.error || 'Asystent niedostępny.') + '</div></div>';
      return;
    }
    var ic = d.intents || {};
    var html = '<div class="ib-assist-body">';

    html += '<div class="ib-assist-row">'
          + '<span class="ib-assist-chip" style="background:' + esc(ic.color || '#6B7280') + '18;color:'
          + esc(ic.color || '#6B7280') + '"><i class="bi ' + esc(ic.icon || 'bi-chat-dots') + '"></i>'
          + esc(ic.label || d.intent) + '</span>';
    if (d.escalate) {
      html += '<span class="ib-assist-chip" style="background:#FEF2F2;color:#B42318">'
            + '<i class="bi bi-person-raised-hand"></i>do człowieka</span>';
    }
    if (d.sensitive) {
      html += '<span class="ib-assist-chip" style="background:#FEF3C7;color:#92400E">'
            + '<i class="bi bi-shield-exclamation"></i>dane wrażliwe w treści</span>';
    }
    html += '</div>';

    if (d.summary) html += '<div style="font-size:.85rem">' + esc(d.summary) + '</div>';
    if (d.escalate && d.escalate_reason) {
      html += '<div class="text-danger mt-1" style="font-size:.79rem">' + esc(d.escalate_reason) + '</div>';
    }

    if (d.missing && d.missing.length) {
      html += '<div class="ib-assist-lbl">Brakuje, żeby załatwić sprawę</div><ul class="mb-0 ps-3" style="font-size:.82rem">';
      d.missing.forEach(function (m) { html += '<li>' + esc(m) + '</li>'; });
      html += '</ul>';
    }

    if (d.reply) {
      html += '<div class="ib-assist-lbl">Propozycja odpowiedzi</div>'
            + '<div class="ib-assist-draft" id="ibAssistDraft">' + esc(d.reply) + '</div>'
            + '<div class="d-flex flex-wrap gap-1 mt-2">';
      if (CONTACT) {
        html += '<button type="button" class="ib-act" id="ibAssistUse">'
              + '<i class="bi bi-reply-fill" aria-hidden="true"></i>Użyj tej odpowiedzi</button>';
      }
      html += '<button type="button" class="ib-act" id="ibAssistCopy">'
            + '<i class="bi bi-clipboard" aria-hidden="true"></i>Kopiuj</button></div>';
    }

    html += '</div>';
    body.innerHTML = html;

    var use = document.getElementById('ibAssistUse');
    if (use) use.addEventListener('click', function () {
      // Kompozytor ładuje się asynchronicznie — wklejamy, gdy edytor już jest.
      openCommModal(CONTACT, 'email');
      var tries = 0;
      var timer = setInterval(function () {
        var subj = document.getElementById('cm-subject');
        if (subj && window.CM) {
          clearInterval(timer);
          if (!subj.value) subj.value = 'Re: ' + <?= json_encode((string)($msg['subject'] ?? '')) ?>;
          var plain = document.getElementById('cm-plain');
          if (plain) { CM.setMode('plain'); plain.value = d.reply; CM.updateChar(); }
        } else if (++tries > 40) { clearInterval(timer); }
      }, 150);
    });

    var copy = document.getElementById('ibAssistCopy');
    if (copy) copy.addEventListener('click', function () {
      navigator.clipboard && navigator.clipboard.writeText(d.reply);
      copy.innerHTML = '<i class="bi bi-check-lg"></i>Skopiowane';
    });
  }

  btn.addEventListener('click', function () {
    box.hidden = false;
    if (cache) { render(cache); return; }   // druga analiza tej samej wiadomości nic nie wnosi

    body.innerHTML = '<div class="ib-assist-body text-muted small">'
                   + '<span class="spinner-border spinner-border-sm me-2"></span>Czytam wiadomość…</div>';

    fetch('<?= APP_URL ?>/crm/api/inbox_assistant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ _csrf: CSRF, id: parseInt(btn.dataset.msg, 10) }),
    })
    .then(function (r) { return r.json(); })
    .then(function (d) { cache = d; render(d); })
    .catch(function () {
      body.innerHTML = '<div class="ib-assist-body"><div class="alert alert-danger py-2 mb-0 small">'
                     + 'Błąd połączenia z asystentem.</div></div>';
    });
  });
})();
</script>

<!-- ══ PODGLĄD ZAŁĄCZNIKA ════════════════════════════════════════════════════ -->
<div class="modal fade" id="ibAttModal" tabindex="-1" aria-labelledby="ibAttTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title text-truncate" id="ibAttTitle" style="font-size:.95rem"></h5>
        <div class="ms-auto d-flex gap-1 align-items-center">
          <a href="#" id="ibAttOpen" target="_blank" rel="noopener"
             class="btn btn-sm btn-outline-secondary" title="Otwórz w nowej karcie">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
          </a>
          <a href="#" id="ibAttDownload" class="btn btn-sm btn-outline-secondary" title="Pobierz plik">
            <i class="bi bi-download" aria-hidden="true"></i>
          </a>
          <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
      </div>
      <div class="modal-body" id="ibAttBody" style="background:#F9FAFB"></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
