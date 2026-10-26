<?php
/**
 * modules/ti_training_contract/logic/trainingContractBilling.php — rozliczenia umowy SZK z księgą rozliczeń TI (includes/ti_payments.php).
 *
 * Harmonogram opłat umowy (TiSzkFees: dane['oplaty']) → należności uczestnika (k30_ti_billing, rozliczenie łączne miesiąca,
 * przez ti_billing_add_charge). Należność uczestnika = jego część: przy finansowaniu publicznym „kwota uczestnika”, inaczej całe
 * wynagrodzenie (reszta to rozliczenie z płatnikiem — poza księgą uczestnika). Księgowanie jest IDEMPOTENTNE: tabela szk_charges
 * pamięta, co już zaksięgowano (lp → miesiąc, rok, kwota), a synchronizacja dopisuje tylko RÓŻNICE (korekty ujemne w starym miesiącu,
 * nowa kwota w nowym) — ponowne uruchomienie niczego nie dubluje. Saldo i wpłaty czyta ti_client_balance().
 */
require_once __DIR__ . '/trainingContractFees.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';

final class TiSzkBilling
{
    public const TABLE = 'szk_charges';
    /** Statusy, od których należności są naliczane automatycznie (setting szk_auto_charges, domyślnie włączone). */
    private const BILL_STATUSES = ['podpisana', 'w realizacji'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id {$pk}, contract_id INTEGER NOT NULL, lp INTEGER NOT NULL, month INTEGER NOT NULL, year INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0, posted_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_szk_charges ON " . self::TABLE . "(contract_id, lp)");
    }

    public static function autoEnabled(): bool
    {
        return org_setting('szk_auto_charges') !== '0';
    }

    /** Część uczestnika w groszach (finansowanie publiczne: kwota uczestnika, jeśli podana; inaczej całość). */
    public static function participantTotalGr(array $c): int
    {
        $d = $c;
        $own = ($d['finansowanie'] ?? '') === 'wlasne';
        $kw = (string)($own ? ($d['wynagrodzenie'] ?? '') : (($d['kwota_kursant'] ?? '') !== '' ? $d['kwota_kursant'] : ($d['wynagrodzenie'] ?? '')));
        return TiSzkFees::gr($kw) ?? 0;
    }

    /**
     * Plan należności uczestnika: [[lp, Y-m-d, grosze], …]. Z harmonogramu opłat (model/raty/termin); bez niego — jedna płatność
     * w terminie z ustawień (data zawarcia + szk_payment_days).
     */
    public static function plan(array $c): array
    {
        $total = self::participantTotalGr($c);
        if ($total <= 0) return [];
        $op = (array)($c['oplaty'] ?? []);
        $model = isset(TiSzkFees::MODELS[$op['model'] ?? '']) ? $op['model'] : 'ryczalt';
        $n = (int)($op['raty'] ?? 1);
        $first = (string)($op['termin'] ?? '');
        if ($first === '') {
            $z = (string)($c['data'] ?? '');
            $first = $z !== '' ? date('Y-m-d', strtotime($z . ' +' . TiSzkFees::settings()['dni'] . ' days')) : date('Y-m-d');
        }
        $plan = array_map(fn($r) => [(int)$r['lp'], (string)$r['termin'], (int)$r['kwota']], TiSzkFees::schedule($model, $total, $n, $first));
        // Rozwiązanie umowy (moduł terminations): raty z terminem po dacie skutku nie są naliczane.
        $eff = self::terminationEffective((int)($c['_row']['id'] ?? 0));
        return $eff === '' ? $plan : array_values(array_filter($plan, fn($r) => $r[1] <= $eff));
    }

    /** Data skutku zaakceptowanego rozwiązania umowy ('' gdy brak). */
    public static function terminationEffective(int $contractId): string
    {
        if ($contractId <= 0) return '';
        try {
            $r = db_one("SELECT effective_date, proposed_date FROM contract_termination_requests WHERE contract_type = 'szk' AND contract_id = ? AND status = 'zaakceptowany' ORDER BY id DESC LIMIT 1", [$contractId]);
        } catch (\Throwable $e) { return ''; }
        return $r ? (string)($r['effective_date'] ?: $r['proposed_date'] ?: '') : '';
    }

