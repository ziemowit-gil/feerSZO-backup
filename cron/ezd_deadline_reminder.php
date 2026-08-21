<?php
/**
 * Skrypt cron: Przypomnienia o terminach w EZD Wirtualne biurko.
 * Uruchamiaj raz dziennie, np. o 7:30:
 *   30 7 * * * php /var/www/html/cron/ezd_deadline_reminder.php
 *
 * Wysyła e-mail (przez kolejkę M365/Graph) gdy:
 *  – dekretacja oczekująca ma termin jutro / dziś / jest przeterminowana → do wykonawcy,
 *  – otwarta sprawa ma termin jutro / dziś / jest przeterminowana       → do właściciela,
 *  – termin liczony od doręczenia przesyłki wychodzącej (RPW-W) wypada
 *    jutro / dziś / minął                                              → do referenta,
 *  – przesyłka nadana za potwierdzeniem odbioru nie ma potwierdzenia
 *    po 21 dniach (brakujące ZPO → reklamacja)                         → do rejestrującego.
 *
 * Każdy kamień milowy (due_1day / due_today / overdue) wysyłany jest tylko raz
 * (dedup w tabeli ezd_reminder_log).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/ezd.php';
require_once $base_dir . '/includes/org.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/notification_service.php';

// Respektuj ustawienia modułu
if (!module_enabled('ezd_enabled')) {
    echo "[" . date('Y-m-d H:i:s') . "] EZD wyłączony — pomijam.\n"; exit;
}
if (org_setting('ezd_reminders_enabled') === '0') {
    echo "[" . date('Y-m-d H:i:s') . "] Powiadomienia EZD wyłączone w ustawieniach — pomijam.\n"; exit;
}

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

/** Zmapuj deadline → rodzaj przypomnienia (lub null gdy poza oknem). */
function ezd_deadline_kind(string $deadline, string $today, string $tomorrow): ?array {
    if ($deadline < $today)      return ['overdue',   'Przekroczony termin'];
    if ($deadline === $today)    return ['due_today', 'Termin dzisiaj'];
    if ($deadline === $tomorrow) return ['due_1day',  'Termin jutro'];
    return null;
}

/** Wyślij przypomnienie do użytkownika (kolejka mailowa z rozwiązaniem zastępstwa). */
function ezd_send_reminder(int $user_id, string $subject, string $body, string $url, string $ctx_type, int $ctx_id): bool {
    if (!$user_id) return false;
    if (class_exists('NotificationService') && function_exists('org_resolve_email')) {
        NotificationService::toUser($user_id, $subject, 'ezd_reminder', ['body' => $body, 'url' => $url], $ctx_type, $ctx_id);
        return true;
    }
    // Fallback bez modułu org
    $u = db_one("SELECT name,email FROM users WHERE id=?", [$user_id]);
    if (!$u || !$u['email']) return false;
    $html = '<p>Cześć ' . htmlspecialchars($u['name']) . ',</p><p>' . htmlspecialchars($body) . '</p>'
          . '<p><a href="' . htmlspecialchars($url) . '">Przejdź do systemu</a></p>';
    mail_queue_add($u['email'], $u['name'], $subject, $html, '', $ctx_type, $ctx_id);
    return true;
}

$sent = 0; $errs = 0; $scan = 0;
echo "[" . date('Y-m-d H:i:s') . "] Start: ezd_deadline_reminder\n";

// ── Dekretacje oczekujące z terminem ──────────────────────────────────────────
$dekr = db_all(
    "SELECT d.id, d.wykonawca_id, d.deadline, d.dyspozycja,
            s.id AS sprawa_id, s.znak_sprawy, s.title AS sprawa_title
     FROM ezd_dekretacje d
     JOIN ezd_sprawy s ON s.id = d.sprawa_id
     WHERE d.status='oczekuje' AND d.deadline IS NOT NULL AND d.wykonawca_id IS NOT NULL
       AND s.status != 'closed'
       AND d.deadline <= ?",
    [$tomorrow]
);
foreach ($dekr as $d) {
    $scan++;
    $k = ezd_deadline_kind($d['deadline'], $today, $tomorrow);
    if (!$k) continue;
    [$kind, $prefix] = $k;
    if (ezd_reminder_sent('dekretacja', (int)$d['id'], $kind)) continue;
    $dysp = EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja'];
    $subject = "[EZD] $prefix — dekretacja: {$d['znak_sprawy']}";
    $body    = "$prefix realizacji dekretacji „$dysp" . '" w sprawie ' . $d['znak_sprawy']
             . ' („' . mb_substr($d['sprawa_title'], 0, 80) . '"). Termin: ' . date_pl($d['deadline']) . '.';
    $url     = APP_URL . '/ezd/sprawy/view.php?id=' . $d['sprawa_id'] . '#dekretacje';
    try {
        if (ezd_send_reminder((int)$d['wykonawca_id'], $subject, $body, $url, 'ezd_reminder', (int)$d['id'])) {
            ezd_reminder_mark('dekretacja', (int)$d['id'], $kind);
            echo "  ✓ dekretacja #{$d['id']} [$kind] → user #{$d['wykonawca_id']} ({$d['znak_sprawy']})\n";
            $sent++;
        }
    } catch (\Throwable $e) {
        echo "  ✗ dekretacja #{$d['id']}: " . $e->getMessage() . "\n"; $errs++;
    }
}

