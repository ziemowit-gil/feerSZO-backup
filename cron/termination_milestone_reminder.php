<?php
/**
 * Skrypt cron: Przypomnienia „milowe" o upływającym okresie wypowiedzenia
 * porozumienia wolontariackiego (§ 7 contracts/wolontariat/print.php).
 * Uruchamiaj raz dziennie, np. o 8:00:
 *   0 8 * * * php /var/www/html/cron/termination_milestone_reminder.php
 *
 * Wysyła email do wolontariusza, gdy efektywna data zakończenia współpracy
 * (wyliczona z wybranego trybu rozwiązania) jest za 7 dni, za 1 dzień lub
 * przypada dzisiaj. Pomija duplikaty: wysłane przypomnienia są odnotowywane
 * w tabeli settings. Obejmuje tylko tryby z realnym okresem wypowiedzenia
 * (notice_days > 0) — tryby natychmiastowe kończą współpracę od razu.
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
require_once $base_dir . '/includes/termination.php';

$today       = date('Y-m-d');
$notify_days = [7, 1, 0];

$sent = 0;
$skip = 0;
$errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: termination_milestone_reminder\n";

try {
    $requests = db_all(
        "SELECT r.*, w.numer_umowy, w.imie_nazwisko, w.email
         FROM contract_termination_requests r
         JOIN umowy_wolontariat w ON w.id = r.contract_id
         WHERE r.contract_type = 'wolontariat'
           AND r.status IN ('oczekuje', 'zaakceptowany')
           AND r.notice_days IS NOT NULL AND r.notice_days > 0
           AND r.effective_date IS NOT NULL",
        []
    );
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD zapytania: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($requests as $r) {
    $effective_date = $r['effective_date'];
    $days_left = (int) round((strtotime($effective_date) - strtotime($today)) / 86400);

    if (!in_array($days_left, $notify_days, true)) continue;

    $req_id = (int) $r['id'];
    $numer  = $r['numer_umowy'] ?? "#{$r['contract_id']}";
    $osoba  = $r['imie_nazwisko'] ?? '';
    $email  = $r['email'] ?? '';

    if (!$email) {
        echo "  [SKIP] wniosek #{$req_id} ({$osoba}) — brak adresu e-mail wolontariusza\n";
        $skip++;
        continue;
    }

    $settings_key = "termination_milestone_sent_{$req_id}_{$days_left}";
    $already_sent = db_one("SELECT value FROM settings WHERE key_=?", [$settings_key]);
    if ($already_sent) {
        $skip++;
        continue;
    }

    $data_pl = date_pl($effective_date);
    $urgency_color = match (true) {
        $days_left <= 0 => '#dc3545',
        $days_left <= 1 => '#fd7e14',
        default          => '#0d6efd',
    };
    $urgency_text = match (true) {
        $days_left <= 0 => 'Dzisiaj upływa okres wypowiedzenia Twojego porozumienia wolontariackiego.',
        $days_left === 1 => 'Okres wypowiedzenia Twojego porozumienia wolontariackiego upływa <strong>jutro</strong>.',
        default          => "Okres wypowiedzenia Twojego porozumienia wolontariackiego upływa za <strong>{$days_left} dni</strong>.",
    };
    $urgency_short = match (true) {
        $days_left <= 0  => 'okres wypowiedzenia upływa dzisiaj',
        $days_left === 1 => 'okres wypowiedzenia upływa jutro',
        default          => "okres wypowiedzenia upływa za {$days_left} dni",
    };

    $rendered = email_tpl_render('termination_milestone', [
        'accent'           => $urgency_color,
        'osoba'            => $osoba,
        'numer'            => $numer,
        'urgency_text'     => $urgency_text,
        'urgency_short'    => $urgency_short,
        'data_zakonczenia' => $data_pl,
        'url'              => APP_URL . '/contracts/wolontariat/view.php?id=' . (int) $r['contract_id'],
    ]);
    if (!$rendered['enabled']) { $skip++; continue; }

    try {
        mail_queue_add($email, $osoba, $rendered['subject'], $rendered['html'], '', 'wolontariat', (int) $r['contract_id']);

        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$settings_key, $today]);

        echo "[" . date('Y-m-d H:i:s') . "] wniosek #{$req_id} ({$osoba}, {$numer}) — wysłano przypomnienie ({$days_left} dni)\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD wniosek #{$req_id}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

try {
    $result = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka pocztowa: wysłano={$result['sent']}, błędy={$result['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD kolejki pocztowej: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano: {$sent}, pominięto: {$skip}, błędy: {$errs}\n";
