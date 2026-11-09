<?php
/**
 * modules/edok_bulk/logic/edokBulk.php — akcje zbiorcze na dokumentach zaznaczonych na liście /edok/index.php.
 *
 * Każda akcja przechodzi dokument po dokumencie przez TE SAME funkcje co akcja pojedyncza (includes/edok.php):
 *   • akceptacja jednym PIN-em  → edok_sign_all() (jak „Weryfikuj i podpisz wszystkie” na karcie dokumentu),
 *   • PDF-y w ZIP               → dokument końcowy (edok_latest_generated_pdf) albo eksport do druku (edok_save_print_pdf),
 *   • oznaczenie „Opłacony”     → te same reguły i skutki co zmiana statusu w Preliminarzu (edok/preliminarz_action.php).
 * Uprawnienia, blokady sekwencji, walidacje i dziennik edok_events są wspólne — tu nie ma własnych reguł.
 * Wynik: lista zrobionych + lista pominiętych z powodem, pokazywana raz nad listą (view/result.php).
 */
require_once dirname(__DIR__, 3) . '/includes/edok.php';

/** Najwięcej dokumentów w jednej akcji (lista ma 50 na stronę; PDF-y i akceptacja są kosztowne). */
const EDOK_BULK_MAX = 100;

/** Adres trasy modułu (lista dokumentów nie jest wywoływana przez router, więc bez route()). */
function edokBulkUrl(string $path): string {
    return rtrim(APP_URL, '/') . '/modules/edok_bulk/router.php?_route=' . rawurlencode('/' . ltrim($path, '/'));
}

/** Identyfikatory z formularza: liczby dodatnie, bez powtórzeń, najwyżej EDOK_BULK_MAX. */
function edokBulkIds($raw): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$raw), fn($v) => $v > 0)));
    return array_slice($ids, 0, EDOK_BULK_MAX);
}

/** Powrót na listę z tymi samymi filtrami — tylko do /edok/index.php (bez otwartego przekierowania). */
function edokBulkBackUrl(string $back): string {
    $list  = rtrim(APP_URL, '/') . '/edok/index.php';
    $path  = (string)parse_url($back, PHP_URL_PATH);
    $query = (string)parse_url($back, PHP_URL_QUERY);
    if (!str_ends_with($path, '/edok/index.php')) return $list;
    return $list . ($query !== '' ? '?' . $query : '');
}

/** Zapamiętuje podsumowanie do pokazania raz nad listą. $verb — nazwa akcji, np. „Akceptacja zbiorcza”. */
function edokBulkRemember(string $verb, array $res): void {
    auth_start();
    $_SESSION['edok_bulk_result'] = ['verb' => $verb] + $res;
}

/** Odbiera (i czyści) podsumowanie ostatniej akcji zbiorczej. */
function edokBulkTakeResult(): ?array {
    auth_start();
    $r = $_SESSION['edok_bulk_result'] ?? null;
    unset($_SESSION['edok_bulk_result']);
    return is_array($r) ? $r : null;
}

/** Etykieta dokumentu w podsumowaniu. */
function edokBulkLabel(array $doc): string {
    return (string)($doc['number'] ?: '#' . (int)$doc['id']);
}

/** Krótki opis dokumentu (kontrahent · tytuł) w podsumowaniu. */
function edokBulkTitle(array $doc): string {
    return trim(implode(' · ', array_filter([(string)($doc['kontrahent_nazwa'] ?? ''), (string)($doc['title'] ?? '')])));
}

/** Dokumenty w kolejności zaznaczenia (id → wiersz edok_get z etapami); nieistniejące trafiają do $missing. */
function edokBulkDocs(array $ids, array &$missing): array {
    $docs = [];
    foreach ($ids as $id) {
        $doc = edok_get((int)$id);
        if ($doc) $docs[(int)$id] = $doc; else $missing[] = ['label' => '#' . (int)$id, 'title' => '', 'reason' => 'dokument nie istnieje'];
    }
    return $docs;
}

/** Czy użytkownik może zmieniać status płatności (te same osoby co w Preliminarzu). */
function edokBulkCanPay(): bool {
    return is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza'));
}

/**
 * Akceptacja zbiorcza: dla każdego dokumentu akceptuje („Tak/OK”) kolejne etapy, do których użytkownik ma rolę —
 * dokładnie jak „Weryfikuj i podpisz wszystkie” na karcie (edok_sign_all_plan + edok_sign_all), każdy etap
 * zapisany osobno w historii. Tożsamość weryfikowana JEDNYM PIN-em (albo aktywną sesją PIN) PRZED pierwszym
 * dokumentem — błędny PIN przerywa całość, zanim cokolwiek zostanie podpisane (i spala tylko jedną próbę).
 *
 * @return array{done: list<string>, skipped: list<array{label:string,title:string,reason:string}>, error: ?string}
 */
