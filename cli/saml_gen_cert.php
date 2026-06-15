<?php
/**
 * cli/saml_gen_cert.php — generator dedykowanego certyfikatu SAML IdP.
 *
 * Tworzy parę kluczy (RSA-2048, self-signed, 5 lat):
 *   certs/saml-idp.crt   — certyfikat PEM (publiczny, trafia do metadata IdP)
 *   certs/saml-idp.key   — klucz prywatny PEM (chmod 600)
 *
 * Użycie:
 *   php cli/saml_gen_cert.php             # generuj, jeśli brak
 *   php cli/saml_gen_cert.php --force     # nadpisz istniejący (rotacja klucza)
 *   php cli/saml_gen_cert.php --status    # pokaż stan certyfikatu
 *
 * Wymaga: PHP z rozszerzeniem openssl.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko CLI.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/saml_idp.php';

if (in_array('--status', $argv, true)) {
    $info = saml_idp_cert_info();
    if (!$info) { echo "[STATUS] Brak certyfikatu SAML — uruchom bez --status, aby wygenerować.\n"; exit(0); }
    echo "┌─ Certyfikat SAML IdP ─────────────────────────────────────────────\n";
    echo "│ Typ:         " . ($info['dedicated'] ? 'dedykowany (saml-idp.*)' : 'współdzielony z certem instalacji (app.*)') . "\n";
    echo "│ Podmiot:     {$info['subject']}\n";
    echo "│ Ważny od:    {$info['valid_from']}\n";
    echo "│ Ważny do:    {$info['valid_to']}  ({$info['days_left']} dni)\n";
    echo "│ SHA-256:     {$info['fingerprint']}\n";
    echo "│ EntityID:    " . saml_idp_entity_id() . "\n";
    echo "└───────────────────────────────────────────────────────────────────\n";
    exit($info['days_left'] < 0 ? 1 : 0);
}

$force = in_array('--force', $argv, true);
$res = saml_idp_generate_cert($force);

if (!$res['ok']) {
    fwrite(STDERR, "[BLAD] {$res['msg']}\n");
    if (!$force && str_contains($res['msg'], 'już istnieje')) {
        fwrite(STDERR, "Aby nadpisać (rotacja klucza): php cli/saml_gen_cert.php --force\n");
    }
    exit(1);
}

echo "[OK] {$res['msg']}\n";
$info = saml_idp_cert_info();
if ($info) {
    echo "     Ważny do: {$info['valid_to']}  (SHA-256: {$info['fingerprint']})\n";
}
echo "     EntityID: " . saml_idp_entity_id() . "\n";
echo "     Metadata: " . saml_idp_metadata_url() . "\n";
