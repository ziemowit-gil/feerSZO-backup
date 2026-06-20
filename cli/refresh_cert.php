<?php
/**
 * cli/refresh_cert.php — Odświeżenie certyfikatu instalacyjnego aplikacji.
 *
 * Nadpisuje komplet plików certs/app.* (crt/key/sig/salt) nowym, samopodpisanym
 * certyfikatem X.509 RSA-2048. DOMYŚLNIE WAŻNY 3 LATA. KRS i nazwę bierze z
 * argumentów lub z bazy (settings org_krs / org_name).
 *
 * Użycie:
 *   php cli/refresh_cert.php                  # odśwież na 3 lata (dane z bazy)
 *   php cli/refresh_cert.php --years=3        # jawnie 3 lata
 *   php cli/refresh_cert.php --days=1095      # jawnie w dniach
 *   php cli/refresh_cert.php --krs=0000123456 --name="Fundacja X"
 *   php cli/refresh_cert.php --status         # pokaż stan certyfikatu
 *   php cli/refresh_cert.php --help
 *
 * Kody wyjścia: 0 = OK, 1 = błąd, 2 = błąd krytyczny (brak config).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko CLI.\n");
}

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "[BLAD] Brak config.php.\n");
    exit(2);
}
require_once $root . '/config.php';
require_once $root . '/includes/app_cert.php';

function rc_line(string $s): void { fwrite(STDOUT, $s . "\n"); }

$opts = getopt('', ['status', 'years:', 'days:', 'krs:', 'name:', 'help']);

if (isset($opts['help'])) {
    rc_line("Odświeżenie certyfikatu instalacyjnego aplikacji (domyślnie 3 lata).");
    rc_line("");
    rc_line("  php cli/refresh_cert.php [--years=N | --days=N] [--krs=KRS] [--name=\"Nazwa\"]");
    rc_line("  php cli/refresh_cert.php --status");
    exit(0);
}

// ── Status ─────────────────────────────────────────────────────────────────────
if (isset($opts['status'])) {
    $st = app_cert_status();
    if (!$st) { rc_line("[STATUS] Brak certyfikatu lub plik nieprawidłowy (certs/app.crt)."); exit(0); }
    $tag = $st['days_left'] < 0 ? "[WYGASŁ " . abs($st['days_left']) . " dni temu]"
         : ($st['days_left'] <= 30 ? "[UWAGA: wygasa za {$st['days_left']} dni]" : "[OK: {$st['days_left']} dni]");
    rc_line("┌─ Certyfikat instalacyjny ─────────────────────────────────────────");
    rc_line("│ KRS:         " . ($st['krs'] ?: '—'));
    rc_line("│ Organizacja: " . ($st['cn'] ?: '—'));
    rc_line("│ Ważny od:    " . date('Y-m-d H:i', $st['valid_from']));
    rc_line("│ Ważny do:    " . date('Y-m-d H:i', $st['valid_to']) . "  " . $tag);
    rc_line("│ HMAC:        " . ($st['hmac_ok'] ? "OK (powiązany z tą instalacją)" : "NIEZGODNY / brak app.sig"));
    rc_line("│ Salt:        " . ($st['has_salt'] ? "dostępny (przez /cert-salt.php)" : "brak — odśwież certyfikat"));
    rc_line("└───────────────────────────────────────────────────────────────────");
    exit(0);
}

// ── Ustalenie ważności (domyślnie 3 lata) ─────────────────────────────────────
$days = 0;
if (isset($opts['days']) && ctype_digit((string)$opts['days'])) {
    $days = (int)$opts['days'];
} elseif (isset($opts['years']) && ctype_digit((string)$opts['years'])) {
    $days = (int)$opts['years'] * 365;
} else {
    $days = 3 * 365;  // domyślnie 3 lata
}
if ($days < 1) { fwrite(STDERR, "[BLAD] Nieprawidłowa liczba dni/lat.\n"); exit(1); }

// ── Tożsamość (KRS + nazwa) ────────────────────────────────────────────────────
$id = app_cert_identity((string)($opts['krs'] ?? ''), (string)($opts['name'] ?? ''));
if ($id['krs'] === '') {
    fwrite(STDERR, "[BLAD] Numer KRS jest wymagany (podaj --krs=… lub ustaw settings.org_krs).\n");
    exit(1);
}
if ($id['name'] === '') {
    fwrite(STDERR, "[BLAD] Nazwa organizacji jest wymagana (podaj --name=… lub ustaw settings.org_name).\n");
    exit(1);
}

rc_line("[INFO] KRS:         {$id['krs']}");
rc_line("[INFO] Organizacja: {$id['name']}");
rc_line("[INFO] Ważność:     {$days} dni (" . round($days / 365, 1) . " lat)");
rc_line("[INFO] Odświeżanie certyfikatu (RSA-2048, self-signed)...");

$res = app_cert_generate($id['krs'], $id['name'], $days);
if (!$res['ok']) {
    fwrite(STDERR, "[BLAD] " . $res['msg'] . "\n");
    exit(1);
}

$days_left = (int)ceil(($res['valid_to'] - time()) / 86400);
rc_line("[OK]   Zapisano: certs/app.crt, app.key (600), app.sig, app.salt (640)");
rc_line("");
rc_line("┌─ Certyfikat odświeżony pomyślnie ────────────────────────────────");
rc_line("│ KRS:         KRS:{$id['krs']}");
rc_line("│ Organizacja: {$id['name']}");
rc_line("│ Ważny do:    " . date('Y-m-d', $res['valid_to']) . " (za {$days_left} dni)");
rc_line("│ HMAC:        {$res['sig']}");
rc_line("└──────────────────────────────────────────────────────────────────");
rc_line("");
rc_line("[OK] Gotowe. Jeśli serwer www był uruchomiony, zresetuj OPcache / przeładuj.");
rc_line("     Uwaga: SAML IdP używa dedykowanego certs/saml-idp.* (jeśli istnieje);");
rc_line("     gdy go brak — korzysta z odświeżonego app.* jako fallback.");
exit(0);
