<?php
/**
 * includes/crm_retention.php — retencja danych osobowych w CRM i anonimizacja.
 *
 * RODO nie pozwala trzymać danych osobowych bezterminowo „na wszelki wypadek".
 * CRM nie miał na to niczego: lead z formularza sprzed pięciu lat, który nigdy
 * nie odpowiedział, wciąż leżał w bazie z imieniem, telefonem i adresem.
 *
 * Model: REGUŁY (kogo dotyczy, po ilu miesiącach bez kontaktu) → KANDYDACI
 * (wyliczani na żądanie) → ANONIMIZACJA (nieodwracalna, po decyzji człowieka).
 *
 * TRZY ZASADY, KTÓRE TRZYMAJĄ TEN MODUŁ W RYZACH:
 *
 * 1. NIE USUWAMY WIERSZY. Kartoteka zostaje, znikają z niej dane osobowe.
 *    Usunięcie rekordu rozsypałoby statystyki, powiązania i historię spraw,
 *    a RODO wymaga usunięcia DANYCH, nie śladu po ich istnieniu.
 *
 * 2. WYJĄTKI PRAWNE MAJĄ PIERWSZEŃSTWO. Darowizna, faktura, otwarta sprawa albo
 *    powiązanie z beneficjentem programu to obowiązek przechowywania z innej
 *    ustawy (rachunkowość: 5 lat). Taka kartoteka NIGDY nie trafia do anonimizacji
 *    automatycznie — dane osobowe nie są tu jedynym interesem.
 *
 * 3. AUTOMAT NIE KASUJE SAM. Cron tylko OZNACZA kandydatów i raportuje.
 *    Anonimizację uruchamia człowiek, świadomie i z możliwością przejrzenia listy.
 *    Nieodwracalna operacja robiona po cichu w nocy to nie jest zgodność z RODO,
 *    tylko utrata danych z opóźnieniem.
 */

require_once __DIR__ . '/db.php';

/** Pola kartoteki czyszczone przy anonimizacji. */
const CRM_RETENTION_WIPE_FIELDS = [
    'email', 'telefon', 'telefon2', 'adres', 'ulica', 'nr_domu', 'nr_lokalu',
    'kod_pocztowy', 'miasto', 'pesel', 'data_urodzenia', 'notatka', 'stanowisko',
    'strona_www', 'avatar_initials', 'outlook_id',
];

