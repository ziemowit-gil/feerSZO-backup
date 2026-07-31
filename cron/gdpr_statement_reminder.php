<?php
/**
 * Skrypt cron: Przypomnienie o konieczności złożenia oświadczenia o ochronie danych.
 * Wysyła e-mail do użytkowników, którzy:
 *  - mają konto w panelu (w tabeli users)
 *  - NIE mają jeszcze założonego konta M365 (m365_konto=0 lub brak umowy z M365)
 *  - nie podpisali jeszcze oświadczenia (gdpr_statement_signed_at IS NULL)
 *
 * Nie duplikuje przypomnień: wysyła maksymalnie raz na 3 dni
 * (gdpr_statement_reminded_at — pole dodawane automatycznie).
 *
 * Uruchamiaj raz dziennie, np. o 8:00:
 *   0 8 * * * php /var/www/html/cron/gdpr_statement_reminder.php
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';

// Migracja kolumn
try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_signed_at DATETIME"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_ip TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_reminded_at DATETIME"); } catch (\Throwable $e) {}

const GDPR_REMINDER_INTERVAL_DAYS = 3;

$now   = date('Y-m-d H:i:s');
$cutoff = date('Y-m-d H:i:s', strtotime('-' . GDPR_REMINDER_INTERVAL_DAYS . ' days'));
$sent = 0; $skip = 0; $errs = 0;

echo "[{$now}] Start: gdpr_statement_reminder\n";

$org = defined('ORG_NAME') ? ORG_NAME : 'FEER';
$panel_url = rtrim(APP_URL, '/') . '/panel/gdpr_statement.php';

// Pobierz użytkowników bez podpisu, z adresem e-mail, aktywnych,
// których nie powiadamialiśmy przez ostatnie GDPR_REMINDER_INTERVAL_DAYS dni.
try {
    $users = db_all(
        "SELECT id, name, email, gdpr_statement_reminded_at
         FROM users
         WHERE is_active = 1
           AND email IS NOT NULL AND email != ''
           AND gdpr_statement_signed_at IS NULL
           AND (gdpr_statement_reminded_at IS NULL OR gdpr_statement_reminded_at < ?)",
        [$cutoff]
    );
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BŁĄD zapytania: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($users as $u) {
    $uid   = (int)$u['id'];
    $email = trim($u['email']);
    $name  = trim($u['name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  [SKIP] uid={$uid} — nieprawidłowy adres e-mail\n";
        $skip++;
        continue;
    }

    // Sprawdź czy użytkownik ma już konto M365 (jeśli tak — nie przypominaj)
    $has_m365 = false;
    foreach (['wolontariat', 'zlecenie', 'dzielo'] as $t) {
        if (db_one("SELECT id FROM umowy_{$t} WHERE (email=? OR m365_login=?) AND m365_konto=1 LIMIT 1",
                   [$email, $email])) {
            $has_m365 = true; break;
        }
    }
    if ($has_m365) {
        echo "  [SKIP] {$email} — konto M365 już założone\n";
        $skip++;
        continue;
    }

    $name_h  = htmlspecialchars($name  ?: $email, ENT_QUOTES);
    $url_h   = htmlspecialchars($panel_url, ENT_QUOTES);
    $org_h   = htmlspecialchars($org, ENT_QUOTES);

    $subject = "[{$org}] Wymagane podpisanie oświadczenia — Panel Współpracownika";

    $body_text = "Dzień dobry,\n\n"
        . "Zauważyliśmy w systemie, że nie zostało jeszcze przez Ciebie złożone elektroniczne "
        . "podpisanie wymaganego oświadczenia dotyczącego Polityki Ochrony Danych Osobowych "
        . "oraz Regulaminu informatycznego.\n\n"
        . "Przypominamy, że podpisanie tego dokumentu jest bezwzględnym wymogiem przed aktywacją "
        . "Twojego konta w usłudze M365 oraz dalszym korzystaniem z panelu współpracownika.\n\n"
        . "Co należy zrobić?\n\n"
        . "1. Zaloguj się do panelu współpracownika: {$panel_url}\n"
        . "2. Zapoznaj się z treścią oświadczenia wyświetlaną na ekranie.\n"
        . "3. Kliknij przycisk potwierdzający elektroniczne podpisanie dokumentu.\n\n"
        . "Bez spełnienia tego warunku aktywacja pakietu M365 pozostaje zablokowana. "
        . "W razie pytań lub problemów technicznych, prosimy o kontakt.\n\n"
        . "Pozdrawiamy,\n{$org}";

    $body_html = <<<HTML
<html><body style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#1f2937">
<div style="background:linear-gradient(135deg,#1D4ED8,#1e40af);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">&#128274; Wymagane podpisanie oświadczenia</h2>
</div>
<div style="border:1px solid #e5e7eb;border-top:none;padding:28px;border-radius:0 0 10px 10px">
  <p>Dzień dobry, <strong>{$name_h}</strong>,</p>
  <p>Zauważyliśmy w systemie, że nie zostało jeszcze przez Ciebie złożone elektroniczne podpisanie
     wymaganego oświadczenia dotyczącego <strong>Polityki Ochrony Danych Osobowych</strong>
     oraz <strong>Regulaminu informatycznego</strong>.</p>
  <p>Przypominamy, że podpisanie tego dokumentu jest <strong>bezwzględnym wymogiem</strong>
     przed aktywacją Twojego konta w usłudze <strong>M365</strong> oraz dalszym korzystaniem
     z panelu współpracownika.</p>

  <div style="background:#FFF7ED;border-left:4px solid #F59E0B;border-radius:6px;padding:14px 18px;margin:20px 0">
    <div style="font-weight:700;margin-bottom:8px;color:#92400E">Co należy zrobić?</div>
    <ol style="margin:0;padding-left:20px;color:#374151;font-size:.92em;line-height:1.7">
      <li>Zaloguj się do panelu współpracownika.</li>
      <li>Zapoznaj się z treścią oświadczenia wyświetlaną na ekranie.</li>
      <li>Kliknij przycisk potwierdzający elektroniczne podpisanie dokumentu.</li>
    </ol>
  </div>

  <div style="margin:24px 0;text-align:center">
    <a href="{$url_h}"
       style="background:#1D4ED8;color:#fff;padding:13px 30px;border-radius:8px;
              text-decoration:none;display:inline-block;font-weight:700;font-size:1rem">
      &#128394; Przejdź do oświadczenia
    </a>
  </div>

  <p style="font-size:.88em;color:#6B7280">
    Bez spełnienia tego warunku aktywacja pakietu M365 pozostaje zablokowana.
    W razie pytań lub problemów technicznych, prosimy o kontakt.
  </p>

  <p>Pozdrawiamy,<br><strong>{$org_h}</strong></p>

  <p style="font-size:.78em;color:#9CA3AF;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:20px">
    Wiadomość automatyczna — Panel Współpracownika {$org_h}
  </p>
</div>
</body></html>
HTML;

    try {
        mail_queue_add($email, $name, $subject, $body_html, $body_text);
        db()->prepare("UPDATE users SET gdpr_statement_reminded_at=? WHERE id=?")
           ->execute([$now, $uid]);
        echo "  [OK]   {$email}\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "  [ERR]  {$email} — " . $e->getMessage() . "\n";
        $errs++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec: wysłano={$sent}, pominięto={$skip}, błędy={$errs}\n";
