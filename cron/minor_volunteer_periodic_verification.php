<?php
/**
 * Skrypt cron: Okresowa weryfikacja wolontariuszy niepełnoletnich.
 * Uruchamiaj raz dziennie, np. o 8:00 (cykl 90-dniowy liczony jest wewnątrz
 * skryptu — samo zadanie cron może działać codziennie):
 *   0 8 * * * php /var/www/html/cron/minor_volunteer_periodic_verification.php
 *
 * Co 90 dni (a przy pierwszym uruchomieniu — od razu) wysyła do opiekuna
 * (a w razie jego braku do samego wolontariusza) e-mail informujący, że
 * w związku z okresową weryfikacją wolontariatu zostanie do niego nadane
 * pismo w sprawie wolontariusza niepełnoletniego. Dotyczy umów wolontariackich
 * z zaznaczonym „niepełnoletni" i aktywnym statusem.
 *
 * Pomija duplikaty: data ostatniej wysyłki na kontrakt jest odnotowywana
 * w tabeli settings (klucz minor_verif_last_sent_wolontariat_{id}).
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
require_once $base_dir . '/includes/notifications.php';

const MINOR_VERIF_INTERVAL_DAYS = 90;

$today = date('Y-m-d');
notif_migrate();

$admins = db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1");

$sent = 0; $skip = 0; $errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: minor_volunteer_periodic_verification\n";

try {
    $contracts = db_all(
        "SELECT id, numer_umowy, imie_nazwisko, email, rodzic_email, rodzic_imie_nazwisko, data_zawarcia
         FROM umowy_wolontariat
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

    // Adresat: opiekun (rodzic_email), gdy podany; inaczej sam wolontariusz.
    $to_email = $c['rodzic_email'] ?: ($c['email'] ?? '');
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        echo "  [SKIP] {$numer} ({$osoba}) — brak adresu e-mail opiekuna/wolontariusza\n";
        $skip++;
        continue;
    }
    $to_name = $c['rodzic_imie_nazwisko'] ?: $osoba;

    // Co 90 dni: pierwsze uruchomienie wysyła od razu (brak wpisu w settings),
    // kolejne — dopiero po upływie interwału od ostatniej wysyłki.
    $settings_key = "minor_verif_last_sent_wolontariat_{$contract_id}";
    $last_sent    = db_one("SELECT value FROM settings WHERE key_=?", [$settings_key]);
    if ($last_sent) {
        $days_since = (int) round((strtotime($today) - strtotime($last_sent['value'])) / 86400);
        if ($days_since < MINOR_VERIF_INTERVAL_DAYS) {
            $skip++;
            continue;
        }
    }

    $rendered = email_tpl_render('minor_volunteer_periodic_verification', [
        'accent'           => '#0ea5e9',
        'osoba'            => $osoba,
        'opiekun_nazwa'    => htmlspecialchars($to_name),
        'numer'            => $numer,
        'data_weryfikacji' => date('d.m.Y', strtotime($today)),
    ]);
    if (!$rendered['enabled']) { $skip++; continue; } // wyłączony przez administratora

    try {
        mail_queue_add($to_email, $to_name, $rendered['subject'], $rendered['html']);

        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$settings_key, $today]);

        echo "[" . date('Y-m-d H:i:s') . "] {$numer} ({$osoba}) — wysłano powiadomienie do {$to_email}\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD {$numer}: " . $e->getMessage() . "\n";
        $errs++;
        continue;
    }

    // Info dla admina — pismo trzeba przygotować i wysłać ręcznie.
    $contract_url = defined('APP_URL') ? APP_URL . '/contracts/wolontariat/view.php?id=' . $contract_id : '';
    $admin_rendered = email_tpl_render('minor_volunteer_periodic_verification_admin', [
        'accent'           => '#0ea5e9',
        'osoba'            => $osoba,
        'numer'            => $numer,
        'opiekun_nazwa'    => $to_name,
        'opiekun_email'    => $to_email,
        'data_weryfikacji' => date('d.m.Y', strtotime($today)),
        'url'              => $contract_url,
    ]);
    foreach ($admins as $admin) {
        try { notif_create((int)$admin['id'], 'contract', 'Do wysłania: pismo ws. weryfikacji — ' . $osoba, '', $contract_url); }
        catch (\Throwable $e) {}
        if ($admin_rendered['enabled'] && !empty($admin['email'])) {
            try { mail_queue_add($admin['email'], $admin['name'] ?? '', $admin_rendered['subject'], $admin_rendered['html']); }
            catch (\Throwable $e) {}
        }
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
