<?php
/**
 * modules/ti_overpayments/logic/overpayments.php — obsługa nadpłat z kont
 * wirtualnych uczestników TI: wykrywanie, rejestr, rozliczanie (zwrot na
 * rachunek, zaliczenie na poczet innej FVAT / przyszłych należności grupy,
 * przeksięgowanie na konto innego uczestnika) i ścieżka audytu.
 *
 * ZASADA: jedno źródło prawdy o pieniądzach to księga k30_ti_payments +
 * k30_ti_billing (includes/ti_payments.php). Rejestr overpayment_transactions
 * opisuje nadpłatę, ale każda dyspozycja zmienia też księgę — w tej samej
 * transakcji PDO, razem z wpisem w audit_logs. Dlatego:
 *   • zwrot = wpis ujemny w k30_ti_payments (method 'refund'),
 *   • zaliczenie = para wpisów wewnętrznych −/+ między „koszykami” (grupami),
 *   • przeksięgowanie = −x u źródłowego, +x u docelowego uczestnika;
 * a kwota dyspozycji nie może przekroczyć nadpłaty wg księgi. Gdy księga sama
 * zużyje nadpłatę (nowa należność pokryta FIFO), ti_op_reconcile() oznacza
 * odpowiednią część rejestru jako zaliczoną automatycznie.
 *
 * „Koszyk” (course_id): nadpłata przypisana do grupy (wpłaty znaczone na grupę)
 * albo ogólna (0) — tak liczy ti_client_allocation().
 * Kwoty: arytmetyka na groszach (int), zapis REAL z 2 miejscami.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

const TI_OP_STATUSES = [
    'available'   => 'do dyspozycji',
    'refunded'    => 'zwrócona',
    'settled'     => 'zaliczona',
    'transferred' => 'przeksięgowana',
];
const TI_OP_SOURCES = ['overpayment_from_fvat' => 'z rozliczenia FVAT', 'manual_adjustment' => 'ręcznie'];
/** Wpisy księgi tworzone przez ten moduł (nie usuwać pojedynczo — ti_payment_delete je pomija). */
const TI_OP_LEDGER_SOURCES = ['overpay_refund', 'overpay_settle', 'overpay_transfer'];

function ti_op_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    ti_payments_migrate();
    audit_logs_migrate();
    db()->exec((string)file_get_contents(dirname(__DIR__) . '/schema.sql'));
    try { db()->exec("ALTER TABLE overpayment_transactions ADD COLUMN edok_doc_id INTEGER"); } catch (\Throwable $e) {}   // zwrot → dokument EODoK
}

// ── Kwoty ────────────────────────────────────────────────────────────────────
function ti_op_gr(float|int|string $zl): int { return (int)round((float)$zl * 100); }
function ti_op_zl(int $gr): float { return round($gr / 100, 2); }
/** Kwota z formularza: „1 234,56” / „1234.5” → grosze; null gdy niepoprawna lub ≤ 0. */
function ti_op_parse_amount(string $raw): ?int {
    $s = str_replace([' ', "\u{00A0}"], '', trim($raw));
    if (!preg_match('/^\d{1,9}([.,]\d{1,2})?$/', $s)) return null;
    $gr = ti_op_gr(str_replace(',', '.', $s));
    return $gr > 0 ? $gr : null;
}
function ti_op_fmt(float $zl): string { return number_format($zl, 2, ',', ' ') . ' zł'; }

// ── Księga ───────────────────────────────────────────────────────────────────
/** Nadpłata wg księgi w koszyku (0 = ogólna), w groszach. */
function ti_op_ledger_credit_gr(array $alloc, int $course_id): int {
    return $course_id === 0 ? ti_op_gr($alloc['general_credit']) : ti_op_gr($alloc['groups'][$course_id]['credit'] ?? 0);
}
/** Zarejestrowana (available) nadpłata w koszyku, w groszach. */
function ti_op_registered_gr(int $client_id, int $course_id): int {
    $r = db_one("SELECT COALESCE(SUM(amount),0) s FROM overpayment_transactions WHERE participant_id=? AND course_id=? AND status='available'",
                [$client_id, $course_id]);
    return ti_op_gr($r['s'] ?? 0);
}
function ti_op_bucket_label(int $course_id): string { return $course_id > 0 ? ti_transfer_group_label($course_id) : 'konto ogólne'; }

