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
        return array_map(fn($r) => [(int)$r['lp'], (string)$r['termin'], (int)$r['kwota']], TiSzkFees::schedule($model, $total, $n, $first));
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
            if (self::autoEnabled() && in_array($new, self::BILL_STATUSES, true)) self::sync($id);
        } catch (\Throwable $e) { error_log('[TiSzkBilling] ' . $e->getMessage()); }
    }

    /** Podsumowanie uczestnika z księgi: wpłaty, należności, saldo, nadpłata, niedopłata. */
    public static function balance(int $clientId): ?array
    {
        if ($clientId <= 0) return null;
        try { ti_payments_migrate(); return ti_client_balance($clientId); } catch (\Throwable $e) { return null; }
    }
}
