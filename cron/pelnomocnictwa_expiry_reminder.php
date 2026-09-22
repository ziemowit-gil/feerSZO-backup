<?php
/**
 * Skrypt cron: Przypomnienia o wygasających pełnomocnictwach.
 * Uruchamiany raz dziennie (rejestrowany w cron/dispatcher.php jako
 * 'pelnomocnictwa_expiry'). Dla każdego ważnego, terminowego pełnomocnictwa,
 * które wygasa dokładnie za 30, 7 lub 1 dzień:
 *   - powiadomienie in-app (dzwonek) dla osoby, która założyła wpis (created_by)
 *     oraz dla powiązanego pełnomocnika (pelnomocnik_user_id),
 *   - e-mail do zakładającego wpis,
 *   - wpis do historii (pelnomocnictwa_log, action='reminder').
 * Duplikaty pomijane przez klucz w tabeli settings.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/pelnomocnictwa.php';
require_once $base_dir . '/includes/pelnomocnictwa_ezd.php';
require_once $base_dir . '/includes/notifications.php';
require_once $base_dir . '/includes/mail_queue.php';

if (!function_exists('module_enabled') || !module_enabled('pelnomocnictwa_enabled')) {
    echo "[" . date('Y-m-d H:i:s') . "] Moduł pełnomocnictw wyłączony — pomijam.\n";
    exit;
}

notif_migrate();

$today       = date('Y-m-d');
$notify_days = [30, 7, 1];
$max_days    = max($notify_days);
$app_url     = defined('APP_URL') ? APP_URL : '';
$peln_url    = $app_url . '/pelnomocnictwa/index.php';

$sent = 0; $skip = 0; $errs = 0;
echo "[" . date('Y-m-d H:i:s') . "] Start: pelnomocnictwa_expiry_reminder\n";

foreach (pelnomocnictwa_expiring($max_days) as $p) {
    $days_left = (int) round((strtotime($p['data_waznosci']) - strtotime($today)) / 86400);
    if (!in_array($days_left, $notify_days, true)) { continue; }

    $id  = (int)$p['id'];
    $key = "peln_expiry_sent_{$id}_{$days_left}";
    if (db_one("SELECT value FROM settings WHERE key_=?", [$key])) { $skip++; continue; }

    $data_pl = date('d.m.Y', strtotime($p['data_waznosci']));
    $when    = $days_left === 1 ? 'jutro' : "za {$days_left} dni";
    $title   = "Pełnomocnictwo {$p['numer']} wygasa {$when}";
    $body    = "Pełnomocnik: {$p['pelnomocnik']} · ważne do {$data_pl}";
    $edit_url = $peln_url . '?edit=' . $id . '#form-peln';

    // Odbiorcy powiadomień in-app: zakładający wpis + powiązany pełnomocnik.
    $recipients = [];
    if (!empty($p['created_by']))          $recipients[(int)$p['created_by']] = true;
    if (!empty($p['pelnomocnik_user_id'])) $recipients[(int)$p['pelnomocnik_user_id']] = true;

    try {
        foreach (array_keys($recipients) as $uid) {
            notif_create($uid, 'pelnomocnictwo', $title, $body, $edit_url);
        }

        // E-mail do zakładającego wpis (jeśli ma adres).
        if (!empty($p['created_by'])) {
            $u = db_one("SELECT name, email FROM users WHERE id=?", [(int)$p['created_by']]);
            if ($u && !empty($u['email'])) {
                $org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $name     = htmlspecialchars($u['name'] ?? '');
                $numer    = htmlspecialchars($p['numer']);
                $peln     = htmlspecialchars($p['pelnomocnik']);
                $color    = $days_left <= 7 ? '#dc3545' : '#fd7e14';
                $link     = htmlspecialchars($edit_url);
                $subject  = "⏳ Pełnomocnictwo {$p['numer']} wygasa {$when}";
                $html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:{$color};padding:20px 24px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.05rem">⏳ Wygasające pełnomocnictwo — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 10px 10px">
  <p>Cześć<strong>{$name}</strong>,</p>
  <p>Pełnomocnictwo <strong>{$numer}</strong> (pełnomocnik: <strong>{$peln}</strong>)
     wygasa <strong>{$when}</strong>, tj. <strong>{$data_pl}</strong>.</p>
  <div style="margin:22px 0;text-align:center">
    <a href="{$link}" style="background:{$color};color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz wpis w rejestrze →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Rejestr pełnomocnictw — {$org}
  </p>
</div>
</body></html>
HTML;
                mail_queue_add($u['email'], $u['name'] ?? '', $subject, $html);
            }
        }

        pelnomocnictwo_log($id, 'reminder', "Wysłano przypomnienie: wygasa {$when} ({$data_pl}).", null);
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$key, $today]);

        echo "[" . date('Y-m-d H:i:s') . "] {$p['numer']} ({$p['pelnomocnik']}) — przypomnienie {$days_left} dni\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD {$p['numer']}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

// Zamknij koszulki EZD pełnomocnictw, które wygasły lub zostały odwołane.
try {
    $closed = pelnomocnictwa_ezd_close_expired();
    if ($closed) echo "[" . date('Y-m-d H:i:s') . "] Zamknięto {$closed} koszulek EZD (wygasłe/odwołane).\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD zamykania koszulek: " . $e->getMessage() . "\n";
}

try {
    $r = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka pocztowa: wysłano={$r['sent']}, błędy={$r['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD kolejki pocztowej: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano: {$sent}, pominięto: {$skip}, błędy: {$errs}\n";
