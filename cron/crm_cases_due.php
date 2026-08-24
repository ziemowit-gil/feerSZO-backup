<?php
/**
 * cron/crm_cases_due.php — przypomnienia o terminach spraw CRM i naruszeniach SLA.
 *
 * Sprawa bez pilnowania terminu wisi w nieskończoność — do tej pory jedynym
 * sygnałem był baner „sprawa bez ruchu". Teraz:
 *   • na 2 dni przed terminem (due_date) prowadzący dostaje e-mail,
 *   • po przekroczeniu SLA typu (pierwsza odpowiedź albo zamknięcie) — również.
 *
 * Każde przypomnienie idzie RAZ: znaczniki due_notified_at i sla_breach_notified_at
 * pilnują, żeby cron nie zasypywał skrzynki tą samą sprawą codziennie.
 *
 * Uruchamiany przez cron/dispatcher.php raz dziennie rano.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_case_extras.php';
require_once $base_dir . '/includes/mail_queue.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_cases_due\n";

const CRM_CASE_DUE_LEAD_DAYS = 2;   // ile dni przed terminem uprzedzamy

$now  = date('Y-m-d H:i:s');
$sent = ['due' => 0, 'sla' => 0, 'skip' => 0];

/** Adresat przypomnienia: prowadzący, a gdy go nie ma — autor sprawy. */
$recipient = static function (array $case): ?array {
    foreach ([(int)($case['owner_id'] ?? 0), (int)($case['created_by'] ?? 0)] as $uid) {
        if ($uid <= 0) continue;
        try {
            $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$uid]);
        } catch (\Throwable $e) { $u = null; }
        if ($u && filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL)) return $u;
    }
    return null;
};

$send = static function (array $to, string $subject, string $text, int $case_id): bool {
    try {
        mail_queue_add($to['email'], (string)$to['name'], $subject, '', $text, 'crm_case_due', $case_id, '', false);
        return true;
    } catch (\Throwable $e) {
        echo '  ✗ kolejka: ' . $e->getMessage() . "\n";
        return false;
    }
};

// ── 1. Zbliżający się termin sprawy ─────────────────────────────────────────
try {
    $rows = db_all(
        "SELECT c.*, ct.imie_nazwisko AS contact_name
           FROM crm_cases c
      LEFT JOIN crm_contacts ct ON ct.id = c.contact_id
          WHERE c.status IN ('open','in_progress')
            AND c.due_date IS NOT NULL AND c.due_date <> ''
            AND date(c.due_date) <= date('now', '+" . CRM_CASE_DUE_LEAD_DAYS . " days')
            AND c.due_notified_at IS NULL
       ORDER BY c.due_date LIMIT 500"
    );
} catch (\Throwable $e) {
    echo '  ✗ zapytanie o terminy: ' . $e->getMessage() . "\n";
    $rows = [];
}

foreach ($rows as $c) {
    $to = $recipient($c);
    if (!$to) { $sent['skip']++; continue; }

    $days = (int)floor((strtotime((string)$c['due_date']) - time()) / 86400);
    $when = $days < 0 ? 'termin minął ' . abs($days) . ' dni temu'
          : ($days === 0 ? 'termin mija dziś' : 'termin mija za ' . $days . ' dni');

    $text = "Sprawa CRM: " . (string)$c['title'] . "\n"
          . 'Klient: ' . (string)($c['contact_name'] ?? '—') . "\n"
          . 'Termin: ' . date('d.m.Y', strtotime((string)$c['due_date'])) . " ({$when}).\n\n"
          . APP_URL . '/crm/cases/view.php?id=' . (int)$c['id'] . "\n\n"
          . "Jeśli termin jest nieaktualny, zmień go albo zamknij sprawę — wtedy przypomnienie nie wróci.";

    if ($send($to, 'Termin sprawy: ' . mb_strimwidth((string)$c['title'], 0, 60, '…'), $text, (int)$c['id'])) {
        try {
            db()->prepare("UPDATE crm_cases SET due_notified_at=? WHERE id=?")->execute([$now, (int)$c['id']]);
        } catch (\Throwable $e) {}
        $sent['due']++;
        echo '  ✓ termin #' . (int)$c['id'] . ' → ' . $to['email'] . "\n";
    }
}

// ── 2. Przekroczone SLA typu sprawy ─────────────────────────────────────────
try {
    $open = db_all(
        "SELECT c.*, ct.imie_nazwisko AS contact_name
           FROM crm_cases c
      LEFT JOIN crm_contacts ct ON ct.id = c.contact_id
          WHERE c.status IN ('open','in_progress')
            AND c.type_id IS NOT NULL
            AND c.sla_breach_notified_at IS NULL
       ORDER BY c.created_at LIMIT 500"
    );
} catch (\Throwable $e) { $open = []; }

foreach ($open as $c) {
    $sla = crm_case_sla($c);
    if (!$sla['has']) continue;

    $breaches = [];
    if (!empty($sla['response']) && $sla['response']['state'] === 'breach' && empty($c['first_response_at'])) {
        $breaches[] = 'brak pierwszej odpowiedzi w terminie';
    }
    if (!empty($sla['close']) && $sla['close']['state'] === 'breach') {
        $breaches[] = 'sprawa niezamknięta po terminie';
    }
    if (!$breaches) continue;

    $to = $recipient($c);
    if (!$to) { $sent['skip']++; continue; }

    $text = "Przekroczone SLA sprawy CRM.\n\n"
          . 'Sprawa: ' . (string)$c['title'] . "\n"
          . 'Klient: ' . (string)($c['contact_name'] ?? '—') . "\n"
          . 'Wpłynęła: ' . date('d.m.Y H:i', strtotime((string)$c['created_at'])) . "\n"
          . 'Naruszenia: ' . implode('; ', $breaches) . ".\n\n"
          . APP_URL . '/crm/cases/view.php?id=' . (int)$c['id'];

    if ($send($to, 'SLA przekroczone: ' . mb_strimwidth((string)$c['title'], 0, 60, '…'), $text, (int)$c['id'])) {
        try {
            db()->prepare("UPDATE crm_cases SET sla_breach_notified_at=? WHERE id=?")->execute([$now, (int)$c['id']]);
        } catch (\Throwable $e) {}
        $sent['sla']++;
        echo '  ✓ SLA #' . (int)$c['id'] . ' → ' . $to['email'] . "\n";
    }
}

echo "  Podsumowanie: terminy {$sent['due']}, SLA {$sent['sla']}, pominięto (brak adresata) {$sent['skip']}\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_cases_due\n";
