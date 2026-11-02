<?php
/**
 * EODoK — transakcje z wyciągów bankowych (MT940 PKO BP) przypisywane do dokumentów.
 * Parser MT940 jest w includes/edok.php (edok_mt940_parse); tu: zapis transakcji,
 * podpowiedzi dopasowania i przypisanie/odpięcie od dokumentu.
 *
 * Przypisanie wypływu do zaakceptowanego wydatku ustawia status płatności „Opłacony”
 * (potwierdzenie z banku); odpięcie przywraca poprzedni status (edok_bank_tx.paid_prev).
 */
require_once __DIR__ . '/edok.php';

/** Cyfry i wielkie litery — do porównań numerów i tytułów niezależnych od spacji/interpunkcji. */
function _edok_bank_norm(string $s): string {
    return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s));
}

/**
 * Zapisuje transakcje z wyniku edok_mt940_parse(). Zwraca ['added'=>int, 'duplicates'=>int, 'ids'=>[idx=>tx_id]].
 * Te same operacje wgrane drugi raz są pomijane (klucz: rachunek + numer operacji + data + kwota + tytuł).
 */
function edok_bank_import(array $parsed, int $user_id): array {
    edok_migrate();
    $out = ['added' => 0, 'duplicates' => 0, 'ids' => [], 'new_ids' => []];
    foreach ($parsed['transactions'] as $i => $t) {
        // raport zbiorczy: rachunek i numer wyciągu z bloku, w którym operacja wystąpiła
        $acc  = (string)($t['account_nrb'] ?? '') !== '' ? (string)$t['account_nrb'] : (string)$parsed['account_nrb'];
        $stmt = (string)($t['statement_no'] ?? '') !== '' ? (string)$t['statement_no'] : (string)$parsed['statement_no'];
        $key = sha1(implode('|', [$acc, $t['numer_operacji'], $t['data_waluty'], $t['znak'], $t['kwota'], $t['tytul']]));
        $ex = db_one("SELECT id FROM edok_bank_tx WHERE dedup_key = ?", [$key]);
        // ta sama operacja wgrana w innym formacie (MT940 ↔ Elixir): rachunek + data + znak + kwota + numer operacji
        if (!$ex && $t['numer_operacji'] !== '') {
            $ex = db_one("SELECT id FROM edok_bank_tx WHERE replace(account_nrb,'PL','')=? AND data_waluty=? AND znak=? AND kwota=? AND numer_operacji=?",
                         [preg_replace('/^PL/i', '', $acc), $t['data_waluty'], $t['znak'], (float)$t['kwota'], $t['numer_operacji']]);
        }
        if ($ex) { $out['duplicates']++; $out['ids'][$i] = (int)$ex['id']; continue; }
        $out['ids'][$i] = db_insert('edok_bank_tx', [
            'dedup_key' => $key, 'account_nrb' => $acc, 'statement_no' => $stmt,
            'data_waluty' => $t['data_waluty'], 'znak' => $t['znak'], 'kwota' => (float)$t['kwota'],
            'kontrahent_nazwa' => $t['kontrahent_nazwa'], 'kontrahent_konto' => preg_replace('/\D/', '', (string)$t['kontrahent_konto']),
            'tytul' => $t['tytul'], 'referencja' => (string)($t['referencja'] ?? ''), 'numer_operacji' => $t['numer_operacji'],
            'imported_by' => $user_id, 'imported_at' => date('Y-m-d H:i:s'),
        ]);
        $out['new_ids'][] = $out['ids'][$i];
        $out['added']++;
        try { edok_bank_apply_kategoria((int)$out['ids'][$i]); } catch (\Throwable $e) { error_log('[edok bank kategoria] ' . $e->getMessage()); }
    }
    return $out;
}

/**
 * Parser pliku Elixir-0 (eksport wyciągu z iPKO biznes) do tej samej struktury co edok_mt940_parse().
 * Jedna operacja = jedna linia CSV: typ, data RRRRMMDD, kwota w groszach, …, rachunek, nazwy (pola
 * rozdzielane „|"), tytuł (4 × 35 znaków rozdzielone „|"), numer operacji. Typ 2xx = obciążenie (D),
 * pozostałe = uznanie (C) — kierunek sprawdź na ekranie podglądu przed zapisem.
 */
