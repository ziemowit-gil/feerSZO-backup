<?php
/**
 * cli/assign_uid.php — Nadanie numeru UID istniejącym umowom.
 *
 * UID = users.id. Docelowo konto (i UID) powstaje automatycznie przy tworzeniu
 * umowy. Ten skrypt jest backfillem dla umów zawartych WCZEŚNIEJ lub takich,
 * którym z jakiegoś powodu nie założono konta w tabeli `users` — nadaje im UID,
 * zakładając (nieaktywne hasłowo) konto powiązane po e-mailu / microsoft_id.
 *
 * Konto zakładane jest z rolą `viewer`, bez hasła (password = NULL) — użytkownik
 * ustawia je samodzielnie przez /user/verify_reset.php lub logując się przez M365.
 *
 * Użycie:
 *   php cli/assign_uid.php [--dry-run] [--type=wolontariat|zlecenie|praca|dzielo|all]
 *
 *   --dry-run   tylko raport, bez zapisu do bazy (zalecane na start)
 *   --type=...  ogranicz do jednego typu umowy (domyślnie: all)
 *
 * Przykład:
 *   php cli/assign_uid.php --dry-run
 *   php cli/assign_uid.php --type=wolontariat
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("Tylko CLI.\n"); }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

// ── Parametry ────────────────────────────────────────────────────────────────
$dry  = in_array('--dry-run', $argv, true);
$type = 'all';
foreach ($argv as $a) {
    if (strpos($a, '--type=') === 0) $type = substr($a, 7);
}

$MAP = [
    // typ          => [tabela,             kolumna e-mail, kolumna id M365]
    'wolontariat'   => ['umowy_wolontariat', 'email',       'm365_user_id'],
    'zlecenie'      => ['umowy_zlecenie',    'email',       'm365_user_id'],
    'praca'         => ['umowy_praca',       'email_login', null],
    'dzielo'        => ['umowy_dzielo',      'email',       'm365_user_id'],
];
$types = ($type === 'all') ? array_keys($MAP) : [$type];
foreach ($types as $t) {
    if (!isset($MAP[$t])) { die("Nieznany typ umowy: {$t}. Dozwolone: " . implode(', ', array_keys($MAP)) . ", all.\n"); }
}

echo "=== assign_uid.php " . ($dry ? "[DRY-RUN] " : "") . "===\n";
echo "Typy: " . implode(', ', $types) . "\n\n";

/** Czy istnieje kolumna w tabeli. */
function _col_exists(string $table, string $col): bool {
    try {
        foreach (db_all("PRAGMA table_info({$table})") as $c) {
            if (strcasecmp($c['name'], $col) === 0) return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

$total_created = 0; $total_linked = 0; $total_skipped = 0; $total_rows = 0;

foreach ($types as $t) {
    [$tbl, $email_col, $ms_col] = $MAP[$t];

    // Zbierz istniejące kolumny (nie każda tabela ma numer_umowy / imie_nazwisko)
    $has_ms      = $ms_col && _col_exists($tbl, $ms_col);
    $has_numer   = _col_exists($tbl, 'numer_umowy');
    $has_name    = _col_exists($tbl, 'imie_nazwisko');
    $has_scope   = _col_exists($tbl, 'portal_scope');

    $sel = "id, {$email_col} AS email"
         . ($has_ms    ? ", {$ms_col} AS ms_id"   : ", '' AS ms_id")
         . ($has_numer ? ", numer_umowy"          : ", '' AS numer_umowy")
         . ($has_name  ? ", imie_nazwisko"        : ", '' AS imie_nazwisko")
         . ($has_scope ? ", portal_scope"         : ", '' AS portal_scope");

    try {
        $rows = db_all("SELECT {$sel} FROM {$tbl}");
    } catch (\Throwable $e) {
        echo "  [{$t}] pominięto (brak tabeli {$tbl}?): {$e->getMessage()}\n";
        continue;
    }

    echo "── {$t} ({$tbl}): " . count($rows) . " umów ──\n";
    $created = 0; $linked = 0; $skipped = 0;

    foreach ($rows as $r) {
        $total_rows++;
        $email = trim((string)($r['email'] ?? ''));
        $ms_id = trim((string)($r['ms_id'] ?? ''));
        $name  = trim((string)($r['imie_nazwisko'] ?? '')) ?: $email;

        // 1) Konto już powiązane przez microsoft_id?
        if ($ms_id !== '') {
            $u = db_one("SELECT id FROM users WHERE microsoft_id = ?", [$ms_id]);
            if ($u) { $skipped++; continue; }
        }
        // 2) Konto po e-mailu?
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $u = db_one("SELECT id, microsoft_id FROM users WHERE LOWER(email) = LOWER(?)", [$email]);
            if ($u) {
                // Konto jest — uzupełnij microsoft_id jeśli brakuje (dowiązanie).
                if ($ms_id !== '' && empty($u['microsoft_id'])) {
                    echo "  ~ LINK   UID {$u['id']}  {$email}  (dowiązano microsoft_id)\n";
                    if (!$dry) db()->prepare("UPDATE users SET microsoft_id = ? WHERE id = ?")->execute([$ms_id, (int)$u['id']]);
                    $linked++;
                } else {
                    $skipped++;
                }
                continue;
            }
        }
        // 3) Brak konta — nadaj UID (załóż konto).
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "  ! POMIŃ  umowa {$tbl}#{$r['id']} — brak poprawnego e-maila, nie można nadać UID\n";
            $skipped++;
            continue;
        }

        if ($dry) {
            echo "  + UID?   (dry) {$email}  [{$name}]  umowa " . ($r['numer_umowy'] ?: $tbl . '#' . $r['id']) . "\n";
        } else {
            $new_uid = db_insert('users', [
                'name'         => $name,
                'email'        => $email,
                'password'     => null,
                'microsoft_id' => $ms_id ?: null,
                'role'         => 'viewer',
                'is_active'    => 1,
                'portal_scope' => ($r['portal_scope'] ?: null),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            echo "  + UID {$new_uid}  {$email}  [{$name}]  umowa " . ($r['numer_umowy'] ?: $tbl . '#' . $r['id']) . "\n";
            // Zapisz do dziennika, jeśli dostępny
            if (function_exists('log_system_action')) {
                @log_system_action((int)$new_uid, 'assign_uid_backfill',
                    "Nadano UID przy backfillu z umowy {$tbl}#{$r['id']}");
            }
        }
        $created++;
    }

    echo "  → nowe UID: {$created}, dowiązane: {$linked}, pominięte: {$skipped}\n\n";
    $total_created += $created; $total_linked += $linked; $total_skipped += $skipped;
}

echo "=== PODSUMOWANIE ===\n";
echo "Przetworzono umów : {$total_rows}\n";
echo "Nowe UID          : {$total_created}" . ($dry ? " (dry-run — NIE zapisano)" : "") . "\n";
echo "Dowiązane konta   : {$total_linked}\n";
echo "Pominięte         : {$total_skipped}\n";
if ($dry) echo "\nUruchom bez --dry-run, aby zapisać zmiany.\n";
