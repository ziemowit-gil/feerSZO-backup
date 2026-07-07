<?php
/**
 * includes/user_reassignment.php — "Przepisz użytkownika": formalna zmiana
 * konta powiązanego z umową (wolontariat/zlecenie) na nowy adres e-mail.
 *
 * Przebieg: admin podaje powód + nowy e-mail → system drukuje oświadczenie
 * o przyjęciu zgłoszenia z numerem FEER/ACM/DDMMRRGGMM/nr — do podpisu przez
 * Operatora → admin przesyła skan podpisanego dokumentu → dopiero WTEDY
 * system tworzy nowe konto (kopiuje dane, nowy e-mail), zamyka stare konto
 * (is_active=0) i przepina umowę na nowe konto.
 *
 * Celowo NIE dotyka tożsamości Microsoft 365 (microsoft_id, users.m365_login)
 * — to zarządzane ręcznie w module IT. Kolumna m365_login NA UMOWIE pełni
 * tu inną rolę: to zwykłe pole dopasowania konta (porównywane z users.email
 * w viewer_owns_contract()), więc jest aktualizowana razem z email.
 */

require_once __DIR__ . '/functions.php';

if (!function_exists('reassignment_ensure_schema')) {

function reassignment_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_reassignments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id    INTEGER NOT NULL,
            admin_email      TEXT,
            source_type      TEXT NOT NULL,
            source_id        INTEGER NOT NULL,
            old_user_id      INTEGER NOT NULL,
            old_email        TEXT,
            new_email        TEXT NOT NULL,
            reason           TEXT NOT NULL,
            doc_number       TEXT NOT NULL,
            scan_file        TEXT,
            scan_uploaded_at DATETIME,
            new_user_id      INTEGER,
            executed_at      DATETIME,
            ip               TEXT,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
}

