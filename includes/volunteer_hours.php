<?php
/**
 * volunteer_hours.php — zaliczanie godzin z zadań (task_time_logs) do czasu wolontariatu.
 *
 * Model:
 *   umowy_wolontariat.godzin_z_zadan         — cache godzin z zarejestrowanego czasu zadań
 *   umowy_wolontariat.godzin_korekta         — ręczna korekta (+/- godziny, np. praca poza systemem)
 *   umowy_wolontariat.godzin_przepracowanych — pole WYLICZANE = godzin_z_zadan + godzin_korekta
 *
 * Atrybucja: zadanie ma logi czasu per użytkownik (task_time_logs.user_id → users.id).
 * Umowa wolontariatu dopasowywana jest do użytkownika po m365_user_id / m365_login / email
 * (ta sama logika co panel/index.php::panel_contracts).
 *
 * Wymaga: db(), db_one(), db_all() z includes/db.php
 */

if (defined('VOLUNTEER_HOURS_LOADED')) return;
define('VOLUNTEER_HOURS_LOADED', true);

/**
 * Zwraca listę users.id dopasowanych do danej umowy wolontariatu
 * (po m365_user_id, m365_login, email). Liczymy godziny samego wolontariusza,
 * NIE opiekuna prawnego (rodzic_email pomijamy celowo).
 *
 * @param array $row Wiersz umowy_wolontariat (musi zawierać m365_user_id/m365_login/email)
 * @return int[]
 */
function volunteer_match_user_ids(array $row): array
{
    $conds = [];
    $params = [];

    if (!empty($row['m365_user_id'])) {
        $conds[]  = "u.microsoft_id = ?";
        $params[] = $row['m365_user_id'];
    }
    $emails = array_unique(array_filter([
        trim((string)($row['m365_login'] ?? '')),
        trim((string)($row['email'] ?? '')),
    ]));
    foreach ($emails as $e) {
        $conds[]  = "u.email = ?";
        $params[] = $e;
        $conds[]  = "u.m365_login = ?";
        $params[] = $e;
    }
    if (!$conds) return [];

    try {
        $rows = db_all(
            "SELECT DISTINCT u.id FROM users u WHERE " . implode(' OR ', $conds),
            $params
        );
    } catch (\Throwable $e) {
        return [];
    }
    return array_map(fn($r) => (int)$r['id'], $rows);
}

/**
 * Sumuje zarejestrowany czas (duration_seconds) zakończonych logów zadań dla danych
 * użytkowników, opcjonalnie ograniczając do okresu obowiązywania umowy.
 *
 * @param int[]       $userIds
 * @param string|null $from  Data (YYYY-MM-DD) — start umowy (logi od tej daty włącznie)
 * @param string|null $to    Data (YYYY-MM-DD) — koniec umowy (logi do końca tego dnia)
 * @return int sekundy
 */
function volunteer_tracked_seconds(array $userIds, ?string $from = null, ?string $to = null): int
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) return 0;

    $ph     = implode(',', array_fill(0, count($userIds), '?'));
    $where  = ["tl.user_id IN ($ph)", "tl.ended_at IS NOT NULL", "tl.duration_seconds IS NOT NULL"];
    $params = $userIds;

    if ($from) {
        $where[]  = "tl.started_at >= ?";
        $params[] = substr($from, 0, 10) . ' 00:00:00';
    }
    if ($to) {
        $where[]  = "tl.started_at <= ?";
        $params[] = substr($to, 0, 10) . ' 23:59:59';
    }

    try {
        $r = db_one(
            "SELECT COALESCE(SUM(tl.duration_seconds), 0) AS s
             FROM task_time_logs tl
             WHERE " . implode(' AND ', $where),
            $params
        );
    } catch (\Throwable $e) {
        return 0;
    }
    return (int)($r['s'] ?? 0);
}

/**
 * Przelicza i zapisuje godziny dla jednej umowy wolontariatu.
 * Zwraca rozbicie ['z_zadan' => float, 'korekta' => float, 'total' => float, 'user_ids' => int[]].
 */
function volunteer_recompute_hours(int $contract_id, ?array $row = null): array
{
    if ($row === null) {
        $row = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$contract_id]);
    }
    if (!$row) {
        return ['z_zadan' => 0.0, 'korekta' => 0.0, 'total' => 0.0, 'user_ids' => []];
    }

    $uids = volunteer_match_user_ids($row);
    $from = $row['data_rozpoczecia'] ?? $row['data_zawarcia'] ?? null;
    $to   = empty($row['bezterminowa']) ? ($row['data_zakonczenia'] ?? null) : null;

    $secs    = volunteer_tracked_seconds($uids, $from ?: null, $to ?: null);
    $z_zadan = round($secs / 3600, 2);
    $korekta = round((float)($row['godzin_korekta'] ?? 0), 2);
    $total   = round($z_zadan + $korekta, 2);

    try {
        db()->prepare(
            "UPDATE umowy_wolontariat SET godzin_z_zadan = ?, godzin_przepracowanych = ? WHERE id = ?"
        )->execute([$z_zadan, $total, $contract_id]);
    } catch (\Throwable $e) {
        // Kolumny mogą jeszcze nie istnieć (przed migracją) — pomiń cicho.
    }

    return ['z_zadan' => $z_zadan, 'korekta' => $korekta, 'total' => $total, 'user_ids' => $uids];
}

/**
 * Przelicza godziny wszystkich umów wolontariatu powiązanych z danym użytkownikiem.
 * Używane po zmianie logu czasu w module zadań (akcja stop/manual/delete).
 *
 * @return int liczba przeliczonych umów
 */
function volunteer_recompute_for_user(int $userId): int
{
    if ($userId <= 0) return 0;

    try {
        $u = db_one("SELECT email, microsoft_id, m365_login FROM users WHERE id = ?", [$userId]);
    } catch (\Throwable $e) {
        return 0;
    }
    if (!$u) return 0;

    $conds  = [];
    $params = [];
    if (!empty($u['microsoft_id'])) {
        $conds[]  = "m365_user_id = ?";
        $params[] = $u['microsoft_id'];
    }
    $emails = array_unique(array_filter([
        trim((string)($u['email'] ?? '')),
        trim((string)($u['m365_login'] ?? '')),
    ]));
    foreach ($emails as $e) {
        $conds[]  = "email = ?";
        $params[] = $e;
        $conds[]  = "m365_login = ?";
        $params[] = $e;
    }
    if (!$conds) return 0;

    try {
        $contracts = db_all(
            "SELECT id FROM umowy_wolontariat WHERE " . implode(' OR ', $conds),
            $params
        );
    } catch (\Throwable $e) {
        return 0;
    }
    foreach ($contracts as $c) {
        volunteer_recompute_hours((int)$c['id']);
    }
    return count($contracts);
}
