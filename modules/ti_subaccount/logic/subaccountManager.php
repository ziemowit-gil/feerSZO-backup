<?php
/**
 * modules/ti_subaccount/logic/subaccountManager.php — wpłata na subkonto kursanta TI
 * (indywidualny rachunek wirtualny z portalu płatności, payment_portal_users.individual_nrb).
 *
 * Sytuacja: kursant wpłacił X zł na swoje subkonto, a należy do kilku grup. Kierownik podaje
 * datę i kwotę, a system:
 *   1. pokazuje PODGLĄD — wszystkie grupy i miesiące od miesiąca wpłaty: rozliczenia już
 *      wystawione (należność, pokryte, pozostało) i zrealizowane zajęcia jeszcze bez rozliczenia
 *      (kwota z kalkulatora), plus wcześniejsze zaległości (domyślnie niezaznaczone);
 *   2. proponuje rozdział wpłaty FIFO po zaznaczonych pozycjach (od najstarszego miesiąca),
 *      który można ręcznie zmienić: kwota per pozycja, wybór grup, korekta należności z powodem;
 *   3. po zatwierdzeniu: wystawia brakujące rozliczenia (bez powiadomień „do zapłaty”), zapisuje
 *      korekty, księguje wpłatę — części przypisane do rozliczeń jako source_type='billing_match'
 *      (faza 0 alokacji, includes/ti_payments.php), reszta jako wpłata ogólna (nadpłata) —
 *      i wysyła kursantowi (małoletniemu: opiekunowi) tekstowy e-mail o ręcznych korektach.
 *
 * Wyciąg z subkonta (tiSubStatement) — wpłaty i należności z bieżącym saldem, tylko dla kursantów
 * z nadanym subkontem.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/modules/payment_portal/logic/paymentPortal.php';

const TI_SUB_MONTHS = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                       7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];

/** Subkonto kursanta (sformatowany NRB) albo null, gdy nie ma nadanego. */
function tiSubNrb(int $clientId): ?string {
    $u = pp_user($clientId);
    $n = $u ? pp_nrb_normalize((string)($u['individual_nrb'] ?? '')) : '';
    return $n !== '' ? pp_nrb_format($n) : null;
}

/** Kursanci z nadanym subkontem — do wyboru na ekranie. */
function tiSubClients(): array {
    pp_migrate();
    return db_all(
        "SELECT c.id, c.name, u.individual_nrb AS nrb
         FROM payment_portal_users u JOIN k30_clients c ON c.id=u.participant_id
         WHERE COALESCE(u.individual_nrb,'')!=''
           AND EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=c.id)
         ORDER BY c.name COLLATE NOCASE"
    );
}

function tiSubMonthLabel(int $m, int $y): string { return (TI_SUB_MONTHS[$m] ?? $m) . ' ' . $y; }

function tiSubCourseName(int $courseId): string {
    static $c = [];
    if ($courseId <= 0) return 'Rozliczenie łączne / opłaty poza zajęciami';
    return $c[$courseId] ??= (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$courseId])['name'] ?? ('Grupa #' . $courseId));
}

/**
 * Grupy, za które w miesiącu należy się rozliczenie — ta sama reguła co k30_ti_issue_billing_split():
 * zrealizowane zajęcia (obecność / no-show) albo aktywny zapis z ryczałtem (model 1/3).
 * @return list<int>
 */
function tiSubMonthCourses(int $clientId, int $m, int $y): array {
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to   = date('Y-m-t', strtotime($from));
    $ids  = [];
    foreach (db_all(
        "SELECT DISTINCT s.course_id FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
              AND s.lesson_date BETWEEN ? AND ?
         JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
         WHERE a.client_id=? AND COALESCE(a.cancelled,0)=0 AND (a.attended=1 OR COALESCE(a.no_show,0)=1)",
        [$from, $to, $clientId]) as $r) $ids[(int)$r['course_id']] = true;
    foreach (db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount
         FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active'", [$clientId]) as $e) {
        $eff = k30_ti_effective_billing($e, ['billing_model' => $e['course_billing_model'], 'billing_amount' => $e['course_billing_amount']]);
        if (in_array($eff['model'], [1, 3], true) && (float)$eff['amount'] > 0
            && !ti_course_hours_only((int)$e['course_id'], sprintf('%04d-%02d', $y, $m))) $ids[(int)$e['course_id']] = true;
    }
    return array_keys($ids);
}

