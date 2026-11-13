<?php
declare(strict_types=1);

/**
 * modules/gosposia/logic/Gosposia.php — przegląd skrzynki i przeniesienie zatwierdzonych wiadomości do Kosza.
 *
 * Poświadczenia: w sesji PHP hasło zaszyfrowane fcEncrypt (kontekst 'gosposia.session'); przy „Zapamiętaj w SZO” także w gosposia_accounts.
 * Wynik przeglądu w sesji to tylko mapa nadawca → UID-y i rozmiary (bez tematów) + UIDVALIDITY i folder Kosza —
 * POST /kosz przyjmuje wyłącznie UID-y z tej mapy, więc nie da się przenieść niczego, czego użytkownik nie widział na liście.
 */
require_once dirname(__DIR__) . '/lib/GosposiaImap.php';
require_once dirname(__DIR__) . '/lib/GosposiaClassifier.php';
require_once __DIR__ . '/GosposiaStore.php';

final class Gosposia
{
    private const K_CRED = 'gosposiaCred';
    private const K_SCAN = 'gosposiaScan';
    private const SESSION_CTX = 'gosposia.session';
    public const DEFAULT_SCAN_LIMIT = 1500;
    /** Nadawca od tej pewności jest domyślnie zaznaczony do sprzątania. */
    public const PRESELECT_CONFIDENCE = 0.7;

    // ── Poświadczenia ──────────────────────────────────────────────────────────────────────────

    /** @return array{email:string, password:string, remembered:bool}|null */
    public static function credentials(int $userId): ?array
    {
        $c = $_SESSION[self::K_CRED] ?? null;
        if (is_array($c) && ($c['uid'] ?? 0) === $userId) {
            $p = fcDecrypt((string)$c['pass'], self::SESSION_CTX);
            if ($p) return ['email' => (string)$c['email'], 'password' => $p, 'remembered' => (bool)$c['remembered']];
        }
        $a = GosposiaStore::account($userId);
        if (!$a) return null;
        self::keepInSession($userId, $a['email'], $a['password'], true);
        return $a + ['remembered' => true];
    }

