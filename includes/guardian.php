<?php
/**
 * includes/guardian.php — Powiązanie konta niepełnoletniego wolontariusza (dziecko)
 * z kontem opiekuna (rodzic) przez users.guardian_user_id.
 *
 * Powiązanie pozwala rodzicowi wejść w kontekst dziecka (zob. includes/context.php
 * → ctx_enter_child). Dane rodzica pochodzą z umowy wolontariatu
 * (rodzic_email / rodzic_imie_nazwisko), gdy niepelnoletni=1.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('guardian_ensure_column')) {

/** Idempotentnie dokłada kolumnę users.guardian_user_id. */
function guardian_ensure_column(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE users ADD COLUMN guardian_user_id INTEGER NULL"); } catch (\Throwable $e) {}
}

/** Znajdź aktywne konto użytkownika po e-mailu lub loginie M365. */
function guardian_find_user(?string $email, ?string $m365 = null): ?array {
    $email = trim((string)$email);
    $m365  = trim((string)$m365);
    try {
        if ($email !== '') {
            $u = db_one("SELECT id, name, email FROM users WHERE email=? AND is_active=1", [$email]);
            if ($u) return $u;
        }
        if ($m365 !== '') {
            $u = db_one("SELECT id, name, email FROM users WHERE email=? AND is_active=1", [$m365]);
            if ($u) return $u;
        }
    } catch (\Throwable $e) {}
    return null;
}

/** Ustaw powiązanie dziecko → rodzic. Zwraca true/false. */
function guardian_link(int $child_uid, int $parent_uid): bool {
    if ($child_uid <= 0 || $parent_uid <= 0 || $child_uid === $parent_uid) return false;
    guardian_ensure_column();
    try {
        db()->prepare("UPDATE users SET guardian_user_id=? WHERE id=?")->execute([$parent_uid, $child_uid]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Powiąż dziecko↔rodzic po adresach e-mail (oba konta muszą istnieć). */
function guardian_link_by_email(string $child_email, string $rodzic_email, ?string $child_m365 = null): bool {
    $child  = guardian_find_user($child_email, $child_m365);
    $parent = guardian_find_user($rodzic_email);
    if (!$child || !$parent) return false;
    return guardian_link((int)$child['id'], (int)$parent['id']);
}

/**
 * Utwórz (jeśli brak) konto opiekuna i wyślij link aktywacyjny. Zwraca id konta
 * rodzica lub null. Slim wariant — niezależny od _wolontariat_provision_account().
 */
function guardian_provision_parent_account(string $email, string $name): ?int {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $existing = guardian_find_user($email);
    if ($existing) return (int)$existing['id'];

    try {
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
        db_insert('users', [
            'name'       => $name ?: $email,
            'email'      => $email,
            'password'   => $hash,
            'role'       => 'viewer',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int)db()->lastInsertId();
    } catch (\Throwable $e) { return null; }

    // Link aktywacyjny + e-mail (best-effort).
    try {
        if (function_exists('auth_generate_setup_token')) {
            $tok = auth_generate_setup_token($uid);
            $url = APP_URL . '/auth/set_password.php?token=' . $tok;
            $org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
            $body = "Witaj {$name},\n\n"
                  . "Utworzono dla Ciebie konto opiekuna w systemie {$org}. Pozwala ono zalogować się "
                  . "i — po wejściu — przełączyć na konto Twojego dziecka (wolontariusza).\n\n"
                  . "Ustaw hasło: {$url}\n\nLink jest jednorazowy.";
            if (function_exists('mail_queue_add')) {
                require_once __DIR__ . '/mail_queue.php';
                mail_queue_add($email, $name ?: $email, "Konto opiekuna — {$org}", nl2br(htmlspecialchars($body)), $body);
            }
        }
    } catch (\Throwable $e) {}

    return $uid;
}

/**
 * Wyznacz konto opiekuna dla umowy wolontariatu i powiąż je z kontem dziecka.
 * Zwraca status: ['ok'=>bool,'msg'=>string,'created_parent'=>bool,'linked'=>bool].
 */
function wolontariat_assign_guardian(int $contract_id): array {
    $res = ['ok'=>false, 'msg'=>'', 'created_parent'=>false, 'linked'=>false];
    try {
        $c = db_one(
            "SELECT id, imie_nazwisko, email, m365_login, niepelnoletni, rodzic_email, rodzic_imie_nazwisko
               FROM umowy_wolontariat WHERE id=?",
            [$contract_id]
        );
    } catch (\Throwable $e) { $c = null; }
    if (!$c) { $res['msg'] = 'Nie znaleziono umowy.'; return $res; }
    if (empty($c['niepelnoletni'])) { $res['msg'] = 'Umowa nie jest oznaczona jako niepełnoletni.'; return $res; }
    $rodzic_email = trim((string)($c['rodzic_email'] ?? ''));
    if ($rodzic_email === '') { $res['msg'] = 'Brak e-maila opiekuna w umowie.'; return $res; }

    // Konto dziecka musi istnieć (zwykle tworzone przy umowie).
    $child = guardian_find_user($c['email'] ?? '', $c['m365_login'] ?? '');
    if (!$child) { $res['msg'] = 'Brak konta dziecka — najpierw utwórz konto wolontariusza.'; return $res; }

    // Konto rodzica — znajdź lub utwórz.
    $parent = guardian_find_user($rodzic_email);
    $parent_uid = $parent ? (int)$parent['id'] : guardian_provision_parent_account($rodzic_email, (string)($c['rodzic_imie_nazwisko'] ?? ''));
    if (!$parent_uid) { $res['msg'] = 'Nie udało się utworzyć konta opiekuna.'; return $res; }
    $res['created_parent'] = !$parent;

    $res['linked'] = guardian_link((int)$child['id'], $parent_uid);
    $res['ok'] = $res['linked'];
    $res['msg'] = $res['linked']
        ? ($res['created_parent'] ? 'Utworzono konto opiekuna i powiązano z kontem dziecka.' : 'Powiązano konto opiekuna z kontem dziecka.')
        : 'Nie udało się powiązać kont.';
    return $res;
}

} // function_exists guard
