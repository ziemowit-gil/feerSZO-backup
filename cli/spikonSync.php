<?php
/**
 * cli/spikonSync.php — pełna synchronizacja książek telefonicznych Spikon z grupami CRM (bez limitu operacji z przeglądarki).
 * Użycie: php cli/spikonSync.php   (np. z crona raz na dobę). Nic nie robi, gdy telefonia Spikon jest wyłączona.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/modules/spikon/logic/SpikonService.php';

crm_migrate();
SpikonService::migrate();
if (!SpikonService::isEnabled()) { fwrite(STDOUT, "Spikon wyłączony lub bez klucza API — pomijam.\n"); exit(0); }

$failed = 0;
foreach (SpikonService::books() as $b) {
    try {
        $r = SpikonService::syncBook((int)$b['id'], null);
        fwrite(STDOUT, sprintf("%s: +%d / -%d%s\n", $b['name'], $r['added'], $r['removed'], $r['errors'] ? ' (błędy: ' . count($r['errors']) . ')' : ''));
        if ($r['errors']) $failed++;
    } catch (Throwable $e) {
        fwrite(STDERR, $b['name'] . ': ' . $e->getMessage() . "\n");
        $failed++;
    }
}
exit($failed ? 1 : 0);
