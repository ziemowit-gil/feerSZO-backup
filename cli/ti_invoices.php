<?php
/**
 * cli/ti_invoices.php — kontrola i testy fakturowania rozliczeń TI
 * (includes/invoices.php → invoice_from_ti_billing() / invoice_ti_items()).
 *
 *   php cli/ti_invoices.php check [--month=RRRR-MM] [--client=ID]
 *       Tabela rozliczeń: kwota zapisana, korekta, NALEŻNOŚĆ, przeliczenie z
 *       obecności, suma pozycji FV i istniejąca faktura. Flagi:
 *         NIEAKTUALNE  — zapis ≠ przeliczenie (obecność zmieniona po wystawieniu)
 *         FV≠NALEŻN.   — brutto istniejącej faktury ≠ należność
 *         BEZ FV       — rozliczenie z kwotą bez faktury
 *         FIRMA BEZ NIP — płatnik wygląda na firmę, brak NIP (faktura poza KSeF)
 *
 *   php cli/ti_invoices.php preview --billing=ID [--out=plik.pdf]
 *       Podgląd faktury dla kursanta: tworzy fakturę roboczą tym samym kodem co
 *       system, wypisuje pozycje i ostrzeżenia, renderuje PDF (z załącznikami
 *       TI) do pliku, po czym WYCOFUJE transakcję — w bazie nic nie zostaje.
 *
 *   php cli/ti_invoices.php selftest
 *       Testy w wycofywanej transakcji: suma faktury = należność (z rabatem),
 *       ryczałt jako 1 usługa, wykrycie nieaktualnego rozliczenia.
 *       Kod wyjścia 0 = OK, 1 = błąd, 2 = brak danych.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

$base = dirname(__DIR__);
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_payments.php';
require_once $base . '/includes/invoices.php';
require_once $base . '/includes/invoice_pdf.php';
karty30_migrate();
ti_payments_migrate();
invoices_migrate();

$cmd = $argv[1] ?? 'help';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? '1';
}
$zl  = fn($v) => number_format((float)$v, 2, ',', ' ');
$out = fn(string $s = '') => fwrite(STDOUT, $s . "\n");
$die = function (string $s, int $code = 1) { fwrite(STDERR, $s . "\n"); exit($code); };
$uid = (int)(db_one("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);

/** Tabela tekstowa z wyrównaniem (UTF-8). */
$table = function (array $cols, array $data) use ($out) {
    $wd = array_map('mb_strlen', $cols);
    foreach ($data as $d) foreach ($d as $i => $v) $wd[$i] = max($wd[$i], mb_strlen((string)$v));
    $line = fn(array $r) => '| ' . implode(' | ', array_map(fn($v, $i) => $v . str_repeat(' ', $wd[$i] - mb_strlen((string)$v)), $r, array_keys($r))) . ' |';
    $sep  = '+' . implode('+', array_map(fn($n) => str_repeat('-', $n + 2), $wd)) . '+';
    $out($sep); $out($line($cols)); $out($sep);
    foreach ($data as $d) $out($line($d));
    $out($sep);
};

/** Suma brutto pozycji faktury (jak invoice_pdf). */
$items_gross = function (array $items): float {
    $g = 0.0;
    foreach ($items as $it) $g += invoice_item_calc($it)['line_gross'];
    return round($g, 2);
};

