<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingConfig.php — ustawienia migratora kont Microsoft 365 → skrzynki dhosting.pl.
 *
 * Klucze w tabeli settings (org_setting):
 *   dhosting_api_url      — adres API (domyślnie https://api.dhosting.pl)
 *   dhosting_api_user     — login API od dhosting.pl
 *   dhosting_api_pass     — hasło API, zaszyfrowane fcEncrypt (kontekst settings.dhosting_api_pass)
 *   dhosting_domains      — obsługiwane domeny, po jednej w linii: „feer.org.pl” albo „domena-m365 = domena-dhosting”
 *   dhosting_quota        — pojemność nowej skrzynki w GB ('' = domyślna usługi, -1 = bez limitu)
 *   dhosting_self_enabled — '1' = użytkownik może sam założyć skrzynkę / dostać nowe hasło SMS-em
 *   dhosting_webmail_url  — adres poczty przez przeglądarkę, dopisywany do SMS i ekranów ('' = bez linku)
 *   dhosting_imap_host    — serwer IMAP skrzynek dhosting (do kopiowania poczty z Microsoft 365; '' = domyślny imap.dpoczta.pl)
 *   dhosting_imap_port    — port IMAP z SSL (domyślnie 993)
 *   dhosting_switch_date  — data przełączenia poczty (RRRR-MM-DD, pokazywana użytkownikom na /migracjapoczty; '' = „wkrótce”)
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';
require_once __DIR__ . '/DhostingApi.php';
require_once __DIR__ . '/DhostingImap.php';

final class DhostingConfig
{
    private const PASS_CTX = 'settings.dhosting_api_pass';
    /** Serwer IMAP poczty dhosting (sprawdzony 2026-11-13: port 993, certyfikat *.dpoczta.pl; MX domen to mx-1/mx-2.dpoczta.pl). */
    public const DEFAULT_IMAP = 'imap.dpoczta.pl';
    /** Serwer wysyłki poczty dhosting (port 465 SSL, sprawdzony 2026-11-13) — do instrukcji dla użytkowników. */
    public const SMTP_HOST = 'smtp.dpoczta.pl';
    // 587 + STARTTLS (sprawdzone 13.11.2026: na 465 serwer odpowiada zwykłym SMTP bez TLS — „SSL na 465” w programach pocztowych nie działa)
    public const SMTP_PORT = 587;
    /** Serwer POP3 (instrukcja dostawcy, 2026-11-13: 110 bez szyfrowania, 995 SSL) — tylko do opisu dla użytkowników. */
    public const POP3_HOST = 'pop3.dpoczta.pl';

    public static function get(string $key, string $default = ''): string
    {
        $v = function_exists('org_setting') ? org_setting($key) : '';
        return $v !== '' ? $v : $default;
    }

    public static function api(): DhostingApi
    {
        $pass = '';
        $enc = self::get('dhosting_api_pass');
        if ($enc !== '') {
            try { $pass = (string)fcDecrypt($enc, self::PASS_CTX); } catch (\Throwable $e) { $pass = ''; }
        }
        return new DhostingApi(self::get('dhosting_api_url', DhostingApi::DEFAULT_URL), self::get('dhosting_api_user'), $pass);
    }

    public static function hasPassword(): bool
    {
        return self::get('dhosting_api_pass') !== '';
    }

    /** Zapis z formularza admina. Puste hasło = bez zmiany. */
    public static function save(array $in): void
    {
        $url = trim((string)($in['api_url'] ?? ''));
        if ($url !== '' && !preg_match('~^https://[a-z0-9.-]+(:\d+)?(/.*)?$~i', $url)) {
            throw new InvalidArgumentException('Adres API musi zaczynać się od https://.');
        }
        $map = self::parseDomains((string)($in['domains'] ?? ''));
        $quota = trim(str_replace(',', '.', (string)($in['quota'] ?? '')));
        if ($quota !== '' && $quota !== '-1' && (!is_numeric($quota) || (float)$quota < 0.1)) {
            throw new InvalidArgumentException('Pojemność skrzynki: liczba GB (np. 2), -1 = bez limitu albo puste pole = domyślna usługi.');
        }
        $web = trim((string)($in['webmail_url'] ?? ''));
        if ($web !== '' && !preg_match('~^https?://~i', $web)) throw new InvalidArgumentException('Adres poczty przez przeglądarkę musi zaczynać się od https://.');

        org_setting_set('dhosting_api_url', $url);
        org_setting_set('dhosting_api_user', trim((string)($in['api_user'] ?? '')));
        $pass = (string)($in['api_pass'] ?? '');
        if ($pass !== '') org_setting_set('dhosting_api_pass', (string)fcEncrypt($pass, self::PASS_CTX));
        org_setting_set('dhosting_domains', implode("\n", array_map(
            fn($src, $dst) => $src === $dst ? $src : $src . ' = ' . $dst, array_keys($map), $map)));
        org_setting_set('dhosting_quota', $quota);
        org_setting_set('dhosting_self_enabled', !empty($in['self_enabled']) ? '1' : '0');
        org_setting_set('dhosting_webmail_url', $web);

        $imap = strtolower(trim((string)($in['imap_host'] ?? '')));
        if ($imap !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $imap)) throw new InvalidArgumentException('Serwer IMAP: sama nazwa hosta, np. mail.example.pl.');
        $port = trim((string)($in['imap_port'] ?? ''));
        if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) throw new InvalidArgumentException('Port IMAP musi być liczbą (zwykle 993).');
        org_setting_set('dhosting_imap_host', $imap);
        org_setting_set('dhosting_imap_port', $port);

        $date = trim((string)($in['switch_date'] ?? ''));
        if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) throw new InvalidArgumentException('Data przełączenia w formacie RRRR-MM-DD.');
        org_setting_set('dhosting_switch_date', $date);
    }

    /** Data przełączenia poczty (znacznik czasu) albo null, gdy nieustalona. */
    public static function switchDate(): ?int
    {
        $d = self::get('dhosting_switch_date');
        return $d !== '' ? (strtotime($d . ' 00:00:00') ?: null) : null;
    }

    /** Serwer i port IMAP do instrukcji dla użytkownika (bez adresów testowych tcp://). */
    public static function imapForUsers(): array
    {
        $h = self::get('dhosting_imap_host', self::DEFAULT_IMAP);
        if (str_contains($h, '://')) $h = self::DEFAULT_IMAP;
        return [$h, (int)(self::get('dhosting_imap_port') ?: 993)];
    }

    /** Adres serwera IMAP do kopiowania poczty (puste ustawienie = imap.dpoczta.pl). Ustawienie może też być pełnym adresem ssl:// / tcp:// (testy). */
    public static function imapAddress(): string
    {
        $host = self::get('dhosting_imap_host', self::DEFAULT_IMAP);
        return $host === '' ? '' : DhostingImap::address($host, (int)(self::get('dhosting_imap_port') ?: 993));
    }

    /** Mapa domena M365 → domena dhosting. */
    public static function domains(): array
    {
        return self::parseDomains(self::get('dhosting_domains'));
    }

    public static function parseDomains(string $text): array
    {
        $map = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '') continue;
            $parts = array_map('trim', preg_split('/\s*(=>|=|→)\s*/u', $line) ?: []);
            $src = $parts[0] ?? '';
            $dst = $parts[1] ?? $src;
            foreach ([$src, $dst] as $d) {
                if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) throw new InvalidArgumentException('Niepoprawna domena: „' . $d . '”.');
            }
            $map[$src] = $dst;
        }
        return $map;
    }

    /** Adres skrzynki na dhosting dla loginu Microsoft (ten sam login, domena wg mapy). null = domena nieobsługiwana. */
    public static function mailboxFor(string $m365Login): ?string
    {
        $l = strtolower(trim($m365Login));
        $at = strrpos($l, '@');
        if ($at === false) return null;
        $local = substr($l, 0, $at);
        $dst = self::domains()[substr($l, $at + 1)] ?? null;
        if ($dst === null || $local === '' || str_contains($local, '#')) return null;   // #EXT# = konto gościa
        $box = $local . '@' . $dst;
        return filter_var($box, FILTER_VALIDATE_EMAIL) ? $box : null;
    }

    /** Pojemność nowej skrzynki: null = domyślna usługi. */
    public static function quota(): ?float
    {
        $q = self::get('dhosting_quota');
        return $q === '' ? null : (float)$q;
    }

    public static function selfEnabled(): bool
    {
        return self::get('dhosting_self_enabled') === '1';
    }

    public static function webmail(): string
    {
        return self::get('dhosting_webmail_url');
    }

    /** Domyślne logowanie do nowej poczty, gdy w konfiguracji nie wpisano adresu (polecenie 2026-11-13: „logowanie dpoczta.pl”). */
    public const DEFAULT_WEBMAIL = 'https://dpoczta.pl';

    /** Adres logowania dla osób (strona migracji, SMS-y, e-mail po przeniesieniu): ustawienie albo dpoczta.pl. Formularz konfiguracji używa webmail(). */
    public static function webmailOrDefault(): string
    {
        $w = self::webmail();
        return $w !== '' ? $w : self::DEFAULT_WEBMAIL;
    }

    /**
     * Hasło do nowej skrzynki: 14 znaków bez łatwych do pomylenia (0/O, 1/l/I), zawsze z wielką i małą literą,
     * cyfrą i znakiem specjalnym — polityka dhosting nie jest opisana w dokumentacji, przykład to „P@ssword34”.
     */
    public static function generatePassword(int $len = 14): string
    {
        $sets = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '#%+=?!'];
        $all  = implode('', $sets);
        $chars = [];
        foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];
        while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) {          // tasowanie Fishera–Yatesa na random_int
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