function edok_elixir_parse(string $raw): array {
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @iconv('CP1250', 'UTF-8//TRANSLIT', $raw);
        if ($conv !== false) $raw = $conv;
    }
    $out = ['account_nrb' => '', 'statement_no' => '', 'opening' => null, 'closing' => null, 'statements' => [], 'transactions' => []];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        if (trim($line) === '') continue;
        $f = str_getcsv($line, ',', '"', '');
        if (count($f) < 12 || !preg_match('/^\d{3}$/', $f[0]) || !preg_match('/^\d{8}$/', $f[1])) continue;
        $own = '';
        foreach ([$f[6] ?? '', $f[5] ?? ''] as $c) { $d = preg_replace('/\D/', '', $c); if (strlen($d) === 26) { $own = $d; break; } }
        if ($out['account_nrb'] === '' && $own !== '') $out['account_nrb'] = $own;
        $names = array_filter(array_map(fn($x) => trim($x), array_merge(explode('|', (string)($f[7] ?? '')), explode('|', (string)($f[8] ?? '')))), fn($x) => $x !== '');
        $title = trim(preg_replace('/\s+/', ' ', str_replace('|', '', (string)($f[11] ?? ''))));
        $opnr  = preg_replace('/\D/', '', (string)($f[13] ?? ''));
        $out['transactions'][] = [
            'account_nrb'    => $own !== '' ? $own : $out['account_nrb'],   // raport zbiorczy: każda linia niesie własny rachunek
            'statement_no'   => '',
            'data_waluty'    => substr($f[1], 0, 4) . '-' . substr($f[1], 4, 2) . '-' . substr($f[1], 6, 2),
            'znak'           => $f[0][0] === '2' ? 'D' : 'C',
            'kwota'          => number_format(((int)$f[2]) / 100, 2, '.', ''),
            'kod_ozsi'       => $f[0],
            'referencja'     => null,
            'numer_operacji' => $opnr,
            'tytul'          => $title,
            'kontrahent_bank' => preg_replace('/\D/', '', (string)($f[10] ?? '')),
            'kontrahent_konto' => '',
            'kontrahent_nazwa' => implode(' ', $names),
            'kontrahent_iban' => '',
            'data_dokumentu' => '',
            'swrk'           => '',
        ];
    }
    foreach (edok_bank_parsed_accounts($out) as $a) {
        $out['statements'][] = ['account_nrb' => $a, 'statement_no' => '', 'opening' => null, 'closing' => null,
            'count' => count(array_filter($out['transactions'], fn($t) => $t['account_nrb'] === $a))];
    }
    return $out;
}

/**
 * Kilka plików raportu naraz (np. raport zbiorczy podzielony na pliki albo osobne wyciągi
 * z kilku rachunków): każdy plik parsowany osobno (mogą być w różnych formatach), wynik
 * scalony do jednej struktury; operacje przenumerowane od zera (indeksy z podglądu).
 */
function edok_bank_parse_files(array $raws): array {
    $out = ['account_nrb' => '', 'statement_no' => '', 'opening' => null, 'closing' => null, 'statements' => [], 'transactions' => [], 'files' => 0];
    foreach ($raws as $raw) {
        if (trim((string)$raw) === '') continue;
        $p = edok_bank_parse_any((string)$raw);
        $out['files']++;
        if ($out['account_nrb'] === '' && $out['opening'] === null) {
            $out['account_nrb'] = $p['account_nrb']; $out['statement_no'] = $p['statement_no'];
            $out['opening'] = $p['opening']; $out['closing'] = $p['closing'];
        }
        foreach ($p['statements'] ?? [] as $s) $out['statements'][] = $s;
        foreach ($p['transactions'] as $t) {
            if (($t['account_nrb'] ?? '') === '') $t['account_nrb'] = $p['account_nrb'];
            if (($t['statement_no'] ?? '') === '') $t['statement_no'] = $p['statement_no'];
            $out['transactions'][] = $t;
        }
    }
    return $out;
}

/** Rozpoznaje format (Elixir-0 vs MT940) i parsuje do wspólnej struktury. */
function edok_bank_parse_any(string $raw): array {
    $first = '';
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) { if (trim($l) !== '') { $first = trim($l); break; } }
    return preg_match('/^\d{3},\d{8},/', $first) ? edok_elixir_parse($raw) : edok_mt940_parse($raw);
}

/** Słowa nazwy bez form prawnych i krótkich spójników. */
function _edok_bank_name_tokens(string $s): array {
    $s = mb_strtoupper($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $stop = ['SP', 'ZOO', 'SPOLKA', 'SPÓŁKA', 'Z', 'O', 'SA', 'S', 'A', 'OO', 'SK', 'KOMANDYTOWA', 'JAWNA', 'POLSKA', 'FIRMA', 'USLUGOWE', 'HANDLOWE', 'PHU', 'FUNDACJA', 'STOWARZYSZENIE'];
    $t = [];
    foreach (preg_split('/\s+/u', trim($s)) as $w) if (mb_strlen($w) >= 3 && !in_array($w, $stop, true)) $t[$w] = true;
    return array_keys($t);
}
/** 2 = wszystkie znaczące słowa nazwy dokumentu występują w nazwie/tytule operacji, 1 = co najmniej połowa (min. jedno), 0 = brak. */
function _edok_bank_name_score(string $doc_name, string $haystack): int {
    $tok = _edok_bank_name_tokens($doc_name);
    if (!$tok) return 0;
    $h = mb_strtoupper(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $haystack));
    $hit = 0; foreach ($tok as $w) if (str_contains($h, $w)) $hit++;
    if ($hit === count($tok)) return 2;
    return $hit >= max(1, (int)ceil(count($tok) / 2)) ? 1 : 0;
}

/**
 * Podpowiedzi dokumentów dla transakcji, od najlepszej. Wynik: [['doc'=>wiersz, 'score'=>int, 'reasons'=>[...], 'amount_ok'=>bool]].
 * Wypływ (D) → wydatki, wpływ (C) → przychody i korekty. Numer EODoK w tytule (tytuł przelewu z Preliminarza
 * zaczyna się od niego) to najsilniejszy sygnał.
 */
