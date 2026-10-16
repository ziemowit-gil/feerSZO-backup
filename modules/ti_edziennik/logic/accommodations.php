<?php
/**
 * modules/ti_edziennik/logic/accommodations.php — dostosowania kursanta TI (odpowiednik IPET) w e-dzienniku.
 *
 * Profil tyflo (k30_ti_accessibility_profiles) opisuje, czego kursant używa. Tu — jak go oceniać i sprawdzać:
 * wydłużony czas (25/50/100%), powiększony druk, czytnik ekranu, brajl, inne zalecenia. Tabela k30_ti_accommodations
 * (jeden wiersz na kursanta). Edycja w karcie kursanta (sekcja „Dostosowania”, dostęp jak do profilu tyflo:
 * prowadzący kursanta i kierownik), plakietka przy nazwisku w macierzy ocen i w zakładkach Testy / Egzaminy.
 * Egzaminy (includes/ti_exams.php) wydłużają twardy limit czasu o extra_time_pct; testy — czas sugerowany.
 * To dane o stanie zdrowia (art. 9 RODO): kursant i opiekun NIE widzą listy dostosowań, tylko wydłużony czas.
 * Zapis — audit_log('ti_dydaktyka.accommodations_saved') bez treści pola „inne”.
 */

const TI_ACCOM_EXTRA_TIME = [0 => 'bez wydłużenia', 25 => '+25%', 50 => '+50%', 100 => '+100% (dwukrotnie)'];
const TI_ACCOM_FLAGS = [
    'large_print'   => ['Powiększony druk / materiały', 'zoom-in'],
    'screen_reader' => ['Czytnik ekranu', 'volume-up'],
    'braille'       => ['Brajl', 'hand-index'],
];

function tiAccomMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('ti_accommodations', __FILE__)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_accommodations (
        client_id      INTEGER PRIMARY KEY REFERENCES k30_clients(id) ON DELETE CASCADE,
        extra_time_pct INTEGER NOT NULL DEFAULT 0,
        large_print    INTEGER NOT NULL DEFAULT 0,
        screen_reader  INTEGER NOT NULL DEFAULT 0,
        braille        INTEGER NOT NULL DEFAULT 0,
        other          TEXT    NOT NULL DEFAULT '',
        updated_by     INTEGER,
        updated_at     DATETIME
    )");
}

/** Dostosowania kursanta (puste wartości, gdy brak wpisu). */
function tiAccomGet(int $clientId): array
{
    tiAccomMigrate();
    $r = db_one("SELECT * FROM k30_ti_accommodations WHERE client_id=?", [$clientId]);
    return $r ?: ['client_id' => $clientId, 'extra_time_pct' => 0, 'large_print' => 0, 'screen_reader' => 0, 'braille' => 0, 'other' => '', 'updated_by' => null, 'updated_at' => null];
}

/** Dostosowania wielu kursantów naraz: [client_id => wiersz] (tylko ci, którzy mają jakiekolwiek). */
function tiAccomMany(array $clientIds): array
{
    tiAccomMigrate();
    $ids = array_values(array_filter(array_map('intval', $clientIds)));
    if (!$ids) return [];
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_accommodations WHERE client_id IN (" . implode(',', $ids) . ")") as $r) {
        if (tiAccomAny($r)) $out[(int)$r['client_id']] = $r;
    }
    return $out;
}

function tiAccomAny(array $a): bool
{
    return (int)$a['extra_time_pct'] > 0 || !empty($a['large_print']) || !empty($a['screen_reader']) || !empty($a['braille']) || trim((string)$a['other']) !== '';
}

/** Wydłużenie czasu w % (0 = brak). */
function tiAccomExtraTime(int $clientId): int
{
    try { return max(0, (int)tiAccomGet($clientId)['extra_time_pct']); } catch (\Throwable $e) { return 0; }
}

/** Limit w minutach po wydłużeniu (0 = bez limitu zostaje bez limitu). */
function tiAccomMinutes(int $minutes, int $extraPct): int
{
    return $minutes > 0 && $extraPct > 0 ? (int)ceil($minutes * (100 + $extraPct) / 100) : $minutes;
}

