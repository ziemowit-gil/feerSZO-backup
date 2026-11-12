<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

// Masowe przeliczenie tytułów przelewów do aktualnego formatu (Uchwała 5/2026 §2
// pkt 8-9) — tylko dla dokumentów jeszcze niezaakceptowanych (zaakceptowany ma już
// wygenerowany dokument końcowy z kartą akceptacji, w którym tytuł się nie zmienia).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_titles') {
    csrf_check();
    if (!is_admin() && !edok_has_role('ksiegowy')) {
        flash_set('danger', 'Brak uprawnień do aktualizacji tytułów przelewów.');
    } else {
        $rows = db_all("SELECT * FROM edok_documents WHERE status != 'zaakceptowany'");
        $n = 0;
        foreach ($rows as $r) {
            $nowy = edok_generate_tytul_przelewu($r);
            if ($nowy !== $r['tytul_przelewu']) {
                db_exec("UPDATE edok_documents SET tytul_przelewu=?, updated_at=datetime('now') WHERE id=?", [$nowy, $r['id']]);
                edok_log((int)$r['id'], 'edit', '', $r['status'], $r['status'], 'Tytuł przelewu zaktualizowany do aktualnego formatu (Uchwała 5/2026).');
                $n++;
            }
        }
        flash_set('success', $n > 0 ? "Zaktualizowano tytuły przelewów: {$n}." : 'Wszystkie tytuły są już aktualne.');
    }
    header('Location: ' . APP_URL . '/edok/index.php' . (($_GET['status'] ?? '') !== '' ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// Zbiorcze usunięcie dokumentów testowych (numer EODoK-TEST/…, patrz edok/add.php
// checkbox "Dokument testowy") — tylko admin, nie dotyka realnej sekwencji EODoK.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_test_docs') {
    csrf_check();
    if (!is_admin()) {
        flash_set('danger', 'Brak uprawnień do usuwania dokumentów testowych.');
    } else {
        $n = edok_delete_test_documents();
        flash_set('success', $n > 0 ? "Usunięto dokumentów testowych: {$n}." : 'Brak dokumentów testowych do usunięcia.');
    }
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

// Zbiorcze zatwierdzenie operacji banku (opłaty, odsetki z wyciągu): jeden rodzaj działalności, jeden PIN.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bank_quick_all') {
    csrf_check();
    $bq_ids = array_filter(array_map('intval', explode(',', (string)($_POST['doc_ids'] ?? ''))));
    $res = edok_bank_quick_accept_all((int)current_user()['id'], (string)($_POST['bq_rodzaj'] ?? ''), (string)($_POST['bq_projekt'] ?? ''), trim((string)($_POST['bq_pin'] ?? '')), $bq_ids);
    if ($res['error'] !== null) flash_set('danger', $res['error']);
    else {
        $msg = 'Zatwierdzono operacje banku: ' . (count($res['ok']) ? implode(', ', $res['ok']) : 'brak') . '.';
        foreach ($res['stopped'] as $num => $why) $msg .= ' ' . $num . ': ' . $why;
        flash_set($res['stopped'] ? 'warning' : 'success', $msg);
    }
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$filter_status   = $_GET['status'] ?? '';
$filter_kierunek = $_GET['kierunek'] ?? '';
$filter_q        = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filter_status && isset(EDOK_STATUSES[$filter_status])) {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}
if (in_array($filter_kierunek, ['wydatek', 'przychod'], true)) {
    $where[]  = "COALESCE(kierunek,'wydatek') = ?";
    $params[] = $filter_kierunek;
}
// Obieg: E = elektroniczny, P = papierowy.
$filter_obieg = in_array($_GET['c_obieg'] ?? '', ['E', 'P'], true) ? $_GET['c_obieg'] : '';
if ($filter_obieg !== '') {
    $where[] = $filter_obieg === 'P' ? "COALESCE(obieg_papierowy,0) = 1" : "COALESCE(obieg_papierowy,0) = 0";
}
$filter_proformy = !empty($_GET['proformy_bez_faktury']);
if ($filter_proformy) {
    $where[] = "typ_dokumentu = 'proforma' AND status NOT IN ('odrzucony','wycofany')
        AND NOT EXISTS (SELECT 1 FROM edok_documents f WHERE f.proforma_id = edok_documents.id AND f.status NOT IN ('odrzucony','wycofany'))";
}
if ($filter_q !== '') {
    $where[]  = '(number LIKE ? OR title LIKE ? OR kontrahent_nazwa LIKE ? OR nr_faktury LIKE ?)';
    $q = '%' . $filter_q . '%';
    array_push($params, $q, $q, $q, $q);
}

// Filtry w nagłówkach kolumn (c_*): tekst = LIKE, etapy M/F/R/D/Z = decyzja lub „oczekuje", daty = zakres dodania.
$col_text = ['c_numer' => 'number', 'c_kontrahent' => 'kontrahent_nazwa', 'c_tytul' => 'title', 'c_kwota' => 'kwota_brutto'];
$cf = [];
foreach (array_merge(array_keys($col_text), ['c_od', 'c_do'], array_map(fn($k) => 'c_' . $k, array_keys(EDOK_STEPS))) as $k) $cf[$k] = trim((string)($_GET[$k] ?? ''));
foreach ($col_text as $k => $col) {
    if ($cf[$k] !== '') { $where[] = "{$col} LIKE ?"; $params[] = '%' . $cf[$k] . '%'; }
}
// Dokument zaakceptowany bez obiegu etapowego (np. zobowiązanie US „Akceptuję”, akceptacja papierowa) nie ma wpisów
// w edok_steps — jego etapy liczą się jako zatwierdzone, nie jako „oczekuje” (tak też pokazuje je lista i karta).
foreach (array_keys(EDOK_STEPS) as $sk) {
    $v = $cf['c_' . $sk];
    if ($v === 'oczekuje') {
        $where[] = "status <> 'zaakceptowany' AND NOT EXISTS (SELECT 1 FROM edok_steps st WHERE st.doc_id = edok_documents.id AND st.step_key = ? AND st.status IN ('ok','uwagi','odrzucono'))";
        $params[] = $sk;
    } elseif ($v === 'ok') {
        $where[] = "(EXISTS (SELECT 1 FROM edok_steps st WHERE st.doc_id = edok_documents.id AND st.step_key = ? AND st.status = 'ok')
            OR (status = 'zaakceptowany' AND NOT EXISTS (SELECT 1 FROM edok_steps st WHERE st.doc_id = edok_documents.id AND st.step_key = ? AND st.status IN ('ok','uwagi','odrzucono'))))";
        array_push($params, $sk, $sk);
    } elseif (in_array($v, ['uwagi', 'odrzucono'], true)) {
        $where[] = "EXISTS (SELECT 1 FROM edok_steps st WHERE st.doc_id = edok_documents.id AND st.step_key = ? AND st.status = ?)";
        array_push($params, $sk, $v);
    }
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cf['c_od'])) { $where[] = "date(created_at) >= ?"; $params[] = $cf['c_od']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cf['c_do'])) { $where[] = "date(created_at) <= ?"; $params[] = $cf['c_do']; }
$cf_active = count(array_filter($cf, fn($v) => $v !== '')) > 0;

