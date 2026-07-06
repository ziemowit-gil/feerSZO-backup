<?php
/**
 * cron/contract_auto_complete.php — Automatyczne kończenie umów po upływie terminu.
 *
 * Uruchamiaj raz dziennie (rejestracja: cron/dispatcher.php, agent 'contract_auto_complete').
 * Dla każdego typu umowy: jeśli minęła data zakończenia (dla umowy o dzieło —
 * termin oddania) i umowa jest wciąż w statusie "aktywnym", status zmienia się
 * automatycznie na "zakończona". Umowy oznaczone jako bezterminowe/bez daty,
 * zablokowane aneksem lub z oczekującym wnioskiem o rozwiązanie są pomijane.
 *
 * Wyjątek: dla umów zlecenia i wolontariackich (patrz $ROZLICZENIE_TYPES) minięcie
 * terminu przenosi status do "do rozliczenia" (nie od razu "zakończona") —
 * ostateczne zamknięcie po rozliczeniu jest już czynnością ręczną.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/approval.php';
require_once $base_dir . '/includes/termination.php';

$today = date('Y-m-d');
$ts    = date('Y-m-d H:i:s');

// typ => [tabela, kolumna daty końca, kolumna flagi "bezterminowa" (lub null gdy brak)]
$TYPES = [
    'wolontariat' => ['umowy_wolontariat', 'data_zakonczenia', 'bezterminowa'],
    'zlecenie'    => ['umowy_zlecenie',    'data_zakonczenia', null],
    'dzielo'      => ['umowy_dzielo',      'termin_oddania',   null],
    'uslugi'      => ['umowy_uslugi',      'data_zakonczenia', 'czas_nieokreslony'],
    'praca'       => ['umowy_praca',       'data_zakonczenia', null],
    'powierzenie' => ['umowy_powierzenie', 'data_zakonczenia', null],
    'inne'        => ['umowy_inne',        'data_zakonczenia', 'czas_nieokreslony'],
];

// Statusy uznawane za aktywne — tylko te podlegają automatycznemu zakończeniu
$ACTIVE_STATUSES = ['podpisana', 'w realizacji', 'zawieszona', 'do rozliczenia', 'obowiązująca'];

// Typy, dla których minięcie terminu przenosi status do "do rozliczenia" zamiast wprost do "zakończona"
$ROZLICZENIE_TYPES = ['wolontariat', 'zlecenie'];

$done    = 0;
$skipped = 0;
$errors  = 0;

echo "[{$ts}] Start: contract_auto_complete\n";

foreach ($TYPES as $type => [$table, $date_col, $flag_col]) {
    $is_rozliczenie_type = in_array($type, $ROZLICZENIE_TYPES, true);
    // Dla typów z etapem "do rozliczenia" ten status jest już celem — nie ma dalszego auto-przejścia
    $active_statuses = $is_rozliczenie_type
        ? array_values(array_diff($ACTIVE_STATUSES, ['do rozliczenia']))
        : $ACTIVE_STATUSES;

    try {
        $placeholders = implode(',', array_fill(0, count($active_statuses), '?'));
        $rows = db_all(
            "SELECT * FROM {$table} WHERE status IN ({$placeholders}) AND {$date_col} IS NOT NULL AND {$date_col} < ?",
            [...$active_statuses, $today]
        );
    } catch (\Throwable $e) {
        echo "[{$ts}] BŁĄD zapytania dla {$type}: " . $e->getMessage() . "\n";
        $errors++;
        continue;
    }

    foreach ($rows as $row) {
        $numer = $row['numer_umowy'] ?? ('#' . $row['id']);

        if ($flag_col && !empty($row[$flag_col])) {
            $skipped++; // oznaczona jako bezterminowa mimo ustawionej daty — nie ruszaj
            continue;
        }
        if (contract_is_locked($row)) {
            $skipped++; // zablokowana aneksem
            continue;
        }
        if (get_pending_termination_for_contract($type, (int)$row['id'])) {
            echo "[{$ts}] {$type} {$numer} — pominięto: oczekujący wniosek o rozwiązanie\n";
            $skipped++;
            continue;
        }

        $target_status = $is_rozliczenie_type ? 'do rozliczenia' : 'zakończona';
        $action        = $is_rozliczenie_type ? 'auto_do_rozliczenia' : 'auto_zakonczenie';
        $comment       = $is_rozliczenie_type
            ? 'Automatyczne przejście do rozliczenia — minął termin (' . $row[$date_col] . ')'
            : 'Automatyczne zakończenie — minął termin (' . $row[$date_col] . ')';

        try {
            db()->prepare("UPDATE {$table} SET status=?, updated_at=? WHERE id=?")
                ->execute([$target_status, $ts, $row['id']]);

            log_contract_action(
                $type,
                (int)$row['id'],
                0,
                $action,
                $comment
            );

            echo "[{$ts}] {$type} {$numer} → {$target_status} (termin: {$row[$date_col]})\n";
            $done++;
        } catch (\Throwable $e) {
            echo "[{$ts}] BŁĄD zakańczania {$type} {$numer}: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

echo "[{$ts}] Koniec. Zakończono: {$done}, pominięto: {$skipped}, błędy: {$errors}\n";
