<?php
/**
 * api/v1/hybrid_szo.php — REST API domeny SZO (System Wspomagania
 * Zarządzania Organizacją) w warstwie hybrydowej. Strona serwerowa wołana
 * przez HttpSzoQualityReportClient, gdy SZO jest wdrożone na osobnym
 * serwerze (SZO_DRIVER=http po stronie wołającego modułu).
 *
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienie: hybrid:szo (nadawane w admin/api_keys.php).
 *
 * GET  ?resource=accessibility_requirements  → lista wymagań dostępności
 * GET  ?resource=quality_report               → ostatnie zgłoszenia (podgląd)
 * POST ?resource=quality_report               → nowe zgłoszenie (JSON body)
 */
declare(strict_types=1);

use FeerSzo\Hybrid\Dto\QualityReportDto;
use FeerSzo\Hybrid\Szo\Service\QualityReportService;
use FeerSzo\Hybrid\Szo\Service\QualityRequirementService;

define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';

api_auth_migrate();
api_require('hybrid:szo');

$resource = $_GET['resource'] ?? '';
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($resource === 'accessibility_requirements' && $method === 'GET') {
    $service = new QualityRequirementService();
    api_json(array_map(fn($dto) => $dto->toArray(), $service->list()));
}

if ($resource === 'quality_report') {
    $service = new QualityReportService();

    if ($method === 'GET') {
        api_json($service->list((int)($_GET['limit'] ?? 100)));
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            api_error('Nieprawidłowy JSON.', 400);
        }
        $report = QualityReportDto::fromArray($body);
        if ($report->sourceModule === '' || $report->description === '') {
            api_error('Podaj source_module i description.', 400);
        }
        $id = $service->submit($report);
        api_json(['id' => $id], 201);
    }

    api_error('Nieobsługiwana metoda.', 405);
}

api_error('Nieznany zasób. Dostępne: accessibility_requirements, quality_report', 404);
