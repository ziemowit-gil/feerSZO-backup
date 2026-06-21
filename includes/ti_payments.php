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
    // Należność: ile już pokryto (alokacja FIFO wpłat)
    try { $pdo->exec("ALTER TABLE k30_ti_billing ADD COLUMN paid_amount REAL NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
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
 * Alokuje wpłaty FIFO na należności (najstarsze najpierw), ustawiając paid_amount i status.
 * Idempotentne — można wołać po każdej zmianie wpłaty/należności.
 * Zwraca ['payments'=>float,'charges'=>float,'credit'=>float,'debt'=>float].
 */
function ti_billing_recompute(int $client_id): array {
    ti_payments_migrate();
    $payments = ti_payments_total($client_id);
    $charges  = db_all(
        "SELECT id, (amount + COALESCE(adjustment,0)) AS due
         FROM k30_ti_billing
         WHERE client_id=? AND status IN ('issued','paid')
         ORDER BY year ASC, month ASC, id ASC",
        [$client_id]
    );
    $remaining = $payments;
    $debt = 0.0;
    foreach ($charges as $c) {
        $due     = round((float)$c['due'], 2);
        $applied = min($remaining, $due);
        if ($applied < 0) $applied = 0;
        $remaining = round($remaining - $applied, 2);
        $status  = ($applied + 0.001 >= $due) ? 'paid' : 'issued';
        if ($status !== 'paid') $debt = round($debt + ($due - $applied), 2);
        db()->prepare("UPDATE k30_ti_billing SET paid_amount=?, status=? WHERE id=?")
           ->execute([round($applied, 2), $status, (int)$c['id']]);
    }
    $credit = round(max(0, $remaining), 2);
    return ['payments' => $payments, 'charges' => round(array_sum(array_map(fn($c)=>(float)$c['due'],$charges)),2),
            'credit' => $credit, 'debt' => $debt];
}

/** Saldo klienta (bez przeliczania zapisu): nadpłata (credit) lub niedopłata (debt). */
function ti_client_balance(int $client_id): array {
    $payments = ti_payments_total($client_id);
    $charges  = ti_charges_total($client_id);
    $bal      = round($payments - $charges, 2);
    return [
        'payments' => $payments,
        'charges'  => $charges,
        'balance'  => $bal,
        'credit'   => round(max(0, $bal), 2),   // nadpłata
        'debt'     => round(max(0, -$bal), 2),  // niedopłata (saldo ujemne)
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
                        string $note = '', string $source_type = 'manual', int $source_id = 0): array {
    ti_payments_migrate();
    $amount = round($amount, 2);
    $credit_before = ti_client_balance($client_id)['credit'];

    $pid = db_insert('k30_ti_payments', [
        'client_id'   => $client_id,
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
        $emailed = ti_payment_notify_overpay($client_id, $amount, $credit, $pid);
    }
    return ['payment_id' => $pid, 'credit' => $credit, 'debt' => $res['debt'], 'emailed' => $emailed];
}

/** Usuwa wpłatę i przelicza saldo (korekta admina). */
function ti_payment_delete(int $payment_id): void {
    ti_payments_migrate();
    $p = db_one("SELECT client_id FROM k30_ti_payments WHERE id=?", [$payment_id]);
    if (!$p) return;
    db()->prepare("DELETE FROM k30_ti_payments WHERE id=?")->execute([$payment_id]);
    ti_billing_recompute((int)$p['client_id']);
}

/**
 * E-mail o zaksięgowaniu nadpłaty (kwota wpłaty, wysokość nadpłaty, info o auto-użyciu)
 * + podziękowanie od Fundacji FEER. Adresat: opiekun (małoletni) lub kursant.
 */
function ti_payment_notify_overpay(int $client_id, float $payment_amount, float $credit, int $payment_id = 0): bool {
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
    $html = "<p>Dzień dobry" . ($toName ? ', ' . h($toName) : '') . ",</p>"
          . "<p>Dziękujemy za wpłatę w wysokości <strong>{$wp} zł</strong>.</p>"
          . "<p>Po rozliczeniu zajęć na koncie kursanta powstała <strong>nadpłata: {$cr} zł</strong>.</p>"
          . "<p>Środki te <strong>zostaną automatycznie zaliczone na poczet kolejnych zajęć</strong> — nie trzeba nic robić.</p>"
          . "<p>Szczegóły rozliczeń znajdą Państwo w panelu: <a href='" . h($portal) . "'>" . h($portal) . "</a></p>"
          . "<p style='margin-top:16px'>Dziękujemy za zaufanie i wsparcie naszej misji.<br>Zespół <strong>" . h($org) . "</strong></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość wygenerowana automatycznie.</p>";
    try {
        mail_queue_add($email, $toName, "Potwierdzenie nadpłaty — {$org}", $html, '', 'ti_overpay', $payment_id, '', false);
        if ($payment_id) db()->prepare("UPDATE k30_ti_payments SET overpay_notified=1 WHERE id=?")->execute([$payment_id]);
        return true;
    } catch (\Throwable $e) { return false; }
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
