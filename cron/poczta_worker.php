#!/usr/bin/env php
<?php
/**
 * cron/poczta_worker.php — Konsument kolejki RabbitMQ „poczta_skanowanie" (moduł Poczta).
 *
 * Uruchamiany przez cron/dispatcher.php co minutę. Odbiera partię zadań (batch-pull,
 * nie długo działający daemon — spójne z resztą projektu) i skanuje odpowiednie
 * skrzynki. Gdy RabbitMQ nie jest skonfigurowane, kończy bez działania — zadania
 * zostały już przetworzone synchronicznie przez cron/poczta_dispatch.php.
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

if (!module_enabled('poczta_enabled')) {
    exit(0);
}

if (!class_exists('PhpAmqpLib\\Connection\\AMQPLazyConnection') || !getenv('RABBITMQ_HOST')) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " RabbitMQ nieskonfigurowane — nic do zrobienia (fallback synchroniczny obsłużył zadania).\n";
    exit(0);
}

require_once dirname(__DIR__) . '/includes/rabbitmq.php';

$batch = (int)(org_setting('poczta_worker_batch') ?: '5');
$service = new PocztaScanService();
$processed = 0;
$errors = [];

try {
    $processed = rabbit_consume_batch('poczta_skanowanie', function (array $payload) use ($service, &$errors) {
        $mailbox_id = (int)($payload['mailbox_id'] ?? 0);
        if (!$mailbox_id) return true; // odrzuć bezsensowny payload — nie ma sensu wracać do kolejki

        try {
            // Zawsze ACK — scan_mailbox() sam zapisuje next_retry_at/status w poczta_mailboxes;
            // ponowne zadanie wygeneruje cron/poczta_dispatch.php po wygaśnięciu backoffu.
            // NACK+requeue tutaj groziłby zapętleniem (natychmiastowy retry na 429 w tym samym batchu).
            $service->scan_mailbox($mailbox_id);
            return true;
        } catch (\Throwable $e) {
            $errors[] = "[{$mailbox_id}] " . $e->getMessage();
            return false; // NACK — spróbuj ponownie
        }
    }, $batch);
} catch (\Throwable $e) {
    echo "[FATAL] " . date('Y-m-d H:i:s') . " " . $e->getMessage() . "\n";
    exit(1);
}

echo sprintf(
    "[DONE] %s Poczta worker: przetworzono=%d, błędów=%d\n",
    date('Y-m-d H:i:s'),
    $processed,
    count($errors)
);
foreach ($errors as $err) {
    echo "[ERROR] " . date('Y-m-d H:i:s') . " {$err}\n";
}

exit(empty($errors) ? 0 : 1);
