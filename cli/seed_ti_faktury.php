<?php
/**
 * cli/seed_ti_faktury.php — dane testowe TI do wystawiania faktur.
 *
 * Tworzy komplet potrzebny, żeby przejść całą ścieżkę: kursant → grupa → zapis →
 * lekcje z obecnością → rozliczenie miesięczne → faktura (moduł Faktury).
 * Powstają dwie grupy o różnych modelach rozliczania, bo faktura wygląda inaczej
 * przy stawce godzinowej niż przy opłacie stałej.
 *
 * Rozliczenia liczy k30_ti_issue_billing() — ta sama funkcja co w panelu, więc
 * godziny i kwoty nie rozjadą się z tym, co system pokaże później sam.
 *
 * WSZYSTKIE adresy e-mail ustawiane są na jeden podany adres, żeby powiadomienia
 * z testów nie poszły do nikogo obcego.
 *
 * Użycie:
 *   php cli/seed_ti_faktury.php [--email=adres] [--month=MM] [--year=RRRR] [--clean]
 *
 *   --clean  usuwa dane z poprzedniego seeda (rozpoznawane po znaczniku w nazwach)
 *
 * Kody wyjścia: 0 = OK, 1 = błąd użycia, 2 = błąd krytyczny.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}

define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_payments.php';
require_once $base . '/includes/invoices.php';

/** Znacznik danych seeda — po nim rozpoznajemy, co usunąć przy --clean. */
const SEED_TAG = '[SEED-FV]';

// ── Argumenty ────────────────────────────────────────────────────────────────
$opts = getopt('', ['email::', 'month::', 'year::', 'clean']);
$email = trim((string)($opts['email'] ?? 'ziemowit.gil@feer.org.pl'));
$month = (int)($opts['month'] ?? date('n'));
$year  = (int)($opts['year']  ?? date('Y'));
$clean = array_key_exists('clean', $opts);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Nieprawidłowy adres e-mail: {$email}\n");
    exit(1);
}
if ($month < 1 || $month > 12) { fwrite(STDERR, "Miesiąc poza zakresem.\n"); exit(1); }

try { karty30_migrate(); ti_payments_migrate(); invoices_migrate(); }
catch (\Throwable $e) { fwrite(STDERR, 'Migracje: ' . $e->getMessage() . "\n"); exit(2); }

