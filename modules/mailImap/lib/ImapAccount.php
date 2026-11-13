<?php
declare(strict_types=1);

/**
 * modules/mailImap/lib/ImapAccount.php — dane logowania do skrzynki IMAP (serwer, port, szyfrowanie, login, hasło) i odczyt
 * nowych wiadomości kursorem UID.
 *
 * Hasło zawsze zaszyfrowane (fcEncrypt, kontekst podany przez wywołującego: np. 'settings.tasks_mail_imap_pass' albo
 * 'task_mail_imap.pass'); nie trafia do komunikatów ani dzienników. Domyślny serwer: IMAP nowej poczty (DhostingConfig,
 * imap.dpoczta.pl).
 *
 * Kursor: [uidvalidity, last_uid]. Pierwszy odczyt (uidvalidity = 0) tylko ustawia kursor na koniec skrzynki — przetwarzamy
 * pocztę, która przyjdzie od tej chwili. Zmiana UIDVALIDITY (skrzynka odtworzona na serwerze) także przestawia kursor na
 * koniec; duplikaty i tak odsiewa Message-ID po stronie modułu.
 */
require_once __DIR__ . '/ImapReader.php';
require_once __DIR__ . '/ImapMessage.php';
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

final class ImapAccount
{
    public const DEFAULT_HOST = 'imap.dpoczta.pl';

    public function __construct(
        public string $host,
        public int $port,
        public string $security,
        public string $user,
        private string $pass,
    ) {}

    /** Serwer domyślny: ustawienie nowej poczty (dhosting) albo imap.dpoczta.pl. */
    public static function defaultHost(): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingConfig.php';
            if (class_exists('DhostingConfig')) return DhostingConfig::imapForUsers();
        } catch (\Throwable $e) {}
        return [self::DEFAULT_HOST, 993];
    }

    /**
     * Sprawdza i normalizuje dane z formularza. $oldEnc — zapisane (zaszyfrowane) hasło, gdy pole hasła zostało puste.
     * @return array{host:string,port:int,security:string,user:string,pass_enc:string}
     */
    public static function normalize(array $in, string $cryptCtx, string $oldEnc = ''): array
    {
        $host = strtolower(trim((string)($in['host'] ?? '')));
        if ($host === '') $host = self::defaultHost()[0];
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) && !ImapReader::isLocal($host)) {
            throw new InvalidArgumentException('Serwer IMAP: sama nazwa hosta, np. imap.dpoczta.pl.');
        }
        $security = (string)($in['security'] ?? 'ssl');
        if (!isset(ImapReader::SECURITY[$security]) && !($security === 'none' && ImapReader::isLocal($host))) $security = 'ssl';
        $port = (int)($in['port'] ?? 0) ?: ($security === 'ssl' ? 993 : 143);
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Port IMAP: liczba od 1 do 65535.');
        $user = trim((string)($in['user'] ?? ''));
        if ($user === '') throw new InvalidArgumentException('Podaj login do skrzynki (zwykle pełny adres e-mail).');
        $pass = (string)($in['pass'] ?? '');
        $enc = $pass !== '' ? (string)fcEncrypt($pass, $cryptCtx) : $oldEnc;
        if ($enc === '') throw new InvalidArgumentException('Podaj hasło do skrzynki.');
        return ['host' => $host, 'port' => $port, 'security' => $security, 'user' => $user, 'pass_enc' => $enc];
    }

    /** Konto z zapisanych pól (hasło zaszyfrowane). */
    public static function fromStored(array $row, string $cryptCtx): ?self
    {
        $enc = (string)($row['pass_enc'] ?? '');
        if (trim((string)($row['host'] ?? '')) === '' || trim((string)($row['user'] ?? '')) === '' || $enc === '') return null;
        try { $pass = (string)fcDecrypt($enc, $cryptCtx); } catch (\Throwable $e) { return null; }
        if ($pass === '') return null;
        return new self((string)$row['host'], (int)($row['port'] ?: 993), (string)($row['security'] ?: 'ssl'), (string)$row['user'], $pass);
    }

    /** Połączenie z zalogowaniem. */
    public function open(int $timeout = 30): ImapReader
    {
        $r = new ImapReader($this->host, $this->port, $this->security, $timeout);
        $r->connect();
        $r->login($this->user, $this->pass);
        return $r;
    }

    /** Test logowania: null = działa, inaczej komunikat dla użytkownika. */
    public function test(): ?string
    {
        try {
            $r = $this->open(15);
            $r->select('INBOX');
            $r->logout();
            return null;
        } catch (ImapException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Nowe wiadomości od kursora. Zwraca ['cursor' => [uidvalidity, last_uid], 'messages' => [uid => ImapMessage|null],
     * 'started' => bool (pierwszy odczyt: tylko ustawiono kursor)]. null w messages = wiadomość za duża albo nie do pobrania.
     * Kursor przesuwa się tylko o pobrane wiadomości (najwyżej $limit na przebieg).
     */
    public function fetchNew(ImapReader $r, int $uidValidity, int $lastUid, int $limit = 50): array
    {
        $box = $r->select('INBOX');
        $uv = max(1, (int)$box['uidvalidity']);
        // Kursor nieustawiony (uidvalidity 0) albo skrzynka odtworzona na serwerze → koniec skrzynki, bez starej poczty
        if ($uidValidity <= 0 || $uv !== $uidValidity) {
            $end = $box['uidnext'] > 0 ? $box['uidnext'] - 1 : (int)max([0, ...$r->uidsAfter(0)]);
            return ['cursor' => [$uv, max(0, $end)], 'messages' => [], 'started' => true];
        }
        $uids = array_slice($r->uidsAfter($lastUid), 0, $limit);
        $sizes = $r->sizes($uids);
        $out = [];
        foreach ($uids as $uid) {
            if (($sizes[$uid] ?? 0) > ImapReader::MAX_BYTES) { $out[$uid] = null; continue; }
            $raw = $r->fetchRaw($uid);
            $out[$uid] = $raw !== null ? ImapMessage::parse($raw) : null;
        }
        $last = $uids ? max($uids) : $lastUid;
        return ['cursor' => [$uv, $last], 'messages' => $out, 'started' => false];
    }
}
