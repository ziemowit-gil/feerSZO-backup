<?php
declare(strict_types=1);

/**
 * modules/userSmtp/lib/UserSmtp.php — wysyłka z WŁASNEJ skrzynki użytkownika (dPoczta) przez SMTP.
 *
 * Zastępuje „Moją skrzynkę” przez Microsoft 365 (Graph) — Fundacja odchodzi od M365 (poczta → dPoczta 13.11.2026).
 * Serwer: smtp.dpoczta.pl:587 + STARTTLS (DhostingConfig::SMTP_HOST/SMTP_PORT), login = adres skrzynki.
 *
 * Hasło do poczty jest INNE niż hasło logowania jednokrotnego (zasada /nowehaslo), więc użytkownik podaje je raz
 * (crm/moja_skrzynka.php) — sprawdzamy je logowaniem SMTP i zapisujemy zaszyfrowane (fcEncrypt, kontekst 'user_smtp.pass').
 * Po KAŻDEJ zmianie hasła do poczty trzeba je podać ponownie:
 *  - zmiana na /nowehaslo → passwordChanged() oznacza zapis jako nieaktualny (status 'changed'),
 *  - serwer odrzuci logowanie (np. hasło zmienione gdzie indziej) → authFailed() (status 'auth_failed').
 * Wiadomość z nieaktualnym hasłem NIE wychodzi z innego konta (nie podszywamy się) — kolejka zapisze błąd.
 *
 * Kolejka (includes/mail_queue.php): kolumna mail_queue.transport = 'user:<id>' → _mail_send() wysyła tylko tędy.
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

final class UserSmtp
{
    private const CTX = 'user_smtp.pass';
    private const HOST = 'smtp.dpoczta.pl';
    private const PORT = 587;

    public static function schema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS user_smtp_accounts (
            user_id    INTEGER PRIMARY KEY,
            email      TEXT NOT NULL,
            pass_enc   TEXT NOT NULL,
            status     TEXT NOT NULL DEFAULT 'ok',      -- ok | changed | auth_failed
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_error TEXT NOT NULL DEFAULT ''
        )");
    }

    /** Serwer SMTP nowej poczty: [host, port] (stałe z DhostingConfig, gdy dostępne). */
    public static function server(): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingConfig.php';
            if (class_exists('DhostingConfig')) return [DhostingConfig::SMTP_HOST, DhostingConfig::SMTP_PORT];
        } catch (\Throwable $e) {}
        return [self::HOST, self::PORT];
    }

    /** Adres skrzynki dPoczta użytkownika (mapowanie domen z migratora; inaczej adres konta). '' = brak skrzynki Fundacji. */
    public static function mailboxOf(array $user): string
    {
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
        try {
            require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingConfig.php';
            if (class_exists('DhostingConfig')) {
                $box = DhostingConfig::mailboxFor($email);
                if ($box) return $box;
                if (in_array(substr($email, strrpos($email, '@') + 1), array_values(DhostingConfig::domains()), true)) return $email;
            }
        } catch (\Throwable $e) {}
        // Bez konfiguracji migratora: skrzynka w domenie organizacji
        $org = defined('ORG_EMAIL_DOMAIN') ? strtolower((string)ORG_EMAIL_DOMAIN) : 'feer.org.pl';
        return str_ends_with($email, '@' . $org) ? $email : '';
    }

    /** Zapis użytkownika (bez hasła) albo null. */
    public static function get(int $userId): ?array
    {
        self::schema();
        $r = db_one("SELECT user_id, email, status, updated_at, last_error FROM user_smtp_accounts WHERE user_id = ?", [$userId]);
        return $r ?: null;
    }

    /** Czy można teraz wysłać z własnej skrzynki (hasło zapisane i aktualne). */
    public static function ready(int $userId): bool
    {
        $r = self::get($userId);
        return $r !== null && $r['status'] === 'ok';
    }

    /**
     * Sprawdza hasło logowaniem SMTP i zapisuje je zaszyfrowane.
     * @throws RuntimeException z komunikatem dla użytkownika
     */
    public static function save(int $userId, string $email, string $password): void
    {
        self::schema();
        if ($password === '') throw new RuntimeException('Wpisz hasło do poczty.');
        self::verify($email, $password);
        db()->prepare("INSERT INTO user_smtp_accounts (user_id, email, pass_enc, status, updated_at, last_error)
                       VALUES (?,?,?,'ok',datetime('now'),'')
                       ON CONFLICT(user_id) DO UPDATE SET email=excluded.email, pass_enc=excluded.pass_enc, status='ok',
                         updated_at=excluded.updated_at, last_error=''")
            ->execute([$userId, $email, (string)fcEncrypt($password, self::CTX)]);
    }

    public static function forget(int $userId): void
    {
        self::schema();
        db()->prepare("DELETE FROM user_smtp_accounts WHERE user_id = ?")->execute([$userId]);
    }

    /** Hasło do skrzynki zmienione (np. na /nowehaslo) — zapisane hasło przestaje obowiązywać. */
    public static function passwordChanged(string $mailbox): void
    {
        self::schema();
        db()->prepare("UPDATE user_smtp_accounts SET status='changed', last_error='Hasło do poczty zostało zmienione.' WHERE lower(email) = lower(?)")
            ->execute([trim($mailbox)]);
    }

    public static function authFailed(int $userId, string $error): void
    {
        self::schema();
        db()->prepare("UPDATE user_smtp_accounts SET status='auth_failed', last_error=? WHERE user_id = ?")
            ->execute([mb_substr($error, 0, 300), $userId]);
    }

    /**
     * Dane do _mail_send_smtp(): ['host','port','user','pass','encryption','from'] albo wyjątek z powodem
     * (brak / nieaktualne hasło) — wtedy wiadomość nie wychodzi z innego konta.
     */
    public static function credentials(int $userId): array
    {
        self::schema();
        $r = db_one("SELECT * FROM user_smtp_accounts WHERE user_id = ?", [$userId]);
        if (!$r) throw new RuntimeException('Własna skrzynka: brak zapisanego hasła do poczty (CRM → Moja skrzynka).');
        if ($r['status'] !== 'ok') throw new RuntimeException('Własna skrzynka: hasło do poczty jest nieaktualne — podaj je ponownie (CRM → Moja skrzynka).');
        $pass = (string)fcDecrypt((string)$r['pass_enc'], self::CTX);
        if ($pass === '') throw new RuntimeException('Własna skrzynka: nie udało się odczytać hasła — podaj je ponownie (CRM → Moja skrzynka).');
        [$host, $port] = self::server();
        return ['host' => $host, 'port' => $port, 'user' => (string)$r['email'], 'pass' => $pass, 'encryption' => 'tls', 'from' => (string)$r['email']];
    }

    /** Czy błąd SMTP to odrzucone logowanie (535 / AUTH failed). */
    public static function isAuthError(string $msg): bool
    {
        return str_contains($msg, 'AUTH failed') || str_contains($msg, 'AUTH LOGIN failed') || preg_match('/\b535\b/', $msg) === 1;
    }

    /** Logowanie SMTP bez wysyłki (EHLO, STARTTLS, AUTH LOGIN, QUIT). */
    private static function verify(string $email, string $password): void
    {
        [$host, $port] = self::server();
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
        if (!$sock) throw new RuntimeException('Nie udało się połączyć z serwerem poczty. Spróbuj za kilka minut.');
        stream_set_timeout($sock, 15);
        $cmd = function (?string $line) use ($sock): string {
            if ($line !== null) fwrite($sock, $line . "\r\n");
            $out = '';
            do { $l = (string)fgets($sock, 512); $out = $l; } while ($l !== '' && strlen($l) > 3 && $l[3] === '-');
            return trim($out);
        };
        try {
            $cmd(null);                                   // 220
            $ehlo = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: 'localhost';
            $cmd("EHLO {$ehlo}");
            if (!str_starts_with($cmd('STARTTLS'), '220')) throw new RuntimeException('Serwer poczty nie zestawił szyfrowanego połączenia. Spróbuj za kilka minut.');
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Serwer poczty nie zestawił szyfrowanego połączenia. Spróbuj za kilka minut.');
            $cmd("EHLO {$ehlo}");
            if (!str_starts_with($cmd('AUTH LOGIN'), '334')) throw new RuntimeException('Serwer poczty nie przyjmuje logowania. Spróbuj za kilka minut.');
            $cmd(base64_encode($email));
            if (!str_starts_with($cmd(base64_encode($password)), '235')) throw new RuntimeException('Serwer poczty nie przyjął hasła. Sprawdź hasło do poczty (to inne hasło niż do logowania jednokrotnego).');
            $cmd('QUIT');
        } finally {
            fclose($sock);
        }
    }
}
