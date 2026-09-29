<?php
/**
 * includes/ti_payments.php — Księga wpłat i saldo rozliczeń TI (nadpłaty/niedopłaty).
 *
 * Model:
 *   - k30_ti_billing  = NALEŻNOŚCI (charges): kwota + korekta, status issued|paid|cancelled|draft.
 *   - k30_ti_payments = WPŁATY (credits): kwota, data, metoda, źródło (ręcznie / Stripe / PayU).
 *
 * Rozliczenie jest WYLICZANE: wpłaty są alokowane FIFO (od najstarszej należności)
 * na wystawione należności. Każda należność dostaje paid_amount i status:
 *   paid (w pełni pokryta) | issued (częściowo lub wcale → NIEDOPŁATA).
 * Nadwyżka wpłat ponad sumę należności to NADPŁATA (credit) na profilu kursanta i jest
 * automatycznie używana na poczet kolejnych zajęć przy następnym przeliczeniu.
 *
 * Zasada częściowego pokrycia (decyzja): zużywamy całą dostępną nadpłatę, a pozostała
 * kwota pozostaje jako należność oznaczona w panelu jako NIEDOPŁATA.
 *
 * MODEL KOMBINOWANY (osobne rozliczenie na każdą grupę/przedmiot):
 *   Kursant może być w kilku grupach i mieć w każdej inny model rozliczania — każda grupa
 *   dostaje własne rozliczenie (k30_ti_billing.course_id > 0). Wpłata może być ZNACZONA
 *   na grupę (k30_ti_payments.course_id > 0) albo OGÓLNA (course_id = 0).
 *   Alokacja przebiega dwufazowo:
 *     faza 1 — wpłaty znaczone pokrywają FIFO wyłącznie należności swojej grupy;
 *              nadwyżka zostaje jako NADPŁATA TEJ GRUPY (nie przechodzi na inne grupy),
 *     faza 2 — wpłaty ogólne pokrywają FIFO wszystko, co zostało niedopłacone;
 *              nadwyżka to NADPŁATA OGÓLNA konta (użyta na kolejne zajęcia w dowolnej grupie).
 *   Dzięki fazie 2 zachowanie sprzed zmiany (same wpłaty ogólne) pozostaje identyczne.
 */

