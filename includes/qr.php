<?php
/**
 * includes/qr.php — pomocnik do generowania QR kodów.
 */

/**
 * Zwraca URL do obrazka QR kodu (api.qrserver.com).
 */
function qr_url(string $data, int $size = 200): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
         . '&data=' . urlencode($data);
}

/**
 * Generuje token check-in dla umowy wolontariackiej.
 * Format base64url: wolontariat:{contract_id}:{sha256(contract_id.secret)}
 */
function qr_checkin_token(int $contract_id): string {
    $secret = defined('APP_SECRET') ? APP_SECRET : (defined('APP_KEY') ? APP_KEY : 'fallback_salt');
    $hash   = hash('sha256', $contract_id . $secret);
    $raw    = 'wolontariat:' . $contract_id . ':' . $hash;
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Weryfikuje token i zwraca contract_id lub null.
 */
function qr_verify_token(string $token): ?int {
    $decoded = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4));
    if ($decoded === false) return null;
    $parts = explode(':', $decoded, 3);
    if (count($parts) !== 3 || $parts[0] !== 'wolontariat') return null;
    $contract_id = (int)$parts[1];
    if ($contract_id <= 0) return null;
    $expected_token = qr_checkin_token($contract_id);
    if (!hash_equals($expected_token, $token)) return null;
    return $contract_id;
}

/**
 * Zwraca pełny URL strony check-in dla danej umowy.
 */
function qr_checkin_url(int $contract_id): string {
    return APP_URL . '/timesheets/checkin.php?token=' . qr_checkin_token($contract_id);
}
