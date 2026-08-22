<?php
/**
 * crm/mobile/includes/mobile.php — wspólna warstwa modułu „Szybkie dzwonienie" (PWA).
 *
 * Moduł jest mobilną nakładką na kontakty CRM: lista + wyszukiwanie + `tel:`.
 * Nie ma własnej autoryzacji — dziedziczy dokładnie te same bramki co reszta CRM
 * (logowanie, crm_enabled + VPN, weryfikacja IKA, uprawnienia can_read/can_write('crm')).
 */

declare(strict_types=1);

/** Krótki alias adresu aplikacji (patrz reguły RewriteRule w .htaccess). */
const CRM_MOBILE_ALIAS = 'mobilna';

if (!defined('APP_INSTALLED')) require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/crm.php';

/**
 * Bramka dostępu do modułu mobilnego.
 *
 * @param bool $json true dla endpointów API (błąd jako JSON 401/403 zamiast redirectu)
 */
function crm_mobile_guard(bool $json = false): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        if (!current_user()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!module_enabled('crm_enabled')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Moduł CRM jest wyłączony.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!can_read('crm') && !is_admin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Brak uprawnień do CRM.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Sesja IKA wygasła (30 min) — przeglądarka dostaje sygnał do przeładowania,
        // żeby użytkownik przeszedł przez normalny ekran weryfikacji IKA.
        if (!ika_ok(APP_URL . '/crm/mobile/')) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Sesja IKA wygasła.', 'reauth' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        crm_migrate();
        return;
    }

    // Niezalogowanych kierujemy na własny ekran wejścia (UID + PIN, obok MS365),
    // a nie na ogólne logowanie SZO — to jest aplikacja mobilna.
    if (!current_user()) {
        header('Location: ' . crm_mobile_base() . '/login.php');
        exit;
    }
    require_login();
    require_module_enabled('crm_enabled', 'Moduł CRM');
    if (!can_read('crm') && !is_admin()) {
        flash_set('error', 'Brak uprawnień do modułu CRM.');
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }

    // Ta sama bramka IKA co w crm/includes/header_crm.php.
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    ika_require(APP_URL . $uri);

    crm_migrate();
}

/**
 * Rozbija surowe pole telefonu na pojedyncze numery gotowe do `tel:`.
 * Pole w CRM bywa wpisane ręcznie: „600 111 222, 22 333 44 55" albo „601-202-303 / wew. 12".
 *
 * @return list<array{label:string,tel:string}>
 */
function crm_mobile_phones(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') return [];

    $out = [];
    foreach (preg_split('/[,;\/]|\bl(?:ub)?\.?\s|\bi\s/u', $raw) as $part) {
        $label = trim((string)$part, " \t\n\r\0\x0B.-");
        if ($label === '') continue;

        // Do wybierania zostawiamy tylko cyfry i wiodący „+".
        $plus   = str_starts_with(ltrim($label), '+');
        $digits = preg_replace('/\D+/', '', $label) ?? '';
        if (strlen($digits) < 6) continue; // numer wewnętrzny / śmieć — nie da się zadzwonić

        if ($plus) {
            $tel = '+' . $digits;
        } elseif (strlen($digits) === 9) {
            $tel = '+48' . $digits;                 // polski numer krajowy
        } elseif (str_starts_with($digits, '0048')) {
            $tel = '+' . substr($digits, 2);
        } elseif (str_starts_with($digits, '48') && strlen($digits) === 11) {
            $tel = '+' . $digits;
        } else {
            $tel = $digits;                          // numery skrócone, zagraniczne bez „+"
        }

        $out[$tel] = ['label' => $label, 'tel' => $tel]; // klucz = deduplikacja
    }
    return array_values($out);
}

/** Inicjały do awatara (2 znaki). */
function crm_mobile_initials(string $name): string
{
    $ini = '';
    foreach (preg_split('/\s+/u', trim($name)) as $w) {
        if ($w === '') continue;
        $ini .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return mb_substr($ini, 0, 2, 'UTF-8') ?: '?';
}

/**
 * Adres bazowy aplikacji dla bieżącego żądania.
 *
 * Moduł jest dostępny pod dwoma ścieżkami: kanoniczną /crm/mobile/ oraz krótkim
 * aliasem /mobilna/ (wewnętrzne przepisanie w .htaccess). Zwracamy tę, której
 * użytkownik faktycznie użył — inaczej service worker zarejestrowany pod jednym
 * adresem nie obsługiwałby drugiego, a manifest wskazywałby obcy scope.
 */
function crm_mobile_base(): string
{
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $app  = rtrim((string)(parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/');
    if ($app !== '' && str_starts_with($path, $app)) {
        $path = substr($path, strlen($app));
    }
    if (str_starts_with($path, '/' . CRM_MOBILE_ALIAS . '/') || $path === '/' . CRM_MOBILE_ALIAS) {
        return APP_URL . '/' . CRM_MOBILE_ALIAS;
    }
    return APP_URL . '/crm/mobile';
}
