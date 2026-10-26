<?php
/**
 * includes/crmErrors.php — czytelne komunikaty błędów w CRM.
 *
 * Bez tego niezłapany wyjątek kończył się surowym „Fatal error: Uncaught Error … Stack trace” (ścieżki serwera, numery
 * wierszy) i urwaną stroną. Teraz, na stronach /crm/…:
 *   • każdy niezłapany wyjątek i błąd krytyczny dostaje krótki KOD zgłoszenia (np. CRM-3FA9C2), trafia z pełnym kontekstem
 *     (użytkownik, adres, plik:wiersz, ślad) do dziennika serwera,
 *   • użytkownik widzi zrozumiałe wyjaśnienie po polsku + kod do podania administratorowi + przyciski „Spróbuj ponownie” i
 *     „Wróć do pulpitu”; szczegóły techniczne (komunikat, plik:wiersz) widzi tylko administrator,
 *   • żądania AJAX/JSON dostają {ok:false,error,error_id} zamiast HTML-a, który rozsadzał parser JSON po stronie przeglądarki,
 *   • znane przyczyny (brak kolumny/tabeli, zablokowana baza, limit pamięci/czasu, brak uprawnień do pliku) mają osobne,
 *     konkretne podpowiedzi.
 * Włączane z includes/crm.php; nie działa w CLI ani poza ścieżką /crm/.
 */

/** Zamienia wyjątek/błąd na [tytuł, podpowiedź]. */
function crm_error_explain(string $class, string $msg): array {
    $m = mb_strtolower($msg);
    if (str_contains($m, 'no such column') || str_contains($m, 'no such table') || str_contains($m, 'has no column')) {
        return ['Baza danych wymaga aktualizacji', 'Ta funkcja korzysta z pola lub tabeli, której w bazie jeszcze nie ma. Poproś administratora o uruchomienie aktualizacji (Admin → Aktualizacje) i podaj kod zgłoszenia.'];
    }
    if (str_contains($m, 'database is locked') || str_contains($m, 'database table is locked') || str_contains($m, 'busy')) {
        return ['Baza jest chwilowo zajęta', 'Inna operacja zapisuje teraz dane. Odczekaj kilka sekund i spróbuj ponownie — nic nie zostało utracone.'];
    }
    if (str_contains($m, 'allowed memory size')) {
        return ['Za mało pamięci na tę operację', 'Operacja objęła zbyt wiele danych naraz. Zawęź filtr (np. krótszy okres lub mniej kontaktów) albo zgłoś administratorowi.'];
    }
    if (str_contains($m, 'maximum execution time')) {
        return ['Operacja trwała zbyt długo', 'Serwer przerwał ją po limicie czasu. Zawęź zakres danych i spróbuj ponownie; jeśli się powtarza, zgłoś administratorowi.'];
    }
    if (str_contains($m, 'permission denied') || str_contains($m, 'failed to open stream') || str_contains($m, 'unable to open')) {
        return ['Brak dostępu do pliku na serwerze', 'System nie mógł odczytać lub zapisać pliku. To problem uprawnień na serwerze — zgłoś administratorowi z kodem zgłoszenia.'];
    }
    if (str_contains($m, 'array callback') || str_contains($m, 'must be callable') || str_contains($m, 'call to undefined') || str_contains($m, 'call to a member function')) {
        return ['Błąd w działaniu tej strony', 'To usterka programu, nie Twojej pracy — dane są bezpieczne. Zgłoś ją administratorowi, podając kod zgłoszenia.'];
    }
    if (str_contains($m, 'curl') || str_contains($m, 'could not resolve') || str_contains($m, 'connection') || str_contains($m, 'timed out')) {
        return ['Nie udało się połączyć z usługą zewnętrzną', 'Poczta, Microsoft 365 lub inna usługa nie odpowiedziała. Spróbuj ponownie za chwilę.'];
    }
    return ['Coś poszło nie tak', 'Strona nie mogła się wyświetlić. Dane są bezpieczne. Spróbuj ponownie, a jeśli błąd wraca — zgłoś go administratorowi, podając kod zgłoszenia.'];
}

