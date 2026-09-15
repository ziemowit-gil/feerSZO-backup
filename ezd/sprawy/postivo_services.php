<?php
/**
 * ezd/sprawy/postivo_services.php — lista usług Postivo.pl (nośnik/usługa,
 * np. "List polecony priorytetowy z potw. odbioru D+3") do wyboru PER WYSYŁKA
 * w modalu "Zarejestruj w wychodzących" (ezd/sprawy/view.php). Wołane leniwie
 * (dopiero po wybraniu trybu "Postivo"), żeby zwykłe otwarcie koszulki nie
 * robiło zbędnego żądania do API Postivo.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

header('Content-Type: application/json; charset=utf-8');

if (postivo_setting('postivo_enabled') !== '1') {
    echo json_encode(['ok' => false, 'error' => 'Integracja z Postivo.pl jest wyłączona.']);
    exit;
}

try {
    $client = new PostivoClient();
    if (!$client->is_configured()) {
        echo json_encode(['ok' => false, 'error' => 'Brak klucza API Postivo.pl.']);
        exit;
    }
    $meta = $client->get_metadata();
    echo json_encode([
        'ok'                => true,
        'carriers'          => $meta['carriers'] ?? [],
        'default_carrier_id'=> (int)postivo_setting('postivo_carrier_id'),
        'default_service_id'=> (int)postivo_setting('postivo_service_id'),
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
