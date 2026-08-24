<?php
/**
 * cron/crm_email_kinds.php — rozpoznawanie adresów: imienny czy ogólny.
 *
 * Rodzaj adresu decyduje o zwrocie w wysyłce ({zwrot} w szablonach): „Dzień dobry,
 * Anno" do a.kowalska@, „Szanowni Państwo" do biuro@. Rozpoznanie da się zrobić
 * z samego adresu w większości przypadków — reszta idzie do modelu.
 *
 * Robimy to W TLE, a nie przy wysyłce: pytanie modelu w trakcie kampanii
 * opóźniałoby każdą wiadomość, a przy kilkuset odbiorcach potrafiłoby ją zablokować.
 *
 * Użycie: php cron/crm_email_kinds.php [--limit=200] [--no-ai] [--recheck]
 *   --recheck  liczy od nowa także adresy już rozpoznane (po zmianie reguł)
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_email_kind.php';

$opts    = getopt('', ['limit::', 'no-ai', 'recheck']);
$limit   = max(1, min(2000, (int)($opts['limit'] ?? 200)));
$use_ai  = !isset($opts['no-ai']);
$recheck = isset($opts['recheck']);

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_email_kinds\n";

if (org_setting('crm_enabled') !== '1') { echo "  Moduł CRM wyłączony — koniec.\n"; exit(0); }

crm_email_kind_migrate();

$where = "crm_active=1 AND email IS NOT NULL AND email <> ''" . ($recheck ? '' : " AND (email_kind IS NULL OR email_kind='' OR email_kind='unknown')");
$rows  = db_all("SELECT id, imie_nazwisko, email FROM crm_contacts WHERE $where ORDER BY id LIMIT $limit");

if (!$rows) { echo "  Nie ma czego rozpoznawać.\n"; exit(0); }

$stat = ['personal' => 0, 'generic' => 0, 'unknown' => 0, 'ai' => 0];
$ask  = [];

// ── 1. Wzorce ──────────────────────────────────────────────────────────────
foreach ($rows as $c) {
    $r = crm_email_kind_local((string)$c['email'], $c['imie_nazwisko']);
    if ($r['sure']) {
        crm_email_kind_save((int)$c['id'], $r['kind'], $r['why']);
        $stat[$r['kind']]++;
    } else {
        $ask[] = ['id' => (int)$c['id'], 'email' => (string)$c['email'], 'name' => (string)$c['imie_nazwisko']];
    }
}
echo '  Wzorce: imiennych ' . $stat['personal'] . ', ogólnych ' . $stat['generic']
   . ', do rozstrzygnięcia ' . count($ask) . "\n";

// ── 2. Model — paczkami, żeby nie mnożyć zapytań ───────────────────────────
if ($ask && $use_ai && crm_email_kind_ai_available()) {
    foreach (array_chunk($ask, 40) as $chunk) {
        $map = crm_email_kind_ai(array_map(
            static fn($a) => ['email' => $a['email'], 'name' => $a['name']], $chunk));
        foreach ($chunk as $a) {
            $r = $map[mb_strtolower($a['email'])] ?? null;
            if (!$r) continue;
            crm_email_kind_save($a['id'], $r['kind'], $r['why']);
            $stat[$r['kind']]++;
            $stat['ai']++;
        }
        echo '  Model: paczka ' . count($chunk) . ", rozpoznanych łącznie " . $stat['ai'] . "\n";
    }
} elseif ($ask && $use_ai) {
    echo "  Model niedostępny (brak klucza Anthropic) — te adresy zostają nierozpoznane.\n";
}

// Nierozpoznane zapisujemy też — inaczej każdy przebieg pytałby o nie od nowa
foreach ($ask as $a) {
    $cur = db_one("SELECT email_kind FROM crm_contacts WHERE id=?", [$a['id']])['email_kind'] ?? '';
    if ($cur === '' || $cur === null) {
        crm_email_kind_save($a['id'], 'unknown', 'wzorce nie rozstrzygają');
        $stat['unknown']++;
    }
}

echo '  Razem: imiennych ' . $stat['personal'] . ', ogólnych ' . $stat['generic']
   . ', nierozpoznanych ' . $stat['unknown'] . ' (z modelu: ' . $stat['ai'] . ")\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_email_kinds\n";
