<?php
/**
 * Web Push helper — VAPID keys + zapis subskrypcji.
 * Generuje parę kluczy ECDH P-256 i przechowuje je w tabeli settings.
 */

/**
 * Zwraca parę kluczy VAPID [public, private]. Jeśli nie istnieją — generuje i zapisuje.
 * public  = URL-safe Base64 bez paddingu (użyj jako applicationServerKey w JS)
 * private = PEM (do podpisywania żądań push na serwerze)
 */
function push_vapid_keys(): array {
    $pub  = m365_setting('push_vapid_public');
    $priv = m365_setting('push_vapid_private');
    if ($pub !== '' && $priv !== '') {
        return ['public' => $pub, 'private' => $priv];
    }
    // Generuj parę kluczy ECDH P-256
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) {
        return ['public' => '', 'private' => ''];
    }
    $details = openssl_pkey_get_details($key);
    // Uncompressed point: 0x04 + x + y (każdy 32 bajty)
    $x = $details['ec']['x'] ?? '';
    $y = $details['ec']['y'] ?? '';
    if ($x === '' || $y === '') {
        return ['public' => '', 'private' => ''];
    }
    $pub_raw = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    $pub_b64 = rtrim(strtr(base64_encode($pub_raw), '+/', '-_'), '=');
    openssl_pkey_export($key, $priv_pem);
    m365_save_setting('push_vapid_public',  $pub_b64);
    m365_save_setting('push_vapid_private', $priv_pem);
    return ['public' => $pub_b64, 'private' => $priv_pem];
}
