<?php
/**
 * cron/agents/rk_announce.php — automat tur zapisów na zajęcia (Rekrutacja TI).
 *
 * Uruchamiać co 5 minut. Trzy zadania:
 *   1. Wysyłka zapowiedzi tur, którym minął announce_at (idempotentna —
 *      znacznik w k30_rk_notifications powstaje przed mailem, więc zazębione
 *      przebiegi nie dublują wiadomości).
 *   2. Otwarcie zapisów: scheduled → open, gdy minął opens_at.
 *   3. Domknięcie: open → closed, gdy minął closes_at.
 *
 * Wpis crontaba (co 5 minut): pięć pól „co 5 min” + php cron/agents/rk_announce.php
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_rekrutacja.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/email_templates.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

// 1. Zapowiedzi, którym minął termin automatycznej wysyłki
foreach (db_all(
    "SELECT id, name FROM k30_rk_rounds
      WHERE status IN ('scheduled','open')
        AND announce_at IS NOT NULL
        AND announce_at <= datetime('now')
        AND announced_at IS NULL") as $r) {
    $n = rk_round_announce((int)$r['id']);
    echo date('Y-m-d H:i:s') . " tura #{$r['id']} „{$r['name']}”: zapowiedź do $n odbiorców\n";
}

// 2. Otwarcie zapisów o godzinie zero
db_exec("UPDATE k30_rk_rounds SET status='open'
          WHERE status='scheduled' AND opens_at <= datetime('now')");

// 3. Domknięcie tur po terminie
db_exec("UPDATE k30_rk_rounds SET status='closed'
          WHERE status='open' AND closes_at IS NOT NULL AND closes_at < datetime('now')");

// 4. Rezerwacje małoletnich bez decyzji rodzica — wygaszenie z pełnym zwrotem
$expired = rk_parent_expire_stale();
if ($expired) echo date('Y-m-d H:i:s') . " wygaszono $expired rezerwacji bez zgody rodzica\n";

// 5. Automatyczne dogenerowywanie terminów z dostępności (tury z auto_generate)
foreach (rk_auto_generate_rounds() as $rid => $n) {
    if ($n > 0) echo date('Y-m-d H:i:s') . " tura #$rid: auto-wygenerowano $n terminów z dostępności\n";
}

echo date('Y-m-d H:i:s') . " rk_announce: gotowe\n";
