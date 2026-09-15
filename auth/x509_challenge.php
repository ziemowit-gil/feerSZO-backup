<?php
/**
 * auth/x509_challenge.php — wydaje jednorazowe wyzwanie (nonce) do podpisania
 * przez aplikację kliencką SzoCert (bin/szocert-app) przy logowaniu
 * certyfikatem X.509 (challenge-response, patrz includes/x509_login.php).
 *
 * Publiczny (bez logowania — to KROK PRZED zalogowaniem), ale bez żadnych
 * danych o użytkowniku w odpowiedzi — samo losowe wyzwanie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/x509_login.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $challenge = x509_challenge_create();
    echo json_encode(['ok' => true] + $challenge);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nie udało się utworzyć wyzwania.']);
}
exit;