/**
 * Podgląd rozdziału wpłaty.
 * Pozycja: key ('b{id}' — wystawione, 'n{y}-{m}-{course}' — do wystawienia), course_id, month, year,
 * billing_id, amount (kwota rozliczenia bez korekty), adjustment, due, paid, remaining, to_issue,
 * earlier (zaległość sprzed miesiąca wpłaty), selected, assign (propozycja), locked (faktura / przeniesiona
 * niedopłata — korekta niemożliwa).
 * @return array{nrb:?string, rows:list<array>, courses:array<int,string>, amount:float, assigned:float, leftover:float, date:string}
 */
function tiSubPlan(int $clientId, string $date, float $amount, ?array $courses = null): array {
    $amount = round(max(0, $amount), 2);
    $ts     = strtotime($date) ?: time();
    $date   = date('Y-m-d', $ts);
    $startY = (int)date('Y', $ts); $startM = (int)date('n', $ts);
    $startK = $startY * 100 + $startM;
    $nowK   = (int)date('Y') * 100 + (int)date('n');

    $alloc = [];
    foreach (ti_client_allocation($clientId)['rows'] as $r) $alloc[$r['id']] = $r;

    $rows = [];
    // (1) rozliczenia wystawione — od miesiąca wpłaty wszystkie, wcześniejsze tylko niedopłacone
    foreach (db_all(
        "SELECT id, year, month, COALESCE(course_id,0) AS course_id, amount, COALESCE(adjustment,0) AS adjustment,
                adjustment_note, invoice_no, invoice_path
         FROM k30_ti_billing
         WHERE client_id=? AND status IN ('issued','paid') AND COALESCE(hours_only,0)=0
         ORDER BY year, month, course_id", [$clientId]) as $b) {
        $k   = (int)$b['year'] * 100 + (int)$b['month'];
        $a   = $alloc[(int)$b['id']] ?? ['due' => (float)$b['amount'] + (float)$b['adjustment'], 'paid' => 0.0];
        $rem = round(max(0, $a['due'] - $a['paid']), 2);
        if ($k < $startK && $rem <= 0.005) continue;
        $rows[] = [
            'key' => 'b' . (int)$b['id'], 'billing_id' => (int)$b['id'], 'course_id' => (int)$b['course_id'],
            'month' => (int)$b['month'], 'year' => (int)$b['year'],
            'amount' => round((float)$b['amount'], 2), 'adjustment' => round((float)$b['adjustment'], 2),
            'adjustment_note' => (string)($b['adjustment_note'] ?? ''),
            'due' => round($a['due'], 2), 'paid' => round($a['paid'], 2), 'remaining' => $rem,
            'to_issue' => false, 'earlier' => $k < $startK,
            'locked' => ti_billing_has_invoice($b) || (bool)ti_debt_transfers_active((int)$b['id']),
        ];
    }
    // (2) zrealizowane zajęcia bez rozliczenia — miesiące od wpłaty do bieżącego
    $have = [];
    foreach ($rows as $r) if (!$r['to_issue']) $have[$r['year'] . '-' . $r['month'] . '-' . $r['course_id']] = true;
    $y = $startY; $m = $startM;
    while ($y * 100 + $m <= $nowK) {
        foreach (tiSubMonthCourses($clientId, $m, $y) as $cid) {
            if (isset($have[$y . '-' . $m . '-' . $cid])) continue;
            if (db_one("SELECT 1 FROM k30_ti_billing WHERE client_id=? AND month=? AND year=? AND course_id=? AND status IN ('issued','paid')",
                       [$clientId, $m, $y, $cid])) continue;   // np. pokryte rozliczenie „tylko godziny”
            if (ti_course_hours_only($cid, sprintf('%04d-%02d', $y, $m))) continue;
            $calc = k30_ti_calculate_billing($clientId, $m, $y, $cid);
            if ((float)$calc['amount'] <= 0.005) continue;
            $rows[] = [
                'key' => 'n' . $y . '-' . $m . '-' . $cid, 'billing_id' => 0, 'course_id' => $cid,
                'month' => $m, 'year' => $y, 'amount' => round((float)$calc['amount'], 2), 'adjustment' => 0.0,
                'adjustment_note' => '', 'due' => round((float)$calc['amount'], 2), 'paid' => 0.0,
                'remaining' => round((float)$calc['amount'], 2), 'to_issue' => true, 'earlier' => false, 'locked' => false,
                'hours' => round((float)$calc['hours_billed'], 2),
            ];
        }
        if (++$m > 12) { $m = 1; $y++; }
    }
    usort($rows, fn($a, $b) => [$a['year'], $a['month'], $a['course_id']] <=> [$b['year'], $b['month'], $b['course_id']]);

    // Wybór grup: domyślnie wszystkie; wcześniejsze zaległości domyślnie poza rozdziałem
    $names = [];
    foreach ($rows as $r) $names[$r['course_id']] = tiSubCourseName($r['course_id']);
    asort($names);
    $left = $amount;
    foreach ($rows as &$r) {
        $r['selected'] = ($courses === null || in_array($r['course_id'], $courses, true)) && !$r['earlier'];
        $take = $r['selected'] ? min($left, $r['remaining']) : 0.0;
        $r['assign'] = round(max(0, $take), 2);
        $left = round($left - $r['assign'], 2);
    }
    unset($r);
    $assigned = round($amount - $left, 2);
    return ['nrb' => tiSubNrb($clientId), 'rows' => $rows, 'courses' => $names, 'amount' => $amount,
            'assigned' => $assigned, 'leftover' => round(max(0, $left), 2), 'date' => $date];
}

/** Liczba z pola formularza („1 234,50” → 1234.5); '' → null. */
function tiSubNum(?string $v): ?float {
    $v = trim(str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v));
    return $v === '' || !is_numeric($v) ? null : round((float)$v, 2);
}