function edokBulkSign(array $ids, int $userId, string $pin, string $notes): array {
    $res = ['done' => [], 'skipped' => [], 'error' => null];
    $docs = edokBulkDocs($ids, $res['skipped']);

    // 1. Co w ogóle da się zaakceptować — reszta pominięta z powodem, bez ruszania PIN-u.
    $ready = [];
    foreach ($docs as $id => $doc) {
        $skip = function (string $why) use (&$res, $doc): void { $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => $why]; };
        if (edok_is_papierowy($doc) && ($doc['status'] ?? '') === 'zaakceptowany') { $skip('obieg papierowy — zatwierdzony na papierze'); continue; }
        if (in_array($doc['status'] ?? '', ['zaakceptowany', 'odrzucony', 'wycofany'], true)) {
            $skip('dokument jest już ' . mb_strtolower(EDOK_STATUSES[$doc['status']]['label'] ?? $doc['status']));
            continue;
        }
        if (($doc['status'] ?? '') === 'draft') { $skip('projekt — dokument nie został jeszcze przekazany do obiegu'); continue; }
        $plan = edok_sign_all_plan($doc, $userId);
        if (!$plan) { $skip('brak etapu, który możesz teraz zaakceptować (inna rola albo decyzja „z uwagami”)'); continue; }
        if ($plan[0]['errors']) { $skip(implode(' ', $plan[0]['errors'])); continue; }
        $blocked = edok_step_blocked_reason($doc, $plan[0]['key']);
        if ($blocked) { $skip($blocked); continue; }
        $ready[$id] = $plan;
    }
    if (!$ready) return $res;

    // 2. Jeden PIN dla wszystkich (sesja PIN zwalnia z wpisywania). Poprawny PIN otwiera sesję PIN.
    if (edok_pin_session_until($userId) <= 0) {
        $firstId = (int)array_key_first($ready);
        $err = edok_pin_verify_for_decision($userId, $pin, $firstId, $ready[$firstId][0]['key']);
        if ($err !== null) { $res['error'] = $err; return $res; }
    }

    // 3. Dokument po dokumencie — edok_sign_all sprawdza wszystko ponownie przed każdym etapem.
    $note = trim($notes . ($notes !== '' ? ' ' : '') . '[Akceptacja zbiorcza z listy EODoK: ' . count($ready) . ' dok.]');
    foreach (array_keys($ready) as $id) {
        $doc = $docs[$id];
        // Sesja PIN może wygasnąć w trakcie (stały czas od wpisania PIN-u) — wtedy ten sam, już sprawdzony PIN.
        $p = edok_pin_session_until($userId) > 0 ? '' : $pin;
        try {
            $out = edok_sign_all((int)$id, $userId, $p, $note);
        } catch (\Throwable $e) {
            $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => 'błąd: ' . $e->getMessage()];
            continue;
        }
        if ($out['error'] !== null) {
            $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => $out['error']];
            continue;
        }
        $res['done'][] = edokBulkLabel($doc) . ' (' . implode(' → ', $out['signed']) . ')'
            . ($out['stopped'] ? ' — zatrzymano: ' . $out['stopped'] : '');
    }
    return $res;
}

/** Bezpieczna nazwa pliku w ZIP: „EODoK_12_2026 Kontrahent.pdf”. */
function edokBulkZipName(array $doc, array &$used): string {
    $base = preg_replace('/[^\p{L}\p{N}_.\- ]+/u', '_', str_replace('/', '_', edokBulkLabel($doc)) . ' ' . mb_substr((string)($doc['kontrahent_nazwa'] ?? ''), 0, 40));
    $base = trim(preg_replace('/\s+/u', ' ', $base), ' _.') ?: 'dokument_' . (int)$doc['id'];
    $name = $base . '.pdf';
    for ($i = 2; isset($used[mb_strtolower($name)]); $i++) $name = $base . ' (' . $i . ').pdf';
    $used[mb_strtolower($name)] = true;
    return $name;
}

/**
 * PDF-y zaznaczonych dokumentów w jednym ZIP. Zaakceptowany z dokumentem końcowym → ten plik (źródło + karta
 * akceptacji z chwili zamknięcia obiegu); pozostałe → świeży eksport do druku (edok_save_print_pdf: zapis
 * w EODoK + wpis w historii, karta pokazuje stan z chwili eksportu) — jak przycisk „PDF” na karcie dokumentu.
 *
 * @return array{zip: ?string, done: list<string>, skipped: list<array{label:string,title:string,reason:string}>}
 *         zip — ścieżka pliku tymczasowego (do wysłania i usunięcia przez wywołującego) albo null.
 */
