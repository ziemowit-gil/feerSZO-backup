<?php
/**
 * cron.php — HTTP endpoint dla hostingowych croni URL-owych.
 *
 * Wywołaj co minutę przez panel hostingowy:
 *   curl -L -s https://twoja-domena.pl/cron.php?token=TWÓJ_TOKEN
 *
 * Token ustawiasz w: Admin → Dane organizacji → Konfiguracja CRON
 * lub ręcznie w tabeli settings: key_ = 'cron_token'
 */

define('APP_CLI', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

// ── Weryfikacja tokenu ─────────────────────────────────────────────────────
$expected = db_one("SELECT value FROM settings WHERE key_='cron_token'")['value'] ?? '';

if (!$expected) {
    http_response_code(503);
    echo "Brak tokenu CRON. Ustaw go w: Admin → Konfiguracja CRON.\n";
    exit;
}

$provided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if (!hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "Nieprawidłowy token.\n";
    exit;
}

// ── Uruchom dispatcher ────────────────────────────────────────────────────
$dispatcher = __DIR__ . '/cron/dispatcher.php';

if (!file_exists($dispatcher)) {
    http_response_code(500);
    echo "Brak pliku dispatcher: {$dispatcher}\n";
    exit;
}

// Dispatcher wymaga APP_CLI — już zdefiniowane powyżej.
// Uruchamiamy go w tym samym procesie (include) bo exec może być zablokowany.
ob_start();
try {
    // Nadpisz PHP_SAPI check jeśli dispatcher go sprawdza
    include $dispatcher;
} catch (\Throwable $e) {
    echo "Błąd: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

echo "[" . date('Y-m-d H:i:s') . "] CRON OK\n";
if ($output) echo $output;
