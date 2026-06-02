<?php
/**
 * Proxy dla API KRS — pobiera dane organizacji po numerze KRS.
 * Dostępny tylko dla zalogowanego admina SaaS.
 */
require_once __DIR__ . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION[SAAS_SESSION_KEY])) {
    echo json_encode(['error' => 'Brak autoryzacji']); exit;
}

$krs = preg_replace('/\D/', '', trim($_GET['krs'] ?? ''));
if (strlen($krs) < 6 || strlen($krs) > 10) {
    echo json_encode(['error' => 'Nieprawidłowy numer KRS']); exit;
}

$krs_padded = str_pad($krs, 10, '0', STR_PAD_LEFT);

// Próbuj oba rejestry: S (stowarzyszenia/fundacje), P (przedsiębiorcy)
foreach (['S', 'P'] as $rejestr) {
    $url = "https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/{$krs_padded}?rejestr={$rejestr}&format=json";
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header'  => "Accept: application/json\r\nUser-Agent: RejestrUmow/1.0",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) continue;

    $data = json_decode($raw, true);
    if (!is_array($data)) continue;

    // Ścieżka dla rejestru S i P
    $nazwa = $data['odpis']['dane']['dzial1']['danePodmiotu']['nazwa']
          ?? $data['odpis']['dane']['dzial1']['danePodmiotu']['nazwaSkrocona']
          ?? null;

    if ($nazwa) {
        echo json_encode([
            'ok'       => true,
            'org_name' => trim($nazwa),
            'rejestr'  => $rejestr,
            'krs'      => $krs_padded,
        ]);
        exit;
    }
}

echo json_encode(['error' => 'Nie znaleziono podmiotu o numerze KRS ' . $krs_padded]);
