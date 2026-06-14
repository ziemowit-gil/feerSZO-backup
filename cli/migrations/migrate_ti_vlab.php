<?php
/**
 * Migracja: moduł VLAB (wirtualne maszyny / Docker po SSH) dla kursantów TI.
 *
 * Tworzy tabele:
 *   - k30_ti_vlab_config     — konfiguracja zdalnego hosta Dockera (1 wiersz),
 *   - k30_ti_vlab_templates  — katalog obrazów Docker dostępnych dla kursantów,
 *   - k30_ti_vlab_containers — kontenery utworzone przez kursantów,
 *   - k30_ti_vlab_log        — audyt operacji.
 *
 * Uruchom: php cli/migrations/migrate_ti_vlab.php
 *
 * Uwaga: includes/karty30.php (karty30_migrate) tworzy te tabele także
 * automatycznie przy pierwszym użyciu — ta migracja jest jawnym,
 * idempotentnym odpowiednikiem.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/karty30.php';

karty30_migrate();

$pdo  = db();
$want = ['k30_ti_vlab_config', 'k30_ti_vlab_templates', 'k30_ti_vlab_containers', 'k30_ti_vlab_log'];
foreach ($want as $t) {
    $ok = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
    echo ($ok ? '[OK] ' : '[!!] ') . $t . PHP_EOL;
}
echo "Migracja VLAB zakończona." . PHP_EOL;
