<?php
/**
 * includes/impersonation.php — Wejście na konto użytkownika z poziomu umowy
 * (wolontariat / zlecenie), potwierdzone kodem SMS lub e-mail wysłanym do
 * WŁAŚCICIELA konta (nie do admina). Po potwierdzeniu korzysta z istniejącego
 * mechanizmu nakładki kontekstu (includes/context.php, ctx_enter_user()).
 *
 * Wymaga podania powodu przez admina; cały przebieg jest audytowany w
 * impersonation_requests, user_context_log, auth_log oraz w historii umowy
 * (contract_audit_log przez log_contract_action()).
 */

if (!function_exists('impersonation_ensure_schema')) {

function impersonation_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS impersonation_requests (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id   INTEGER NOT NULL,
            admin_email     TEXT,
            target_user_id  INTEGER NOT NULL,
            target_label    TEXT,
            source_type     TEXT NOT NULL,
            source_id       INTEGER NOT NULL,
            reason          TEXT NOT NULL,
            method          TEXT NOT NULL,
            code            TEXT NOT NULL,
            expires_at      DATETIME NOT NULL,
            attempts        INTEGER NOT NULL DEFAULT 0,
            verified_at     DATETIME,
            ip              TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
}

/**
 * Znajduje aktywne konto w `users` powiązane z umową — odwrócenie logiki
 * viewer_owns_contract() (includes/auth.php). Zwraca null, gdy umowa nie ma
 * powiązanego konta (wtedy nie ma czego "wcielić").
 */
function impersonation_linked_user(string $type, array $row): ?array {
    $conds  = [];
    $params = [];
    if (!empty($row['m365_user_id'])) {
        $conds[] = 'microsoft_id = ?';
        $params[] = $row['m365_user_id'];
    }
    // Odzwierciedla DOKŁADNIE logikę viewer_owns_contract() (includes/auth.php) —
    // to jest OR pomiędzy kandydatami, nie jeden priorytetowy adres. Kontrakt
    // wolontariacki dopuszcza dopasowanie zarówno po m365_login, jak i po email;
    // zlecenie/dzieło — tylko po m365_login.
    $email_candidates = [];
    if (!empty($row['m365_login'])) $email_candidates[] = $row['m365_login'];
    if ($type === 'wolontariat' && !empty($row['email'])) $email_candidates[] = $row['email'];
    $email_candidates = array_unique($email_candidates);
    foreach ($email_candidates as $cand) {
        $conds[] = 'email = ?';
        $params[] = $cand;
    }
    if (!$conds) return null;

    try {
        return db_one(
            "SELECT id, name, email, role, phone_number FROM users
              WHERE is_active=1 AND (" . implode(' OR ', $conds) . ")
              ORDER BY id LIMIT 1",
            $params
        );
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Tworzy żądanie impersonacji i wysyła kod DO WŁAŚCICIELA KONTA (nie do
 * admina). Zwraca ['request_id'=>int, 'target_label'=>string].
 * Rzuca RuntimeException gdy metoda niedostępna lub wysyłka się nie powiedzie.
 */
function impersonation_create_request(array $target, string $type, int $id, string $reason, string $method): array {
    impersonation_ensure_schema();
    $admin = ctx_real_user();
    if (!$admin) throw new RuntimeException('Brak zalogowanego administratora.');

    $reason = trim(mb_substr($reason, 0, 500));
    if ($reason === '') throw new RuntimeException('Podaj powód wejścia na konto.');

    $method = in_array($method, ['sms', 'email'], true) ? $method : '';
    if ($method === 'sms' && empty($target['phone_number'])) {
        throw new RuntimeException('Ten użytkownik nie ma zapisanego numeru telefonu.');
    }
    if ($method === 'email' && empty($target['email'])) {
        throw new RuntimeException('Ten użytkownik nie ma zapisanego adresu e-mail.');
    }
    if ($method === '') throw new RuntimeException('Wybierz metodę potwierdzenia.');

    $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300);
    $label   = trim(($target['name'] ?? '') . ' · ' . ($target['email'] ?? ''));

    $request_id = db_insert('impersonation_requests', [
        'admin_user_id'  => (int)$admin['id'],
        'admin_email'    => $admin['email'] ?? '',
        'target_user_id' => (int)$target['id'],
        'target_label'   => $label,
        'source_type'    => $type,
        'source_id'      => $id,
        'reason'         => $reason,
        'method'         => $method,
        'code'           => $code,
        'expires_at'     => $expires,
        'ip'             => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
    $body = "Administrator {$admin['name']} ({$admin['email']}) chce wejść na Twoje konto w systemie {$org}, "
          . "aby wykonać w Twoim imieniu działania związane z Twoją umową.\n\n"
          . "Podany powód: {$reason}\n\n"
          . "Jeśli się zgadzasz, przekaż administratorowi ten kod: {$code} (ważny 5 minut).\n"
          . "Jeśli NIE oczekiwałeś/aś tej prośby, zignoruj wiadomość i zgłoś to administracji.";

    if ($method === 'sms') {
        require_once __DIR__ . '/sms.php';
        sms_send($target['phone_number'], $body);
    } else {
        require_once __DIR__ . '/mail_queue.php';
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        mail_queue_add($target['email'], $target['name'] ?? '', "[{$org}] Kod potwierdzający dostęp do Twojego konta", $html, $body, 'impersonation_request', $request_id, '', true);
    }

    return ['request_id' => $request_id, 'target_label' => $label];
}

/**
 * Weryfikuje kod i po sukcesie wchodzi w kontekst użytkownika (ctx_enter_user).
 * Zwraca ['ok'=>bool, 'error'=>string].
 */
function impersonation_verify_and_enter(int $request_id, string $code): array {
    impersonation_ensure_schema();
    $admin = ctx_real_user();
    if (!$admin) return ['ok' => false, 'error' => 'Sesja wygasła.'];

    $reqRow = db_one("SELECT * FROM impersonation_requests WHERE id=? AND admin_user_id=?", [$request_id, (int)$admin['id']]);
    if (!$reqRow) return ['ok' => false, 'error' => 'Nie znaleziono żądania.'];
    if ($reqRow['verified_at']) return ['ok' => false, 'error' => 'Ten kod został już wykorzystany.'];
    if ((int)$reqRow['attempts'] >= 5) return ['ok' => false, 'error' => 'Przekroczono limit prób. Wyślij kod ponownie.'];
    if (strtotime($reqRow['expires_at']) < time()) return ['ok' => false, 'error' => 'Kod wygasł. Wyślij nowy.'];

    $code = trim($code);
    if ($code === '' || $code !== $reqRow['code']) {
        db()->prepare("UPDATE impersonation_requests SET attempts = attempts + 1 WHERE id=?")->execute([$request_id]);
        return ['ok' => false, 'error' => 'Nieprawidłowy kod.'];
    }

    db()->prepare("UPDATE impersonation_requests SET verified_at = datetime('now') WHERE id=?")->execute([$request_id]);

    $ok = ctx_enter_user((int)$reqRow['target_user_id'], $reqRow['reason'], [
        'source_type'  => $reqRow['source_type'],
        'source_id'    => (int)$reqRow['source_id'],
        'verified_via' => $reqRow['method'],
    ]);
    if (!$ok) return ['ok' => false, 'error' => 'Nie udało się wejść na konto.'];

    require_once __DIR__ . '/approval.php';
    $methodLabel = $reqRow['method'] === 'sms' ? 'SMS' : 'e-mail';
    log_contract_action($reqRow['source_type'], (int)$reqRow['source_id'], (int)$admin['id'], 'impersonation',
        "Wejście na konto {$reqRow['target_label']} — powód: {$reqRow['reason']} (potwierdzone kodem: {$methodLabel})");

    return ['ok' => true];
}

} // function_exists guard
