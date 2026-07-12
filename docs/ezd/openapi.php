<?php
/**
 * docs/ezd/openapi.php — serwuje openapi.yaml zalogowanym przez Microsoft 365
 * (patrz index.php). Bezpośredni dostęp do openapi.yaml jest zablokowany
 * w .htaccess, żeby nie dało się pominąć bramki logowania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!current_user()) {
    http_response_code(401);
    exit;
}

header('Content-Type: application/yaml; charset=utf-8');
readfile(__DIR__ . '/openapi.yaml');
