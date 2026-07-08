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
 * Generuje kod QR jako lokalny plik PNG (bez wysyłania danych do zewnętrznego serwisu)
 * i zwraca jego ścieżkę. Przeznaczone do osadzania w PDF (FPDF/FPDI) dla treści
 * wrażliwych. Plik ląduje w $dir (musi być zapisywalny) — wywołujący usuwa go po użyciu.
 * Zwraca null, gdy biblioteka endroid/GD są niedostępne lub zapis się nie powiódł.
 */
function qr_png_file(string $data, string $dir, int $size = 300): ?string {
    // Biblioteka endroid emituje E_DEPRECATED pod PHP 8.4 — częściowo już przy
    // kompilacji klas (autoload). Gdy funkcję wywołano w trakcie budowania PDF,
    // taki komunikat (przy display_errors=On) uszkodziłby strumień pliku. Dlatego
    // wyciszamy deprecacje i buforujemy stray-output ZANIM dotkniemy klas endroid.
    $er = error_reporting();
    error_reporting($er & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ob_start();
    try {
        if (!class_exists(\Endroid\QrCode\Builder\Builder::class)) return null;
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($data)
            ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
            ->errorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Medium)
            ->size($size)
            ->margin(1)
            ->build();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        $path = rtrim($dir, '/') . '/_qr_' . uniqid('', true) . '.png';
        if (file_put_contents($path, $result->getString()) === false) return null;
        return $path;
    } catch (\Throwable $e) {
        return null;
    } finally {
        ob_end_clean();
        error_reporting($er);
    }
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
