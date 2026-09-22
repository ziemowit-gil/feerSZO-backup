<?php
/**
 * cli/betterfly_demo.php — demonstracja integracji z Comarch Betterfly.
 *
 * Pokazuje pełny proces dla modułu TI, rozliczanego PER KURS:
 *   1) rozliczenie kursu (godziny × stawka) dla kursanta w danym miesiącu,
 *   2) zbudowanie payloadu faktury (mapowanie nabywcy + pozycji),
 *   3) [--send] wystawienie faktury w Betterfly (find-or-create kontrahenta),
 *   4) [--send] pobranie faktury z powrotem i synchronizacja statusu płatności.
 *
 * Domyślnie działa w trybie --dry-run: liczy rozliczenie i wypisuje payload
 * BEZ łączenia z API (bezpieczne do uruchomienia lokalnie).
 *
 * Użycie:
 *   php cli/betterfly_demo.php --course=12 --client=345 --month=9 --year=2026 [--confirm]
 *   php cli/betterfly_demo.php --course=12 --client=345 --month=9 --year=2026 --send [--confirm]
 *
 * Wymagania trybu --send (ustawienia admina w tabeli settings):
 *   betterfly_enabled=1, betterfly_ti_enabled=1,
 *   betterfly_client_id, betterfly_client_secret,
 *   betterfly_default_payment_type_id, betterfly_default_vat_rate_id,
 *   betterfly_ti_product_id (produkt dla pozycji kursu).
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

// ── Parsowanie argumentów ────────────────────────────────────────────────────
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$course  = (int)($args['course'] ?? 0);
$client  = (int)($args['client'] ?? 0);
$month   = (int)($args['month']  ?? date('n'));
$year    = (int)($args['year']   ?? date('Y'));
$send    = !empty($args['send']);
$confirm = !empty($args['confirm']);

if ($course <= 0 || $client <= 0) {
    fwrite(STDERR, "Podaj --course=<id kursu> oraz --client=<id kursanta>.\n");
    fwrite(STDERR, "Przykład: php cli/betterfly_demo.php --course=12 --client=345 --month=9 --year=2026\n");
    exit(1);
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
$sep = str_repeat('─', 64);

try {
    out($sep);
    out("Comarch Betterfly — demo fakturowania TI (per kurs)");
    out("Kurs #{$course} · kursant #{$client} · okres {$month}/{$year} · "
        . ($send ? ($confirm ? 'WYSYŁKA + ZATWIERDZENIE' : 'WYSYŁKA (bufor)') : 'DRY-RUN'));
    out($sep);

    // ── Krok 1: rozliczenie PER KURS ─────────────────────────────────────────
    $billing = betterfly_ti_course_billing($course, $client, $month, $year);
    out(sprintf(
        "1) Rozliczenie kursu \"%s\":\n   lekcje z obecnością: %d | godziny (ceil/lekcja): %s | stawka: %.2f | kwota: %.2f",
        $billing['course_name'], $billing['lessons'], rtrim(rtrim(number_format($billing['hours'], 2, '.', ''), '0'), '.'),
        $billing['hourly_rate'], $billing['amount']
    ));

    if (!$send) {
        // ── Tryb DRY-RUN: zbuduj payload bez łączenia z API ──────────────────
        $unitNet = betterfly_ti_unit_net((float)$billing['hourly_rate']);
        $desc    = sprintf('Zajęcia TI — %s, %02d/%d (%s godz.)',
            $billing['course_name'], $month, $year,
            rtrim(rtrim(number_format($billing['hours'], 2, '.', ''), '0'), '.'));

        $buyer = betterfly_buyer_from_ti_client($client);
        out("\n2) Payload kontrahenta (nabywca):");
        out(json_encode(betterfly_customer_payload($buyer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // ProductId tylko jeśli skonfigurowany — w dry-run pokazujemy 0 gdy brak.
        $productId = (int)org_setting('betterfly_ti_product_id');
        $items = [[
            'ProductId'            => $productId,
            'Quantity'             => (float)$billing['hours'],
            'ProductCurrencyPrice' => $unitNet,
            'ProductDescription'   => $desc,
            'VatRateId'            => (int)org_setting('betterfly_default_vat_rate_id'),
        ]];

        out("\n3) Payload faktury (PurchasingPartyId zostanie ustalony po find-or-create):");
        // Pokaz poglądowy — nie waliduje wymaganych ustawień (to robi tryb --send).
        $preview = [
            'PurchasingPartyId' => '<ustalony z NIP>',
            'PaymentTypeId'     => (int)org_setting('betterfly_default_payment_type_id'),
            'IssueDate'         => date('Y-m-d\T00:00:00P'),
            'SalesDate'         => date('Y-m-d\T00:00:00P'),
            'Items'             => $items,
        ];
        out(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        out("\n[DRY-RUN] Nic nie wysłano. Dodaj --send, aby wystawić fakturę w Betterfly.");
        exit(0);
    }

    // ── Krok 2–3: wystawienie faktury (realne wywołanie API) ─────────────────
    out("\n2-3) Wystawianie faktury w Betterfly…");
    $res = betterfly_issue_ti_course_invoice($course, $client, $month, $year, [
        'confirm' => $confirm,
        // Przykład płatnika z NIP (rodzic/podmiot). Usuń, jeśli nabywcą jest kursant:
        // 'buyer_override' => ['name' => 'Firma XYZ Sp. z o.o.', 'nip' => '6770065406', 'is_company' => true],
    ]);
    out(sprintf(
        "   OK · Betterfly invoice #%d · numer: %s · kontrahent #%d · rekord lokalny #%d",
        $res['betterfly_invoice_id'], $res['number'] !== '' ? $res['number'] : '(bufor)',
        $res['customer_id'], $res['local_id']
    ));

    // ── Krok 4: pobranie faktury i synchronizacja statusu ────────────────────
    out("\n4) Pobranie faktury z Betterfly i synchronizacja statusu…");
    $row = betterfly_sync_invoice((int)$res['local_id']);
    out(sprintf(
        "   Numer: %s | netto: %.2f %s | brutto: %.2f | VAT: %.2f | dokument: %s | płatność: %s",
        $row['number'] ?: '(brak)', (float)$row['net_total'], $row['currency'],
        (float)$row['gross_total'], (float)$row['vat_total'],
        (int)$row['doc_status'] === 1 ? 'zatwierdzona' : 'bufor',
        betterfly_payment_status_label((int)$row['payment_status'])
    ));

    out("\n" . $sep);
    out("Gotowe.");
    exit(0);

} catch (BetterFlyException $e) {
    fwrite(STDERR, "\n[BŁĄD BETTERFLY] " . $e->getMessage()
        . ($e->httpStatus ? " (HTTP {$e->httpStatus})" : '') . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[BŁĄD] " . $e->getMessage() . "\n");
    exit(2);
}
