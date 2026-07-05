<?php
/**
 * Skrypt cron: Odnowienie zgody przedstawiciela ustawowego na wolontariat
 * małoletniego + RODO (ważna 6 miesięcy — art. 17-18 KC, art. 6/8 RODO).
 * Uruchamiaj raz dziennie, np. o 7:20:
 *   0 7 * * * php /var/www/html/cron/guardian_consent_renewal.php
 *
 * Dla każdej aktywnej umowy wolontariackiej z zaznaczonym „niepełnoletni",
 * której zgoda nigdy nie była udzielona albo wygasa w ciągu 14 dni (lub już
 * wygasła), otwiera sprawę EZD (JRWA WOL, teczka roczna „Zgody opiekunów…")
 * z pismem wychodzącym i wysyła opiekunowi e-mail zapraszający do złożenia
 * zgody „na klik" w panelu (panel/zgody.php).
 *
 * Nie duplikuje zaproszeń: jeśli dla umowy istnieje już otwarta (niezamknięta)
 * sprawa EZD dot. zgody, kolejne uruchomienie jej nie ponawia — sprawa
 * zamyka się automatycznie, gdy opiekun złoży zgodę w panelu.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/email_templates.php';
require_once $base_dir . '/includes/guardian_consent.php';
require_once $base_dir . '/includes/ezd.php';

const GUARDIAN_CONSENT_REMINDER_LEAD_DAYS = 14;

$today = date('Y-m-d');
$sent = 0; $skip = 0; $errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: guardian_consent_renewal\n";

try {
    $contracts = db_all(
        "SELECT * FROM umowy_wolontariat
         WHERE niepelnoletni = 1
           AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')"
    );
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD zapytania: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($contracts as $c) {
    $contract_id = (int) $c['id'];
    $numer       = $c['numer_umowy'] ?: "#{$contract_id}";
    $osoba       = $c['imie_nazwisko'] ?? '';

    $guardian_email = trim($c['rodzic_email'] ?? '');
    if (!$guardian_email || !filter_var($guardian_email, FILTER_VALIDATE_EMAIL)) {
        echo "  [SKIP] {$numer} ({$osoba}) — brak adresu e-mail przedstawiciela ustawowego\n";
        $skip++;
        continue;
    }
    $guardian_name = $c['rodzic_imie_nazwisko'] ?: $guardian_email;

    // Zgoda ważna i nie zbliża się jej koniec — pomiń.
    $expires = $c['zgoda_przedstawiciela_wygasa'] ?? null;
    $expiring_soon = $expires && (int)round((strtotime($expires) - strtotime($today)) / 86400) <= GUARDIAN_CONSENT_REMINDER_LEAD_DAYS;
    if (guardian_consent_is_valid($c) && !$expiring_soon) {
        $skip++;
        continue;
    }

    // Już zaproszony i sprawa wciąż otwarta — czekamy na działanie opiekuna, nie duplikujemy.
    $open_sprawa_id = (int)($c['zgoda_przedstawiciela_ezd_sprawa_id'] ?? 0);
    if ($open_sprawa_id) {
        $sprawa = ezd_sprawa_get($open_sprawa_id);
        if ($sprawa && $sprawa['status'] !== 'closed') {
            $skip++;
            continue;
        }
    }

    $ezd = ezd_register_guardian_consent_letter($c, $guardian_name, 0);
    $znak_sprawy = $ezd['znak_sprawy'] ?? '';

    $rendered = email_tpl_render('guardian_consent_renewal', [
        'accent'          => '#1D4ED8',
        'org_nazwa'       => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju "FEER"'),
        'org_adres'       => org_setting('org_adres') ?: 'ul. Barbackiego 28/18, 33-300 Nowy Sącz',
        'org_email'       => org_setting('notify_from_email') ?: '',
        'org_telefon'     => org_setting('org_telefon') ?: '',
        'znak_sprawy'     => $znak_sprawy,
        'miejscowosc'     => org_setting('org_miejscowosc') ?: 'Nowy Sącz',
        'data_pisma'      => date('d.m.Y', strtotime($today)),
        'dziecko'         => $osoba,
        'login_url'       => APP_URL . '/auth/login.php',
        'kontakt_email'   => org_setting('notify_from_email') ?: '',
        'kontakt_telefon' => org_setting('org_telefon') ?: '',
    ]);
    if (!$rendered['enabled']) { $skip++; continue; } // wyłączony przez administratora

    try {
        mail_queue_add($guardian_email, $guardian_name, $rendered['subject'], $rendered['html']);
        echo "[" . date('Y-m-d H:i:s') . "] {$numer} ({$osoba}) — wysłano zaproszenie do {$guardian_email} [{$znak_sprawy}]\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD {$numer}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

// Przetwórz kolejkę pocztową
try {
    $result = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka pocztowa: wysłano={$result['sent']}, błędy={$result['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD kolejki pocztowej: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano: {$sent}, pominięto: {$skip}, błędy: {$errs}\n";