function edokBulkZip(array $ids): array {
    $res = ['zip' => null, 'done' => [], 'skipped' => []];
    if (!class_exists('ZipArchive')) { $res['skipped'][] = ['label' => '—', 'title' => '', 'reason' => 'brak rozszerzenia ZIP na serwerze']; return $res; }
    @set_time_limit(300);
    $docs = edokBulkDocs($ids, $res['skipped']);
    $tmp  = tempnam(sys_get_temp_dir(), 'edok_zip_');
    $zip  = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); $res['skipped'][] = ['label' => '—', 'title' => '', 'reason' => 'nie udało się utworzyć pliku ZIP']; return $res; }
    $used = [];
    foreach ($docs as $doc) {
        $abs = null; $kind = '';
        $final = ($doc['status'] ?? '') === 'zaakceptowany' ? edok_latest_generated_pdf((int)$doc['id']) : null;
        if ($final && is_file(UPLOAD_DIR . $final['file_path'])) {
            $abs = UPLOAD_DIR . $final['file_path']; $kind = 'dokument końcowy';
        } else {
            try {
                $saved = edok_save_print_pdf($doc);
                $abs = UPLOAD_DIR . $saved['file_path']; $kind = 'wydruk ze stanem obiegu z ' . date('d.m.Y H:i');
            } catch (\Throwable $e) {
                $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => 'nie udało się wygenerować PDF: ' . $e->getMessage()];
                continue;
            }
        }
        if (!is_file($abs)) { $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => 'brak pliku PDF na serwerze']; continue; }
        $zip->addFile($abs, edokBulkZipName($doc, $used));
        $res['done'][] = edokBulkLabel($doc) . ' (' . $kind . ')';
    }
    $zip->close();
    if (!$res['done']) { @unlink($tmp); return $res; }
    $res['zip'] = $tmp;
    return $res;
}

/**
 * Oznaczenie „Opłacony” — te same reguły co zmiana statusu płatności w Preliminarzu (edok/preliminarz_action.php):
 * tylko zaakceptowane wydatki, ze skutkami dla listy płac (wszystkie pozycje wypłacone) i rachunku do umowy
 * zlecenie („Zapłacony”). Data i forma zapłaty trafiają do historii dokumentu (pola data_zaplaty/forma_zaplaty
 * dotyczą zapłaty PRZED akceptacją i zostają nietknięte).
 *
 * @return array{done: list<string>, skipped: list<array{label:string,title:string,reason:string}>}
 */
function edokBulkMarkPaid(array $ids, string $paidOn, string $forma, string $notes): array {
    $res = ['done' => [], 'skipped' => []];
    $docs = edokBulkDocs($ids, $res['skipped']);
    $opis = 'zapłacono ' . date('d.m.Y', strtotime($paidOn)) . ', ' . mb_strtolower(EDOK_FORMY_ZAPLATY[$forma] ?? $forma)
          . ($notes !== '' ? '; ' . $notes : '');
    foreach ($docs as $doc) {
        $skip = function (string $why) use (&$res, $doc): void { $res['skipped'][] = ['label' => edokBulkLabel($doc), 'title' => edokBulkTitle($doc), 'reason' => $why]; };
        $prev = (string)($doc['status_platnosci'] ?: 'nowy');
        if (($doc['status'] ?? '') !== 'zaakceptowany')          { $skip('dokument nie jest zaakceptowany'); continue; }
        if (($doc['kierunek'] ?? 'wydatek') !== 'wydatek')      { $skip('przychód — status płatności dotyczy tylko wydatków'); continue; }
        if ($prev === 'oplacony')                                { $skip('już opłacony'); continue; }
        if ($prev === 'anulowany')                               { $skip('płatność anulowana'); continue; }
        try {
            db_exec("UPDATE edok_documents SET status_platnosci='oplacony', updated_at=datetime('now') WHERE id=?", [(int)$doc['id']]);
            edok_log((int)$doc['id'], 'status_platnosci', '', $prev, 'oplacony',
                'Status płatności: ' . (EDOK_STATUS_PLATNOSCI[$prev]['label'] ?? $prev) . ' → ' . EDOK_STATUS_PLATNOSCI['oplacony']['label']
                . ' (zbiorczo z listy EODoK; ' . $opis . ')');
            if (($doc['typ_dokumentu'] ?? '') === 'lista_plac') edok_lista_plac_oplacona((int)$doc['id'], true);
            edok_sync_rachunki_umow((int)$doc['id']);   // rachunek do umowy zlecenie → „Zapłacony”
        } catch (\Throwable $e) {
            $skip('błąd: ' . $e->getMessage());
            continue;
        }
        $res['done'][] = edokBulkLabel($doc);
    }
    return $res;
}