function ti_payments_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_payments (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        amount          REAL    NOT NULL DEFAULT 0,        -- kwota wpłaty (zł)
        paid_at         DATE,                              -- data wpłaty
        method          TEXT    NOT NULL DEFAULT 'transfer', -- transfer|cash|stripe|payu|other
        note            TEXT    NOT NULL DEFAULT '',
        source_type     TEXT    NOT NULL DEFAULT '',       -- np. stripe|payu|manual
        source_id       INTEGER NOT NULL DEFAULT 0,
        overpay_notified INTEGER NOT NULL DEFAULT 0,        -- czy wysłano mail o nadpłacie
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_pay_client ON k30_ti_payments(client_id)");
    // Model kombinowany: wpłata może być zaksięgowana na konkretną grupę (kurs).
    // 0 = wpłata ogólna na konto kursanta (pokrywa należności FIFO ze wszystkich grup).
    try { $pdo->exec("ALTER TABLE k30_ti_payments ADD COLUMN course_id INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_pay_course ON k30_ti_payments(client_id, course_id)");
    // Należność: ile już pokryto (alokacja FIFO wpłat)
    try { $pdo->exec("ALTER TABLE k30_ti_billing ADD COLUMN paid_amount REAL NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // Zgłoszenia przelewu tradycyjnego przez kursanta — czekają na zaksięgowanie
    // przez kierownika (dopiero wtedy powstaje wpłata w k30_ti_payments).
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_wallet_requests (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id    INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        amount       REAL    NOT NULL DEFAULT 0,
        note         TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'pending',  -- pending|approved|rejected
        declared_by  TEXT    NOT NULL DEFAULT '',          -- kursant|opiekun
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        decided_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        decided_at   DATETIME,
        decide_note  TEXT    NOT NULL DEFAULT '',
        payment_id   INTEGER REFERENCES k30_ti_payments(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_wallet_req_client ON k30_ti_wallet_requests(client_id)");
    // Zgłoszenie dotyczy nadpłaty do końca roku (przelew tradycyjny) — po
    // zatwierdzeniu leci e-mail do adminów o FV, tak jak przy bramkach online.
    try { $pdo->exec("ALTER TABLE k30_ti_wallet_requests ADD COLUMN is_year_end INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Przeniesienia niedopłaty między rozliczeniami (ti_transfer_debt) — rejestr,
    // żeby nie dało się wycofać żadnej ze stron i zgubić kwoty; cofnięcie = reversed_at
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_debt_transfers (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id       INTEGER NOT NULL,
        from_billing_id INTEGER NOT NULL,
        to_billing_id   INTEGER NOT NULL,
        amount          REAL    NOT NULL DEFAULT 0,
        created_by_name TEXT    NOT NULL DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        reversed_at     DATETIME,
        reversed_by_name TEXT   NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_debt_tr_from ON k30_ti_debt_transfers(from_billing_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_debt_tr_to ON k30_ti_debt_transfers(to_billing_id)");

    // Wyczyszczenie rozliczeń kursanta (ti_client_billing_purge) — kopia usuniętych
    // wierszy (JSON), żeby pomyłkę dało się odtworzyć; kto/kiedy/dlaczego
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_billing_purge_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id   INTEGER NOT NULL,
        reason      TEXT    NOT NULL DEFAULT '',
        by_name     TEXT    NOT NULL DEFAULT '',
        billings    INTEGER NOT NULL DEFAULT 0,
        payments    INTEGER NOT NULL DEFAULT 0,
        snapshot    TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Wnioski o przeniesienie płatności na następny miesiąc
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_payment_deferrals (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        billing_id   INTEGER NOT NULL REFERENCES k30_ti_billing(id) ON DELETE CASCADE,
        client_id    INTEGER NOT NULL,
        from_month   INTEGER NOT NULL,
        from_year    INTEGER NOT NULL,
        to_month     INTEGER NOT NULL,
        to_year      INTEGER NOT NULL,
        amount       REAL    NOT NULL DEFAULT 0,
        reason       TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'pending',
        requested_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        decided_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        decided_at   DATETIME,
        decide_note  TEXT    NOT NULL DEFAULT ''
    )");
}

/** Suma wpłat klienta. */
function ti_payments_total(int $client_id): float {
    ti_payments_migrate();
    $r = db_one("SELECT COALESCE(SUM(amount),0) AS s FROM k30_ti_payments WHERE client_id=?", [$client_id]);
    return round((float)($r['s'] ?? 0), 2);
}

/** Należności klienta (wystawione/opłacone, bez anulowanych i wersji roboczych). */
function ti_charges_total(int $client_id): float {
    $r = db_one(
        "SELECT COALESCE(SUM(amount + COALESCE(adjustment,0)),0) AS s
         FROM k30_ti_billing WHERE client_id=? AND status IN ('issued','paid')",
        [$client_id]
    );
    return round((float)($r['s'] ?? 0), 2);
}

/**
 * Wylicza alokację wpłat na należności kursanta — BEZ zapisu do bazy (do raportów i podglądu).
 *
 * Faza 1: wpłaty znaczone na grupę (course_id > 0) pokrywają FIFO tylko należności tej grupy.
 * Faza 2: wpłaty ogólne (course_id = 0) pokrywają FIFO wszystko, co pozostało niedopłacone.
 *
 * @return array{
 *   payments:float, charges:float, paid:float, credit:float, debt:float,
 *   general_credit:float, group_credit:float,
 *   rows:array<int,array{id:int,course_id:int,due:float,paid:float,status:string}>,
 *   groups:array<int,array{charges:float,paid:float,debt:float,payments:float,credit:float}>
 * }
 */
function ti_client_allocation(int $client_id): array {
    ti_payments_migrate();
    $charges = db_all(
        "SELECT id, COALESCE(course_id,0) AS course_id, (amount + COALESCE(adjustment,0)) AS due
         FROM k30_ti_billing
         WHERE client_id=? AND status IN ('issued','paid')
         ORDER BY year ASC, month ASC, id ASC",
        [$client_id]
    );
    $pay_rows = db_all(
        "SELECT COALESCE(course_id,0) AS course_id, COALESCE(SUM(amount),0) AS s
         FROM k30_ti_payments WHERE client_id=? GROUP BY COALESCE(course_id,0)",
        [$client_id]
    );

    $earmarked = [];     // course_id > 0 => kwota wpłat znaczonych
    $general   = 0.0;    // wpłaty ogólne
    $payments  = 0.0;
    foreach ($pay_rows as $pr) {
        $cid = (int)$pr['course_id'];
        $sum = round((float)$pr['s'], 2);
        $payments = round($payments + $sum, 2);
        if ($cid > 0) $earmarked[$cid] = round(($earmarked[$cid] ?? 0) + $sum, 2);
        else          $general = round($general + $sum, 2);
    }

    $rows = [];
    foreach ($charges as $c) {
        $rows[] = ['id' => (int)$c['id'], 'course_id' => (int)$c['course_id'],
                   'due' => round((float)$c['due'], 2), 'paid' => 0.0, 'status' => 'issued'];
    }

    // Faza 1 — wpłaty znaczone na grupę
    $group_credit_map = [];
    foreach ($earmarked as $cid => $sum) {
        $rem = $sum;
        foreach ($rows as $k => $r) {
            if ($rem <= 0) break;
            if ($r['course_id'] !== $cid) continue;
            $need = round($r['due'] - $r['paid'], 2);
            if ($need <= 0) continue;
            $take = min($rem, $need);
            $rows[$k]['paid'] = round($r['paid'] + $take, 2);
            $rem = round($rem - $take, 2);
        }
        $group_credit_map[$cid] = round(max(0, $rem), 2);
    }

    // Faza 2 — wpłaty ogólne, FIFO po wszystkich należnościach
    $rem = $general;
    foreach ($rows as $k => $r) {
        if ($rem <= 0) break;
        $need = round($r['due'] - $r['paid'], 2);
        if ($need <= 0) continue;
        $take = min($rem, $need);
        $rows[$k]['paid'] = round($r['paid'] + $take, 2);
        $rem = round($rem - $take, 2);
    }
    $general_credit = round(max(0, $rem), 2);

    // Statusy + agregacja per grupa
    $groups = [];
    $tot_due = 0.0; $tot_paid = 0.0; $tot_debt = 0.0;
    foreach ($rows as $k => $r) {
        $rows[$k]['status'] = ($r['paid'] + 0.001 >= $r['due']) ? 'paid' : 'issued';
        $cid  = $r['course_id'];
        $debt = round(max(0, $r['due'] - $r['paid']), 2);
        if (!isset($groups[$cid])) $groups[$cid] = ['charges'=>0.0,'paid'=>0.0,'debt'=>0.0,'payments'=>0.0,'credit'=>0.0];
        $groups[$cid]['charges'] = round($groups[$cid]['charges'] + $r['due'], 2);
        $groups[$cid]['paid']    = round($groups[$cid]['paid'] + $r['paid'], 2);
        $groups[$cid]['debt']    = round($groups[$cid]['debt'] + $debt, 2);
        $tot_due  = round($tot_due + $r['due'], 2);
        $tot_paid = round($tot_paid + $r['paid'], 2);
        $tot_debt = round($tot_debt + $debt, 2);
    }
    // Grupy, na które są wpłaty, ale nie ma (jeszcze) należności
    foreach ($earmarked as $cid => $sum) {
        if (!isset($groups[$cid])) $groups[$cid] = ['charges'=>0.0,'paid'=>0.0,'debt'=>0.0,'payments'=>0.0,'credit'=>0.0];
        $groups[$cid]['payments'] = $sum;
        $groups[$cid]['credit']   = $group_credit_map[$cid] ?? 0.0;
    }

    // 'applied' = środki faktycznie przypisane do grupy: pokryte należności + nadpłata grupy.
    // (różni się od 'payments', czyli sumy wpłat ZNACZONYCH na grupę — należność może być
    //  pokryta również wpłatą ogólną).
    foreach ($groups as $gk => $g) $groups[$gk]['applied'] = round($g['paid'] + $g['credit'], 2);

    $group_credit = round(array_sum(array_map(fn($g) => $g['credit'], $groups)), 2);
    return [
        'payments'       => $payments,
        'charges'        => $tot_due,
        'paid'           => $tot_paid,
        'debt'           => $tot_debt,
        'credit'         => round($general_credit + $group_credit, 2),
        'general_credit' => $general_credit,
        'group_credit'   => $group_credit,
        'rows'           => $rows,
        'groups'         => $groups,
    ];
}

/**
 * Alokuje wpłaty na należności (patrz ti_client_allocation) i ZAPISUJE wynik:
 * paid_amount + status na każdym rozliczeniu. Idempotentne.
 * Zwraca ['payments','charges','credit','debt','general_credit','group_credit','groups'].
 */
function ti_billing_recompute(int $client_id): array {
    $a  = ti_client_allocation($client_id);
    $st = db()->prepare("UPDATE k30_ti_billing SET paid_amount=?, status=? WHERE id=?");
    foreach ($a['rows'] as $r) {
        $st->execute([$r['paid'], $r['status'], $r['id']]);
    }
    unset($a['rows']);
    return $a;
}

/**
 * Saldo kursanta w JEDNEJ grupie (model kombinowany).
 * charges = należności grupy, paid = pokryte, debt = niedopłata, credit = nadpłata grupy,
 * payments = wpłaty ZNACZONE na grupę, applied = środki przypisane do grupy (paid + credit).
 * @return array{charges:float,paid:float,debt:float,payments:float,credit:float,applied:float}
 */
function ti_group_balance(int $client_id, int $course_id): array {
    $a = ti_client_allocation($client_id);
    return $a['groups'][$course_id] ?? ['charges'=>0.0,'paid'=>0.0,'debt'=>0.0,'payments'=>0.0,'credit'=>0.0,'applied'=>0.0];
}

/**
 * Rozbicie salda kursanta na grupy + nadpłata ogólna (nieprzypisana do grupy).
 * Grupy dostają nazwy kursów; pozycja course_id=0 to rozliczenia łączne / opłaty poza zajęciami.
 * @return array{groups:array<int,array>,general_credit:float,total:array}
 */
function ti_client_group_balances(int $client_id): array {
    $a    = ti_client_allocation($client_id);
    $out  = [];
    foreach ($a['groups'] as $cid => $g) {
        $name = 'Rozliczenie łączne / opłaty poza zajęciami';
        if ($cid > 0) {
            $r = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid]);
            $name = $r['name'] ?? ('Grupa #' . $cid);
        }
        $out[$cid] = $g + ['course_id' => $cid, 'course_name' => $name];
    }
    uasort($out, fn($x, $y) => strcmp((string)$x['course_name'], (string)$y['course_name']));
    return [
        'groups'         => $out,
        'general_credit' => $a['general_credit'],
        'total'          => ['charges'=>$a['charges'], 'paid'=>$a['paid'], 'debt'=>$a['debt'],
                             'payments'=>$a['payments'], 'credit'=>$a['credit']],
    ];
}

/**
 * Rozliczenia jednej grupy w danym miesiącu dla jednego kursanta
 * (należności i wpłaty zaksięgowane na tę grupę w tym miesiącu).
 * @return array{charges:float,paid:float,payments:float}
 */
function ti_group_month_billing(int $client_id, int $course_id, int $year, int $month): array {
    ti_payments_migrate();
    $ch = db_one(
        "SELECT COALESCE(SUM(amount + COALESCE(adjustment,0)),0) AS charges,
                COALESCE(SUM(COALESCE(paid_amount,0)),0)         AS paid
         FROM k30_ti_billing
         WHERE client_id=? AND COALESCE(course_id,0)=CAST(? AS INTEGER) AND year=? AND month=? AND status IN ('issued','paid')",
        [$client_id, $course_id, $year, $month]
    );
    $pay = db_one(
        "SELECT COALESCE(SUM(amount),0) AS s FROM k30_ti_payments
         WHERE client_id=? AND COALESCE(course_id,0)=CAST(? AS INTEGER) AND strftime('%Y-%m', COALESCE(paid_at, created_at))=?",
        [$client_id, $course_id, sprintf('%04d-%02d', $year, $month)]
    );
    return ['charges'  => round((float)$ch['charges'], 2),
            'paid'     => round((float)$ch['paid'], 2),
            'payments' => round((float)$pay['s'], 2)];
}

/**
 * Przegląd rozliczeń za miesiąc — dane pod pulpit modułu Rozliczenia.
 * Łączy sumy miesiąca (z rozliczeń) z BIEŻĄCYMI saldami grup (z alokacji wpłat).
 *
 * @return array{
 *   kpi:array{charges:float,paid:float,debt:float,credit:float,clients:int,billings:int},
 *   groups:array<int,array>,
 *   debtors:array<int,array>
 * }
 */
function ti_month_overview(int $year, int $month): array {
    ti_payments_migrate();

    // Sumy miesiąca per grupa
    $sums = db_all(
        "SELECT COALESCE(b.course_id,0) AS course_id,
                COUNT(DISTINCT b.client_id)                    AS participants,
                COUNT(*)                                       AS billings,
                COALESCE(SUM(b.amount + COALESCE(b.adjustment,0)),0) AS charges,
                COALESCE(SUM(COALESCE(b.paid_amount,0)),0)     AS paid,
                SUM(CASE WHEN COALESCE(b.invoice_path,'')='' THEN 1 ELSE 0 END) AS no_invoice
         FROM k30_ti_billing b
         WHERE b.year=? AND b.month=? AND b.status IN ('issued','paid')
         GROUP BY COALESCE(b.course_id,0)",
        [$year, $month]
    );

    $groups = [];
    $kpi = ['charges'=>0.0,'paid'=>0.0,'debt'=>0.0,'credit'=>0.0,'clients'=>0,'billings'=>0];
    foreach ($sums as $r) {
        $cid  = (int)$r['course_id'];
        $name = 'Rozliczenia łączne / opłaty poza zajęciami';
        $code = '';
        if ($cid > 0) {
            $c = db_one("SELECT name, COALESCE(group_code,'') AS group_code FROM k30_ti_courses WHERE id=?", [$cid]);
            $name = (string)($c['name'] ?? ('Grupa #' . $cid));
            $code = (string)($c['group_code'] ?? '');
        }
        $groups[$cid] = [
            'course_id'    => $cid,
            'course_name'  => $name,
            'group_code'   => $code,
            'participants' => (int)$r['participants'],
            'billings'     => (int)$r['billings'],
            'no_invoice'   => (int)$r['no_invoice'],
            'charges'      => round((float)$r['charges'], 2),
            'paid'         => round((float)$r['paid'], 2),
            'debt'         => 0.0,   // bieżące saldo grupy — z alokacji poniżej
            'credit'       => 0.0,
        ];
        $kpi['charges']  = round($kpi['charges'] + (float)$r['charges'], 2);
        $kpi['paid']     = round($kpi['paid'] + (float)$r['paid'], 2);
        $kpi['billings'] += (int)$r['billings'];
    }

    // Bieżące salda — alokacja per kursant rozliczany w tym miesiącu
    $clients = db_all(
        "SELECT DISTINCT b.client_id, cl.name
         FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
         WHERE b.year=? AND b.month=? AND b.status IN ('issued','paid') ORDER BY cl.name",
        [$year, $month]
    );
    $kpi['clients'] = count($clients);
    $debtors = [];
    foreach ($clients as $c) {
        $cid_client = (int)$c['client_id'];
        $a = ti_client_allocation($cid_client);
        $kpi['debt']   = round($kpi['debt'] + $a['debt'], 2);
        $kpi['credit'] = round($kpi['credit'] + $a['credit'], 2);
        foreach ($a['groups'] as $gc => $g) {
            if (!isset($groups[$gc])) continue;   // grupa spoza tego miesiąca
            $groups[$gc]['debt']   = round($groups[$gc]['debt'] + $g['debt'], 2);
            $groups[$gc]['credit'] = round($groups[$gc]['credit'] + $g['credit'], 2);
        }
        if ($a['debt'] > 0.005) {
            $debtors[$cid_client] = ['client_id'=>$cid_client, 'client_name'=>(string)$c['name'],
                                     'debt'=>$a['debt'], 'credit'=>$a['credit']];
        }
    }
    uasort($debtors, fn($x, $y) => $y['debt'] <=> $x['debt']);
    uasort($groups,  fn($x, $y) => strcmp((string)$x['course_name'], (string)$y['course_name']));

    return ['kpi' => $kpi, 'groups' => $groups, 'debtors' => $debtors];
}

/**
 * Rozliczenia jednej grupy w całym roku dla jednego kursanta.
 * @return array{charges:float,paid:float,payments:float}
 */
function ti_group_year_billing(int $client_id, int $course_id, int $year): array {
    ti_payments_migrate();
    $ch = db_one(
        "SELECT COALESCE(SUM(amount + COALESCE(adjustment,0)),0) AS charges,
                COALESCE(SUM(COALESCE(paid_amount,0)),0)         AS paid
         FROM k30_ti_billing
         WHERE client_id=? AND COALESCE(course_id,0)=CAST(? AS INTEGER) AND year=? AND status IN ('issued','paid')",
        [$client_id, $course_id, $year]
    );
    $pay = db_one(
        "SELECT COALESCE(SUM(amount),0) AS s FROM k30_ti_payments
         WHERE client_id=? AND COALESCE(course_id,0)=CAST(? AS INTEGER) AND strftime('%Y', COALESCE(paid_at, created_at))=?",
        [$client_id, $course_id, (string)$year]
    );
    return ['charges'  => round((float)$ch['charges'], 2),
            'paid'     => round((float)$ch['paid'], 2),
            'payments' => round((float)$pay['s'], 2)];
}

/**
 * Zbiorcze rozliczenie GRUPY: uczestnicy + sumy należności/wpłat/nadpłat/niedopłat.
 * $year/$month = 0 → tylko salda bieżące (bez części miesięcznej).
 * @return array{participants:array<int,array>,totals:array}
 */
function ti_course_billing_summary(int $course_id, int $year = 0, int $month = 0): array {
    ti_payments_migrate();
    $rows = db_all(
        "SELECT DISTINCT cl.id AS client_id, cl.name AS client_name
         FROM k30_clients cl
         WHERE cl.id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active')
            OR cl.id IN (SELECT client_id FROM k30_ti_billing WHERE COALESCE(course_id,0)=CAST(? AS INTEGER) AND status IN ('issued','paid'))
         ORDER BY cl.name",
        [$course_id, $course_id]
    );
    $participants = [];
    $totals = ['charges'=>0.0,'paid'=>0.0,'debt'=>0.0,'credit'=>0.0,
               'm_charges'=>0.0,'m_paid'=>0.0,'m_payments'=>0.0];
    foreach ($rows as $r) {
        $cid = (int)$r['client_id'];
        $g   = ti_group_balance($cid, $course_id);
        $m   = ($year && $month) ? ti_group_month_billing($cid, $course_id, $year, $month)
                                 : ['charges'=>0.0,'paid'=>0.0,'payments'=>0.0];
        $participants[$cid] = [
            'client_id'   => $cid,
            'client_name' => (string)$r['client_name'],
            'charges'     => $g['charges'], 'paid' => $g['paid'],
            'debt'        => $g['debt'],    'credit' => $g['credit'],
            'payments'    => $g['payments'],
            'm_charges'   => $m['charges'], 'm_paid' => $m['paid'], 'm_payments' => $m['payments'],
        ];
        foreach (['charges','paid','debt','credit'] as $k) $totals[$k] = round($totals[$k] + $g[$k], 2);
        $totals['m_charges']  = round($totals['m_charges']  + $m['charges'], 2);
        $totals['m_paid']     = round($totals['m_paid']     + $m['paid'], 2);
        $totals['m_payments'] = round($totals['m_payments'] + $m['payments'], 2);
    }
    return ['participants' => $participants, 'totals' => $totals];
}

/**
 * Dopisuje należność do rozliczenia klienta za dany miesiąc (np. usługa dodatkowa poza zajęciami:
 * dedykowane IP VLAB). Jeśli rozliczenie za ten miesiąc już istnieje (np. za zajęcia), kwota trafia
 * do pola `adjustment` (korekta/dopłata) — tak jak ręczne korekty admina. W przeciwnym razie tworzy
 * nowy wiersz k30_ti_billing z amount=0 i całą kwotą w adjustment. Przelicza saldo klienta.
 * @return int id wiersza k30_ti_billing
 */
function ti_billing_add_charge(int $clientId, float $amount, string $note, ?int $month = null, ?int $year = null): int {
    ti_payments_migrate();
    $month  = $month ?? (int)date('n');
    $year   = $year  ?? (int)date('Y');
    $amount = round($amount, 2);

    // Tylko żywe rozliczenie łączne — anulowane (np. zastąpione rozliczeniami per grupa) pomijamy,
    // żeby opłata spoza zajęć nie wylądowała na niewidocznym wierszu.
    $existing = db_one("SELECT id, adjustment, adjustment_note FROM k30_ti_billing
                        WHERE client_id=? AND month=? AND year=? AND COALESCE(course_id,0)=0
                          AND status IN ('draft','issued','paid')", [$clientId, $month, $year]);
    if ($existing) {
        $id       = (int)$existing['id'];
        $newAdj   = round((float)$existing['adjustment'] + $amount, 2);
        $prevNote = trim((string)($existing['adjustment_note'] ?? ''));
        $newNote  = $prevNote !== '' ? $prevNote . ' · ' . $note : $note;
        db()->prepare("UPDATE k30_ti_billing SET adjustment=?, adjustment_note=? WHERE id=?")
            ->execute([$newAdj, mb_substr($newNote, 0, 500), $id]);
    } else {
        $id = db_insert('k30_ti_billing', [
            'client_id'       => $clientId,
            'month'           => $month,
            'year'            => $year,
            'hours_billed'    => 0,
            'hourly_rate'     => 0,
            'amount'          => 0,
            'adjustment'      => $amount,
            'adjustment_note' => mb_substr($note, 0, 500),
            'status'          => 'issued',
            'issued_at'       => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }
    ti_billing_recompute($clientId);
    return $id;
}

/**
 * Saldo klienta (bez zapisu do bazy): nadpłata (credit) lub niedopłata (debt).
 * Liczone z alokacji świadomej grup — przy wpłatach znaczonych na grupę kursant może
 * jednocześnie mieć nadpłatę w jednej grupie i niedopłatę w innej.
 */
function ti_client_balance(int $client_id): array {
    $a = ti_client_allocation($client_id);
    return [
        'payments'       => $a['payments'],
        'charges'        => $a['charges'],
        'balance'        => round($a['payments'] - $a['charges'], 2),
        'credit'         => $a['credit'],          // nadpłata (grupowa + ogólna)
        'debt'           => $a['debt'],            // niedopłata (nie pokryte należności)
        'general_credit' => $a['general_credit'],  // nadpłata do wykorzystania w dowolnej grupie
        'group_credit'   => $a['group_credit'],    // nadpłata przypisana do konkretnych grup
        'groups'         => $a['groups'],
    ];
}

/** Lista wpłat klienta (najnowsze pierwsze). */
function ti_payments_for_client(int $client_id): array {
    ti_payments_migrate();
    return db_all("SELECT * FROM k30_ti_payments WHERE client_id=? ORDER BY COALESCE(paid_at, created_at) DESC, id DESC", [$client_id]);
}

/**
 * Rejestruje wpłatę, przelicza saldo i — jeśli powstała nadpłata — wysyła mail.
 * @return array ['payment_id','credit','debt','emailed'=>bool]
 */
function ti_payment_add(int $client_id, float $amount, string $paid_at = '', string $method = 'transfer',
                        string $note = '', string $source_type = 'manual', int $source_id = 0,
                        int $course_id = 0): array {
    ti_payments_migrate();
    $amount = round($amount, 2);
    $credit_before = ti_client_balance($client_id)['credit'];

    $pid = db_insert('k30_ti_payments', [
        'client_id'   => $client_id,
        'course_id'   => max(0, $course_id),   // >0 = wpłata znaczona na grupę
        'amount'      => $amount,
        'paid_at'     => $paid_at !== '' ? $paid_at : date('Y-m-d'),
        'method'      => $method,
        'note'        => mb_substr($note, 0, 500),
        'source_type' => $source_type,
        'source_id'   => $source_id,
        'created_by'  => function_exists('current_user') ? (current_user()['id'] ?? null) : null,
    ]);

    $res    = ti_billing_recompute($client_id);
    $credit = $res['credit'];
    $emailed = false;

    // Nadpłata powstała/wzrosła wskutek tej wpłaty → powiadom opiekuna/kursanta
    if ($credit > 0 && $credit + 0.001 >= $credit_before && $amount > 0) {
        $emailed = ti_payment_notify_overpay($client_id, $amount, $credit, $pid, max(0, $course_id));
    }
    return ['payment_id' => $pid, 'credit' => $credit, 'debt' => $res['debt'], 'emailed' => $emailed];
}

// ── Przenoszenie salda między grupami i okresami (kierownik) ─────────────────
//
// Nadpłata: para wpisów wewnętrznych w k30_ti_payments (method='internal',
// source_type='transfer') — minus w grupie źródłowej, plus w docelowej; suma
// wpłat kursanta się nie zmienia, zmienia się tylko przypisanie do grupy.
// Niedopłata: korekta (adjustment) — minus na rozliczeniu źródłowym (inny
// okres / grupa), plus na docelowym; należność łączna bez zmian. Rozliczeń
// z fakturą nie ruszamy (kwota dokumentu księgowego musi zostać).

/** Czy z rozliczenia jest faktura (produkcyjna, zarejestrowany numer albo skan). */
function ti_billing_has_invoice(array $b): bool {
    if (trim((string)($b['invoice_no'] ?? '')) !== '' || trim((string)($b['invoice_path'] ?? '')) !== '') return true;
    try {
        return (bool)db_one("SELECT 1 FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [(int)$b['id']]);
    } catch (\Throwable $e) { return false; }
}

/** Stawka godzinowa rozliczenia: hourly_rate, a gdy brak (model indywidualny) — kwota / godziny. */
function ti_billing_rate(array $b): float {
    if ((float)($b['hourly_rate'] ?? 0) > 0.005) return round((float)$b['hourly_rate'], 2);
    $h = (float)($b['hours_billed'] ?? 0);
    return $h > 0.005 ? round((float)$b['amount'] / $h, 2) : 0.0;
}

/** Dopisek „≈ 2,5 h po 80,00 zł/h” do opisu przeniesienia (pusty, gdy nie znamy stawki). */
function ti_transfer_hours_note(float $amount, float $rate): string {
    if ($rate <= 0.005) return '';
    return ' ≈ ' . rtrim(rtrim(number_format($amount / $rate, 2, ',', ''), '0'), ',') . ' h po ' . number_format($rate, 2, ',', ' ') . ' zł/h';
}

/** Nazwa grupy do opisu (0 = konto ogólne / rozliczenie łączne). */
function ti_transfer_group_label(int $course_id): string {
    if ($course_id <= 0) return 'konto ogólne';
    $r = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
    return (string)($r['name'] ?? ('grupa #' . $course_id));
}

/**
 * Co można przenieść na rozliczenie $billing_id: nadpłaty innych grup (i
 * ogólną) oraz niedopłaty innych rozliczeń tego kursanta.
 * rate = stawka do przeliczenia na godziny: dla nadpłaty stawka rozliczenia docelowego
 * (ile jego godzin pokryje), dla niedopłaty stawka rozliczenia źródłowego (ile godzin nieopłaconych).
 * @return array{credits:list<array{course_id:int,label:string,amount:float,rate:float}>,debts:list<array{billing_id:int,label:string,amount:float,rate:float}>}
 */
function ti_balance_transfer_sources(int $billing_id): array {
    $out = ['credits' => [], 'debts' => []];
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=? AND status IN ('issued','paid')", [$billing_id]);
    if (!$b) return $out;
    $client = (int)$b['client_id']; $to_course = (int)$b['course_id'];
    $to_rate = ti_billing_rate($b);
    $a = ti_client_allocation($client);
    if ($to_course > 0 && $a['general_credit'] > 0.005)
        $out['credits'][] = ['course_id' => 0, 'label' => 'nadpłata ogólna (konto)', 'amount' => $a['general_credit'], 'rate' => $to_rate];
    foreach ($a['groups'] as $cid => $g) {
        if ((int)$cid === $to_course || $cid <= 0 || $g['credit'] <= 0.005) continue;
        $out['credits'][] = ['course_id' => (int)$cid, 'label' => ti_transfer_group_label((int)$cid), 'amount' => $g['credit'], 'rate' => $to_rate];
    }
    if (!ti_billing_has_invoice($b)) {
        $pl = [1=>'sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
        $paid = []; foreach ($a['rows'] as $r) $paid[$r['id']] = $r['paid'];
        foreach (db_all("SELECT * FROM k30_ti_billing WHERE client_id=? AND id!=? AND status='issued' ORDER BY year, month, id", [$client, $billing_id]) as $o) {
            $debt = round((float)$o['amount'] + (float)$o['adjustment'] - ($paid[(int)$o['id']] ?? 0), 2);
            if ($debt <= 0.005 || ti_billing_has_invoice($o)) continue;
            $out['debts'][] = ['billing_id' => (int)$o['id'], 'amount' => $debt, 'rate' => ti_billing_rate($o),
                'label' => ($pl[(int)$o['month']] ?? $o['month']) . ' ' . $o['year'] . ' · ' . ti_transfer_group_label((int)$o['course_id'])];
        }
    }
    return $out;
}

/** Przenosi nadpłatę z grupy $from_course (0 = ogólna) na grupę rozliczenia $to_billing_id. Zwraca komunikat błędu albo null. */
function ti_transfer_credit(int $to_billing_id, int $from_course, float $amount, string $by = ''): ?string {
    $amount = round($amount, 2);
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=? AND status IN ('issued','paid')", [$to_billing_id]);
    if (!$b) return 'Nie znaleziono rozliczenia.';
    $src = null;
    foreach (ti_balance_transfer_sources($to_billing_id)['credits'] as $c) if ($c['course_id'] === $from_course) $src = $c;
    if (!$src) return 'W tej grupie nie ma nadpłaty do przeniesienia.';
    if ($amount <= 0 || $amount > $src['amount'] + 0.001) return 'Kwota musi być większa od zera i nie większa niż nadpłata (' . number_format($src['amount'], 2, ',', ' ') . ' zł).';
    $client = (int)$b['client_id']; $to_course = (int)$b['course_id'];
    $note = 'Przeniesienie nadpłaty: ' . ti_transfer_group_label($from_course) . ' → ' . ti_transfer_group_label($to_course)
          . ti_transfer_hours_note($amount, (float)$src['rate']) . ($by !== '' ? ' (' . $by . ')' : '');
    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ([[$from_course, -$amount], [$to_course, $amount]] as [$cid, $amt]) {
            db_insert('k30_ti_payments', ['client_id' => $client, 'course_id' => $cid, 'amount' => $amt, 'paid_at' => date('Y-m-d'),
                'method' => 'internal', 'note' => mb_substr($note, 0, 500), 'source_type' => 'transfer', 'source_id' => $to_billing_id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd zapisu: ' . $e->getMessage(); }
    ti_billing_recompute($client);
    return null;
}

/** Przenosi niedopłatę z rozliczenia $from_billing_id (inny okres / grupa) na $to_billing_id. Zwraca komunikat błędu albo null. */
function ti_transfer_debt(int $to_billing_id, int $from_billing_id, float $amount, string $by = ''): ?string {
    $amount = round($amount, 2);
    $src = null;
    foreach (ti_balance_transfer_sources($to_billing_id)['debts'] as $d) if ($d['billing_id'] === $from_billing_id) $src = $d;
    if (!$src) return 'Tej niedopłaty nie można przenieść (rozliczenie opłacone, wycofane albo z fakturą — także docelowe nie może mieć faktury).';
    if ($amount <= 0 || $amount > $src['amount'] + 0.001) return 'Kwota musi być większa od zera i nie większa niż niedopłata (' . number_format($src['amount'], 2, ',', ' ') . ' zł).';
    $to   = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$to_billing_id]);
    $from = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$from_billing_id]);
    $pl = [1=>'sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
    $lbl = fn(array $x) => ($pl[(int)$x['month']] ?? $x['month']) . ' ' . $x['year'] . ' · ' . ti_transfer_group_label((int)$x['course_id']);
    $sfx = ti_transfer_hours_note($amount, (float)$src['rate']) . ($by !== '' ? ' (' . $by . ')' : '');
    $up  = db()->prepare("UPDATE k30_ti_billing SET adjustment=ROUND(COALESCE(adjustment,0)+?,2),
            adjustment_note=CASE WHEN COALESCE(adjustment_note,'')='' THEN ? ELSE substr(adjustment_note || ' · ' || ?, 1, 500) END WHERE id=?");
    $pdo = db(); $pdo->beginTransaction();
    try {
        $n1 = 'Niedopłata ' . number_format($amount, 2, ',', ' ') . ' zł przeniesiona na ' . $lbl($to) . $sfx;
        $n2 = 'Niedopłata ' . number_format($amount, 2, ',', ' ') . ' zł przeniesiona z ' . $lbl($from) . $sfx;
        $up->execute([-$amount, $n1, $n1, $from_billing_id]);
        $up->execute([$amount, $n2, $n2, $to_billing_id]);
        db_insert('k30_ti_debt_transfers', ['client_id' => (int)$to['client_id'], 'from_billing_id' => $from_billing_id,
            'to_billing_id' => $to_billing_id, 'amount' => $amount, 'created_by_name' => $by]);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd zapisu: ' . $e->getMessage(); }
    ti_billing_recompute((int)$to['client_id']);
    return null;
}

/**
 * Aktywne (niecofnięte) przeniesienia niedopłaty, w których rozliczenie jest
 * źródłem albo celem — z opisem drugiej strony. Blokują wycofanie rozliczenia.
 * @return list<array{id:int,amount:float,dir:string,other_id:int,other_label:string,created_at:string,created_by_name:string}>
 */
function ti_debt_transfers_active(int $billing_id): array {
    ti_payments_migrate();
    $pl  = [1=>'sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_debt_transfers WHERE reversed_at IS NULL AND (from_billing_id=? OR to_billing_id=?) ORDER BY id",
                    [$billing_id, $billing_id]) as $t) {
        $in    = (int)$t['to_billing_id'] === $billing_id;
        $other = (int)($in ? $t['from_billing_id'] : $t['to_billing_id']);
        $o     = db_one("SELECT month, year, course_id FROM k30_ti_billing WHERE id=?", [$other]);
        $out[] = ['id' => (int)$t['id'], 'amount' => (float)$t['amount'], 'dir' => $in ? 'in' : 'out', 'other_id' => $other,
                  'other_label' => $o ? (($pl[(int)$o['month']] ?? $o['month']) . ' ' . $o['year'] . ' · ' . ti_transfer_group_label((int)$o['course_id'])) : ('#' . $other),
                  'created_at' => (string)$t['created_at'], 'created_by_name' => (string)$t['created_by_name']];
    }
    return $out;
}

/** Cofa przeniesienie niedopłaty (korekta odwrotna na obu rozliczeniach). Zwraca komunikat błędu albo null. */
function ti_transfer_debt_reverse(int $transfer_id, string $by = ''): ?string {
    ti_payments_migrate();
    $t = db_one("SELECT * FROM k30_ti_debt_transfers WHERE id=? AND reversed_at IS NULL", [$transfer_id]);
    if (!$t) return 'Nie znaleziono przeniesienia (albo zostało już cofnięte).';
    $from = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [(int)$t['from_billing_id']]);
    $to   = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [(int)$t['to_billing_id']]);
    foreach ([$from, $to] as $x) {
        if (!$x) return 'Jedno z rozliczeń przeniesienia już nie istnieje.';
        if (ti_billing_has_invoice($x)) return 'Z jednego z rozliczeń wystawiono już fakturę — przeniesienia nie można cofnąć (potrzebna korekta faktury).';
    }
    $amount = round((float)$t['amount'], 2);
    $note   = 'Cofnięto przeniesienie niedopłaty ' . number_format($amount, 2, ',', ' ') . ' zł' . ($by !== '' ? ' (' . $by . ')' : '');
    $up  = db()->prepare("UPDATE k30_ti_billing SET adjustment=ROUND(COALESCE(adjustment,0)+?,2),
            adjustment_note=CASE WHEN COALESCE(adjustment_note,'')='' THEN ? ELSE substr(adjustment_note || ' · ' || ?, 1, 500) END WHERE id=?");
    $pdo = db(); $pdo->beginTransaction();
    try {
        $up->execute([$amount, $note, $note, (int)$from['id']]);
        $up->execute([-$amount, $note, $note, (int)$to['id']]);
        db()->prepare("UPDATE k30_ti_debt_transfers SET reversed_at=datetime('now'), reversed_by_name=? WHERE id=?")->execute([$by, $transfer_id]);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd zapisu: ' . $e->getMessage(); }
    ti_billing_recompute((int)$t['client_id']);
    return null;
}

/**
 * Co obejmie wyczyszczenie rozliczeń kursanta + blokady. Rozliczeń z fakturą
 * (produkcyjna, numer, skan) nie usuwamy nigdy — dokument księgowy musi mieć
 * podstawę; wpłaty z bramek online (Stripe/PayU/P24) tylko na wyraźne życzenie.
 * @return array{billings:list<array>, payments:list<array>, gateway:list<array>, invoiced:list<array>}
 */
function ti_client_billing_purge_scope(int $client_id): array {
    ti_payments_migrate();
    $bills = db_all("SELECT * FROM k30_ti_billing WHERE client_id=? ORDER BY year, month, id", [$client_id]);
    $inv = [];
    foreach ($bills as $b) if (ti_billing_has_invoice($b)) $inv[] = $b;
    $pays = db_all("SELECT * FROM k30_ti_payments WHERE client_id=? ORDER BY id", [$client_id]);
    $gw   = array_values(array_filter($pays, fn($p) => in_array((string)$p['method'], ['stripe', 'payu', 'p24'], true)
                                               || in_array((string)$p['source_type'], ['stripe', 'payu', 'p24'], true)));
    return ['billings' => $bills, 'payments' => $pays, 'gateway' => $gw, 'invoiced' => $inv];
}

/**
 * Czyści rozliczenia kursanta: wszystkie należności (k30_ti_billing z wnioskami
 * o przeniesienie, przeniesieniami niedopłaty, fakturami demo i skanami) i —
 * gdy $with_payments — wpłaty (bez bramek online, chyba że $with_gateway).
 * Przed usunięciem kopia wierszy do k30_ti_billing_purge_log. Zwraca błąd albo null.
 */
function ti_client_billing_purge(int $client_id, bool $with_payments, bool $with_gateway, string $reason, string $by): ?string {
    $sc = ti_client_billing_purge_scope($client_id);
    if ($sc['invoiced']) return 'Nie można wyczyścić: ' . count($sc['invoiced']) . ' rozliczeń ma fakturę (np. '
        . sprintf('%02d/%d', (int)$sc['invoiced'][0]['month'], (int)$sc['invoiced'][0]['year']) . ') — faktura wymaga korekty, nie usunięcia podstawy.';
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód wyczyszczenia rozliczeń.';
    $gw_ids = array_map(fn($p) => (int)$p['id'], $sc['gateway']);
    $pays   = $with_payments ? array_values(array_filter($sc['payments'], fn($p) => $with_gateway || !in_array((int)$p['id'], $gw_ids, true))) : [];
    $bids   = array_map(fn($b) => (int)$b['id'], $sc['billings']);
    $pids   = array_map(fn($p) => (int)$p['id'], $pays);
    $in     = fn(array $ids) => implode(',', array_fill(0, count($ids), '?'));
    $pdo = db(); $pdo->beginTransaction();
    try {
        $snap = ['billings' => $sc['billings'], 'payments' => $pays,
                 'deferrals' => $bids ? db_all("SELECT * FROM k30_ti_payment_deferrals WHERE billing_id IN (" . $in($bids) . ")", $bids) : [],
                 'debt_transfers' => db_all("SELECT * FROM k30_ti_debt_transfers WHERE client_id=?", [$client_id])];
        db_insert('k30_ti_billing_purge_log', ['client_id' => $client_id, 'reason' => trim($reason), 'by_name' => $by,
            'billings' => count($bids), 'payments' => count($pids), 'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        if ($bids) {
            try {
                db()->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE source='ti_billing' AND is_test=1 AND source_id IN (" . $in($bids) . "))")->execute($bids);
                db()->prepare("DELETE FROM invoices WHERE source='ti_billing' AND is_test=1 AND source_id IN (" . $in($bids) . ")")->execute($bids);
            } catch (\Throwable $e) {}   // moduł faktur wyłączony — brak tabel
            db()->prepare("DELETE FROM k30_ti_payment_deferrals WHERE billing_id IN (" . $in($bids) . ")")->execute($bids);
            db()->prepare("DELETE FROM k30_ti_billing WHERE id IN (" . $in($bids) . ")")->execute($bids);
        }
        db()->prepare("DELETE FROM k30_ti_debt_transfers WHERE client_id=?")->execute([$client_id]);
        if ($pids) db()->prepare("DELETE FROM k30_ti_payments WHERE id IN (" . $in($pids) . ")")->execute($pids);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
    ti_billing_recompute($client_id);
    return null;
}

/** Usuwa wpłatę i przelicza saldo (korekta admina). */
function ti_payment_delete(int $payment_id): void {
    ti_payments_migrate();
    $p = db_one("SELECT * FROM k30_ti_payments WHERE id=?", [$payment_id]);
    if (!$p) return;
    db()->prepare("DELETE FROM k30_ti_payments WHERE id=?")->execute([$payment_id]);
    // Przeniesienie nadpłaty to para wpisów — usuwamy też drugą nogę, żeby suma się zgadzała
    if (($p['source_type'] ?? '') === 'transfer') {
        db()->prepare("DELETE FROM k30_ti_payments WHERE id=(SELECT id FROM k30_ti_payments
                        WHERE client_id=? AND source_type='transfer' AND source_id=? AND ABS(amount + ?) < 0.001
                          AND created_at=? AND id!=? LIMIT 1)")
            ->execute([(int)$p['client_id'], (int)$p['source_id'], (float)$p['amount'], (string)$p['created_at'], $payment_id]);
    }
    ti_billing_recompute((int)$p['client_id']);
}

// ── Zgłoszenia przelewu tradycyjnego (kursant/opiekun → akceptacja kierownika) ──

/** Kursant/opiekun deklaruje przelew — czeka na zaksięgowanie przez kierownika. */
function ti_wallet_request_add(int $client_id, float $amount, string $note, string $declared_by = 'kursant', bool $is_year_end = false): int {
    ti_payments_migrate();
    return db_insert('k30_ti_wallet_requests', [
        'client_id'   => $client_id,
        'amount'      => round($amount, 2),
        'note'        => mb_substr(trim($note), 0, 500),
        'status'      => 'pending',
        'declared_by' => $declared_by,
        'is_year_end' => $is_year_end ? 1 : 0,
    ]);
}

/** Zatwierdza zgłoszenie — księguje wpłatę (ogólną, metoda „przelew") i przelicza saldo. */
function ti_wallet_request_approve(int $id, int $decided_by, string $note = ''): bool {
    ti_payments_migrate();
    $r = db_one("SELECT * FROM k30_ti_wallet_requests WHERE id=? AND status='pending'", [$id]);
    if (!$r) return false;
    $is_year_end = !empty($r['is_year_end']);
    $desc = $is_year_end
        ? trim('Nadpłata do końca roku (przelew)' . ($r['note'] !== '' ? ' — ' . $r['note'] : ''))
        : trim('Zgłoszenie kursanta' . ($r['note'] !== '' ? ' — ' . $r['note'] : ''));
    $pay = ti_payment_add((int)$r['client_id'], (float)$r['amount'], '', 'transfer', $desc, 'manual', 0, 0);
    db()->prepare(
        "UPDATE k30_ti_wallet_requests SET status='approved', decided_by=?, decided_at=datetime('now'), decide_note=?, payment_id=? WHERE id=?"
    )->execute([$decided_by, mb_substr(trim($note), 0, 500), $pay['payment_id'], $id]);
    if ($is_year_end) {
        ti_year_end_overpay_notify_admin((int)$r['client_id'], (float)$r['amount'], 'transfer');
    }
    return true;
}

/** Odrzuca zgłoszenie (np. wpłata nie dotarła). */
function ti_wallet_request_reject(int $id, int $decided_by, string $note = ''): bool {
    ti_payments_migrate();
    $r = db_one("SELECT id FROM k30_ti_wallet_requests WHERE id=? AND status='pending'", [$id]);
    if (!$r) return false;
    db()->prepare(
        "UPDATE k30_ti_wallet_requests SET status='rejected', decided_by=?, decided_at=datetime('now'), decide_note=? WHERE id=?"
    )->execute([$decided_by, mb_substr(trim($note), 0, 500), $id]);
    return true;
}

/** Oczekujące zgłoszenia — dla panelu kierownika. */
function ti_wallet_requests_pending(): array {
    ti_payments_migrate();
    return db_all(
        "SELECT w.*, cl.name AS client_name FROM k30_ti_wallet_requests w
         JOIN k30_clients cl ON cl.id=w.client_id
         WHERE w.status='pending' ORDER BY w.created_at"
    );
}

/** Zgłoszenia jednego klienta (najnowsze pierwsze) — dla portfela kursanta. */
function ti_wallet_requests_for_client(int $client_id): array {
    ti_payments_migrate();
    return db_all("SELECT * FROM k30_ti_wallet_requests WHERE client_id=? ORDER BY created_at DESC", [$client_id]);
}

/**
 * E-mail o zaksięgowaniu nadpłaty (kwota wpłaty, wysokość nadpłaty, info o auto-użyciu)
 * + podziękowanie od Fundacji FEER. Adresat: opiekun (małoletni) lub kursant.
 */
function ti_payment_notify_overpay(int $client_id, float $payment_amount, float $credit, int $payment_id = 0, int $course_id = 0): bool {
    $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) ?: [];
    $acc    = db_one("SELECT is_minor, guardian_name, guardian_email FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$client_id]);
    $minor  = $acc && !empty($acc['is_minor']);
    $email  = $minor && !empty($acc['guardian_email']) ? $acc['guardian_email'] : (string)($client['email'] ?? '');
    $toName = $minor && !empty($acc['guardian_name'])  ? $acc['guardian_name']  : (string)($client['name'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    require_once __DIR__ . '/mail_queue.php';
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER';
    $wp    = number_format($payment_amount, 2, ',', ' ');
    $cr    = number_format($credit, 2, ',', ' ');
    $portal = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/login.php';
    // Wpłata znaczona na grupę → nadpłata dotyczy tylko tej grupy
    $grp_name = '';
    if ($course_id > 0) {
        $gr = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
        $grp_name = (string)($gr['name'] ?? '');
    }
    $grp_txt  = $grp_name !== '' ? ' w grupie <strong>' . h($grp_name) . '</strong>' : '';
    $grp_next = $grp_name !== '' ? ' w tej grupie' : '';
    $html = "<p>Dzień dobry" . ($toName ? ', ' . h($toName) : '') . ",</p>"
          . "<p>Dziękujemy za wpłatę w wysokości <strong>{$wp} zł</strong>.</p>"
          . "<p>Po rozliczeniu zajęć na koncie kursanta powstała <strong>nadpłata: {$cr} zł</strong>" . $grp_txt . ".</p>"
          . "<p>Środki te <strong>zostaną automatycznie zaliczone na poczet kolejnych zajęć</strong>" . $grp_next . " — nie trzeba nic robić.</p>"
          . "<p>Szczegóły rozliczeń znajdą Państwo w panelu: <a href='" . h($portal) . "'>" . h($portal) . "</a></p>"
          . "<p style='margin-top:16px'>Dziękujemy za zaufanie i wsparcie naszej misji.<br>Zespół <strong>" . h($org) . "</strong></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość wygenerowana automatycznie.</p>";
    try {
        mail_queue_add($email, $toName, "Potwierdzenie nadpłaty — {$org}", $html, '', 'ti_overpay', $payment_id, '', false);
        if ($payment_id) db()->prepare("UPDATE k30_ti_payments SET overpay_notified=1 WHERE id=?")->execute([$payment_id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

// ── Przeniesienie płatności na następny miesiąc ─────────────────────────────

/** Tworzy wniosek o przeniesienie rozliczenia $billing_id na następny miesiąc. */
function ti_deferral_request(int $billing_id, string $reason, int $requested_by): int {
    ti_payments_migrate();
    $b = db_one("SELECT id, client_id, month, year, amount, adjustment, status FROM k30_ti_billing WHERE id=?", [$billing_id]);
    if (!$b || !in_array($b['status'], ['issued', 'draft'], true)) return 0;
    // Czy już istnieje oczekujący wniosek dla tego rozliczenia?
    $exists = db_one("SELECT id FROM k30_ti_payment_deferrals WHERE billing_id=? AND status='pending'", [$billing_id]);
    if ($exists) return (int)$exists['id'];

    $from_month = (int)$b['month'];
    $from_year  = (int)$b['year'];
    $to_month   = $from_month === 12 ? 1  : $from_month + 1;
    $to_year    = $from_month === 12 ? $from_year + 1 : $from_year;
    $amount     = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);

    return db_insert('k30_ti_payment_deferrals', [
        'billing_id'   => $billing_id,
        'client_id'    => (int)$b['client_id'],
        'from_month'   => $from_month,
        'from_year'    => $from_year,
        'to_month'     => $to_month,
        'to_year'      => $to_year,
        'amount'       => $amount,
        'reason'       => mb_substr(trim($reason), 0, 1000),
        'status'       => 'pending',
        'requested_by' => $requested_by,
        'requested_at' => date('Y-m-d H:i:s'),
    ]);
}

/** Zatwierdza wniosek — anuluje oryginalne rozliczenie i tworzy dopłatę w następnym miesiącu. */
function ti_deferral_approve(int $deferral_id, int $decided_by, string $note = ''): bool {
    ti_payments_migrate();
    $d = db_one("SELECT * FROM k30_ti_payment_deferrals WHERE id=? AND status='pending'", [$deferral_id]);
    if (!$d) return false;

    $months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                  7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
    $from_label = $months_pl[(int)$d['from_month']] . ' ' . $d['from_year'];

    db()->prepare("UPDATE k30_ti_billing SET status='cancelled' WHERE id=?")->execute([(int)$d['billing_id']]);
    ti_billing_add_charge(
        (int)$d['client_id'],
        (float)$d['amount'],
        'Przeniesienie płatności za ' . $from_label,
        (int)$d['to_month'],
        (int)$d['to_year']
    );
    db()->prepare(
        "UPDATE k30_ti_payment_deferrals SET status='approved', decided_by=?, decided_at=datetime('now'), decide_note=? WHERE id=?"
    )->execute([$decided_by, mb_substr(trim($note), 0, 500), $deferral_id]);
    return true;
}

/** Odrzuca wniosek. */
function ti_deferral_reject(int $deferral_id, int $decided_by, string $note = ''): bool {
    ti_payments_migrate();
    $d = db_one("SELECT id FROM k30_ti_payment_deferrals WHERE id=? AND status='pending'", [$deferral_id]);
    if (!$d) return false;
    db()->prepare(
        "UPDATE k30_ti_payment_deferrals SET status='rejected', decided_by=?, decided_at=datetime('now'), decide_note=? WHERE id=?"
    )->execute([$decided_by, mb_substr(trim($note), 0, 500), $deferral_id]);
    return true;
}

/** Lista oczekujących wniosków — dla panelu admina. */
function ti_deferrals_pending(): array {
    ti_payments_migrate();
    return db_all(
        "SELECT d.*, cl.name AS client_name, u.name AS requested_by_name
         FROM k30_ti_payment_deferrals d
         JOIN k30_clients cl ON cl.id=d.client_id
         LEFT JOIN users u ON u.id=d.requested_by
         WHERE d.status='pending'
         ORDER BY d.requested_at"
    );
}

/** Lista klientów z niedopłatą (saldo ujemne) — dla panelu admina. */
function ti_clients_with_debt(): array {
    ti_payments_migrate();
    $rows = db_all(
        "SELECT b.client_id, cl.name AS client_name,
                SUM(b.amount + COALESCE(b.adjustment,0)) AS charges,
                SUM(COALESCE(b.paid_amount,0)) AS paid
         FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
         WHERE b.status IN ('issued','paid')
         GROUP BY b.client_id
         HAVING charges - paid > 0.005
         ORDER BY (charges - paid) DESC"
    );
    foreach ($rows as &$r) { $r['debt'] = round((float)$r['charges'] - (float)$r['paid'], 2); }
    return $rows;
}

/**
 * Prognoza "nadpłaty do końca roku": szacowana suma należności za WSZYSTKIE
 * pozostałe zajęcia (jeszcze nieodbyte, zaplanowane) we wszystkich aktywnych
 * zapisach kursanta, do 31 grudnia bieżącego roku, pomniejszona o obecne
 * saldo portfela (jeśli już jest nadpłata). To SZACUNEK, nie rozliczenie:
 * nie uwzględnia pojedynczych odwołań zajęć, które jeszcze nie zaistniały
 * (k30_ti_attendance dla przyszłych lekcji zwykle jeszcze nie ma wierszy).
 *
 * k30_ti_billing NIE MA wierszy za przyszłe miesiące (rozliczenia liczą się
 * wstecz, z frekwencji) — stąd liczymy wprost z harmonogramu (k30_ti_sessions)
 * i cennika zapisu (k30_ti_effective_billing() z includes/karty30.php — wymaga,
 * żeby wołający miał już załadowane includes/karty30.php, tak jak reszta
 * funkcji rozliczeniowych w tym pliku).
 *
 * @return array{
 *   year:int, deadline:string, courses:array, projected_total:float,
 *   current_credit:float, suggested_amount:float
 * }
 */
function ti_year_end_projection(int $client_id): array {
    $today    = date('Y-m-d');
    $cur_year = (int)date('Y');
    $year_end = $cur_year . '-12-31';
    // Miesiące od bieżącego do grudnia włącznie — miesięczny/stały model
    // nalicza się per miesiąc niezależnie od tego, ile dni z niego zostało.
    $months_left = 12 - (int)date('m') + 1;

    $enrs = db_all(
        "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount,
                c.name AS course_name
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c ON c.id=e.course_id
         WHERE e.client_id=? AND e.status='active'",
        [$client_id]
    );

    $total   = 0.0;
    $courses = [];
    foreach ($enrs as $e) {
        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
        ]);

        // Zaplanowane zmiany cen (includes/ti_price_changes.php) liczone tak jak
        // w rozliczeniu: ryczałt wg stanu na 1. dzień każdego miesiąca, godzinowo
        // wg daty lekcji — prognoza uwzględnia zapowiedziane podwyżki/obniżki.
        require_once __DIR__ . '/ti_price_changes.php';
        $cid_e = (int)$e['course_id'];
        if ($eff['model'] === 1 || $eff['model'] === 3) {
            // miesięczny / stały — kwota za każdy pozostały miesiąc (od bieżącego)
            $course_amount = 0.0;
            for ($mi = 0; $mi < $months_left; $mi++) {
                $m1 = date('Y-m-01', strtotime(date('Y-m-01') . " +{$mi} month"));
                $course_amount += (float)ti_price_eff_on($eff, $cid_e, $client_id, $m1)['amount'];
            }
        } else {
            // godzinowy — zaplanowane (nieodbyte) sesje do końca roku, po stawce z ich dnia
            $rows = db_all(
                "SELECT duration_min, lesson_date FROM k30_ti_sessions
                 WHERE course_id=? AND status='planned' AND lesson_date BETWEEN ? AND ?",
                [$cid_e, $today, $year_end]
            );
            $lessons = [];
            foreach ($rows as $r) $lessons[] = ['date' => (string)$r['lesson_date'], 'hours' => (float)ceil((int)$r['duration_min'] / 60)];
            $course_amount = ti_price_hourly_breakdown($eff, $cid_e, $client_id, $lessons)['amount'];
        }

        if ($course_amount > 0.005) {
            $courses[] = [
                'course_id'   => (int)$e['course_id'],
                'course_name' => (string)($e['course_name'] ?? '?'),
                'model_label' => $eff['label'],
                'amount'      => round($course_amount, 2),
            ];
        }
        $total += $course_amount;
    }

    $bal       = ti_client_balance($client_id);
    $suggested = max(0.0, round($total - (float)$bal['credit'], 2));

    return [
        'year'             => $cur_year,
        'deadline'         => $year_end,
        'courses'          => $courses,
        'projected_total'  => round($total, 2),
        'current_credit'   => (float)$bal['credit'],
        'suggested_amount' => $suggested,
    ];
}

/**
 * Powiadamia adminów o wpłaconej nadpłacie do końca roku — cel wpłaty to
 * przedpłata na poczet FV wystawianej w BIEŻĄCYM roku podatkowym, więc biuro
 * powinno wiedzieć, że trzeba przygotować/wystawić fakturę (source_type
 * 'k30_ti_wallet_year_end' w tabelach *_payments odróżnia to od zwykłego
 * doładowania portfela — patrz stripe_mark_paid()/payu_mark_paid()/p24_mark_paid()).
 */
function ti_year_end_overpay_notify_admin(int $client_id, float $amount, string $gateway): void {
    $admins = db_all("SELECT email, name FROM users WHERE role='admin' AND is_active=1 AND email IS NOT NULL AND email != ''");
    if (!$admins) return;
    $client = db_one("SELECT name FROM k30_clients WHERE id=?", [$client_id]) ?: [];
    require_once __DIR__ . '/mail_queue.php';
    $amt = number_format($amount, 2, ',', ' ');
    $gw_label = match ($gateway) { 'stripe' => 'Stripe', 'payu' => 'PayU', 'p24' => 'Przelewy24', default => $gateway };
    $subject = 'Prośba o FV — nadpłata do końca roku: ' . ($client['name'] ?? ('kursant #' . $client_id));
    $html = "<p>Kursant <strong>" . h((string)($client['name'] ?? '')) . "</strong> opłacił nadpłatę do końca roku"
          . " ({$amt} zł, {$gw_label}).</p>"
          . "<p>To przedpłata na poczet zajęć do końca bieżącego roku kalendarzowego — prosimy o przygotowanie"
          . " faktury VAT za ten rok podatkowy.</p>";
    foreach ($admins as $a) {
        try {
            mail_queue_add((string)$a['email'], (string)($a['name'] ?? ''), $subject, $html);
        } catch (\Throwable $e) {}
    }
}