/** Czy bieżące żądanie oczekuje JSON-a (XHR / API). */
function crm_error_wants_json(): bool {
    $h = static fn(string $k) => (string)($_SERVER[$k] ?? '');
    return strtolower($h('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest'
        || str_contains(strtolower($h('HTTP_ACCEPT')), 'application/json')
        || !empty($_GET['_ajax']) || !empty($_POST['_ajax'])
        || (bool)preg_match('#/crm/(api|track)/#', (string)parse_url($h('REQUEST_URI'), PHP_URL_PATH));
}

/** Obsługuje błąd: log + komunikat. Nie zwraca (kończy żądanie). */
function crm_error_render(\Throwable $e): void {
    $id = 'CRM-' . strtoupper(bin2hex(random_bytes(3)));
    $uid = 0; $is_admin = false;
    try { $uid = (int)((function_exists('current_user') ? (current_user()['id'] ?? 0) : 0)); } catch (\Throwable $x) {}
    try { $is_admin = function_exists('is_admin') && is_admin(); } catch (\Throwable $x) {}
    error_log(sprintf("[%s] %s: %s @ %s:%d | user=%d uri=%s\n%s", $id, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $uid, (string)($_SERVER['REQUEST_URI'] ?? ''), $e->getTraceAsString()));

    [$title, $hint] = crm_error_explain(get_class($e), $e->getMessage());
    $detail = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';

    while (ob_get_level() > 0 && !crm_error_wants_json() && headers_sent() === false) { @ob_end_clean(); }
    if (crm_error_wants_json()) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok' => false, 'error' => $title . '. ' . $hint, 'error_id' => $id] + ($is_admin ? ['detail' => $detail] : []), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $e_ = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $base = defined('APP_URL') ? APP_URL : '';
    $box = '<div role="alert" style="max-width:640px;margin:2rem auto;padding:1.25rem 1.5rem;background:#fff;border:1px solid #D1D5DB;border-left:6px solid #C23934;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#1F2937">'
         . '<h1 style="margin:0 0 .5rem;font-size:1.25rem;font-weight:600">' . $e_($title) . '</h1>'
         . '<p style="margin:0 0 .75rem;line-height:1.5">' . $e_($hint) . '</p>'
         . '<p style="margin:0 0 1rem;font-size:.9rem">Kod zgłoszenia: <strong style="font-family:monospace;user-select:all">' . $e_($id) . '</strong></p>'
         . '<p style="margin:0"><a href="javascript:location.reload()" style="display:inline-block;padding:.4rem .9rem;background:#0B5CAB;color:#fff;text-decoration:none;margin-right:.5rem">Spróbuj ponownie</a>'
         . '<a href="' . $e_($base) . '/crm/dashboard.php" style="display:inline-block;padding:.4rem .9rem;border:1px solid #6B7280;color:#1F2937;text-decoration:none">Wróć do pulpitu CRM</a></p>'
         . ($is_admin ? '<details style="margin-top:1rem;font-size:.82rem;color:#4B5563"><summary style="cursor:pointer">Szczegóły techniczne (widoczne tylko dla administratora)</summary><pre style="white-space:pre-wrap;margin:.5rem 0 0">' . $e_($detail) . '</pre></details>' : '')
         . '</div>';

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e_($title) . ' — CRM</title></head><body style="margin:0;background:#F3F4F6">' . $box . '</body></html>';
    } else {
        echo $box;   // strona już się zaczęła — komunikat wstawiamy w miejscu błędu
    }
    exit;
}

/** Rejestruje obsługę błędów dla stron CRM (raz na żądanie). */
function crm_errors_register(): void {
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return;
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!preg_match('#/crm/#', $path)) return;
    $done = true;
    set_exception_handler('crm_error_render');
    register_shutdown_function(static function () {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) return;
        crm_error_render(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
    });
}
crm_errors_register();
