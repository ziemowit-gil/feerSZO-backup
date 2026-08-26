#!/usr/bin/env php
<?php
/**
 * cron/agents/never_logged_in_reminder.php — Przypomnienie o aktywacji konta w SZO.
 *
 * Uruchamiany raz dziennie przez cron/dispatcher.php (między 8:00 a 10:00).
 * Można uruchomić ręcznie: php cron/agents/never_logged_in_reminder.php
 *
 * Dla każdego aktywnego użytkownika, który NIE zalogował się ani razu do SZO:
 *   - wysyła e-mail z instrukcją aktywacji konta,
 *   - opcjonalnie SMS, jeśli użytkownik ma numer telefonu i SMS jest włączony,
 *   - zapisuje datę i liczbę wysyłek w settings (dedup: max 4×, min. 14 dni przerwy),
 *   - pomija konta starsze niż 90 dni (nie ma sensu przypominać po tak długim czasie).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

define('APP_CLI', true);
$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/sms.php';

$today     = date('Y-m-d');
$org       = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER');
$app_url   = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://szo.feer.org.pl';
$sent = 0; $skip = 0; $errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: never_logged_in_reminder\n";

// Użytkownicy aktywni, którzy nigdy nie zalogowali się do SZO.
// Konto musi istnieć co najmniej 3 dni (czas na pierwsze logowanie),
// ale nie dłużej niż 90 dni (przestajemy przypominać).
$users = db_all(
    "SELECT u.id, u.name, u.email, u.role, u.created_at, u.phone_number
     FROM users u
     WHERE u.is_active = 1
       AND LOWER(u.email) != 'serwis@local'
       AND u.created_at <= datetime('now', '-3 days')
       AND u.created_at >= datetime('now', '-90 days')
       AND NOT EXISTS (
           SELECT 1 FROM login_log l
           WHERE l.user_id = u.id
             AND l.action IN ('login', 'login_sms', 'login_x509', 'login_code')
       )
       AND NOT EXISTS (
           SELECT 1 FROM contract_audit_log cal
           WHERE cal.user_id = u.id
             AND cal.contract_type = 'auth'
             AND cal.action = 'login_ms'
       )
     ORDER BY u.created_at ASC",
    []
);

echo "[" . date('Y-m-d H:i:s') . "] Znaleziono użytkowników bez logowania: " . count($users) . "\n";

foreach ($users as $u) {
    $uid   = (int)$u['id'];
    $name  = $u['name'] ?? '';
    $email = $u['email'] ?? '';

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  [SKIP] uid={$uid} ({$name}) — brak prawidłowego adresu e-mail\n";
        $skip++;
        continue;
    }

    // Dedup: max 4 wysyłki, min. 14 dni przerwy
    $settings_key = "never_login_reminder_{$uid}";
    $meta_raw     = db_one("SELECT value FROM settings WHERE key_=?", [$settings_key]);
    $meta         = $meta_raw ? (json_decode($meta_raw['value'], true) ?? []) : [];
    $count        = (int)($meta['count'] ?? 0);
    $last_sent    = $meta['last'] ?? '';

    if ($count >= 4) {
        echo "  [SKIP] uid={$uid} ({$email}) — wyczerpano limit 4 przypomnień\n";
        $skip++;
        continue;
    }
    if ($last_sent && (strtotime($today) - strtotime($last_sent)) < 14 * 86400) {
        echo "  [SKIP] uid={$uid} ({$email}) — ostatnie przypomnienie {$last_sent} (min. 14 dni przerwy)\n";
        $skip++;
        continue;
    }

    // ── E-mail ────────────────────────────────────────────────────────────────
    $tozsamosc_url  = $app_url . '/tozsamosc';
    $contact_email  = 'fundacja@feer.org.pl';
    $name_html      = htmlspecialchars($name);
    $greeting       = $name_html ? "Dzień dobry, {$name_html}," : 'Dzień dobry,';

    $subject = "Pilne: Do tej pory nie aktywowałeś/aś konta w systemie FEER (SZO)";
    $body_html = <<<HTML
<html><body style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#c2410c;padding:18px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">Fundacja FEER &mdash; System Wspomagania Zarządzania Organizacją (SZO)</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>{$greeting}</p>
  <p>Zauważyliśmy, że do tej pory nie aktywowałeś/aś swojego konta w zintegrowanym środowisku Fundacji FEER.</p>
  <p>Przypominamy, że w związku z posiadaną umową o współpracę przechodzimy na nasz centralny system zarządzania.
     Zgodnie z przyjętą zasadą, odchodzimy od rozproszonych rozwiązań &ndash; wszystkie zadania, zarządzanie
     umowami oraz komunikacja odbywają się obecnie w jednym, centralnym punkcie (SZO).</p>

  <p><strong>Co zyskujesz w module SZO?</strong></p>
  <ul>
    <li><strong>Zadania:</strong> Bieżący wgląd w powierzone zadania i ich statusy.</li>
    <li><strong>Zarządzanie umową:</strong> Przejrzysty dostęp do informacji i dokumentacji związanej z Twoją umową.</li>
    <li><strong>Komunikacja:</strong> Jeden, zintegrowany kanał wymiany informacji z fundacją.</li>
  </ul>

  <p><strong>Instrukcja szybkiej aktywacji konta</strong></p>
  <ol style="line-height:1.8">
    <li>
      <strong>Węzeł tożsamości (Centralny punkt logowania):</strong><br>
      Przejdź pod adres: <a href="{$tozsamosc_url}" style="color:#c2410c">{$tozsamosc_url}</a>
    </li>
    <li>
      <strong>Pierwsze logowanie:</strong><br>
      Użyj mechanizmu węzła tożsamości FEER, aby uwierzytelnić się w systemie.
    </li>
    <li>
      <strong>Aktywacja konta w SZO:</strong><br>
      Po zalogowaniu system poprowadzi Cię przez proces aktywacji profilu w module SZO
      (Zadania, Zarządzanie umową i komunikacja).
    </li>
  </ol>

  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:20px 0;font-size:.92em">
    ⚠️ Prosimy o pilne dokończenie procesu aktywacji.
  </div>

  <p>W razie trudności technicznych lub pytań, prosimy o kontakt pod adresem:
     <a href="mailto:{$contact_email}" style="color:#c2410c">{$contact_email}</a>.</p>

  <p>Z pozdrowieniami,<br><strong>Zespół Fundacji FEER</strong></p>

  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:24px">
    Wiadomość wysłana automatycznie przez system SZO &mdash; {$org}.
  </p>
</div>
</body></html>
HTML;

    $body_text = "Dzień dobry {$name},\n\n"
        . "Zauważyliśmy, że do tej pory nie aktywowałeś/aś swojego konta w zintegrowanym środowisku Fundacji FEER.\n\n"
        . "Aby aktywować konto, przejdź pod adres:\n{$tozsamosc_url}\n\n"
        . "W razie pytań: {$contact_email}\n\n"
        . "Z pozdrowieniami,\nZespół Fundacji FEER";

    try {
        mail_queue_add($email, $name, $subject, $body_html, $body_text, 'user', $uid);

        // ── SMS (opcjonalny) ──────────────────────────────────────────────────
        $phone = trim((string)($u['phone_number'] ?? ''));
        if ($phone && function_exists('sms_is_enabled') && sms_is_enabled()) {
            $sms_text = "FEER SZO: Nie aktywowałeś/aś jeszcze konta. Zaloguj się: {$tozsamosc_url} Pytania: {$contact_email}";
            try {
                sms_send($phone, $sms_text);
                echo "  [SMS] uid={$uid} → {$phone}\n";
            } catch (\Throwable $sms_e) {
                echo "  [SMS-ERR] uid={$uid}: " . $sms_e->getMessage() . "\n";
            }
        }

        // Zapisz metadane dedup
        $meta['count'] = $count + 1;
        $meta['last']  = $today;
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
             ->execute([$settings_key, json_encode($meta)]);

        echo "[" . date('Y-m-d H:i:s') . "] uid={$uid} ({$email}) — wysłano przypomnienie #{$meta['count']}\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD uid={$uid} ({$email}): " . $e->getMessage() . "\n";
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
