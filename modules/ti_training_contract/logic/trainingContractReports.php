<?php
/**
 * modules/ti_training_contract/logic/trainingContractReports.php — podmenu Umowy SZK: zestawienia przekrojowe
 * (Zmiany w umowach, Umowy z nadpłatami, Lista zapisów, Lista wypowiedzeń, Notatki do umów, Płatnicy).
 * Każde zestawienie zwraca ['cols' => [nagłówki], 'rows' => [[komórki jako gotowy, ZABEZPIECZONY HTML]], 'note' => opis].
 * Tylko odczyt, poza notatkami (tabela szk_notes).
 */
require_once __DIR__ . '/trainingContractStore.php';

final class TiSzkReports
{
    public const VIEWS = [
        'zmiany'       => ['Zmiany w umowach',   'bi-clock-history',   'Historia zdarzeń i aneksy do umów szkoleniowych (najnowsze pierwsze).'],
        'nadplaty'     => ['Umowy z nadpłatami', 'bi-cash-coin',       'Uczestnicy z umową SZK, którzy mają nadpłatę na koncie rozliczeń TI.'],
        'zapisy'       => ['Lista zapisów',      'bi-people',          'Zapisy uczestników z umową SZK na grupy zajęć; umowy bez zapisu są wyróżnione.'],
        'wypowiedzenia'=> ['Lista wypowiedzeń',  'bi-file-earmark-x',  'Wnioski o rozwiązanie umów SZK oraz umowy w statusie rozwiązanej.'],
        'notatki'      => ['Notatki do umów',    'bi-sticky',          'Wewnętrzne notatki kadry do umów szkoleniowych (niewidoczne dla uczestnika).'],
        'platnicy'     => ['Płatnicy',           'bi-bank',            'Podmioty finansujące szkolenia (np. PFRON, MOPS) z liczbą umów i kwotami.'],
    ];