function edok_bank_candidates(array $tx, int $limit = 5): array {
    $kierunek = $tx['znak'] === 'C' ? 'przychod' : 'wydatek';
    $docs = db_all(
        "SELECT d.* FROM edok_documents d
         WHERE d.status IN ('zaakceptowany','w_obiegu') AND COALESCE(d.kierunek,'wydatek') = ?
           AND NOT EXISTS (SELECT 1 FROM edok_bank_tx b WHERE b.doc_id = d.id AND b.id <> ?)
         ORDER BY d.id DESC LIMIT 800",
        [$kierunek, (int)($tx['id'] ?? 0)]
    );
    $title = _edok_bank_norm($tx['tytul']);
    $name  = mb_strtoupper($tx['kontrahent_nazwa']);
    $out = [];
    foreach ($docs as $d) {
        $score = 0; $why = [];
        $brutto = abs(_edok_kwota_float((string)$d['kwota_brutto']));
        $amount_ok = $brutto > 0 && abs($brutto - abs((float)$tx['kwota'])) < 0.01;
        if ($amount_ok) { $score += 50; $why[] = 'kwota'; }
        $num = _edok_bank_norm((string)$d['number']);
        if ($num !== '' && $title !== '' && str_contains($title, $num)) { $score += 100; $why[] = 'numer EODoK w tytule'; }
        $nr = _edok_bank_norm((string)$d['nr_faktury']);
        if (strlen($nr) >= 4 && $title !== '' && str_contains($title, $nr)) { $score += 40; $why[] = 'nr dokumentu w tytule'; }
        $rach = preg_replace('/\D/', '', (string)$d['rachunek_bankowy']);
        if (strlen($rach) === 26 && str_ends_with((string)$tx['kontrahent_konto'], $rach)) { $score += 40; $why[] = 'rachunek kontrahenta'; }
        $nip = preg_replace('/\D/', '', (string)$d['kontrahent_nip']);
        if (strlen($nip) === 10 && str_contains(preg_replace('/\D/', '', $tx['tytul']), $nip)) { $score += 20; $why[] = 'NIP w tytule'; }
        // bramki płatności: numer transakcji z dokumentu w tytule/referencji operacji (najsilniejszy sygnał po numerze EODoK)
        $gw = _edok_bank_norm((string)($d['nr_transakcji_bramki'] ?? ''));
        if (strlen($gw) >= 6 && (str_contains($title, $gw) || str_contains(_edok_bank_norm((string)($tx['referencja'] ?? '') . ' ' . (string)($tx['numer_operacji'] ?? '')), $gw))) {
            $score += 120; $why[] = 'nr transakcji bramki';
        }
        // faktura zapłacona z góry (karta/bramka): data operacji zbliżona do daty zapłaty/wystawienia + kwota
        if ($amount_ok) {
            $dref = (string)(($d['data_zaplaty'] ?? '') ?: ($d['data_wystawienia'] ?? ''));
            if ($dref !== '' && $tx['data_waluty'] !== '' && abs((strtotime($tx['data_waluty']) - strtotime($dref)) / 86400) <= 3) {
                $score += 30; $why[] = 'data zgodna z zapłatą';
                if (!empty($d['zaplacono_przed'])) { $score += 20; $why[] = 'zapłacona z góry'; }
            }
        }
        // nazwa kontrahenta: udział znaczących słów nazwy z dokumentu znalezionych w nazwie/tytule operacji
        $nm = _edok_bank_name_score((string)$d['kontrahent_nazwa'], $name . ' ' . mb_strtoupper((string)$tx['tytul']));
        if ($nm === 2) { $score += 40; $why[] = 'nazwa kontrahenta (pełna)'; }
        elseif ($nm === 1) { $score += 15; $why[] = 'nazwa kontrahenta (część)'; }
        if ($score >= 50 && ($amount_ok || $score >= 100)) $out[] = ['doc' => $d, 'score' => $score, 'reasons' => array_values(array_unique($why)), 'amount_ok' => $amount_ok];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}

/** Przypisuje transakcję do dokumentu. Zwraca komunikat błędu albo null. */
function edok_bank_assign(int $tx_id, int $doc_id, string $how = 'manual'): ?string {
    $tx = db_one("SELECT * FROM edok_bank_tx WHERE id = ?", [$tx_id]);
    $doc = db_one("SELECT * FROM edok_documents WHERE id = ?", [$doc_id]);
    if (!$tx || !$doc) return 'Nie znaleziono transakcji lub dokumentu.';
    if ($tx['doc_id']) return 'Ta transakcja jest już przypisana.';
    $user = current_user();
    $paid_prev = null;
    // Wypływ na zaakceptowany wydatek = zapłata potwierdzona w banku.
    if ($tx['znak'] === 'D' && $doc['status'] === 'zaakceptowany' && ($doc['kierunek'] ?? 'wydatek') === 'wydatek'
        && ($doc['status_platnosci'] ?: 'nowy') !== 'oplacony') {
        $paid_prev = $doc['status_platnosci'] ?: 'nowy';
        db_exec("UPDATE edok_documents SET status_platnosci='oplacony', updated_at=datetime('now') WHERE id=?", [$doc_id]);
    }
    db_exec("UPDATE edok_bank_tx SET doc_id=?, matched_how=?, matched_by=?, matched_by_name=?, matched_at=datetime('now'), paid_prev=?, ignored=0 WHERE id=?",
        [$doc_id, $how, (int)($user['id'] ?? 0), $user['name'] ?? '', $paid_prev, $tx_id]);
    edok_log($doc_id, 'bank_match', '', $doc['status_platnosci'] ?: 'nowy', $paid_prev !== null ? 'oplacony' : ($doc['status_platnosci'] ?: 'nowy'),
        'Przypisano transakcję bankową: ' . date_pl($tx['data_waluty']) . ', ' . ($tx['znak'] === 'C' ? '+' : '−') . number_format((float)$tx['kwota'], 2, ',', ' ') . ' ' . $tx['waluta']
        . ', ' . ($tx['kontrahent_nazwa'] ?: 'brak kontrahenta') . ' (wyciąg ' . ($tx['statement_no'] ?: '—') . ', operacja ' . $tx['numer_operacji'] . ', '
        . ($how === 'auto' ? 'automatycznie' : 'ręcznie') . ')' . ($paid_prev !== null ? '. Status płatności: Opłacony.' : '.'), $doc);
    if ($paid_prev !== null) edok_sync_rachunki_umow($doc_id); // rachunek do umowy → „Zapłacony”
    return null;
}

/** Odpina transakcję od dokumentu i cofa status „Opłacony”, jeśli ustawiło go to przypisanie. */
function edok_bank_unassign(int $tx_id): ?string {
    $tx = db_one("SELECT * FROM edok_bank_tx WHERE id = ?", [$tx_id]);
    if (!$tx || !$tx['doc_id']) return 'Transakcja nie jest przypisana.';
    $doc_id = (int)$tx['doc_id'];
    if ($tx['paid_prev'] !== null) {
        db_exec("UPDATE edok_documents SET status_platnosci=?, updated_at=datetime('now') WHERE id=? AND status_platnosci='oplacony'", [$tx['paid_prev'], $doc_id]);
    }
    db_exec("UPDATE edok_bank_tx SET doc_id=NULL, matched_how='', matched_by=NULL, matched_by_name='', matched_at=NULL, paid_prev=NULL WHERE id=?", [$tx_id]);
    edok_log($doc_id, 'bank_unmatch', '', '', '', 'Odpięto transakcję bankową (operacja ' . $tx['numer_operacji'] . ')' . ($tx['paid_prev'] !== null ? '; status płatności przywrócono do: ' . $tx['paid_prev'] . '.' : '.'));
    if ($tx['paid_prev'] !== null) edok_sync_rachunki_umow($doc_id);
    return null;
}

/** Cyfry rachunku (bez PL i spacji). */
function _edok_nrb_digits(string $s): string { return preg_replace('/^PL/i', '', preg_replace('/\s+/', '', $s)); }

// ── Kategorie operacji rozpoznawane automatycznie ─────────────────────────────
// Reguły ustalone z użytkownikiem 2026-11-02:
//   zus               — składki ZUS (rachunek składkowy NRS albo nazwa „Zakład Ubezpieczeń Społecznych”): BEZ dokumentu
//                       i bez zatwierdzania — wynikają z rachunków i umów; operacja oznaczana „ZUS”, pomijana automatycznie.
//   podatek_us        — Urząd Skarbowy / Centrum Rozliczeniowe (PIT od rachunków): jak ZUS — bez zatwierdzania.
//   wynagrodzenie     — tytuł „WYNAGRODZENIE MM/RRRR - umowa nr …”: wynika z rachunku — przypisywana do dokumentu
//                       rachunku/listy płac (umowa + okres + kwota), a gdy go nie ma: pomijana z adnotacją, bez nowego dokumentu.
//   oplata_bankowa    — „OPŁ.PRZ.ZEW.DO…”, prowizje PKO: oznaczana „PKO Bankowa”, WYMAGA zatwierdzenia — dokument
//                       kosztowy typu oplata_bankowa tworzony automatycznie, przechodzi obieg.
//   faktura_sprzedazy — wpływ z „FAKTURA FSP…” w tytule: zawsze przychód — przypisywany do faktury sprzedaży
//                       po numerze, a gdy jej nie ma: dokument przychodowy faktura_sprzedazy z numerem z tytułu.

const EDOK_BANK_KATEGORIE = [
    'zus'               => ['label' => 'ZUS',                 'class' => 'text-bg-dark',    'zatwierdzanie' => false],
    'podatek_us'        => ['label' => 'Urząd Skarbowy',      'class' => 'text-bg-dark',    'zatwierdzanie' => false],
    'wynagrodzenie'     => ['label' => 'Wynagrodzenie',       'class' => 'text-bg-primary', 'zatwierdzanie' => false],
    'oplata_bankowa'    => ['label' => 'PKO Bankowa',         'class' => 'text-bg-warning', 'zatwierdzanie' => true],
    'odsetki_bankowe'   => ['label' => 'Bank — odsetki',      'class' => 'text-bg-success', 'zatwierdzanie' => true],
    'faktura_sprzedazy' => ['label' => 'Przychód — faktura',  'class' => 'text-bg-success', 'zatwierdzanie' => true],
];

/**
 * Wzorce tytułów operacji bankowych (opłaty, prowizje, odsetki) — wbudowane skróty PKO BP podane przez użytkownika
 * 2026-11-02 + wyuczone z ręcznych oznaczeń (org_setting 'edok_bank_wzorce', JSON: kod kategorii → lista prefiksów).
 * Dopasowanie: tytuł zaczyna się od prefiksu (bez rozróżniania wielkości liter i spacji wokół kropek).
 */
const EDOK_BANK_WZORCE_WBUDOWANE = [
    'oplata_bankowa'  => ['OPŁ.', 'OPL.', 'OPŁATA', 'OPLATA', 'PROWIZJA', 'OPŁ.PRZ.ZEW.DO', 'OPŁ. WYC. OKR.', 'OPŁ.MOD.PEŁN.', 'OPŁ.ZA OP.SWRK', 'OPŁATA ZA PROWADZENIE RACHUNKU', 'KAPIT.ODS.K.OB'],
    'odsetki_bankowe' => ['KAPIT.ODS.', 'KAPITALIZACJA ODSETEK', 'ODSETKI'],
];

function _edok_bank_norm_tytul(string $s): string {
    $s = mb_strtoupper(trim($s));
    return preg_replace('/\s*\.\s*/u', '.', preg_replace('/\s+/u', ' ', $s));
}

/** Wzorce wbudowane + wyuczone. @return array<string, string[]> kod → prefiksy */
function edok_bank_wzorce(): array {
    $out = EDOK_BANK_WZORCE_WBUDOWANE;
    $learned = json_decode((string)org_setting('edok_bank_wzorce'), true);
    if (is_array($learned)) foreach ($learned as $kod => $list) if (isset(EDOK_BANK_KATEGORIE[$kod]) && is_array($list)) foreach ($list as $p) if (is_string($p) && trim($p) !== '') $out[$kod][] = $p;
    return $out;
}

/** Czy tytuł pasuje do wzorców kategorii. */
function edok_bank_wzorzec_match(string $kod, string $tytul): bool {
    $t = _edok_bank_norm_tytul($tytul);
    if ($t === '') return false;
    foreach (edok_bank_wzorce()[$kod] ?? [] as $p) { $p = _edok_bank_norm_tytul($p); if ($p !== '' && str_starts_with($t, $p)) return true; }
    return false;
}

/**
 * Uczenie: zapamiętuje prefiks tytułu (do pierwszej spacji po skrócie, np. „OPŁ.MOD.PEŁN.”, albo cały tytuł do 40 znaków)
 * jako wzorzec kategorii. Następny import rozpozna takie operacje sam.
 */
function edok_bank_wzorzec_learn(string $kod, string $tytul): ?string {
    if (!isset(EDOK_BANK_KATEGORIE[$kod])) return null;
    $t = _edok_bank_norm_tytul($tytul);
    if ($t === '') return null;
    // prefiks: skrót z kropkami (OPŁ.MOD.PEŁN.) albo pierwsze 3 słowa
    $p = preg_match('/^([A-ZĄĆĘŁŃÓŚŹŻ]+(?:\.[A-ZĄĆĘŁŃÓŚŹŻ]+)+\.?)/u', $t, $m) ? $m[1] : implode(' ', array_slice(explode(' ', $t), 0, 3));
    $p = mb_substr($p, 0, 40);
    $learned = json_decode((string)org_setting('edok_bank_wzorce'), true); if (!is_array($learned)) $learned = [];
    $learned[$kod] = array_values(array_unique(array_merge((array)($learned[$kod] ?? []), [$p])));
    org_setting_set('edok_bank_wzorce', json_encode($learned, JSON_UNESCAPED_UNICODE));
    return $p;
}

/**
 * Rozpoznaje kategorię operacji (patrz EDOK_BANK_KATEGORIE). Zwraca ['kod', 'label', ...dodatki] albo null.
 * Dodatki: wynagrodzenie → okres_od/okres_do (RRRR-MM), umowa; faktura_sprzedazy → nr_faktury.
 */
function edok_bank_kategoria(array $tx): ?array {
    $tytul = trim((string)($tx['tytul'] ?? ''));
    $nazwa = mb_strtoupper(trim((string)($tx['kontrahent_nazwa'] ?? '')));
    $konto = _edok_nrb_digits((string)($tx['kontrahent_konto'] ?? ''));
    $znak  = (string)($tx['znak'] ?? 'D');
    $up    = mb_strtoupper($tytul);
    $nrs   = function_exists('edok_zus_nrs') ? edok_zus_nrs() : '';
    if ($znak === 'D' && (($nrs !== '' && $konto !== '' && str_ends_with($konto, $nrs)) || preg_match('/ZAK[LŁ]AD\s+UBEZPIECZE|^ZUS\b|\bZUS\s+(SK[LŁ]ADK|DRA)/u', $nazwa . ' ' . $up))) {
        return ['kod' => 'zus'] + EDOK_BANK_KATEGORIE['zus'];
    }
    if ($znak === 'D' && preg_match('/URZ[AĄ]D\s+SKARBOW|CENTRUM\s+ROZLICZENIOW|\bPIT-?\d|\bPIT\s*4R?\b|MIKRORACHUN/u', $nazwa . ' ' . $up)) {
        return ['kod' => 'podatek_us'] + EDOK_BANK_KATEGORIE['podatek_us'];
    }
    if ($znak === 'D' && preg_match('/WYNAGRODZENI[AE]\s+(\d{2})\/(\d{4})(?:\s*-\s*(\d{2})\/(\d{4}))?(?:.*?UMOWA\s+NR\s*([A-Z0-9\/._-]+))?/iu', $tytul, $m)) {
        return ['kod' => 'wynagrodzenie', 'okres_od' => $m[2] . '-' . $m[1], 'okres_do' => !empty($m[3]) ? $m[4] . '-' . $m[3] : '', 'umowa' => trim((string)($m[5] ?? ''))] + EDOK_BANK_KATEGORIE['wynagrodzenie'];
    }
    // Operacje banku (opłaty, prowizje, kapitalizacja odsetek): wzorce wbudowane + wyuczone; wypływ = opłata, wpływ = odsetki.
    // Wszystkie operacje własne banku opisujemy z automatu jako opłata bankowa (decyzja użytkownika 2026-11-02).
    if ($znak === 'D' && (edok_bank_wzorzec_match('oplata_bankowa', $tytul) || ($konto === '' && $nazwa !== '' && preg_match('/^PKO\b|\bPKO BP\b|BANK POLSKI/u', $nazwa)))) {
        return ['kod' => 'oplata_bankowa'] + EDOK_BANK_KATEGORIE['oplata_bankowa'];
    }
    if ($znak === 'C' && (edok_bank_wzorzec_match('odsetki_bankowe', $tytul) || edok_bank_wzorzec_match('oplata_bankowa', $tytul))) {
        return ['kod' => 'odsetki_bankowe'] + EDOK_BANK_KATEGORIE['odsetki_bankowe'];
    }
    if ($znak === 'C' && preg_match('/\bFSP[\s\/-]*([0-9][0-9\/.\-]*)/u', $up, $m)) {
        return ['kod' => 'faktura_sprzedazy', 'nr_faktury' => 'FSP/' . ltrim(str_replace(' ', '', $m[1]), '/')] + EDOK_BANK_KATEGORIE['faktura_sprzedazy'];
    }
    return null;
}

/** Plakietka kategorii do list (HTML) albo ''. */
function edok_bank_kategoria_badge(?string $kod): string {
    if (!$kod || !isset(EDOK_BANK_KATEGORIE[$kod])) return '';
    $k = EDOK_BANK_KATEGORIE[$kod];
    return '<span class="badge rounded-pill ' . $k['class'] . '" title="Rozpoznane automatycznie' . ($k['zatwierdzanie'] ? ' — wymaga zatwierdzenia w obiegu' : ' — bez zatwierdzania (wynika z rachunków i umów)') . '">' . h($k['label']) . '</span>';
}

/**
 * Dokument EODoK dla rozpoznanego wynagrodzenia: rachunek do umowy (umowa_numer + okres) albo lista płac
 * z pozycją dla tej umowy/okresu; kwota musi się zgadzać z kwotą do wypłaty/pozycji (±0,01) albo brutto.
 */
function edok_bank_wynagrodzenie_doc(array $tx, array $kat): ?int {
    $kw = abs((float)$tx['kwota']);
    $umowa = _edok_bank_norm((string)($kat['umowa'] ?? ''));
    $okres = (string)($kat['okres_od'] ?? '');
    $ok = fn($v) => $v !== '' && abs(abs(_edok_kwota_float((string)$v)) - $kw) < 0.01;
    foreach (db_all("SELECT id, kwota_brutto, kwota_do_wyplaty, umowa_numer, okres, typ_dokumentu FROM edok_documents WHERE COALESCE(kierunek,'wydatek')='wydatek' AND typ_dokumentu IN ('rachunek','lista_plac') AND status IN ('zaakceptowany','w_obiegu') ORDER BY id DESC LIMIT 600") as $d) {
        if ($d['typ_dokumentu'] === 'rachunek') {
            $um_ok = $umowa !== '' && _edok_bank_norm((string)$d['umowa_numer']) === $umowa;
            $ok_ok = $okres !== '' && (string)$d['okres'] === $okres;
            if (($um_ok && ($ok_ok || $ok($d['kwota_do_wyplaty']) || $ok($d['kwota_brutto']))) || ($ok_ok && ($ok($d['kwota_do_wyplaty']) || $ok($d['kwota_brutto'])))) return (int)$d['id'];
        } else {
            foreach (db_all("SELECT kwota, umowa_numer, okres_od FROM edok_wyplaty WHERE doc_id=?", [(int)$d['id']]) as $w) {
                $um_ok = $umowa !== '' && _edok_bank_norm((string)$w['umowa_numer']) === $umowa;
                $ok_ok = $okres !== '' && ((string)($w['okres_od'] ?? '') === $okres || (string)$d['okres'] === $okres);
                if ($ok($w['kwota']) && ($um_ok || $ok_ok)) return (int)$d['id'];
            }
        }
    }
    return null;
}

/** Faktura sprzedaży (przychód) po numerze z tytułu wpływu — bez przypisanej już operacji. */
function edok_bank_faktura_sprzedazy_doc(array $tx, array $kat): ?int {
    $nr = _edok_bank_norm((string)($kat['nr_faktury'] ?? ''));
    if (strlen($nr) < 4) return null;
    foreach (db_all("SELECT d.id, d.nr_faktury FROM edok_documents d WHERE COALESCE(d.kierunek,'wydatek')='przychod' AND d.typ_dokumentu IN ('faktura_sprzedazy','korekta_sprzedazy') AND NOT EXISTS (SELECT 1 FROM edok_bank_tx b WHERE b.doc_id=d.id) ORDER BY d.id DESC LIMIT 400") as $d) {
        $dn = _edok_bank_norm((string)$d['nr_faktury']);
        if ($dn !== '' && ($dn === $nr || str_contains($nr, $dn) || str_contains($dn, $nr))) return (int)$d['id'];
    }
    return null;
}

/**
 * Stosuje kategorię do zapisanej operacji: ZUS / Urząd Skarbowy → pominięta (bez dokumentu); wynagrodzenie →
 * przypisanie do rachunku/listy płac albo pominięta z adnotacją; faktura sprzedaży → przypisanie po numerze.
 * Opłata bankowa i nieznaleziona faktura zostają nieprzypisane (dokument tworzy edok_bank_auto_create_docs).
 */
function edok_bank_apply_kategoria(int $tx_id): void {
    $tx = db_one("SELECT * FROM edok_bank_tx WHERE id=?", [$tx_id]);
    if (!$tx || $tx['doc_id'] || $tx['ignored'] || !empty($tx['transfer_id'])) return;
    $kat = edok_bank_kategoria($tx);
    db_exec("UPDATE edok_bank_tx SET kategoria=? WHERE id=?", [$kat['kod'] ?? '', $tx_id]);
    if (!$kat) return;
    switch ($kat['kod']) {
        case 'zus':
            db_exec("UPDATE edok_bank_tx SET ignored=1, ignore_note=? WHERE id=?", ['ZUS — składki (wynikają z rachunków i umów, bez zatwierdzania)', $tx_id]); break;
        case 'podatek_us':
            db_exec("UPDATE edok_bank_tx SET ignored=1, ignore_note=? WHERE id=?", ['Urząd Skarbowy — podatek od rachunków (bez zatwierdzania)', $tx_id]); break;
        case 'wynagrodzenie':
            $doc = edok_bank_wynagrodzenie_doc($tx, $kat);
            if ($doc) edok_bank_assign($tx_id, $doc, 'auto');
            else db_exec("UPDATE edok_bank_tx SET ignored=1, ignore_note=? WHERE id=?", ['Wynagrodzenie — wynika z rachunku' . (!empty($kat['umowa']) ? ' (umowa ' . $kat['umowa'] : ' (') . ($kat['okres_od'] !== '' ? ', ' . edok_okres_label($kat['okres_od']) : '') . '); brak dokumentu w EODoK', $tx_id]);
            break;
        case 'faktura_sprzedazy':
            $doc = edok_bank_faktura_sprzedazy_doc($tx, $kat);
            if ($doc) edok_bank_assign($tx_id, $doc, 'auto');
            break;
    }
}

/** Rachunek własny organizacji (z listy rachunków) rozpoznany po rachunku kontrahenta operacji; null = to nie przelew własny fundacji. */
function edok_bank_detect_own_counter(array $tx): ?string {
    $k = _edok_nrb_digits((string)($tx['kontrahent_konto'] ?? ''));
    if (strlen($k) < 26) return null;
    foreach (edok_rachunki_list() as $r) {
        $n = _edok_nrb_digits((string)($r['nrb'] ?? ''));
        if (strlen($n) === 26 && str_ends_with($k, $n) && $n !== _edok_nrb_digits((string)($tx['account_nrb'] ?? ''))) return (string)$r['nrb'];
    }
    return null;
}

/**
 * Rejestruje operację z wyciągu jako przelew własny (edok_transfers) i wyłącza ją z przypisywania do dokumentów.
 * Kierunek z znaku: wpływ = z rachunku kontrahenta na rachunek wyciągu, wypływ = odwrotnie.
 * Ten sam przelew widoczny na dwóch wyciągach (wypływ + wpływ) tworzy jeden wpis. Zwraca komunikat błędu albo null.
 */
function edok_bank_register_own_transfer(int $tx_id, string $counter_nrb, string $uzasadnienie, int $user_id): ?string {
    $tx = db_one("SELECT * FROM edok_bank_tx WHERE id = ?", [$tx_id]);
    if (!$tx) return 'Nie ma takiej operacji.';
    if ($tx['doc_id']) return 'Operacja jest już przypisana do dokumentu.';
    $own = _edok_nrb_digits((string)$tx['account_nrb']); $cnt = _edok_nrb_digits($counter_nrb);
    if (strlen($cnt) !== 26 || strlen($own) !== 26) return 'Brak rachunku własnego lub kontrahenta (26 cyfr).';
    if ($cnt === $own) return 'Rachunek źródłowy i docelowy muszą się różnić.';
    [$z, $do] = $tx['znak'] === 'C' ? [$cnt, $own] : [$own, $cnt];
    $kw = number_format((float)$tx['kwota'], 2, ',', '');
    $nazwa = function (string $n): string { foreach (edok_rachunki_list() as $r) if (_edok_nrb_digits((string)$r['nrb']) === $n) return ($r['nazwa'] ?: $r['bank']) . ' (' . substr($n, -4) . ')'; return ''; };
    $ex = null;
    foreach (db_all("SELECT id, rachunek_z_nrb, rachunek_do_nrb FROM edok_transfers WHERE data_przelewu=? AND kwota=?", [$tx['data_waluty'], $kw]) as $t) {
        if (_edok_nrb_digits($t['rachunek_z_nrb']) === $z && _edok_nrb_digits($t['rachunek_do_nrb']) === $do) { $ex = (int)$t['id']; break; }
    }
    $tid = $ex ?? edok_transfer_add([
        'data_przelewu' => $tx['data_waluty'], 'rachunek_z_nrb' => $z, 'rachunek_z_nazwa' => $nazwa($z),
        'rachunek_do_nrb' => $do, 'rachunek_do_nazwa' => $nazwa($do), 'kwota' => $kw, 'waluta' => $tx['waluta'] ?: 'PLN',
        'rodzaj_dzialalnosci_z' => '', 'projekt_z' => '', 'rodzaj_dzialalnosci_do' => '', 'projekt_do' => '',
        'uzasadnienie' => $uzasadnienie !== '' ? $uzasadnienie : 'Przelew własny fundacji między rachunkami organizacji (import wyciągu)',
    ], $user_id);
    db()->prepare("UPDATE edok_bank_tx SET transfer_id=?, ignored=1, ignore_note=? WHERE id=?")
        ->execute([$tid, 'Przelew własny fundacji #' . $tid, $tx_id]);
    return null;
}

/**
 * Nierozpoznane operacje z importu (bez dokumentu, nie pominięte, nie przelew własny) → automatycznie
 * nowe dokumenty EODoK „w obiegu" do opisania (wpływ = przychód, wypływ = wydatek typu „inny"),
 * z od razu przypisaną operacją. Dokument trzeba uzupełnić o skan i dekretację.
 * @param int[] $tx_ids id z edok_bank_import()['ids']
 */
function edok_bank_auto_create_docs(array $tx_ids, int $user_id): int {
    $n = 0;
    foreach (array_unique(array_map('intval', $tx_ids)) as $tid) {
        $t = db_one("SELECT * FROM edok_bank_tx WHERE id=? AND doc_id IS NULL AND ignored=0 AND COALESCE(transfer_id,0)=0", [$tid]);
        if (!$t) continue;
        $doc_id = edok_mt940_create_doc($t, $user_id);
        if (edok_bank_assign($tid, $doc_id, 'auto') === null) $n++;
    }
    return $n;
}

/** Przypisuje automatycznie transakcje z jednoznacznym kandydatem (numer EODoK w tytule + kwota albo kwota + pełna nazwa kontrahenta). */
function edok_bank_auto_match(): int {
    $n = 0;
    foreach (db_all("SELECT * FROM edok_bank_tx WHERE doc_id IS NULL AND ignored = 0") as $tx) {
        $c = edok_bank_candidates($tx, 2);
        if (!$c || !$c[0]['amount_ok']) continue;
        // pewne: numer EODoK w tytule (score ≥ 150) albo zgodna kwota + pełna nazwa kontrahenta
        $by_name = in_array('nazwa kontrahenta (pełna)', $c[0]['reasons'], true) && $c[0]['score'] >= 90;
        // faktura zapłacona z góry przez bramkę: kwota + data zapłaty w oknie ±3 dni + jedyny taki kandydat
        $by_paid = in_array('zapłacona z góry', $c[0]['reasons'], true) && in_array('data zgodna z zapłatą', $c[0]['reasons'], true);
        if ($c[0]['score'] < 150 && !$by_name && !$by_paid) continue;
        if (isset($c[1]) && $c[1]['score'] >= $c[0]['score']) continue; // niejednoznaczne
        if (edok_bank_assign((int)$tx['id'], (int)$c[0]['doc']['id'], 'auto') === null) $n++;
    }
    return $n;
}

/** Transakcje przypisane do dokumentu (karta dokumentu). */
function edok_bank_for_doc(int $doc_id): array {
    try { return db_all("SELECT * FROM edok_bank_tx WHERE doc_id = ? ORDER BY data_waluty", [$doc_id]); } catch (\Throwable $e) { return []; }
}

function edok_bank_unassigned_count(): int {
    try { return (int)db_one("SELECT COUNT(*) c FROM edok_bank_tx WHERE doc_id IS NULL AND ignored = 0")['c']; } catch (\Throwable $e) { return 0; }
}

/**
 * Niepowiązane transakcje z wyciągów, które mogą pasować do dokumentu (kierunek: wypływ↔wydatek, wpływ↔przychód),
 * od najlepiej dopasowanych — kwota, numer EODoK/nr dokumentu w tytule. Do wyboru na karcie dokumentu.
 */
function edok_bank_for_doc_candidates(array $doc, int $limit = 8): array {
    $znak = ($doc['kierunek'] ?? 'wydatek') === 'przychod' ? 'C' : 'D';
    $num = _edok_bank_norm((string)$doc['number']);
    $nr  = _edok_bank_norm((string)$doc['nr_faktury']);
    $brutto = abs(_edok_kwota_float((string)$doc['kwota_brutto']));
    $out = [];
    foreach (db_all("SELECT * FROM edok_bank_tx WHERE doc_id IS NULL AND ignored = 0 AND znak = ? ORDER BY data_waluty DESC LIMIT 400", [$znak]) as $t) {
        $title = _edok_bank_norm($t['tytul']); $score = 0;
        if ($brutto > 0 && abs($brutto - (float)$t['kwota']) < 0.01) $score += 50;
        if ($num !== '' && str_contains($title, $num)) $score += 100;
        if (strlen($nr) >= 4 && str_contains($title, $nr)) $score += 40;
        $out[] = ['tx' => $t, 'score' => $score];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($b['tx']['data_waluty'], $a['tx']['data_waluty']));
    return array_slice($out, 0, $limit);
}

/** Odpięcie z karty dokumentu — tylko transakcji przypisanej właśnie do tego dokumentu. */
function edok_bank_unlink_from_doc(int $tx_id, int $doc_id): ?string {
    $tx = db_one("SELECT doc_id FROM edok_bank_tx WHERE id = ?", [$tx_id]);
    if (!$tx || (int)$tx['doc_id'] !== $doc_id) return 'Ta transakcja nie jest powiązana z tym dokumentem.';
    return edok_bank_unassign($tx_id);
}
