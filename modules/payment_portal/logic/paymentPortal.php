<?php
/**
 * modules/payment_portal/logic/paymentPortal.php — logika portalu płatności SZO (/platnosci).
 *
 * Przepływ:
 *   1. Admin (platnosci/admin.php) zakłada uczestnikowi dostęp: indywidualny NRB
 *      (generowany z prefiksu rachunków wirtualnych banku albo wpisany) i link z
 *      tokenem (w bazie tylko SHA-256). Pozycje do zapłaty: ręczne, faktury, karty,
 *      warsztaty oraz import nieopłaconych rozliczeń TI (kwota = bieżąca niedopłata).
 *   2. Uczestnik (platnosci/index.php?t=…) zaznacza pozycje; serwer liczy kwotę z bazy
 *      (pp_create_transaction — pozycje → processing, transakcja pending, w jednej
 *      transakcji PDO z audytem) i:
 *        • P24: p24_create_order('payment_portal', tx) → przekierowanie do bramki;
 *          webhook + transaction/verify (includes/p24.php) → pp_settle();
 *        • NRB: dane do przelewu (NRB, kwota, tytuł z identyfikatorem transakcji);
 *          „Zgłoś wykonanie przelewu” zostawia transakcję pending do potwierdzenia
 *          przez admina po zaksięgowaniu (pp_settle) albo odrzucenia (pp_fail).
 *   3. pp_settle(): transakcja success, pozycje paid i skutki w źródłach (rozliczenie
 *      TI → wpłata w księdze ti_payment_add, faktura → invoices.paid_at) — atomowo.
 * Audyt (audit_logs): payments.attempt / p24_request / p24_simulated / nrb_declared /
 * success / failed / item_status / item_created / token_issued / nrb_set.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/includes/p24.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

const PP_REF_TYPES = ['invoice' => 'Faktura VAT', 'card_application' => 'Karta dostępu', 'workshop' => 'Warsztat / zajęcia',
                      'manual' => 'Inna opłata', 'ti_billing' => 'Zajęcia TI (rozliczenie)'];
const PP_ITEM_STATUS = ['pending' => 'do zapłaty', 'processing' => 'w trakcie opłacania', 'paid' => 'opłacone', 'cancelled' => 'anulowane'];
const PP_TX_STATUS   = ['pending' => 'oczekuje', 'success' => 'opłacona', 'failed' => 'nieudana / anulowana'];

function pp_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    audit_logs_migrate();
    p24_migrate();
    db()->exec((string)file_get_contents(dirname(__DIR__) . '/schema.sql'));
}
function pp_gr(float|int|string $v): int { return (int)round((float)$v * 100); }
function pp_zl(int $gr): float { return round($gr / 100, 2); }
function pp_fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }

// ── NRB ─────────────────────────────────────────────────────────────────────
/** Cyfry kontrolne IBAN dla polskiego BBAN (24 cyfry). */
function pp_nrb_check(string $bban): string {
    $num = $bban . '2521' . '00';                      // PL = 25 21, "00" na miejsce cyfr kontrolnych
    $mod = 0;
    foreach (str_split($num) as $d) $mod = ($mod * 10 + (int)$d) % 97;
    return str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT);
}
function pp_nrb_normalize(string $raw): string { return preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', $raw)); }
function pp_nrb_valid(string $nrb): bool {
    $n = pp_nrb_normalize($nrb);
    return strlen($n) === 26 && pp_nrb_check(substr($n, 2)) === substr($n, 0, 2);
}
function pp_nrb_format(string $nrb): string {
    $n = pp_nrb_normalize($nrb);
    return strlen($n) === 26 ? substr($n, 0, 2) . ' ' . trim(chunk_split(substr($n, 2), 4, ' ')) : $nrb;
}
/**
 * NRB wirtualny z ustawień: 8 cyfr rozliczeniowych banku + prefiks klienta
 * + identyfikator uczestnika dopełniony zerami do 24 cyfr BBAN. null = nie skonfigurowano.
 */
function pp_nrb_generate(int $participant_id): ?string {
    $bank   = preg_replace('/\D/', '', (string)org_setting('pp_nrb_bank'));
    $prefix = preg_replace('/\D/', '', (string)org_setting('pp_nrb_prefix'));
    if (strlen($bank) !== 8) return null;
    $room = 16 - strlen($prefix);
    if ($room < 4 || strlen((string)$participant_id) > $room) return null;
    $bban = $bank . $prefix . str_pad((string)$participant_id, $room, '0', STR_PAD_LEFT);
    return pp_nrb_check($bban) . $bban;
}

// ── Użytkownicy i dostęp ────────────────────────────────────────────────────
function pp_user(int $participant_id): ?array {
    pp_migrate();
    return db_one("SELECT u.*, c.name AS participant_name FROM payment_portal_users u JOIN k30_clients c ON c.id=u.participant_id WHERE u.participant_id=?", [$participant_id]) ?: null;
}
/** Zakłada dostęp uczestnika (idempotentnie); NRB z generatora, gdy skonfigurowany. */
function pp_user_ensure(int $participant_id, string $by = '', ?int $uid = null): array|string {
    pp_migrate();
    $cl = db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$participant_id]);
    if (!$cl) return 'Nie ma takiego uczestnika.';
    if ($u = pp_user($participant_id)) return $u;
    $email = trim((string)$cl['email']);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || db_one("SELECT 1 FROM payment_portal_users WHERE email=?", [$email]))) $email = '';
    db_insert('payment_portal_users', ['participant_id' => $participant_id, 'email' => $email !== '' ? $email : null,
                                       'individual_nrb' => pp_nrb_generate($participant_id), 'is_active' => 1]);
    audit_log('payments.portal_user_created', ['participant_id' => $participant_id, 'by' => $by], $uid);
    return pp_user($participant_id);
}
function pp_set_nrb(int $participant_id, string $nrb, string $by, ?int $uid): ?string {
    pp_migrate();
    $n = pp_nrb_normalize($nrb);
    if ($n !== '' && !pp_nrb_valid($n)) return 'Nieprawidłowy numer rachunku (26 cyfr, suma kontrolna).';
    if ($n !== '' && db_one("SELECT 1 FROM payment_portal_users WHERE individual_nrb=? AND participant_id!=?", [$n, $participant_id])) return 'Ten numer rachunku ma już inny uczestnik.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $old = db_one("SELECT individual_nrb FROM payment_portal_users WHERE participant_id=?", [$participant_id]);
        db()->prepare("UPDATE payment_portal_users SET individual_nrb=? WHERE participant_id=?")->execute([$n !== '' ? $n : null, $participant_id]);
        audit_log('payments.nrb_set', ['participant_id' => $participant_id, 'before' => $old['individual_nrb'] ?? null, 'after' => $n, 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}
/** Nowy link dostępu (poprzedni przestaje działać). Zwraca URL z tokenem — pokazywany raz. */
function pp_issue_link(int $participant_id, string $by, ?int $uid): string {
    pp_migrate();
    $tok = bin2hex(random_bytes(24));
    db()->prepare("UPDATE payment_portal_users SET access_hash=?, token_created_at=datetime('now'), is_active=1 WHERE participant_id=?")
        ->execute([hash('sha256', $tok), $participant_id]);
    audit_log('payments.token_issued', ['participant_id' => $participant_id, 'by' => $by], $uid);
    return rtrim(APP_URL, '/') . '/platnosci/?t=' . $tok;
}
function pp_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('szo_platnosci');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/platnosci', 'httponly' => true, 'samesite' => 'Lax',
                                   'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        session_start();
    }
}
/** Logowanie tokenem z linku → sesja portalu (osobna od SZO). */
function pp_login_token(string $tok): ?array {
    pp_migrate();
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $u = db_one("SELECT * FROM payment_portal_users WHERE access_hash=? AND is_active=1", [hash('sha256', $tok)]);
    if (!$u) return null;
    pp_session_start();
    session_regenerate_id(true);
    $_SESSION['pp_participant'] = ['participant_id' => (int)$u['participant_id'], 'ts' => time()];
    $_SESSION['pp_csrf'] = bin2hex(random_bytes(16));
    db()->prepare("UPDATE payment_portal_users SET last_login_at=datetime('now') WHERE id=?")->execute([(int)$u['id']]);
    audit_log('payments.portal_login', ['participant_id' => (int)$u['participant_id']], null);
    return $u;
}
function pp_current(): ?int {
    pp_session_start();
    $s = $_SESSION['pp_participant'] ?? null;
    if (!$s || time() - (int)$s['ts'] > 7200) { unset($_SESSION['pp_participant']); return null; }
    $_SESSION['pp_participant']['ts'] = time();
    $u = db_one("SELECT is_active FROM payment_portal_users WHERE participant_id=?", [(int)$s['participant_id']]);
    return $u && (int)$u['is_active'] ? (int)$s['participant_id'] : null;
}
function pp_csrf(): string { pp_session_start(); return $_SESSION['pp_csrf'] ??= bin2hex(random_bytes(16)); }
function pp_csrf_check(): void {
    if (!hash_equals((string)($_SESSION['pp_csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) { http_response_code(403); exit('Sesja wygasła — odśwież stronę.'); }
}

// ── Pozycje do zapłaty ──────────────────────────────────────────────────────
function pp_add_item(int $participant_id, string $title, float $amount, string $type, ?int $ref_id, ?string $due, string $by, ?int $uid): int|string {
    pp_migrate();
    $title = mb_substr(trim($title), 0, 200);
    if ($title === '') return 'Podaj nazwę pozycji.';
    if (!array_key_exists($type, PP_REF_TYPES)) return 'Nieznany typ pozycji.';
    if ($amount <= 0 || $amount > 1000000) return 'Kwota musi być większa od zera.';
    if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return 'Nieprawidłowy termin płatności.';
    if (!db_one("SELECT 1 FROM k30_clients WHERE id=?", [$participant_id])) return 'Nie ma takiego uczestnika.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $id = db_insert('payable_items', ['participant_id' => $participant_id, 'title' => $title, 'reference_type' => $type,
            'reference_id' => $ref_id ?: null, 'amount' => round($amount, 2), 'status' => 'pending', 'due_date' => $due, 'created_by' => $by]);
        audit_log('payments.item_created', ['item_id' => $id, 'participant_id' => $participant_id, 'type' => $type, 'reference_id' => $ref_id, 'amount' => round($amount, 2), 'by' => $by], $uid);
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) { $pdo->rollBack(); return str_contains($e->getMessage(), 'UNIQUE') ? 'Ta pozycja jest już otwarta w portalu.' : 'Błąd: ' . $e->getMessage(); }
}
function pp_cancel_item(int $item_id, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $pdo->beginTransaction();
    try {
        $it = db_one("SELECT * FROM payable_items WHERE id=?", [$item_id]);
        if (!$it || $it['status'] !== 'pending') { $pdo->rollBack(); return 'Anulować można tylko pozycję „do zapłaty” (nie w trakcie opłacania).'; }
        db()->prepare("UPDATE payable_items SET status='cancelled' WHERE id=?")->execute([$item_id]);
        audit_log('payments.item_status', ['item_id' => $item_id, 'from' => 'pending', 'to' => 'cancelled', 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/** Bieżąca niedopłata rozliczenia TI (z alokacji wpłat), w groszach. */
function pp_ti_billing_debt_gr(int $billing_id): int {
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$billing_id]);
    if (!$b || !in_array($b['status'], ['issued', 'paid'], true)) return 0;
    $paid = 0.0;
    foreach (ti_client_allocation((int)$b['client_id'])['rows'] as $r) if ($r['id'] === (int)$b['id']) $paid = $r['paid'];
    return max(0, pp_gr((float)$b['amount'] + (float)($b['adjustment'] ?? 0)) - pp_gr($paid));
}
/** Import nieopłaconych rozliczeń TI jako pozycji portalu (jedna otwarta pozycja na rozliczenie). */
function pp_import_ti(?int $participant_id, string $by, ?int $uid): int {
    pp_migrate();
    $pl = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    $rows = db_all("SELECT b.*, c.name AS course_name FROM k30_ti_billing b LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
                     WHERE b.status='issued'" . ($participant_id ? " AND b.client_id=?" : '') . " ORDER BY b.year, b.month",
                   $participant_id ? [$participant_id] : []);
    $n = 0;
    foreach ($rows as $b) {
        if (db_one("SELECT 1 FROM payable_items WHERE reference_type='ti_billing' AND reference_id=? AND status IN ('pending','processing')", [(int)$b['id']])) continue;
        $debt = pp_ti_billing_debt_gr((int)$b['id']);
        if ($debt <= 0) continue;
        $title = 'Zajęcia TI — ' . ((int)$b['course_id'] > 0 ? (string)$b['course_name'] : 'rozliczenie łączne') . ', ' . ($pl[(int)$b['month']] ?? $b['month']) . ' ' . $b['year'];
        if (is_int(pp_add_item((int)$b['client_id'], $title, pp_zl($debt), 'ti_billing', (int)$b['id'], $b['due_date'] ?: null, $by, $uid))) $n++;
    }
    return $n;
}
/**
 * Uzgadnia otwarte pozycje TI z księgą (ktoś zapłacił inaczej / zmieniła się kwota):
 * brak niedopłaty → pozycja opłacona poza portalem; inna kwota → nowa kwota.
 */
function pp_sync_ti_items(int $participant_id): void {
    foreach (db_all("SELECT * FROM payable_items WHERE participant_id=? AND reference_type='ti_billing' AND status='pending'", [$participant_id]) as $it) {
        $debt = pp_ti_billing_debt_gr((int)$it['reference_id']);
        if ($debt <= 0) {
            db()->prepare("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'")->execute([(int)$it['id']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => 'pending', 'to' => 'paid', 'reason' => 'rozliczenie TI opłacone poza portalem'], null);
        } elseif ($debt !== pp_gr($it['amount'])) {
            db()->prepare("UPDATE payable_items SET amount=? WHERE id=?")->execute([pp_zl($debt), (int)$it['id']]);
            audit_log('payments.item_amount_synced', ['item_id' => (int)$it['id'], 'from' => (float)$it['amount'], 'to' => pp_zl($debt)], null);
        }
    }
}

// ── Transakcje ──────────────────────────────────────────────────────────────
function pp_uuid(): string {
    $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** Tytuł przelewu: stały format, który księgowość dopasowuje do transakcji. */
function pp_transfer_title(string $uuid, array $cl): string {
    return 'SZO ' . strtoupper(substr(str_replace('-', '', $uuid), 0, 10)) . ' ' . mb_substr(preg_replace('/\s+/', ' ', (string)$cl['name']), 0, 40);
}

/**
 * Tworzy transakcję z koszyka: pozycje muszą należeć do uczestnika i być „do zapłaty”;
 * kwota wyłącznie z bazy. Zwraca transakcję albo komunikat błędu.
 */
function pp_create_transaction(int $participant_id, array $item_ids, string $method): array|string {
    pp_migrate();
    if (!in_array($method, ['individual_nrb', 'p24'], true)) return 'Wybierz metodę płatności.';
    $ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
    if (!$ids) return 'Zaznacz co najmniej jedną pozycję.';
    $u = pp_user($participant_id);
    if (!$u) return 'Brak dostępu do portalu.';
    if ($method === 'individual_nrb' && empty($u['individual_nrb'])) return 'Nie masz jeszcze przypisanego indywidualnego numeru rachunku — skontaktuj się z biurem.';
    if ($method === 'p24' && !p24_enabled() && org_setting('pp_p24_simulation') !== '1') return 'Płatność Przelewy24 jest chwilowo niedostępna.';
    pp_sync_ti_items($participant_id);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $items = db_all("SELECT * FROM payable_items WHERE id IN ($ph) AND participant_id=? AND status='pending'", [...$ids, $participant_id]);
        if (count($items) !== count($ids)) { $pdo->rollBack(); return 'Część pozycji jest już opłacana albo niedostępna — odśwież stronę.'; }
        $total = 0; foreach ($items as $it) $total += pp_gr($it['amount']);
        if ($total <= 0) { $pdo->rollBack(); return 'Kwota do zapłaty musi być większa od zera.'; }
        $uuid = pp_uuid();
        $tid  = db_insert('portal_transactions', ['participant_id' => $participant_id, 'transaction_uuid' => $uuid, 'total_amount' => pp_zl($total),
            'payment_method' => $method, 'status' => 'pending', 'transfer_title' => pp_transfer_title($uuid, ['name' => $u['participant_name']])]);
        $st = db()->prepare("UPDATE payable_items SET status='processing' WHERE id=? AND status='pending'");
        foreach ($items as $it) {
            $st->execute([(int)$it['id']]);
            if ($st->rowCount() !== 1) throw new \RuntimeException('Pozycja zmieniła stan w trakcie — spróbuj ponownie.');
            db_insert('portal_transaction_items', ['portal_transaction_id' => $tid, 'payable_item_id' => (int)$it['id'], 'amount' => (float)$it['amount']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => 'pending', 'to' => 'processing', 'transaction_id' => $tid], null);
        }
        audit_log('payments.attempt', ['transaction_id' => $tid, 'uuid' => $uuid, 'participant_id' => $participant_id, 'method' => $method,
            'total' => pp_zl($total), 'items' => array_map(fn($i) => (int)$i['id'], $items)], null);
        if ($method === 'individual_nrb') audit_log('payments.nrb_declared', ['transaction_id' => $tid, 'total' => pp_zl($total)], null);
        $pdo->commit();
        return db_one("SELECT * FROM portal_transactions WHERE id=?", [$tid]);
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** P24: rejestracja w bramce po utworzeniu transakcji. Zwraca URL przekierowania albo błąd (transakcja wtedy failed). */
function pp_start_p24(array $tx, string $email): string|array {
    $base = rtrim(APP_URL, '/');
    if (!p24_enabled() && org_setting('pp_p24_simulation') === '1') {
        audit_log('payments.p24_request', ['transaction_id' => (int)$tx['id'], 'mode' => 'simulation'], null);
        return $base . '/platnosci/?view=sim&tx=' . urlencode($tx['transaction_uuid']);
    }
    try {
        $o = p24_create_order('payment_portal', (int)$tx['id'], (float)$tx['total_amount'], $tx['transfer_title'],
                              $base . '/platnosci/?view=status&tx=' . urlencode($tx['transaction_uuid']), $base . '/api/p24_webhook.php', $email);
        db()->prepare("UPDATE portal_transactions SET p24_payment_id=?, gateway_response=? WHERE id=?")
            ->execute([(int)$o['id'], json_encode(['p24_payment_id' => $o['id'], 'token' => $o['token']]), (int)$tx['id']]);
        audit_log('payments.p24_request', ['transaction_id' => (int)$tx['id'], 'p24_payment_id' => (int)$o['id']], null);
        return $o['url'];
    } catch (\Throwable $e) {
        pp_fail((int)$tx['id'], 'Przelewy24: ' . $e->getMessage(), 'system', null);
        return ['error' => 'Nie udało się połączyć z Przelewy24. Spróbuj ponownie albo wybierz przelew na indywidualny rachunek.'];
    }
}

/** Rozliczenie transakcji: success + pozycje paid + skutki w źródłach — atomowo, idempotentnie. */
function pp_settle(int $tx_id, array $response, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [$tx_id]);
        if (!$tx) throw new \RuntimeException('Nie znaleziono transakcji.');
        if ($tx['status'] === 'success') { if ($own) $pdo->commit(); return null; }
        if ($tx['status'] !== 'pending') throw new \RuntimeException('Transakcja jest już zamknięta (' . $tx['status'] . ').');
        $prev = json_decode((string)$tx['gateway_response'], true) ?: [];
        db()->prepare("UPDATE portal_transactions SET status='success', completed_at=datetime('now'), gateway_response=? WHERE id=? AND status='pending'")
            ->execute([json_encode($prev + ['settlement' => $response], JSON_UNESCAPED_UNICODE), $tx_id]);
        $method = $tx['payment_method'] === 'p24' ? 'p24' : 'transfer';
        foreach (db_all("SELECT pi.*, ti.amount AS tx_amount FROM portal_transaction_items ti JOIN payable_items pi ON pi.id=ti.payable_item_id
                          WHERE ti.portal_transaction_id=?", [$tx_id]) as $it) {
            db()->prepare("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=?")->execute([(int)$it['id']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => $it['status'], 'to' => 'paid', 'transaction_id' => $tx_id], $uid);
            if ($it['reference_type'] === 'ti_billing' && (int)$it['reference_id'] > 0) {
                $b = db_one("SELECT client_id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [(int)$it['reference_id']]);
                if ($b) ti_payment_add((int)$b['client_id'], (float)$it['tx_amount'], date('Y-m-d'), $method,
                                       'Portal płatności ' . substr((string)$tx['transaction_uuid'], 0, 8), 'payment_portal', $tx_id, (int)$b['course_id']);
            } elseif ($it['reference_type'] === 'invoice' && (int)$it['reference_id'] > 0) {
                try { db()->prepare("UPDATE invoices SET paid_at=datetime('now') WHERE id=? AND paid_at IS NULL")->execute([(int)$it['reference_id']]); } catch (\Throwable $e) {}
            }
        }
        audit_log('payments.success', ['transaction_id' => $tx_id, 'uuid' => $tx['transaction_uuid'], 'method' => $tx['payment_method'],
            'total' => (float)$tx['total_amount'], 'response' => $response, 'by' => $by], $uid);
        if ($own) $pdo->commit();
        return null;
    } catch (\Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** Nieudana / anulowana transakcja — pozycje wracają do „do zapłaty”. */
function pp_fail(int $tx_id, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=? AND status='pending'", [$tx_id]);
        if (!$tx) throw new \RuntimeException('Transakcja nie oczekuje (już rozliczona albo anulowana).');
        $prev = json_decode((string)$tx['gateway_response'], true) ?: [];
        db()->prepare("UPDATE portal_transactions SET status='failed', completed_at=datetime('now'), gateway_response=? WHERE id=?")
            ->execute([json_encode($prev + ['failure' => $reason, 'by' => $by], JSON_UNESCAPED_UNICODE), $tx_id]);
        foreach (db_all("SELECT payable_item_id FROM portal_transaction_items WHERE portal_transaction_id=?", [$tx_id]) as $r) {
            db()->prepare("UPDATE payable_items SET status='pending' WHERE id=? AND status='processing'")->execute([(int)$r['payable_item_id']]);
            audit_log('payments.item_status', ['item_id' => (int)$r['payable_item_id'], 'from' => 'processing', 'to' => 'pending', 'transaction_id' => $tx_id], $uid);
        }
        audit_log('payments.failed', ['transaction_id' => $tx_id, 'reason' => $reason, 'by' => $by], $uid);
        if ($own) $pdo->commit();
        return null;
    } catch (\Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** Wywoływane z includes/p24.php (p24_mark_paid) po transaction/verify. */
function pp_settle_from_p24(int $tx_id, array $p24row): void {
    $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [$tx_id]);
    if (!$tx || $tx['status'] !== 'pending') return;
    if (pp_gr($p24row['amount_grosze'] / 100) !== pp_gr($tx['total_amount'])) {   // kwota z bramki ≠ koszyk — nie księgujemy
        audit_log('payments.amount_mismatch', ['transaction_id' => $tx_id, 'p24' => (int)$p24row['amount_grosze'], 'expected' => pp_gr($tx['total_amount'])], null);
        return;
    }
    pp_settle($tx_id, ['gateway' => 'p24', 'p24_payment_id' => (int)$p24row['id'], 'order_id' => (string)$p24row['order_id']], 'Przelewy24', null);
}

/** Stan transakcji uczestnika (dla ekranu statusu); dla P24 aktywne sprawdzenie w bramce. */
function pp_transaction_view(string $uuid, int $participant_id): ?array {
    pp_migrate();
    $tx = db_one("SELECT * FROM portal_transactions WHERE transaction_uuid=? AND participant_id=?", [$uuid, $participant_id]);
    if (!$tx) return null;
    if ($tx['status'] === 'pending' && $tx['payment_method'] === 'p24' && (int)$tx['p24_payment_id'] > 0) {
        p24_reconcile_payment((int)$tx['p24_payment_id']);   // p24_mark_paid → pp_settle_from_p24
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [(int)$tx['id']]);
    }
    $tx['items'] = db_all("SELECT pi.title, pi.reference_type, ti.amount, pi.status FROM portal_transaction_items ti JOIN payable_items pi ON pi.id=ti.payable_item_id
                            WHERE ti.portal_transaction_id=? ORDER BY pi.id", [(int)$tx['id']]);
    return $tx;
}

/** Porzucone płatności P24 (> 24 h bez potwierdzenia) wracają do koszyka. */
function pp_expire_stale(int $participant_id): void {
    foreach (db_all("SELECT id, p24_payment_id FROM portal_transactions WHERE participant_id=? AND status='pending' AND payment_method='p24'
                      AND created_at < datetime('now','-24 hours')", [$participant_id]) as $t) {
        if ((int)$t['p24_payment_id'] > 0 && p24_reconcile_payment((int)$t['p24_payment_id']) === 'paid') continue;
        pp_fail((int)$t['id'], 'Brak potwierdzenia płatności Przelewy24 w ciągu 24 h', 'system', null);
    }
}
