<?php
/**
 * cli/docRegistryBackfill.php — nadaje numer + kod Code128 + QR plikom wgranym PRZED wprowadzeniem rejestru (modules/doc_registry):
 * załączniki EZD, pliki spraw CRM, pliki zadań. Idempotentne (istniejące wpisy zostają), bezpieczne do ponawiania; nowe pliki dostają kod przy wgraniu.
 *
 * Użycie: php cli/docRegistryBackfill.php [limit_na_typ]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/modules/doc_registry/lib/DocRegistry.php';

$limit = max(1, (int)($argv[1] ?? 5000));
foreach (DocRegistry::backfill($limit) as $type => $n) echo str_pad($type, 16) . " nowych kodów: {$n}\n";
echo "Razem w rejestrze: " . (int)(db_one("SELECT COUNT(*) AS n FROM doc_registry")['n'] ?? 0) . "\n";
