<?php
/**
 * Migracja: dyspozycyjność wolontariusza (sloty) + urlopy z formalną akceptacją.
 *
 * Tworzy tabele:
 *   - wol_dyspozycje  — konkretne terminy dostępności (data + godziny)
 *   - wol_urlopy      — przedziały dat niedostępności ze statusem akceptacji
 *
 * Idempotentne (CREATE TABLE IF NOT EXISTS). Tę samą logikę uruchamia
 * dyspo_migrate() w includes/dyspozycyjnosc.php przy pierwszym użyciu stron.
 *
 * Uruchom: php migrate_dyspozycyjnosc.php  (lub z panelu admin/migrations.php)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/dyspozycyjnosc.php';

$existing = [];
foreach (db_all("SELECT name FROM sqlite_master WHERE type='table'") as $t) {
    $existing[$t['name']] = true;
}

dyspo_migrate();

foreach (['wol_dyspozycje', 'wol_urlopy'] as $tbl) {
    if (isset($existing[$tbl])) {
        echo "  · Tabela już istniała: {$tbl}\n";
    } else {
        echo "  ✓ Utworzono tabelę: {$tbl}\n";
    }
}

echo "\nGotowe.\n";
