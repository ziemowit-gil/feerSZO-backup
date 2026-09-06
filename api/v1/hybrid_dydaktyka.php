<?php
/**
 * api/v1/hybrid_dydaktyka.php — REST API domeny Dydaktyka w warstwie
 * hybrydowej. Strona serwerowa Dydaktyki, wołana z zewnątrz gdy Dydaktyka
 * jest wdrożona jako Niezależny Serwis (DYDAKTYKA_DRIVER=http po stronie
 * innego modułu, który chciałby np. zgłosić zapotrzebowanie na adaptację
 * materiału bez wchodzenia w wewnętrzne DTO tego procesu).
 *
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienie: hybrid:dydaktyka (nadawane w admin/api_keys.php).
 *
 * GET  ?resource=courses                          → lista kursów
 * GET  ?resource=enrollments&course_id=N          → zapisani na kurs
 * POST ?resource=material_adaptation_request      → zgłoszenie adaptacji
 *      (JSON body: course_id, client_id, requested_format, reason?)
 *      — uruchamia MaterialAdaptationController, który sam pobiera profil
 *        z TI i zgłasza raport do SZO (przez ClientFactory, wg ich driverów).
 */
declare(strict_types=1);

use FeerSzo\Hybrid\Dydaktyka\MaterialAdaptationController;
use FeerSzo\Hybrid\Dydaktyka\Service\CourseService;
use FeerSzo\Hybrid\Dydaktyka\Service\EnrollmentService;

define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';

api_auth_migrate();
api_require('hybrid:dydaktyka');

$resource = $_GET['resource'] ?? '';
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($resource === 'courses' && $method === 'GET') {
    api_json(array_map(fn($dto) => $dto->toArray(), (new CourseService())->list()));
}

if ($resource === 'enrollments' && $method === 'GET') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if ($courseId <= 0) {
        api_error('Podaj course_id.', 400);
    }
    api_json(array_map(fn($dto) => $dto->toArray(), (new EnrollmentService())->listForCourse($courseId)));
}

if ($resource === 'material_adaptation_request' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($body)) {
        api_error('Nieprawidłowy JSON.', 400);
    }
    $courseId = (int)($body['course_id'] ?? 0);
    $clientId = (int)($body['client_id'] ?? 0);
    $format   = (string)($body['requested_format'] ?? '');
    if ($courseId <= 0 || $clientId <= 0 || $format === '') {
        api_error('Podaj course_id, client_id i requested_format.', 400);
    }
    try {
        $result = MaterialAdaptationController::withDefaultClients()
            ->requestAdaptation($courseId, $clientId, $format, (string)($body['reason'] ?? ''));
        api_json($result, 201);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Nieznany zasób lub metoda. Dostępne: courses, enrollments, material_adaptation_request', 404);
