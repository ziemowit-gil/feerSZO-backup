<?php
/**
 * cron/crm_consents_expiring.php — przypomnienia o wygasających zgodach CRM.
 *
 * Cel zgody może mieć określoną ważność (crm_consent_purposes.valid_months).
 * Po tym czasie zgoda przestaje uprawniać do wysyłki — filtruje ją już
 * crm_consent_granted_ids(). Sam fakt wygaśnięcia nikogo jednak nie informuje,
 * więc baza po cichu chudnie: kampania trafia do coraz mniejszej grupy i nikt
 * nie wie dlaczego.
 *
 * Ten agent raz w tygodniu wysyła zestawienie: co już wygasło i co wygaśnie
 * w ciągu najbliższych 30 dni, pogrupowane po opiekunach kartotek. Opiekun
 * dostaje swoją listę, a zestawienie zbiorcze idzie na adres z ustawienia
 * `crm_consent_notify_email` (gdy puste — do administratorów systemu).
 *
 * Nie wysyłamy nic do samych kontaktów: prośba o odnowienie zgody
 * marketingowej sama jest wysyłką marketingową i wymagałaby zgody, której
 * właśnie brakuje. Decyzję, jak odnowić, podejmuje człowiek.
 *
 * Użycie: php cron/crm_consents_expiring.php [--days=30] [--dry]
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_consent.php';
require_once $base_dir . '/includes/mail_queue.php';

$opts  = getopt('', ['days::', 'dry']);
$days  = max(1, min(365, (int)($opts['days'] ?? 30)));
$dry   = isset($opts['dry']);

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_consents_expiring (okno {$days} dni"
   . ($dry ? ', tryb próbny' : '') . ")\n";

if (org_setting('crm_enabled') !== '1') { echo "  Moduł CRM wyłączony — koniec.\n"; exit(0); }

$items = crm_consent_expiring($days);
if (!$items) { echo "  Brak zgód do odnowienia.\n"; exit(0); }

// ── Dociągnięcie danych kontaktów jednym zapytaniem ─────────────────────────
$ids = array_values(array_unique(array_map(static fn($i) => $i['contact_id'], $items)));
$in  = implode(',', array_map('intval', $ids));
$contacts = [];
foreach (db_all("SELECT id, imie_nazwisko, email, organizacja, owner_id FROM crm_contacts
                  WHERE id IN ($in) AND crm_active=1") as $c) {
    $contacts[(int)$c['id']] = $c;
}

// ── Podział na opiekunów ────────────────────────────────────────────────────
$by_owner = [];     // owner_id (0 = bez opiekuna) => wiersze
foreach ($items as $it) {
    $c = $contacts[$it['contact_id']] ?? null;
    if (!$c) continue;                       // kartoteka wygaszona — nie ma po co przypominać
    $by_owner[(int)($c['owner_id'] ?? 0)][] = $it + ['contact' => $c];
}
if (!$by_owner) { echo "  Wszystkie kartoteki wygaszone — koniec.\n"; exit(0); }

/** Jedna pozycja listy w treści e-maila. */
$line = static function (array $r): string {
    $c   = $r['contact'];
    $who = trim((string)$c['imie_nazwisko']);
    if (!empty($c['organizacja']) && $c['organizacja'] !== $who) $who .= ' (' . $c['organizacja'] . ')';
    return sprintf(
        '  %s %s — cel: %s — %s %s%s',
        $r['expired'] ? '[WYGASŁA]' : '[wygasa]',
        $who,
        $r['purpose'],
        $r['expired'] ? 'termin minął' : 'termin',
        date('d.m.Y', strtotime((string)$r['expires_at'])),
        $c['email'] ? ' — ' . $c['email'] : ''
    );
};

$body_for = static function (array $rows, string $intro) use ($line, $days): string {
    $expired = array_values(array_filter($rows, static fn($r) => $r['expired']));
    $soon    = array_values(array_filter($rows, static fn($r) => !$r['expired']));
    $t  = $intro . "\n\n";
    if ($expired) {
        $t .= "ZGODY, KTÓRE JUŻ WYGASŁY (" . count($expired) . ") — te kartoteki są już pomijane w wysyłkach:\n";
        foreach ($expired as $r) $t .= $line($r) . "\n";
        $t .= "\n";
    }
    if ($soon) {
        $t .= "ZGODY WYGASAJĄCE W CIĄGU {$days} DNI (" . count($soon) . "):\n";
        foreach ($soon as $r) $t .= $line($r) . "\n";
        $t .= "\n";
    }
    $t .= "Zgodę odnawia się, zapisując nowe zdarzenie w kartotece kontaktu\n"
        . "(zakładka Zgody). Sposób odnowienia — rozmowa, formularz, papier —\n"
        . "wybiera opiekun; system nie wysyła próśb do kontaktów samodzielnie,\n"
        . "bo prośba o zgodę marketingową sama byłaby wysyłką marketingową.\n\n"
        . APP_URL . "/crm/settings/consents.php\n";
    return $t;
};

$queued = 0;

// ── 1. Listy imienne do opiekunów ───────────────────────────────────────────
foreach ($by_owner as $owner_id => $rows) {
    if ($owner_id <= 0) continue;
    try {
        $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$owner_id]);
    } catch (\Throwable $e) { $u = null; }
    if (!$u || !filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL)) continue;

    $subject = 'CRM: zgody do odnowienia (' . count($rows) . ')';
    $text    = $body_for($rows, 'Kartoteki, którymi się opiekujesz, mają zgody wymagające odnowienia.');
    echo '  → ' . $u['email'] . ': ' . count($rows) . " poz.\n";
    if ($dry) continue;
    try {
        mail_queue_add($u['email'], (string)$u['name'], $subject, '', $text, 'crm_consent_expiry', (int)$u['id'], '', false);
        $queued++;
    } catch (\Throwable $e) { echo '  ✗ kolejka: ' . $e->getMessage() . "\n"; }
}

// ── 2. Zestawienie zbiorcze (z pozycjami bez opiekuna) ──────────────────────
$all = [];
foreach ($by_owner as $rows) foreach ($rows as $r) $all[] = $r;

$targets = [];
$cfg = trim((string)org_setting('crm_consent_notify_email'));
if ($cfg !== '') {
    foreach (preg_split('/[,;\s]+/', $cfg) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $targets[] = ['email' => $e, 'name' => ''];
    }
}
if (!$targets) {
    try {
        foreach (db_all("SELECT name, email FROM users WHERE role='admin' AND is_active=1") as $a) {
            if (filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL)) $targets[] = $a;
        }
    } catch (\Throwable $e) {}
}

$orphans = count($by_owner[0] ?? []);
$intro   = 'Zestawienie zbiorcze zgód do odnowienia w CRM.'
         . ($orphans ? " Bez przypisanego opiekuna: {$orphans} — nikt nie dostał ich na swoją listę." : '');

foreach ($targets as $t) {
    echo '  → (zbiorcze) ' . $t['email'] . ': ' . count($all) . " poz.\n";
    if ($dry) continue;
    try {
        mail_queue_add($t['email'], (string)($t['name'] ?? ''), 'CRM: zgody do odnowienia — zestawienie ('
            . count($all) . ')', '', $body_for($all, $intro), 'crm_consent_expiry', 0, '', false);
        $queued++;
    } catch (\Throwable $e) { echo '  ✗ kolejka: ' . $e->getMessage() . "\n"; }
}

echo '  Pozycji: ' . count($all) . ', wiadomości w kolejce: ' . $queued
   . ($dry ? ' (tryb próbny — nic nie wysłano)' : '') . "\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_consents_expiring\n";
