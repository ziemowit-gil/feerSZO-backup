<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/lib/NoweHasloHelpdesk.php — każda zmiana hasła na /nowehaslo ma wpis w Helpdesku (polecenie 2026-11-13).
 *
 * Jedno zgłoszenie na osobę (tabela nowehaslo_helpdesk: klucz osoby → ticket_id), kolejne zmiany to notatki wewnętrzne.
 * Gdy osoba ma już zgłoszenie migracji swojej skrzynki (DhostingHelpdesk, prefiks MIG) — dopisujemy tam, bez nowego zgłoszenia.
 * Inaczej zakładamy zgłoszenie w kategorii „Konto / Logowanie” (zgłaszający = osoba; dostaje zwykłe potwierdzenie z linkiem
 * do śledzenia — może odpisać, jeśli to nie ona zmieniała hasło). Treść bez haseł i bez nazwy dostawcy poczty.
 * Nigdy nie przerywa zmiany hasła — błędy do error_log.
 */
require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingHelpdesk.php';

final class NoweHasloHelpdesk
{
    public const CATEGORY = 'it_konto';
    public const SOURCE   = 'nowe_haslo';
    private const AUTHOR  = 'Zmiana hasła (system)';

    /** Atrapa w testach: fn(array $requester, string $title, string $description): int — id nowego zgłoszenia. */
    public static ?\Closure $create = null;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS nowehaslo_helpdesk (
            person VARCHAR(190) PRIMARY KEY,
            ticket_id INTEGER NOT NULL,
            created_at VARCHAR(19) NULL)");
    }

    /**
     * Notatka o zmianie w zgłoszeniu osoby. $p — osoba z NoweHasloAuth (key, user_id, name, email, mailbox).
     * Zwraca id zgłoszenia albo null.
     */
    public static function event(array $p, string $note): ?int
    {
        $key = strtolower(trim((string)($p['key'] ?? '')));
        if ($key === '') return null;
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            helpdesk_migrate();
            self::migrate();
            $t = self::ticket($key);
            if (!$t && !empty($p['mailbox'])) $t = DhostingHelpdesk::ticketFor((string)$p['mailbox']);
            $id = $t ? (int)$t['id'] : self::open($p);
            $now = date('Y-m-d H:i:s');
            if (db_one("SELECT person FROM nowehaslo_helpdesk WHERE person=?", [$key])) {
                db()->prepare("UPDATE nowehaslo_helpdesk SET ticket_id=? WHERE person=?")->execute([$id, $key]);
            } else {
                db()->prepare("INSERT INTO nowehaslo_helpdesk (person, ticket_id, created_at) VALUES (?, ?, ?)")->execute([$key, $id, $now]);
            }
            db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => null, 'user_name' => self::AUTHOR,
                                            'body' => self::plain($note), 'is_internal' => 1]);
            db()->prepare("UPDATE helpdesk_tickets SET updated_at=? WHERE id=?")->execute([$now, $id]);
            return $id;
        } catch (\Throwable $e) {
            error_log('[nowehaslo] helpdesk ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Zgłoszenie z mapy (po scaleniu — docelowe) albo null. */
    private static function ticket(string $key): ?array
    {
        $map = db_one("SELECT ticket_id FROM nowehaslo_helpdesk WHERE person=?", [$key]);
        if (!$map) return null;
        $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$map['ticket_id']]);
        $guard = 0;
        while ($t && !empty($t['merged_into']) && $guard++ < 5) $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$t['merged_into']]);
        return $t ?: null;
    }

    private static function open(array $p): int
    {
        $uid = (int)($p['user_id'] ?? 0);
        $user = $uid > 0 ? db_one("SELECT id, name, email FROM users WHERE id=?", [$uid]) : null;
        $email = (string)(($user['email'] ?? '') ?: ($p['email'] ?? '') ?: ($p['address'] ?? ''));
        $name = (string)(($p['name'] ?? '') ?: ($user['name'] ?? '') ?: $email);
        $requester = ['id' => $user ? (int)$user['id'] : null, 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''];
        $title = 'Zmiana hasła: ' . $name;
        $desc = "Samodzielna zmiana hasła na stronie /nowehaslo.\n\n"
              . 'Osoba: ' . $name . "\n"
              . 'Login: ' . ((string)($p['login'] ?? '') ?: '—') . "\n"
              . 'Poczta: ' . ((string)($p['mailbox'] ?? '') ?: '—') . "\n\n"
              . 'Każdą zmianę hasła dopisujemy do tego zgłoszenia. Jeśli to nie Ty zmieniałeś(-aś) hasło, odpisz tutaj od razu.';
        if (self::$create) return (int)(self::$create)($requester, $title, self::plain($desc));
        return hd_ticket_quick_create($requester, $title, self::plain($desc), self::CATEGORY, 'normalny', self::SOURCE);
    }

    /** Czysty tekst — helpdesk/_detail.php traktuje treść z „<” jako HTML. */
    private static function plain(string $s): string
    {
        return str_replace(['<', '>'], ['‹', '›'], trim($s));
    }
}
