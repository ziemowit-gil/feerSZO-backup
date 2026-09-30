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
        $key = sha1(implode('|', [$parsed['account_nrb'], $t['numer_operacji'], $t['data_waluty'], $t['znak'], $t['kwota'], $t['tytul']]));
        $ex = db_one("SELECT id FROM edok_bank_tx WHERE dedup_key = ?", [$key]);
        // ta sama operacja wgrana w innym formacie (MT940 ↔ Elixir): rachunek + data + znak + kwota + numer operacji
        if (!$ex && $t['numer_operacji'] !== '') {
            $ex = db_one("SELECT id FROM edok_bank_tx WHERE replace(account_nrb,'PL','')=? AND data_waluty=? AND znak=? AND kwota=? AND numer_operacji=?",
                         [preg_replace('/^PL/i', '', (string)$parsed['account_nrb']), $t['data_waluty'], $t['znak'], (float)$t['kwota'], $t['numer_operacji']]);
        }
        if ($ex) { $out['duplicates']++; $out['ids'][$i] = (int)$ex['id']; continue; }
        $out['ids'][$i] = db_insert('edok_bank_tx', [
            'dedup_key' => $key, 'account_nrb' => $parsed['account_nrb'], 'statement_no' => $parsed['statement_no'],
            'data_waluty' => $t['data_waluty'], 'znak' => $t['znak'], 'kwota' => (float)$t['kwota'],
            'kontrahent_nazwa' => $t['kontrahent_nazwa'], 'kontrahent_konto' => preg_replace('/\D/', '', (string)$t['kontrahent_konto']),
            'tytul' => $t['tytul'], 'referencja' => (string)($t['referencja'] ?? ''), 'numer_operacji' => $t['numer_operacji'],
            'imported_by' => $user_id, 'imported_at' => date('Y-m-d H:i:s'),
        ]);
        $out['new_ids'][] = $out['ids'][$i];
        $out['added']++;
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
    $out = ['account_nrb' => '', 'statement_no' => '', 'opening' => null, 'closing' => null, 'transactions' => []];
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
    return null;
}

/** Cyfry rachunku (bez PL i spacji). */
function _edok_nrb_digits(string $s): string { return preg_replace('/^PL/i', '', preg_replace('/\s+/', '', $s)); }

/** Rachunek własny organizacji (z listy rachunków) rozpoznany po rachunku kontrahenta operacji; null = to nie przelew własny. */
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
        'uzasadnienie' => $uzasadnienie !== '' ? $uzasadnienie : 'Przelew własny między rachunkami organizacji (import wyciągu)',
    ], $user_id);
    db()->prepare("UPDATE edok_bank_tx SET transfer_id=?, ignored=1, ignore_note=? WHERE id=?")
        ->execute([$tid, 'Przelew własny #' . $tid, $tx_id]);
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
