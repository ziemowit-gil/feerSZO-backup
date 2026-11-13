<?php
/**
 * modules/dhosting_migrator/lib/DhostingHelpdesk.php — każda migracja skrzynki ma zgłoszenie w Helpdesku
 * (kategoria „Migracja poczty”, prefiks MIG; polecenie 2026-11-13).
 *
 * Jedno zgłoszenie na skrzynkę (tabela dhosting_helpdesk: mailbox → ticket_id). Powstaje przy pierwszym zdarzeniu
 * migracji (założenie skrzynki, nowe hasło, błąd, kopiowanie poczty — przez użytkownika, administratora albo CRON),
 * kolejne zdarzenia dopisujemy jako notatki wewnętrzne (widzi je obsługa IT, nie użytkownik). Zgłaszający = osoba,
 * której skrzynka dotyczy: dostaje zwykłe potwierdzenie Helpdesku z linkiem do śledzenia i może tam odpisać.
 * Treść widoczna dla użytkownika (tytuł, opis) bez nazwy dostawcy. Nigdy nie przerywa migracji — błędy do error_log.
 */

final class DhostingHelpdesk
{
    public const CATEGORY = 'it_migracja_poczty';
    public const SOURCE   = 'migracja_poczty';
    private const AUTHOR  = 'Migracja poczty (system)';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_helpdesk (
            mailbox VARCHAR(190) PRIMARY KEY,
            ticket_id INTEGER NOT NULL,
            created_at VARCHAR(19) NULL)");
    }

    /**
     * Dopisuje zdarzenie (notatka wewnętrzna) do zgłoszenia skrzynki; gdy zgłoszenia nie ma — zakłada je.
     * $who: osoba, której dotyczy skrzynka — user_id, name, login (konto Microsoft 365); brakujące dane z rejestru skrzynek.
     * $create=false: tylko dopisz do istniejącego (np. ciche zawieszenie — nowe zgłoszenie wysłałoby osobie potwierdzenie).
     * Zwraca id zgłoszenia albo null (brak zgłoszenia przy $create=false albo błąd — zapisany w error_log).
     */
    public static function event(string $mailbox, string $note, array $who = [], bool $create = true): ?int
    {
        $mailbox = strtolower(trim($mailbox));
        if ($mailbox === '') return null;
        try {
            self::boot();
            $ticket = self::ticketFor($mailbox);
            if (!$ticket && !$create) return null;
            $id = $ticket ? (int)$ticket['id'] : self::open($mailbox, $who);
            self::note($id, $note);
            return $id;
        } catch (\Throwable $e) {
            error_log('[dhosting] helpdesk ' . $mailbox . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Zgłoszenie skrzynki (po scaleniu w Helpdesku — zgłoszenie docelowe) albo null. */
    public static function ticketFor(string $mailbox): ?array
    {
        try {
            self::boot();
            $map = db_one("SELECT ticket_id FROM dhosting_helpdesk WHERE mailbox=?", [strtolower(trim($mailbox))]);
            if (!$map) return null;
            $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$map['ticket_id']]);
            $guard = 0;
            while ($t && !empty($t['merged_into']) && $guard++ < 5) $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$t['merged_into']]);
            return $t ?: null;
        } catch (\Throwable $e) {
            error_log('[dhosting] helpdesk ticketFor ' . $mailbox . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Link do śledzenia zgłoszenia (bez logowania) albo ''. */
    public static function trackUrl(?array $ticket): string
    {
        if (!$ticket) return '';
        try { self::boot(); return hd_track_url($ticket); } catch (\Throwable $e) { return ''; }
    }

    private static function boot(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
        helpdesk_migrate();
        self::migrate();
    }

    private static function open(string $mailbox, array $who): int
    {
        $reg = class_exists('DhostingMigrator') ? (DhostingMigrator::registryRow($mailbox) ?: []) : [];
        $uid = (int)($who['user_id'] ?? 0) ?: (int)($reg['user_id'] ?? 0);
        $user = $uid ? db_one("SELECT id, name, email FROM users WHERE id=?", [$uid]) : null;
        $login = (string)(($who['login'] ?? '') ?: ($reg['source_login'] ?? ''));
        if (!filter_var($login, FILTER_VALIDATE_EMAIL)) $login = '';
        $name = (string)(($who['name'] ?? '') ?: ($reg['display_name'] ?? '') ?: ($user['name'] ?? ''));
        $requester = [
            'id'    => $user ? (int)$user['id'] : null,
            'name'  => $name !== '' ? $name : (string)($user['name'] ?? $mailbox),
            'email' => (string)(($user['email'] ?? '') ?: ($login !== '' ? $login : $mailbox)),
        ];
        $desc = "Migracja poczty z Microsoft 365 do nowej skrzynki.\n\n"
              . "Skrzynka: {$mailbox}\n"
              . 'Login Microsoft 365: ' . ($login !== '' ? $login : '—') . "\n"
              . 'Osoba: ' . $requester['name'] . "\n\n"
              . 'Przebieg migracji (założenie skrzynki, hasło SMS-em, kopiowanie poczty) dopisujemy do tego zgłoszenia. '
              . 'Jeśli coś nie działa, odpisz tutaj.';
        $id = hd_ticket_quick_create($requester, 'Migracja poczty: ' . $mailbox, self::plain($desc), self::CATEGORY, 'normalny', self::SOURCE);
        $now = date('Y-m-d H:i:s');
        if (db_one("SELECT mailbox FROM dhosting_helpdesk WHERE mailbox=?", [$mailbox])) {
            db()->prepare("UPDATE dhosting_helpdesk SET ticket_id=?, created_at=? WHERE mailbox=?")->execute([$id, $now, $mailbox]);
        } else {
            db()->prepare("INSERT INTO dhosting_helpdesk (mailbox, ticket_id, created_at) VALUES (?, ?, ?)")->execute([$mailbox, $id, $now]);
        }
        return $id;
    }

    private static function note(int $ticketId, string $note): void
    {
        db_insert('helpdesk_messages', [
            'ticket_id' => $ticketId, 'user_id' => null, 'user_name' => self::AUTHOR,
            'body' => self::plain($note), 'is_internal' => 1,
        ]);
        db()->prepare("UPDATE helpdesk_tickets SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $ticketId]);
    }

    /** Czysty tekst — helpdesk/_detail.php traktuje treść z „<” jako HTML. */
    private static function plain(string $s): string
    {
        return str_replace(['<', '>'], ['‹', '›'], trim($s));
    }
}