/** Numer dokumentu: FEER/ACM/DDMMRRGGMM/nr-kolejny (3 cyfry, licznik globalny). */
function reassignment_doc_number(): string {
    reassignment_ensure_schema();
    $seq = 1;
    try {
        $seq = (int)(db_one("SELECT COUNT(*) c FROM user_reassignments")['c'] ?? 0) + 1;
    } catch (\Throwable $e) {}
    return 'FEER/ACM/' . date('dmyHi') . '/' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * Tworzy żądanie przepisania konta. Zwraca ['request_id'=>int, 'doc_number'=>string].
 * Rzuca RuntimeException przy błędnych danych.
 */
function reassignment_create_request(array $target, string $type, int $id, string $reason, string $new_email): array {
    reassignment_ensure_schema();
    $admin = ctx_real_user();
    if (!$admin) throw new RuntimeException('Brak zalogowanego administratora.');

    $reason = trim(mb_substr($reason, 0, 500));
    if ($reason === '') throw new RuntimeException('Podaj powód przepisania konta.');

    $new_email = trim(mb_strtolower($new_email));
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Podaj poprawny nowy adres e-mail.');
    }
    if ($new_email === mb_strtolower((string)$target['email'])) {
        throw new RuntimeException('Nowy adres e-mail jest taki sam jak obecny.');
    }
    $exists = db_one("SELECT id FROM users WHERE email=? AND is_active=1", [$new_email]);
    if ($exists) throw new RuntimeException('Ten adres e-mail jest już używany przez aktywne konto.');

    $doc_number = reassignment_doc_number();

    $request_id = db_insert('user_reassignments', [
        'admin_user_id' => (int)$admin['id'],
        'admin_email'   => $admin['email'] ?? '',
        'source_type'   => $type,
        'source_id'     => $id,
        'old_user_id'   => (int)$target['id'],
        'old_email'     => $target['email'] ?? '',
        'new_email'     => $new_email,
        'reason'        => $reason,
        'doc_number'    => $doc_number,
        'ip'            => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    return ['request_id' => $request_id, 'doc_number' => $doc_number];
}

/** Kolumny konta, które NIE są kopiowane na nowe konto. */
if (!defined('REASSIGNMENT_COLUMN_BLOCKLIST')) define('REASSIGNMENT_COLUMN_BLOCKLIST', [
    'id', 'email', 'password', 'created_at',
    'microsoft_id', 'm365_login', 'm365_security_group_id', 'm365_security_group_name',
    'totp_secret', 'totp_confirmed', 'totp_backup_codes', 'twofa_method', 'twofa_phone',
    'login_code', 'must_change_password', 'locked_until', 'activation_token',
    'sms_fallback_code', 'sms_fallback_expires_at',
    'ika_setup_token', 'ika_setup_token_expires', 'ika_email_otp', 'ika_email_otp_expires',
    'kdok_ika_hash', 'kdok_ika_set_at', 'kdok_ikaks_hash', 'kdok_ikaks_set_at',
    'is_active',
]);

/**
 * Wykonuje przepisanie po dostarczeniu skanu podpisanego oświadczenia:
 * tworzy nowe konto (kopia danych, nowy e-mail), zamyka stare (is_active=0),
 * przepina umowę na nowe konto. Zwraca ['ok'=>bool, 'error'=>string].
 */
function reassignment_execute(int $request_id, string $scan_path): array {
    reassignment_ensure_schema();
    $admin = ctx_real_user();
    if (!$admin) return ['ok' => false, 'error' => 'Sesja wygasła.'];

    $req = db_one("SELECT * FROM user_reassignments WHERE id=? AND admin_user_id=?", [$request_id, (int)$admin['id']]);
    if (!$req) return ['ok' => false, 'error' => 'Nie znaleziono żądania.'];
    if ($req['executed_at']) return ['ok' => false, 'error' => 'To żądanie zostało już wykonane.'];

    $old = db_one("SELECT * FROM users WHERE id=?", [(int)$req['old_user_id']]);
    if (!$old) return ['ok' => false, 'error' => 'Stare konto nie istnieje.'];

    $new_data = [];
    foreach ($old as $col => $val) {
        if (in_array($col, REASSIGNMENT_COLUMN_BLOCKLIST, true)) continue;
        $new_data[$col] = $val;
    }
    $new_data['email']                = $req['new_email'];
    $new_data['is_active']            = 1;
    $new_data['must_change_password'] = 1;
    $new_data['password']             = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    try {
        $new_user_id = db_insert('users', $new_data);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Nie udało się utworzyć nowego konta: ' . $e->getMessage()];
    }

    require_once __DIR__ . '/auth.php';
    $activation_token = auth_generate_setup_token($new_user_id);

    db()->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([(int)$old['id']]);

    $table = table_for_type($req['source_type']);
    $contract = db_one("SELECT email, m365_login FROM {$table} WHERE id=?", [(int)$req['source_id']]);
    if ($contract) {
        $set = ['email' => $req['new_email']];
        if (($contract['m365_login'] ?? '') === $req['old_email']) {
            $set['m365_login'] = $req['new_email'];
        }
        $cols = implode(', ', array_map(fn($c) => "$c=?", array_keys($set)));
        db()->prepare("UPDATE {$table} SET {$cols} WHERE id=?")->execute([...array_values($set), (int)$req['source_id']]);
    }

    db()->prepare(
        "UPDATE user_reassignments SET scan_file=?, scan_uploaded_at=datetime('now'), new_user_id=?, executed_at=datetime('now') WHERE id=?"
    )->execute([$scan_path, $new_user_id, $request_id]);

    require_once __DIR__ . '/mail_queue.php';
    $org      = defined('ORG_NAME') ? ORG_NAME : 'System';
    $setupUrl = APP_URL . '/auth/set_password.php?token=' . $activation_token;
    $body = "Twoje konto w systemie {$org} zostało przepisane na ten adres e-mail (dokument nr {$req['doc_number']}).\n\n"
          . "Aby ustawić hasło do nowego konta, otwórz: {$setupUrl}\n\n"
          . "Jeśli nie spodziewałeś/aś się tej wiadomości, skontaktuj się z administracją.";
    mail_queue_add($req['new_email'], '', "[{$org}] Twoje konto zostało przepisane — ustaw hasło", nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), $body, 'user_reassignment', $request_id, '', true);

    require_once __DIR__ . '/approval.php';
    log_contract_action($req['source_type'], (int)$req['source_id'], (int)$admin['id'], 'user_reassignment',
        "Przepisano użytkownika: {$req['old_email']} → {$req['new_email']} — dokument nr {$req['doc_number']}, powód: {$req['reason']}");

    return ['ok' => true];
}

} // function_exists guard
