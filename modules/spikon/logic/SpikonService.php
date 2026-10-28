<?php
/**
 * modules/spikon/logic/SpikonService.php — integracja CRM ↔ telefonia Spikon.
 *
 * 1) Książki telefoniczne: grupa CRM jest powiązana z książką w Spikon (spikon_books); synchronizacja dopisuje do niej
 *    główny numer kontaktów z grupy i usuwa wpisy kontaktów, które z grupy wyszły albo zmieniły numer/nazwę.
 *    Do Spikon trafiają WYŁĄCZNIE kontakty z grup, które administrator świadomie powiązał — nigdy cała kartoteka.
 * 2) Wybieranie numeru: Spikon dzwoni najpierw na konto telefoniczne użytkownika (spikon_user_accounts albo konto
 *    domyślne), po odebraniu łączy z numerem kontaktu. Każde wywołanie trafia do spikon_calls (numer tylko końcówką).
 *
 * Ustawienia (tabela settings): spikon_enabled, spikon_api_key, spikon_api_base, spikon_default_account_id.
 * Tabele są w głównej bazie (jak ustawienia); dane CRM czytamy przez crm_all() — baza CRM może być osobna.
 */
require_once dirname(__DIR__) . '/lib/SpikonClient.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class SpikonService
{
    public const BASE_DEFAULT = 'https://panel.spikon.pl';
    /** Ile operacji (dodanie/usunięcie wpisu) wykonuje jedna synchronizacja z przeglądarki — dalej limit czasu PHP. CLI bez limitu. */
    public const WEB_MAX_OPS = 120;

    // ── ustawienia ──────────────────────────────────────────────────────────────────────────────────────────────────
    public static function setting(string $key): string
    {
        return (string)(db_one("SELECT value FROM settings WHERE key_=?", [$key])['value'] ?? '');
    }

    public static function settingSet(string $key, string $value): void
    {
        if (db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) {
            db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
        } else {
            db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $value]);
        }
    }

    public static function isConfigured(): bool { return self::setting('spikon_api_key') !== ''; }
    public static function isEnabled(): bool { return self::setting('spikon_enabled') === '1' && self::isConfigured(); }

    public static function client(): SpikonClient
    {
        $base = self::setting('spikon_api_base') ?: self::BASE_DEFAULT;
        return new SpikonClient(self::setting('spikon_api_key'), $base);
    }

    // ── schemat ─────────────────────────────────────────────────────────────────────────────────────────────────────
    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS spikon_books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL UNIQUE,
            spikon_book_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            last_sync_at DATETIME,
            last_sync_added INTEGER NOT NULL DEFAULT 0,
            last_sync_removed INTEGER NOT NULL DEFAULT 0,
            last_sync_error TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS spikon_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL REFERENCES spikon_books(id) ON DELETE CASCADE,
            contact_id INTEGER NOT NULL,
            element_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            number TEXT NOT NULL,
            UNIQUE(book_id, contact_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS spikon_user_accounts (
            user_id INTEGER PRIMARY KEY,
            phone_account_id INTEGER NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS spikon_calls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            contact_id INTEGER,
            phone_account_id INTEGER,
            number_tail TEXT,
            state TEXT,
            error TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_spikon_calls_user ON spikon_calls(user_id, created_at)");
        try { $pdo->exec("ALTER TABLE spikon_calls ADD COLUMN from_number TEXT"); } catch (\Throwable $e) {}
    }

    // ── numery ──────────────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * Numer do książki/wybierania: polski → 9 cyfr; zagraniczny → „00” + cyfry z kierunkowym (zwykłe wybieranie
     * międzynarodowe; dokumentacja Spikon nie opisuje formatu numerów zagranicznych — do sprawdzenia na koncie).
     * null = nie wygląda na numer telefonu.
     */
    public static function normalizeNumber(string $raw): ?string
    {
        $compact = preg_replace('/[^\d+]/', '', trim($raw));
        if ($compact === '') return null;
        $intl = str_starts_with($compact, '+') || str_starts_with($compact, '00');
        $d = preg_replace('/\D/', '', $compact);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        if (!$intl && strlen($d) === 9) return $d;
        if (strlen($d) === 11 && str_starts_with($d, '48')) return substr($d, 2);
        if ($intl && strlen($d) >= 7 && strlen($d) <= 20) return '00' . $d;
        return null;
    }

    /** Końcówka numeru do dziennika (reszta to dane osobowe, a wystarczy do rozpoznania wywołania). */
    public static function numberTail(string $number): string
    {
        return '***' . substr($number, -3);
    }

    // ── książki telefoniczne ────────────────────────────────────────────────────────────────────────────────────────
    /** @return list<array<string,mixed>> powiązania wraz z nazwą grupy CRM i liczbą wpisów */
    public static function books(): array
    {
        self::migrate();
        $rows = db_all("SELECT b.*, (SELECT COUNT(*) FROM spikon_entries e WHERE e.book_id=b.id) AS entries FROM spikon_books b ORDER BY b.name COLLATE NOCASE");
        foreach ($rows as &$r) {
            $g = crm_one("SELECT name FROM crm_groups WHERE id=?", [(int)$r['group_id']]);
            $r['group_name'] = $g['name'] ?? '(grupa usunięta)';
            $r['group_exists'] = (bool)$g;
        }
        return $rows;
    }

    /** @return list<array{id:int,name:string,members:int,with_phone:int}> grupy CRM jeszcze niepowiązane z książką */
    public static function unlinkedGroups(): array
    {
        self::migrate();
        $linked = array_map('intval', array_column(db_all("SELECT group_id FROM spikon_books"), 'group_id'));
        $out = [];
        foreach (crm_all("SELECT id, name FROM crm_groups ORDER BY name COLLATE NOCASE") as $g) {
            if (in_array((int)$g['id'], $linked, true)) continue;
            $out[] = ['id' => (int)$g['id'], 'name' => (string)$g['name'],
                'members' => count(self::desired((int)$g['id'], false)),
                'with_phone' => count(self::desired((int)$g['id'], true))];
        }
        return $out;
    }

    /**
     * Kontakty grupy, które trafią do książki: aktywne, z poprawnym numerem. Klucz = id kontaktu.
     * $onlyWithPhone=false zwraca wszystkich członków (do licznika „z numerem / wszystkich”).
     *
     * @return array<int,array{name:string,number:string}>
     */
    public static function desired(int $groupId, bool $onlyWithPhone = true): array
    {
        $rows = crm_all(
            "SELECT c.id, c.imie_nazwisko, c.organizacja, c.type, c.telefon
               FROM crm_group_members m JOIN crm_contacts c ON c.id = m.contact_id
              WHERE m.group_id = ? AND COALESCE(c.crm_active,1) = 1
              ORDER BY c.imie_nazwisko COLLATE NOCASE",
            [$groupId]
        );
        $out = [];
        foreach ($rows as $r) {
            $num = self::normalizeNumber((string)($r['telefon'] ?? ''));
            if ($num === null && $onlyWithPhone) continue;
            $name = trim((string)$r['imie_nazwisko']);
            $org = trim((string)($r['organizacja'] ?? ''));
            if (($r['type'] ?? '') === 'osoba' && $org !== '' && mb_stripos($name, $org) === false) $name .= ' (' . $org . ')';
            $out[(int)$r['id']] = ['name' => mb_substr($name, 0, 120), 'number' => (string)$num];
        }
        return $out;
    }

    /** Zakłada książkę w Spikon dla grupy CRM i zapisuje powiązanie; @return int id lokalnego powiązania */
    public static function linkGroup(int $groupId, int $userId): int
    {
        self::migrate();
        $g = crm_one("SELECT id, name FROM crm_groups WHERE id=?", [$groupId]);
        if (!$g) throw new \InvalidArgumentException('Nie ma takiej grupy CRM.');
        if (db_one("SELECT 1 FROM spikon_books WHERE group_id=?", [$groupId])) {
            throw new \InvalidArgumentException('Ta grupa ma już książkę w Spikon.');
        }
        $client = self::client();
        $name = mb_substr((string)$g['name'], 0, 25);
        try {
            $bookId = $client->phoneBookAdd($name);
        } catch (SpikonException $e) {
            if ($e->apiCode() !== SpikonClient::ERR_ALREADY_EXISTS) throw $e;
            $name = mb_substr((string)$g['name'], 0, 20) . ' #' . $groupId;   // nazwa zajęta — dopisujemy id grupy
            $bookId = $client->phoneBookAdd($name);
        }
        db()->prepare("INSERT INTO spikon_books (group_id, spikon_book_id, name, created_by) VALUES (?,?,?,?)")
            ->execute([$groupId, $bookId, $name, $userId ?: null]);
        $id = (int)db()->lastInsertId();
        audit_log('spikon.book_link', ['group_id' => $groupId, 'book' => $name], $userId);
        return $id;
    }

    /**
     * Synchronizuje książkę z grupą CRM. $maxOps = limit operacji w jednym przebiegu (null = bez limitu, dla CLI);
     * po limicie reszta zostaje na kolejne uruchomienie ('remaining' > 0).
     *
     * @return array{added:int,removed:int,remaining:int,errors:list<string>}
     */
    public static function syncBook(int $localId, ?int $maxOps = self::WEB_MAX_OPS): array
    {
        self::migrate();
        $b = db_one("SELECT * FROM spikon_books WHERE id=?", [$localId]);
        if (!$b) throw new \InvalidArgumentException('Nie ma takiej książki.');
        $client = self::client();
        $remoteId = (int)$b['spikon_book_id'];
        $want = self::desired((int)$b['group_id']);
        $have = [];
        foreach (db_all("SELECT * FROM spikon_entries WHERE book_id=?", [$localId]) as $e) $have[(int)$e['contact_id']] = $e;

        $toRemove = [];   // contact_id => wpis
        foreach ($have as $cid => $e) {
            if (!isset($want[$cid]) || $want[$cid]['number'] !== $e['number'] || $want[$cid]['name'] !== $e['name']) $toRemove[$cid] = $e;
        }
        $toAdd = [];
        foreach ($want as $cid => $w) {
            if (!isset($have[$cid]) || isset($toRemove[$cid])) $toAdd[$cid] = $w;
        }

        $ops = 0; $added = 0; $removed = 0; $remaining = 0; $errors = [];
        $budget = function () use (&$ops, $maxOps): bool { return $maxOps === null || $ops < $maxOps; };   // $ops przez referencję — fn() kopiuje wartość

        foreach ($toRemove as $cid => $e) {
            if (!$budget()) { $remaining++; continue; }
            $ops++;
            try {
                $client->elementDelete((int)$e['element_id']);
            } catch (SpikonException $ex) {
                if ($ex->apiCode() !== SpikonClient::ERR_NO_ELEMENTS) { $errors[] = 'Usunięcie wpisu: ' . $ex->getMessage(); unset($toAdd[$cid]); continue; }
            }
            db()->prepare("DELETE FROM spikon_entries WHERE id=?")->execute([(int)$e['id']]);
            unset($have[$cid]);
            $removed++;
        }
        foreach ($toAdd as $cid => $w) {
            if (isset($toRemove[$cid]) && isset($have[$cid])) continue;   // stary wpis się nie usunął — nie dublujemy
            if (!$budget()) { $remaining++; continue; }
            $ops++;
            try {
                $elId = $client->elementAdd($remoteId, $w['name'], $w['number']);
            } catch (SpikonException $ex) {
                $errors[] = 'Dodanie „' . $w['name'] . '”: ' . $ex->getMessage();
                if (in_array($ex->apiCode(), [-2, -3, -4, -5, 0], true)) break;   // błąd dostępu/transportu — dalsze próby bez sensu
                continue;
            }
            db()->prepare("INSERT OR REPLACE INTO spikon_entries (book_id, contact_id, element_id, name, number) VALUES (?,?,?,?,?)")
                ->execute([$localId, $cid, $elId, $w['name'], $w['number']]);
            $added++;
        }

        $errText = $errors ? mb_substr(implode(' | ', array_slice($errors, 0, 3)), 0, 500) : null;
        db()->prepare("UPDATE spikon_books SET last_sync_at=CURRENT_TIMESTAMP, last_sync_added=?, last_sync_removed=?, last_sync_error=? WHERE id=?")
            ->execute([$added, $removed, $errText, $localId]);
        audit_log('spikon.book_sync', ['book' => $b['name'], 'added' => $added, 'removed' => $removed, 'errors' => count($errors)], PHP_SAPI === 'cli' ? 0 : null);
        return ['added' => $added, 'removed' => $removed, 'remaining' => $remaining, 'errors' => $errors];
    }

    /** Odpina grupę. $deleteRemote=true kasuje też książkę w Spikon (wraz z wpisami); brak książki po stronie Spikon nie przeszkadza. */
    public static function unlinkBook(int $localId, bool $deleteRemote): void
    {
        self::migrate();
        $b = db_one("SELECT * FROM spikon_books WHERE id=?", [$localId]);
        if (!$b) throw new \InvalidArgumentException('Nie ma takiej książki.');
        if ($deleteRemote) {
            try {
                self::client()->phoneBookDelete((int)$b['spikon_book_id']);
            } catch (SpikonException $e) {
                if ($e->apiCode() !== SpikonClient::ERR_NO_ELEMENTS) throw $e;
            }
        }
        db()->prepare("DELETE FROM spikon_entries WHERE book_id=?")->execute([$localId]);
        db()->prepare("DELETE FROM spikon_books WHERE id=?")->execute([$localId]);
        audit_log('spikon.book_unlink', ['book' => $b['name'], 'remote_deleted' => $deleteRemote], PHP_SAPI === 'cli' ? 0 : null);
    }

    // ── wybieranie numeru ───────────────────────────────────────────────────────────────────────────────────────────
    public static function accountFor(int $userId): ?int
    {
        self::migrate();
        $r = db_one("SELECT phone_account_id FROM spikon_user_accounts WHERE user_id=?", [$userId]);
        if ($r) return (int)$r['phone_account_id'];
        $def = (int)self::setting('spikon_default_account_id');
        return $def > 0 ? $def : null;
    }

    /** @param array<int,int> $map user_id => phone_account_id (0 = brak) */
    public static function saveAccountMap(array $map): void
    {
        self::migrate();
        db()->prepare("DELETE FROM spikon_user_accounts")->execute();
        $ins = db()->prepare("INSERT INTO spikon_user_accounts (user_id, phone_account_id) VALUES (?,?)");
        foreach ($map as $uid => $acc) if ((int)$uid > 0 && (int)$acc > 0) $ins->execute([(int)$uid, (int)$acc]);
    }

    // ── numery VoIP (konta telefoniczne), z których można dzwonić ───────────────────────────────────────────────────
    private const ACCOUNTS_TTL = 43200;   // 12 h — lista numerów zmienia się rzadko, a karta kontaktu nie może czekać na API

    /** Zapamiętuje listę kont pobraną ze Spikon (ustawienia odświeżają ją przy każdym wejściu). */
    public static function cacheAccounts(array $accounts): void
    {
        self::settingSet('spikon_accounts_cache', json_encode(['at' => time(), 'items' => array_values($accounts)], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Numery VoIP do listy „Zadzwoń z”. Z pamięci podręcznej; gdy pusta lub starsza niż 12 h — odświeżana ze Spikon
     * (przy błędzie API zostaje poprzednia lista).
     * @return list<array{id:int,number:string,internal:string,login:string,description:string,state:string,recording:bool}>
     */
    public static function accounts(): array
    {
        $c = json_decode(self::setting('spikon_accounts_cache'), true);
        $items = is_array($c['items'] ?? null) ? $c['items'] : [];
        if (!$items || time() - (int)($c['at'] ?? 0) > self::ACCOUNTS_TTL) {
            try {
                $fresh = self::client()->phoneAccounts();
                self::cacheAccounts($fresh);
                $items = $fresh;
            } catch (\Throwable $e) {
                // zostaje to, co było
            }
        }
        return $items;
    }

    /** Numer VoIP do pokazania na liście: sam numer (bez nazwy dostawcy), a gdy konto go nie ma — wewnętrzny albo login. */
    public static function accountNumber(array $a): string
    {
        foreach (['number', 'internal', 'login'] as $k) {
            $v = trim((string)($a[$k] ?? ''));
            if ($v !== '' && $v !== '-') return $k === 'internal' ? 'wew. ' . $v : $v;
        }
        return 'konto #' . (int)($a['id'] ?? 0);
    }

    /**
     * Zestawia połączenie użytkownika z kontaktem CRM: dzwoni wybrany numer VoIP (domyślnie przypisany użytkownikowi),
     * po odebraniu — numer kontaktu. Połączenie trafia do historii komunikacji kontaktu (z numeru i na numer).
     * Wywołujący sprawdza uprawnienia do kontaktu (crm_can_access_contact). @return string stan od Spikon („initiated”)
     */
    public static function dial(int $userId, int $contactId, ?int $accountId = null): string
    {
        self::migrate();
        if (!self::isEnabled()) throw new \RuntimeException('Telefonia jest wyłączona.');
        $c = crm_one("SELECT id, telefon FROM crm_contacts WHERE id=?", [$contactId]);
        if (!$c) throw new \InvalidArgumentException('Nie ma takiego kontaktu.');
        $number = self::normalizeNumber((string)($c['telefon'] ?? ''));
        if ($number === null) throw new \InvalidArgumentException('Kontakt nie ma poprawnego numeru telefonu.');
        $acc = $accountId ?: self::accountFor($userId);
        if (!$acc) throw new \RuntimeException('Nie masz przypisanego numeru VoIP — wybierz numer z listy albo poproś administratora CRM.');
        $from = null;
        foreach (self::accounts() as $a) if ((int)$a['id'] === $acc) { $from = self::accountNumber($a); break; }
        if ($accountId && $from === null) throw new \InvalidArgumentException('Nieznany numer VoIP.');
        $from ??= 'konto #' . $acc;
        try {
            $state = self::client()->callInitiate($acc, $number);
        } catch (SpikonException $e) {
            self::logCall($userId, $contactId, $acc, $number, $from, '', $e->getMessage());
            throw $e;
        }
        self::logCall($userId, $contactId, $acc, $number, $from, $state, null);
        db_insert('crm_communications', [
            'contact_id' => $contactId,
            'channel'    => 'telefon',
            'direction'  => 'out',
            'subject'    => 'Połączenie wychodzące',
            'body'       => 'Z numeru: ' . $from . "\nNa numer: " . trim((string)$c['telefon']),
            'status'     => 'wysłana',
            'sent_by'    => $userId,
            'sent_at'    => date('Y-m-d H:i:s'),
        ]);
        audit_log('spikon.call', ['contact_id' => $contactId, 'from' => $from, 'to' => self::numberTail($number)], $userId);
        return $from;
    }

    private static function logCall(int $userId, int $contactId, int $acc, string $number, string $from, string $state, ?string $error): void
    {
        db()->prepare("INSERT INTO spikon_calls (user_id, contact_id, phone_account_id, from_number, number_tail, state, error) VALUES (?,?,?,?,?,?,?)")
            ->execute([$userId, $contactId, $acc, $from, self::numberTail($number), $state, $error]);
    }
}
