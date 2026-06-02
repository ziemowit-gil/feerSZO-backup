<?php
/**
 * Moduł WhatsApp — SMSAPI.pl
 */

function wa_setting(string $key): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        return $r['value'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

function wa_enabled(): bool {
    return wa_setting('wa_enabled') === '1';
}

function wa_chat_url(): string {
    $phone = preg_replace('/\D/', '', wa_setting('wa_phone_number'));
    return $phone ? 'https://wa.me/' . $phone : '';
}

/**
 * Wysyła wiadomość WhatsApp przez SMSAPI.pl
 * @throws RuntimeException
 */
function wa_send_message(string $to_phone, string $message): void {
    $token = wa_setting('wa_smsapi_token');
    $from  = wa_setting('wa_phone_number');

    if (!$token) throw new \RuntimeException('Brak tokenu SMSAPI.pl dla WhatsApp.');
    if (!$from)  throw new \RuntimeException('Brak numeru WhatsApp Business (nadawca).');

    $to = preg_replace('/\D/', '', $to_phone);
    if (strlen($to) === 9) $to = '48' . $to;

    $payload = json_encode([
        'from'    => $from,
        'to'      => $to,
        'message' => ['type' => 'text', 'text' => ['body' => $message]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.smsapi.com/whatsapp/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) throw new \RuntimeException('cURL: ' . $err);

    $data = json_decode($resp, true) ?? [];

    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? $data['message'] ?? "HTTP $code";
        throw new \RuntimeException('SMSAPI WhatsApp: ' . $msg);
    }
}