/** Wpis w księdze (k30_ti_payments) — zwraca id. */
function ti_op_ledger_add(int $client_id, int $course_id, int $gr, string $method, string $source_type, int $source_id, string $note): int {
    return db_insert('k30_ti_payments', [
        'client_id' => $client_id, 'course_id' => max(0, $course_id), 'amount' => ti_op_zl($gr), 'paid_at' => date('Y-m-d'),
        'method' => $method, 'note' => mb_substr($note, 0, 500), 'source_type' => $source_type, 'source_id' => $source_id,
    ]);
}

/** Migawka salda uczestnika (virtual_account_balances). */
function ti_op_refresh_balance(int $client_id): void {
    $a = ti_client_allocation($client_id);
    $reg = db_one("SELECT COALESCE(SUM(amount),0) s FROM overpayment_transactions WHERE participant_id=? AND status='available'", [$client_id]);
    db()->prepare(
        "INSERT INTO virtual_account_balances (participant_id, balance, overpayment_amount, registered_amount, debt_amount, updated_at)
         VALUES (?, ?, ?, ?, ?, datetime('now'))
         ON CONFLICT(participant_id) DO UPDATE SET balance=excluded.balance, overpayment_amount=excluded.overpayment_amount,
             registered_amount=excluded.registered_amount, debt_amount=excluded.debt_amount, updated_at=excluded.updated_at"
    )->execute([$client_id, round($a['payments'] - $a['charges'], 2), $a['credit'], round((float)($reg['s'] ?? 0), 2), $a['debt']]);
}