// ── Otwarte sprawy z terminem ─────────────────────────────────────────────────
$spr = db_all(
    "SELECT id, owner_id, deadline, znak_sprawy, title
     FROM ezd_sprawy
     WHERE status IN ('open','in_progress') AND deadline IS NOT NULL AND owner_id IS NOT NULL
       AND COALESCE(ciagla,0)=0
       AND deadline <= ?",
    [$tomorrow]
);
foreach ($spr as $s) {
    $scan++;
    $k = ezd_deadline_kind($s['deadline'], $today, $tomorrow);
    if (!$k) continue;
    [$kind, $prefix] = $k;
    if (ezd_reminder_sent('sprawa', (int)$s['id'], $kind)) continue;
    $subject = "[EZD] $prefix — sprawa: {$s['znak_sprawy']}";
    $body    = "$prefix załatwienia sprawy {$s['znak_sprawy']} („" . mb_substr($s['title'], 0, 80)
             . '"). Termin: ' . date_pl($s['deadline']) . '.';
    $url     = APP_URL . '/ezd/sprawy/view.php?id=' . $s['id'];
    try {
        if (ezd_send_reminder((int)$s['owner_id'], $subject, $body, $url, 'ezd_reminder', (int)$s['id'])) {
            ezd_reminder_mark('sprawa', (int)$s['id'], $kind);
            echo "  ✓ sprawa #{$s['id']} [$kind] → user #{$s['owner_id']} ({$s['znak_sprawy']})\n";
            $sent++;
        }
    } catch (\Throwable $e) {
        echo "  ✗ sprawa #{$s['id']}: " . $e->getMessage() . "\n"; $errs++;
    }
}

// ── Terminy liczone od doręczenia przesyłek wychodzących (RPW-W) ──────────────
foreach (ezd_rpwy_terminy_do($tomorrow) as $w) {
    $scan++;
    $k = ezd_deadline_kind($w['termin_do'], $today, $tomorrow);
    if (!$k) continue;
    [$kind, $prefix] = $k;
    if (ezd_reminder_sent('rpwy_termin', (int)$w['id'], $kind)) continue;

    $adresat = (int)($w['pismo_owner_id'] ?: $w['sprawa_owner_id'] ?: $w['created_by']);
    if (!$adresat) continue;

    $tryb  = ($w['doreczenie_typ'] ?? '') === 'fikcja' ? ' (doręczenie w trybie fikcji)' : '';
    $czego = $w['pismo_sygnatura'] ? 'pisma ' . $w['pismo_sygnatura'] : ezd_rpwy_label($w);

    $subject = "[EZD] $prefix — $czego (termin od doręczenia)";
    $body    = "$prefix wynikający z doręczenia $czego. Przesyłka " . ezd_rpwy_label($w)
             . ' do „' . mb_substr($w['odbiorca'], 0, 60) . '" została doręczona '
             . date_pl($w['data_doreczenia']) . $tryb . '; termin ' . (int)$w['termin_dni']
             . ' dni upływa ' . date_pl($w['termin_do']) . '.';
    $url     = APP_URL . '/ezd/rpwy/view.php?id=' . (int)$w['id'];
    try {
        if (ezd_send_reminder($adresat, $subject, $body, $url, 'ezd_reminder', (int)$w['id'])) {
            ezd_reminder_mark('rpwy_termin', (int)$w['id'], $kind);
            echo "  ✓ RPW-W #{$w['id']} [$kind] → user #$adresat (" . ezd_rpwy_label($w) . ")\n";
            $sent++;
        }
    } catch (\Throwable $e) {
        echo "  ✗ RPW-W #{$w['id']}: " . $e->getMessage() . "\n"; $errs++;
    }
}

// ── Brakujące potwierdzenia odbioru (nadane, bez ZPO po 21 dniach) ────────────
foreach (ezd_rpwy_bez_zpo(21) as $w) {
    $scan++;
    if (ezd_reminder_sent('rpwy_zpo', (int)$w['id'], 'missing_zpo')) continue;
    $adresat = (int)$w['created_by'];
    if (!$adresat) continue;

    $dni     = (int)floor((strtotime($today) - strtotime($w['data_wysylki'])) / 86400);
    $sposob  = EZD_RPWY_SPOSOBY[$w['sposob']]['label'] ?? $w['sposob'];
    $subject = '[EZD] Brak potwierdzenia odbioru — ' . ezd_rpwy_label($w);
    $body    = 'Przesyłka ' . ezd_rpwy_label($w) . ' (' . $sposob . ') do „'
             . mb_substr($w['odbiorca'], 0, 60) . '" została nadana ' . date_pl($w['data_wysylki'])
             . " ($dni dni temu), a w rejestrze nadal nie ma potwierdzenia odbioru."
             . ' Sprawdź status przesyłki — jeśli nie została podjęta, przyjmij doręczenie w trybie fikcji doręczenia.';
    $url     = APP_URL . '/ezd/rpwy/view.php?id=' . (int)$w['id'];
    try {
        if (ezd_send_reminder($adresat, $subject, $body, $url, 'ezd_reminder', (int)$w['id'])) {
            ezd_reminder_mark('rpwy_zpo', (int)$w['id'], 'missing_zpo');
            echo "  ✓ brak ZPO #{$w['id']} → user #$adresat (" . ezd_rpwy_label($w) . ")\n";
            $sent++;
        }
    } catch (\Throwable $e) {
        echo "  ✗ brak ZPO #{$w['id']}: " . $e->getMessage() . "\n"; $errs++;
    }
}

// ── Sprzątanie starych wpisów dedup (> 180 dni) ───────────────────────────────
try { db()->exec("DELETE FROM ezd_reminder_log WHERE sent_at < datetime('now','-180 days')"); } catch (\Throwable $e) {}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Przeskanowano: $scan, wysłano: $sent, błędy: $errs\n";
