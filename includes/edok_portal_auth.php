<?php
/**
 * includes/edok_portal_auth.php — Portal Kontrahenta (EODoK).
 *
 * Osobna, lekka warstwa uwierzytelniania dla kontrahentów (zewnętrznych,
 * niebędących pracownikami/wolontariuszami organizacji) — konto NIP/e-mail +
 * hasło, sesja izolowana od personelu ($_SESSION['edok_portal_id'], NIE
 * current_user()/is_admin()). Kontrahent nie trafia do tabeli users i nie
 * dostaje żadnych uprawnień w głównej aplikacji.
 *
 * Rejestracja jest bramkowana: NIP musi już występować na co najmniej jednym
 * dokumencie w EODoK lub archiwalnym KDOK — inaczej rejestracja nie ma sensu
 * (portal pokazuje tylko dokumenty tego NIP-u) i mogłaby służyć do zakładania
 * kont "na wyrost" pod cudzy NIP bez żadnej weryfikacji tożsamości. Aktywacja
 * konta wymaga potwierdzenia e-maila (token, mail_queue_add) — to jedyna
 * weryfikacja tożsamości w tym MVP (bez Login.gov.pl/Profilu Zaufanego).
 */

require_once __DIR__ . '/mail_queue.php';

function edok_portal_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS edok_kontrahent_accounts (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        nip                   TEXT    NOT NULL DEFAULT '',
        email                 TEXT    NOT NULL,
        nazwa                 TEXT    NOT NULL DEFAULT '',
        password_hash         TEXT    NOT NULL DEFAULT '',
        status                TEXT    NOT NULL DEFAULT 'oczekuje_weryfikacji',
        verify_token          TEXT    NOT NULL DEFAULT '',
        verify_token_expires  TEXT,
        reset_token           TEXT    NOT NULL DEFAULT '',
        reset_token_expires   TEXT,
        failed_attempts       INTEGER NOT NULL DEFAULT 0,
        locked_until          TEXT,
        created_at            TEXT    NOT NULL DEFAULT '',
        last_login_at         TEXT
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_edok_kontrahent_email ON edok_kontrahent_accounts(email)"); } catch (\Throwable $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS ix_edok_kontrahent_nip ON edok_kontrahent_accounts(nip)"); } catch (\Throwable $e) {}
}

const EDOK_PORTAL_STATUSES = [
    'oczekuje_weryfikacji' => 'Oczekuje na potwierdzenie e-maila',
    'aktywne'              => 'Aktywne',
    'zablokowane'          => 'Zablokowane',
];

/** Czy NIP występuje na jakimkolwiek dokumencie w EODoK lub archiwalnym KDOK. */
function edok_portal_nip_has_documents(string $nip): bool {
    if (db_one("SELECT 1 FROM edok_documents WHERE kontrahent_nip = ?", [$nip])) return true;
    try {
        require_once __DIR__ . '/ksiegowosc.php';
        kdok_migrate();
        if (kdok_one("SELECT 1 FROM kdok_documents WHERE nip_dostawcy = ?", [$nip])) return true;
    } catch (\Throwable $e) {}
    return false;
}

function edok_portal_account_by_email(string $email): ?array {
    return db_one("SELECT * FROM edok_kontrahent_accounts WHERE email = ?", [mb_strtolower(trim($email))]);
}

/**
 * Rejestruje konto (stan 'oczekuje_weryfikacji') i wysyła e-mail aktywacyjny.
 * Zwraca ['ok'=>bool, 'error'=>?string].
 */
