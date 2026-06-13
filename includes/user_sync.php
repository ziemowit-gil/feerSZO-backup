<?php
/**
 * includes/user_sync.php — Wysyłanie kont użytkowników do środowiska testowego.
 *
 * Uruchamiany wyłącznie gdy w config.local.php zdefiniowane są:
 *   TEST_SYNC_URL  — bazowy URL środowiska testowego
 *   TEST_SYNC_KEY  — wspólny tajny klucz (musi być identyczny w obu środowiskach)
 *
 * Funkcje są no-op gdy TEST_SYNC_URL nie jest zdefiniowane — bezpieczne do włączenia wszędzie.
 */

/**
 * Serializuje dane użytkownika do wysłania (tylko dozwolone pola).
 */
function _user_sync_payload(array $user): array {
    $out = [];
    foreach (['name','first_name','last_name','email','password','role','is_active'] as $f) {
        if (array_key_exists($f, $user)) $out[$f] = $user[$f];
    }
    return $out;
}

/**
 * Wysyła jednego użytkownika do środowiska testowego.
 * Non-blocking (krótki timeout), błędy tylko w error_log.
 */
function user_sync_push(array $user): void {
    if (!defined('TEST_SYNC_URL') || !defined('TEST_SYNC_KEY')) return;

    $payload = _user_sync_payload($user);
    if (!($payload['email'] ?? '')) return;

    _user_sync_post([$payload], timeout_ms: 3000);
}

/**
 * Wysyła tablicę użytkowników do środowiska testowego (cron batch).
 * Zwraca wynik JSON jako tablicę lub null przy błędzie.
 */
function user_sync_batch(array $users): ?array {
    if (!defined('TEST_SYNC_URL') || !defined('TEST_SYNC_KEY')) return null;

    $payloads = array_values(array_filter(array_map('_user_sync_payload', $users)));
    if (!$payloads) return null;

    return _user_sync_post($payloads, timeout_ms: 30000);
}

/**
 * @internal Wykonuje POST do endpointu synchronizacji.
 */
function _user_sync_post(array $payloads, int $timeout_ms = 5000): ?array {
    if (!function_exists('curl_init')) {
        error_log('[user_sync] cURL nie jest dostępny.');
        return null;
    }

    $url  = rtrim((string)TEST_SYNC_URL, '/') . '/api/internal/user_sync.php';
    $body = json_encode(['users' => $payloads], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT_MS      => $timeout_ms,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            'X-Sync-Key: ' . TEST_SYNC_KEY,
        ],
    ]);

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("[user_sync] cURL error ({$url}): {$err}");
        return null;
    }
    if ($code !== 200) {
        error_log("[user_sync] HTTP {$code} from test env");
        return null;
    }

    $json = json_decode((string)$res, true);
    if (!($json['ok'] ?? false)) {
        error_log('[user_sync] Odpowiedź błędu: ' . ($json['msg'] ?? $res));
    }
    return $json;
}
