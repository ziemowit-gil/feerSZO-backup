#!/usr/bin/env php
<?php
/**
 * bin/login_sentry.php — LoginSentry („Staszek”): przebieg co 6 godzin, sprawdza IP logowań do SZO i Logto.
 * Podejrzane logowanie: zawieszenie konta w Logto, blokada w SZO, e-mail do administratora. Panel: /modules/login_sentry/router.php
 *
 *   php bin/login_sentry.php               przebieg według trybu z panelu (off / preview / live)
 *   php bin/login_sentry.php --dry-run     pełny raport na stdout, bez maili, blokad i zapisów
 *   php bin/login_sentry.php --force-id=12 sprawdź jeszcze raz konto SZO #12 (także logowania już ocenione)
 *   php bin/login_sentry.php --stats       statystyki z bazy Staszka
 *
 * Uruchamia go dyspozytor CRON SZO (klucz „login_sentry”, co 6 h) albo crontab (config/login_sentry.example.php).
 * Kody wyjścia: 0 dobrze, 1 błąd, 2 inny przebieg trwa, 64 złe argumenty.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/login_sentry/logic/LoginSentryEngine.php';

use Feer\Szo\LoginSentry\LoginSentryDatabase;
use Feer\Szo\LoginSentry\LoginSentryEngine;

$opts = getopt('', ['dry-run', 'force-id:', 'stats', 'help']);
if ($opts === false || isset($opts['help'])) {
    fwrite(STDOUT, "Użycie: php bin/login_sentry.php [--dry-run] [--force-id=ID] [--stats]\n");
    exit(isset($opts['help']) ? 0 : 64);
}
$force = null;
if (isset($opts['force-id'])) {
    if (!ctype_digit((string)$opts['force-id']) || (int)$opts['force-id'] <= 0) { fwrite(STDERR, "--force-id musi być numerem konta SZO.\n"); exit(64); }
    $force = (int)$opts['force-id'];
}
@set_time_limit(0);

if (isset($opts['stats'])) {
    echo json_encode(LoginSentryEngine::stats(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

// Blokada CLI przeciw nakładaniu się uruchomień z crona (silnik ma jeszcze własną, dla przebiegów z panelu).
try { LoginSentryDatabase::pdo(); } catch (\Throwable $e) { fwrite(STDERR, 'LOGIN_SENTRY ERROR: ' . $e->getMessage() . "\n"); exit(1); }   // zakłada katalog i bazę
$lock = @fopen(LoginSentryDatabase::storageDir() . '/login_sentry.cli.lock', 'c');
if (!$lock) { fwrite(STDERR, "LOGIN_SENTRY ERROR: nie mogę otworzyć pliku blokady.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "LOGIN_SENTRY: poprzednie uruchomienie jeszcze trwa.\n"); exit(2); }

$dry = isset($opts['dry-run']);
try {
    $r = LoginSentryEngine::fromSzo()->run($dry ? 'cli-dry' : 'cron', $dry, $force);
} catch (\Throwable $e) {
    fwrite(STDERR, 'LOGIN_SENTRY ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($r['mode'] === 'off') { echo "LOGIN_SENTRY: wyłączony w panelu.\n"; exit(0); }
$label = ['clean' => 'OK', 'exempt' => 'WYJĄTEK', 'warn' => 'OSTRZEŻENIE', 'suspend' => 'BLOKADA', 'repeat' => 'JUŻ OBSŁUŻONE', 'error' => 'ERROR', 'info' => 'INFO'];
echo 'LOGIN_SENTRY (' . $r['mode'] . ($r['dry'] ? ', próbnie: bez maili i blokad' : '') . ")\n";
foreach ($r['counts'] as $k => $v) if ($v) echo '  ', str_pad($k, 20), $v, "\n";
foreach ($r['items'] ?? [] as $i) {
    if (!$r['dry'] && $i['action'] === 'clean') continue;   // w zwykłym przebiegu tylko to, co ważne
    if ($i['user_id'] === 0) { echo '  [', $label[$i['action']] ?? $i['action'], '] ', $i['note'], "\n"; continue; }
    printf("  [%s] #%d %s (%d pkt): %s\n", $label[$i['action']] ?? $i['action'], $i['user_id'], $i['email'], $i['score'], $i['note']);
}
if (!empty($r['error'])) { fwrite(STDERR, 'LOGIN_SENTRY ERROR: ' . $r['error'] . "\n"); exit(str_contains($r['error'], 'trwa') ? 2 : 1); }
exit(0);
