<?php
/**
 * modules/ti_lesson_ledger/logic/lessonLedger.php — „pobieranie za lekcje z salda”.
 *
 * Widok portfela kursanta lekcja po lekcji: wpłata zwiększa saldo, każda
 * rozliczalna lekcja (obecność albo płatna nieobecność) jest POBRANIEM z salda
 * po stawce z dnia lekcji (k30_ti_calculate_billing → rate_by_date). Ryczałt
 * to jedno pobranie na 1. dzień miesiąca. Gdy miesiąc jest już rozliczony,
 * różnica między należnością rozliczenia a sumą pobrań (korekta, kwota ręczna,
 * rabat, przeniesienie niedopłaty) wchodzi osobną pozycją — dzięki temu saldo
 * rozliczonych miesięcy zgadza się z księgą rozliczeń (ti_client_allocation).
 * Lekcje z miesięcy jeszcze nierozliczonych są oznaczone „nierozliczone”.
 *
 * Księga jest wyliczana (nie zapisywana) — jedno źródło prawdy zostaje w
 * k30_ti_billing / k30_ti_payments. Zapisujemy tylko historię WYSYŁEK
 * (k30_ti_ledger_sends) i wygenerowane PDF-y wysłane mailem (uploads/ti_ledger/).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';

function ti_ledger_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_ledger_sends (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id   INTEGER NOT NULL,
        date_from   TEXT    NOT NULL DEFAULT '',
        date_to     TEXT    NOT NULL DEFAULT '',
        recipients  TEXT    NOT NULL DEFAULT '',
        closing     REAL    NOT NULL DEFAULT 0,
        file_path   TEXT    NOT NULL DEFAULT '',
        by_name     TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_ledger_sends_client ON k30_ti_ledger_sends(client_id)");
}

/**
 * Księga pobrań kursanta. $from/$to = 'Y-m-d' (puste = bez ograniczenia);
 * pozycje sprzed $from wchodzą do salda otwarcia.
 * @return array{rows:list<array>, opening:float, closing:float, charged:float, paid_in:float, unbilled:float, client:array}
 */
