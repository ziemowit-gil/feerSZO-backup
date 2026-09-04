<?php
/**
 * cli/seed_edok_kontrahent_test.php — zakłada dane testowe do QA Portalu
 * Kontrahenta (portal_kontrahenta/): jeden kontrahent testowy (NIP + konto
 * AKTYWNE od razu, bez przechodzenia przez e-mail weryfikacyjny) + kilka
 * dokumentów EODoK w różnych stanach, żeby pulpit miał co pokazać.
 *
 * Idempotentne: jeśli dane konto już istnieje, pomija je (nie nadpisuje hasła).
 *
 * Użycie:
 *   php cli/seed_edok_kontrahent_test.php '<haslo>' [domena_email]
 *
 * Przykład:
 *   php cli/seed_edok_kontrahent_test.php 'MojeHaslo123!' feer.test
 *
 * Hasło podajesz jako argument — NIE jest zapisane na stałe w tym pliku.
 * Domena e-mail domyślnie "feer.test" (RFC 2606, nierozwiązywalna — żeby
 * żaden e-mail nie poszedł naprawdę; i tak nie wysyłamy weryfikacyjnego,
 * bo konto jest zakładane od razu jako 'aktywne').
 *
 * Po testach uruchom cli/cleanup_edok_kontrahent_test.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$password = $argv[1] ?? '';
$domain   = $argv[2] ?? 'feer.test';

if ($password === '') {
    fwrite(STDERR, "Użycie: php cli/seed_edok_kontrahent_test.php '<haslo>' [domena_email]\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Hasło musi mieć co najmniej 8 znaków.\n");
    exit(2);
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/edok.php';
require_once $base . '/includes/edok_portal_auth.php';

edok_migrate();
edok_portal_migrate();

const TEST_NIP   = '5260001246'; // suma kontrolna poprawna — patrz edok_nip_valid()
$email  = 'kontrahent.test@' . $domain;
$nazwa  = 'Kontrahent Testowy (TEST)';

// ── Dokumenty EODoK dla tego NIP-u, w różnych stanach ─────────────────────────
$docs = [
    ['suffix' => 'A', 'status' => 'w_obiegu',      'status_platnosci' => 'nowy',    'kwota_brutto' => '250,00'],
    ['suffix' => 'B', 'status' => 'zaakceptowany', 'status_platnosci' => 'nowy',    'kwota_brutto' => '480,50'],
    ['suffix' => 'C', 'status' => 'zaakceptowany', 'status_platnosci' => 'oplacony','kwota_brutto' => '1200,00'],
];

foreach ($docs as $d) {
    $number = 'EODOK-TEST/' . $d['suffix'];
    $existing = db_one("SELECT id FROM edok_documents WHERE number=?", [$number]);
    if ($existing) {
        echo "POMINIĘTO dokument {$number} — już istnieje (id={$existing['id']}).\n";
        continue;
    }
    $doc_id = db_insert('edok_documents', [
        'number'              => $number,
        'title'               => 'FV/TEST/' . $d['suffix'],
        'typ_dokumentu'       => 'faktura_vat',
        'description'         => 'Dokument testowy (TEST) do QA Portalu Kontrahenta.',
        'kontrahent_nazwa'    => $nazwa,
        'kontrahent_nip'      => TEST_NIP,
        'nr_faktury'          => 'FV/TEST/' . $d['suffix'],
        'data_wystawienia'    => date('Y-m-d'),
        'kwota_netto'         => '',
        'kwota_vat'           => '',
        'kwota_brutto'        => $d['kwota_brutto'],
        'waluta'              => 'PLN',
        'rodzaj_dzialalnosci' => 'nieodplatna',
        'status'              => $d['status'],
        'status_platnosci'    => $d['status_platnosci'],
        'termin_platnosci'    => date('Y-m-d', strtotime('+14 days')),
        'created_by'          => null,
        'creator_name'        => 'seed_edok_kontrahent_test.php',
        'created_at'          => date('Y-m-d H:i:s'),
        'updated_at'          => date('Y-m-d H:i:s'),
    ]);
    echo "UTWORZONO dokument {$number} (id={$doc_id}, status={$d['status']}/{$d['status_platnosci']}).\n";
}

// ── Konto Portalu Kontrahenta — 'aktywne' od razu, bez maila weryfikacyjnego ──
$existing_acc = edok_portal_account_by_email($email);
if ($existing_acc) {
    echo "POMINIĘTO konto {$email} — już istnieje (id={$existing_acc['id']}), hasło NIE zmienione.\n";
} else {
    $id = db_insert('edok_kontrahent_accounts', [
        'nip'           => TEST_NIP,
        'email'         => $email,
        'nazwa'         => $nazwa,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'status'        => 'aktywne',
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
    echo "UTWORZONO konto {$email} (id={$id}, NIP=" . TEST_NIP . ").\n";
}

echo "\nGotowe. Zaloguj się na " . (defined('APP_URL') ? APP_URL : '') . "/portal_kontrahenta/login.php\n";
echo "  Login: {$email}  (albo NIP: " . TEST_NIP . ", jeśli to jedyne konto na ten NIP)\n";
echo "  Hasło: [podane w argumencie]\n";
echo "\nPo testach: php cli/cleanup_edok_kontrahent_test.php" . ($domain !== 'feer.test' ? " {$domain}" : '') . "\n";
