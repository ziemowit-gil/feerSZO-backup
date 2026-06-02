<?php
/**
 * Automatyczna synchronizacja kont M365.
 * Uruchamiany z poziomu CLI (cron), np. co 30 min.
 *
 * Logika:
 *  1. Zbiera wszystkich użytkowników z microsoft_id ze wszystkich tabel umów.
 *  2. Dla każdego user_id wyznacza docelowy stan (active/inactive) na podstawie
 *     WSZYSTKICH jego umów — aktywny jeśli choć jedna umowa jest aktywna.
 *  3. Wywołuje Graph API TYLKO gdy stan DB różni się od wyliczonego.
 *  4. Wyłącza konto w dniu wygaśnięcia ostatniej aktywnej umowy (≤ today).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/m365.php';

$today = date('Y-m-d');
echo "[{$today} " . date('H:i:s') . "] Synchronizacja M365 — start\n";

try {
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        throw new RuntimeException('M365 nie jest skonfigurowane.');
    }

    $tables = ['umowy_wolontariat', 'umowy_zlecenie', 'umowy_dzielo'];

    // ── Krok 1: zbierz wszystkie umowy z M365 per użytkownik ─────────────────
    // Klucz: m365_user_id → ['should' => bool, 'login' => str, 'contracts' => [...]]
    $users = [];

    foreach ($tables as $table) {
        try {
            $rows = db_all(
                "SELECT id, numer_umowy, m365_user_id, m365_login, m365_konto_aktywne,
                        status, bezterminowa,
                        data_rozpoczecia, data_zakonczenia, data_zawarcia, termin_oddania
                 FROM {$table}
                 WHERE m365_konto = 1
                   AND m365_user_id IS NOT NULL AND m365_user_id != ''"
            );
        } catch (\Throwable $e) {
            // Tabela może nie mieć wszystkich kolumn (np. umowy_dzielo)
            echo "  [WARN] Tabela {$table}: " . $e->getMessage() . "\n";
            continue;
        }

        foreach ($rows as $row) {
            $uid = $row['m365_user_id'];
            if (!isset($users[$uid])) {
                $users[$uid] = [
                    'login'      => $row['m365_login'] ?? '',
                    'db_active'  => (bool)$row['m365_konto_aktywne'],
                    'should'     => false,
                    'contracts'  => [],
                ];
            }
            $active = m365_should_be_active($row);
            $users[$uid]['contracts'][] = [
                'table'  => $table,
                'id'     => $row['id'],
                'numer'  => $row['numer_umowy'] ?? '',
                'active' => $active,
            ];
            // Aktywny jeśli choć jedna umowa jest aktywna
            if ($active) {
                $users[$uid]['should'] = true;
            }
            // db_active bierzemy z dowolnej umowy — aktualizujemy do pierwszej "aktywnej"
            if ($active) {
                $users[$uid]['db_active'] = (bool)$row['m365_konto_aktywne'];
            }
        }
    }

    if (empty($users)) {
        echo "  Brak kont M365 do sprawdzenia.\n";
    }

    $count_changed = 0;
    $count_skip    = 0;
    $count_errors  = 0;

    // ── Krok 2: przetwórz każdego użytkownika ────────────────────────────────
    foreach ($users as $m365_uid => $u) {
        $should    = $u['should'];
        $db_active = $u['db_active'];
        $login     = $u['login'];

        // Optymalizacja: jeśli DB mówi że stan jest zgodny — pomiń wywołanie Graph API
        // (Graph API wywołujemy tylko gdy wykrywamy rozbieżność lub dzisiaj coś się zmienia)
        $expiring_today = false;
        foreach ($u['contracts'] as $c) {
            // Sprawdź czy któraś umowa wygasa dokładnie dziś
            // (wtedy chcemy wymusić synchronizację nawet jeśli db_active jeszcze = 1)
        }
        // Pobierz daty zakończenia umów aktywnych wczoraj, a nieaktywnych dziś
        foreach ($tables as $table) {
            try {
                $exp = db_one(
                    "SELECT COUNT(*) AS n FROM {$table}
                     WHERE m365_user_id = ?
                       AND m365_konto = 1
                       AND (data_zakonczenia = ? OR termin_oddania = ?)
                       AND bezterminowa = 0",
                    [$m365_uid, $today, $today]
                );
                if ($exp && (int)$exp['n'] > 0) { $expiring_today = true; break; }
            } catch (\Throwable $e) {}
        }

        // Pomiń Graph API jeśli stan DB zgadza się z oczekiwanym i nic nie wygasa dziś
        if ($db_active === $should && !$expiring_today) {
            $count_skip++;
            continue;
        }

        try {
            // Odpytaj Graph API o aktualny stan
            $ms_info    = $graph->get_user_by_id($m365_uid);
            $ms_enabled = isset($ms_info['accountEnabled']) ? (bool)$ms_info['accountEnabled'] : null;

            if ($ms_enabled === null) {
                echo "  [WARN] {$login} — nie można odczytać stanu konta\n";
                $count_errors++;
                continue;
            }

            // Brak licencji → wymuś wyłączenie niezależnie od umowy
            $has_license = !empty($ms_info['assignedLicenses'] ?? []);
            if (!$has_license && $should) {
                $should = false;
                echo "  [WARN] {$login} — brak licencji M365, wymuszam wyłączenie\n";
            }

            if ($ms_enabled === $should) {
                // Stan w chmurze jest poprawny — wyrównaj tylko DB
                foreach ($u['contracts'] as $c) {
                    db()->prepare("UPDATE {$c['table']} SET m365_konto_aktywne=? WHERE id=?")
                        ->execute([$should ? 1 : 0, $c['id']]);
                }
                $count_skip++;
                continue;
            }

            // Zmień stan w Azure AD
            $graph->set_enabled($m365_uid, $should);

            // Zaktualizuj flagę we wszystkich powiązanych umowach
            foreach ($u['contracts'] as $c) {
                db()->prepare("UPDATE {$c['table']} SET m365_konto_aktywne=? WHERE id=?")
                    ->execute([$should ? 1 : 0, $c['id']]);
            }

            $action = $should ? 'WŁĄCZONO' : 'WYŁĄCZONO';
            $reason = !$has_license ? 'brak licencji'
                    : ($expiring_today ? 'wygaśnięcie umowy dziś' : 'zmiana statusu umowy');
            echo "  [OK] {$login} — {$action} ({$reason})\n";
            $count_changed++;

        } catch (\Throwable $e) {
            echo "  [BŁĄD] {$login} — " . $e->getMessage() . "\n";
            $count_errors++;
        }
    }

    $summary = "Zmieniono: {$count_changed} | Pominięto (bez zmian): {$count_skip} | Błędów: {$count_errors}";
    echo "[" . date('H:i:s') . "] Zakończono — {$summary}\n";

    m365_save_setting('m365_last_cron_sync', date('Y-m-d H:i:s'));

} catch (\Throwable $e) {
    echo "[" . date('H:i:s') . "] BŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
    exit(1);
}