// ── Sprzątanie ───────────────────────────────────────────────────────────────
if ($clean) {
    $n = 0;
    foreach (db_all("SELECT id FROM k30_clients WHERE name LIKE ?", ['%' . SEED_TAG . '%']) as $c) {
        $cid = (int)$c['id'];
        // Faktury najpierw — trzymają source_id rozliczeń.
        foreach (db_all("SELECT id FROM k30_ti_billing WHERE client_id=?", [$cid]) as $b) {
            db()->prepare("DELETE FROM invoice_items WHERE invoice_id IN
                           (SELECT id FROM invoices WHERE source='ti_billing' AND source_id=?)")->execute([(int)$b['id']]);
            db()->prepare("DELETE FROM invoices WHERE source='ti_billing' AND source_id=?")->execute([(int)$b['id']]);
        }
        db()->prepare("DELETE FROM k30_clients WHERE id=?")->execute([$cid]);  // kaskady zrobią resztę
        $n++;
    }
    foreach (db_all("SELECT id FROM k30_ti_courses WHERE name LIKE ?", ['%' . SEED_TAG . '%']) as $g) {
        db()->prepare("DELETE FROM k30_ti_courses WHERE id=?")->execute([(int)$g['id']]);
        $n++;
    }
    echo "Usunięto rekordy seeda: {$n}.\n";
    exit(0);
}

$now  = date('Y-m-d H:i:s');
$from = sprintf('%04d-%02d-01', $year, $month);
$last = (int)date('t', strtotime($from));

echo "Seed TI → faktury\n";
echo "  okres: " . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "/{$year}\n";
echo "  e-mail (wszystkie kontakty): {$email}\n\n";

// ── Grupy: godzinowa i stała ─────────────────────────────────────────────────
// Dwa modele naraz, bo faktura i wykaz lekcji zachowują się przy nich inaczej.
$groups = [
    ['name' => 'Robotyka A ' . SEED_TAG, 'model' => 2, 'amount' => 0,   'rate' => 80.0],
    ['name' => 'Programowanie B ' . SEED_TAG, 'model' => 1, 'amount' => 400.0, 'rate' => 0.0],
];

$group_ids = [];
foreach ($groups as $g) {
    $ex = db_one("SELECT id FROM k30_ti_courses WHERE name=?", [$g['name']]);
    $gid = $ex ? (int)$ex['id'] : db_insert('k30_ti_courses', [
        'name'           => $g['name'],
        'description'    => 'Grupa testowa utworzona przez cli/seed_ti_faktury.php',
        'location'       => 'Sala 1',
        'duration_min'   => 90,
        'is_active'      => 1,
        'billing_model'  => $g['model'],
        'billing_amount' => $g['amount'],
        'created_at'     => $now,
    ]);
    $group_ids[] = ['id' => $gid] + $g;
    printf("  grupa #%d %-28s model=%s\n", $gid, $g['name'],
        $g['model'] === 1 ? 'stała ' . number_format($g['amount'], 2, ',', ' ') . ' zł/mies.'
                          : 'godzinowy ' . number_format($g['rate'], 2, ',', ' ') . ' zł/godz.');
}

// ── Kursanci ─────────────────────────────────────────────────────────────────
$students = [
    ['name' => 'Anna Testowa ' . SEED_TAG,  'payer' => '',                          'pay' => 0.0],
    ['name' => 'Bartek Testowy ' . SEED_TAG,'payer' => 'Firma Płatnik sp. z o.o.',  'pay' => 500.0],
];

echo "\n";
$made = [];
foreach ($students as $si => $st) {
    $ex  = db_one("SELECT id FROM k30_clients WHERE name=?", [$st['name']]);
    $cid = $ex ? (int)$ex['id'] : db_insert('k30_clients', [
        'name'       => $st['name'],
        'email'      => $email,
        'phone'      => '600100200',
        'status'     => 'enrolled',
        'address'    => 'ul. Testowa 1, 00-001 Warszawa',
        'consent'    => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Zapis do grup + lekcje z obecnością
    foreach ($group_ids as $gi => $g) {
        if ($si === 1 && $gi === 1) continue;   // drugi kursant tylko w jednej grupie

        if (!db_one("SELECT id FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$g['id'], $cid])) {
            db_insert('k30_ti_enrollments', [
                'course_id'   => $g['id'],
                'client_id'   => $cid,
                'hourly_rate' => $g['rate'],
                'start_date'  => $from,
                'status'      => 'active',
                'created_at'  => $now,
            ]);
        }

        // Cztery lekcje w miesiącu, 90 min każda → ceil(90/60) = 2 godz. na lekcję.
        foreach ([4, 11, 18, 25] as $k => $day) {
            if ($day > $last) continue;
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $ses = db_one("SELECT id FROM k30_ti_sessions WHERE course_id=? AND lesson_date=?", [$g['id'], $date]);
            $sid = $ses ? (int)$ses['id'] : db_insert('k30_ti_sessions', [
                'course_id'    => $g['id'],
                'lesson_date'  => $date,
                'time_from'    => '16:00',
                'time_to'      => '17:30',
                'duration_min' => 90,
                'status'       => $k === 3 ? 'remote_material' : 'held',   // ostatnia jako praca własna
                'notes'        => SEED_TAG,
                'created_at'   => $now,
            ]);
            if (!db_one("SELECT id FROM k30_ti_attendance WHERE session_id=? AND client_id=?", [$sid, $cid])) {
                db_insert('k30_ti_attendance', [
                    'session_id' => $sid,
                    'client_id'  => $cid,
                    'attended'   => 1,
                    'notes'      => SEED_TAG,
                ]);
            }
        }
    }

    // Płatnik (nabywca faktury) — drugi kursant ma firmę, pierwszy płaci sam.
    if ($st['payer'] !== '') {
        db()->prepare("UPDATE k30_ti_billing SET payer_type='other', payer_name=? WHERE client_id=?")
            ->execute([$st['payer'], $cid]);
    }

    // Rozliczenie per grupa — model kombinowany, tak jak w panelu.
    $bids = [];
    foreach ($group_ids as $gi => $g) {
        if ($si === 1 && $gi === 1) continue;
        $bid = k30_ti_issue_billing($cid, $month, $year, SEED_TAG . ' rozliczenie testowe', (int)$g['id']);
        if ($st['payer'] !== '') {
            db()->prepare("UPDATE k30_ti_billing SET payer_type='other', payer_name=? WHERE id=?")
                ->execute([$st['payer'], $bid]);
        }
        $b = db_one("SELECT hours_billed, amount FROM k30_ti_billing WHERE id=?", [$bid]);
        $bids[] = ['id' => $bid, 'group' => $g['name'], 'h' => (float)$b['hours_billed'], 'a' => (float)$b['amount']];
    }

    // Wpłata częściowa — żeby na fakturze było widać niedopłatę albo nadpłatę.
    if ($st['pay'] > 0 && !db_one("SELECT id FROM k30_ti_payments WHERE client_id=? AND note=?", [$cid, SEED_TAG])) {
        db_insert('k30_ti_payments', [
            'client_id'  => $cid,
            'amount'     => $st['pay'],
            'paid_at'    => date('Y-m-d', strtotime($from . ' +10 days')),
            'method'     => 'transfer',
            'note'       => SEED_TAG,
            'course_id'  => 0,
            'created_at' => $now,
        ]);
    }
    try { ti_billing_recompute($cid); } catch (\Throwable $e) {}

    printf("  kursant #%d %s\n", $cid, $st['name']);
    foreach ($bids as $b) {
        printf("      rozliczenie #%-4d %-28s %5s godz.  %10s zł\n",
            $b['id'], $b['group'],
            rtrim(rtrim(number_format($b['h'], 2, ',', ' '), '0'), ','),
            number_format($b['a'], 2, ',', ' '));
    }
    $made[] = ['cid' => $cid, 'bids' => $bids];
}

// ── Podsumowanie ─────────────────────────────────────────────────────────────
echo "\nGotowe. Faktury wystawisz w: Rozliczenia TI → „Wystaw fakturę” przy rozliczeniu\n";
echo "albo od razu z rejestru: " . APP_URL . "/crm/invoices/index.php\n";

if (!module_enabled('invoices_enabled')) {
    echo "\nUWAGA: moduł Faktury jest wyłączony — włącz go w Administracja → Moduły,\n";
    echo "inaczej przycisk „Wystaw fakturę” się nie pokaże.\n";
}
if (!invoices_api_ready()) {
    echo "\nUWAGA: Fakturownia nieskonfigurowana — szkice utworzysz, ale nie wystawisz.\n";
    echo "Podgląd wydruku roboczego działa bez konfiguracji.\n";
}
echo "\nUsunięcie danych testowych: php cli/seed_ti_faktury.php --clean\n";
exit(0);