/** Rozliczenie źródłowe dla wykrytej nadpłaty: ostatnie z FVAT (numer/skan/produkcyjna) w koszyku, inaczej ostatnie. */
function ti_op_source_billing(int $client_id, int $course_id): array {
    $rows = db_all("SELECT * FROM k30_ti_billing WHERE client_id=? AND status IN ('issued','paid')" . ($course_id > 0 ? " AND course_id=?" : '') . "
                    ORDER BY year DESC, month DESC, id DESC", $course_id > 0 ? [$client_id, $course_id] : [$client_id]);
    foreach ($rows as $b) {
        if (!ti_billing_has_invoice($b)) continue;
        $inv = null;
        try { $inv = db_one("SELECT id FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [(int)$b['id']]); } catch (\Throwable $e) {}
        return ['billing_id' => (int)$b['id'], 'fvat_id' => $inv ? (int)$inv['id'] : null];
    }
    return ['billing_id' => $rows ? (int)$rows[0]['id'] : null, 'fvat_id' => null];
}

/**
 * Wydziela z wpisu available część $gr i nadaje jej status (albo zmienia cały
 * wpis, gdy $gr = całość). Zwraca id wpisu z dyspozycją. Wywoływać w transakcji.
 */
function ti_op_dispose(array $row, int $gr, string $status, array $fields, string $by): int {
    $row_gr = ti_op_gr($row['amount']);
    $fields += ['disposed_at' => date('Y-m-d H:i:s'), 'disposed_by' => $by];
    if ($gr >= $row_gr) {
        $set = ['status=?']; $p = [$status];
        foreach ($fields as $k => $v) { $set[] = "$k=?"; $p[] = $v; }
        $p[] = (int)$row['id'];
        db()->prepare("UPDATE overpayment_transactions SET " . implode(',', $set) . " WHERE id=?")->execute($p);
        return (int)$row['id'];
    }
    db()->prepare("UPDATE overpayment_transactions SET amount=? WHERE id=?")->execute([ti_op_zl($row_gr - $gr), (int)$row['id']]);
    return db_insert('overpayment_transactions', array_merge([
        'participant_id' => (int)$row['participant_id'], 'course_id' => (int)$row['course_id'],
        'fvat_id' => $row['fvat_id'], 'billing_id' => $row['billing_id'], 'amount' => ti_op_zl($gr),
        'source_type' => $row['source_type'], 'status' => $status, 'parent_id' => (int)$row['id'],
        'notes' => $row['notes'], 'created_by' => $by,
    ], $fields));
}

/** Wpisy available koszyka, najstarsze pierwsze (FIFO). */
function ti_op_available_rows(int $client_id, int $course_id): array {
    return db_all("SELECT * FROM overpayment_transactions WHERE participant_id=? AND course_id=? AND status='available' ORDER BY created_at, id",
                  [$client_id, $course_id]);
}

/**
 * Uzgadnia rejestr z księgą dla uczestnika: nowa nadpłata → wpis
 * overpayment_from_fvat; nadpłata zużyta przez księgę (FIFO na nowe należności)
 * → odpowiednia część rejestru „zaliczona automatycznie”. Zwraca liczbę zmian.
 */
function ti_op_reconcile(int $client_id, string $by = 'system', ?int $user_id = null): int {
    ti_op_migrate();
    $a = ti_client_allocation($client_id);
    $buckets = [0 => true];
    foreach ($a['groups'] as $cid => $g) if ((int)$cid > 0) $buckets[(int)$cid] = true;
    foreach (db_all("SELECT DISTINCT course_id FROM overpayment_transactions WHERE participant_id=? AND status='available'", [$client_id]) as $r)
        $buckets[(int)$r['course_id']] = true;
    $changes = 0;
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        foreach (array_keys($buckets) as $cid) {
            $diff = ti_op_ledger_credit_gr($a, $cid) - ti_op_registered_gr($client_id, $cid);
            if ($diff > 0) {
                $src = ti_op_source_billing($client_id, $cid);
                $id  = db_insert('overpayment_transactions', [
                    'participant_id' => $client_id, 'course_id' => $cid, 'fvat_id' => $src['fvat_id'], 'billing_id' => $src['billing_id'],
                    'amount' => ti_op_zl($diff), 'source_type' => 'overpayment_from_fvat', 'status' => 'available',
                    'notes' => 'Wykryto: wpłaty przewyższają należności (' . ti_op_bucket_label($cid) . ')', 'created_by' => $by,
                ]);
                audit_log('overpayments.detected', ['overpayment_id' => $id, 'participant_id' => $client_id, 'course_id' => $cid,
                    'amount' => ti_op_zl($diff), 'billing_id' => $src['billing_id'], 'fvat_id' => $src['fvat_id']], $user_id);
                $changes++;
            } elseif ($diff < 0) {
                // Księga zużyła część nadpłaty — zaliczamy najstarsze wpisy na ostatnią należność
                $need = -$diff;
                $tb = db_one("SELECT id FROM k30_ti_billing WHERE client_id=? AND status IN ('issued','paid')" . ($cid > 0 ? " AND course_id=?" : '') . "
                              ORDER BY year DESC, month DESC, id DESC", $cid > 0 ? [$client_id, $cid] : [$client_id]);
                foreach (ti_op_available_rows($client_id, $cid) as $row) {
                    if ($need <= 0) break;
                    $take = min($need, ti_op_gr($row['amount']));
                    $nid  = ti_op_dispose($row, $take, 'settled', [
                        'settled_against_billing_id' => $tb ? (int)$tb['id'] : null,
                        'notes' => trim(($row['notes'] ?? '') . ' · zaliczono automatycznie (alokacja wpłat na należności)'),
                    ], $by);
                    audit_log('overpayments.auto_settled', ['overpayment_id' => $nid, 'participant_id' => $client_id, 'course_id' => $cid,
                        'amount' => ti_op_zl($take), 'billing_id' => $tb['id'] ?? null], $user_id);
                    $need -= $take; $changes++;
                }
            }
        }
        ti_op_refresh_balance($client_id);
        if ($own) $pdo->commit();
    } catch (\Throwable $e) { if ($own) $pdo->rollBack(); throw $e; }
    return $changes;
}

/** Wykrywanie dla wszystkich uczestników z rozliczeniami/wpłatami. @return array{participants:int, changes:int} */
function ti_op_detect_all(string $by, ?int $user_id = null): array {
    ti_op_migrate();
    $ids = array_map('intval', array_column(db_all(
        "SELECT client_id FROM k30_ti_payments UNION SELECT client_id FROM k30_ti_billing UNION SELECT participant_id FROM overpayment_transactions"
    ), 'client_id'));
    $ch = 0;
    foreach ($ids as $cid) $ch += ti_op_reconcile($cid, $by, $user_id);
    return ['participants' => count($ids), 'changes' => $ch];
}

/** Ręczna rejestracja nadpłaty (np. po korekcie FVAT na minus) — do wysokości niezarejestrowanej nadpłaty z księgi. */
function ti_op_register_manual(int $client_id, int $course_id, int $gr, string $notes, string $by, ?int $user_id = null): int|string {
    ti_op_migrate();
    if (mb_strlen(trim($notes)) < 5) return 'Podaj uzasadnienie (np. numer korekty FVAT).';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $free = ti_op_ledger_credit_gr(ti_client_allocation($client_id), $course_id) - ti_op_registered_gr($client_id, $course_id);
        if ($gr > $free) { $pdo->rollBack(); return 'W księdze uczestnika (' . ti_op_bucket_label($course_id) . ') nie ma tyle niezarejestrowanej nadpłaty — dostępne '
            . ti_op_fmt(ti_op_zl(max(0, $free))) . '. Najpierw zaksięguj wpłatę albo korektę rozliczenia.'; }
        $src = ti_op_source_billing($client_id, $course_id);
        $id = db_insert('overpayment_transactions', [
            'participant_id' => $client_id, 'course_id' => $course_id, 'fvat_id' => $src['fvat_id'], 'billing_id' => $src['billing_id'],
            'amount' => ti_op_zl($gr), 'source_type' => 'manual_adjustment', 'status' => 'available', 'notes' => trim($notes), 'created_by' => $by,
        ]);
        audit_log('overpayments.registered', ['overpayment_id' => $id, 'participant_id' => $client_id, 'course_id' => $course_id, 'amount' => ti_op_zl($gr), 'notes' => trim($notes)], $user_id);
        ti_op_refresh_balance($client_id);
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/** Wspólna walidacja dyspozycji: wpis available, kwota ≤ wpis i ≤ nadpłata w księdze. */
function _ti_op_check(int $id, int $gr): array|string {
    $row = db_one("SELECT * FROM overpayment_transactions WHERE id=?", [$id]);
    if (!$row) return 'Nie znaleziono nadpłaty.';
    if ($row['status'] !== 'available') return 'Ta nadpłata jest już rozliczona (' . (TI_OP_STATUSES[$row['status']] ?? $row['status']) . ').';
    if ($gr > ti_op_gr($row['amount'])) return 'Kwota większa niż nadpłata (' . ti_op_fmt((float)$row['amount']) . ').';
    $ledger = ti_op_ledger_credit_gr(ti_client_allocation((int)$row['participant_id']), (int)$row['course_id']);
    if ($gr > $ledger) return 'Księga uczestnika pokazuje w tym koszyku tylko ' . ti_op_fmt(ti_op_zl($ledger)) . ' nadpłaty — uruchom „Wykryj nadpłaty”, żeby uzgodnić rejestr.';
    return $row;
}

/** 1) Zwrot na rachunek bankowy — polecenie zwrotu + wpis ujemny w księdze. Zwraca id wpisu zwrotu albo błąd. */
/**
 * Fizyczne usunięcie wpisu nadpłaty (tylko „do dyspozycji”, bez wpisów w księdze i bez powiązanych części).
 * Ślad zostaje wyłącznie w audycie. Uwaga: jeśli saldo w księdze nadal ma nadpłatę, „Wykryj nadpłaty”
 * może utworzyć wpis ponownie — wtedy trzeba skorygować wpłatę źródłową.
 * @return string|null komunikat błędu albo null przy sukcesie
 */
function ti_op_delete(int $id, string $reason, string $by, ?int $user_id = null): ?string {
    ti_op_migrate();
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód usunięcia (min. 5 znaków).';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $row = db_one("SELECT * FROM overpayment_transactions WHERE id=?", [$id]);
        if (!$row) { $pdo->rollBack(); return 'Nie znaleziono nadpłaty.'; }
        if ($row['status'] !== 'available') { $pdo->rollBack(); return 'Usunąć można tylko nadpłatę „do dyspozycji” (ta jest: ' . (TI_OP_STATUSES[$row['status']] ?? $row['status']) . ').'; }
        if (trim((string)($row['ledger_payment_ids'] ?? '')) !== '' && $row['ledger_payment_ids'] !== '[]') { $pdo->rollBack(); return 'Wpis ma powiązane wpisy w księdze wpłat — nie można go usunąć.'; }
        if (db_one("SELECT 1 FROM overpayment_transactions WHERE parent_id=?", [$id])) { $pdo->rollBack(); return 'Z tego wpisu wydzielono części (rozliczone) — nie można go usunąć.'; }
        db()->prepare("DELETE FROM overpayment_transactions WHERE id=?")->execute([$id]);
        audit_log('overpayments.deleted', ['overpayment_id' => $id, 'participant_id' => (int)$row['participant_id'], 'course_id' => (int)$row['course_id'],
            'amount' => (float)$row['amount'], 'source_type' => $row['source_type'], 'reason' => trim($reason), 'by' => $by, 'row' => $row], $user_id);
        ti_op_refresh_balance((int)$row['participant_id']);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/**
 * Zwrot nadpłaty → od razu dokument w obiegu akceptacji EODoK (wydatek / przelew na rachunek kursanta).
 * Błąd tu nie cofa zwrotu w księdze — zwraca id dokumentu EODoK albo null (ślad w audycie).
 */
function ti_op_refund_to_edok(int $overpayment_id, int $participant_id, float $amount, string $account, string $title, string $by, ?int $user_id): ?int {
    try {
        require_once dirname(__DIR__, 3) . '/includes/edok.php';
        $uid = $user_id ?: (int)(db_one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")['id'] ?? 0);
        if (!$uid) throw new \RuntimeException('Brak użytkownika do przypisania dokumentu EODoK.');
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$participant_id]);
        $kw = number_format($amount, 2, ',', '');
        $opis = 'Zwrot nadpłaty za zajęcia TI — ' . ($cl['name'] ?? ('uczestnik #' . $participant_id)) . ' (nadpłata #' . $overpayment_id . ')';
        $doc = [
            'number' => edok_next_number(), 'title' => $opis, 'kierunek' => 'wydatek', 'typ_dokumentu' => 'inny', 'description' => $opis,
            'kontrahent_nazwa' => (string)($cl['name'] ?? 'Kursant TI'), 'kontrahent_nip' => '', 'nr_faktury' => 'NADPLATA-' . $overpayment_id,
            'data_wystawienia' => date('Y-m-d'), 'termin_platnosci' => date('Y-m-d', strtotime('+3 days')),
            'kwota_netto' => $kw, 'kwota_vat' => '0,00', 'kwota_brutto' => $kw, 'waluta' => 'PLN', 'rodzaj_dzialalnosci' => '',
            'rachunek_bankowy' => preg_replace('/\D/', '', preg_replace('/^PL/i', '', $account)),
            'status' => 'w_obiegu', 'created_by' => $uid, 'creator_name' => $by . ' (zwrot nadpłaty TI)',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $doc['tytul_przelewu'] = mb_substr(trim($title) !== '' ? trim($title) : $opis, 0, 140);
        $doc_id = db_insert('edok_documents', $doc);
        edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Utworzono automatycznie ze zwrotu nadpłaty TI #' . $overpayment_id . ' (' . $by . '). Uzupełnij dekretację przed kontrolą merytoryczną.', $doc);
        try { db()->prepare("UPDATE overpayment_transactions SET edok_doc_id=? WHERE id=?")->execute([$doc_id, $overpayment_id]); } catch (\Throwable $e) {}
        audit_log('overpayments.refund_edok', ['overpayment_id' => $overpayment_id, 'edok_doc_id' => $doc_id, 'number' => $doc['number']], $user_id);
        return $doc_id;
    } catch (\Throwable $e) {
        audit_log('overpayments.refund_edok_failed', ['overpayment_id' => $overpayment_id, 'error' => $e->getMessage()], $user_id);
        return null;
    }
}

function ti_op_refund(int $id, int $gr, string $account, string $title, string $notes, string $by, ?int $user_id = null): int|string {
    ti_op_migrate();
    $acct = preg_replace('/\s+/', '', $account);
    if (!preg_match('/^(PL)?\d{26}$/i', (string)$acct)) return 'Podaj numer rachunku do zwrotu (26 cyfr, opcjonalnie z PL).';
    if (trim($title) === '') return 'Podaj tytuł przelewu zwrotnego.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $row = _ti_op_check($id, $gr);
        if (is_string($row)) { $pdo->rollBack(); return $row; }
        $cid = (int)$row['participant_id'];
        $pid = ti_op_ledger_add($cid, (int)$row['course_id'], -$gr, 'refund', 'overpay_refund', $id,
            'Zwrot nadpłaty na rachunek ' . substr($acct, -4) . ' — ' . trim($title));
        $nid = ti_op_dispose($row, $gr, 'refunded', ['refund_account' => strtoupper($acct), 'refund_title' => trim($title),
            'ledger_payment_ids' => json_encode([$pid]), 'notes' => trim(($row['notes'] ?? '') . ($notes !== '' ? ' · ' . $notes : ''))], $by);
        db()->prepare("UPDATE k30_ti_payments SET source_id=? WHERE id=?")->execute([$nid, $pid]);
        ti_billing_recompute($cid);
        audit_log('overpayments.refunded', ['overpayment_id' => $nid, 'from_id' => $id, 'participant_id' => $cid, 'amount' => ti_op_zl($gr),
            'account_last4' => substr($acct, -4), 'title' => trim($title), 'ledger_payment_id' => $pid], $user_id);
        ti_op_refresh_balance($cid);
        $pdo->commit();
        return $nid;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/**
 * 2) Zaliczenie na poczet innej FVAT (rozliczenia z niedopłatą) albo przyszłych
 * należności innej grupy — przeniesienie koszyka parą wpisów wewnętrznych.
 */
function ti_op_settle(int $id, int $gr, int $target_billing_id, int $target_course_id, string $notes, string $by, ?int $user_id = null): int|string {
    ti_op_migrate();
    $pdo = db(); $pdo->beginTransaction();
    try {
        $row = _ti_op_check($id, $gr);
        if (is_string($row)) { $pdo->rollBack(); return $row; }
        $cid = (int)$row['participant_id'];
        $fvat = null; $tb = null;
        if ($target_billing_id > 0) {
            $tb = db_one("SELECT * FROM k30_ti_billing WHERE id=? AND client_id=? AND status='issued'", [$target_billing_id, $cid]);
            if (!$tb) { $pdo->rollBack(); return 'Wybierz nieopłacone rozliczenie tego samego uczestnika.'; }
            $paid = 0; foreach (ti_client_allocation($cid)['rows'] as $r) if ($r['id'] === (int)$tb['id']) $paid = ti_op_gr($r['paid']);
            $debt = ti_op_gr((float)$tb['amount'] + (float)$tb['adjustment']) - $paid;
            if ($debt <= 0) { $pdo->rollBack(); return 'To rozliczenie jest już opłacone.'; }
            if ($gr > $debt) { $pdo->rollBack(); return 'Kwota większa niż niedopłata rozliczenia (' . ti_op_fmt(ti_op_zl($debt)) . ').'; }
            $target_course_id = (int)$tb['course_id'];
            try { $inv = db_one("SELECT id FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [(int)$tb['id']]); $fvat = $inv ? (int)$inv['id'] : null; } catch (\Throwable $e) {}
        } elseif ($target_course_id <= 0 || !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$cid, $target_course_id])) {
            $pdo->rollBack(); return 'Wybierz rozliczenie albo grupę uczestnika, na którą zaliczyć nadpłatę.';
        }
        if ($target_course_id === (int)$row['course_id']) { $pdo->rollBack(); return 'Nadpłata jest już w tym koszyku — księga zalicza ją na należności tej grupy automatycznie.'; }
        $lbl = ti_op_bucket_label((int)$row['course_id']) . ' → ' . ti_op_bucket_label($target_course_id)
             . ($tb ? ' (rozliczenie ' . sprintf('%02d/%d', (int)$tb['month'], (int)$tb['year']) . ')' : ' (przyszłe należności)');
        $p1 = ti_op_ledger_add($cid, (int)$row['course_id'], -$gr, 'internal', 'overpay_settle', $id, 'Zaliczenie nadpłaty: ' . $lbl);
        $p2 = ti_op_ledger_add($cid, $target_course_id, $gr, 'internal', 'overpay_settle', $id, 'Zaliczenie nadpłaty: ' . $lbl);
        $nid = ti_op_dispose($row, $gr, 'settled', ['settled_against_billing_id' => $tb ? (int)$tb['id'] : null, 'settled_against_fvat_id' => $fvat,
            'target_course_id' => $target_course_id, 'ledger_payment_ids' => json_encode([$p1, $p2]),
            'notes' => trim(($row['notes'] ?? '') . ' · ' . $lbl . ($notes !== '' ? ' · ' . $notes : ''))], $by);
        db()->prepare("UPDATE k30_ti_payments SET source_id=? WHERE id IN (?, ?)")->execute([$nid, $p1, $p2]);
        ti_billing_recompute($cid);
        audit_log('overpayments.settled', ['overpayment_id' => $nid, 'from_id' => $id, 'participant_id' => $cid, 'amount' => ti_op_zl($gr),
            'target_billing_id' => $tb['id'] ?? null, 'target_fvat_id' => $fvat, 'target_course_id' => $target_course_id, 'ledger_payment_ids' => [$p1, $p2]], $user_id);
        ti_op_refresh_balance($cid);
        $pdo->commit();
        return $nid;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/** 3) Przeksięgowanie na konto wirtualne innego uczestnika (np. rodzeństwo). */
function ti_op_transfer(int $id, int $gr, int $target_participant_id, string $notes, string $by, ?int $user_id = null): int|string {
    ti_op_migrate();
    if (mb_strlen(trim($notes)) < 5) return 'Podaj uzasadnienie przeksięgowania (np. zgoda opiekuna).';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $row = _ti_op_check($id, $gr);
        if (is_string($row)) { $pdo->rollBack(); return $row; }
        $cid = (int)$row['participant_id'];
        $tgt = db_one("SELECT id, name FROM k30_clients WHERE id=?", [$target_participant_id]);
        if (!$tgt || (int)$tgt['id'] === $cid) { $pdo->rollBack(); return 'Wybierz innego uczestnika.'; }
        $src = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid]);
        $p1 = ti_op_ledger_add($cid, (int)$row['course_id'], -$gr, 'internal', 'overpay_transfer', $id, 'Przeksięgowanie nadpłaty na konto: ' . $tgt['name']);
        $p2 = ti_op_ledger_add((int)$tgt['id'], 0, $gr, 'internal', 'overpay_transfer', $id, 'Przeksięgowanie nadpłaty z konta: ' . ($src['name'] ?? ''));
        $nid = ti_op_dispose($row, $gr, 'transferred', ['target_participant_id' => (int)$tgt['id'], 'ledger_payment_ids' => json_encode([$p1, $p2]),
            'notes' => trim(($row['notes'] ?? '') . ' · na konto: ' . $tgt['name'] . ' · ' . trim($notes))], $by);
        $tid = db_insert('overpayment_transactions', [
            'participant_id' => (int)$tgt['id'], 'course_id' => 0, 'amount' => ti_op_zl($gr), 'source_type' => 'manual_adjustment',
            'status' => 'available', 'parent_id' => $nid, 'notes' => 'Przeksięgowano z konta: ' . ($src['name'] ?? '') . ' · ' . trim($notes), 'created_by' => $by,
        ]);
        db()->prepare("UPDATE k30_ti_payments SET source_id=? WHERE id IN (?, ?)")->execute([$nid, $p1, $p2]);
        ti_billing_recompute($cid); ti_billing_recompute((int)$tgt['id']);
        audit_log('overpayments.transferred', ['overpayment_id' => $nid, 'from_id' => $id, 'participant_id' => $cid, 'amount' => ti_op_zl($gr),
            'target_participant_id' => (int)$tgt['id'], 'target_overpayment_id' => $tid, 'ledger_payment_ids' => [$p1, $p2], 'notes' => trim($notes)], $user_id);
        ti_op_refresh_balance($cid);
        // Księga docelowego konta mogła od razu zużyć środki na jego zaległości (FIFO) — uzgodnij rejestr
        ti_op_reconcile((int)$tgt['id'], $by, $user_id);
        $pdo->commit();
        return $nid;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

// ── Odczyt ───────────────────────────────────────────────────────────────────
/** Lista wpisów rejestru z nazwami (do tabeli z filtrowaniem). */
function ti_op_list(): array {
    ti_op_migrate();
    return db_all(
        "SELECT o.*, cl.name AS participant_name, tc.name AS target_name,
                b.month AS b_month, b.year AS b_year, b.invoice_no AS b_invoice_no,
                sb.month AS sb_month, sb.year AS sb_year, sb.invoice_no AS sb_invoice_no
           FROM overpayment_transactions o
           JOIN k30_clients cl ON cl.id=o.participant_id
           LEFT JOIN k30_clients tc ON tc.id=o.target_participant_id
           LEFT JOIN k30_ti_billing b  ON b.id=o.billing_id
           LEFT JOIN k30_ti_billing sb ON sb.id=o.settled_against_billing_id
          ORDER BY o.status='available' DESC, o.created_at DESC, o.id DESC"
    );
}

/** Pełna ścieżka nadpłaty: łańcuch wpisów (od pierwszego do wszystkich wydzielonych) + wpisy audit_logs. */
function ti_op_history(int $id): array {
    ti_op_migrate();
    $root = db_one("SELECT * FROM overpayment_transactions WHERE id=?", [$id]);
    if (!$root) return ['rows' => [], 'audit' => []];
    for ($i = 0; $i < 50 && !empty($root['parent_id']); $i++) {
        $p = db_one("SELECT * FROM overpayment_transactions WHERE id=?", [(int)$root['parent_id']]);
        if (!$p) break; $root = $p;
    }
    $rows = [$root]; $queue = [(int)$root['id']]; $seen = [(int)$root['id'] => true];
    while ($queue) {
        $pid = array_shift($queue);
        foreach (db_all("SELECT * FROM overpayment_transactions WHERE parent_id=? ORDER BY id", [$pid]) as $c) {
            if (isset($seen[(int)$c['id']])) continue;
            $seen[(int)$c['id']] = true; $rows[] = $c; $queue[] = (int)$c['id'];
        }
    }
    $names = [];
    foreach ($rows as &$r) {
        $r['participant_name'] = $names[(int)$r['participant_id']] ??= (string)(db_one("SELECT name FROM k30_clients WHERE id=?", [(int)$r['participant_id']])['name'] ?? '?');
        $r['bucket'] = ti_op_bucket_label((int)$r['course_id']);
    }
    unset($r);
    $audit = [];
    foreach (array_keys($seen) as $oid) {
        foreach (db_all("SELECT * FROM audit_logs WHERE action LIKE 'overpayments.%' AND (details LIKE ? OR details LIKE ?) ORDER BY id",
                        ['%"overpayment_id":' . $oid . ',%', '%"from_id":' . $oid . ',%']) as $a) $audit[(int)$a['id']] = $a;
    }
    ksort($audit);
    return ['rows' => $rows, 'audit' => array_values($audit)];
}

/** Podsumowanie: sumy wg statusu + salda kont (migawka). */
function ti_op_summary(): array {
    ti_op_migrate();
    $by = array_fill_keys(array_keys(TI_OP_STATUSES), 0.0);
    foreach (db_all("SELECT status, COALESCE(SUM(amount),0) s FROM overpayment_transactions GROUP BY status") as $r) $by[$r['status']] = round((float)$r['s'], 2);
    $bal = db_one("SELECT COUNT(*) n, COALESCE(SUM(overpayment_amount),0) op, COALESCE(SUM(registered_amount),0) reg, COALESCE(SUM(debt_amount),0) debt,
                          COALESCE(SUM(CASE WHEN ABS(overpayment_amount-registered_amount)>0.005 THEN 1 ELSE 0 END),0) diff FROM virtual_account_balances");
    return ['by_status' => $by, 'accounts' => (int)$bal['n'], 'ledger_overpayment' => round((float)$bal['op'], 2),
            'registered' => round((float)$bal['reg'], 2), 'debt' => round((float)$bal['debt'], 2), 'unreconciled' => (int)$bal['diff']];
}


/**
 * Zerowanie stanu konta: niedopłata → wpis „umorzenie” (wpłata 'other', source 'writeoff'), nadpłata → wpis
 * rozchodowy (ujemna wpłata 'internal', source 'writeoff'). Historia zostaje; nic nie jest kasowane.
 * $course_id: >0 = jedna grupa, 0 = rozliczenie łączne/konto ogólne, -1 = wszystkie grupy i konto ogólne kursanta.
 * @return array{ok:bool, msg:string, rows:list<array{course_id:int,name:string,debt:float,credit:float}>}
 */
function ti_balance_zero_out(int $client_id, int $course_id, string $reason, string $by, ?int $user_id = null, bool $dry = true): array {
    ti_op_migrate();
    if (!$dry && mb_strlen(trim($reason)) < 5) return ['ok' => false, 'msg' => 'Podaj powód (min. 5 znaków).', 'rows' => []];
    ti_billing_recompute($client_id);
    $gb = ti_client_group_balances($client_id);
    $rows = [];
    foreach ($gb['groups'] as $cid => $g) {
        if ($course_id >= 0 && (int)$cid !== $course_id) continue;
        if ((float)$g['debt'] > 0.005 || (float)$g['credit'] > 0.005)
            $rows[] = ['course_id' => (int)$cid, 'name' => (string)$g['course_name'], 'debt' => round((float)$g['debt'], 2), 'credit' => round((float)$g['credit'], 2)];
    }
    if (($course_id === -1 || $course_id === 0) && (float)$gb['general_credit'] > 0.005 && !array_filter($rows, fn($r) => $r['course_id'] === 0))
        $rows[] = ['course_id' => 0, 'name' => 'Konto ogólne (nadpłata)', 'debt' => 0.0, 'credit' => round((float)$gb['general_credit'], 2)];
    if (!$rows) return ['ok' => true, 'msg' => 'Stan konta jest już zerowy.', 'rows' => []];
    if ($dry) return ['ok' => true, 'msg' => 'Podgląd.', 'rows' => $rows];
    $pdo = db(); $pdo->beginTransaction();
    try {
        $note = trim($reason) . ' (' . $by . ')';
        foreach ($rows as $r) {
            if ($r['debt'] > 0.005)   ti_payment_add($client_id, $r['debt'], date('Y-m-d'), 'other', 'Umorzenie niedopłaty: ' . $note, 'writeoff', 0, $r['course_id']);
            if ($r['credit'] > 0.005) ti_payment_add($client_id, -$r['credit'], date('Y-m-d'), 'internal', 'Wyzerowanie nadpłaty: ' . $note, 'writeoff', 0, $r['course_id']);
        }
        ti_billing_recompute($client_id);
        ti_op_reconcile($client_id, $by, $user_id);
        audit_log('overpayments.zero_out', ['participant_id' => $client_id, 'course_id' => $course_id, 'rows' => $rows, 'reason' => trim($reason), 'by' => $by], $user_id);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Błąd: ' . $e->getMessage(), 'rows' => $rows]; }
    $d = array_sum(array_column($rows, 'debt')); $c = array_sum(array_column($rows, 'credit'));
    return ['ok' => true, 'msg' => 'Wyzerowano: niedopłaty ' . number_format($d, 2, ',', ' ') . ' zł, nadpłaty ' . number_format($c, 2, ',', ' ') . ' zł.', 'rows' => $rows];
}
