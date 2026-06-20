<?php
/**
 * Skrypt cron: Przypomnienie dla wolontariusza bez aktywnego konta.
 * Uruchamiaj raz dziennie (rejestrowany w cron/dispatcher.php).
 *
 * Dla każdej umowy wolontariatu starszej niż 14 dni, której wolontariusz NIE ma
 * aktywnego konta w systemie:
 *   - wysyła przypomnienie do wolontariusza (lub opiekuna, gdy niepełnoletni),
 *   - powiadamia opiekuna umowy (guardian_editor_id → users.email),
 *   - odnotowuje wysyłkę w tabeli settings (jednorazowo na umowę).
 *
 * Po tym etapie kolejne pisma do umowy są wysyłane automatycznie zaszyfrowane —
 * patrz includes/secure_mail.php (wpięcie w contracts/letters/add.php).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/secure_mail.php';

$today = date('Y-m-d');
$org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$sent = 0; $skip = 0; $errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: volunteer_account_reminder\n";

$contracts = db_all(
    "SELECT * FROM umowy_wolontariat
     WHERE created_at IS NOT NULL
       AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')",
    []
);

foreach ($contracts as $c) {
    $cid    = (int) $c['id'];
    $numer  = $c['numer_umowy'] ?? "#{$cid}";
    $osoba  = $c['imie_nazwisko'] ?? '';

    // ≥14 dni od założenia umowy
    $days = (int) floor((strtotime($today) - strtotime(substr((string)$c['created_at'], 0, 10))) / 86400);
    if ($days < 14) { $skip++; continue; }

    // Tylko gdy brak aktywnego konta
    if (volunteer_account_exists($c)) { $skip++; continue; }

    // Dedup — jednorazowo na umowę
    $settings_key = "vol_acct_reminder_sent_{$cid}";
    if (db_one("SELECT value FROM settings WHERE key_=?", [$settings_key])) { $skip++; continue; }

    // Adresat przypomnienia: opiekun prawny gdy niepełnoletni, inaczej wolontariusz
    $to_email = (!empty($c['niepelnoletni']) && !empty($c['rodzic_email']))
        ? $c['rodzic_email']
        : ($c['email'] ?? '');
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        echo "  [SKIP] wolontariat #{$numer} ({$osoba}) — brak adresu e-mail wolontariusza\n";
        $skip++;
        continue;
    }

    $subject = "Załóż konto w systemie — {$org}";
    $body = <<<HTML
<html><body style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#0d6efd;padding:18px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">👋 Aktywuj swoje konto — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$osoba}</strong>!</p>
  <p>Minęło ponad 14 dni od zawarcia Twojej umowy wolontariackiej (nr <strong>{$numer}</strong>),
     a w systemie {$org} nie widzimy jeszcze Twojego aktywnego konta.</p>
  <p>Konto daje Ci stały, bezpieczny dostęp online do umowy oraz wszystkich pism — bez podawania haseł do plików.</p>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:16px 0;font-size:.92em">
    ⚠️ Jeśli konto nie zostanie założone, kolejne pisma do umowy będziemy wysyłać Ci e-mailem
    jako <strong>zaszyfrowane załączniki</strong> (hasło = ostatnie cyfry Twojego numeru PESEL).
  </div>
  <p>W sprawie założenia/aktywacji konta skontaktuj się ze swoim opiekunem.</p>
  <p style="color:#6c757d;font-size:.84em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Wiadomość wysłana automatycznie przez system {$org}.
  </p>
</div></body></html>
HTML;

    try {
        mail_queue_add($to_email, $osoba, $subject, $body, '', 'wolontariat', $cid);

        // Powiadom opiekuna umowy (jeśli da się ustalić jego e-mail)
        $guardian_email = '';
        $gid = (int)($c['guardian_editor_id'] ?? 0);
        if ($gid) {
            $u = db_one("SELECT email FROM users WHERE id=?", [$gid]);
            if ($u && !empty($u['email'])) $guardian_email = $u['email'];
        }
        if ($guardian_email && strcasecmp($guardian_email, $to_email) !== 0) {
            $g_subject = "Wolontariusz bez konta: {$osoba} (umowa {$numer})";
            $g_body = "<p>Wolontariusz <strong>" . htmlspecialchars($osoba) . "</strong> "
                    . "(umowa nr " . htmlspecialchars($numer) . ") nie ma aktywnego konta w systemie "
                    . "po 14 dniach od zawarcia umowy.</p>"
                    . "<p>Wysłaliśmy mu przypomnienie. Kolejne pisma do tej umowy będą wysyłane "
                    . "automatycznie e-mailem jako zaszyfrowane załączniki, dopóki konto nie zostanie założone.</p>";
            mail_queue_add($guardian_email, '', $g_subject, $g_body, '', 'wolontariat', $cid);
        }

        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$settings_key, $today]);

        echo "[" . date('Y-m-d H:i:s') . "] wolontariat #{$numer} ({$osoba}) — wysłano przypomnienie do {$to_email}"
           . ($guardian_email ? " (+ opiekun {$guardian_email})" : '') . "\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD wolontariat #{$numer}: " . $e->getMessage() . "\n";
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