    public static function migrateNotes(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS szk_notes (
            id {$pk}, contract_id INTEGER NOT NULL, body TEXT NOT NULL, author VARCHAR(190) NOT NULL DEFAULT '',
            pinned INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(19)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_szk_notes_contract ON szk_notes(contract_id)");
    }

    private static function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    private static function d(?string $s, bool $time = false): string { return $s && strtotime($s) ? date($time ? 'd.m.Y H:i' : 'd.m.Y', strtotime($s)) : ''; }
    private static function link(array $c): string
    {
        $t = ($c['numer_umowy'] ?? '') !== '' ? $c['numer_umowy'] : 'projekt #' . $c['id'];
        return '<a href="' . self::e(APP_URL) . '/contracts/szk/view.php?id=' . (int)$c['id'] . '">' . self::e($t) . '</a>';
    }
    private static function money(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }
    private static function num(string $s): float { $x = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $s)); return $x === '' ? 0.0 : (float)$x; }

    /** @return array<int,array> id => wiersz umowy (bez testowych) */
    private static function contracts(): array
    {
        TiTrainingContractStore::migrate();
        $out = [];
        $where = function_exists('contract_access_where') ? contract_access_where('szk') : '1=1';
        foreach (db_all("SELECT * FROM " . TiTrainingContractStore::TABLE . " WHERE is_test = 0 AND {$where} ORDER BY id DESC") as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    public static function build(string $view): array
    {
        $m = match ($view) {
            'zmiany' => 'zmiany', 'nadplaty' => 'nadplaty', 'zapisy' => 'zapisy', 'wypowiedzenia' => 'wypowiedzenia',
            'notatki' => 'notatki', 'platnicy' => 'platnicy', default => 'zmiany',
        };
        return self::$m(self::contracts());
    }

    private static function zmiany(array $cs): array
    {
        $rows = [];
        if ($cs) {
            foreach (db_all("SELECT * FROM contract_audit_log WHERE contract_type = 'szk' ORDER BY id DESC LIMIT 400") as $l) {
                $c = $cs[(int)$l['contract_id']] ?? null;
                if (!$c) continue;
                $rows[] = [$l['created_at'], [self::d($l['created_at'], true), self::link($c), self::e($c['imie_nazwisko']), self::e($l['action']), self::e($l['note']), self::e($l['user_snapshot'])]];
            }
            try {
                foreach (db_all("SELECT * FROM contract_amendments WHERE contract_type = 'szk' ORDER BY id DESC LIMIT 200") as $a) {
                    $c = $cs[(int)$a['contract_id']] ?? null;
                    if (!$c) continue;
                    $rows[] = [(string)$a['requested_at'], [self::d($a['requested_at'], true), self::link($c), self::e($c['imie_nazwisko']), 'aneks ' . self::e($a['numer_aneksu']) . ' (' . self::e($a['status']) . ')', self::e($a['opis_zmian']), '']];
                }
            } catch (\Throwable $e) {}
        }
        usort($rows, fn($x, $y) => strcmp((string)$y[0], (string)$x[0]));
        return ['cols' => ['Kiedy', 'Umowa', 'Uczestnik', 'Zdarzenie', 'Opis', 'Kto'], 'rows' => array_map(fn($r) => $r[1], array_slice($rows, 0, 300)), 'note' => ''];
    }

    private static function nadplaty(array $cs): array
    {
        $rows = [];
        $note = '';
        try {
            require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
            ti_payments_migrate();
            $seen = [];
            foreach ($cs as $c) {
                $cl = (int)$c['client_id'];
                if ($cl <= 0 || isset($seen[$cl])) continue;
                $seen[$cl] = true;
                $b = ti_client_balance($cl);
                if ((float)$b['credit'] <= 0.004) continue;
                $rows[] = [(float)$b['credit'], [self::link($c), self::e($c['imie_nazwisko']), self::money((float)$b['credit']), self::money((float)$b['general_credit']), self::money((float)$b['group_credit']), self::money((float)$b['payments']), self::money((float)$b['charges'])]];
            }
        } catch (\Throwable $e) { $note = 'Nie udało się odczytać rozliczeń TI: ' . self::e($e->getMessage()); }
        usort($rows, fn($x, $y) => $y[0] <=> $x[0]);
        return ['cols' => ['Umowa', 'Uczestnik', 'Nadpłata', 'w tym ogólna', 'w tym w grupach', 'Wpłaty', 'Należności'], 'rows' => array_column($rows, 1), 'note' => $note];
    }

    private static function zapisy(array $cs): array
    {
        $rows = [];
        foreach ($cs as $c) {
            $cl = (int)$c['client_id'];
            $gid = (int)($c['grupa_id'] ?? 0);
            $en = $cl > 0 ? db_all("SELECT e.*, COALESCE(NULLIF(k.display_name,''), k.name) AS gname, k.group_code FROM k30_ti_enrollments e LEFT JOIN k30_ti_courses k ON k.id = e.course_id WHERE e.client_id = ? ORDER BY e.id DESC", [$cl]) : [];
            if ($gid > 0 && $en && !array_filter($en, fn($e) => (int)$e['course_id'] === $gid)) {
                $rows[] = [self::link($c), self::e($c['imie_nazwisko']), '<span class="text-warning">brak zapisu w grupie z umowy</span>', '', '', ''];
            }
            if (!$en) { $rows[] = [self::link($c), self::e($c['imie_nazwisko']), '<span class="text-warning">brak zapisu' . ($cl > 0 ? '' : ' (uczestnik niepowiązany z kartą TI)') . '</span>', '', '', '']; continue; }
            foreach ($en as $e) $rows[] = [self::link($c), self::e($c['imie_nazwisko']), self::e((string)$e['gname']) . ($e['group_code'] ? ' <span class="text-muted small">' . self::e($e['group_code']) . '</span>' : ''), self::e((string)$e['status']), self::d($e['start_date']), self::d($e['end_date'])];
        }
        return ['cols' => ['Umowa', 'Uczestnik', 'Grupa', 'Status zapisu', 'Od', 'Do'], 'rows' => $rows, 'note' => ''];
    }

    private static function wypowiedzenia(array $cs): array
    {
        $rows = [];
        $seen = [];
        try {
            foreach (db_all("SELECT * FROM contract_termination_requests WHERE contract_type = 'szk' ORDER BY id DESC") as $t) {
                $c = $cs[(int)$t['contract_id']] ?? null;
                if (!$c) continue;
                $seen[(int)$c['id']] = true;
                $rows[] = [self::link($c), self::e($c['imie_nazwisko']), self::e((string)$t['status']), self::e((string)($t['initiator'] ?? '')) . ' ' . self::e((string)($t['variant'] ?? '')), self::d($t['created_at']), self::d($t['effective_date'] ?: $t['proposed_date']), self::e((string)$t['powod'])];
            }
        } catch (\Throwable $e) {}
        foreach ($cs as $c) {
            if (isset($seen[(int)$c['id']]) || !in_array((string)$c['status'], ['rozwiązana'], true)) continue;
            $rows[] = [self::link($c), self::e($c['imie_nazwisko']), 'rozwiązana (status umowy)', '', self::d($c['data_wypowiedzenia'] ?? ''), '', self::e((string)($c['powod_wypowiedzenia'] ?? ''))];
        }
        return ['cols' => ['Umowa', 'Uczestnik', 'Status', 'Inicjator / wariant', 'Złożono', 'Skutek od', 'Powód'], 'rows' => $rows, 'note' => ''];
    }

    private static function notatki(array $cs): array
    {
        self::migrateNotes();
        $rows = [];
        foreach (db_all("SELECT * FROM szk_notes ORDER BY pinned DESC, id DESC LIMIT 500") as $n) {
            $c = $cs[(int)$n['contract_id']] ?? null;
            if (!$c) continue;
            $rows[] = [self::link($c), self::e($c['imie_nazwisko']), ((int)$n['pinned'] ? '<i class="bi bi-pin-angle-fill text-warning" title="przypięta"></i> ' : '') . nl2br(self::e($n['body'])), self::e($n['author']), self::d($n['created_at'], true)];
        }
        return ['cols' => ['Umowa', 'Uczestnik', 'Notatka', 'Autor', 'Kiedy'], 'rows' => $rows, 'note' => ''];
    }

    private static function platnicy(array $cs): array
    {
        $g = [];
        foreach ($cs as $c) {
            $d = json_decode((string)$c['dane'], true) ?: [];
            if (($d['finansowanie'] ?? '') === 'osoba_trzecia') continue;   // rodzic / osoba prywatna — nie podmiot finansujący
            $name = trim((string)($c['platnik_nazwa'] ?: ($d['platnik'] ?? '')));
            if ($name === '') continue;
            $k = mb_strtolower($name);
            $g[$k] ??= ['name' => $name, 'n' => 0, 'active' => 0, 'sum' => 0.0, 'payer' => 0.0, 'paid' => 0.0];
            $g[$k]['n']++;
            if (in_array((string)$c['status'], ['podpisana', 'w realizacji'], true)) $g[$k]['active']++;
            $g[$k]['sum'] += self::num((string)($c['kwota_umowy'] ?: ($d['wynagrodzenie'] ?? '')));
            $g[$k]['payer'] += self::num((string)($d['kwota_platnik'] ?? ''));
            try { foreach (db_all("SELECT amount FROM szk_payer_entries WHERE contract_id = ?", [(int)$c['id']]) as $e) $g[$k]['paid'] += (float)$e['amount']; } catch (\Throwable $e) {}
        }
        uasort($g, fn($a, $b) => $b['n'] <=> $a['n']);
        $rows = array_map(fn($x) => [self::e($x['name']), (string)$x['n'], (string)$x['active'], self::money($x['sum']), self::money($x['payer']), self::money($x['paid']), self::money($x['payer'] - $x['paid']), self::money($x['sum'] - $x['payer'])], array_values($g));
        return ['cols' => ['Płatnik', 'Umowy', 'w realizacji', 'Wartość umów', 'Kwota płatnika', 'Wpłacił płatnik', 'Płatnik winien', 'Dopłata uczestników'], 'rows' => $rows, 'note' => ''];
    }

    public static function addNote(int $contractId, string $body, string $by, bool $pinned = false): string
    {
        $body = trim($body);
        if ($body === '') return 'Wpisz treść notatki.';
        if (mb_strlen($body) > 4000) return 'Notatka jest za długa (maks. 4000 znaków).';
        if (!db_one("SELECT 1 FROM " . TiTrainingContractStore::TABLE . " WHERE id = ?", [$contractId])) return 'Nie ma takiej umowy.';
        self::migrateNotes();
        db_insert('szk_notes', ['contract_id' => $contractId, 'body' => $body, 'author' => mb_substr($by, 0, 190), 'pinned' => $pinned ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')]);
        return '';
    }

    public static function notesFor(int $contractId): array
    {
        self::migrateNotes();
        return db_all("SELECT * FROM szk_notes WHERE contract_id = ? ORDER BY pinned DESC, id DESC", [$contractId]);
    }

    public static function deleteNote(int $id): void
    {
        self::migrateNotes();
        db_exec("DELETE FROM szk_notes WHERE id = ?", [$id]);
    }
}