/**
 * Zatwierdzenie rozdziału.
 * $in: date, amount, rows[key] => ['on'=>bool,'assign'=>str,'due'=>str (należność po korekcie, '' = bez zmian),'reason'=>str]
 * @return array{ok:bool, errors:list<string>, issued:int, corrected:int, assigned:float, leftover:float, emailed:bool}
 */
function tiSubApply(int $clientId, array $in, string $by): array {
    $date   = date('Y-m-d', strtotime((string)($in['date'] ?? '')) ?: time());
    $amount = tiSubNum((string)($in['amount'] ?? '')) ?? 0.0;
    $plan   = tiSubPlan($clientId, $date, $amount);
    $nrb    = $plan['nrb'];
    $errors = [];
    if (!$nrb)              $errors[] = 'Kursant nie ma nadanego subkonta (rachunku wirtualnego).';
    if ($amount <= 0)       $errors[] = 'Podaj kwotę wpłaty większą od zera.';
    if ($date > date('Y-m-d')) $errors[] = 'Data wpłaty nie może być z przyszłości.';

    $byKey = [];
    foreach ($plan['rows'] as $r) $byKey[$r['key']] = $r;
    $todo = []; $sum = 0.0;
    foreach ((array)($in['rows'] ?? []) as $key => $f) {
        if (!isset($byKey[$key]) || empty($f['on'])) continue;
        $r      = $byKey[$key];
        $assign = max(0, tiSubNum((string)($f['assign'] ?? '')) ?? 0.0);
        $newDue = tiSubNum((string)($f['due'] ?? ''));
        $reason = mb_substr(trim((string)($f['reason'] ?? '')), 0, 500);
        $label  = tiSubCourseName($r['course_id']) . ', ' . tiSubMonthLabel($r['month'], $r['year']);
        if ($newDue !== null && abs($newDue - $r['due']) > 0.005) {
            if ($newDue < 0)               $errors[] = "{$label}: należność po korekcie nie może być ujemna.";
            elseif ($r['locked'])          $errors[] = "{$label}: rozliczenie ma fakturę albo przeniesioną niedopłatę — korekta tylko w Rozliczeniach kursantów.";
            elseif (mb_strlen($reason) < 5) $errors[] = "{$label}: podaj powód korekty (kilka słów) — trafi do e-maila dla kursanta.";
        } else {
            $newDue = null;
        }
        $cap = $newDue ?? $r['due'];
        if ($assign > round($cap - $r['paid'], 2) + 0.005) $errors[] = "{$label}: przypisano więcej niż pozostało do zapłaty.";
        $sum = round($sum + $assign, 2);
        $todo[] = ['r' => $r, 'assign' => $assign, 'due' => $newDue, 'reason' => $reason, 'label' => $label];
    }
    if ($sum > $amount + 0.005) $errors[] = 'Suma przypisań (' . number_format($sum, 2, ',', ' ') . ' zł) przekracza kwotę wpłaty.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $issued = 0; $corrected = []; $months = [];
        foreach ($todo as &$t) {
            if ($t['r']['to_issue']) {
                $bid = k30_ti_issue_billing($clientId, $t['r']['month'], $t['r']['year'],
                                            'Wystawione przy wpłacie na subkonto z ' . date('d.m.Y', strtotime($date)), $t['r']['course_id']);
                if (!$bid) throw new \RuntimeException($t['label'] . ': nie udało się wystawić rozliczenia.');
                $t['r']['billing_id'] = $bid;
                $b = db_one("SELECT amount, COALESCE(adjustment,0) AS adjustment FROM k30_ti_billing WHERE id=?", [$bid]);
                $t['r']['amount'] = (float)$b['amount']; $t['r']['adjustment'] = (float)$b['adjustment'];
                $months[$t['r']['year'] . '-' . $t['r']['month']] = [$t['r']['month'], $t['r']['year']];
                $issued++;
            }
            if ($t['due'] !== null) {
                $old = round($t['r']['amount'] + $t['r']['adjustment'], 2);
                $adj = round($t['due'] - $t['r']['amount'], 2);
                $pdo->prepare("UPDATE k30_ti_billing SET adjustment=?, adjustment_note=?, adjustment_by_name=?, adjustment_at=datetime('now') WHERE id=?")
                    ->execute([$adj, $t['reason'], $by, $t['r']['billing_id']]);
                $corrected[] = ['label' => $t['label'], 'old' => $old, 'new' => $t['due'], 'reason' => $t['reason']];
            }
        }
        unset($t);
        foreach ($months as [$m, $y]) k30_ti_billing_absorb_combined($clientId, $m, $y);

        $uid  = function_exists('current_user') ? (current_user()['id'] ?? null) : null;
        $base = 'Wpłata na subkonto ' . $nrb . ' z ' . date('d.m.Y', strtotime($date));
        foreach ($todo as $t) {
            if ($t['assign'] <= 0.005) continue;
            db_insert('k30_ti_payments', [
                'client_id' => $clientId, 'course_id' => $t['r']['course_id'], 'amount' => $t['assign'],
                'paid_at' => $date, 'method' => 'transfer', 'note' => mb_substr($base . ' — ' . $t['label'], 0, 500),
                'source_type' => 'billing_match', 'source_id' => $t['r']['billing_id'], 'created_by' => $uid,
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Nie zapisano zmian: ' . $e->getMessage()]];
    }

    $leftover = round($amount - $sum, 2);
    if ($leftover > 0.005) {   // reszta = nadpłata ogólna (ti_payment_add przelicza saldo i powiadamia o nadpłacie)
        ti_payment_add($clientId, $leftover, $date, 'transfer', $base . ' — nadpłata (nieprzypisana)', 'subaccount', 0, 0);
    } else {
        ti_billing_recompute($clientId);
    }
    $emailed = $corrected ? tiSubCorrectionMail($clientId, $corrected) : false;
    return ['ok' => true, 'errors' => [], 'issued' => $issued, 'corrected' => count($corrected),
            'assigned' => $sum, 'leftover' => max(0, $leftover), 'emailed' => $emailed];
}

/** Adresat powiadomień kursanta: małoletni → opiekun (gdy ma e-mail), inaczej kursant. */
function tiSubRecipient(int $clientId): array {
    $c   = db_one("SELECT name, email FROM k30_clients WHERE id=?", [$clientId]) ?: [];
    $acc = db_one("SELECT is_minor, guardian_name, guardian_email FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$clientId]);
    $minor = $acc && !empty($acc['is_minor']) && !empty($acc['guardian_email']);
    return ['email' => $minor ? (string)$acc['guardian_email'] : (string)($c['email'] ?? ''),
            'name'  => $minor ? (string)($acc['guardian_name'] ?: $c['name']) : (string)($c['name'] ?? ''),
            'student' => (string)($c['name'] ?? '')];
}

/**
 * Tekstowy e-mail o ręcznej korekcie rozliczeń (kwota przed / po, powód).
 * $items: list<{label, old, new, reason}>
 */
function tiSubCorrectionMail(int $clientId, array $items): bool {
    $to = tiSubRecipient($clientId);
    if (!filter_var($to['email'], FILTER_VALIDATE_EMAIL) || !$items) return false;
    $zl = fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';
    $lines = [];
    foreach ($items as $i) {
        $lines[] = '- ' . $i['label'] . ': ' . $zl((float)$i['old']) . ' -> ' . $zl((float)$i['new']) . "\n  Powód: " . $i['reason'];
    }
    $org  = function_exists('org_setting') ? (string)(org_setting('org_name') ?: '') : '';
    $text = "Dzień dobry,\n\n"
          . 'informujemy, że w rozliczeniach zajęć' . ($to['student'] !== '' && $to['student'] !== $to['name'] ? ' (uczestnik: ' . $to['student'] . ')' : '')
          . " wprowadziliśmy ręczną korektę:\n\n" . implode("\n", $lines) . "\n\n"
          . "Aktualne saldo i rozliczenia są widoczne w panelu uczestnika. W razie pytań prosimy o odpowiedź na tę wiadomość.\n\n"
          . 'Pozdrawiamy' . ($org !== '' ? "\n" . $org : '') . "\n";
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    mail_queue_add($to['email'], $to['name'], 'Korekta rozliczenia zajęć', nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')),
                   $text, 'ti_billing_correction', $clientId);
    return true;
}

/**
 * Wyciąg z subkonta: wpłaty i należności chronologicznie z saldem narastającym (wpłaty − należności),
 * pozycje opisane grupą i miesiącem; na koniec saldo z alokacji (nadpłata / niedopłata per grupa).
 * @return array{nrb:?string, client:string, lines:list<array>, payments:float, charges:float, balance:array}
 */
function tiSubStatement(int $clientId, string $from = '', string $to = ''): array {
    $from = $from !== '' ? date('Y-m-d', strtotime($from)) : '0000-00-00';
    $to   = $to   !== '' ? date('Y-m-d', strtotime($to))   : '9999-12-31';
    $lines = [];
    foreach (db_all("SELECT p.*, b.month AS b_month, b.year AS b_year FROM k30_ti_payments p
                     LEFT JOIN k30_ti_billing b ON p.source_type='billing_match' AND b.id=p.source_id
                     WHERE p.client_id=? ORDER BY COALESCE(p.paid_at, date(p.created_at)), p.id", [$clientId]) as $p) {
        $d = (string)($p['paid_at'] ?: substr((string)$p['created_at'], 0, 10));
        $what = $p['source_type'] === 'billing_match' && $p['b_month']
              ? 'przypisana: ' . tiSubCourseName((int)$p['course_id']) . ', ' . tiSubMonthLabel((int)$p['b_month'], (int)$p['b_year'])
              : ((int)$p['course_id'] > 0 ? 'na grupę: ' . tiSubCourseName((int)$p['course_id']) : 'ogólna (FIFO)');
        $lines[] = ['date' => $d, 'kind' => 'payment', 'desc' => trim((string)$p['note']) ?: 'Wpłata', 'what' => $what,
                    'in' => round((float)$p['amount'], 2), 'out' => 0.0];
    }
    foreach (db_all("SELECT * FROM k30_ti_billing WHERE client_id=? AND status IN ('issued','paid') AND COALESCE(hours_only,0)=0
                     ORDER BY year, month, id", [$clientId]) as $b) {
        $d = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', (int)$b['year'], (int)$b['month'])));
        $due = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
        if (abs($due) < 0.005) continue;
        $lines[] = ['date' => $d, 'kind' => 'charge', 'desc' => 'Należność za ' . tiSubMonthLabel((int)$b['month'], (int)$b['year']),
                    'what' => tiSubCourseName((int)($b['course_id'] ?? 0))
                              . (abs((float)($b['adjustment'] ?? 0)) > 0.005 ? ' (w tym korekta ' . number_format((float)$b['adjustment'], 2, ',', ' ') . ' zł'
                                 . (trim((string)($b['adjustment_note'] ?? '')) !== '' ? ': ' . $b['adjustment_note'] : '') . ')' : ''),
                    'in' => 0.0, 'out' => $due];
    }
    usort($lines, fn($a, $b) => [$a['date'], $a['kind'] === 'charge'] <=> [$b['date'], $b['kind'] === 'charge']);
    $bal = 0.0; $out = []; $open = 0.0; $sp = 0.0; $sc = 0.0;
    foreach ($lines as $l) {
        $bal = round($bal + $l['in'] - $l['out'], 2);
        if ($l['date'] < $from) { $open = $bal; continue; }
        if ($l['date'] > $to) continue;
        $l['balance'] = $bal; $out[] = $l;
        $sp += $l['in']; $sc += $l['out'];
    }
    $c = db_one("SELECT name FROM k30_clients WHERE id=?", [$clientId]);
    return ['nrb' => tiSubNrb($clientId), 'client' => (string)($c['name'] ?? ''), 'opening' => $open, 'lines' => $out,
            'payments' => round($sp, 2), 'charges' => round($sc, 2), 'balance' => ti_client_group_balances($clientId)];
}
