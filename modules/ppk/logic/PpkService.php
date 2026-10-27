<?php
/**
 * modules/ppk/logic/PpkService.php — PPK przy umowie o pracę: historia ustawień (obowiązywanie od daty), deklaracje PPKR/PPKP, autozapis.
 *
 * Tabele (główna baza):
 *  - employee_ppk_settings     — wersje: contract_id + effective_from + status + stawki (bp). Wersji się nie edytuje, tylko dopisuje nową;
 *                                 ta sama data = zastąpienie wersji z tego dnia (wpis do audytu).
 *  - employee_ppk_declarations — druki: PPKR (rezygnacja z wpłat), PPKP (przystąpienie / wniosek o wpłaty); snapshot danych w JSON,
 *                                 numer PPKR/NNNN/RRRR. Złożenie druku (registerSubmission) dopisuje wersję ustawień.
 * Obliczenia: PpkCalculator (czysta logika). SZO nie ma silnika list płac — serwis daje previewMonth() i dane do wpięcia w przyszły silnik.
 */
require_once dirname(__DIR__) . '/lib/PpkCalculator.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class PpkService
{
    public const KIND_RESIGN = 'PPKR';
    public const KIND_JOIN   = 'PPKP';
    public const FORMS = ['papierowa', 'elektroniczna'];
    private const AUTO_MONTH_KEY = 'ppk_auto_enroll_month';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_ppk_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id INTEGER NOT NULL,
            effective_from DATE NOT NULL,
            status TEXT NOT NULL,
            employee_base_bp INTEGER NOT NULL DEFAULT 200,
            employee_add_bp INTEGER NOT NULL DEFAULT 0,
            employer_base_bp INTEGER NOT NULL DEFAULT 150,
            employer_add_bp INTEGER NOT NULL DEFAULT 0,
            source TEXT NOT NULL DEFAULT 'reczna',
            declaration_id INTEGER,
            note TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(contract_id, effective_from)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_ppk_declarations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            number TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'szkic',
            snapshot TEXT NOT NULL,
            declared_at DATE,
            effective_from DATE,
            form TEXT,
            proof TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            submitted_by INTEGER,
            submitted_at DATETIME
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ppk_decl_contract ON employee_ppk_declarations(contract_id)");
    }

    public static function contract(int $contractId): ?array
    {
        return db_one("SELECT * FROM umowy_praca WHERE id=?", [$contractId]);
    }

    private static function validDate(string $d): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    }

    // ── historia ustawień ───────────────────────────────────────────────────────────────────────────────────────────
    /** @return list<array<string,mixed>> od najnowszej */
    public static function versions(int $contractId): array
    {
        self::migrate();
        return db_all("SELECT * FROM employee_ppk_settings WHERE contract_id=? ORDER BY effective_from DESC, id DESC", [$contractId]);
    }

    /** @return list<array{effective_from:string,status:string,rates:PpkRates}> w formie dla PpkCalculator */
    public static function calcVersions(int $contractId): array
    {
        return array_map(static fn(array $r): array => [
            'effective_from' => (string)$r['effective_from'], 'status' => (string)$r['status'], 'rates' => PpkRates::fromArray($r),
        ], self::versions($contractId));
    }

    public static function current(int $contractId, ?string $date = null): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM employee_ppk_settings WHERE contract_id=? AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1",
            [$contractId, $date ?? date('Y-m-d')]);
    }

    /**
     * Dopisuje wersję. Rezygnacja i status „niepodlegający” zachowują ostatnie stawki (przydadzą się po autozapisie / ponownym przystąpieniu
     * ustawiane są wtedy od nowa). Ta sama data co istniejąca wersja = jej zastąpienie.
     * @throws InvalidArgumentException przy błędnych danych/stawkach
     */
    public static function addVersion(int $contractId, string $effectiveFrom, string $status, ?PpkRates $rates, string $source, ?int $declarationId, string $note, int $userId): int
    {
        self::migrate();
        if (!self::contract($contractId)) throw new InvalidArgumentException('Nie ma takiej umowy.');
        if (!self::validDate($effectiveFrom)) throw new InvalidArgumentException('Podaj poprawną datę obowiązywania.');
        if (!in_array($status, PpkCalculator::STATUSES, true)) throw new InvalidArgumentException('Nieznany status PPK.');
        if ($status === PpkCalculator::STATUS_PARTICIPANT) {
            $rates ??= new PpkRates();
            if ($errs = $rates->errors()) throw new InvalidArgumentException(implode(' ', $errs));
        } else {
            $prev = self::current($contractId, $effectiveFrom);
            $rates = $prev ? PpkRates::fromArray($prev) : new PpkRates();
        }
        $replaced = db_one("SELECT id FROM employee_ppk_settings WHERE contract_id=? AND effective_from=?", [$contractId, $effectiveFrom]);
        if ($replaced) db()->prepare("DELETE FROM employee_ppk_settings WHERE id=?")->execute([(int)$replaced['id']]);
        $a = $rates->toArray();
        db()->prepare("INSERT INTO employee_ppk_settings (contract_id, effective_from, status, employee_base_bp, employee_add_bp, employer_base_bp, employer_add_bp, source, declaration_id, note, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$contractId, $effectiveFrom, $status, $a['employee_base_bp'], $a['employee_add_bp'], $a['employer_base_bp'], $a['employer_add_bp'],
                       $source, $declarationId, $note !== '' ? mb_substr($note, 0, 500) : null, $userId ?: null]);
        $id = (int)db()->lastInsertId();
        self::syncContractFlag($contractId);
        audit_log('ppk.version_add', ['contract_id' => $contractId, 'from' => $effectiveFrom, 'status' => $status, 'source' => $source, 'replaced' => (bool)$replaced], PHP_SAPI === 'cli' ? 0 : $userId);
        return $id;
    }

    /** Flaga ppk w umowie (tak/nie w kartotece) = czy dziś obowiązuje wersja „uczestnik”. */
    public static function syncContractFlag(int $contractId): void
    {
        $cur = self::current($contractId);
        $flag = ($cur && $cur['status'] === PpkCalculator::STATUS_PARTICIPANT) ? 1 : 0;
        db()->prepare("UPDATE umowy_praca SET ppk=? WHERE id=?")->execute([$flag, $contractId]);
    }

    // ── autozapis ───────────────────────────────────────────────────────────────────────────────────────────────────
    public static function autoEnrollMonth(): int
    {
        $m = (int)(db_one("SELECT value FROM settings WHERE key_=?", [self::AUTO_MONTH_KEY])['value'] ?? 0);
        return $m >= 1 && $m <= 12 ? $m : 3;
    }

    /** Dopisuje wersję „uczestnik” z autozapisu, jeśli termin nadszedł. @return ?int id wersji albo null, gdy nic do zrobienia */
    public static function autoEnroll(int $contractId, ?string $today = null): ?int
    {
        $c = self::contract($contractId);
        if (!$c || ($c['status'] ?? '') !== 'obowiązująca') return null;
        $due = PpkCalculator::autoEnrollmentDue(self::calcVersions($contractId), $today ?? date('Y-m-d'), self::autoEnrollMonth());
        if ($due === null) return null;
        return self::addVersion($contractId, $due, PpkCalculator::STATUS_PARTICIPANT, new PpkRates(), 'autozapis', null, 'Ponowny autozapis', 0);
    }

    /** @return int ile umów zapisano ponownie */
    public static function autoEnrollAll(?string $today = null): int
    {
        self::migrate();
        $n = 0;
        foreach (db_all("SELECT DISTINCT s.contract_id FROM employee_ppk_settings s JOIN umowy_praca c ON c.id = s.contract_id WHERE c.status = 'obowiązująca'") as $r) {
            if (self::autoEnroll((int)$r['contract_id'], $today) !== null) $n++;
        }
        return $n;
    }

    // ── podgląd wpłat ───────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * Wpłaty za miesiąc dla umowy z jednej wypłaty (domyślnie wynagrodzenie brutto z umowy jako podstawa) albo z podanych wypłat.
     * @param ?list<array{date:string,base_gr:int}> $payments
     */
    public static function previewMonth(int $contractId, string $month, ?array $payments = null): array
    {
        $c = self::contract($contractId);
        if (!$c) throw new InvalidArgumentException('Nie ma takiej umowy.');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Miesiąc w formacie RRRR-MM.');
        if ($payments === null) {
            $gross = PpkCalculator::toGrosze((string)($c['wynagrodzenie_brutto'] ?? '0')) ?? 0;
            $payments = [['date' => date('Y-m-t', strtotime($month . '-01')), 'base_gr' => $gross]];
        }
        return PpkCalculator::calculateMonth(self::calcVersions($contractId), $payments);
    }

    // ── deklaracje PPKR / PPKP ──────────────────────────────────────────────────────────────────────────────────────
    /** @return list<array<string,mixed>> od najnowszej */
    public static function declarations(int $contractId): array
    {
        self::migrate();
        return db_all("SELECT * FROM employee_ppk_declarations WHERE contract_id=? ORDER BY id DESC", [$contractId]);
    }

    public static function declaration(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM employee_ppk_declarations WHERE id=?", [$id]);
    }

    private static function nextNumber(string $kind): string
    {
        $year = date('Y');
        $last = (int)(db_one("SELECT MAX(CAST(SUBSTR(number, ?, 4) AS INTEGER)) AS n FROM employee_ppk_declarations WHERE kind=? AND number LIKE ?",
            [strlen($kind) + 2, $kind, $kind . '/%/' . $year])['n'] ?? 0);
        return sprintf('%s/%04d/%s', $kind, $last + 1, $year);
    }

    /**
     * Tworzy szkic druku z danymi umowy i pracodawcy zapisanymi w snapshocie (poza PESEL-em — ten druk bierze z umowy w chwili wydruku).
     * PPKP: $rates = wybrane przez pracownika stawki (walidowane).
     */
    public static function createDeclaration(int $contractId, string $kind, ?PpkRates $rates, int $userId): int
    {
        self::migrate();
        if (!in_array($kind, [self::KIND_RESIGN, self::KIND_JOIN], true)) throw new InvalidArgumentException('Nieznany rodzaj druku.');
        $c = self::contract($contractId);
        if (!$c) throw new InvalidArgumentException('Nie ma takiej umowy.');
        if ($kind === self::KIND_JOIN) {
            $rates ??= new PpkRates();
            if ($errs = $rates->errors()) throw new InvalidArgumentException(implode(' ', $errs));
        }
        $addr = trim(implode(' ', array_filter([trim(($c['addr_street'] ?? '') . ' ' . ($c['addr_house'] ?? '') . (($c['addr_flat'] ?? '') !== '' ? '/' . $c['addr_flat'] : '')),
                                                  trim(($c['addr_postal'] ?? '') . ' ' . ($c['addr_city'] ?? ''))]))) ?: (string)($c['adres'] ?? '');
        $snap = [
            'employee_name' => (string)$c['imie_nazwisko'], 'address' => $addr,   // PESEL celowo NIE w snapshocie: kolumna umowy jest szyfrowana (field_crypto), druk czyta go na żywo
            'contract_number' => (string)$c['numer_umowy'], 'position' => (string)($c['stanowisko'] ?? ''),
            'employer_name' => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''),
            'rates' => $kind === self::KIND_JOIN ? $rates->toArray() : null,
        ];
        for ($try = 0; $try < 3; $try++) {
            try {
                db()->prepare("INSERT INTO employee_ppk_declarations (contract_id, kind, number, snapshot, created_by) VALUES (?,?,?,?,?)")
                    ->execute([$contractId, $kind, self::nextNumber($kind), json_encode($snap, JSON_UNESCAPED_UNICODE), $userId ?: null]);
                $id = (int)db()->lastInsertId();
                audit_log('ppk.declaration_create', ['contract_id' => $contractId, 'kind' => $kind, 'id' => $id], PHP_SAPI === 'cli' ? 0 : $userId);
                return $id;
            } catch (PDOException $e) {
                if ($try === 2 || !str_contains($e->getMessage(), 'UNIQUE')) throw $e;   // zderzenie numeru z równoległym zapisem — ponów
            }
        }
        throw new RuntimeException('Nie udało się nadać numeru druku.');
    }

    /**
     * Rejestruje ZŁOŻENIE druku przez pracownika i dopisuje wersję ustawień: PPKR → „rezygnacja”, PPKP → „uczestnik” ze stawkami z druku.
     * $effectiveFrom domyślnie = data złożenia. Forma elektroniczna wymaga potwierdzenia (np. identyfikator wiadomości lub podpisu).
     */
    public static function registerSubmission(int $declId, string $declaredAt, string $form, string $proof, ?string $effectiveFrom, int $userId): void
    {
        $d = self::declaration($declId);
        if (!$d) throw new InvalidArgumentException('Nie ma takiego druku.');
        if ($d['status'] !== 'szkic') throw new InvalidArgumentException('Ten druk został już złożony albo anulowany.');
        if (!self::validDate($declaredAt)) throw new InvalidArgumentException('Podaj poprawną datę złożenia.');
        if ($declaredAt > date('Y-m-d')) throw new InvalidArgumentException('Data złożenia nie może być w przyszłości.');
        if (!in_array($form, self::FORMS, true)) throw new InvalidArgumentException('Wybierz formę złożenia.');
        $proof = trim($proof);
        if ($form === 'elektroniczna' && $proof === '') throw new InvalidArgumentException('Przy oświadczeniu elektronicznym podaj potwierdzenie złożenia (np. identyfikator wiadomości lub podpisu).');
        $from = $effectiveFrom !== null && $effectiveFrom !== '' ? $effectiveFrom : $declaredAt;
        if (!self::validDate($from)) throw new InvalidArgumentException('Podaj poprawną datę obowiązywania.');

        $snap = json_decode((string)$d['snapshot'], true) ?: [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $note = $d['number'] . ($form === 'elektroniczna' ? ' (elektroniczne)' : ' (papierowe)');
            if ($d['kind'] === self::KIND_RESIGN) {
                self::addVersion((int)$d['contract_id'], $from, PpkCalculator::STATUS_RESIGNED, null, 'deklaracja', $declId, $note, $userId);
            } else {
                self::addVersion((int)$d['contract_id'], $from, PpkCalculator::STATUS_PARTICIPANT, PpkRates::fromArray($snap['rates'] ?? []), 'deklaracja', $declId, $note, $userId);
            }
            $pdo->prepare("UPDATE employee_ppk_declarations SET status='zlozona', declared_at=?, effective_from=?, form=?, proof=?, submitted_by=?, submitted_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$declaredAt, $from, $form, $proof !== '' ? mb_substr($proof, 0, 500) : null, $userId ?: null, $declId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        audit_log('ppk.declaration_submit', ['id' => $declId, 'kind' => $d['kind'], 'form' => $form, 'from' => $from], $userId);
    }

    public static function cancelDeclaration(int $declId, int $userId): void
    {
        $d = self::declaration($declId);
        if (!$d) throw new InvalidArgumentException('Nie ma takiego druku.');
        if ($d['status'] !== 'szkic') throw new InvalidArgumentException('Anulować można tylko druk, który nie został jeszcze złożony.');
        db()->prepare("UPDATE employee_ppk_declarations SET status='anulowana' WHERE id=?")->execute([$declId]);
        audit_log('ppk.declaration_cancel', ['id' => $declId], $userId);
    }
}