    /** Sprawdza logowanie na serwerze i zapisuje poświadczenia (sesja, opcjonalnie baza). */
    public static function connect(int $userId, string $email, string $password, bool $remember): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Podaj poprawny adres skrzynki.');
        if ($password === '') throw new \RuntimeException('Podaj hasło do poczty.');
        $imap = new GosposiaImap();
        $imap->connect();
        $imap->login($email, $password);
        $imap->logout();
        self::keepInSession($userId, $email, $password, $remember);
        unset($_SESSION[self::K_SCAN]);
        if ($remember) GosposiaStore::saveAccount($userId, $email, $password);
        else GosposiaStore::forgetAccount($userId);
        audit_log('gosposia.connect', ['mailbox' => $email, 'remembered' => $remember]);
    }

    public static function disconnect(int $userId): void
    {
        unset($_SESSION[self::K_CRED], $_SESSION[self::K_SCAN]);
        GosposiaStore::forgetAccount($userId);
        audit_log('gosposia.disconnect', []);
    }

    private static function keepInSession(int $userId, string $email, string $password, bool $remembered): void
    {
        $_SESSION[self::K_CRED] = ['uid' => $userId, 'email' => $email, 'pass' => fcEncrypt($password, self::SESSION_CTX), 'remembered' => $remembered];
    }

    private static function open(array $cred): GosposiaImap
    {
        $imap = new GosposiaImap();
        $imap->connect();
        $imap->login($cred['email'], $cred['password']);
        return $imap;
    }

    public static function scanLimit(): int
    {
        $v = 0;
        try { $v = (int)(db_one("SELECT value FROM settings WHERE key_ = 'gosposia_scan_limit'")['value'] ?? 0); } catch (\Throwable) {}
        return $v > 0 ? min($v, 5000) : self::DEFAULT_SCAN_LIMIT;
    }

    // ── Przegląd ───────────────────────────────────────────────────────────────────────────────

    /**
     * Przegląda ostatnie wiadomości w Odebranych i zwraca nadawców do sprzątania.
     * @return array{senders:list<array>, stats:array<string, int|string|bool>}
     */
    public static function scan(int $userId, array $cred, ?GosposiaClassifier $classifier = null, ?GosposiaImap $imap = null): array
    {
        $imap ??= self::open($cred);
        $trash = $imap->findTrash();
        $box = $imap->select('INBOX');
        $all = $imap->uids();
        $limit = self::scanLimit();
        $uids = array_slice($all, -$limit);
        $msgs = $uids ? $imap->headers($uids) : [];
        $imap->logout();

        $patterns = GosposiaStore::patterns($userId);
        $groups = [];
        $whitelistedMsgs = 0;
        foreach ($msgs as $m) {
            $e = (string)$m['from_email'];
            if ($e === '') continue;
            if (GosposiaStore::isWhitelisted($e, $patterns)) { $whitelistedMsgs++; continue; }
            $g = &$groups[$e];
            $g ??= ['email' => $e, 'name' => '', 'count' => 0, 'size' => 0, 'subjects' => [], 'unsubscribe' => false, 'list_id' => false, 'precedence' => '', 'messages' => []];
            if ($g['name'] === '' && $m['from_name'] !== '') $g['name'] = $m['from_name'];
            $g['count']++;
            $g['size'] += (int)$m['size'];
            if (count($g['subjects']) < 5 && $m['subject'] !== '' && !in_array($m['subject'], $g['subjects'], true)) $g['subjects'][] = $m['subject'];
            $g['unsubscribe'] = $g['unsubscribe'] || $m['list_unsubscribe'];
            $g['list_id'] = $g['list_id'] || $m['list_id'] !== '';
            if ($m['precedence'] !== '') $g['precedence'] = $m['precedence'];
            $g['messages'][] = ['uid' => (int)$m['uid'], 'subject' => $m['subject'], 'date' => $m['date'], 'size' => (int)$m['size']];
            unset($g);
        }

        $classifier ??= new GosposiaClassifier();
        $cls = $groups ? $classifier->classify(array_values(array_map(fn($g) => array_diff_key($g, ['messages' => 1, 'size' => 1]), $groups))) : ['results' => [], 'ai_batches' => 0, 'fallback_batches' => 0];

        $senders = [];
        $map = [];
        foreach ($groups as $e => $g) {
            $r = $cls['results'][$e] ?? GosposiaClassifier::heuristic($g);
            if (!$r['is_junk']) continue;
            usort($g['messages'], fn($a, $b) => strcmp($b['date'], $a['date']));
            $senders[] = [
                'email' => $e, 'name' => $g['name'], 'domain' => (string)substr((string)strrchr($e, '@'), 1),
                'category' => $r['category'], 'label' => GosposiaClassifier::CATEGORIES[$r['category']] ?? $r['category'],
                'confidence' => round((float)$r['confidence'], 2), 'reason' => $r['reason'], 'source' => $r['source'],
                'count' => $g['count'], 'size' => $g['size'], 'unsubscribe' => $g['unsubscribe'],
                'preselect' => (float)$r['confidence'] >= self::PRESELECT_CONFIDENCE,
                'messages' => $g['messages'],
            ];
            $map[$e] = array_column($g['messages'], 'size', 'uid');
        }
        usort($senders, fn($a, $b) => [$b['count'], $b['confidence']] <=> [$a['count'], $a['confidence']]);

        $_SESSION[self::K_SCAN] = ['uid' => $userId, 'mailbox' => $cred['email'], 'uidvalidity' => $box['uidvalidity'],
            'trash' => $trash['raw'] ?? '', 'trash_name' => $trash['name'] ?? '', 'senders' => $map, 'at' => time()];

        return ['senders' => $senders, 'stats' => [
            'inbox_total'      => count($all),
            'scanned'          => count($msgs),
            'limited'          => count($all) > $limit,
            'whitelisted'      => $whitelistedMsgs,
            'senders_checked'  => count($groups),
            'ai_batches'       => $cls['ai_batches'],
            'fallback_batches' => $cls['fallback_batches'],
            'trash'            => $trash['name'] ?? '',
        ]];
    }

    /** Usuwa nadawców z białej listy z zapamiętanego przeglądu (żeby nie dało się ich już przenieść). */
    public static function forgetWhitelisted(int $userId): void
    {
        $s = &$_SESSION[self::K_SCAN];
        if (!is_array($s) || ($s['uid'] ?? 0) !== $userId) return;
        $patterns = GosposiaStore::patterns($userId);
        foreach (array_keys($s['senders']) as $e) if (GosposiaStore::isWhitelisted((string)$e, $patterns)) unset($s['senders'][$e]);
    }

    // ── Przeniesienie do Kosza ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string, list<int>> $selection nadawca → UID-y zaznaczone przez użytkownika
     * @return array{moved:int, senders:int, bytes:int, folder:string, method:string}
     */
    public static function moveToTrash(int $userId, array $cred, array $selection, ?GosposiaImap $imap = null): array
    {
        $scan = $_SESSION[self::K_SCAN] ?? null;
        if (!is_array($scan) || ($scan['uid'] ?? 0) !== $userId || $scan['mailbox'] !== $cred['email']) {
            throw new \RuntimeException('Lista wygasła. Przejrzyj skrzynkę jeszcze raz.');
        }
        if ($scan['trash'] === '') throw new \RuntimeException('Nie znaleźliśmy folderu Kosz na tej skrzynce, więc niczego nie przenosimy. Napisz do Helpdesku IT.');
        $patterns = GosposiaStore::patterns($userId);

        $uids = []; $chosen = []; $perSender = []; $bytes = 0;
        foreach ($selection as $email => $list) {
            $email = strtolower((string)$email);
            if (!isset($scan['senders'][$email]) || GosposiaStore::isWhitelisted($email, $patterns)) continue;
            foreach ((array)$list as $u) {
                $u = (int)$u;
                if (!isset($scan['senders'][$email][$u])) continue;   // tylko UID-y z tego przeglądu
                if (isset($uids[$u])) continue;
                $uids[$u] = true;
                $chosen[$email][] = $u;
                $perSender[$email] = ($perSender[$email] ?? 0) + 1;
                $bytes += (int)$scan['senders'][$email][$u];
            }
        }
        if (!$uids) throw new \RuntimeException('Nie zaznaczono żadnej wiadomości.');

        $imap ??= self::open($cred);
        $box = $imap->select('INBOX');
        if ($box['uidvalidity'] !== (int)$scan['uidvalidity']) {
            $imap->logout();
            unset($_SESSION[self::K_SCAN]);
            throw new \RuntimeException('Skrzynka zmieniła się od przeglądu. Przejrzyj ją jeszcze raz.');
        }
        $method = $imap->moveToTrash(array_keys($uids), (string)$scan['trash']);
        $imap->logout();

        foreach ($chosen as $email => $list) {
            foreach ($list as $u) unset($_SESSION[self::K_SCAN]['senders'][$email][$u]);
            if (empty($_SESSION[self::K_SCAN]['senders'][$email])) unset($_SESSION[self::K_SCAN]['senders'][$email]);
        }
        // Ślad audytowy: kto (user_id w audit_logs), kiedy, ile, od jakich adresów — bez tematów i treści.
        audit_log('gosposia.trash', ['mailbox' => $cred['email'], 'count' => count($uids), 'bytes' => $bytes,
            'senders' => $perSender, 'folder' => $scan['trash_name'], 'method' => $method]);
        return ['moved' => count($uids), 'senders' => count($perSender), 'bytes' => $bytes, 'folder' => (string)$scan['trash_name'], 'method' => $method];
    }
}
