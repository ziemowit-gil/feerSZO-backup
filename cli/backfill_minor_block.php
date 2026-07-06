<?php
/**
 * cli/backfill_minor_block.php — jednorazowe zastosowanie reguły blokady
 * niepełnoletnich (ContractMinorGuard) do UMÓW ISTNIEJĄCYCH przed wdrożeniem
 * tej reguły (2026-07-07). Bez tego blokada włącza się tylko przy najbliższym
 * zapisie/wejściu w widok danej umowy — ten skrypt naprawia to od razu,
 * dla wszystkich umów wolontariackich z niepelnoletni=1 na raz.
 *
 * Bezpieczne do wielokrotnego uruchamiania (idempotentne — ContractMinorGuard::
 * syncAfterSave() nic nie robi, jeśli już zablokowana albo zgoda aktualna).
 *
 * Użycie: php cli/backfill_minor_block.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/wolontariat_schema.php';
require_once $base . '/includes/guardian_consent.php';
require_once $base . '/includes/contract_transitions.php';

$rows = db_all("SELECT * FROM umowy_wolontariat WHERE niepelnoletni = 1");
echo "Znaleziono " . count($rows) . " umów niepełnoletnich wolontariuszy.\n";

$blocked = 0;
foreach ($rows as $row) {
    $was_blocked = !empty($row['is_blocked']);
    ContractMinorGuard::syncAfterSave('wolontariat', (int)$row['id'], $row, 0);
    $after = db_one("SELECT is_blocked FROM umowy_wolontariat WHERE id=?", [$row['id']]);
    if (!$was_blocked && !empty($after['is_blocked'])) {
        $blocked++;
        echo "  ZABLOKOWANO #{$row['id']} — {$row['imie_nazwisko']} (brak/wygasła zgoda przedstawiciela ustawowego)\n";
    }
}

echo "Zablokowano {$blocked} nowych umów.\n";