function ti_lesson_ledger(int $client_id, string $from = '', string $to = ''): array {
    $cl = db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$client_id]) ?: ['id' => $client_id, 'name' => '?', 'email' => ''];
    $ev = [];   // [date, ord, kind, label, amount(+/-), meta]

    // Wpłaty (przeniesienia wewnętrzne między grupami nie zmieniają salda konta — pomijamy)
    foreach (db_all("SELECT * FROM k30_ti_payments WHERE client_id=? AND COALESCE(source_type,'')!='transfer'", [$client_id]) as $p) {
        $ev[] = ['date' => (string)($p['paid_at'] ?: substr((string)$p['created_at'], 0, 10)), 'ord' => 0, 'kind' => 'in',
                 'label' => 'Wpłata' . ((string)$p['note'] !== '' ? ' — ' . $p['note'] : ''), 'amount' => round((float)$p['amount'], 2),
                 'course' => (int)$p['course_id'] > 0 ? ti_transfer_group_label((int)$p['course_id']) : '', 'hours' => null, 'rate' => null, 'unbilled' => false];
    }

    // Miesiące z lekcjami lub rozliczeniami
    $months = [];
    foreach (db_all("SELECT DISTINCT strftime('%Y-%m', s.lesson_date) AS ym FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
                      WHERE a.client_id=? AND (a.attended=1 OR COALESCE(a.no_show,0)=1)", [$client_id]) as $r) $months[$r['ym']] = true;
    foreach (db_all("SELECT DISTINCT printf('%04d-%02d', year, month) AS ym FROM k30_ti_billing WHERE client_id=? AND status IN ('issued','paid')", [$client_id]) as $r) $months[$r['ym']] = true;
    ksort($months);

    $cname = [];
    $course_name = function (int $cid) use (&$cname): string {
        return $cname[$cid] ??= (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? ('#' . $cid));
    };

    foreach (array_keys($months) as $ym) {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $first = sprintf('%04d-%02d-01', $y, $m); $last = date('Y-m-t', strtotime($first));
        $calc  = k30_ti_calculate_billing($client_id, $m, $y);
        $bills = db_all("SELECT * FROM k30_ti_billing WHERE client_id=? AND month=? AND year=? AND status IN ('issued','paid')", [$client_id, $m, $y]);
        $billed_course = []; $has_total = false;
        foreach ($bills as $b) { if ((int)$b['course_id'] > 0) $billed_course[(int)$b['course_id']] = $b; else $has_total = true; }
        $sum_by = [];   // course_id => suma pobrań z lekcji (do rozliczenia różnicy)

        foreach ($calc['courses'] as $cc) {
            $cid = (int)$cc['course_id']; $sum_by[$cid] = 0.0;
            $unb = !$has_total && !isset($billed_course[$cid]);
            if (empty($cc['hourly'])) {
                if ((float)$cc['amount'] <= 0.005) continue;
                $ev[] = ['date' => $first, 'ord' => 1, 'kind' => 'fee', 'label' => 'Opłata miesięczna (ryczałt)', 'amount' => -round((float)$cc['amount'], 2),
                         'course' => (string)$cc['course_name'], 'hours' => null, 'rate' => null, 'unbilled' => $unb];
                $sum_by[$cid] += round((float)$cc['amount'], 2);
                continue;
            }
            $ls = db_all(
                "SELECT s.lesson_date, s.time_from, s.duration_min, s.topic, a.attended, COALESCE(a.no_show,0) AS no_show, a.no_show_billing
                   FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
                  WHERE a.client_id=? AND s.course_id=? AND s.lesson_date BETWEEN ? AND ? AND COALESCE(a.cancelled,0)=0
                    AND (a.attended=1 OR COALESCE(a.no_show,0)=1) ORDER BY s.lesson_date, s.time_from",
                [$client_id, $cid, $first, $last]
            );
            foreach ($ls as $l) {
                $ns  = (int)$l['attended'] === 0;
                $h   = ($ns && $l['no_show_billing'] === '1h') ? 1.0 : (float)ceil((int)$l['duration_min'] / 60);
                $r   = (float)($cc['rate_by_date'][$l['lesson_date']] ?? $cc['hourly_rate']);
                $amt = round($h * $r, 2);
                $ev[] = ['date' => (string)$l['lesson_date'], 'ord' => 2, 'kind' => 'lesson',
                         'label' => ($ns ? 'Nieobecność nieusprawiedliwiona' : 'Lekcja') . ($l['time_from'] ? ' ' . substr((string)$l['time_from'], 0, 5) : '')
                                    . ((string)$l['topic'] !== '' ? ' — ' . $l['topic'] : ''),
                         'amount' => -$amt, 'course' => (string)$cc['course_name'], 'hours' => $h, 'rate' => $r, 'unbilled' => $unb];
                $sum_by[$cid] += $amt;
            }
        }

        // Rozliczone: różnica należność − pobrania (korekty, kwota ręczna, przeniesienia, rabat polecający)
        foreach ($bills as $b) {
            $due  = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
            $cid  = (int)$b['course_id'];
            $done = $cid > 0 ? ($sum_by[$cid] ?? 0.0) : array_sum($sum_by);
            $diff = round($due - $done, 2);
            if (abs($diff) < 0.005) continue;
            $why = [];
            if (($b['manual_amount'] ?? null) !== null) $why[] = 'kwota ręczna' . ((string)$b['manual_note'] !== '' ? ': ' . $b['manual_note'] : '');
            if (abs((float)($b['adjustment'] ?? 0)) > 0.005) $why[] = trim((string)$b['adjustment_note']) !== '' ? (string)$b['adjustment_note'] : 'korekta';
            $ev[] = ['date' => $last, 'ord' => 3, 'kind' => 'corr',
                     'label' => ($diff > 0 ? 'Dopłata' : 'Zwrot / rabat') . ' wg rozliczenia ' . sprintf('%02d/%d', $m, $y) . ($why ? ' — ' . implode('; ', $why) : ''),
                     'amount' => -$diff, 'course' => $cid > 0 ? $course_name($cid) : 'łączne', 'hours' => null, 'rate' => null, 'unbilled' => false];
        }
    }

    usort($ev, fn($a, $b) => [$a['date'], $a['ord']] <=> [$b['date'], $b['ord']]);
    $bal = 0.0; $opening = 0.0; $rows = []; $charged = 0.0; $paid_in = 0.0; $unbilled = 0.0;
    foreach ($ev as $e) {
        $bal = round($bal + $e['amount'], 2);
        if ($from !== '' && $e['date'] < $from) { $opening = $bal; continue; }
        if ($to !== '' && $e['date'] > $to) continue;
        $e['balance'] = $bal;
        if ($e['amount'] >= 0) $paid_in += $e['amount']; else $charged -= $e['amount'];
        if ($e['unbilled']) $unbilled -= $e['amount'];
        $rows[] = $e;
    }
    return ['rows' => $rows, 'opening' => $opening, 'closing' => $rows ? end($rows)['balance'] : $opening,
            'charged' => round($charged, 2), 'paid_in' => round($paid_in, 2), 'unbilled' => round($unbilled, 2), 'client' => $cl];
}

/** PDF historii pobrań (TiPdf, polskie znaki). Zwraca treść pliku. */
function ti_lesson_ledger_pdf(int $client_id, string $from = '', string $to = ''): string {
    require_once dirname(__DIR__, 3) . '/modules/ti_pdf/logic/TiPdf.php';
    $L   = ti_lesson_ledger($client_id, $from, $to);
    $pl  = [TiPdf::class, 'pl'];
    $zl  = fn(float $v) => number_format($v, 2, ',', ' ') . ' zł';
    $org = (string)org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $pdf = new TiPdf('P', 'mm', 'A4');
    $pdf->tiOrg = $org;
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(0, 9, $pl('Historia pobrań za lekcje — ' . $L['client']['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 9); $pdf->Ln(2);
    $range = ($from !== '' ? date('d.m.Y', strtotime($from)) : 'od początku') . ' – ' . ($to !== '' ? date('d.m.Y', strtotime($to)) : date('d.m.Y'));
    $pdf->Cell(0, 5, $pl('Okres: ' . $range . '   ·   Saldo otwarcia: ' . $zl($L['opening']) . '   ·   Wpłaty: ' . $zl($L['paid_in'])
        . '   ·   Pobrania: ' . $zl($L['charged'])), 0, 1);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, $pl('Saldo na koniec okresu: ' . ($L['closing'] < 0 ? 'do zapłaty ' . $zl(-$L['closing']) : $zl($L['closing']))), 0, 1);
    $pdf->Ln(2);
    $w = [20, 38, 70, 12, 20, 22];
    $pdf->SetFont('Helvetica', 'B', 8); $pdf->SetFillColor(224, 232, 244);
    foreach (['Data', 'Grupa', 'Pozycja', 'Godz.', 'Kwota', 'Saldo'] as $i => $hd) $pdf->Cell($w[$i], 6, $pl($hd), 1, 0, $i >= 3 ? 'R' : 'L', true);
    $pdf->Ln();
    $pdf->SetFont('Helvetica', '', 8);
    $z = false;
    foreach ($L['rows'] as $r) {
        $pdf->SetFillColor(241, 245, 249);
        $lab = $r['label'] . ($r['rate'] !== null ? ' (' . number_format((float)$r['rate'], 2, ',', ' ') . ' zł/h)' : '') . ($r['unbilled'] ? ' *' : '');
        $pdf->Cell($w[0], 5.5, date('d.m.Y', strtotime($r['date'])), 1, 0, 'L', $z);
        $pdf->Cell($w[1], 5.5, $pl(mb_strimwidth((string)$r['course'], 0, 26, '…')), 1, 0, 'L', $z);
        $pdf->Cell($w[2], 5.5, $pl(mb_strimwidth($lab, 0, 52, '…')), 1, 0, 'L', $z);
        $pdf->Cell($w[3], 5.5, $r['hours'] !== null ? rtrim(rtrim(number_format((float)$r['hours'], 2, ',', ''), '0'), ',') : '', 1, 0, 'R', $z);
        if ($r['amount'] < 0) $pdf->SetTextColor(170, 0, 0); else $pdf->SetTextColor(0, 130, 0);
        $pdf->Cell($w[4], 5.5, $pl(($r['amount'] < 0 ? '−' : '+') . $zl(abs($r['amount']))), 1, 0, 'R', $z);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($w[5], 5.5, $pl($zl($r['balance'])), 1, 1, 'R', $z);
        $z = !$z;
    }
    if (!$L['rows']) $pdf->Cell(array_sum($w), 6, $pl('Brak operacji w tym okresie.'), 1, 1, 'C');
    $pdf->Ln(3); $pdf->SetFont('Helvetica', '', 7.5); $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, $pl('Każda odbyta lekcja (i płatna nieobecność) jest pobierana z salda po stawce z dnia lekcji; ryczałt — 1. dnia miesiąca.'
        . ' „Dopłata / zwrot wg rozliczenia” to różnica między wystawionym rozliczeniem a sumą lekcji (korekta, rabat, kwota ustalona indywidualnie).'
        . ($L['unbilled'] > 0.005 ? ' * lekcje z miesięcy jeszcze nierozliczonych (' . $zl($L['unbilled']) . ') — kwota może się zmienić przy wystawieniu rozliczenia.' : '')));
    $pdf->Ln(1); $pdf->Cell(0, 4, $pl('Wygenerowano ' . date('d.m.Y H:i')), 0, 1, 'L');
    return $pdf->Output('S');
}

/** Adresy do wysyłki: e-mail kursanta + opiekuna (małoletni). @return array<string,string> email => nazwa */
function ti_lesson_ledger_recipients(int $client_id): array {
    $out = [];
    $cl  = db_one("SELECT name, email FROM k30_clients WHERE id=?", [$client_id]);
    $acc = db_one("SELECT is_minor, guardian_email, guardian_name FROM k30_ti_student_accounts WHERE client_id=?", [$client_id]);
    $e = trim((string)($cl['email'] ?? ''));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = (string)$cl['name'];
    $g = trim((string)($acc['guardian_email'] ?? ''));
    if (!empty($acc['is_minor']) && $g !== '' && filter_var($g, FILTER_VALIDATE_EMAIL)) $out[$g] = (string)($acc['guardian_name'] ?: $cl['name']);
    return $out;
}

/**
 * Wysyła historię pobrań (podsumowanie w treści + PDF w załączniku) i zapisuje
 * wysyłkę w k30_ti_ledger_sends. Zwraca liczbę adresów albo komunikat błędu.
 */
function ti_lesson_ledger_send(int $client_id, string $from, string $to, string $by = ''): int|string {
    ti_ledger_migrate();
    if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $rcp = ti_lesson_ledger_recipients($client_id);
    if (!$rcp) return 'Brak adresu e-mail kursanta (ani opiekuna małoletniego).';
    $L   = ti_lesson_ledger($client_id, $from, $to);
    $pdf = ti_lesson_ledger_pdf($client_id, $from, $to);
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_ledger/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rel = 'ti_ledger/historia_' . $client_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
    if (@file_put_contents(rtrim(UPLOAD_DIR, '/') . '/' . $rel, $pdf) === false) return 'Nie udało się zapisać PDF.';
    $zl    = fn(float $v) => number_format($v, 2, ',', ' ') . ' zł';
    $org   = (string)org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $range = ($from !== '' ? date('d.m.Y', strtotime($from)) : 'od początku') . ' – ' . ($to !== '' ? date('d.m.Y', strtotime($to)) : date('d.m.Y'));
    $last  = array_slice($L['rows'], -10);
    $tr = '';
    foreach ($last as $r) {
        $tr .= '<tr><td style="padding:3px 8px;border-bottom:1px solid #e5e7eb">' . date('d.m.Y', strtotime($r['date'])) . '</td>'
             . '<td style="padding:3px 8px;border-bottom:1px solid #e5e7eb">' . htmlspecialchars($r['label'] . ($r['course'] !== '' ? ' · ' . $r['course'] : ''), ENT_QUOTES) . '</td>'
             . '<td style="padding:3px 8px;border-bottom:1px solid #e5e7eb;text-align:right;color:' . ($r['amount'] < 0 ? '#b91c1c' : '#15803d') . '">'
             . ($r['amount'] < 0 ? '−' : '+') . $zl(abs($r['amount'])) . '</td>'
             . '<td style="padding:3px 8px;border-bottom:1px solid #e5e7eb;text-align:right">' . $zl($r['balance']) . '</td></tr>';
    }
    $saldo = $L['closing'] < 0 ? 'do zapłaty <strong style="color:#b91c1c">' . $zl(-$L['closing']) . '</strong>' : '<strong>' . $zl($L['closing']) . '</strong>';
    $html = '<p>Dzień dobry,</p><p>przesyłamy historię pobrań za zajęcia — <strong>' . htmlspecialchars((string)$L['client']['name'], ENT_QUOTES) . '</strong>, okres ' . $range . '.</p>'
          . '<p>Wpłaty: ' . $zl($L['paid_in']) . ' · Pobrania za lekcje: ' . $zl($L['charged']) . '<br>Saldo na koniec okresu: ' . $saldo . '</p>'
          . ($tr !== '' ? '<p style="margin-bottom:4px">Ostatnie operacje:</p><table style="border-collapse:collapse;font-size:13px">' . $tr . '</table>' : '')
          . '<p>Pełna historia jest w załączniku (PDF).</p>'
          . '<p style="color:#888;font-size:12px">Wiadomość z systemu ' . htmlspecialchars($org, ENT_QUOTES) . '.</p>';
    $att = [['path' => $rel, 'name' => 'historia_pobran.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)]];
    $n = 0;
    foreach ($rcp as $addr => $nm) {
        try { mail_queue_add($addr, $nm, $org . ': historia pobrań za zajęcia (' . $range . ')', $html, '', 'ti_ledger', $client_id, '', false, $att); $n++; }
        catch (\Throwable $e) {}
    }
    db_insert('k30_ti_ledger_sends', ['client_id' => $client_id, 'date_from' => $from, 'date_to' => $to,
        'recipients' => implode(', ', array_keys($rcp)), 'closing' => $L['closing'], 'file_path' => $rel, 'by_name' => $by]);
    return $n;
}

/** Historia wysyłek (najnowsze pierwsze). */
function ti_lesson_ledger_sends(int $client_id): array {
    ti_ledger_migrate();
    return db_all("SELECT * FROM k30_ti_ledger_sends WHERE client_id=? ORDER BY id DESC", [$client_id]);
}
