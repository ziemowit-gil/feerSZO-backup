<?php
/**
 * cron/crm_office_mail.php — automatyczne dociąganie korespondencji z Outlooka
 * do kartotek CRM.
 *
 * Przycisk „Pobierz maile z Outlooka" w kartotece wymagał wejścia i kliknięcia,
 * więc historia komunikacji była pełna wyłącznie tam, gdzie ktoś akurat zajrzał —
 * a przy sprawdzaniu, czy coś do kogoś poszło, liczy się właśnie kartoteka,
 * do której jeszcze nikt nie wchodził.
 *
 * Automat obchodzi kartoteki po kolei: najpierw te, których nigdy nie pobierano,
 * potem najdawniej odświeżane. Chodzi co 10 minut po 25 kartotek (do 150 na godzinę);
 * limit na przebieg jest twardy, bo każda kartoteka to wywołanie Graph API i przy
 * tysiącu kontaktów nieograniczony przebieg wpadłby w limity Microsoftu.
 *
 * Włącza się w Ustawieniach CRM → Microsoft 365 (crm_office_auto_mail).
 *
 * Użycie: php cron/crm_office_mail.php [--limit=25] [--hours=24]
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/auth.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_office.php';

$opts  = getopt('', ['limit::', 'hours::']);
$limit = max(1, min(500, (int)($opts['limit'] ?? 25)));
$hours = isset($opts['hours']) ? max(1, (int)$opts['hours']) : null;

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_office_mail\n";

if (!crm_office_auto_mail()) {
    echo "  automatyczne pobieranie korespondencji wyłączone — nic do zrobienia\n";
    exit(0);
}

$st = crm_office_status();
if (empty($st['graph_configured'])) {
    echo "  Microsoft 365 nie jest skonfigurowany — koniec\n";
    exit(0);
}
if (trim((string)($st['mailbox'] ?? '')) === '') {
    echo "  nie wskazano skrzynki, z której pobierać — koniec\n";
    exit(0);
}

$res = crm_office_pull_pending($limit, $hours);
echo "  kartotek odświeżonych: {$res['done']}, dopisanych wiadomości: {$res['logged']}, błędów: {$res['failed']}\n";
foreach ($res['errors'] as $e) echo "  ! $e\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_office_mail\n";
