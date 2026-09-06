<?php
/**
 * api/v1/hybrid_ti.php — REST API domeny TI (Tyfloinformatyka) w warstwie
 * hybrydowej. To jest strona SERWEROWA, którą woła HttpTyfloProfileClient,
 * gdy TI jest wdrożone na osobnym serwerze (TI_DRIVER=http po stronie
 * Dydaktyki). Gdy wszystko działa na jednym serwerze (Monolit Modularny),
 * ten plik nie jest w ogóle wołany — używany jest InternalTyfloProfileClient.
 *
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienie: hybrid:ti (nadawane w admin/api_keys.php).
 *
 * GET  ?resource=accessibility_profile&client_id=N   → profil dostępności
 * POST ?resource=accessibility_profile                → zapis profilu (JSON body)
 */
declare(strict_types=1);

use FeerSzo\Hybrid\Dto\AccessibilityProfileDto;
use FeerSzo\Hybrid\Ti\Service\AccessibilityProfileService;

define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';

api_auth_migrate();
api_require('hybrid:ti');

$resource = $_GET['resource'] ?? '';
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$service  = new AccessibilityProfileService();

if ($resource !== 'accessibility_profile') {
    api_error('Nieznany zasób. Dostępne: accessibility_profile', 404);
}

if ($method === 'GET') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        api_error('Podaj client_id.', 400);
    }
    api_json($service->getProfile($clientId)->toArray());
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($body)) {
        api_error('Nieprawidłowy JSON.', 400);
    }
    $profile = AccessibilityProfileDto::fromArray($body);
    if ($profile->clientId <= 0) {
        api_error('Podaj client_id.', 400);
    }
    $service->saveProfile($profile);
    api_json($profile->toArray());
}

api_error('Nieobsługiwana metoda.', 405);