function edok_portal_register(string $nip, string $email, string $nazwa, string $password): array {
    edok_portal_migrate();

    $nip   = preg_replace('/\D/', '', $nip);
    $email = mb_strtolower(trim($email));

    if (!function_exists('edok_nip_valid')) require_once __DIR__ . '/edok.php';
    if (!edok_nip_valid($nip)) return ['ok' => false, 'error' => 'Nieprawidłowy NIP.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Nieprawidłowy adres e-mail.'];
    if (mb_strlen($password) < 8) return ['ok' => false, 'error' => 'Hasło musi mieć co najmniej 8 znaków.'];
    if (!edok_portal_nip_has_documents($nip)) {
        return ['ok' => false, 'error' => 'Nie znaleziono żadnego dokumentu powiązanego z tym NIP-em w systemie. Skontaktuj się z organizacją, jeśli spodziewasz się dostępu.'];
    }
    if (edok_portal_account_by_email($email)) {
        return ['ok' => false, 'error' => 'Konto z tym adresem e-mail już istnieje.'];
    }

    $token = bin2hex(random_bytes(32));
    $id = db_insert('edok_kontrahent_accounts', [
        'nip'                  => $nip,
        'email'                => $email,
        'nazwa'                => trim($nazwa),
        'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'status'               => 'oczekuje_weryfikacji',
        'verify_token'         => $token,
        'verify_token_expires' => date('Y-m-d H:i:s', strtotime('+48 hours')),
        'created_at'           => date('Y-m-d H:i:s'),
    ]);

    $verify_url = APP_URL . '/portal_kontrahenta/verify.php?token=' . $token;
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    mail_queue_add(
        $email, trim($nazwa),
        'Potwierdź konto w Portalu Kontrahenta — ' . $org,
        '<p>Dzień dobry,</p><p>Aby aktywować konto w Portalu Kontrahenta ' . h($org) . ', kliknij poniższy link (ważny 48 godzin):</p>'
        . '<p><a href="' . h($verify_url) . '">' . h($verify_url) . '</a></p>'
        . '<p>Jeśli to nie Ty zakładałeś/aś to konto, zignoruj tę wiadomość.</p>'
    );

    return ['ok' => true, 'error' => null];
}

function edok_portal_verify(string $token): bool {
    edok_portal_migrate();
    $acc = db_one("SELECT * FROM edok_kontrahent_accounts WHERE verify_token = ? AND verify_token != ''", [$token]);
    if (!$acc) return false;
    if (strtotime($acc['verify_token_expires']) < time()) return false;
    db_exec("UPDATE edok_kontrahent_accounts SET status='aktywne', verify_token='', verify_token_expires=NULL WHERE id=?", [$acc['id']]);
    return true;
}

/** Loguje po e-mailu LUB NIP (gdy NIP ma dokładnie jedno aktywne konto). Blokada 15 min po 5 nieudanych próbach. */
function edok_portal_login(string $identifier, string $password): array {
    edok_portal_migrate();
    $identifier = trim($identifier);

    $acc = filter_var($identifier, FILTER_VALIDATE_EMAIL)
        ? edok_portal_account_by_email($identifier)
        : (function () use ($identifier) {
            $nip = preg_replace('/\D/', '', $identifier);
            $rows = db_all("SELECT * FROM edok_kontrahent_accounts WHERE nip = ? AND status = 'aktywne'", [$nip]);
            return count($rows) === 1 ? $rows[0] : null;
        })();

    if (!$acc) return ['ok' => false, 'error' => 'Nieprawidłowy login lub hasło.'];

    if (!empty($acc['locked_until']) && strtotime($acc['locked_until']) > time()) {
        return ['ok' => false, 'error' => 'Konto tymczasowo zablokowane po zbyt wielu nieudanych próbach. Spróbuj ponownie za kilkanaście minut.'];
    }
    if ($acc['status'] === 'zablokowane') {
        return ['ok' => false, 'error' => 'Konto zostało zablokowane. Skontaktuj się z organizacją.'];
    }
    if ($acc['status'] === 'oczekuje_weryfikacji') {
        return ['ok' => false, 'error' => 'Potwierdź adres e-mail — sprawdź skrzynkę pocztową.'];
    }

    if (!password_verify($password, $acc['password_hash'])) {
        $fails = (int)$acc['failed_attempts'] + 1;
        $locked_until = $fails >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
        db_exec("UPDATE edok_kontrahent_accounts SET failed_attempts=?, locked_until=? WHERE id=?", [$fails, $locked_until, $acc['id']]);
        return ['ok' => false, 'error' => 'Nieprawidłowy login lub hasło.'];
    }

    db_exec("UPDATE edok_kontrahent_accounts SET failed_attempts=0, locked_until=NULL, last_login_at=datetime('now') WHERE id=?", [$acc['id']]);

    auth_start();
    session_regenerate_id(true);
    $_SESSION['edok_portal_id'] = (int)$acc['id'];

    return ['ok' => true, 'error' => null];
}

function edok_portal_current(): ?array {
    auth_start();
    $id = $_SESSION['edok_portal_id'] ?? 0;
    if (!$id) return null;
    edok_portal_migrate();
    $acc = db_one("SELECT * FROM edok_kontrahent_accounts WHERE id = ? AND status = 'aktywne'", [$id]);
    return $acc ?: null;
}

function edok_portal_require_login(): void {
    if (!edok_portal_current()) {
        header('Location: ' . APP_URL . '/portal_kontrahenta/login.php');
        exit;
    }
}

function edok_portal_logout(): void {
    auth_start();
    unset($_SESSION['edok_portal_id']);
    session_regenerate_id(true);
}