switch ($cmd) {

case 'check':
    $w = ["b.status != 'cancelled'"]; $p = [];
    if (preg_match('/^(\d{4})-(\d{2})$/', $opt['month'] ?? '', $mm)) { $w[] = 'b.year=? AND b.month=?'; $p[] = (int)$mm[1]; $p[] = (int)$mm[2]; }
    if (!empty($opt['client'])) { $w[] = 'b.client_id=?'; $p[] = (int)$opt['client']; }
    $rows = db_all(
        "SELECT b.*, COALESCE(b.course_id,0) AS course_id, c.name AS client_name, co.name AS course_name
           FROM k30_ti_billing b JOIN k30_clients c ON c.id=b.client_id
           LEFT JOIN k30_ti_courses co ON co.id=b.course_id
          WHERE " . implode(' AND ', $w) . " ORDER BY b.year DESC, b.month DESC, c.name", $p
    );
    $cfg = invoices_config();
    $data = []; $bad = 0;
    foreach ($rows as $b) {
        $calc = k30_ti_calculate_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year'], (int)$b['course_id']);
        $ti   = invoice_ti_items($b, (string)$cfg['vat']);
        $inv  = db_one("SELECT id, number, status FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [(int)$b['id']]);
        $inv_gross = $inv ? $items_gross(invoice_get((int)$inv['id'])['items'] ?? []) : null;
        $due  = $ti['due'];
        $flags = [];
        if (abs((float)$calc['amount'] - (float)$b['amount']) >= 0.01) $flags[] = 'NIEAKTUALNE';
        if ($inv && abs($inv_gross - $due) >= 0.01) $flags[] = 'FV≠NALEŻN.';
        if (!$inv && $due > 0.005) $flags[] = 'BEZ FV';
        $buyer = invoice_ti_buyer_preview($b + ['client_name' => $b['client_name']]);
        if ($buyer['tax_no'] === '' && invoice_name_looks_company($buyer['name'])) $flags[] = 'FIRMA BEZ NIP';
        if (array_intersect($flags, ['NIEAKTUALNE', 'FV≠NALEŻN.', 'FIRMA BEZ NIP'])) $bad++;
        $data[] = [
            '#' . $b['id'], $b['client_name'], sprintf('%02d/%d', $b['month'], $b['year']),
            $b['course_id'] ? $b['course_name'] : 'łączne',
            $zl($b['amount']), $zl($b['adjustment'] ?? 0), $zl($due), $zl($calc['amount']),
            (string)count($ti['items']),
            $inv ? (($inv['number'] ?: 'szkic #' . $inv['id']) . ' · ' . $zl($inv_gross)) : '—',
            $flags ? implode(' ', $flags) : 'OK',
        ];
    }
    if (!$data) { $out('Brak rozliczeń dla filtrów.'); break; }
    $table(['ID', 'Kursant', 'Okres', 'Grupa', 'Zapisane', 'Korekta', 'Należność', 'Przeliczone', 'Poz. FV', 'Faktura (brutto)', 'Stan'], $data);
    $out(sprintf('Rozliczeń: %d · z rozbieżnością: %d · VAT domyślny: %s', count($data), $bad, $cfg['vat']));
    exit($bad ? 1 : 0);

case 'preview':
    $bid = (int)($opt['billing'] ?? 0);
    if (!$bid) $die('Użycie: preview --billing=ID [--out=plik.pdf]');
    $b = db_one("SELECT b.*, c.name AS client_name FROM k30_ti_billing b JOIN k30_clients c ON c.id=b.client_id WHERE b.id=?", [$bid]);
    if (!$b) $die("Brak rozliczenia #{$bid}.");
    $file = (string)($opt['out'] ?? (sys_get_temp_dir() . "/faktura_ti_podglad_{$bid}.pdf"));

    db()->beginTransaction();
    try {
        // Uwaga: prawdziwa (nie testowa) ścieżka — ten sam dokument, jaki powstałby
        // z panelu; transakcja i tak jest wycofywana.
        db()->prepare("UPDATE invoices SET deleted_at=datetime('now') WHERE source='ti_billing' AND source_id=?")->execute([$bid]);
        $r = invoice_from_ti_billing($bid, $uid, false);
        if (empty($r['ok'])) $die('Nie da się utworzyć faktury: ' . ($r['error'] ?? '?'));
        $inv = invoice_get((int)$r['id']);
        $out("Faktura (podgląd, szkic) z rozliczenia #{$bid} — {$b['client_name']}, " . sprintf('%02d/%d', $b['month'], $b['year']));
        $out('Nabywca: ' . $inv['buyer_name'] . (trim((string)($inv['buyer_tax_no'] ?? '')) !== '' ? ' · NIP ' . $inv['buyer_tax_no'] : ' · bez NIP (poza KSeF)'));
        $out('Termin płatności: ' . $inv['payment_to']);
        $rows = [];
        foreach ($inv['items'] as $i => $it) {
            $c = invoice_item_calc($it);
            $rows[] = [(string)($i + 1), $c['name'], rtrim(rtrim(number_format($c['qty'], 2, ',', ''), '0'), ','), $c['unit'],
                       $zl($c['unit_net']), $c['vat_rate'], $zl($c['line_net']), $zl($c['line_gross'])];
        }
        $table(['Lp', 'Nazwa', 'Ilość', 'J.m.', 'Cena netto', 'VAT', 'Netto', 'Brutto'], $rows);
        $g = $items_gross($inv['items']);
        $due = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
        $out(sprintf('Razem brutto: %s zł · należność z rozliczenia: %s zł %s', $zl($g), $zl($due), abs($g - $due) < 0.01 ? '✔' : '✘ RÓŻNICA'));
        foreach (($r['warnings'] ?? []) as $w) $out('⚠ ' . $w);
        $pdf = invoice_pdf_render($inv);
        file_put_contents($file, $pdf);
        $out("PDF (wizualizacja dla kursanta, ze znakiem FAKTURA ROBOCZA): {$file}");
    } finally {
        db()->rollBack();
        $out('Wycofano — w bazie nie powstała żadna faktura.');
    }
    break;

case 'selftest':
    $cfg  = invoices_config();
    $fail = 0;
    $check = function (string $name, bool $ok) use (&$fail, $out) { $out(($ok ? '  ✔ ' : '  ✘ ') . $name); if (!$ok) $fail++; };
    $bills = db_all("SELECT b.*, COALESCE(b.course_id,0) AS course_id FROM k30_ti_billing b WHERE b.status != 'cancelled' AND b.amount > 0 ORDER BY b.id DESC LIMIT 100");
    $hourly = $flat = null;
    foreach ($bills as $b) {
        $c = k30_ti_calculate_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year'], (int)$b['course_id']);
        if (abs((float)$c['amount'] - (float)$b['amount']) >= 0.01 || count($c['courses']) !== 1) continue;
        if (!empty($c['courses'][0]['hourly'])) $hourly ??= $b; else $flat ??= $b;
    }
    if (!$hourly && !$flat) $die('Brak aktualnych rozliczeń jednej grupy do testu.', 2);

    db()->beginTransaction();
    try {
        foreach (array_filter(['godzinowe' => $hourly, 'ryczałt' => $flat]) as $kind => $b) {
            $bid = (int)$b['id'];
            $out("Rozliczenie #{$bid} ({$kind}), " . sprintf('%02d/%d', $b['month'], $b['year']) . ', kwota ' . $zl($b['amount']));
            db()->prepare("UPDATE invoices SET deleted_at=datetime('now') WHERE source='ti_billing' AND source_id=?")->execute([$bid]);
            db()->prepare("UPDATE k30_ti_billing SET adjustment=-25, adjustment_note='test rabat' WHERE id=?")->execute([$bid]);
            $r   = invoice_from_ti_billing($bid, $uid, false);
            $inv = !empty($r['ok']) ? invoice_get((int)$r['id']) : null;
            $check('faktura utworzona', (bool)$inv);
            if (!$inv) continue;
            $due = round((float)$b['amount'] - 25, 2);
            $check('suma brutto = należność z rabatem (' . $zl($due) . ')', abs($items_gross($inv['items']) - $due) < 0.01);
            $check('rabat jako osobna pozycja', (bool)array_filter($inv['items'], fn($i) => str_starts_with((string)$i['name'], 'Rabat')));
            if ($kind === 'ryczałt') {
                $first = $inv['items'][0];
                $check('ryczałt jako 1 usł. (nie godziny)', (float)$first['qty'] === 1.0 && $first['unit'] === 'usł.');
            } else {
                $first = $inv['items'][0];
                $check('godzinowo: ilość w godzinach', $first['unit'] === 'godz.' && (float)$first['qty'] > 0);
            }
            $check('aktualne rozliczenie bez ostrzeżenia „nieaktualne”', !array_filter($r['warnings'] ?? [], fn($w) => str_contains($w, 'nieaktualne')));
        }
        // Nieaktualne rozliczenie: zapisana kwota ≠ przeliczenie
        $b = $hourly ?: $flat;
        $bid = (int)$b['id'];
        db()->prepare("UPDATE invoices SET deleted_at=datetime('now') WHERE source='ti_billing' AND source_id=?")->execute([$bid]);
        db()->prepare("UPDATE k30_ti_billing SET amount=amount+10, adjustment=0 WHERE id=?")->execute([$bid]);
        $r   = invoice_from_ti_billing($bid, $uid, false);
        $inv = invoice_get((int)$r['id']);
        $out("Rozliczenie #{$bid} z kwotą zmienioną ręcznie (+10 zł)");
        $check('ostrzeżenie o nieaktualnym rozliczeniu', (bool)array_filter($r['warnings'] ?? [], fn($w) => str_contains($w, 'nieaktualne')));
        $check('faktura wg kwoty zapisanej', abs($items_gross($inv['items']) - round((float)$b['amount'] + 10, 2)) < 0.01);
        $pdf = invoice_pdf_render($inv);
        $check('PDF faktury renderuje się', str_starts_with($pdf, '%PDF'));
    } finally {
        db()->rollBack();
    }
    $out($fail ? "BŁĘDY: {$fail}" : 'OK — wszystkie testy przeszły (transakcja wycofana).');
    exit($fail ? 1 : 0);

default:
    $out('Użycie: php cli/ti_invoices.php check|preview|selftest — szczegóły w nagłówku pliku.');
}
