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
    $out = ['added' => 0, 'duplicates' => 0, 'ids' => []];
    foreach ($parsed['transactions'] as $i => $t) {
        $key = sha1(implode('|', [$parsed['account_nrb'], $t['numer_operacji'], $t['data_waluty'], $t['znak'], $t['kwota'], $t['tytul']]));
        $ex = db_one("SELECT id FROM edok_bank_tx WHERE dedup_key = ?", [$key]);
        if ($ex) { $out['duplicates']++; $out['ids'][$i] = (int)$ex['id']; continue; }
        $out['ids'][$i] = db_insert('edok_bank_tx', [
            'dedup_key' => $key, 'account_nrb' => $parsed['account_nrb'], 'statement_no' => $parsed['statement_no'],
            'data_waluty' => $t['data_waluty'], 'znak' => $t['znak'], 'kwota' => (float)$t['kwota'],
            'kontrahent_nazwa' => $t['kontrahent_nazwa'], 'kontrahent_konto' => preg_replace('/\D/', '', (string)$t['kontrahent_konto']),
            'tytul' => $t['tytul'], 'referencja' => (string)($t['referencja'] ?? ''), 'numer_operacji' => $t['numer_operacji'],
            'imported_by' => $user_id, 'imported_at' => date('Y-m-d H:i:s'),
        ]);
        $out['added']++;
    }
    return $out;
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
        foreach (preg_split('/\s+/', mb_strtoupper((string)$d['kontrahent_nazwa'])) as $w) {
            if (mb_strlen($w) >= 5 && str_contains($name, $w)) { $score += 10; $why[] = 'nazwa'; break; }
        }
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

/** Przypisuje automatycznie transakcje, dla których jest jednoznaczny kandydat (numer EODoK w tytule + zgodna kwota). */
function edok_bank_auto_match(): int {
    $n = 0;
    foreach (db_all("SELECT * FROM edok_bank_tx WHERE doc_id IS NULL AND ignored = 0") as $tx) {
        $c = edok_bank_candidates($tx, 2);
        if (!$c || !$c[0]['amount_ok'] || $c[0]['score'] < 150) continue;
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
