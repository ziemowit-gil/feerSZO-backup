#!/usr/bin/env php
<?php
/**
 * cli/dedup_users.php — usuwa zduplikowane konta z tabeli users.
 *
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  UWAGA: TO JEST WYŁĄCZNIE TYMCZASOWE ROZWIĄZANIE.                  ║
 * ║                                                                      ║
 * ║  Docelowo duplikaty należy eliminować u źródła — przez walidację    ║
 * ║  unikalności e-maila przy tworzeniu konta (UNIQUE constraint lub    ║
 * ║  sprawdzenie w kodzie przed INSERT). Ten skrypt służy jedynie do    ║
 * ║  jednorazowego oczyszczenia bazy ze zduplikowanych rekordów         ║
 * ║  powstałych przed wdrożeniem właściwego zabezpieczenia.             ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * Kryterium duplikatu: ten sam adres e-mail (case-insensitive, trimowany).
 * Priorytet:           NAJNOWSZE konto (największe id) — "to utworzone" wygrywa.
 *
 * Dla każdego duplikatu:
 *  1. Dane ze starszych kont przenoszone są do konta z największym id.
 *  2. Tabele z unikalnym kluczem (np. task_notification_prefs) są
 *     obsługiwane osobno — dane konta docelowego mają pierwszeństwo.
 *  3. Starsze konta są usuwane.
 *
 * Użycie:
 *   php cli/dedup_users.php               # podgląd + potwierdzenie
 *   php cli/dedup_users.php --dry-run     # tylko pokaż, nic nie rób
 *   php cli/dedup_users.php --yes         # bez pytania
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("Tylko CLI.\n"); }

$opts    = getopt('', ['dry-run', 'yes']);
$dry     = isset($opts['dry-run']);
$noask   = isset($opts['yes']);

if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();

// ── Kolory ANSI ───────────────────────────────────────────────────────────────
$tty = stream_isatty(STDOUT);
function clr(string $t, string $c): string {
    global $tty;
    if (!$tty) return $t;
    return "\033[" . ['red'=>'31','yellow'=>'33','green'=>'32','cyan'=>'36','bold'=>'1','dim'=>'2'][$c] . "m{$t}\033[0m";
}

// ── Tabele — prosta aktualizacja user_id ───────────────────────────────────────
// Dla tych tabel wystarczy UPDATE user_id = $new WHERE user_id = $old
$SIMPLE_TABLES = [
    'admin_audit_log', 'announcement_reads',  'approval_workflow_approvers',
    'checkin_sessions', 'contract_audit_log', 'contract_supervisors',
    'ev_roles', 'ezd_log', 'k30_clients', 'k30_consultant_certs',
    'kdok_certificates', 'kdok_history', 'kdok_steps', 'kdok_user_roles',
    'login_log', 'm365_standalone_accounts', 'moodle_enrollments',
    'notifications', 'org_members', 'org_rules_ack',
    'resource_reservation_log', 'resource_reservations',
    'shipments', 'sms_login_tokens',
    'task_assignments', 'task_history', 'task_notification_log', 'task_time_logs',
    'timesheets', 'user_applications', 'volunteer_applications',
    'webauthn_credentials', 'zwroty_log',
];

// ── Tabele z unikalnym kluczem zawierającym user_id ───────────────────────────
// Strategia: jeśli konto docelowe ma już wiersz dla danego klucza → usuń ze starego.
//            Jeśli nie ma → przenieś (UPDATE user_id).
// Format: [ table, unique_col_beside_user_id ]   (null = user_id jest jedynym kluczem)
$UNIQUE_TABLES = [
    // [ tabela,                 drugi klucz uniq (obok user_id),  kolumna user_id ]
    ['task_notification_prefs',  null,                    'user_id'],   // PK = user_id
    ['task_workspace_members',   'workspace_id',          'user_id'],
    ['crm_group_users',          'group_id',              'user_id'],
    ['profile_field_values',     'field_def_id',          'user_id'],
    ['user_profiles',            null,                    'user_id'],
    ['user_sessions',            null,                    'user_id'],
];

// ── Pomocnik: sprawdź czy tabela istnieje ─────────────────────────────────────
function table_exists(PDO $pdo, string $t): bool {
    return (bool)$pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t)
    )->fetchColumn();
}

// ── Znajdź zduplikowane emaile ────────────────────────────────────────────────
$dups = $pdo->query(
    "SELECT LOWER(TRIM(email)) AS email_lc, COUNT(*) AS cnt
     FROM users
     WHERE email IS NOT NULL AND TRIM(email) != ''
     GROUP BY email_lc
     HAVING cnt > 1
     ORDER BY cnt DESC, email_lc"
)->fetchAll(PDO::FETCH_ASSOC);

echo "\n";
echo clr("╔══════════════════════════════════════════════════════════════╗\n", 'bold');
echo clr("║   dedup_users.php — usuwanie zduplikowanych kont            ║\n", 'bold');
echo clr("╚══════════════════════════════════════════════════════════════╝\n", 'bold');
echo "\n";
echo clr("  ⚠  TYMCZASOWE ROZWIĄZANIE\n", 'yellow');
echo clr("     Skrypt służy do jednorazowego oczyszczenia bazy.\n", 'dim');
echo clr("     Docelowo duplikaty powinny być blokowane przez UNIQUE\n", 'dim');
echo clr("     constraint lub walidację w kodzie przy tworzeniu konta.\n", 'dim');
echo "\n";

if (empty($dups)) {
    echo clr("  ✓ Brak zduplikowanych kont — baza jest spójna.\n\n", 'green');
    exit(0);
}

echo clr("  Znaleziono " . count($dups) . " grup duplikatów:\n\n", 'yellow');

// ── Szczegóły każdej grupy ────────────────────────────────────────────────────
$plan = []; // [ [keep => row, remove => [rows...]], ... ]

foreach ($dups as $d) {
    $email_lc = $d['email_lc'];
    $accounts = $pdo->prepare(
        "SELECT id, name, email, role, is_active, created_at, is_standalone_volunteer
         FROM users
         WHERE LOWER(TRIM(email)) = ?
         ORDER BY id DESC"  // Największe id = najnowsze = wygrywa
    );
    $accounts->execute([$email_lc]);
    $rows = $accounts->fetchAll(PDO::FETCH_ASSOC);

    $keep   = $rows[0];                   // najnowsze
    $remove = array_slice($rows, 1);     // starsze

    $plan[] = compact('keep', 'remove');

    echo "  " . clr($email_lc, 'cyan') . " (" . count($rows) . " kont)\n";
    echo clr("    ZACHOWA:  ", 'green')
        . "id={$keep['id']}  {$keep['name']}  [{$keep['role']}]"
        . ($keep['is_standalone_volunteer'] ? "  [standalone]" : "")
        . "  utworzono: {$keep['created_at']}\n";
    foreach ($remove as $r) {
        echo clr("    USUNIE:   ", 'red')
            . "id={$r['id']}  {$r['name']}  [{$r['role']}]"
            . ($r['is_standalone_volunteer'] ? "  [standalone]" : "")
            . "  utworzono: {$r['created_at']}\n";
    }
    echo "\n";
}

$total_remove = array_sum(array_map(fn($p) => count($p['remove']), $plan));
echo clr("  Łącznie do usunięcia: {$total_remove} kont\n\n", 'yellow');

if ($dry) {
    echo clr("  (--dry-run — nic nie zmieniono)\n\n", 'dim');
    exit(0);
}

// ── Potwierdzenie ─────────────────────────────────────────────────────────────
if (!$noask) {
    echo "  Kontynuować? Wpisz  " . clr("TAK", 'bold') . ": ";
    if (trim(fgets(STDIN)) !== 'TAK') {
        echo clr("\n  Anulowano.\n\n", 'yellow');
        exit(1);
    }
    echo "\n";
}

// ── Przetwarzanie ─────────────────────────────────────────────────────────────
$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->beginTransaction();

$moved_rows = 0;
$deleted    = 0;
$errors     = [];

foreach ($plan as $p) {
    $new_id = (int)$p['keep']['id'];
    $email  = $p['keep']['email'];

    foreach ($p['remove'] as $old) {
        $old_id = (int)$old['id'];

        echo "  Migracja id={$old_id} → id={$new_id}  ({$email})\n";

        // ── 1. Proste tabele — UPDATE user_id ─────────────────────────────────
        foreach ($SIMPLE_TABLES as $tbl) {
            if (!table_exists($pdo, $tbl)) continue;
            try {
                $n = $pdo->prepare("UPDATE {$tbl} SET user_id=? WHERE user_id=?");
                $n->execute([$new_id, $old_id]);
                $cnt = $n->rowCount();
                if ($cnt) {
                    echo clr("    · {$tbl}: przeniesiono {$cnt} wierszy\n", 'dim');
                    $moved_rows += $cnt;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$tbl}: " . $e->getMessage();
                echo clr("    ✗ {$tbl}: " . $e->getMessage() . "\n", 'red');
            }
        }

        // ── 2. Tabele z unikalnym kluczem ─────────────────────────────────────
        foreach ($UNIQUE_TABLES as [$tbl, $other_col, $uid_col]) {
            if (!table_exists($pdo, $tbl)) continue;
            try {
                if ($other_col === null) {
                    // user_id jest jedynym kluczem (PK lub UNIQUE)
                    $has_new = (bool)$pdo->prepare(
                        "SELECT 1 FROM {$tbl} WHERE {$uid_col}=? LIMIT 1"
                    )->execute([$new_id]) && $pdo->prepare(
                        "SELECT 1 FROM {$tbl} WHERE {$uid_col}=? LIMIT 1"
                    )->execute([$new_id]) && (bool)$pdo->query(
                        "SELECT 1 FROM {$tbl} WHERE {$uid_col}={$new_id} LIMIT 1"
                    )->fetchColumn();

                    if ($has_new) {
                        // Konto docelowe ma już wiersz — usuń stare
                        $pdo->prepare("DELETE FROM {$tbl} WHERE {$uid_col}=?")->execute([$old_id]);
                        echo clr("    · {$tbl}: usunięto stary wiersz (cel ma już wpis)\n", 'dim');
                    } else {
                        $n = $pdo->prepare("UPDATE {$tbl} SET {$uid_col}=? WHERE {$uid_col}=?");
                        $n->execute([$new_id, $old_id]);
                        if ($n->rowCount()) {
                            echo clr("    · {$tbl}: przeniesiono wiersz\n", 'dim');
                            $moved_rows += $n->rowCount();
                        }
                    }
                } else {
                    // Sprawdź konflikty wiersz po wierszu
                    $old_rows = $pdo->prepare(
                        "SELECT * FROM {$tbl} WHERE {$uid_col}=?"
                    );
                    $old_rows->execute([$old_id]);
                    foreach ($old_rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $other_val = $row[$other_col];
                        $conflict  = (bool)$pdo->prepare(
                            "SELECT 1 FROM {$tbl} WHERE {$uid_col}=? AND {$other_col}=? LIMIT 1"
                        )->execute([$new_id, $other_val]) && (bool)$pdo->query(
                            "SELECT 1 FROM {$tbl} WHERE {$uid_col}={$new_id} AND {$other_col}=" . $pdo->quote($other_val) . " LIMIT 1"
                        )->fetchColumn();

                        if ($conflict) {
                            // Cel ma już ten same wpis — usuń ze starego
                            $pdo->prepare(
                                "DELETE FROM {$tbl} WHERE {$uid_col}=? AND {$other_col}=?"
                            )->execute([$old_id, $other_val]);
                        } else {
                            $pdo->prepare(
                                "UPDATE {$tbl} SET {$uid_col}=? WHERE {$uid_col}=? AND {$other_col}=?"
                            )->execute([$new_id, $old_id, $other_val]);
                            $moved_rows++;
                        }
                    }
                    echo clr("    · {$tbl}: obsłużono\n", 'dim');
                }
            } catch (\Throwable $e) {
                $errors[] = "{$tbl}: " . $e->getMessage();
                echo clr("    ✗ {$tbl}: " . $e->getMessage() . "\n", 'red');
            }
        }

        // ── 3. Usuń stare konto ────────────────────────────────────────────────
        try {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$old_id]);
            echo clr("    ✓ Usunięto konto id={$old_id}\n", 'green');
            $deleted++;
        } catch (\Throwable $e) {
            $errors[] = "users id={$old_id}: " . $e->getMessage();
            echo clr("    ✗ Nie można usunąć id={$old_id}: " . $e->getMessage() . "\n", 'red');
        }

        echo "\n";
    }
}

if (!empty($errors)) {
    $pdo->rollBack();
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo clr("\n  Wystąpiły błędy — transakcja wycofana. Nic nie zostało zmienione.\n\n", 'red');
    exit(1);
}

$pdo->commit();
$pdo->exec("PRAGMA foreign_keys = ON");

// ── Podsumowanie ──────────────────────────────────────────────────────────────
echo clr("╔══════════════════════════════════════════════════════════════╗\n", 'green');
echo clr("║  Gotowe                                                      ║\n", 'green');
echo clr("╚══════════════════════════════════════════════════════════════╝\n", 'green');
echo "\n";
echo clr("  Usuniętych kont:        ", 'bold') . $deleted     . "\n";
echo clr("  Przeniesionych wierszy: ", 'bold') . $moved_rows  . "\n";
echo "\n";