/** Krótki opis dla kadry, np. „+50% czasu, czytnik ekranu”. */
function tiAccomSummary(array $a): string
{
    $p = [];
    if ((int)$a['extra_time_pct'] > 0) $p[] = '+' . (int)$a['extra_time_pct'] . '% czasu';
    foreach (TI_ACCOM_FLAGS as $k => [$label]) if (!empty($a[$k])) $p[] = mb_strtolower($label);
    if (trim((string)$a['other']) !== '') $p[] = trim((string)$a['other']);
    return implode(', ', $p);
}

/** Plakietka dla kadry przy nazwisku (HTML) — '' gdy brak dostosowań. */
function tiAccomBadge(?array $a): string
{
    if (!$a || !tiAccomAny($a)) return '';
    $sum = tiAccomSummary($a);
    return '<span class="badge border bg-info-subtle text-info-emphasis border-info-subtle ms-1" title="' . h($sum) . '">'
         . '<i class="bi bi-universal-access me-1" aria-hidden="true"></i>dostosowania'
         . '<span class="visually-hidden">: ' . h($sum) . '</span></span>';
}

/**
 * Zapis z formularza karty kursanta. $actor jak w TiDydaktykaManager (id, staff, real_id); dostęp sprawdza wołający.
 * @return array{ok:bool, errors:list<string>}
 */
function tiAccomSave(array $actor, int $clientId, array $in): array
{
    tiAccomMigrate();
    $extra = (int)($in['extra_time_pct'] ?? 0);
    if (!isset(TI_ACCOM_EXTRA_TIME[$extra])) return ['ok' => false, 'errors' => ['Wybierz wydłużenie czasu z listy.']];
    $other = trim((string)($in['other'] ?? ''));
    if (mb_strlen($other) > 500) return ['ok' => false, 'errors' => ['Pole „Inne zalecenia” jest za długie (maks. 500 znaków).']];
    $row = ['extra_time_pct' => $extra, 'other' => $other];
    foreach (TI_ACCOM_FLAGS as $k => $_) $row[$k] = !empty($in[$k]) ? 1 : 0;
    db()->prepare("INSERT INTO k30_ti_accommodations (client_id, extra_time_pct, large_print, screen_reader, braille, other, updated_by, updated_at)
                   VALUES (?,?,?,?,?,?,?,datetime('now'))
                   ON CONFLICT(client_id) DO UPDATE SET extra_time_pct=excluded.extra_time_pct, large_print=excluded.large_print,
                       screen_reader=excluded.screen_reader, braille=excluded.braille, other=excluded.other,
                       updated_by=excluded.updated_by, updated_at=excluded.updated_at")
        ->execute([$clientId, $row['extra_time_pct'], $row['large_print'], $row['screen_reader'], $row['braille'], $row['other'], (int)$actor['id'] ?: null]);
    $details = ['client_id' => $clientId, 'extra_time_pct' => $extra, 'large_print' => $row['large_print'],
                'screen_reader' => $row['screen_reader'], 'braille' => $row['braille'], 'other' => $other !== ''];
    if (!empty($actor['real_id']) && (int)$actor['real_id'] !== (int)$actor['id']) $details['real_user_id'] = (int)$actor['real_id'];
    if (function_exists('audit_log')) audit_log('ti_dydaktyka.accommodations_saved', $details, (int)$actor['id']);
    return ['ok' => true, 'errors' => []];
}

/** Ramka dla kadry w zakładkach Testy / Egzaminy: kto w grupie ma dostosowania (HTML, '' gdy nikt). */
function tiAccomCourseNotice(int $courseId): string
{
    $rows = db_all("SELECT cl.id, cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
                     WHERE e.course_id = ? AND e.status = 'active' ORDER BY cl.name COLLATE NOCASE", [$courseId]);
    $acc  = tiAccomMany(array_column($rows, 'id'));
    if (!$acc) return '';
    $li = '';
    foreach ($rows as $r) if (isset($acc[(int)$r['id']])) {
        $li .= '<li><a href="kartaKursanta.php?client=' . (int)$r['id'] . '&amp;course=' . $courseId . '#dostosowania">' . h((string)$r['name']) . '</a>: '
             . h(tiAccomSummary($acc[(int)$r['id']])) . '</li>';
    }
    return '<div class="alert alert-info small py-2" role="note"><i class="bi bi-universal-access me-1" aria-hidden="true"></i>'
         . '<strong>Dostosowania w tej grupie</strong> (wydłużony czas egzaminów z limitem nalicza się automatycznie):'
         . '<ul class="mb-0 mt-1">' . $li . '</ul></div>';
}
