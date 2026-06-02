<?php
/**
 * migrate_persons.php
 * Migracja danych osobowych z tabel umów do tabeli persons.
 * Uruchom jednorazowo z CLI lub przez przeglądarkę (wymaga roli admin).
 *
 * php migrate_persons.php
 */

$cli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/persons.php';

if (!$cli) {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Brak dostępu. Wymagana rola: admin.');
    }
}

function log_line(string $msg): void {
    global $cli;
    if ($cli) {
        echo $msg . PHP_EOL;
    } else {
        echo nl2br(htmlspecialchars($msg)) . "<br>\n";
        ob_flush(); flush();
    }
}

// Zbierz wszystkie osoby ze wszystkich tabel umów
$sources = [
    'zlecenie'   => ['table' => 'umowy_zlecenie',   'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'email' => 'email', 'telefon' => 'telefon', 'adres' => 'adres', 'data_urodzenia' => 'data_urodzenia', 'seria_dowodu' => 'seria_nr_dowodu', 'us' => 'urzad_skarbowy', 'bank' => 'rachunek_bankowy'],
    'dzielo'     => ['table' => 'umowy_dzielo',     'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'email' => 'email', 'telefon' => 'telefon', 'adres' => 'adres', 'data_urodzenia' => 'data_urodzenia', 'seria_dowodu' => 'seria_nr_dowodu', 'us' => 'urzad_skarbowy', 'bank' => 'rachunek_bankowy'],
    'wolontariat'=> ['table' => 'umowy_wolontariat','name' => 'imie_nazwisko', 'pesel' => 'pesel', 'email' => 'email', 'telefon' => 'telefon', 'adres' => 'adres', 'data_urodzenia' => 'data_urodzenia', 'seria_dowodu' => 'seria_nr_dowodu', 'us' => 'urzad_skarbowy', 'bank' => 'rachunek_bankowy'],
    'praca'      => ['table' => 'umowy_praca',      'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'email' => 'email', 'telefon' => 'telefon', 'adres' => 'adres', 'data_urodzenia' => 'data_urodzenia', 'seria_dowodu' => 'seria_nr_dowodu', 'us' => 'urzad_skarbowy', 'bank' => 'rachunek_bankowy'],
];

if (!$cli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Migracja osób</title></head><body><pre>\n";
}

log_line("=== Migracja danych osobowych do tabeli persons ===");
log_line("Start: " . date('Y-m-d H:i:s'));
log_line("");

// Kolekcja: pesel -> person_id, email -> person_id, name -> person_id
$by_pesel = [];
$by_email = [];
$by_name  = [];
$created  = 0;
$skipped  = 0;

// Kontrakt-rows do aktualizacji: [['table'=>..., 'id'=>..., 'person_id'=>...], ...]
$updates = [];

foreach ($sources as $type => $cfg) {
    $table = $cfg['table'];
    log_line("--- Tabela: $table ---");

    try {
        $rows = db_all("SELECT * FROM $table WHERE (person_id IS NULL OR person_id = '') AND imie_nazwisko != '' ORDER BY id");
    } catch (\Throwable $e) {
        log_line("  BŁĄD odczytu: " . $e->getMessage());
        continue;
    }

    log_line("  Znaleziono " . count($rows) . " wierszy bez powiązanej osoby.");

    foreach ($rows as $row) {
        $name  = trim($row[$cfg['name']] ?? '');
        $pesel = trim($row[$cfg['pesel']] ?? '');
        $email = strtolower(trim($row[$cfg['email']] ?? ''));

        if (!$name) {
            continue;
        }

        // Szukaj istniejącej osoby: najpierw PESEL, potem email, potem imię
        $person_id = null;

        if ($pesel && isset($by_pesel[$pesel])) {
            $person_id = $by_pesel[$pesel];
        } elseif ($email && isset($by_email[$email])) {
            $person_id = $by_email[$email];
        } elseif (isset($by_name[$name])) {
            $person_id = $by_name[$name];
        }

        if ($person_id === null) {
            // Sprawdź też w bazie (dla idempotentności przy ponownym uruchomieniu)
            if ($pesel) {
                $existing = db_one("SELECT id FROM persons WHERE pesel = ? AND pesel != ''", [$pesel]);
                if ($existing) {
                    $person_id = (int)$existing['id'];
                }
            }
            if ($person_id === null && $email) {
                $existing = db_one("SELECT id FROM persons WHERE email = ? AND email != ''", [$email]);
                if ($existing) {
                    $person_id = (int)$existing['id'];
                }
            }
            if ($person_id === null) {
                $existing = db_one("SELECT id FROM persons WHERE imie_nazwisko = ?", [$name]);
                if ($existing) {
                    $person_id = (int)$existing['id'];
                }
            }
        }

        if ($person_id === null) {
            // Utwórz nową osobę
            $data = [
                'imie_nazwisko'   => $name,
                'pesel'           => $pesel,
                'email'           => $email,
                'telefon'         => trim($row[$cfg['telefon']] ?? ''),
                'adres'           => trim($row[$cfg['adres']] ?? ''),
                'data_urodzenia'  => trim($row[$cfg['data_urodzenia']] ?? ''),
                'seria_nr_dowodu' => trim($row[$cfg['seria_dowodu']] ?? ''),
                'urzad_skarbowy'  => trim($row[$cfg['us']] ?? ''),
                'rachunek_bankowy'=> trim($row[$cfg['bank']] ?? ''),
                'created_by'      => 0,
            ];
            try {
                $person_id = db_insert('persons', $data);
                log_line("  [DODANO] id=$person_id: $name" . ($pesel ? " (PESEL: $pesel)" : '') . ($email ? " <$email>" : ''));
                $created++;

                if ($pesel) $by_pesel[$pesel] = $person_id;
                if ($email) $by_email[$email] = $person_id;
                $by_name[$name] = $person_id;
            } catch (\Throwable $e) {
                log_line("  BŁĄD tworzenia osoby '$name': " . $e->getMessage());
                continue;
            }
        } else {
            log_line("  [ISTNIEJE] id=$person_id: $name — pomijam tworzenie.");
            $skipped++;
            if ($pesel && !isset($by_pesel[$pesel])) $by_pesel[$pesel] = $person_id;
            if ($email && !isset($by_email[$email])) $by_email[$email] = $person_id;
            if (!isset($by_name[$name])) $by_name[$name] = $person_id;
        }

        $updates[] = ['table' => $table, 'id' => (int)$row['id'], 'person_id' => $person_id];
    }
}

log_line("");
log_line("=== Aktualizacja person_id w tabelach umów ===");

$updated = 0;
foreach ($updates as $u) {
    try {
        db_query("UPDATE {$u['table']} SET person_id = ? WHERE id = ?", [$u['person_id'], $u['id']]);
        $updated++;
    } catch (\Throwable $e) {
        log_line("  BŁĄD UPDATE {$u['table']} id={$u['id']}: " . $e->getMessage());
    }
}

log_line("Zaktualizowano person_id w $updated wierszach umów.");
log_line("");
log_line("=== Podsumowanie ===");
log_line("Nowe osoby:       $created");
log_line("Istniejące:       $skipped");
log_line("Umowy powiązane:  $updated");
log_line("Koniec: " . date('Y-m-d H:i:s'));

if (!$cli) {
    echo "</pre></body></html>\n";
}
