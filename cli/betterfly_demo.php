<?php
/**
 * cli/betterfly_demo.php — demonstracja integracji z Comarch Betterfly.
 *
 * Tryby (rozliczanie per kurs):
 *   • zbiorczy (domyślny, bez --course): JEDNA faktura dla kursanta z OSOBNĄ
 *     pozycją na każdy kurs, na który jest zapisany (w danym miesiącu),
 *   • pojedynczy (--course=ID): faktura za jeden konkretny kurs.
 *
 * Domyślnie działa jako --dry-run: liczy rozliczenie i wypisuje payload BEZ
 * łączenia z API. Dodaj --send, aby wystawić fakturę (i pobrać ją z powrotem).
 *
 * Użycie:
 *   php cli/betterfly_demo.php --client=345 --month=9 --year=2026            # zbiorczy, dry-run
 *   php cli/betterfly_demo.php --client=345 --month=9 --year=2026 --send --confirm
 *   php cli/betterfly_demo.php --client=345 --course=12 --month=9 --year=2026 --send
 *
 * Uwaga: gdy włączona brama EODoK (betterfly_edok_gate_sales=1), --send tworzy
 * fakturę w buforze i dokument w obiegu — zatwierdzenie w Betterfly następuje
 * po finalnej akceptacji EODoK (--confirm jest wtedy ignorowany).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/betterfly.php';
require_once $base . '/includes/betterfly_invoices.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

$course  = (int)($args['course'] ?? 0);
$client  = (int)($args['client'] ?? 0);
$month   = (int)($args['month']  ?? date('n'));
$year    = (int)($args['year']   ?? date('Y'));
$send    = !empty($args['send']);
$confirm = !empty($args['confirm']);

if ($client <= 0) {
    fwrite(STDERR, "Podaj --client=<id kursanta> (opcjonalnie --course=<id kursu> dla trybu pojedynczego).\n");
    fwrite(STDERR, "Przykład: php cli/betterfly_demo.php --client=345 --month=9 --year=2026\n");
    exit(1);
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
$sep    = str_repeat('─', 64);
$hrsTxt = fn(float $h): string => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');

try {
    out($sep);
    out('Comarch Betterfly — demo fakturowania TI');
    out(($course > 0 ? "Kurs #{$course} · " : 'Tryb zbiorczy (pozycja/kurs) · ')
        . "kursant #{$client} · okres {$month}/{$year} · "
        . ($send ? ($confirm ? 'WYSYŁKA + ZATWIERDZENIE' : 'WYSYŁKA (bufor)') : 'DRY-RUN'));
    if (betterfly_edok_gate_sales()) out('Brama EODoK: WŁĄCZONA — faktura sprzedaży czeka na akceptację obiegu.');
    out($sep);

    // ── DRY-RUN: policz i pokaż, bez API ─────────────────────────────────────
    if (!$send) {
        $items = [];
        if ($course > 0) {
            $b = betterfly_ti_course_billing($course, $client, $month, $year);
            out(sprintf('1) Kurs "%s": lekcje %d | godziny %s | stawka %.2f | kwota %.2f',
                $b['course_name'], $b['lessons'], $hrsTxt($b['hours']), $b['hourly_rate'], $b['amount']));
            $items[] = $b;
        } else {
            out('1) Rozliczenie per kurs (osobna pozycja na każdy kurs):');
            foreach (db_all("SELECT course_id FROM k30_ti_enrollments WHERE client_id=? ORDER BY course_id", [$client]) as $e) {
                try { $b = betterfly_ti_course_billing((int)$e['course_id'], $client, $month, $year); }
                catch (BetterFlyException $ex) { continue; }
                if ($b['hours'] <= 0) continue;
                out(sprintf('   • %s — %s godz. × %.2f = %.2f', $b['course_name'], $hrsTxt($b['hours']), $b['hourly_rate'], $b['amount']));
                $items[] = $b;
            }
            if (!$items) { out('   (brak kursów z godzinami w tym okresie)'); }
        }

        out("\n2) Payload nabywcy:");
        out(json_encode(betterfly_customer_payload(betterfly_buyer_from_ti_client($client)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        out("\n3) Pozycje faktury (ProductCurrencyPrice = netto):");
        $pid = (int)org_setting('betterfly_ti_product_id');
        foreach ($items as $b) {
            out(json_encode([
                'ProductId'            => $pid,
                'Quantity'             => (float)$b['hours'],
                'ProductCurrencyPrice' => betterfly_ti_unit_net((float)$b['hourly_rate']),
                'ProductDescription'   => sprintf('Zajęcia TI — %s, %02d/%d (%s godz.)', $b['course_name'], $month, $year, $hrsTxt($b['hours'])),
                'VatRateId'            => (int)org_setting('betterfly_default_vat_rate_id'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        out("\n[DRY-RUN] Nic nie wysłano. Dodaj --send, aby wystawić fakturę.");
        exit(0);
    }

    // ── SEND: realne wywołanie API ───────────────────────────────────────────
    out('2-3) Wystawianie faktury w Betterfly…');
    $res = ($course > 0)
        ? betterfly_issue_ti_course_invoice($course, $client, $month, $year, ['confirm' => $confirm])
        : betterfly_issue_ti_client_invoice($client, $month, $year, ['confirm' => $confirm]);

    out(sprintf('   OK · Betterfly invoice #%d · numer: %s · kontrahent #%d · rekord #%d',
        $res['betterfly_invoice_id'], $res['number'] !== '' ? $res['number'] : '(bufor)',
        $res['customer_id'], $res['local_id']));
    if (!empty($res['via_edok'])) {
        out('   → Skierowano do obiegu EODoK (dokument #' . (int)$res['edok_doc_id']
            . '); zatwierdzenie w Betterfly po finalnej akceptacji.');
    }

    out("\n4) Pobranie faktury z Betterfly i synchronizacja statusu…");
    $row = betterfly_sync_invoice((int)$res['local_id']);
    out(sprintf('   Numer: %s | netto: %.2f %s | brutto: %.2f | VAT: %.2f | dokument: %s | płatność: %s',
        $row['number'] ?: '(brak)', (float)$row['net_total'], $row['currency'],
        (float)$row['gross_total'], (float)$row['vat_total'],
        (int)$row['doc_status'] === 1 ? 'zatwierdzona' : 'bufor',
        betterfly_payment_status_label((int)$row['payment_status'])));

    out("\n" . $sep . "\nGotowe.");
    exit(0);

} catch (BetterFlyException $e) {
    fwrite(STDERR, "\n[BŁĄD BETTERFLY] " . $e->getMessage() . ($e->httpStatus ? " (HTTP {$e->httpStatus})" : '') . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[BŁĄD] " . $e->getMessage() . "\n");
    exit(2);
}
