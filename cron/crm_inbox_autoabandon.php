<?php
/**
 * cron/crm_inbox_autoabandon.php — automatyczne porzucanie starych wiadomości w Skrzynce CRM.
 *
 * Wiadomość przychodząca, której przez 3 miesiące nikt nie tknął, przestaje być
 * pracą do zrobienia — zostaje porzucona, czyli dostaje `crm_hidden=1` i znika
 * z widoków Skrzynki CRM (widok „Ukryte" pokazuje ją dalej i pozwala przywrócić).
 *
 * NIE kasujemy niczego i nie ruszamy `inbox_status` — tę samą wiadomość widzą
 * moduł Poczta i EZD; to filtr wyłącznie CRM-owy.
 *
 * „Bez akcji" = przychodząca, aktywna (nie załatwiona, nie spam), bez opiekuna
 * i bez powiązania z EZD. Wiadomość przypisana komuś albo dopięta do koszulki
 * jest prowadzona — takiej nie porzucamy, choćby leżała rok.
 *
 * Próg w dniach: settings `crm_inbox_abandon_days` (domyślnie 90; 0 = wyłącz).
 *
 * Uruchamiany przez cron/dispatcher.php raz dziennie w nocy.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_mailbox.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_inbox_autoabandon\n";

crm_mailbox_schema_heal();   // kolumny crm_hidden* bywają młodsze niż baza

$days = (int)(crm_setting('crm_inbox_abandon_days') ?: 90);
if ($days <= 0) {
    echo "  · wyłączone (crm_inbox_abandon_days = 0)\n";
    return;
}

// Kolumna ezd_sprawa_id pochodzi z modułu EZD — na bazie bez EZD jej nie ma.
$has_ezd = false;
try {
    foreach (db_all("PRAGMA table_info(crm_communications)") as $c) {
        if (($c['name'] ?? '') === 'ezd_sprawa_id') { $has_ezd = true; break; }
    }
} catch (\Throwable $e) {}

$sql = "SELECT id, subject, from_email, sent_at, msg_no
        FROM crm_communications
        WHERE direction='in'
          AND COALESCE(crm_hidden,0)=0
          AND inbox_status='active'
          AND (assigned_to IS NULL OR assigned_to=0)
          " . ($has_ezd ? 'AND ezd_sprawa_id IS NULL' : '') . "
          AND sent_at IS NOT NULL
          AND sent_at <= datetime('now','-{$days} days')
        ORDER BY sent_at ASC
        LIMIT 2000";

$rows = [];
try { $rows = db_all($sql); } catch (\Throwable $e) {
    echo '  ✗ błąd zapytania: ' . $e->getMessage() . "\n";
    return;
}

if (!$rows) {
    echo "  · brak wiadomości starszych niż {$days} dni bez akcji\n";
    echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_inbox_autoabandon\n";
    return;
}

// crm_mailbox_set_hidden() zapisuje autora (current_user), a tu autora nie ma —
// crm_hidden_by zostaje NULL i to właśnie odróżnia porzucenie automatyczne od ręcznego.
$now  = date('Y-m-d H:i:s');
$stmt = db()->prepare("UPDATE crm_communications SET crm_hidden=1, crm_hidden_at=?, crm_hidden_by=NULL WHERE id=?");

$done = $errs = 0;
foreach ($rows as $r) {
    try {
        $stmt->execute([$now, (int)$r['id']]);
        $done++;
        echo '  ✓ porzucono #' . ($r['msg_no'] ?: $r['id']) . ' (' . substr((string)$r['sent_at'], 0, 10) . ') '
           . mb_strimwidth((string)($r['subject'] ?: '(bez tematu)'), 0, 60, '…') . "\n";
    } catch (\Throwable $e) {
        $errs++;
        echo '  ✗ #' . (int)$r['id'] . ': ' . $e->getMessage() . "\n";
    }
}

echo "  Podsumowanie: porzucono {$done}, błędów {$errs} (próg {$days} dni)\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_inbox_autoabandon\n";