function crm_retention_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_retention_rules (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT    NOT NULL,
            scope        TEXT    NOT NULL DEFAULT 'status',   -- status | source | type | any
            match_value  TEXT,
            months       INTEGER NOT NULL DEFAULT 24,
            is_active    INTEGER NOT NULL DEFAULT 1,
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}

    foreach ([
        "ALTER TABLE crm_contacts ADD COLUMN anonymized_at DATETIME",
        "ALTER TABLE crm_contacts ADD COLUMN retention_flagged_at DATETIME",
        "ALTER TABLE crm_contacts ADD COLUMN retention_hold INTEGER NOT NULL DEFAULT 0",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
}

function crm_retention_scopes(): array
{
    return [
        'status' => 'Kontakty o statusie',
        'source' => 'Kontakty ze źródła',
        'type'   => 'Kontakty typu',
        'any'    => 'Wszystkie kontakty',
    ];
}

/** @return array<int,array> Reguły retencji. */
function crm_retention_rules(bool $only_active = true): array
{
    crm_retention_migrate();
    try {
        return db_all("SELECT * FROM crm_retention_rules"
            . ($only_active ? " WHERE is_active=1" : '') . " ORDER BY months, id");
    } catch (\Throwable $e) { return []; }
}

function crm_retention_rule_save(array $d, ?int $id = null): array
{
    crm_retention_migrate();
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') return ['ok' => false, 'error' => 'Reguła musi mieć nazwę.'];

    $scope = isset(crm_retention_scopes()[$d['scope'] ?? '']) ? (string)$d['scope'] : 'status';
    $val   = trim((string)($d['match_value'] ?? ''));
    if ($scope !== 'any' && $val === '') {
        return ['ok' => false, 'error' => 'Wskaż, kogo reguła dotyczy.'];
    }
    // Poniżej sześciu miesięcy retencja przestaje być retencją, a staje się
    // kasowaniem bieżącej pracy — kontakt sprzed kwartału bywa po prostu świeży.
    $months = max(6, min(240, (int)($d['months'] ?? 24)));

    try {
        if ($id) {
            db()->prepare("UPDATE crm_retention_rules SET name=?, scope=?, match_value=?, months=?, is_active=? WHERE id=?")
                ->execute([$name, $scope, $val ?: null, $months, !empty($d['is_active']) ? 1 : 0, $id]);
        } else {
            db_insert('crm_retention_rules', [
                'name' => $name, 'scope' => $scope, 'match_value' => $val ?: null,
                'months' => $months, 'is_active' => 1,
                'created_by' => (int)(current_user()['id'] ?? 0) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        error_log('[crm_retention_rule_save] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się zapisać reguły.'];
    }
}

function crm_retention_rule_delete(int $id): bool
{
    try { db()->prepare("DELETE FROM crm_retention_rules WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/**
 * Warunek SQL wyłączający kartoteki, których nie wolno anonimizować.
 *
 * Powody są z innych ustaw niż RODO — dlatego lista jest twarda i nie ma
 * przełącznika „ignoruj wyjątki". Blokada ręczna (retention_hold) jest tu też,
 * bo bywają sprawy, o których system nie wie.
 */
function _crm_retention_exclusions(): string
{
    $keep_years = 5;   // rachunkowość: dokumenty przechowuje się 5 lat
    return "
        c.anonymized_at IS NULL
    AND COALESCE(c.retention_hold, 0) = 0
    AND NOT EXISTS (SELECT 1 FROM crm_cases k
                     WHERE k.contact_id = c.id AND k.status IN ('open','in_progress'))
    AND NOT EXISTS (SELECT 1 FROM donations d
                     WHERE d.contact_id = c.id
                       AND COALESCE(d.donation_date, d.created_at) > date('now', '-{$keep_years} years'))
    AND NOT EXISTS (SELECT 1 FROM invoices i
                     WHERE i.contact_id = c.id
                       AND COALESCE(i.issue_date, i.created_at) > date('now', '-{$keep_years} years'))
    AND NOT EXISTS (SELECT 1 FROM crm_beneficiary_links b WHERE b.contact_id = c.id)
    ";
}

/**
 * Kandydaci jednej reguły: brak jakiegokolwiek kontaktu od `months` miesięcy.
 *
 * „Ostatni kontakt" to najpóźniejsza z dat: ostatniej korespondencji, zmiany
 * kartoteki i jej założenia. Liczenie po samej dacie dodania kasowałoby kontakty,
 * z którymi rozmawiamy od lat, a po samej korespondencji — te, które ktoś
 * niedawno poprawiał ręcznie.
 */
function crm_retention_candidates(array $rule, int $limit = 500): array
{
    crm_retention_migrate();

    $where  = ['c.crm_active = 1'];
    $params = [];

    switch ((string)$rule['scope']) {
        case 'status': $where[] = 'c.status = ?'; $params[] = (string)$rule['match_value']; break;
        case 'source': $where[] = 'c.source = ?'; $params[] = (string)$rule['match_value']; break;
        case 'type':   $where[] = 'c.type = ?';   $params[] = (string)$rule['match_value']; break;
    }

    $months = max(6, (int)$rule['months']);
    $limit  = max(1, min(5000, $limit));

    // Warunek na „ostatni ruch" MUSI iść przez podzapytanie, a nie przez HAVING:
    // SQLite bez GROUP BY traktuje HAVING jak filtr na JEDNEJ grupie obejmującej
    // cały wynik i zwraca najwyżej jeden wiersz — zapytanie wyglądało poprawnie,
    // a po cichu gubiło wszystkich kandydatów poza pierwszym.
    $inner = "SELECT c.id, c.imie_nazwisko, c.email, c.status, c.source, c.created_at,
                     (SELECT MAX(sent_at) FROM crm_communications m WHERE m.contact_id = c.id) AS last_comm,
                     MAX(COALESCE((SELECT MAX(sent_at) FROM crm_communications m2 WHERE m2.contact_id = c.id), ''),
                         COALESCE(c.updated_at, ''), COALESCE(c.created_at, '')) AS last_touch
                FROM crm_contacts c
               WHERE " . implode(' AND ', $where) . "
                 AND " . _crm_retention_exclusions();

    $sql = "SELECT * FROM ({$inner}) t
             WHERE t.last_touch < datetime('now', '-{$months} months')
          ORDER BY t.last_touch
             LIMIT {$limit}";

    try { return db_all($sql, $params); }
    catch (\Throwable $e) { error_log('[crm_retention_candidates] ' . $e->getMessage()); return []; }
}

/** Kandydaci wszystkich aktywnych reguł, bez powtórzeń. */
function crm_retention_all_candidates(int $limit_per_rule = 500): array
{
    $out = [];
    foreach (crm_retention_rules() as $r) {
        foreach (crm_retention_candidates($r, $limit_per_rule) as $c) {
            $c['rule']      = $r['name'];
            $c['rule_id']   = (int)$r['id'];
            $c['months']    = (int)$r['months'];
            $out[(int)$c['id']] = $c;      // ta sama kartoteka może pasować do kilku reguł
        }
    }
    return array_values($out);
}

/**
 * Anonimizacja kartoteki. NIEODWRACALNA.
 *
 * Czyścimy pola osobowe, treści notatek i treści korespondencji. Zostaje szkielet:
 * identyfikator, typ, status, daty i liczniki — czyli to, co potrzebne do
 * statystyk i do wykazania, że dane usunięto, a nie że rekord zniknął.
 *
 * @return array{ok:bool,error:string}
 */
function crm_retention_anonymize(int $contact_id, string $reason = 'retencja'): array
{
    crm_retention_migrate();

    $c = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$c) return ['ok' => false, 'error' => 'Kartoteka nie istnieje.'];
    if (!empty($c['anonymized_at'])) return ['ok' => true, 'error' => ''];   // już zrobione

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // 1. Pola kartoteki — tylko te, które w tej bazie faktycznie istnieją
        $cols = array_map(static fn($x) => $x['name'], db_all("PRAGMA table_info(crm_contacts)"));
        $set  = ["imie_nazwisko = 'Dane usunięte (RODO)'", 'anonymized_at = ?', 'crm_active = 0'];
        $par  = [date('Y-m-d H:i:s')];
        foreach (CRM_RETENTION_WIPE_FIELDS as $f) {
            if (in_array($f, $cols, true)) $set[] = "{$f} = NULL";
        }
        $pdo->prepare("UPDATE crm_contacts SET " . implode(', ', $set) . " WHERE id = ?")
            ->execute(array_merge($par, [$contact_id]));

        // 2. Notatki i korespondencja — treść, nie sam fakt
        $pdo->prepare("UPDATE crm_notes SET body = '[treść usunięta — retencja danych]' WHERE contact_id = ?")
            ->execute([$contact_id]);
        $pdo->prepare("UPDATE crm_communications
                          SET body = '[treść usunięta — retencja danych]', body_html = NULL,
                              subject = '[usunięto]', from_name = NULL, from_email = NULL
                        WHERE contact_id = ?")->execute([$contact_id]);

        // 3. Osoby kontaktowe podmiotu to też dane osobowe
        try {
            $pdo->prepare("UPDATE crm_contact_persons
                              SET imie_nazwisko = 'Dane usunięte (RODO)', email = NULL,
                                  telefon = NULL, stanowisko = NULL, uwagi = NULL
                            WHERE contact_id = ?")->execute([$contact_id]);
        } catch (\Throwable $e) {}

        // 4. Tagi i przynależność do grup — same w sobie bywają informacją o osobie
        foreach (['crm_tags', 'crm_group_members'] as $t) {
            try { $pdo->prepare("DELETE FROM {$t} WHERE contact_id = ?")->execute([$contact_id]); }
            catch (\Throwable $e) {}
        }

        // 5. Ślad w historii zmian — musi zostać, to dowód wykonania obowiązku
        try {
            $who = function_exists('crm_user_display') ? crm_user_display(current_user()) : '';
            $pdo->prepare("INSERT INTO crm_contact_audit (contact_id, field, old_value, new_value, user_id, user_name, created_at)
                           VALUES (?, 'anonimizacja', ?, ?, ?, ?, ?)")
                ->execute([
                    $contact_id,
                    'dane osobowe kartoteki, notatek i korespondencji',
                    'usunięte — ' . $reason,
                    (int)(current_user()['id'] ?? 0) ?: null,
                    $who ?: 'system',
                    date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            // Ślad w historii jest DOWODEM wykonania obowiązku — jego brak to nie
            // drobiazg do przemilczenia, tylko powód, żeby wycofać całą operację
            throw $e;
        }

        $pdo->commit();
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[crm_retention_anonymize] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Anonimizacja nie powiodła się: ' . $e->getMessage()];
    }
}

/** Ręczna blokada: „tej kartoteki nie ruszać, mimo że reguła ją łapie". */
function crm_retention_set_hold(int $contact_id, bool $hold): bool
{
    crm_retention_migrate();
    try {
        db()->prepare("UPDATE crm_contacts SET retention_hold=? WHERE id=?")
            ->execute([$hold ? 1 : 0, $contact_id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Oznaczenie kandydatów — to robi cron. Anonimizuje wyłącznie człowiek. */
function crm_retention_flag(array $ids): int
{
    if (!$ids) return 0;
    crm_retention_migrate();
    $n = 0;
    $st = db()->prepare("UPDATE crm_contacts SET retention_flagged_at=? WHERE id=? AND retention_flagged_at IS NULL");
    foreach ($ids as $id) {
        try { $st->execute([date('Y-m-d H:i:s'), (int)$id]); $n += $st->rowCount(); }
        catch (\Throwable $e) {}
    }
    return $n;
}

/** Liczby do ekranu ustawień i do raportu crona. */
function crm_retention_summary(): array
{
    crm_retention_migrate();
    $one = static function (string $sql): int {
        try { return (int)(db_one($sql)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    };
    return [
        'rules'       => count(crm_retention_rules()),
        'flagged'     => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE retention_flagged_at IS NOT NULL AND anonymized_at IS NULL AND crm_active=1"),
        'anonymized'  => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE anonymized_at IS NOT NULL"),
        'held'        => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE COALESCE(retention_hold,0)=1"),
    ];
}
