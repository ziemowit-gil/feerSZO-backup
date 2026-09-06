<?php

/**
 * hybrid/demo.php — uruchamialny przykład warstwy hybrydowej.
 *
 * Pokazuje MaterialAdaptationController działający identycznie niezależnie
 * od tego, czy TI/SZO są "local" czy "http" — o wyborze decydują wyłącznie
 * zmienne środowiskowe (patrz hybrid/README.md).
 *
 * Użycie:
 *   php hybrid/demo.php                          — auto-dobiera pierwszy aktywny zapis
 *   php hybrid/demo.php --course=5 --client=42    — konkretny kurs/kursant
 *
 * Sprawdź tryb driverów przed uruchomieniem:
 *   TI_DRIVER=local DYDAKTYKA_DRIVER=local SZO_DRIVER=local php hybrid/demo.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

use FeerSzo\Hybrid\Config\DriverConfig;
use FeerSzo\Hybrid\Dydaktyka\MaterialAdaptationController;

function demo_arg(string $name): ?string {
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
}

echo "── Warstwa hybrydowa SZO / TI / Dydaktyka — demo ─────────────────────\n";
echo "TI_DRIVER=" . DriverConfig::tiDriver() . "  SZO_DRIVER=" . DriverConfig::szoDriver()
   . "  DYDAKTYKA_DRIVER=" . DriverConfig::dydaktykaDriver() . "\n\n";

$courseId = (int)(demo_arg('course') ?? 0);
$clientId = (int)(demo_arg('client') ?? 0);

if ($courseId <= 0 || $clientId <= 0) {
    $row = db_one(
        "SELECT e.course_id, e.client_id, c.name AS course_name, cl.name AS client_name
           FROM k30_ti_enrollments e
           JOIN k30_ti_courses c ON c.id = e.course_id
           JOIN k30_clients cl   ON cl.id = e.client_id
          WHERE e.status = 'active' LIMIT 1"
    );
    if (!$row) {
        fwrite(STDERR, "Brak żadnego aktywnego zapisu w bazie — podaj --course= i --client= ręcznie.\n");
        exit(1);
    }
    $courseId = (int)$row['course_id'];
    $clientId = (int)$row['client_id'];
    echo "Auto-dobrano: kurs #{$courseId} („{$row['course_name']}”), kursant #{$clientId} ({$row['client_name']})\n\n";
}

$controller = MaterialAdaptationController::withDefaultClients();

try {
    $result = $controller->requestAdaptation(
        courseId: $courseId,
        clientId: $clientId,
        requestedFormat: 'audio',
        reason: 'Zgłoszenie testowe z hybrid/demo.php',
    );
    echo "OK — zgłoszenie #{$result['request_id']} zapisane, raport SZO #{$result['quality_report_id']}.\n";
    echo "Profil TI użyty przy zgłoszeniu:\n";
    echo json_encode($result['profile_used'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . "\n");
    exit(1);
}
