<?php
/**
 * Skrypt cron: Przypomnienia o rejestracji i wyrejestrowaniu z ZUS (umowy zlecenie).
 *
 * Uruchamiany raz dziennie przez cron/dispatcher.php (agent 'zus_reminder', okno 8–10).
 * Bezpośrednio:  php /var/www/umowy/cron/zus_reminder.php
 *
 * Dla zleceń podlegających ZUS (zus_skladki = 1):
 *   • Rejestracja (ZUA/ZZA) — termin: 7 dni od daty rozpoczęcia (lub zawarcia).
 *   • Wyrejestrowanie (ZWUA) — termin: 7 dni od daty zakończenia (umowy terminowe).
 *
 * Przypomnienia przy pozostałych dniach do terminu: 7, 3, 1, 0 oraz dzień po (−1).
 * Wysyła e-mail (kolejka pocztowa) + powiadomienie w aplikacji do OPIEKUNA umowy
 * (guardian_editor_id); w razie braku opiekuna — do administratorów.
 *
 * Wyciszenie: wpisanie daty w zus_data_rejestracji / zus_data_wyrejestrowania
 * kończy przypomnienia danego typu. Duplikaty progów odnotowywane w tabeli settings.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/notifications.php';
require_once $base_dir . '/includes/email_templates.php';

$today      = date('Y-m-d');
$thresholds = [7, 3, 1, 0, -1];   // dni do upływu terminu (−1 = dzień po terminie)
$DEADLINE_DAYS = 7;               // ustawowy termin: 7 dni od zdarzenia

// Administratorzy — fallback gdy umowa nie ma opiekuna
$ADMINS = db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1");

$sent = 0; $skip = 0; $errs = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: zus_reminder\n";

$contracts = db_all(
    "SELECT id, numer_umowy, imie_nazwisko, status, bezterminowa,
            data_zawarcia, data_rozpoczecia, data_zakonczenia,
            zus_data_rejestracji, zus_data_wyrejestrowania,
            opiekun, guardian_editor_id
     FROM umowy_zlecenie
     WHERE zus_skladki = 1
       AND status <> 'anulowana'"
);

foreach ($contracts as $c) {
    // Dwa typy przypomnień: rejestracja + wyrejestrowanie
    $jobs = [];

    // 1) Rejestracja — termin liczony od rozpoczęcia (fallback: zawarcie)
    $reg_anchor = $c['data_rozpoczecia'] ?: $c['data_zawarcia'];
    if ($reg_anchor && empty($c['zus_data_rejestracji'])) {
        $jobs[] = [
            'kind'     => 'reg',
            'deadline' => date('Y-m-d', strtotime($reg_anchor . " +{$DEADLINE_DAYS} days")),
            'tytul'    => 'Rejestracja w ZUS (ZUA/ZZA)',
            'akcja'    => 'zgłosić do ZUS',
        ];
    }

    // 2) Wyrejestrowanie — termin od zakończenia (tylko umowy terminowe)
    if (empty($c['bezterminowa']) && !empty($c['data_zakonczenia']) && empty($c['zus_data_wyrejestrowania'])) {
        $jobs[] = [
            'kind'     => 'dereg',
            'deadline' => date('Y-m-d', strtotime($c['data_zakonczenia'] . " +{$DEADLINE_DAYS} days")),
            'tytul'    => 'Wyrejestrowanie z ZUS (ZWUA)',
            'akcja'    => 'wyrejestrować z ZUS',
        ];
    }

    foreach ($jobs as $job) {
        $dleft = (int) round((strtotime($job['deadline']) - strtotime($today)) / 86400);
        if (!in_array($dleft, $thresholds, true)) continue;

        $cid    = (int) $c['id'];
        $numer  = $c['numer_umowy'] ?: "#{$cid}";
        $osoba  = $c['imie_nazwisko'] ?? '';

        // Deduplikacja progu
        $skey = "zus_{$job['kind']}_reminder_sent_{$cid}_{$dleft}";
        if (db_one("SELECT value FROM settings WHERE key_=?", [$skey])) { $skip++; continue; }

        // Odbiorcy: opiekun (guardian_editor_id) → fallback administratorzy
        $emails = []; $uids = [];
        if (!empty($c['guardian_editor_id'])) {
            $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [(int)$c['guardian_editor_id']]);
            if ($u) {
                $uids[] = (int)$u['id'];
                if (!empty($u['email'])) $emails[] = [$u['email'], $u['name'] ?? ''];
            }
        }
        if (!$uids) { // brak opiekuna — administratorzy
            foreach ($ADMINS as $a) {
                $uids[] = (int)$a['id'];
                if (!empty($a['email'])) $emails[] = [$a['email'], $a['name'] ?? ''];
            }
        }

        $deadline_pl = date('d.m.Y', strtotime($job['deadline']));
        $url         = (defined('APP_URL') ? APP_URL : '') . '/contracts/zlecenie/view.php?id=' . $cid;

        // Treść przypomnienia
        if ($dleft < 0) {
            $pilnosc = "Termin minął " . abs($dleft) . " " . _zus_dni(abs($dleft)) . " temu!";
            $accent  = '#dc3545';
        } elseif ($dleft === 0) {
            $pilnosc = "Termin upływa <strong>dziś</strong>!";
            $accent  = '#dc3545';
        } elseif ($dleft <= 3) {
            $pilnosc = "Pozostało <strong>{$dleft} " . _zus_dni($dleft) . "</strong>.";
            $accent  = '#fd7e14';
        } else {
            $pilnosc = "Pozostało <strong>{$dleft} " . _zus_dni($dleft) . "</strong>.";
            $accent  = '#0d6efd';
        }

        $notif_body = "Należy {$job['akcja']} osobę {$osoba}. Termin: {$deadline_pl} (" .
                      ($dleft < 0 ? "po terminie" : "pozostało {$dleft} " . _zus_dni(max($dleft,0))) . ").";
        $rendered   = email_tpl_render('zus_reminder', [
            'accent'   => $accent,
            'tytul'    => $job['tytul'],
            'akcja'    => htmlspecialchars($job['akcja']),
            'numer'    => htmlspecialchars($numer),
            'osoba'    => htmlspecialchars($osoba),
            'deadline' => $deadline_pl,
            'pilnosc'  => $pilnosc,
            'url'      => htmlspecialchars($url),
        ]);
        $subject    = $rendered['subject'];
        $html_body  = $rendered['html'];

        try {
            if ($rendered['enabled']) { // szablon e-mail może być wyłączony przez admina
                foreach ($emails as [$to, $name]) {
                    mail_queue_add($to, $name, $subject, $html_body, '', 'zus_zlecenie', $cid);
                }
            }
            foreach (array_unique($uids) as $uid) {
                notif_create($uid, 'contract', "ZUS: {$job['tytul']} — {$numer}", $notif_body, $url);
            }
            db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
                ->execute([$skey, $today]);

            echo "[" . date('Y-m-d H:i:s') . "] zlecenie #{$numer} ({$osoba}) — {$job['kind']}, termin za {$dleft} dni — wysłano (" . count($emails) . " e-mail, " . count(array_unique($uids)) . " powiad.)\n";
            $sent++;
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] BLAD zlecenie #{$numer} ({$job['kind']}): " . $e->getMessage() . "\n";
            $errs++;
        }
    }
}

// Przetwórz kolejkę pocztową
try {
    $r = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka pocztowa: wysłano={$r['sent']}, błędy={$r['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD kolejki pocztowej: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano: {$sent}, pominięto: {$skip}, błędy: {$errs}\n";

// ── Pomocnicze ─────────────────────────────────────────────────────────────────

function _zus_dni(int $n): string
{
    if ($n === 1) return 'dzień';
    return 'dni';
}