// Stronicowanie: licznik całości przy tych samych filtrach; eksport CSV bierze wszystkie pasujące, nie tylko stronę.
$per_page   = 50;
$is_csv     = ($_GET['export'] ?? '') === 'csv';
$total_docs = (int)(db_one("SELECT COUNT(*) AS n FROM edok_documents WHERE " . implode(' AND ', $where), $params)['n'] ?? 0);
$pages      = max(1, (int)ceil($total_docs / $per_page));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$docs = db_all(
    "SELECT * FROM edok_documents WHERE " . implode(' AND ', $where) . " ORDER BY id DESC"
    . ($is_csv ? '' : " LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page)),
    $params
);
$page_url = fn(int $n): string => APP_URL . '/edok/index.php?' . http_build_query(array_merge(array_diff_key($_GET, ['export' => 1]), ['page' => $n]));
foreach ($docs as &$d) {
    $d['steps'] = [];
    $d['step_meta'] = [];
    foreach (db_all("SELECT step_key, status, user_name, decided_at FROM edok_steps WHERE doc_id = ?", [$d['id']]) as $s) {
        $d['steps'][$s['step_key']] = $s['status'];
        $d['step_meta'][$s['step_key']] = $s;
    }
}
unset($d);
// Proformy z listy, które mają już fakturę końcową (znaczek „proforma ✓”).
$proforma_z_faktura = [];
$pf_ids = array_column(array_filter($docs, fn($d) => $d['typ_dokumentu'] === 'proforma'), 'id');
if ($pf_ids) {
    $ph = implode(',', array_fill(0, count($pf_ids), '?'));
    foreach (db_all("SELECT DISTINCT proforma_id FROM edok_documents WHERE proforma_id IN ($ph) AND status NOT IN ('odrzucony','wycofany')", $pf_ids) as $r) {
        $proforma_z_faktura[(int)$r['proforma_id']] = true;
    }
}

// Zaznaczanie + eksport przelewów z listy — ten sam handler co w Preliminarzu
// (POST export_przelewy na edok/preliminarz.php: potwierdzenie NIP/NRB, pliki ELIXIR-O).
$przelew_rachunki = edok_rachunki_list();
$can_export = $przelew_rachunki && (is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza')));
$bank_pin_ok = edok_pin_is_set((int)current_user()['id']);   // zaznaczanie z listy do „Zatwierdź operacje bankowe”
// Akcje zbiorcze (modules/edok_bulk): jedno pole wyboru w każdym wierszu; eksport przelewów i operacje banku
// biorą z zaznaczenia tylko pasujące dokumenty (data-export / data-bank).
require_once __DIR__ . '/../modules/edok_bulk/logic/edokBulk.php';
$can_pay = edokBulkCanPay();

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_dokumenty_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numer', 'Kierunek', 'Kontrahent', 'NIP', 'Tytuł', 'Netto', 'VAT', 'Brutto', 'Waluta', 'Status', 'Klasyfikacja', 'Dodano'], ';', '"', '\\');
    foreach ($docs as $d) {
        fputcsv($out, [
            $d['number'], ($d['kierunek'] ?? 'wydatek') === 'przychod' ? 'Przychód' : 'Wydatek', $d['kontrahent_nazwa'], $d['kontrahent_nip'], $d['title'],
            $d['kwota_netto'], $d['kwota_vat'], $d['kwota_brutto'], $d['waluta'] ?: 'PLN',
            EDOK_STATUSES[$d['status']]['label'] ?? $d['status'],
            edok_transfer_label_klasyfikacja($d['rodzaj_dzialalnosci'], $d['projekt']),
            $d['created_at'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// Liczniki do kafli i zakładek statusów (całość, niezależnie od filtrów listy).
$status_counts = array_fill_keys(array_keys(EDOK_STATUSES), 0);
foreach (db_all("SELECT status, COUNT(*) AS n FROM edok_documents GROUP BY status") as $r) {
    if (isset($status_counts[$r['status']])) $status_counts[$r['status']] = (int)$r['n'];
}
$do_zaplaty = (int)(db_one("SELECT COUNT(*) AS n FROM edok_documents WHERE status = 'zaakceptowany' AND COALESCE(kierunek,'wydatek') = 'wydatek'
    AND COALESCE(status_platnosci,'nowy') NOT IN ('oplacony','anulowany') AND COALESCE(zaplacono_przed,0) = 0")['n'] ?? 0);
// Adres listy z bieżącymi filtrami, z podmienionymi parametrami ('' usuwa parametr); zawsze od 1. strony.
$list_url = function (array $set): string {
    $q = array_diff_key($_GET, ['export' => 1, 'page' => 1]);
    foreach ($set as $k => $v) { if ($v === '') unset($q[$k]); else $q[$k] = $v; }
    return APP_URL . '/edok/index.php' . ($q ? '?' . http_build_query($q) : '');
};
$step_abbr  = ['meryt' => 'M', 'formal' => 'F', 'rachunkowa' => 'R', 'dekretacja' => 'D', 'zatwierdza' => 'Z'];
$step_short = ['meryt' => 'merytoryczny', 'formal' => 'formalny', 'rachunkowa' => 'rachunkowy', 'dekretacja' => 'dekretacja', 'zatwierdza' => 'zatwierdzenie'];
$status_short = ['draft' => 'Projekt', 'w_obiegu' => 'W obiegu', 'zaakceptowany' => 'Zaakceptowany', 'odrzucony' => 'Odrzucony', 'wycofany' => 'Wycofany'];
$tab_labels   = ['' => 'Wszystkie', 'draft' => 'Projekty', 'w_obiegu' => 'W obiegu', 'zaakceptowany' => 'Zaakceptowane', 'odrzucony' => 'Odrzucone', 'wycofany' => 'Wycofane'];
$adv_active   = $cf_active || $filter_obieg !== '';
$filters_any  = $filter_status !== '' || $filter_kierunek !== '' || $filter_q !== '' || $filter_proformy || $adv_active;

$PAGE_TITLE = 'EODoK — Elektroniczny Obieg Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';

$can_upload = is_admin() || edok_has_role('upload');
$can_fin    = is_admin() || edok_has_role('zatwierdza') || edok_has_role('ksiegowy');
$can_ksieg  = is_admin() || edok_has_role('ksiegowy');
$pending_count = array_sum(array_map('count', edok_pending_for_user((int)current_user()['id'])));
if ($can_upload) { require_once __DIR__ . '/../includes/edok_bank.php'; $bank_open = edok_bank_unassigned_count(); $queue_count = edok_queue_count(); } else { $bank_open = 0; $queue_count = 0; }
$ksef_on = org_setting('kdok_ksef_enabled') === '1';
$bank_docs_pending = $bank_pin_ok ? edok_bank_docs_pending() : [];
?>
<style type="text/tailwindcss">
/* Lista EODoK — styl Metro jak karta dokumentu (edok/view.php): płasko, akcent #0072c6, kolory stanów z kontrastem ≥ 4.5:1.
   Bez preflightu: każde tw-border* ma tw-border-solid. Zaczepy skryptów (.edok-sel, #edokSelAll, #edokExportForm, #bankQuick*) bez zmian. */
.edl-page { font-family: 'Segoe UI', 'Segoe UI Web', system-ui, sans-serif; }
.edl-page .btn, .edl-page .form-control, .edl-page .form-select, .edl-page .dropdown-menu, .edl-page .page-link { border-radius: 0; }
.edl-page .btn-primary { background-color: #0072c6; border-color: #0072c6; }
.edl-page .btn-primary:hover, .edl-page .btn-primary:focus { background-color: #005a9e; border-color: #005a9e; }
.edl-page :focus-visible { outline: 3px solid #1d1d1d; outline-offset: 2px; }

.edl-head { @apply tw-flex tw-items-end tw-justify-between tw-flex-wrap tw-gap-3 tw-mb-4; }
.edl-title { @apply tw-m-0 tw-text-3xl tw-font-light tw-text-neutral-900 tw-leading-tight; }
.edl-subtitle { @apply tw-block tw-text-sm tw-font-normal tw-text-neutral-600 tw-mt-1; }
.edl-actions { @apply tw-flex tw-items-center tw-gap-2 tw-flex-wrap; }

.edl-tiles { @apply tw-list-none tw-p-0 tw-m-0 tw-mb-4 tw-grid tw-gap-1 tw-grid-cols-2 md:tw-grid-cols-3 xl:tw-grid-cols-5; }
.edl-tile { @apply tw-relative tw-flex tw-flex-col tw-justify-between tw-h-full tw-min-h-[6.5rem] tw-p-3 tw-no-underline tw-bg-neutral-200 tw-text-neutral-900 hover:tw-bg-neutral-300 hover:tw-text-neutral-900; }
.edl-tile__n { @apply tw-text-4xl tw-font-light tw-leading-none; }
.edl-tile__l { @apply tw-text-sm tw-font-semibold tw-leading-snug tw-mt-2 tw-pr-6; }
.edl-tile__i { @apply tw-absolute tw-top-3 tw-right-3 tw-text-xl; }
.edl-tile--accent { @apply tw-bg-[#0072c6] tw-text-white hover:tw-bg-[#005a9e] hover:tw-text-white; }
.edl-tile--warn   { @apply tw-bg-[#b45f06] tw-text-white hover:tw-bg-[#8f4a05] hover:tw-text-white; }
.edl-tile--ok     { @apply tw-bg-[#128a3f] tw-text-white hover:tw-bg-[#0d6b30] hover:tw-text-white; }

.edl-tabs { @apply tw-flex tw-flex-wrap tw-mb-3 tw-border-0 tw-border-b-2 tw-border-solid tw-border-neutral-300; }
.edl-tab { @apply tw-inline-flex tw-items-center tw-gap-2 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold tw-text-neutral-700 tw-no-underline tw-border-0 tw-border-b-4 tw-border-solid tw-border-transparent hover:tw-text-neutral-900 hover:tw-border-neutral-400; margin-bottom: -2px; }
.edl-tab[aria-current="page"] { @apply tw-text-[#0072c6] tw-border-[#0072c6]; }
.edl-tab__n { @apply tw-text-xs tw-font-semibold tw-bg-neutral-200 tw-text-neutral-800 tw-px-1.5 tw-py-0.5; }
.edl-tab[aria-current="page"] .edl-tab__n { @apply tw-bg-[#0072c6] tw-text-white; }

.edl-filters { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-mb-3; }
.edl-search { @apply tw-relative tw-flex-1 tw-min-w-[16rem] tw-max-w-xl; }
.edl-search > .bi { @apply tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-neutral-600 tw-pointer-events-none; }
.edl-search input { @apply tw-pl-9; }
.edl-seg { @apply tw-inline-flex tw-border tw-border-solid tw-border-neutral-400 tw-bg-white; }
.edl-seg a { @apply tw-px-3 tw-py-1.5 tw-text-sm tw-text-neutral-800 tw-no-underline hover:tw-bg-neutral-200; }
.edl-seg a + a { @apply tw-border-0 tw-border-l tw-border-solid tw-border-neutral-400; }
.edl-seg a[aria-current="true"] { @apply tw-bg-neutral-900 tw-text-white; }
.edl-adv { @apply tw-bg-neutral-100 tw-border-0 tw-border-l-[6px] tw-border-solid tw-border-[#0072c6] tw-mb-3; }
.edl-adv > summary { @apply tw-cursor-pointer tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-text-neutral-900; }
.edl-adv__grid { @apply tw-grid tw-gap-3 tw-px-4 tw-pb-4 tw-pt-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 xl:tw-grid-cols-6; }
.edl-adv__grid label { @apply tw-block tw-text-xs tw-font-semibold tw-text-neutral-700 tw-mb-1; }

.edl-meta { @apply tw-flex tw-justify-between tw-items-center tw-flex-wrap tw-gap-2 tw-mb-2 tw-text-sm tw-text-neutral-600; }
.edl-exportbar { @apply tw-items-center tw-gap-2 tw-mb-2 tw-flex-wrap tw-px-3 tw-py-2 tw-bg-[#e6f1fa] tw-border-0 tw-border-l-[6px] tw-border-solid tw-border-[#0072c6]; }

.edl-tablewrap { @apply tw-overflow-x-auto tw-bg-white tw-border tw-border-solid tw-border-neutral-300; }
.edl-table { @apply tw-w-full tw-text-sm tw-border-collapse; }
.edl-table thead th { @apply tw-bg-neutral-100 tw-text-neutral-700 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-px-3 tw-py-2 tw-text-left tw-whitespace-nowrap tw-border-0 tw-border-b-2 tw-border-solid tw-border-neutral-300; }
.edl-table td { @apply tw-px-3 tw-py-2.5 tw-align-middle tw-border-0 tw-border-b tw-border-solid tw-border-neutral-200; }
.edl-table tbody tr:hover td { @apply tw-bg-[#f3f8fc]; }
.edl-table tbody tr:last-child td { @apply tw-border-b-0; }
.edl-num { @apply tw-font-mono tw-font-semibold tw-text-[#0072c6] tw-no-underline tw-whitespace-nowrap hover:tw-underline; }
.edl-tags { @apply tw-flex tw-flex-wrap tw-gap-1 tw-mt-1; }
.edl-pill { @apply tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-text-[11px] tw-font-semibold tw-leading-tight tw-whitespace-nowrap; }
.edl-pill--out  { @apply tw-bg-neutral-200 tw-text-neutral-800; }
.edl-pill--in   { @apply tw-bg-[#dcebf7] tw-text-[#00497f]; }
.edl-pill--warn { @apply tw-bg-[#fbe9d7] tw-text-[#7a3f03]; }
.edl-pill--ok   { @apply tw-bg-[#dcf1e3] tw-text-[#0b5c2a]; }
.edl-pill--info { @apply tw-bg-[#e6eefb] tw-text-[#1f3f7a]; }
.edl-party { @apply tw-block tw-font-medium tw-text-neutral-900 tw-leading-snug; }
.edl-sub { @apply tw-block tw-text-xs tw-text-neutral-600 tw-mt-0.5; }
.edl-titlecell { @apply tw-text-neutral-800 tw-leading-snug tw-line-clamp-2; }
.edl-amount { @apply tw-text-right tw-whitespace-nowrap tw-tabular-nums; }
.edl-amount b { @apply tw-font-semibold tw-text-neutral-900; }
.edl-amount small { @apply tw-text-xs tw-text-neutral-600 tw-ml-1; }
.edl-status { @apply tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold tw-whitespace-nowrap; }
.edl-status--draft         { @apply tw-bg-neutral-300 tw-text-neutral-900; }
.edl-status--w_obiegu      { @apply tw-bg-[#b45f06] tw-text-white; }
.edl-status--zaakceptowany { @apply tw-bg-[#128a3f] tw-text-white; }
.edl-status--odrzucony     { @apply tw-bg-[#c4262e] tw-text-white; }
.edl-status--wycofany      { @apply tw-bg-neutral-900 tw-text-white; }
.edl-steps { @apply tw-inline-flex tw-gap-0.5; }
.edl-step { @apply tw-inline-flex tw-items-center tw-justify-center tw-w-6 tw-h-6 tw-text-[11px] tw-font-bold tw-bg-white tw-text-neutral-600 tw-border tw-border-solid tw-border-neutral-400; }
.edl-step.is-ok    { @apply tw-bg-[#128a3f] tw-border-[#128a3f] tw-text-white; }
.edl-step.is-uwagi { @apply tw-bg-[#b45f06] tw-border-[#b45f06] tw-text-white; }
.edl-step.is-rej   { @apply tw-bg-[#c4262e] tw-border-[#c4262e] tw-text-white; }
.edl-step.is-next  { @apply tw-border-2 tw-border-[#0072c6] tw-text-[#0072c6]; }
.edl-paper { @apply tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold tw-bg-white tw-text-neutral-800 tw-border tw-border-solid tw-border-neutral-400 tw-whitespace-nowrap; }
.edl-open { @apply tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-text-[#0072c6] tw-no-underline tw-border-2 tw-border-solid tw-border-[#0072c6] hover:tw-bg-[#0072c6] hover:tw-text-white; }
.edl-legend { @apply tw-flex tw-flex-wrap tw-gap-x-4 tw-gap-y-1 tw-text-xs tw-text-neutral-600 tw-mt-2 tw-mb-0; }
.edl-empty { @apply tw-text-center tw-text-neutral-600 tw-py-10; }
</style>

<div class="edl-page">
<div class="edl-head">
  <h1 class="edl-title">EODoK <span class="edl-subtitle">Elektroniczny Obieg Dokumentów Księgowych</span></h1>
  <div class="edl-actions">
    <?php if ($bank_pin_ok): ?>
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#bankQuickModal" id="bankQuickBtn" title="Rozpoznane operacje banku w obiegu albo dokumenty zaznaczone na liście">
      <i class="bi bi-bank" aria-hidden="true"></i> Zatwierdź operacje bankowe <span class="badge bg-dark ms-1" id="bankQuickCount"><?= count($bank_docs_pending) ?></span>
    </button>
    <?php endif; ?>

    <?php if ($can_upload): ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Import</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/mailbox.php"><i class="bi bi-envelope-paper" aria-hidden="true"></i> Skrzynka faktur</a></li>
        <?php if ($ksef_on): ?>
        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#salesSyncModal"><i class="bi bi-cloud-download" aria-hidden="true"></i> Pobierz sprzedaż z KSeF</button></li>
        <?php endif; ?>
        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#salesXmlModal"><i class="bi bi-file-earmark-code" aria-hidden="true"></i> Import sprzedaży z plików XML</button></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/ksef_sync.php"><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Synchronizacja KSeF (zakupy)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/mt940_import.php"><i class="bi bi-upload" aria-hidden="true"></i> Import wyciągu (MT940)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/szablony.php"><i class="bi bi-file-earmark-richtext" aria-hidden="true"></i> Szablony</a></li>
      </ul>
    </div>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-bank2" aria-hidden="true"></i> Bank i płatności<?php if ($bank_open): ?> <span class="badge bg-warning text-dark"><?= $bank_open ?></span><?php endif; ?></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($can_upload): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/wyciag.php"><i class="bi bi-bank2" aria-hidden="true"></i> Wyciągi bankowe<?php if ($bank_open): ?> <span class="badge bg-warning text-dark"><?= $bank_open ?></span><?php endif; ?></a></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/preliminarz.php"><i class="bi bi-calendar-check" aria-hidden="true"></i> Preliminarz płatności</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/transfers.php"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Przelewy własne fundacji</a></li>
        <?php if ($can_fin): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/zaplacone_przed.php"><i class="bi bi-cash-coin" aria-hidden="true"></i> Zapłacone przed akceptacją</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Raporty i eksport</button>
      <ul class="dropdown-menu dropdown-menu-end" style="min-width:19rem">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/index.php?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Eksport listy (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/dla_ksiegowego.php"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Dla księgowego (PDF/Excel)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/raport_analityczny.php"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> Tabela analityczna</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/archiwum.php"><i class="bi bi-archive" aria-hidden="true"></i> Archiwum miesięczne</a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <form method="get" action="<?= APP_URL ?>/edok/monthly_pdf.php" class="px-3 py-1">
            <?php $months_pl = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień']; ?>
            <div class="small fw-semibold mb-1" id="edl_mpdf">PDF ze wszystkimi dokumentami z miesiąca</div>
            <div class="d-flex gap-1" role="group" aria-labelledby="edl_mpdf">
              <select name="miesiac" class="form-select form-select-sm" aria-label="Miesiąc">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == (int)date('n') ? 'selected' : '' ?>><?= h($months_pl[$m]) ?></option>
                <?php endfor; ?>
              </select>
              <select name="rok" class="form-select form-select-sm" style="max-width:6rem" aria-label="Rok">
                <?php for ($r = (int)date('Y'); $r >= (int)date('Y') - 5; $r--): ?>
                <option value="<?= $r ?>" <?= $r == (int)date('Y') ? 'selected' : '' ?>><?= $r ?></option>
                <?php endfor; ?>
              </select>
              <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</button>
            </div>
          </form>
        </li>
      </ul>
    </div>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Więcej"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/ustaw_pin.php"><i class="bi bi-shield-lock" aria-hidden="true"></i> Twój PIN</a></li>
        <?php if ($can_ksieg): ?>
        <li><form method="post" onsubmit="return confirm('Przeliczyć tytuły przelewów wszystkich niezaakceptowanych dokumentów do aktualnego formatu?');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="update_titles">
          <button type="submit" class="dropdown-item"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Aktualizuj tytuły przelewów</button></form></li>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" onsubmit="return confirm('Usunąć wszystkie dokumenty testowe (numer EODoK-TEST/…) wraz z etapami, audytem i wygenerowanymi PDF-ami? Tej operacji nie można cofnąć.');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_test_docs">
          <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3" aria-hidden="true"></i> Usuń dokumenty testowe</button></form></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if ($can_upload): ?>
    <a href="<?= APP_URL ?>/edok/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy dokument</a>
    <?php endif; ?>
  </div>
</div>

<ul class="edl-tiles" aria-label="Podsumowanie obiegu">
  <li><a class="edl-tile<?= $pending_count ? ' edl-tile--accent' : '' ?>" href="<?= APP_URL ?>/edok/pending.php">
    <i class="bi bi-check2-all edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $pending_count ?></span><span class="edl-tile__l">Czeka na Twoją akceptację</span></a></li>
  <li><a class="edl-tile" href="<?= APP_URL ?>/edok/index.php?status=w_obiegu">
    <i class="bi bi-arrow-repeat edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $status_counts['w_obiegu'] ?></span><span class="edl-tile__l">Dokumenty w obiegu</span></a></li>
  <li><a class="edl-tile<?= $do_zaplaty ? ' edl-tile--ok' : '' ?>" href="<?= APP_URL ?>/edok/preliminarz.php">
    <i class="bi bi-calendar-check edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $do_zaplaty ?></span><span class="edl-tile__l">Zaakceptowane, do zapłaty</span></a></li>
  <?php if ($can_upload): ?>
  <li><a class="edl-tile<?= $queue_count ? ' edl-tile--warn' : '' ?>" href="<?= APP_URL ?>/edok/queue.php">
    <i class="bi bi-inboxes edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $queue_count ?></span><span class="edl-tile__l">Kolejka do opisu</span></a></li>
  <li><a class="edl-tile<?= $bank_open ? ' edl-tile--warn' : '' ?>" href="<?= APP_URL ?>/edok/wyciag.php">
    <i class="bi bi-bank2 edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $bank_open ?></span><span class="edl-tile__l">Operacje z wyciągu bez dokumentu</span></a></li>
  <?php else: ?>
  <li><a class="edl-tile" href="<?= APP_URL ?>/edok/index.php?status=draft">
    <i class="bi bi-pencil edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $status_counts['draft'] ?></span><span class="edl-tile__l">Projekty</span></a></li>
  <li><a class="edl-tile" href="<?= APP_URL ?>/edok/index.php?status=odrzucony">
    <i class="bi bi-x-circle edl-tile__i" aria-hidden="true"></i><span class="edl-tile__n"><?= $status_counts['odrzucony'] ?></span><span class="edl-tile__l">Odrzucone</span></a></li>
  <?php endif; ?>
</ul>

<nav aria-label="Status dokumentów">
  <div class="edl-tabs">
    <?php foreach ($tab_labels as $k => $lbl): $n_ = $k === '' ? array_sum($status_counts) : $status_counts[$k]; ?>
    <a class="edl-tab" href="<?= h($list_url(['status' => $k])) ?>"<?= $filter_status === $k ? ' aria-current="page"' : '' ?>><?= h($lbl) ?> <span class="edl-tab__n"><?= $n_ ?></span></a>
    <?php endforeach; ?>
  </div>
</nav>

<form id="colFilters" method="get" action="<?= APP_URL ?>/edok/index.php" role="search" aria-label="Filtrowanie dokumentów">
  <?php if ($filter_status !== '' && isset(EDOK_STATUSES[$filter_status])): ?><input type="hidden" name="status" value="<?= h($filter_status) ?>"><?php endif; ?>
  <?php if ($filter_kierunek !== ''): ?><input type="hidden" name="kierunek" value="<?= h($filter_kierunek) ?>"><?php endif; ?>
  <div class="edl-filters">
    <div class="edl-search">
      <i class="bi bi-search" aria-hidden="true"></i>
      <label for="edl_q" class="visually-hidden">Szukaj dokumentów</label>
      <input type="search" id="edl_q" name="q" value="<?= h($filter_q) ?>" class="form-control" placeholder="Szukaj: numer, kontrahent, tytuł, nr faktury…">
    </div>
    <button type="submit" class="btn btn-primary">Szukaj</button>
    <div class="edl-seg" role="group" aria-label="Kierunek">
      <?php foreach (['' => 'Wszystkie', 'wydatek' => 'Wydatki', 'przychod' => 'Przychody'] as $k => $lbl): ?>
      <a href="<?= h($list_url(['kierunek' => $k])) ?>"<?= $filter_kierunek === $k ? ' aria-current="true"' : '' ?>><?= h($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="form-check mb-0">
      <input type="checkbox" class="form-check-input" name="proformy_bez_faktury" id="f_proformy" value="1" <?= $filter_proformy ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="f_proformy">Proformy bez faktury końcowej</label>
    </div>
    <?php if ($filters_any): ?><a href="<?= APP_URL ?>/edok/index.php" class="btn btn-link btn-sm"><i class="bi bi-x-lg" aria-hidden="true"></i> Wyczyść filtry</a><?php endif; ?>
  </div>
  <details class="edl-adv"<?= $adv_active ? ' open' : '' ?>>
    <summary>Filtry szczegółowe<?= $adv_active ? ' (włączone)' : '' ?></summary>
    <div class="edl-adv__grid">
      <div><label for="f_numer">Numer</label><input id="f_numer" name="c_numer" value="<?= h($cf['c_numer']) ?>" class="form-control form-control-sm" placeholder="np. 0041"></div>
      <div><label for="f_kontr">Kontrahent</label><input id="f_kontr" name="c_kontrahent" value="<?= h($cf['c_kontrahent']) ?>" class="form-control form-control-sm"></div>
      <div><label for="f_tytul">Tytuł</label><input id="f_tytul" name="c_tytul" value="<?= h($cf['c_tytul']) ?>" class="form-control form-control-sm"></div>
      <div><label for="f_kwota">Kwota brutto</label><input id="f_kwota" name="c_kwota" value="<?= h($cf['c_kwota']) ?>" class="form-control form-control-sm" placeholder="np. 220" inputmode="decimal"></div>
      <div><label for="f_od">Dodano od</label><input id="f_od" type="date" name="c_od" value="<?= h($cf['c_od']) ?>" class="form-control form-control-sm"></div>
      <div><label for="f_do">Dodano do</label><input id="f_do" type="date" name="c_do" value="<?= h($cf['c_do']) ?>" class="form-control form-control-sm"></div>
      <div><label for="f_obieg">Obieg</label><select id="f_obieg" name="c_obieg" class="form-select form-select-sm">
        <option value="">Każdy</option><option value="E"<?= $filter_obieg === 'E' ? ' selected' : '' ?>>Elektroniczny (E)</option><option value="P"<?= $filter_obieg === 'P' ? ' selected' : '' ?>>Papierowy (P)</option></select></div>
      <?php foreach (array_keys(EDOK_STEPS) as $sk): $cv = $cf['c_' . $sk]; ?>
      <div><label for="f_<?= h($sk) ?>">Etap <?= h($step_abbr[$sk]) ?> — <?= h($step_short[$sk]) ?></label><select id="f_<?= h($sk) ?>" name="c_<?= h($sk) ?>" class="form-select form-select-sm">
        <option value="">Dowolny</option><option value="ok"<?= $cv === 'ok' ? ' selected' : '' ?>>✓ zatwierdzone</option><option value="uwagi"<?= $cv === 'uwagi' ? ' selected' : '' ?>>! z uwagami</option>
        <option value="odrzucono"<?= $cv === 'odrzucono' ? ' selected' : '' ?>>✗ odrzucone</option><option value="oczekuje"<?= $cv === 'oczekuje' ? ' selected' : '' ?>>○ oczekuje</option></select></div>
      <?php endforeach; ?>
      <div class="tw-flex tw-items-end"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel" aria-hidden="true"></i> Zastosuj</button></div>
    </div>
  </details>
</form>

<?php if ($can_export): ?>
<form method="post" action="<?= APP_URL ?>/edok/preliminarz.php" id="edokExportForm" class="d-none edl-exportbar">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="export_przelewy">
  <input type="hidden" name="pakiet_ids" id="edokExportIds">
  <input type="hidden" name="rachunek_map" value="{}">
  <span class="small"><strong id="edokExportCount">0</strong> do eksportu przelewów</span>
  <select name="rachunek_zlecen" class="form-select form-select-sm" style="width:auto" aria-label="Rachunek, z którego mają pójść przelewy" required>
    <?php foreach ($przelew_rachunki as $r): ?>
    <option value="<?= h($r['nrb']) ?>"><?= h(edok_rachunek_etykieta($r)) ?> (…<?= h(substr(preg_replace('/\D/', '', $r['nrb']), -4)) ?>)</option>
    <?php endforeach; ?>
  </select>
  <select name="format" class="form-select form-select-sm" style="width:auto" aria-label="Format pliku przelewów">
    <?php foreach (EDOK_PRZELEWY_FORMATY as $k => $label): ?>
    <option value="<?= h($k) ?>"><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-bank" aria-hidden="true"></i> Eksportuj przelewy</button>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../modules/edok_bulk/view/result.php'; ?>

<?php $from_n = $total_docs ? ($page - 1) * $per_page + 1 : 0; $to_n = min($page * $per_page, $total_docs); ?>
<div class="edl-meta">
  <span role="status">Dokumenty <strong><?= $from_n ?>–<?= $to_n ?></strong> z <strong><?= $total_docs ?></strong><?= $filters_any ? ' (po filtrach)' : '' ?></span>
  <span>Zaznacz dokumenty, żeby użyć akcji zbiorczych (pasek na dole)<?= $can_export ? ' lub wyeksportować przelewy zaakceptowanych, nieopłaconych wydatków' : '' ?>.<?php if ($pages > 1): ?> · Strona <?= $page ?> z <?= $pages ?><?php endif; ?></span>
</div>

<div class="edl-tablewrap">
  <table class="edl-table">
    <caption class="visually-hidden">Dokumenty EODoK</caption>
    <thead>
      <tr>
        <th scope="col" style="width:2.25rem"><input type="checkbox" class="form-check-input" id="edokSelAll" title="Zaznacz wszystkie na stronie" aria-label="Zaznacz wszystkie dokumenty na stronie"></th>
        <th scope="col">Numer</th>
        <th scope="col">Kontrahent</th>
        <th scope="col">Tytuł</th>
        <th scope="col" class="tw-text-right">Kwota brutto</th>
        <th scope="col">Status</th>
        <th scope="col" title="Etapy akceptacji: M — merytoryczny, F — formalny, R — rachunkowy, D — dekretacja, Z — zatwierdzenie">Etapy M·F·R·D·Z</th>
        <th scope="col">Dodano</th>
        <th scope="col"><span class="visually-hidden">Otwórz</span></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
      <tr><td colspan="9" class="edl-empty"><i class="bi bi-inbox tw-text-3xl tw-block tw-mb-2" aria-hidden="true"></i>Brak dokumentów<?= $filters_any ? ' dla wybranych filtrów' : '' ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc):
        $kier = ($doc['kierunek'] ?? 'wydatek') === 'przychod' ? 'przychod' : 'wydatek';
        $sel_pay = $can_pay && $doc['status'] === 'zaakceptowany' && $kier === 'wydatek'
                   && !in_array($doc['status_platnosci'] ?: 'nowy', ['oplacony', 'anulowany'], true);
        $view_url = APP_URL . '/edok/view.php?id=' . (int)$doc['id'];
        $pl = (string)($doc['status_platnosci'] ?? '');
      ?>
      <tr>
        <td><input type="checkbox" class="form-check-input edok-sel" value="<?= (int)$doc['id'] ?>"
                   data-sign="<?= $doc['status'] === 'w_obiegu' && empty($doc['obieg_papierowy']) ? 1 : 0 ?>"
                   data-pay="<?= $sel_pay ? 1 : 0 ?>"
                   data-export="<?= $can_export && edok_przelew_exportable($doc) ? 1 : 0 ?>"
                   data-bank="<?= $bank_pin_ok && $doc['status'] === 'w_obiegu' ? 1 : 0 ?>"
                   aria-label="Zaznacz dokument <?= h($doc['number']) ?>"></td>
        <td>
          <a class="edl-num" href="<?= h($view_url) ?>"><?= h($doc['number']) ?></a>
          <div class="edl-tags">
            <?php if ($kier === 'przychod'): ?><span class="edl-pill edl-pill--in"><i class="bi bi-arrow-down-left" aria-hidden="true"></i> Przychód</span>
            <?php else: ?><span class="edl-pill edl-pill--out"><i class="bi bi-arrow-up-right" aria-hidden="true"></i> Wydatek</span><?php endif; ?>
            <?php if (edok_is_test_number($doc['number'])): ?><span class="edl-pill edl-pill--warn">TEST</span><?php endif; ?>
            <?php if (edok_zaplata_do_zwrotu($doc)): ?><span class="edl-pill edl-pill--warn" title="<?= h(edok_zaplata_opis($doc)) ?>"><i class="bi bi-person-check" aria-hidden="true"></i> do zwrotu</span>
            <?php elseif (!empty($doc['zaplacono_przed'])): ?><span class="edl-pill edl-pill--ok" title="Zapłacona przed akceptacją: <?= h(edok_zaplata_opis($doc)) ?>"><i class="bi bi-cash-coin" aria-hidden="true"></i> zapłacona</span><?php endif; ?>
            <?php if ($doc['typ_dokumentu'] === 'proforma'): ?><span class="edl-pill edl-pill--info" title="<?= isset($proforma_z_faktura[$doc['id']]) ? 'Rozliczona fakturą końcową' : 'Czeka na fakturę końcową' ?>">proforma<?= isset($proforma_z_faktura[$doc['id']]) ? ' ✓' : ' · bez faktury' ?></span><?php endif; ?>
          </div>
        </td>
        <td><span class="edl-party"><?= h($doc['kontrahent_nazwa']) ?></span><?php if (!empty($doc['kontrahent_nip'])): ?><span class="edl-sub">NIP <?= h($doc['kontrahent_nip']) ?></span><?php endif; ?></td>
        <td><span class="edl-titlecell" title="<?= h($doc['title']) ?>"><?= h($doc['title']) ?></span></td>
        <td class="edl-amount"><b><?= h($doc['kwota_brutto']) ?></b><small><?= h($doc['waluta'] ?: 'PLN') ?></small></td>
        <td>
          <span class="edl-status edl-status--<?= h($doc['status']) ?>" title="<?= h(edok_status_label($doc)) ?>"><?= h($status_short[$doc['status']] ?? $doc['status']) ?></span>
          <?php if ($doc['status'] === 'zaakceptowany' && $kier === 'wydatek' && $pl !== '' && $pl !== 'nowy' && isset(EDOK_STATUS_PLATNOSCI[$pl])): ?>
          <span class="edl-sub">Płatność: <?= h(mb_strtolower(EDOK_STATUS_PLATNOSCI[$pl]['label'])) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (edok_is_papierowy($doc)): ?>
          <span class="edl-paper" title="Obieg papierowy — akceptacja na oryginale dokumentu"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Papierowy</span>
          <?php else:
            $parts_ = []; $steps_html_ = ''; $next_ = false;
            foreach (array_keys(EDOK_STEPS) as $sk) {
                $st = $doc['steps'][$sk] ?? null; $m_ = $doc['step_meta'][$sk] ?? null;
                $who_ = ($m_ && $m_['decided_at']) ? ' — ' . $m_['user_name'] . ', ' . date('d.m.Y H:i', strtotime($m_['decided_at'])) : '';
                if ($st === 'ok')                            { $c_ = 'is-ok';    $in_ = '<i class="bi bi-check-lg"></i>';       $s_ = 'zatwierdzone'; }
                elseif ($st === 'uwagi')                     { $c_ = 'is-uwagi'; $in_ = '!';                                    $s_ = 'z uwagami'; }
                elseif ($st === 'odrzucono')                 { $c_ = 'is-rej';   $in_ = '<i class="bi bi-x-lg"></i>';           $s_ = 'odrzucone'; }
                elseif ($doc['status'] === 'zaakceptowany')  { $c_ = 'is-ok';    $in_ = '<i class="bi bi-check-lg"></i>';       $s_ = 'zatwierdzone (bez obiegu etapowego)'; }
                elseif (!$next_ && $doc['status'] === 'w_obiegu') { $next_ = true; $c_ = 'is-next'; $in_ = h($step_abbr[$sk]); $s_ = 'bieżący etap, oczekuje'; }
                else                                         { $c_ = '';         $in_ = h($step_abbr[$sk]);                     $s_ = 'oczekuje'; }
                $parts_[] = $step_short[$sk] . ': ' . $s_;
                $steps_html_ .= '<span class="edl-step ' . $c_ . '" title="' . h(edok_step_label($sk, $doc) . ': ' . $s_ . $who_) . '">' . $in_ . '</span>';
            } ?>
          <span class="edl-steps" role="img" aria-label="<?= h('Obieg elektroniczny — ' . implode('; ', $parts_)) ?>"><?= $steps_html_ ?></span>
          <?php endif; ?>
        </td>
        <td class="tw-whitespace-nowrap" title="Dodano <?= h(date('d.m.Y H:i', strtotime((string)$doc['created_at']))) ?><?= !empty($doc['creator_name']) ? ' — ' . h($doc['creator_name']) : '' ?>"><?= date_pl($doc['created_at']) ?></td>
        <td><a href="<?= h($view_url) ?>" class="edl-open" title="Otwórz dokument" aria-label="Otwórz dokument <?= h($doc['number']) ?>"><i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="edl-legend">
  <span><strong>Etapy:</strong> M — merytoryczny, F — formalny, R — rachunkowy, D — dekretacja, Z — zatwierdzenie do zapłaty.</span>
  <span>✓ zielony — zatwierdzone, ! pomarańczowy — z uwagami, ✗ czerwony — odrzucone, niebieska ramka — bieżący etap, szara ramka — oczekuje.</span>
</p>
<?php if ($docs) include __DIR__ . '/../modules/edok_bulk/view/bar.php'; ?>

<?php if ($pages > 1): ?>
<nav aria-label="Stronicowanie dokumentów" class="mt-3">
  <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($page_url(max(1, $page - 1))) ?>" rel="prev">« Poprzednia</a></li>
    <?php for ($n = 1; $n <= $pages; $n++):
      if ($n !== 1 && $n !== $pages && abs($n - $page) > 2) { if ($n === 2 || $n === $pages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; continue; } ?>
    <li class="page-item<?= $n === $page ? ' active' : '' ?>"><a class="page-link" href="<?= h($page_url($n)) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($page_url(min($pages, $page + 1))) ?>" rel="next">Następna »</a></li>
  </ul>
</nav>
<?php endif; ?>
</div><!-- /.edl-page -->

<script>
(function () {
  // Zaznaczenie z listy (pola .edok-sel, obsługa w modules/edok_bulk/view/modals.php) → eksport przelewów
  // (tylko data-export=1) i „Zatwierdź operacje bankowe” (tylko data-bank=1: dokumenty w obiegu).
  function picked(attr) {
    return Array.prototype.slice.call(document.querySelectorAll('.edok-sel'))
      .filter(function (c) { return c.checked && c.getAttribute(attr) === '1'; })
      .map(function (c) { return c.value; });
  }
  var bankBtn = document.getElementById('bankQuickBtn'), bankCount = document.getElementById('bankQuickCount');
  var bankIds = document.getElementById('bankQuickIds'), bankNote = document.getElementById('bankQuickNote');
  var pendingCount = bankCount ? parseInt(bankCount.textContent, 10) || 0 : 0;
  var form = document.getElementById('edokExportForm');
  function sync() {
    var ids = picked('data-bank');
    if (bankIds) bankIds.value = ids.join(',');
    if (bankCount) bankCount.textContent = ids.length ? ids.length : pendingCount;
    if (bankBtn) { bankBtn.classList.toggle('btn-warning', !ids.length); bankBtn.classList.toggle('btn-danger', ids.length > 0); }
    if (bankNote) { bankNote.textContent = ids.length ? 'Zaznaczone z listy w obiegu: ' + ids.length + ' — zostaną oznaczone jako operacje banku (system zapamięta wzorzec tytułu) i zatwierdzone.' : ''; bankNote.style.display = ids.length ? '' : 'none'; }
    var list = document.getElementById('bankQuickPendingList'); if (list) list.style.display = ids.length ? 'none' : '';
    if (form) {
      var ex = picked('data-export');
      document.getElementById('edokExportIds').value = ex.join(',');
      document.getElementById('edokExportCount').textContent = ex.length;
      form.classList.toggle('d-none', !ex.length);
      form.classList.toggle('d-flex', ex.length > 0);
    }
  }
  document.addEventListener('edok-sel-change', sync);
  sync();
})();
</script>
<?php $edbBack = $_SERVER['REQUEST_URI'] ?? ''; if ($docs) include __DIR__ . '/../modules/edok_bulk/view/modals.php'; ?>

<?php if ((is_admin() || edok_has_role('upload')) && org_setting('kdok_ksef_enabled') === '1'): ?>
<div class="modal fade" id="salesSyncModal" tabindex="-1" aria-labelledby="salesSyncLabel" aria-hidden="true">
  <div class="modal-dialog"><form method="post" action="<?= APP_URL ?>/edok/ksef_sync.php" class="modal-content">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="sync_sales">
    <input type="hidden" name="return" value="index">
    <div class="modal-header"><h5 class="modal-title" id="salesSyncLabel">Pobierz faktury sprzedaży z KSeF</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <p class="small text-muted">Faktury wystawione przez organizację (sprzedawca) i ich korekty. Powstają jako dokumenty przychodowe EODoK; już pobrane są pomijane.</p>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-1" for="ss_from">Od</label><input type="date" id="ss_from" name="sales_from" class="form-control" value="<?= date('Y-m-01') ?>"></div>
        <div class="col-6"><label class="form-label small mb-1" for="ss_to">Do</label><input type="date" id="ss_to" name="sales_to" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary"><i class="bi bi-cloud-download"></i> Pobierz</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php if (is_admin() || edok_has_role('upload')): ?>
<div class="modal fade" id="salesXmlModal" tabindex="-1" aria-labelledby="salesXmlLabel" aria-hidden="true">
  <div class="modal-dialog"><form method="post" action="<?= APP_URL ?>/edok/ksef_sync.php" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="sales_xml"><input type="hidden" name="return" value="index">
    <div class="modal-header"><h5 class="modal-title" id="salesXmlLabel">Import sprzedaży z plików XML</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <p class="small text-muted">Faktury sprzedaży i korekty wystawione przez organizację (FA z KSeF lub z systemu fakturowego). Powstają jako dokumenty przychodowe; faktury o tym samym numerze są pomijane.</p>
      <label class="form-label small mb-1" for="sx_files">Pliki XML</label>
      <input type="file" id="sx_files" name="xml_files[]" class="form-control" accept=".xml" multiple required>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary"><i class="bi bi-upload"></i> Importuj</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php if ($bank_pin_ok): ?>
<!-- ═══ MODAL — zbiorcze zatwierdzenie operacji banku (jeden rodzaj działalności, jeden PIN) ═══ -->
<div class="modal fade" id="bankQuickModal" tabindex="-1" aria-labelledby="bankQuickLabel">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="bank_quick_all"><input type="hidden" name="doc_ids" id="bankQuickIds" value="">
      <div class="modal-header" style="background:#b45309;color:#fff">
        <h5 class="modal-title" id="bankQuickLabel"><i class="bi bi-bank me-2"></i>Zatwierdź operacje bankowe</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Opłaty i odsetki banku z wyciągu. Wskaż tylko rodzaj działalności — kwoty i opis dopełni system, wszystkie etapy podpiszą się jednym PIN-em.</p>
        <div id="bankQuickNote" class="alert alert-danger py-2 small" style="display:none"></div>
        <div id="bankQuickPendingList">
          <?php if ($bank_docs_pending): ?>
          <div class="small fw-semibold mb-1">Rozpoznane operacje banku w obiegu (<?= count($bank_docs_pending) ?>):</div>
          <ul class="small mb-3">
            <?php foreach ($bank_docs_pending as $d): ?><li><?= h($d['number']) ?> · <?= h($d['kontrahent_nazwa']) ?> · <?= h($d['kwota_brutto']) ?> PLN — <?= h($d['title']) ?></li><?php endforeach; ?>
          </ul>
          <?php else: ?><p class="small text-muted">Brak rozpoznanych operacji banku w obiegu. Zaznacz dokumenty na liście (kolumna z polami wyboru), żeby zatwierdzić je jako operacje banku.</p><?php endif; ?>
        </div>
        <div class="mb-2"><label class="form-label small fw-semibold" for="bqa_rodzaj">Rodzaj działalności</label>
          <select id="bqa_rodzaj" name="bq_rodzaj" class="form-select form-select-sm" required onchange="document.getElementById('bqa_projekt_wrap').style.display = this.value === 'projekt' ? '' : 'none'">
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2" id="bqa_projekt_wrap" style="display:none"><label class="form-label small fw-semibold" for="bqa_projekt">Projekt</label>
          <input id="bqa_projekt" name="bq_projekt" class="form-control form-control-sm"></div>
        <?php if (edok_pin_session_until((int)current_user()['id']) <= 0): ?>
        <div class="mb-2"><label class="form-label small fw-semibold" for="bqa_pin">PIN EODoK</label>
          <input id="bqa_pin" name="bq_pin" type="password" class="form-control form-control-sm" inputmode="numeric" autocomplete="one-time-code" required></div>
        <?php else: ?><p class="small text-success mb-0"><i class="bi bi-shield-check"></i> Sesja PIN aktywna — PIN niepotrzebny.</p><?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-warning"><i class="bi bi-check2-all"></i> Zatwierdź wszystkie</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
