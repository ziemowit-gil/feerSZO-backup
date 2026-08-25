<?php
/**
 * api/internal/rc_login_notice.php — treść komunikatu na stronie logowania
 * Roundcube (moduł Poczta), zarządzana centralnie w admin/poczta_settings.php.
 *
 * Wywoływane WEWNĘTRZNIE przez kontener "rc" (docker/roundcube/plugins/login_notice/)
 * po sieci Docker (http://app/api/internal/rc_login_notice.php), nie przez Traefik.
 * Zabezpieczenie: nagłówek X-Internal-Key musi pasować do APP_KEY tej instalacji
 * (ten sam sekret już współdzielony między kontenerami "app" i "rc" — zob.
 * docker/docker-compose.rc.yml).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/poczta.php';
require_once dirname(__DIR__, 2) . '/includes/org_case.php';

header('Content-Type: application/json; charset=utf-8');

$incoming = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
if (!defined('APP_KEY') || APP_KEY === '' || !$incoming || !hash_equals((string)APP_KEY, $incoming)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$html = org_setting('poczta_rc_login_notice');
if ($html === '') {
    $html = poczta_rc_login_notice_default();
}

/* Nazwa organizacji W DOPEŁNIACZU — ekran logowania poczty przedstawia się tak
   samo jak pozostałe wejścia: „Poczta Fundacji …". Kontener Roundcube nie ma
   dostępu do ustawień aplikacji, więc dostaje gotowy tekst tą samą drogą co
   komunikat. */
echo json_encode([
    'html' => $html,
    'org'  => org_name_genitive(),
], JSON_UNESCAPED_UNICODE);
