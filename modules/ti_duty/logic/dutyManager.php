<?php
/**
 * modules/ti_duty/logic/dutyManager.php — DK: dyżury kierownika / Zarządu (grafik + publikacja w portalu planu).
 *
 * Tabela k30_ti_duty:
 *   kind='weekly' — stały dyżur: dzień tygodnia (dow 1–7) + godziny, obowiązuje valid_from–valid_to (puste = bez ograniczeń),
 *   kind='date'   — dyżur w konkretnym dniu (duty_date); WSZYSTKIE wpisy „date” danego dnia zastępują stałe dyżury tego dnia,
 *                   a wpis z is_cancelled=1 oznacza „brak dyżuru” w tym dniu.
 * Osoba = wolny tekst (person_name) + funkcja (role_label), np. „Jan Kowalski” / „Prezes Zarządu”;
 * kontakt dyżurowy (telefon/e-mail) wpisywany przy dyżurze — nie z prywatnych danych konta.
 * Publicznie: inicjały + funkcja (RODO); pełne imię i nazwisko w widoku pełnym portalu (hasło kierownika).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/selfrepairDB/logic/selfRepairDB.php';

const TI_DUTY_ROLES = ['Kierownik', 'Zastępca kierownika', 'Prezes Zarządu', 'Wiceprezes Zarządu', 'Członek Zarządu', 'Koordynator'];

function tiDutyMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();
    if (szo_schema_current('ti_duty_migrate', __FILE__)) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_duty (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        kind          TEXT    NOT NULL DEFAULT 'weekly' CHECK (kind IN ('weekly','date')),
        dow           INTEGER,                              -- 1=Pn … 7=Nd (weekly)
        duty_date     TEXT,                                 -- Y-m-d (date)
        valid_from    TEXT,                                 -- weekly: od (opcjonalnie)
        valid_to      TEXT,                                 -- weekly: do (opcjonalnie)
        time_from     TEXT    NOT NULL DEFAULT '',
        time_to       TEXT    NOT NULL DEFAULT '',
        person_name   TEXT    NOT NULL DEFAULT '',
        role_label    TEXT    NOT NULL DEFAULT '',
        place         TEXT    NOT NULL DEFAULT '',          -- np. „biuro, ul. …”, „telefonicznie”, „online (Teams)”
        contact_phone TEXT    NOT NULL DEFAULT '',          -- telefon dyżurowy
        contact_email TEXT    NOT NULL DEFAULT '',
        note          TEXT    NOT NULL DEFAULT '',
        is_cancelled  INTEGER NOT NULL DEFAULT 0,           -- date: „brak dyżuru” w tym dniu
        created_by    INTEGER,
        created_at    DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at    DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_duty_kind ON k30_ti_duty(kind, dow, duty_date)");
}

/** Publikacja zakładki DK w portalu planu (przełącznik w planLinki.php). */
function tiDutyPublic(): bool {
    return org_setting('ti_duty_public') === '1';
}

function tiDutyAll(): array {
    tiDutyMigrate();
    return db_all("SELECT * FROM k30_ti_duty ORDER BY kind DESC, COALESCE(duty_date, ''), dow, time_from, id");
}

function tiDutyGet(int $id): ?array {
    tiDutyMigrate();
    return db_one("SELECT * FROM k30_ti_duty WHERE id=?", [$id]) ?: null;
}

