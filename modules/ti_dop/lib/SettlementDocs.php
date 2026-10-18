<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/SettlementDocs.php — dokumenty rozliczeniowe uczestnika TI:
 *
 *   PRZ-P — ustalenie stanu początkowego (per grupa albo całe konto): godziny wykorzystane i opłacone przed startem
 *           rozliczeń w SZO oraz saldo pieniężne na dzień (nadpłata → wpis w księdze, zaległość → należność).
 *           Wymagany (zatwierdzony) przed PRZ dla tego uczestnika i grupy (albo całego konta).
 *   PRZ   — ręczne przeliczenie godzin per miesiąc i grupa: faktyczne godziny × stawka → „faktycznie rozliczaj”:
 *           wystawienie / zmiana należności k30_ti_billing jako kwota ręczna (manual_amount — przeliczenie jej nie nadpisze).
 *           Miesięcy z fakturą nie zmieniamy (kwota dokumentu księgowego musi zostać) — PRZ zgłasza błąd.
 *   RKO   — rozliczenie końcowe: grupa (godziny, należności, wpłaty, saldo grupy) albo całe konto (wszystkie grupy,
 *           licencje i usługi do cofnięcia — AMZ, saldo końcowe) → nadpłata (DOP1) albo kwota do dopłaty.
 * Numer: {RKO|PRZ|PRZ-P}/{ID uczestnika}{6 losowych cyfr}-{nr wniosku}; bez wniosku: PRZ-P → SALDPocz, RKO → SALDKonc, PRZ → GODZ.
 * Obieg do wyboru przy dokumencie: 'approval' — składa biuro, zatwierdza kierownik (stempel), wykonanie i PDF e-mailem do
 * kursanta i rodzica; 'direct' — bez akceptacji: wystawia biuro z podpisem, wykonanie od razu, dokument tylko do akt (teczka).
 * Każdy dokument końcowy trafia do teczki „Dokumenty kursanta”.
 */
require_once __DIR__ . '/dopSchema.php';
require_once __DIR__ . '/RopStatement.php';
require_once __DIR__ . '/DopService.php';

function settleMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    dopMigrate();
    if (function_exists('szo_schema_current') && szo_schema_current('ti_dop_settle_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    db()->exec("CREATE TABLE IF NOT EXISTS settlement_docs (
        id {$pk},
        kind VARCHAR(6) NOT NULL DEFAULT '',
        number VARCHAR(80) NOT NULL DEFAULT '',
        client_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL DEFAULT 0,
        scope VARCHAR(8) NOT NULL DEFAULT 'group',
        mode VARCHAR(10) NOT NULL DEFAULT 'approval',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        data TEXT,
        result TEXT,
        request_number VARCHAR(60) NOT NULL DEFAULT '',
        pdf_path VARCHAR(255) NOT NULL DEFAULT '',
        sent TEXT,
        folder_file_id INTEGER,
        created_by INTEGER, created_name VARCHAR(190) NOT NULL DEFAULT '', created_ip VARCHAR(64) NOT NULL DEFAULT '', created_at VARCHAR(19),
        decided_by INTEGER, decided_name VARCHAR(190) NOT NULL DEFAULT '', decided_ip VARCHAR(64) NOT NULL DEFAULT '', decided_at VARCHAR(19), decision_note TEXT,
        applied_at VARCHAR(19)
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_settle_number ON settlement_docs(number)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_settle_client ON settlement_docs(client_id, kind, course_id, status)");
}

enum SettleKind: string
{
    case Przp = 'PRZ-P';
    case Prz  = 'PRZ';
    case Rko  = 'RKO';
    case Rm   = 'RM';
    case Rr   = 'RR';
    case Rmr  = 'RMR';
    case Rrr  = 'RRR';
    case Rks  = 'RKS';
    case Sub1 = 'SUB1';

    public function title(): string
    {
        return match ($this) {
            self::Przp => 'Ustalenie stanu początkowego',
            self::Prz  => 'Ręczne przeliczenie godzin i rozliczenie',
            self::Rko  => 'Rozliczenie końcowe',
            self::Rm   => 'Rozliczenie miesięczne',
            self::Rr   => 'Rozliczenie roczne',
            self::Rmr  => 'Rozliczenie miesięczne ręczne',
            self::Rrr  => 'Rozliczenie roczne ręczne',
            self::Rks  => 'Ręczne księgowanie środków',
            self::Sub1 => 'Nowa procedura subkonta — wyczyszczenie historii i księgowanie ręczne',
        };
    }

    public function suffix(): string
    {
        return match ($this) { self::Przp => 'SALDPocz', self::Rko => 'SALDKonc', self::Prz => 'GODZ', self::Rm, self::Rmr => 'MIES', self::Rr, self::Rrr => 'ROK', self::Rks => 'KSIEG', self::Sub1 => 'NOWAPROC' };
    }

    public function color(): string
    {
        return match ($this) { self::Przp => '#647687', self::Prz => '#fa6800', self::Rko => '#a20025', self::Rm => '#00aba9', self::Rr => '#0050ef', self::Rmr => '#d80073', self::Rrr => '#6a00ff', self::Rks => '#008a00', self::Sub1 => '#825a2c' };
    }

    /** Wyliczane z księgi (RM, RR) vs wpisywane ręcznie (RMR, RRR, PRZ, PRZ-P). */
    public function automatic(): bool
    {
        return in_array($this, [self::Rm, self::Rr, self::Rko], true);
    }
}

final class SettlementDocs
{
    private static function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
    private static function zl(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }

    public static function get(int $id): ?array
    {
        settleMigrate();
        $r = db_one("SELECT s.*, c.name AS client_name FROM settlement_docs s JOIN k30_clients c ON c.id = s.client_id WHERE s.id = ?", [$id]);
        if (!$r) return null;
        $r['kindEnum'] = SettleKind::from((string)$r['kind']);
        $r['d'] = json_decode((string)$r['data'], true) ?: [];
        $r['res'] = json_decode((string)($r['result'] ?? ''), true) ?: [];
        $r['sentList'] = json_decode((string)($r['sent'] ?? ''), true) ?: [];
        return $r;
    }

    public static function search(array $f = []): array
    {
        settleMigrate();
        $w = []; $p = [];
        if (!empty($f['client'])) { $w[] = 's.client_id = ?'; $p[] = (int)$f['client']; }
        if (($k = SettleKind::tryFrom((string)($f['kind'] ?? '')))) { $w[] = 's.kind = ?'; $p[] = $k->value; }
        if (($st = (string)($f['status'] ?? '')) !== '') { $w[] = 's.status = ?'; $p[] = $st; }
        return db_all("SELECT s.*, c.name AS client_name FROM settlement_docs s JOIN k30_clients c ON c.id = s.client_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY s.id DESC LIMIT 300", $p);
    }

    public static function courses(int $clientId): array
    {
        return db_all("SELECT c.id, c.name, c.display_name, c.group_code, e.hourly_rate, e.start_date, e.end_date, c.closed_at FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id WHERE e.client_id = ? ORDER BY c.is_active DESC, c.name", [$clientId]);
    }

    public static function courseName(int $courseId): string
    {
        if ($courseId <= 0) return 'całe konto';
        $c = db_one("SELECT name, display_name, group_code FROM k30_ti_courses WHERE id = ?", [$courseId]);
        return $c ? ((trim((string)$c['display_name']) !== '' ? $c['display_name'] : $c['name']) . (trim((string)$c['group_code']) !== '' ? ' (' . $c['group_code'] . ')' : '')) : 'grupa #' . $courseId;
    }

    /** Zatwierdzony / wykonany PRZ-P dla uczestnika i grupy (albo całego konta, course 0). */
    public static function baseline(int $clientId, int $courseId): ?array
    {
        settleMigrate();
        $r = db_one("SELECT id FROM settlement_docs WHERE kind = 'PRZ-P' AND client_id = ? AND course_id IN (?, 0) AND status = 'applied' ORDER BY CASE WHEN course_id = ? THEN 0 ELSE 1 END, id DESC LIMIT 1", [$clientId, $courseId, $courseId]);
        return $r ? self::get((int)$r['id']) : null;
    }

    private static function number(SettleKind $k, int $clientId, string $req): string
    {
        for ($i = 0; $i < 20; $i++) {
            $n = $k->value . '/' . $clientId . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '-' . ($req !== '' ? $req : $k->suffix());
            if (!db_one("SELECT id FROM settlement_docs WHERE number = ?", [$n])) return $n;
        }
        throw new RuntimeException('Nie udało się nadać numeru.');
    }

    // ── Dane wejściowe (walidacja) ──────────────────────────────────────────────

    private static function num(string $v): ?float
    {
        $v = str_replace([' ', ','], ['', '.'], trim($v));
        return $v === '' ? null : (is_numeric($v) ? round((float)$v, 2) : null);
    }

    /** @return array{0:array,1:array} [dane, błędy] */
    public static function validate(SettleKind $k, int $clientId, array $in): array
    {
        $e = []; $d = [];
        $courseId = (int)($in['course_id'] ?? 0);
        $d['course_id'] = $courseId;
        $d['uzasadnienie'] = mb_substr(trim((string)($in['uzasadnienie'] ?? '')), 0, 2000);
        if ($k === SettleKind::Przp) {
            $d['as_of'] = (string)($in['as_of'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['as_of'])) $e['as_of'] = 'Podaj dzień stanu początkowego.';
            foreach (['godz_wykorzystane' => 'Godziny wykorzystane', 'godz_oplacone' => 'Godziny opłacone', 'saldo' => 'Saldo pieniężne'] as $f => $l) {
                $v = self::num((string)($in[$f] ?? ''));
                if ($v === null && trim((string)($in[$f] ?? '')) !== '') $e[$f] = $l . ': podaj liczbę.';
                $d[$f] = $v ?? 0.0;
            }
            if ($d['godz_wykorzystane'] < 0 || $d['godz_oplacone'] < 0) $e['godz_oplacone'] = 'Godziny nie mogą być ujemne.';
            $d['zrodlo'] = mb_substr(trim((string)($in['zrodlo'] ?? '')), 0, 300);
            if ($d['zrodlo'] === '') $e['zrodlo'] = 'Podaj podstawę stanu początkowego (np. poprzedni system, umowa, zestawienie).';
            if (db_one("SELECT id FROM settlement_docs WHERE kind = 'PRZ-P' AND client_id = ? AND course_id = ? AND status IN ('pending', 'applied')", [$clientId, $courseId]))
                $e['course_id'] = 'Dla tego uczestnika i ' . ($courseId ? 'grupy' : 'konta') . ' jest już PRZ-P (oczekujący albo zatwierdzony).';
        } elseif ($k === SettleKind::Prz) {
            if ($courseId <= 0) $e['course_id'] = 'PRZ dotyczy grupy — wybierz grupę.';
            elseif (!self::baseline($clientId, $courseId)) $e['course_id'] = 'Brak zatwierdzonego PRZ-P (stanu początkowego) dla tego uczestnika i grupy — najpierw PRZ-P.';
            $rows = [];
            foreach ((array)($in['m'] ?? []) as $row) {
                if (!is_array($row) || trim((string)($row['ym'] ?? '')) === '') continue;
                if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$row['ym'], $mm)) { $e['m'] = 'Miesiąc w formacie RRRR-MM.'; continue; }
                $h = self::num((string)($row['h'] ?? '')); $rate = self::num((string)($row['rate'] ?? ''));
                if ($h === null || $h < 0) { $e['m'] = 'Godziny za ' . $row['ym'] . ': podaj liczbę ≥ 0.'; continue; }
                if ($rate === null || $rate < 0) { $e['m'] = 'Stawka za ' . $row['ym'] . ': podaj kwotę.'; continue; }
                $rows[] = ['year' => (int)$mm[1], 'month' => (int)$mm[2], 'h' => $h, 'rate' => $rate, 'amount' => round($h * $rate, 2)];
            }
            if (!$rows && !isset($e['m'])) $e['m'] = 'Dodaj co najmniej jeden miesiąc.';
            if (mb_strlen($d['uzasadnienie']) < 5) $e['uzasadnienie'] = 'Podaj powód ręcznego przeliczenia (trafi do rozliczenia).';
            $d['months'] = $rows;
        } elseif (in_array($k, [SettleKind::Rm, SettleKind::Rmr], true)) {
            $d['ym'] = (string)($in['ym'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})$/', $d['ym'])) $e['ym'] = 'Wybierz miesiąc rozliczenia.';
            if ($k === SettleKind::Rmr) {
                if ($courseId <= 0) $e['course_id'] = 'Rozliczenie ręczne dotyczy grupy — wybierz grupę.';
                $lines = [];
                foreach ((array)($in['l'] ?? []) as $row) {
                    if (!is_array($row) || trim((string)($row['opis'] ?? '')) === '') continue;
                    $h = self::num((string)($row['h'] ?? '')) ?? 0.0; $rate = self::num((string)($row['rate'] ?? '')) ?? 0.0; $amt = self::num((string)($row['amount'] ?? ''));
                    if ($h < 0 || $rate < 0) { $e['l'] = 'Godziny i stawka nie mogą być ujemne.'; continue; }
                    $lines[] = ['opis' => mb_substr(trim((string)$row['opis']), 0, 200), 'h' => $h, 'rate' => $rate, 'amount' => $amt ?? round($h * $rate, 2)];
                }
                if (!$lines) $e['l'] = 'Dodaj co najmniej jedną pozycję rozliczenia.';
                if (mb_strlen($d['uzasadnienie']) < 5) $e['uzasadnienie'] = 'Podaj powód rozliczenia ręcznego.';
                $d['lines'] = $lines;
            }
        } elseif (in_array($k, [SettleKind::Rr, SettleKind::Rrr], true)) {
            $d['year'] = (int)($in['year'] ?? 0);
            if ($d['year'] < 2000 || $d['year'] > (int)date('Y') + 1) $e['year'] = 'Podaj rok rozliczenia.';
            if ($k === SettleKind::Rrr) {
                $rows = [];
                foreach ((array)($in['y'] ?? []) as $row) {
                    if (!is_array($row) || (int)($row['m'] ?? 0) < 1) continue;
                    $vals = ['h' => self::num((string)($row['h'] ?? '')) ?? 0.0, 'charges' => self::num((string)($row['charges'] ?? '')) ?? 0.0, 'payments' => self::num((string)($row['payments'] ?? '')) ?? 0.0];
                    if ($vals['h'] == 0 && $vals['charges'] == 0 && $vals['payments'] == 0) continue;
                    $rows[] = ['m' => min(12, (int)$row['m'])] + $vals + ['opis' => mb_substr(trim((string)($row['opis'] ?? '')), 0, 200)];
                }
                if (!$rows) $e['y'] = 'Wpisz dane co najmniej jednego miesiąca.';
                if (mb_strlen($d['uzasadnienie']) < 5) $e['uzasadnienie'] = 'Podaj podstawę rozliczenia ręcznego (np. dokumentacja sprzed SZO).';
                $d['rows'] = $rows;
            }
        } elseif ($k === SettleKind::Sub1) {
            $d['course_id'] = 0;
            $d['since'] = (string)($in['since'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['since'])) $e['since'] = 'Podaj dzień uruchomienia nowej procedury.';
            $raw = trim((string)($in['opening'] ?? '')); $v = self::num($raw);
            if ($raw !== '' && $v === null) $e['opening'] = 'Saldo otwarcia: podaj kwotę (+ nadpłata, − zaległość).';
            $d['opening'] = $v ?? 0.0;
            $d['opening_src'] = mb_substr(trim((string)($in['opening_src'] ?? '')), 0, 300);
            if (abs($d['opening']) > 0.004 && $d['opening_src'] === '') $e['opening_src'] = 'Podaj podstawę salda otwarcia (np. uzgodnienie z kursantem z dnia …).';
            if (mb_strlen($d['uzasadnienie']) < 5) $e['uzasadnienie'] = 'Podaj powód wyczyszczenia historii subkonta.';
            if (db_one("SELECT id FROM settlement_docs WHERE kind = 'SUB1' AND client_id = ? AND status = 'pending'", [$clientId])) $e['since'] = 'Dla tego uczestnika jest już SUB1 czekający na zatwierdzenie.';
        } elseif ($k === SettleKind::Rks) {
            $d['date'] = (string)($in['date'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'])) $e['date'] = 'Podaj datę księgowania.';
            foreach (['wplacona' => 'Kwota wpłacona', 'nalezna' => 'Kwota należna'] as $f => $l) {
                $raw = trim((string)($in[$f] ?? '')); $v = self::num($raw);
                if ($raw !== '' && ($v === null || $v < 0)) $e[$f] = $l . ': podaj kwotę ≥ 0.';
                $d[$f] = max(0.0, $v ?? 0.0);
            }
            if (!isset($e['wplacona']) && !isset($e['nalezna']) && $d['wplacona'] < 0.005 && $d['nalezna'] < 0.005) $e['wplacona'] = 'Podaj kwotę wpłaconą, należną albo obie.';
            $d['method'] = in_array($in['method'] ?? '', ['transfer', 'cash', 'other'], true) ? $in['method'] : 'transfer';
            $d['roznica'] = round($d['wplacona'] - $d['nalezna'], 2);
            if (mb_strlen($d['uzasadnienie']) < 5) $e['uzasadnienie'] = 'Podaj tytuł / podstawę księgowania.';
        } else {
            $d['scope'] = ($in['scope'] ?? '') === 'account' ? 'account' : 'group';
            if ($d['scope'] === 'group' && $courseId <= 0) $e['course_id'] = 'Wybierz grupę albo rozliczenie całego konta.';
            if ($d['scope'] === 'account') $d['course_id'] = 0;
        }
        return [$d, $e];
    }

    // ── Wystawienie, decyzja, wykonanie ─────────────────────────────────────────

    /** @return array{id:int, errors:array, report:list<string>} */
    public static function create(SettleKind $k, int $clientId, array $in, string $mode, array $actor): array
    {
        settleMigrate();
        if (!db_one("SELECT id FROM k30_clients WHERE id = ?", [$clientId])) return ['id' => 0, 'errors' => ['client' => 'Wybierz uczestnika.'], 'report' => []];
        [$d, $e] = self::validate($k, $clientId, $in);
        if ($e) return ['id' => 0, 'errors' => $e, 'report' => []];
        $mode = $mode === 'direct' ? 'direct' : 'approval';
        // RKS księguje kierownik: bez uprawnień kierownika dokument zawsze czeka na jego zatwierdzenie
        if (in_array($k, [SettleKind::Rks, SettleKind::Sub1], true) && $mode === 'direct' && !DopService::canApprove((int)($actor['id'] ?? 0))) $mode = 'approval';
        $req = trim(preg_replace('/\s+/', '', (string)($in['request_number'] ?? '')));
        $d['snapshot'] = self::snapshot($k, $clientId, $d);
        $id = db_insert('settlement_docs', ['kind' => $k->value, 'number' => self::number($k, $clientId, $req), 'client_id' => $clientId, 'course_id' => (int)$d['course_id'],
            'scope' => $k === SettleKind::Rko ? $d['scope'] : ((int)$d['course_id'] > 0 ? 'group' : 'account'), 'mode' => $mode, 'status' => 'pending',
            'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'request_number' => $req, 'created_by' => ($actor['id'] ?? 0) ?: null,
            'created_name' => (string)$actor['name'], 'created_ip' => self::ip(), 'created_at' => ldNow()]);
        $report = [];
        if ($mode === 'direct') {
            $report = self::apply($id, $actor, 'Wystawiono bez akceptacji (tryb do akt).');
        } else {
            (new LicenseNotificationService('ti_dop'))->alertOffice($k->value . ' do zatwierdzenia — ' . self::get($id)['number'],
                '<p>' . htmlspecialchars((string)$actor['name']) . ' wystawił(a) dokument <b>' . htmlspecialchars($k->value . ' — ' . $k->title()) . '</b>. Czeka na zatwierdzenie kierownika (Finanse › Wnioski DOP › Rozliczenia).</p>',
                ['type' => 'settle', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
            $report[] = 'czeka na zatwierdzenie kierownika';
        }
        return ['id' => $id, 'errors' => [], 'report' => $report];
    }

    public static function decide(int $id, bool $approve, string $note, array $actor): array
    {
        $r = self::get($id);
        if (!$r || $r['status'] !== 'pending') throw new DomainException('Dokument nie czeka na zatwierdzenie.');
        if (!DopService::canApprove((int)$actor['id'])) throw new DomainException('Zatwierdza kierownik (rola „kierownik” panelu TI) albo administrator.');
        if (!$approve && trim($note) === '') throw new InvalidArgumentException('Podaj powód odrzucenia.');
        db_exec("UPDATE settlement_docs SET decided_by = ?, decided_name = ?, decided_ip = ?, decided_at = ?, decision_note = ?, status = ? WHERE id = ?",
            [$actor['id'] ?: null, (string)$actor['name'], self::ip(), ldNow(), $note, $approve ? 'approved' : 'rejected', $id]);
        if (!$approve) return ['Odrzucono.'];
        return self::apply($id, $actor, 'Zatwierdzono.');
    }

    /** Wykonanie: PRZ-P — stan początkowy w księdze; PRZ — należności; RKO — zamknięcie (migawka). Potem PDF, teczka, wysyłka (tryb z akceptacją). */
    private static function apply(int $id, array $actor, string $prefix): array
    {
        $r = self::get($id);
        $d = $r['d'];
        $cid = (int)$r['client_id'];
        require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
        $res = ['log' => []];
        if ($r['kindEnum'] === SettleKind::Sub1) {
            // Archiwum historii (do dokumentu i PDF), potem wyzerowanie salda wpisami umorzenia — własna transakcja
            require_once dirname(__DIR__, 2) . '/ti_subaccount/logic/subaccountManager.php';
            require_once dirname(__DIR__, 2) . '/ti_overpayments/logic/overpayments.php';
            $st = tiSubStatement($cid);
            $d['archive'] = ['at' => ldNow(), 'opening' => $st['opening'], 'payments' => $st['payments'], 'charges' => $st['charges'],
                'lines' => array_map(fn($l) => ['date' => $l['date'], 'kind' => $l['kind'], 'desc' => $l['desc'], 'what' => $l['what'], 'in' => $l['in'], 'out' => $l['out'], 'balance' => $l['balance']], $st['lines']),
                'balance' => (float)ti_client_balance($cid)['balance']];
            $z = ti_balance_zero_out($cid, -1, 'SUB1 ' . $r['number'] . ' — nowa procedura subkonta: ' . $d['uzasadnienie'], (string)$actor['name'], ($actor['id'] ?? 0) ?: null, false);
            if (!$z['ok']) throw new DomainException('Nie wyzerowano salda: ' . $z['msg']);
            $res['zero_out'] = $z['rows'];
            $res['log'][] = 'historia zarchiwizowana (' . count($d['archive']['lines']) . ' pozycji, saldo ' . self::zl($d['archive']['balance']) . ')';
            $res['log'][] = $z['rows'] ? mb_strtolower(rtrim($z['msg'], '.')) : 'saldo było zerowe';
            db_exec("UPDATE settlement_docs SET data = ? WHERE id = ?", [json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($r['kindEnum'] === SettleKind::Przp) {
                $s = (float)$d['saldo'];
                $t = strtotime((string)$d['as_of']);
                if ($s > 0.004) {
                    $res['ledger_id'] = db_insert('k30_ti_payments', ['client_id' => $cid, 'course_id' => (int)$r['course_id'], 'amount' => round($s, 2), 'paid_at' => $d['as_of'], 'method' => 'internal',
                        'note' => 'Saldo początkowe ' . $r['number'] . ' (PRZ-P): ' . $d['zrodlo'], 'source_type' => 'opening_balance', 'source_id' => $id]);
                    $res['log'][] = 'saldo początkowe +' . self::zl($s) . ' (nadpłata) w księdze';
                } elseif ($s < -0.004) {
                    $res['billing_id'] = ti_billing_add_charge($cid, round(-$s, 2), 'Saldo początkowe ' . $r['number'] . ' (PRZ-P): ' . $d['zrodlo'], (int)date('n', $t), (int)date('Y', $t));
                    $res['log'][] = 'saldo początkowe −' . self::zl(-$s) . ' (zaległość) jako należność';
                }
                $res['log'][] = 'godziny początkowe: wykorzystane ' . $d['godz_wykorzystane'] . ' h, opłacone ' . $d['godz_oplacone'] . ' h';
            } elseif ($r['kindEnum'] === SettleKind::Prz) {
                $res['billing'] = [];
                foreach ($d['months'] as $m) {
                    $b = db_one("SELECT * FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND course_id = ? AND status IN ('draft','issued','paid')", [$cid, $m['month'], $m['year'], (int)$r['course_id']]);
                    $label = sprintf('%02d.%d', $m['month'], $m['year']);
                    if ($b && ti_billing_has_invoice($b)) throw new DomainException('Rozliczenie za ' . $label . ' ma fakturę — kwoty dokumentu księgowego nie zmieniamy (korekta faktury poza PRZ).');
                    $note = 'PRZ ' . $r['number'] . ': ' . $m['h'] . ' h × ' . number_format($m['rate'], 2, ',', ' ') . ' zł — ' . $d['uzasadnienie'];
                    if ($b) {
                        db_exec("UPDATE k30_ti_billing SET hours_billed = ?, hourly_rate = ?, amount = ?, manual_amount = ?, manual_note = ?, manual_by_name = ?, manual_at = ?, status = CASE WHEN status = 'draft' THEN 'issued' ELSE status END,
                                 issued_at = COALESCE(issued_at, ?) WHERE id = ?", [$m['h'], $m['rate'], $m['amount'], $m['amount'], mb_substr($note, 0, 500), (string)$actor['name'], ldNow(), ldNow(), (int)$b['id']]);
                        $res['billing'][] = ['id' => (int)$b['id'], 'month' => $label, 'old' => round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2), 'new' => $m['amount'] + (float)($b['adjustment'] ?? 0)];
                    } else {
                        $bid = db_insert('k30_ti_billing', ['client_id' => $cid, 'month' => $m['month'], 'year' => $m['year'], 'course_id' => (int)$r['course_id'], 'hours_billed' => $m['h'],
                            'hourly_rate' => $m['rate'], 'amount' => $m['amount'], 'manual_amount' => $m['amount'], 'manual_note' => mb_substr($note, 0, 500), 'manual_by_name' => (string)$actor['name'],
                            'manual_at' => ldNow(), 'status' => 'issued', 'issued_at' => ldNow(), 'created_at' => ldNow(), 'due_date' => ldDue()]);
                        $res['billing'][] = ['id' => $bid, 'month' => $label, 'old' => 0.0, 'new' => $m['amount']];
                    }
                    $res['log'][] = $label . ': ' . $m['h'] . ' h → ' . self::zl($m['amount']);
                }
            } elseif ($r['kindEnum'] === SettleKind::Rmr) {
                [$y, $mo] = array_map('intval', explode('-', $d['ym']));
                $h = array_sum(array_column($d['lines'], 'h')); $amt = round(array_sum(array_column($d['lines'], 'amount')), 2);
                $b = db_one("SELECT * FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND course_id = ? AND status IN ('draft','issued','paid')", [$cid, $mo, $y, (int)$r['course_id']]);
                if ($b && ti_billing_has_invoice($b)) throw new DomainException('Rozliczenie za ' . sprintf('%02d.%d', $mo, $y) . ' ma fakturę — kwoty dokumentu księgowego nie zmieniamy.');
                $note = 'RMR ' . $r['number'] . ': ' . implode('; ', array_map(fn($l) => $l['opis'] . ' ' . $l['h'] . ' h = ' . number_format($l['amount'], 2, ',', ' ') . ' zł', $d['lines'])) . ' — ' . $d['uzasadnienie'];
                $rate = $h > 0 ? round($amt / $h, 2) : 0.0;
                if ($b) db_exec("UPDATE k30_ti_billing SET hours_billed = ?, hourly_rate = ?, amount = ?, manual_amount = ?, manual_note = ?, manual_by_name = ?, manual_at = ?, status = CASE WHEN status = 'draft' THEN 'issued' ELSE status END, issued_at = COALESCE(issued_at, ?) WHERE id = ?",
                    [$h, $rate, $amt, $amt, mb_substr($note, 0, 500), (string)$actor['name'], ldNow(), ldNow(), (int)$b['id']]);
                else db_insert('k30_ti_billing', ['client_id' => $cid, 'month' => $mo, 'year' => $y, 'course_id' => (int)$r['course_id'], 'hours_billed' => $h, 'hourly_rate' => $rate, 'amount' => $amt,
                    'manual_amount' => $amt, 'manual_note' => mb_substr($note, 0, 500), 'manual_by_name' => (string)$actor['name'], 'manual_at' => ldNow(), 'status' => 'issued', 'issued_at' => ldNow(), 'created_at' => ldNow(), 'due_date' => ldDue()]);
                $res['log'][] = sprintf('%02d.%d', $mo, $y) . ': ' . $h . ' h → ' . self::zl($amt) . ' (kwota ręczna)';
            } elseif ($r['kindEnum'] === SettleKind::Sub1) {
                $res['cutover'] = tiSubCutoverSet($cid, (string)$d['since'], $id, (string)$r['number'], true, (string)$actor['name']);
                $res['log'][] = 'od ' . ldDate($d['since']) . ' księgowanie ręczne (RKS), automatyczne rozliczenia wyłączone';
                $s0 = (float)$d['opening'];
                if ($s0 > 0.004) {
                    $res['ledger_id'] = db_insert('k30_ti_payments', ['client_id' => $cid, 'course_id' => 0, 'amount' => round($s0, 2), 'paid_at' => $d['since'], 'method' => 'internal',
                        'note' => 'Saldo otwarcia ' . $r['number'] . ' (SUB1): ' . $d['opening_src'], 'source_type' => 'opening_balance', 'source_id' => $id, 'created_at' => ldNow()]);
                    $res['log'][] = 'saldo otwarcia +' . self::zl($s0);
                } elseif ($s0 < -0.004) {
                    $t = strtotime((string)$d['since']);
                    $res['billing_id'] = ti_billing_add_charge($cid, round(-$s0, 2), 'Saldo otwarcia ' . $r['number'] . ' (SUB1): ' . $d['opening_src'], (int)date('n', $t), (int)date('Y', $t));
                    $res['log'][] = 'saldo otwarcia −' . self::zl(-$s0) . ' (zaległość)';
                } else $res['log'][] = 'saldo otwarcia 0,00 zł';
            } elseif ($r['kindEnum'] === SettleKind::Rks) {
                $t = strtotime((string)$d['date']); $gname = (int)$r['course_id'] > 0 ? ' [grupa: ' . self::courseName((int)$r['course_id']) . ']' : '';
                if ((float)$d['nalezna'] > 0.004) {
                    $res['billing_id'] = ti_billing_add_charge($cid, (float)$d['nalezna'], 'RKS ' . $r['number'] . $gname . ': ' . $d['uzasadnienie'], (int)date('n', $t), (int)date('Y', $t));
                    $res['log'][] = 'należność +' . self::zl((float)$d['nalezna']);
                }
                if ((float)$d['wplacona'] > 0.004) {
                    $p = ti_payment_add($cid, (float)$d['wplacona'], (string)$d['date'], (string)$d['method'], 'RKS ' . $r['number'] . ': ' . $d['uzasadnienie'], 'rks', $id, (int)$r['course_id']);
                    $res['payment_id'] = (int)($p['payment_id'] ?? 0);
                    $res['log'][] = 'wpłata +' . self::zl((float)$d['wplacona']);
                }
                $res['log'][] = 'różnica (wpłacona − należna) ' . self::zl((float)$d['roznica']);
            } elseif (in_array($r['kindEnum'], [SettleKind::Rm, SettleKind::Rr, SettleKind::Rrr], true)) {
                $res['log'][] = $r['kindEnum']->title() . ' — dokument (bez zmian w księdze)';
            } else {
                $res['log'][] = 'rozliczenie końcowe: saldo ' . self::zl((float)$d['snapshot']['balance']);
            }
            db_exec("UPDATE settlement_docs SET status = 'applied', applied_at = ?, result = ? WHERE id = ?", [ldNow(), json_encode($res, JSON_UNESCAPED_UNICODE), $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            db_exec("UPDATE settlement_docs SET status = 'pending' WHERE id = ? AND status = 'approved'", [$id]);
            throw $e;
        }
        ti_billing_recompute($cid);
        try { require_once dirname(__DIR__, 2) . '/ti_overpayments/logic/overpayments.php'; ti_op_refresh_balance($cid); } catch (\Throwable $e) {}
        // migawka po wykonaniu (RKO / PRZ: stan po zmianach)
        $d['snapshot_after'] = self::snapshot($r['kindEnum'], $cid, $d);
        db_exec("UPDATE settlement_docs SET data = ? WHERE id = ?", [json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
        $out = [$prefix . ' ' . implode('; ', $res['log']) . '.'];
        $out[] = self::finish($id, $actor);
        return $out;
    }

    /** PDF, teczka kursanta, wysyłka do kursanta i rodzica (tylko tryb z akceptacją). */
    private static function finish(int $id, array $actor): string
    {
        $r = self::get($id);
        $o = self::render($r);
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . DOP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = strtolower(str_replace('-', '', $r['kind'])) . $id . '_' . bin2hex(random_bytes(6)) . '.' . $o['ext'];
        file_put_contents($dir . '/' . $name, $o['bytes']);
        $rel = DOP_DIR . '/' . $name;
        db_exec("UPDATE settlement_docs SET pdf_path = ? WHERE id = ?", [$rel, $id]);
        $msg = [];
        // teczka
        try {
            $acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [(int)$r['client_id']]);
            if ($acc && $o['ext'] === 'pdf') {
                require_once dirname(__DIR__, 2) . '/ti_student_folder/logic/studentFolder.php';
                TiStudentFolder::migrate();
                $relT = TiStudentFolder::DIR . '/' . (int)$acc['id'] . '_' . bin2hex(random_bytes(8)) . '.pdf';
                if (!is_dir(rtrim(UPLOAD_DIR, '/') . '/' . TiStudentFolder::DIR)) @mkdir(rtrim(UPLOAD_DIR, '/') . '/' . TiStudentFolder::DIR, 0750, true);
                copy($dir . '/' . $name, rtrim(UPLOAD_DIR, '/') . '/' . $relT);
                $fid = db_insert('k30_ti_sfile', ['account_id' => (int)$acc['id'], 'category' => 'potwierdzenie', 'title' => mb_substr($r['number'] . ' — ' . $r['kindEnum']->title(), 0, 200),
                    'note' => 'Dokument rozliczeniowy ' . $r['kind'] . '.', 'file_path' => $relT, 'file_name' => preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['number']) . '.pdf', 'mime' => 'application/pdf',
                    'size' => strlen($o['bytes']), 'sha256' => hash('sha256', $o['bytes']), 'visible_student' => 1, 'source' => 'system', 'status' => 'approved',
                    'created_by_name' => 'system (' . $r['kind'] . ')', 'created_at' => ldNow()]);
                db_exec("UPDATE settlement_docs SET folder_file_id = ? WHERE id = ?", [$fid, $id]);
                $msg[] = 'teczka kursanta';
            }
        } catch (\Throwable $e) { error_log('settle folder: ' . $e->getMessage()); }
        if ($r['mode'] === 'approval') $msg[] = 'wysyłka: ' . self::send($id, $actor);
        else $msg[] = 'tylko do akt (bez wysyłki)';
        return implode(', ', $msg);
    }

    public static function send(int $id, array $actor): string
    {
        $r = self::get($id);
        $c = db_one("SELECT name, email FROM k30_clients WHERE id = ?", [(int)$r['client_id']]) ?: [];
        $g = db_one("SELECT guardian_name, guardian_email FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [(int)$r['client_id']]) ?: [];
        $att = str_ends_with((string)$r['pdf_path'], '.pdf') ? [['path' => $r['pdf_path'], 'name' => preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['number']) . '.pdf', 'mime' => 'application/pdf', 'size' => (int)@filesize(rtrim(UPLOAD_DIR, '/') . '/' . $r['pdf_path'])]] : [];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = LicenseNotificationService::layout($r['kindEnum']->title(), '<p>Dzień dobry,</p><p>przesyłamy dokument <b>' . $e($r['number']) . '</b> — ' . $e($r['kindEnum']->title()) . ' (uczestnik: <b>' . $e($r['client_name']) . '</b>'
            . ((int)$r['course_id'] > 0 ? ', grupa: ' . $e(self::courseName((int)$r['course_id'])) : '') . '). Dokument z podpisem — w załączniku (PDF).</p>', '', '', $r['kind']);
        $n = new LicenseNotificationService('ti_dop');
        $sent = [];
        foreach ([['kursant', $c['name'] ?? '', $c['email'] ?? ''], ['rodzic / opiekun', $g['guardian_name'] ?? '', $g['guardian_email'] ?? '']] as [$rola, $nm, $em]) {
            if ($em === '' || ($rola !== 'kursant' && strcasecmp($em, (string)($c['email'] ?? '')) === 0)) continue;
            $res = $n->send(LicenseNotificationType::Statement, ['name' => $nm, 'email' => $em], $r['kind'] . ' — ' . $r['kindEnum']->title() . ' — ' . $r['number'], $html, '', ['type' => 'settle', 'id' => $id], $actor, false, $att);
            $sent[] = ['rola' => $rola, 'email' => LicenseNotificationService::maskEmail($em), 'status' => $res['channels']['email'] ?? 'skipped', 'at' => ldNow()];
        }
        db_exec("UPDATE settlement_docs SET sent = ? WHERE id = ?", [json_encode(array_merge($r['sentList'], $sent), JSON_UNESCAPED_UNICODE), $id]);
        return implode('; ', array_map(fn($x) => $x['rola'] . ' — ' . LicenseNotificationService::statusLabel($x['status']), $sent)) ?: 'brak adresów e-mail';
    }

    // ── Migawka danych ──────────────────────────────────────────────────────────

    public static function snapshot(SettleKind $k, int $clientId, array $d): array
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
        $courseId = (int)($d['course_id'] ?? 0);
        $bal = ti_client_balance($clientId);
        $groups = ti_client_group_balances($clientId);
        $hours = function (int $course) use ($clientId): array {
            $s = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(s.duration_min), 0) AS mins FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
                          WHERE a.client_id = ? AND a.attended = 1 AND s.status IN ('held','individual_change','remote_material')" . ($course > 0 ? ' AND s.course_id = ' . $course : ''), [$clientId]) ?? [];
            $b = db_one("SELECT COALESCE(SUM(hours_billed), 0) AS h, COALESCE(SUM(amount + COALESCE(adjustment, 0)), 0) AS due, COALESCE(SUM(paid_amount), 0) AS paid FROM k30_ti_billing
                          WHERE client_id = ? AND status IN ('issued','paid')" . ($course > 0 ? ' AND course_id = ' . $course : ''), [$clientId]) ?? [];
            return ['lessons' => (int)($s['n'] ?? 0), 'hours_attended' => round(((int)($s['mins'] ?? 0)) / 60, 2), 'hours_billed' => round((float)($b['h'] ?? 0), 2),
                    'charges' => round((float)($b['due'] ?? 0), 2), 'paid' => round((float)($b['paid'] ?? 0), 2)];
        };
        $snap = ['at' => ldNow(), 'credit' => (float)$bal['credit'], 'debt' => (float)$bal['debt'], 'balance' => (float)$bal['balance']];
        if ($courseId > 0) {
            $g = $groups['groups'][$courseId] ?? ['charges' => 0, 'paid' => 0, 'debt' => 0, 'payments' => 0, 'credit' => 0];
            $snap['group'] = ['name' => self::courseName($courseId), 'charges' => round((float)$g['charges'], 2), 'payments' => round((float)$g['payments'], 2),
                              'debt' => round((float)$g['debt'], 2), 'credit' => round((float)$g['credit'], 2)] + $hours($courseId);
            if ($k === SettleKind::Rko) $snap['balance'] = round((float)$g['credit'] - (float)$g['debt'], 2);
        }
        if ($k === SettleKind::Rko && ($d['scope'] ?? '') === 'account') {
            $snap['groups'] = [];
            foreach ($groups['groups'] as $gid => $g) $snap['groups'][] = ['name' => $g['course_name'], 'charges' => round((float)$g['charges'], 2), 'payments' => round((float)$g['payments'], 2),
                'credit' => round((float)$g['credit'], 2), 'debt' => round((float)$g['debt'], 2)] + ($gid > 0 ? $hours((int)$gid) : []);
            $snap['general_credit'] = round((float)$groups['general_credit'], 2);
            try {
                require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/AmzStatement.php';
                $amz = AmzStatement::build($clientId);
                $snap['resources'] = array_values(array_map(fn($i) => ['group' => $i['group'], 'name' => $i['name'], 'status' => $i['status'], 'to' => $i['to']],
                    array_filter($amz['items'] ?? [], fn($i) => !in_array($i['status'], ['Cofnięta', 'Wygasła', 'Zakończona', 'Wykorzystana'], true))));
            } catch (\Throwable $e) { $snap['resources'] = []; }
        }
        if (in_array($k, [SettleKind::Rm, SettleKind::Rmr], true) && preg_match('/^(\d{4})-(\d{2})$/', (string)($d['ym'] ?? ''), $mm)) {
            $snap['month'] = self::monthStats($clientId, (int)$mm[1], (int)$mm[2], $courseId);
        }
        if (in_array($k, [SettleKind::Rr, SettleKind::Rrr], true) && (int)($d['year'] ?? 0) > 0) {
            $snap['months'] = [];
            for ($m = 1; $m <= 12; $m++) { $ms = self::monthStats($clientId, (int)$d['year'], $m, $courseId); if ($ms['charges'] != 0 || $ms['payments'] != 0 || $ms['hours_attended'] != 0) $snap['months'][] = $ms; }
            $snap['year_totals'] = ['charges' => round(array_sum(array_column($snap['months'], 'charges')), 2), 'payments' => round(array_sum(array_column($snap['months'], 'payments')), 2),
                                    'hours_billed' => round(array_sum(array_column($snap['months'], 'hours_billed')), 2), 'hours_attended' => round(array_sum(array_column($snap['months'], 'hours_attended')), 2)];
        }
        if ($k === SettleKind::Prz && $courseId > 0) {
            $snap['current'] = [];
            foreach ((array)($d['months'] ?? []) as $m) {
                $b = db_one("SELECT hours_billed, hourly_rate, amount, adjustment FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND course_id = ? AND status IN ('draft','issued','paid')", [$clientId, $m['month'], $m['year'], $courseId]);
                $snap['current'][] = ['month' => sprintf('%02d.%d', $m['month'], $m['year']), 'hours' => $b ? (float)$b['hours_billed'] : null, 'amount' => $b ? round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2) : null];
            }
            $bl = self::baseline($clientId, $courseId);
            if ($bl) $snap['baseline'] = ['number' => $bl['number'], 'as_of' => $bl['d']['as_of'] ?? '', 'h_used' => $bl['d']['godz_wykorzystane'] ?? 0, 'h_paid' => $bl['d']['godz_oplacone'] ?? 0, 'saldo' => $bl['d']['saldo'] ?? 0];
        }
        return $snap;
    }

    /** Miesiąc uczestnika (opcjonalnie grupa): zajęcia z obecnością, godziny, należności, wpłaty, saldo na koniec miesiąca. */
    public static function monthStats(int $clientId, int $y, int $m, int $courseId = 0): array
    {
        $from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime($from));
        $cw = $courseId > 0 ? ' AND s.course_id = ' . $courseId : '';
        $att = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(s.duration_min), 0) AS mins FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
                        WHERE a.client_id = ? AND a.attended = 1 AND s.lesson_date BETWEEN ? AND ?" . $cw, [$clientId, $from, $to]) ?? [];
        $b = db_one("SELECT COALESCE(SUM(hours_billed), 0) AS h, COALESCE(SUM(amount + COALESCE(adjustment, 0)), 0) AS due FROM k30_ti_billing WHERE client_id = ? AND month = ? AND year = ? AND status IN ('issued','paid')"
                    . ($courseId > 0 ? ' AND course_id = ' . $courseId : ''), [$clientId, $m, $y]) ?? [];
        $p = db_one("SELECT COALESCE(SUM(amount), 0) AS s FROM k30_ti_payments WHERE client_id = ? AND COALESCE(paid_at, date(created_at)) BETWEEN ? AND ? AND method NOT IN ('internal')"
                    . ($courseId > 0 ? ' AND course_id IN (0, ' . $courseId . ')' : ''), [$clientId, $from, $to]) ?? [];
        $end = 0.0;
        try { require_once dirname(__DIR__, 2) . '/ti_subaccount/logic/subaccountManager.php'; $st = tiSubStatement($clientId, '', $to); $end = $st['lines'] ? (float)$st['lines'][count($st['lines']) - 1]['balance'] : (float)$st['opening']; } catch (\Throwable $e) {}
        return ['m' => $m, 'y' => $y, 'label' => sprintf('%02d.%d', $m, $y), 'lessons' => (int)($att['n'] ?? 0), 'hours_attended' => round(((int)($att['mins'] ?? 0)) / 60, 2),
                'hours_billed' => round((float)($b['h'] ?? 0), 2), 'charges' => round((float)($b['due'] ?? 0), 2), 'payments' => round((float)($p['s'] ?? 0), 2), 'balance_end' => round($end, 2)];
    }

    // ── Wydruk ──────────────────────────────────────────────────────────────────

    public static function html(array $r): string
    {
        $k = $r['kindEnum']; $d = $r['d']; $s = $d['snapshot_after'] ?? $d['snapshot'] ?? [];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
        $n = 0;
        $fld = function (string $label, string $html, string $extra = '') use (&$n): string { $n++; return '<td class="f"' . $extra . '><div class="lb"><b>' . $n . '.</b> ' . htmlspecialchars($label) . '</div><div class="v">' . ($html !== '' ? $html : '&nbsp;') . '</div></td>'; };
        $sec = fn(string $l, string $t, int $c = 2) => '<tr><td class="sec" colspan="' . $c . '"><b>' . $l . '. ' . htmlspecialchars($t) . '</b></td></tr>';
        $th = fn(array $cols) => '<tr>' . implode('', array_map(fn($c) => '<td class="lb" style="padding:1mm"><b>' . htmlspecialchars($c) . '</b></td>', $cols)) . '</tr>';
        $h = '<table class="hdr"><tr><td style="width:60%"><b style="font-size:11pt">' . $e(ldOrgName()) . '</b><br><span class="small">' . $e((string)org_setting('org_adres')) . '</span></td>'
           . '<td style="width:40%;text-align:right">' . $e(ldDate(substr((string)$r['created_at'], 0, 10))) . '<div class="small">data wystawienia</div></td></tr></table>'
           . '<div class="title">' . $e($k->value) . ' – ' . $e(mb_strtoupper($k->title())) . '</div><div class="num">Numer: <b>' . $e($r['number']) . '</b> · ' . ($r['mode'] === 'direct' ? 'wystawiony bez akceptacji (do akt)' : 'tryb z akceptacją kierownika') . '</div>';
        $h .= '<table class="t">' . $sec('A', 'UCZESTNIK I ZAKRES') . '<tr>' . $fld('Uczestnik', '<b>' . $e($r['client_name']) . '</b> · ID ' . (int)$r['client_id'], ' width="50%"')
            . $fld('Zakres', $e((int)$r['course_id'] > 0 ? 'grupa: ' . self::courseName((int)$r['course_id']) : 'całe konto uczestnika'), ' width="50%"') . '</tr><tr>'
            . $fld('Wniosek', $e($r['request_number'] ?: '—'), '') . $fld('Uzasadnienie', $e($d['uzasadnienie'] ?? '') ?: '—', '') . '</tr></table>';
        if ($k === SettleKind::Przp) {
            $h .= '<table class="t">' . $sec('B', 'STAN POCZĄTKOWY NA DZIEŃ ' . ldDate($d['as_of'] ?? ''), 3) . '<tr>' . $fld('Godziny wykorzystane', '<b>' . $e($d['godz_wykorzystane']) . ' h</b>', ' width="33%"')
                . $fld('Godziny opłacone', '<b>' . $e($d['godz_oplacone']) . ' h</b>', ' width="33%"') . $fld('Saldo pieniężne (+ nadpłata / − zaległość)', '<b>' . $e($zl($d['saldo'])) . '</b>', ' width="34%"')
                . '</tr><tr>' . $fld('Podstawa stanu początkowego', $e($d['zrodlo']), ' colspan="3"') . '</tr></table>';
        } elseif ($k === SettleKind::Prz) {
            $rows = '';
            foreach ($d['months'] as $i => $m) {
                $cur = $d['snapshot']['current'][$i] ?? [];
                $rows .= '<tr><td class="f">' . $e(sprintf('%02d.%d', $m['month'], $m['year'])) . '</td><td class="f" style="text-align:right">' . ($cur['hours'] !== null && isset($cur['hours']) ? $e($cur['hours']) . ' h' : '—') . '</td><td class="f" style="text-align:right">'
                    . (isset($cur['amount']) && $cur['amount'] !== null ? $e($zl($cur['amount'])) : '—') . '</td><td class="f" style="text-align:right"><b>' . $e($m['h']) . ' h</b></td><td class="f" style="text-align:right">' . $e($zl($m['rate'])) . '</td><td class="f" style="text-align:right"><b>' . $e($zl($m['amount'])) . '</b></td></tr>';
            }
            $bl = $d['snapshot']['baseline'] ?? null;
            $h .= '<table class="t">' . $sec('B', 'STAN POCZĄTKOWY (PRZ-P)', 2) . '<tr>' . $fld('Dokument', $e($bl['number'] ?? '—') . ($bl ? ' z ' . $e(ldDate($bl['as_of'])) : ''), ' width="50%"')
                . $fld('Godziny wykorzystane / opłacone, saldo', $bl ? $e($bl['h_used'] . ' h / ' . $bl['h_paid'] . ' h, ' . $zl($bl['saldo'])) : '—', ' width="50%"') . '</tr></table>';
            $h .= '<table class="t">' . $sec('C', 'PRZELICZENIE GODZIN I ROZLICZENIE', 6) . $th(['Miesiąc', 'Było: godziny', 'Było: kwota', 'Faktyczne godziny', 'Stawka', 'Kwota rozliczenia']) . $rows . '</table>';
        } elseif (in_array($k, [SettleKind::Rm, SettleKind::Rmr], true)) {
            $ms = $s['month'] ?? null;
            if ($ms) $h .= '<table class="t">' . $sec('B', 'MIESIĄC ' . $ms['label'] . ' — STAN Z KSIĘGI' . ($k === SettleKind::Rmr ? ' (PRZED ROZLICZENIEM RĘCZNYM)' : ''), 3) . '<tr>'
                . $fld('Zajęcia z obecnością / godziny', $e($ms['lessons'] . ' / ' . $ms['hours_attended'] . ' h'), ' width="33%"') . $fld('Godziny rozliczone', $e($ms['hours_billed'] . ' h'), ' width="33%"') . $fld('Należności miesiąca', '<b>' . $e($zl($ms['charges'])) . '</b>', ' width="34%"') . '</tr><tr>'
                . $fld('Wpłaty w miesiącu', $e($zl($ms['payments'])), '') . $fld('Saldo na koniec miesiąca', '<b>' . $e($zl($ms['balance_end'])) . '</b>', '') . $fld('Saldo + nadpłata / − zaległość', '', '') . '</tr></table>';
            if ($k === SettleKind::Rmr) {
                $rows = '';
                foreach ($d['lines'] as $l) $rows .= '<tr><td class="f">' . $e($l['opis']) . '</td><td class="f" style="text-align:right">' . $e($l['h']) . ' h</td><td class="f" style="text-align:right">' . $e($zl($l['rate'])) . '</td><td class="f" style="text-align:right"><b>' . $e($zl($l['amount'])) . '</b></td></tr>';
                $rows .= '<tr><td class="f"><b>Razem</b></td><td class="f" style="text-align:right"><b>' . $e(array_sum(array_column($d['lines'], 'h'))) . ' h</b></td><td class="f"></td><td class="f" style="text-align:right"><b>' . $e($zl(array_sum(array_column($d['lines'], 'amount')))) . '</b></td></tr>';
                $h .= '<table class="t">' . $sec('C', 'ROZLICZENIE RĘCZNE MIESIĄCA ' . ($d['ym'] ?? ''), 4) . $th(['Pozycja', 'Godziny', 'Stawka', 'Kwota']) . $rows . '</table>';
            }
        } elseif ($k === SettleKind::Sub1) {
            $ar = $d['archive'] ?? null;
            $h .= '<table class="t">' . $sec('B', 'NOWA PROCEDURA OD DNIA ' . ldDate($d['since']), 3) . '<tr>' . $fld('Dzień uruchomienia', '<b>' . $e(ldDate($d['since'])) . '</b>', ' width="33%"')
                . $fld('Saldo otwarcia', '<b>' . $e($zl($d['opening'])) . '</b>' . ($d['opening_src'] !== '' ? '<div class="small">' . $e($d['opening_src']) . '</div>' : ''), ' width="33%"')
                . $fld('Księgowanie od dnia uruchomienia', 'wyłącznie ręczne (RKS) — bez automatycznych rozliczeń z kalkulatora', ' width="34%"') . '</tr><tr>'
                . $fld('Historia przed uruchomieniem', $ar ? 'zarchiwizowana w niniejszym dokumencie: ' . count($ar['lines']) . ' pozycji; wpłaty ' . $e($zl($ar['payments'])) . ', należności ' . $e($zl($ar['charges'])) . ', saldo ' . $e($zl($ar['balance'])) : 'zostanie zarchiwizowana przy wykonaniu', ' colspan="2"')
                . $fld('Wyzerowanie', $ar ? (($r['res']['zero_out'] ?? []) ? $e(implode('; ', array_map(fn($x) => $x['name'] . ($x['debt'] > 0 ? ' umorzono ' . $zl($x['debt']) : '') . ($x['credit'] > 0 ? ' wyzerowano nadpłatę ' . $zl($x['credit']) : ''), $r['res']['zero_out']))) : 'saldo było zerowe') : 'przy wykonaniu', '') . '</tr></table>';
            if ($ar && $ar['lines']) {
                $rows = '';
                foreach ($ar['lines'] as $l) $rows .= '<tr><td class="f">' . $e(ldDate($l['date'])) . '</td><td class="f">' . $e($l['desc']) . '<div class="small">' . $e($l['what']) . '</div></td><td class="f" style="text-align:right">' . ($l['in'] ? $e($zl($l['in'])) : '') . '</td><td class="f" style="text-align:right">' . ($l['out'] ? $e($zl($l['out'])) : '') . '</td><td class="f" style="text-align:right">' . $e($zl($l['balance'])) . '</td></tr>';
                $h .= '<table class="t">' . $sec('C', 'ZAŁĄCZNIK — ARCHIWUM HISTORII SUBKONTA SPRZED NOWEJ PROCEDURY', 5) . $th(['Data', 'Pozycja', 'Wpłata', 'Należność', 'Saldo']) . $rows . '</table>';
            }
        } elseif ($k === SettleKind::Rks) {
            $diff = (float)$d['roznica'];
            $h .= '<table class="t">' . $sec('B', 'KSIĘGOWANIE NA DZIEŃ ' . ldDate($d['date']), 3) . '<tr>' . $fld('Kwota wpłacona', '<b>' . $e($zl($d['wplacona'])) . '</b><div class="small">' . $e(['transfer' => 'przelew', 'cash' => 'gotówka', 'other' => 'inna forma'][$d['method']] ?? '') . '</div>', ' width="33%"')
                . $fld('Kwota należna', '<b>' . $e($zl($d['nalezna'])) . '</b>', ' width="33%"') . $fld('Różnica (wpłacona − należna)', '<b>' . $e($zl($diff)) . '</b><div class="small">' . ($diff > 0.004 ? 'nadpłata' : ($diff < -0.004 ? 'niedopłata' : 'rozliczone')) . '</div>', ' width="34%"') . '</tr></table>';
            if ($s) $h .= '<table class="t">' . $sec('C', 'SALDO KONTA PO KSIĘGOWANIU', 2) . '<tr>' . $fld('Saldo (+ nadpłata / − zaległość)', '<b>' . $e($zl($s['balance'] ?? 0)) . '</b>', ' width="50%"') . $fld('Do zapłaty / nadpłata', $e($zl($s['debt'] ?? 0)) . ' / ' . $e($zl($s['credit'] ?? 0)), ' width="50%"') . '</tr></table>';
        } elseif (in_array($k, [SettleKind::Rr, SettleKind::Rrr], true)) {
            $rows = '';
            if ($k === SettleKind::Rrr) {
                foreach ($d['rows'] as $x) $rows .= '<tr><td class="f">' . $e(sprintf('%02d.%d', $x['m'], $d['year'])) . '</td><td class="f">' . $e($x['opis']) . '</td><td class="f" style="text-align:right">' . $e($x['h']) . ' h</td><td class="f" style="text-align:right">' . $e($zl($x['charges'])) . '</td><td class="f" style="text-align:right">' . $e($zl($x['payments'])) . '</td></tr>';
                $tot = ['h' => array_sum(array_column($d['rows'], 'h')), 'c' => array_sum(array_column($d['rows'], 'charges')), 'p' => array_sum(array_column($d['rows'], 'payments'))];
                $rows .= '<tr><td class="f" colspan="2"><b>Razem ' . $e($d['year']) . '</b></td><td class="f" style="text-align:right"><b>' . $e($tot['h']) . ' h</b></td><td class="f" style="text-align:right"><b>' . $e($zl($tot['c'])) . '</b></td><td class="f" style="text-align:right"><b>' . $e($zl($tot['p'])) . '</b></td></tr>';
                $h .= '<table class="t">' . $sec('B', 'ROZLICZENIE ROCZNE RĘCZNE ' . $d['year'] . ' (DANE WPISANE)', 5) . $th(['Miesiąc', 'Opis', 'Godziny', 'Należności', 'Wpłaty']) . $rows
                    . '<tr><td class="f" colspan="5"><b>Saldo roku (wpłaty − należności): ' . $e($zl($tot['p'] - $tot['c'])) . '</b></td></tr></table>';
            } else {
                foreach (($s['months'] ?? []) as $x) $rows .= '<tr><td class="f">' . $e($x['label']) . '</td><td class="f" style="text-align:right">' . $e($x['lessons']) . '</td><td class="f" style="text-align:right">' . $e($x['hours_attended']) . ' / ' . $e($x['hours_billed']) . ' h</td><td class="f" style="text-align:right">'
                    . $e($zl($x['charges'])) . '</td><td class="f" style="text-align:right">' . $e($zl($x['payments'])) . '</td><td class="f" style="text-align:right"><b>' . $e($zl($x['balance_end'])) . '</b></td></tr>';
                $t = $s['year_totals'] ?? ['charges' => 0, 'payments' => 0, 'hours_billed' => 0, 'hours_attended' => 0];
                $rows .= '<tr><td class="f"><b>Razem</b></td><td class="f"></td><td class="f" style="text-align:right"><b>' . $e($t['hours_attended']) . ' / ' . $e($t['hours_billed']) . ' h</b></td><td class="f" style="text-align:right"><b>' . $e($zl($t['charges'])) . '</b></td><td class="f" style="text-align:right"><b>' . $e($zl($t['payments'])) . '</b></td><td class="f"></td></tr>';
                $h .= '<table class="t">' . $sec('B', 'ROZLICZENIE ROCZNE ' . $d['year'] . ' — STAN Z KSIĘGI', 6) . $th(['Miesiąc', 'Zajęcia', 'Godziny (obecność / rozliczone)', 'Należności', 'Wpłaty', 'Saldo na koniec']) . ($rows ?: '<tr><td class="f" colspan="6">brak operacji w roku</td></tr>') . '</table>';
            }
        } else {
            if (!empty($s['group'])) {
                $g = $s['group'];
                $h .= '<table class="t">' . $sec('B', 'ROZLICZENIE GRUPY: ' . $g['name'], 3) . '<tr>' . $fld('Zajęcia z obecnością / godziny', $e($g['lessons'] . ' / ' . $g['hours_attended'] . ' h'), ' width="33%"')
                    . $fld('Godziny rozliczone', $e($g['hours_billed'] . ' h'), ' width="33%"') . $fld('Należności grupy', $e($zl($g['charges'])), ' width="34%"') . '</tr><tr>'
                    . $fld('Wpłaty przypisane grupie', $e($zl($g['payments'])), '') . $fld('Nadpłata grupy', $e($zl($g['credit'])), '') . $fld('Do zapłaty', $e($zl($g['debt'])), '') . '</tr></table>';
            }
            if (!empty($s['groups'])) {
                $rows = '';
                foreach ($s['groups'] as $g) $rows .= '<tr><td class="f">' . $e($g['name']) . '</td><td class="f" style="text-align:right">' . $e(isset($g['hours_attended']) ? $g['hours_attended'] . ' h' : '—') . '</td><td class="f" style="text-align:right">' . $e($zl($g['charges']))
                    . '</td><td class="f" style="text-align:right">' . $e($zl($g['payments'])) . '</td><td class="f" style="text-align:right">' . $e($zl($g['credit'] - $g['debt'])) . '</td></tr>';
                $h .= '<table class="t">' . $sec('B', 'ROZLICZENIE WSZYSTKICH GRUP', 5) . $th(['Grupa', 'Godziny', 'Należności', 'Wpłaty', 'Saldo']) . $rows . '</table>';
                if (!empty($s['resources'])) {
                    $rr = '';
                    foreach ($s['resources'] as $x) $rr .= '<tr><td class="f">' . $e($x['group']) . '</td><td class="f">' . $e($x['name']) . '</td><td class="f">' . $e($x['status']) . '</td><td class="f">' . $e(ldDate($x['to']) ?: 'bezterminowo') . '</td></tr>';
                    $h .= '<table class="t">' . $sec('C', 'LICENCJE I USŁUGI DO ZAMKNIĘCIA (WG AMZ)', 4) . $th(['Rodzaj', 'Nazwa', 'Status', 'Do']) . $rr . '</table>';
                }
            }
            $bal = (float)($s['balance'] ?? 0);
            $h .= '<table class="t" style="page-break-inside:avoid">' . $sec('D', 'WYNIK ROZLICZENIA KOŃCOWEGO', 2) . '<tr>' . $fld('Saldo końcowe', '<b>' . $e($zl($bal)) . '</b>', ' width="40%"')
                . $fld('Wynik', $bal > 0.004 ? 'nadpłata ' . $e($zl($bal)) . ' — do zwrotu wnioskiem DOP1 albo zaliczenia' : ($bal < -0.004 ? 'do dopłaty ' . $e($zl(-$bal)) : 'rozliczono bez salda'), ' width="60%"') . '</tr></table>';
        }
        $h .= '<table class="t" style="page-break-inside:avoid">' . $sec('E', 'PODPISY') . '<tr>' . $fld('Wystawił(a)', '<b>' . $e($r['created_name']) . '</b><div class="esig">Podpisano elektronicznie w SZO: ' . $e(ldDate(substr((string)$r['created_at'], 0, 10)) . ' ' . substr((string)$r['created_at'], 11, 5)) . ($r['created_ip'] ? ', IP ' . $e($r['created_ip']) : '') . '</div>', ' width="50%" style="height:14mm"')
            . $fld('Zatwierdził(a)', $r['mode'] === 'direct' ? 'nie dotyczy (bez akceptacji)' : ($r['decided_at'] ? '<b>' . $e($r['decided_name']) . '</b><div class="esig">Zatwierdzono elektronicznie: ' . $e(ldDate(substr((string)$r['decided_at'], 0, 10)) . ' ' . substr((string)$r['decided_at'], 11, 5)) . ', IP ' . $e($r['decided_ip']) . '</div>' : ''), ' width="50%"') . '</tr></table>';
        return '<style>body{font-family:dejavusans;font-size:8.8pt}table.hdr{width:100%;border-collapse:collapse;margin-bottom:3mm}table.hdr td{vertical-align:top}.title{font-size:13pt;font-weight:bold;text-align:center;margin:2mm 0 1mm}.num{text-align:center;font-size:8.6pt;margin-bottom:3mm}table.t{width:100%;border-collapse:collapse;margin:0 0 2mm}table.t td{border:0.25mm solid #000;vertical-align:top}td.f{padding:0.8mm 1.6mm 1mm}td.sec{background:#bfbfbf;padding:1mm 1.6mm}.lb{font-size:6.6pt;color:#222;margin-bottom:0.4mm}.v{font-size:9.2pt}.small{font-size:7pt}.esig{font-size:6.6pt;color:#333;margin-top:0.8mm}</style>' . $h;
    }

    public static function render(array $r): array
    {
        $html = self::html($r);
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 10, 'margin_bottom' => 13, 'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $m->SetTitle($r['kind'] . ' — ' . $r['client_name']);
            $m->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr><td width="40%"><b>' . htmlspecialchars($r['kind']) . '</b> — ' . htmlspecialchars($r['kindEnum']->title()) . '</td><td width="40%" style="text-align:center">' . htmlspecialchars($r['number']) . '</td><td width="20%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
            $m->WriteHTML($html);
            return ['bytes' => $m->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (\Throwable $e) {
            error_log('settle pdf fallback: ' . $e->getMessage());
            return ['bytes' => '<!doctype html><meta charset="utf-8">' . $html, 'mime' => 'text/html', 'ext' => 'html'];
        }
    }
}