    /** Co już zaksięgowano: lp => wiersz szk_charges. */
    public static function posted(int $contractId): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT * FROM " . self::TABLE . " WHERE contract_id = ? ORDER BY lp", [$contractId]) as $r) $out[(int)$r['lp']] = $r;
        return $out;
    }

    /**
     * Synchronizuje należności umowy z jej planem. $clear = true → wycofuje wszystko (anulowana umowa).
     * Zwraca ['posted' => liczba operacji w księdze, 'error' => ''].
     */
    public static function sync(int $id, bool $clear = false): array
    {
        $c = TiTrainingContractStore::get($id);
        if (!$c) return ['posted' => 0, 'error' => 'Nie ma takiej umowy.'];
        $row = $c['_row'];
        $client = (int)$row['client_id'];
        if ($client <= 0) return ['posted' => 0, 'error' => 'Umowa nie jest powiązana z uczestnikiem z karty TI — wybierz uczestnika w formularzu.'];
        if ($row['numer_umowy'] === '') return ['posted' => 0, 'error' => 'Najpierw zarejestruj umowę (numer SZK).'];
        if ((int)$row['is_test'] === 1) return ['posted' => 0, 'error' => 'Umowy testowej nie księgujemy w rozliczeniach.'];
        self::migrate();
        ti_payments_migrate();
        $label = $row['numer_umowy'];
        $plan = $clear ? [] : self::plan($c);
        $want = [];
        foreach ($plan as [$lp, $date, $gr]) $want[$lp] = ['m' => (int)date('n', strtotime($date)), 'y' => (int)date('Y', strtotime($date)), 'a' => round($gr / 100, 2)];
        $have = self::posted($id);
        $ops = 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $lp) {
                $w = $want[$lp] ?? null;
                $h = $have[$lp] ?? null;
                $n = count($want);
                if ($h && (!$w || $w['m'] !== (int)$h['month'] || $w['y'] !== (int)$h['year'])) {      // wycofanie starej pozycji
                    if (abs((float)$h['amount']) > 0.004) { ti_billing_add_charge($client, -(float)$h['amount'], "Korekta {$label}: rata {$lp}", (int)$h['month'], (int)$h['year']); $ops++; }
                    db_exec("DELETE FROM " . self::TABLE . " WHERE id = ?", [(int)$h['id']]);
                    $h = null;
                }
                if (!$w) continue;
                $delta = round($w['a'] - ($h ? (float)$h['amount'] : 0.0), 2);
                if (abs($delta) < 0.004 && $h) continue;
                if (abs($delta) >= 0.004) {
                    ti_billing_add_charge($client, $delta, ($h ? "Korekta {$label}: rata {$lp}/{$n}" : "{$label}: rata {$lp}/{$n}"), $w['m'], $w['y']);
                    $ops++;
                }
                if ($h) db_update(self::TABLE, ['amount' => $w['a'], 'posted_at' => date('Y-m-d H:i:s')], (int)$h['id']);
                else db_insert(self::TABLE, ['contract_id' => $id, 'lp' => $lp, 'month' => $w['m'], 'year' => $w['y'], 'amount' => $w['a'], 'posted_at' => date('Y-m-d H:i:s')]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['posted' => 0, 'error' => 'Błąd księgowania: ' . $e->getMessage()];
        }
        return ['posted' => $ops, 'error' => ''];
    }

    /** Zmiana statusu umowy: podpisana / w realizacji → naliczenie (gdy włączone), anulowana → wycofanie. Błędów nie rzuca. */
    public static function onStatusChange(int $id, string $new): void
    {
        try {
            if ($new === 'anulowana') { if (self::posted($id)) self::sync($id, true); return; }
            if ($new === 'rozwiązana') { if (self::posted($id)) self::sync($id); return; }
            if (self::autoEnabled() && in_array($new, self::BILL_STATUSES, true)) self::sync($id);
        } catch (\Throwable $e) { error_log('[TiSzkBilling] ' . $e->getMessage()); }
    }

    /** Podsumowanie uczestnika z księgi: wpłaty, należności, saldo, nadpłata, niedopłata. */
    public static function balance(int $clientId): ?array
    {
        if ($clientId <= 0) return null;
        try { ti_payments_migrate(); return ti_client_balance($clientId); } catch (\Throwable $e) { return null; }
    }

    // ── Raty ze statusami (prosty ekran rozliczeń) ──────────────────────────────

    /**
     * Raty umowy ze statusem: zaplacona | czesciowo | po_terminie | do_zaplaty | niezaksiegowana.
     * Wpłacone liczymy z wiersza rozliczenia łącznego miesiąca (k30_ti_billing, course_id=0), proporcjonalnie do udziału raty
     * w należnościach tego wiersza (gdy w miesiącu są też inne opłaty). @return list<array>
     */
    public static function installments(array $c): array
    {
        $id = (int)($c['_row']['id'] ?? 0);
        $client = (int)($c['_row']['client_id'] ?? 0);
        $posted = $id ? self::posted($id) : [];
        $out = [];
        $today = date('Y-m-d');
        foreach (self::plan($c) as [$lp, $date, $gr]) {
            $amount = $gr / 100;
            $p = $posted[$lp] ?? null;
            $status = 'niezaksiegowana';
            $paid = 0.0;
            if ($p && $client > 0) {
                $b = db_one("SELECT amount, adjustment, paid_amount FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND COALESCE(course_id,0) = 0 AND status != 'cancelled'",
                            [$client, (int)$p['month'], (int)$p['year']]);
                $total = $b ? (float)$b['amount'] + (float)$b['adjustment'] : 0.0;
                if ($b && $total > 0.004) $paid = min($amount, round($amount * min(1.0, (float)$b['paid_amount'] / $total), 2));
                $status = $paid >= $amount - 0.004 ? 'zaplacona' : ($paid > 0.004 ? 'czesciowo' : ($date < $today ? 'po_terminie' : 'do_zaplaty'));
                if ($status === 'czesciowo' && $date < $today) $status = 'czesciowo';
            }
            $out[] = ['lp' => $lp, 'termin' => $date, 'kwota' => $amount, 'wplacono' => $paid, 'status' => $status, 'zaksiegowano' => (bool)$p];
        }
        return $out;
    }

    public const STATUS_LABELS = [
        'zaplacona' => ['Zapłacona', 'text-bg-success'], 'czesciowo' => ['Częściowo', 'text-bg-warning'], 'po_terminie' => ['Po terminie', 'text-bg-danger'],
        'do_zaplaty' => ['Do zapłaty', 'text-bg-primary'], 'niezaksiegowana' => ['Nie zaksięgowana', 'text-bg-secondary'],
    ];

    /** Konto główne i subkonta (grupy) uczestnika z księgi rozliczeń. @return array{groups:array,general_credit:float,total:array}|null */
    public static function accounts(int $clientId): ?array
    {
        if ($clientId <= 0) return null;
        try { ti_payments_migrate(); return ti_client_group_balances($clientId); } catch (\Throwable $e) { return null; }
    }

    /** Wpłata uczestnika: course_id 0 = konto ogólne, >0 = subkonto grupy. Zwraca '' albo komunikat. */
    public static function pay(int $contractId, float $amount, string $date, string $method, int $courseId, string $note): string
    {
        $c = TiTrainingContractStore::get($contractId);
        if (!$c || (int)$c['_row']['client_id'] <= 0) return 'Umowa nie jest powiązana z uczestnikiem z karty TI.';
        if ($amount <= 0 || $amount > 1000000) return 'Kwota wpłaty musi być większa od 0.';
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Nieprawidłowa data wpłaty.';
        if (!in_array($method, ['transfer', 'cash', 'other'], true)) $method = 'transfer';
        try {
            ti_payment_add((int)$c['_row']['client_id'], $amount, $date, $method, trim($note) !== '' ? $note : 'Wpłata do umowy ' . $c['_row']['numer_umowy'], 'manual', 0, max(0, $courseId));
        } catch (\Throwable $e) { return 'Nie udało się zaksięgować wpłaty: ' . $e->getMessage(); }
        return '';
    }

    /** Przenosi nadpłatę z subkonta grupy ($fromCourse > 0) na ratę $lp umowy (rozliczenie łączne jej miesiąca). Zwraca '' albo komunikat. */
    public static function transferCredit(int $contractId, int $lp, int $fromCourse, float $amount, string $by): string
    {
        $c = TiTrainingContractStore::get($contractId);
        if (!$c) return 'Nie ma takiej umowy.';
        if ($fromCourse <= 0) return 'Wybierz subkonto (grupę), z którego przenosimy nadpłatę.';
        $p = self::posted($contractId)[$lp] ?? null;
        if (!$p) return 'Rata nie jest zaksięgowana w rozliczeniach — najpierw zsynchronizuj należności.';
        $b = db_one("SELECT id FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND COALESCE(course_id,0) = 0 AND status IN ('issued','paid')",
                    [(int)$c['_row']['client_id'], (int)$p['month'], (int)$p['year']]);
        if (!$b) return 'Nie znaleziono rozliczenia miesiąca tej raty.';
        try { return ti_transfer_credit((int)$b['id'], $fromCourse, $amount, $by) ?? ''; }
        catch (\Throwable $e) { return 'Błąd przeniesienia: ' . $e->getMessage(); }
    }

    // ── Rozliczenie z płatnikiem (PFRON / MOPS …) ──────────────────────────────

    public static function migratePayer(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS szk_payer_entries (
            id {$pk}, contract_id INTEGER NOT NULL, amount REAL NOT NULL DEFAULT 0, paid_at VARCHAR(10),
            note VARCHAR(255) NOT NULL DEFAULT '', created_by VARCHAR(190) NOT NULL DEFAULT '', created_at VARCHAR(19)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_szk_payer_contract ON szk_payer_entries(contract_id)");
    }

    /** Należność płatnika w groszach (kwota płatnika z umowy przy finansowaniu publicznym; przy własnym — 0). */
    public static function payerDueGr(array $c): int
    {
        if (($c['finansowanie'] ?? '') === 'wlasne') return 0;
        return TiSzkFees::gr((string)($c['kwota_platnik'] ?? '')) ?? 0;
    }

    public static function payerEntries(int $contractId): array
    {
        self::migratePayer();
        return db_all("SELECT * FROM szk_payer_entries WHERE contract_id = ? ORDER BY paid_at DESC, id DESC", [$contractId]);
    }

    /** @return array{due:float,paid:float,left:float} */
    public static function payerBalance(array $c): array
    {
        $id = (int)($c['_row']['id'] ?? 0);
        $due = self::payerDueGr($c) / 100;
        $paid = 0.0;
        if ($id) foreach (self::payerEntries($id) as $e) $paid += (float)$e['amount'];
        return ['due' => $due, 'paid' => round($paid, 2), 'left' => round($due - $paid, 2)];
    }

    public static function payerAdd(int $contractId, float $amount, string $date, string $note, string $by): string
    {
        if ($amount <= 0 || $amount > 1000000) return 'Kwota wpłaty płatnika musi być większa od 0.';
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Podaj datę wpłaty płatnika.';
        self::migratePayer();
        db_insert('szk_payer_entries', ['contract_id' => $contractId, 'amount' => round($amount, 2), 'paid_at' => $date, 'note' => mb_substr(trim($note), 0, 255), 'created_by' => mb_substr($by, 0, 190), 'created_at' => date('Y-m-d H:i:s')]);
        return '';
    }

    public static function payerDelete(int $id, int $contractId): void
    {
        self::migratePayer();
        db_exec("DELETE FROM szk_payer_entries WHERE id = ? AND contract_id = ?", [$id, $contractId]);
    }
}