function edok_portal_request_reset(string $email): void {
    edok_portal_migrate();
    $acc = edok_portal_account_by_email($email);
    if (!$acc || $acc['status'] !== 'aktywne') return; // nie ujawniaj czy konto istnieje

    $token = bin2hex(random_bytes(32));
    db_exec("UPDATE edok_kontrahent_accounts SET reset_token=?, reset_token_expires=? WHERE id=?",
        [$token, date('Y-m-d H:i:s', strtotime('+2 hours')), $acc['id']]);

    $reset_url = APP_URL . '/portal_kontrahenta/reset.php?token=' . $token;
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    mail_queue_add(
        $acc['email'], $acc['nazwa'],
        'Reset hasła — Portal Kontrahenta ' . $org,
        '<p>Dzień dobry,</p><p>Otrzymaliśmy prośbę o reset hasła do Portalu Kontrahenta. Link ważny jest 2 godziny:</p>'
        . '<p><a href="' . h($reset_url) . '">' . h($reset_url) . '</a></p>'
        . '<p>Jeśli to nie Ty prosiłeś/aś o reset, zignoruj tę wiadomość.</p>'
    );
}

function edok_portal_reset_password(string $token, string $new_password): array {
    edok_portal_migrate();
    if (mb_strlen($new_password) < 8) return ['ok' => false, 'error' => 'Hasło musi mieć co najmniej 8 znaków.'];

    $acc = db_one("SELECT * FROM edok_kontrahent_accounts WHERE reset_token = ? AND reset_token != ''", [$token]);
    if (!$acc || strtotime($acc['reset_token_expires']) < time()) {
        return ['ok' => false, 'error' => 'Link do resetu hasła jest nieprawidłowy lub wygasł.'];
    }

    db_exec(
        "UPDATE edok_kontrahent_accounts SET password_hash=?, reset_token='', reset_token_expires=NULL, failed_attempts=0, locked_until=NULL WHERE id=?",
        [password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]), $acc['id']]
    );
    return ['ok' => true, 'error' => null];
}

/** Przyjazny, zewnętrzny opis statusu — bez ujawniania wewnętrznych ról/etapów. */
function edok_portal_friendly_status(array $row): string {
    if ($row['status'] === 'odrzucony')  return 'Odrzucony';
    if ($row['status'] === 'wycofany')   return 'Wycofany';
    if ($row['status'] === 'zaakceptowany') {
        return match($row['status_platnosci'] ?? 'nowy') {
            'oplacony'      => 'Opłacony',
            'zlecony'       => 'Płatność zlecona do banku',
            'do_realizacji' => 'Zaakceptowany, płatność w realizacji',
            'wstrzymany'    => 'Płatność wstrzymana',
            default         => 'Zaakceptowany, oczekuje na płatność',
        };
    }
    return 'W trakcie weryfikacji';
}

/** Dokumenty (EODoK + archiwalny KDOK) powiązane z NIP-em zalogowanego konta. */
function edok_portal_documents(string $nip): array {
    $out = [];
    foreach (db_all("SELECT * FROM edok_documents WHERE kontrahent_nip = ? ORDER BY id DESC", [$nip]) as $r) {
        $out[] = [
            'source'  => 'edok',
            'number'  => $r['number'],
            'title'   => $r['nr_faktury'] ?: $r['title'],
            'kwota'   => $r['kwota_brutto'],
            'waluta'  => $r['waluta'] ?: 'PLN',
            'termin'  => $r['termin_platnosci'],
            'data'    => $r['data_wystawienia'] ?: $r['created_at'],
            'status'  => edok_portal_friendly_status($r),
        ];
    }
    try {
        require_once __DIR__ . '/ksiegowosc.php';
        kdok_migrate();
        foreach (kdok_all("SELECT * FROM kdok_documents WHERE nip_dostawcy = ? ORDER BY id DESC", [$nip]) as $r) {
            $out[] = [
                'source'  => 'kdok',
                'number'  => $r['number'],
                'title'   => $r['nr_faktury'] ?: $r['title'],
                'kwota'   => $r['kwota_brutto'] ?: $r['kwota'],
                'waluta'  => $r['waluta'] ?: 'PLN',
                'termin'  => $r['termin_platnosci'] ?? '',
                'data'    => $r['created_at'],
                'status'  => edok_portal_friendly_status($r),
            ];
        }
    } catch (\Throwable $e) {}
    usort($out, fn($a, $b) => strcmp($b['data'] ?? '', $a['data'] ?? ''));
    return $out;
}