/** Normalizacja i walidacja wpisu z formularza; zwraca [dane, błąd|''] */
function tiDutyValidate(array $in): array {
    $kind = ($in['kind'] ?? '') === 'date' ? 'date' : 'weekly';
    $time = fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string)$v)) ? trim((string)$v) : '';
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$v)) ? trim((string)$v) : null;
    $d = [
        'kind'          => $kind,
        'dow'           => $kind === 'weekly' ? max(1, min(7, (int)($in['dow'] ?? 1))) : null,
        'duty_date'     => $kind === 'date' ? $date($in['duty_date'] ?? '') : null,
        'valid_from'    => $kind === 'weekly' ? $date($in['valid_from'] ?? '') : null,
        'valid_to'      => $kind === 'weekly' ? $date($in['valid_to'] ?? '') : null,
        'time_from'     => $time($in['time_from'] ?? ''),
        'time_to'       => $time($in['time_to'] ?? ''),
        'person_name'   => mb_substr(trim((string)($in['person_name'] ?? '')), 0, 120),
        'role_label'    => mb_substr(trim((string)($in['role_label'] ?? '')), 0, 80),
        'place'         => mb_substr(trim((string)($in['place'] ?? '')), 0, 200),
        'contact_phone' => mb_substr(trim((string)($in['contact_phone'] ?? '')), 0, 40),
        'contact_email' => mb_substr(trim((string)($in['contact_email'] ?? '')), 0, 120),
        'note'          => mb_substr(trim((string)($in['note'] ?? '')), 0, 300),
        'is_cancelled'  => $kind === 'date' && !empty($in['is_cancelled']) ? 1 : 0,
    ];
    if ($kind === 'date' && !$d['duty_date']) return [$d, 'Podaj datę dyżuru.'];
    if ($d['is_cancelled']) return [$d, ''];   // „brak dyżuru” — bez godzin i osoby
    if ($d['time_from'] === '' || $d['time_to'] === '' || $d['time_to'] <= $d['time_from']) return [$d, 'Podaj poprawne godziny dyżuru (od < do).'];
    if ($d['person_name'] === '' && $d['role_label'] === '') return [$d, 'Podaj osobę albo funkcję dyżurnego.'];
    if ($d['contact_email'] !== '' && !filter_var($d['contact_email'], FILTER_VALIDATE_EMAIL)) return [$d, 'Niepoprawny e-mail dyżurowy.'];
    if ($d['valid_from'] && $d['valid_to'] && $d['valid_to'] < $d['valid_from']) return [$d, 'Data „do” jest wcześniejsza niż „od”.'];
    return [$d, ''];
}

function tiDutySave(array $d, int $by, ?int $id = null): int {
    tiDutyMigrate();
    $cols = ['kind', 'dow', 'duty_date', 'valid_from', 'valid_to', 'time_from', 'time_to', 'person_name', 'role_label', 'place', 'contact_phone', 'contact_email', 'note', 'is_cancelled'];
    $vals = array_map(fn($c) => $d[$c], $cols);
    if ($id) {
        db()->prepare('UPDATE k30_ti_duty SET ' . implode('=?, ', $cols) . "=?, updated_at=datetime('now') WHERE id=?")->execute(array_merge($vals, [$id]));
        return $id;
    }
    db()->prepare('INSERT INTO k30_ti_duty(' . implode(',', $cols) . ', created_by) VALUES(' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
        ->execute(array_merge($vals, [$by]));
    return (int)db()->lastInsertId();
}

function tiDutyDelete(int $id): void {
    tiDutyMigrate();
    db()->prepare("DELETE FROM k30_ti_duty WHERE id=?")->execute([$id]);
}

/**
 * Dyżury w zakresie dat (włącznie): [Y-m-d => ['cancelled' => bool, 'items' => [wpisy…]]].
 * Dzień z wpisami „date” bierze tylko je (nadpisują stałe); „brak dyżuru” → cancelled=true, items=[].
 */
function tiDutyOccurrences(string $from, string $to): array {
    tiDutyMigrate();
    $weekly = db_all("SELECT * FROM k30_ti_duty WHERE kind='weekly' ORDER BY time_from, id");
    $dated  = db_all("SELECT * FROM k30_ti_duty WHERE kind='date' AND duty_date BETWEEN ? AND ? ORDER BY time_from, id", [$from, $to]);
    $byDate = [];
    foreach ($dated as $r) $byDate[(string)$r['duty_date']][] = $r;
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        if (isset($byDate[$d])) {
            $items = array_values(array_filter($byDate[$d], fn($r) => !(int)$r['is_cancelled']));
            $out[$d] = ['cancelled' => !$items, 'items' => $items, 'special' => true];
            continue;
        }
        $dow = (int)date('N', $t);
        $items = array_values(array_filter($weekly, fn($r) => (int)$r['dow'] === $dow
            && (empty($r['valid_from']) || $r['valid_from'] <= $d) && (empty($r['valid_to']) || $r['valid_to'] >= $d)));
        if ($items) $out[$d] = ['cancelled' => false, 'items' => $items, 'special' => false];
    }
    return $out;
}

/** Dyżur trwający teraz albo najbliższy (do 14 dni naprzód): ['date' => …, 'item' => …, 'now' => bool] | null */
function tiDutyCurrentOrNext(): ?array {
    $now = date('H:i');
    foreach (tiDutyOccurrences(date('Y-m-d'), date('Y-m-d', strtotime('+14 days'))) as $d => $occ) {
        foreach ($occ['items'] as $it) {
            if ($d === date('Y-m-d') && $it['time_to'] <= $now) continue;
            return ['date' => $d, 'item' => $it, 'now' => $d === date('Y-m-d') && $it['time_from'] <= $now];
        }
    }
    return null;
}
