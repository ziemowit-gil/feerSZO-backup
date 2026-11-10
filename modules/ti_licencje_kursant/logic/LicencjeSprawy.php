<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeSprawy.php — „Moje sprawy” w Panelu Licencji (polecenie 2026-11-10): wszystkie sprawy AM uczestnika
 * (operacje AM11–AM24 z license_operations, wnioski AM5/AM7 z licenses) i zgłoszenia Helpdesk z numerem AM… (kategoria licencji),
 * z podglądem i korespondencją (wiadomości jawne zgłoszenia; odpowiedź uczestnika jak na stronie śledzenia helpdesk/track.php).
 * Zgłoszenie należy do uczestnika, gdy jest przypięte do jego sprawy / abonamentu albo zgłaszającym jest jego e-mail.
 */
require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php';

final class LicencjeSprawy
{
    /** E-maile uczestnika (kartoteka + kontakt panelu). */
    private static function emails(int $cid): array
    {
        $e = [];
        $c = db_one("SELECT email FROM k30_clients WHERE id=?", [$cid]);
        if ($c && filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL)) $e[] = mb_strtolower(trim((string)$c['email']));
        return array_values(array_unique($e));
    }

    /** Id zgłoszeń przypiętych do spraw i abonamentów uczestnika. */
    private static function linkedTickets(int $cid): array
    {
        $ids = [];
        foreach (["SELECT helpdesk_ticket_id t FROM license_operations WHERE account_kind='client' AND account_id=?",
                  "SELECT helpdesk_ticket_id t FROM licenses WHERE interested_kind='client' AND interested_id=?",
                  "SELECT helpdesk_ticket_id t FROM claude_subscriptions WHERE client_id=?"] as $sql) {
            try { foreach (db_all($sql, [$cid]) as $r) if ((int)$r['t'] > 0) $ids[(int)$r['t']] = true; } catch (\Throwable $e) {}
        }
        return array_keys($ids);
    }

    /** Zgłoszenia AM… uczestnika (najnowsze pierwsze). */
    public static function tickets(int $cid): array
    {
        $ids = self::linkedTickets($cid);
        $em = self::emails($cid);
        $w = []; $p = [];
        if ($ids) { $w[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $p = array_merge($p, $ids); }
        if ($em) { $w[] = 'LOWER(TRIM(requester_email)) IN (' . implode(',', array_fill(0, count($em), '?')) . ')'; $p = array_merge($p, $em); }
        if (!$w) return [];
        try {
            return db_all("SELECT id, number, title, status, created_at, updated_at FROM helpdesk_tickets WHERE number LIKE 'AM%' AND merged_into IS NULL AND (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 50", $p);
        } catch (\Throwable $e) {
            try { return db_all("SELECT id, number, title, status, created_at, updated_at FROM helpdesk_tickets WHERE number LIKE 'AM%' AND (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 50", $p); } catch (\Throwable $e2) { return []; }
        }
    }

    public static function ticket(int $cid, int $tid): ?array
    {
        foreach (self::tickets($cid) as $t) if ((int)$t['id'] === $tid) return db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$tid]) ?: null;
        return null;
    }

    /** Wiadomości jawne zgłoszenia (bez notatek wewnętrznych). */
    public static function messages(int $tid): array
    {
        try { return db_all("SELECT user_name, body, created_at, user_id FROM helpdesk_messages WHERE ticket_id=? AND is_internal=0 ORDER BY created_at ASC, id ASC", [$tid]); } catch (\Throwable $e) { return []; }
    }

    /** Sprawy AM uczestnika: operacje (AM11–AM24) i wnioski AM5 / AM7. */
    public static function cases(int $cid): array
    {
        $out = [];
        try {
            foreach (db_all("SELECT id, number, form, status, created_at, decided_at, helpdesk_ticket_id, helpdesk_number FROM license_operations WHERE account_kind='client' AND account_id=? ORDER BY id DESC LIMIT 50", [$cid]) as $o) {
                $out[] = ['kind' => 'op', 'id' => (int)$o['id'], 'number' => (string)$o['number'], 'form' => (string)$o['form'], 'status' => (string)$o['status'], 'created' => (string)$o['created_at'],
                          'decided' => (string)($o['decided_at'] ?? ''), 'ticket' => (int)($o['helpdesk_ticket_id'] ?? 0), 'ticket_no' => (string)($o['helpdesk_number'] ?? '')];
            }
        } catch (\Throwable $e) {}
        try {
            foreach (db_all("SELECT id, case_number, title, COALESCE(form_kind,'AM5') form_kind, request_status, period_from, period_to, created_at, decided_at, helpdesk_ticket_id, helpdesk_number
                               FROM licenses WHERE interested_kind='client' AND interested_id=? ORDER BY id DESC LIMIT 50", [$cid]) as $l) {
                $out[] = ['kind' => 'lic', 'id' => (int)$l['id'], 'number' => (string)$l['case_number'], 'form' => (string)$l['form_kind'], 'status' => (string)$l['request_status'], 'title' => (string)$l['title'],
                          'created' => (string)$l['created_at'], 'decided' => (string)($l['decided_at'] ?? ''), 'from' => (string)($l['period_from'] ?? ''), 'to' => (string)($l['period_to'] ?? ''),
                          'ticket' => (int)($l['helpdesk_ticket_id'] ?? 0), 'ticket_no' => (string)($l['helpdesk_number'] ?? '')];
            }
        } catch (\Throwable $e) {}
        usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
        return $out;
    }

    public static function case(int $cid, string $kind, int $id): ?array
    {
        foreach (self::cases($cid) as $c) if ($c['kind'] === $kind && $c['id'] === $id) return $c;
        return null;
    }

    /** Odpowiedź uczestnika w zgłoszeniu (jak helpdesk/track.php): wiadomość jawna, ponowne otwarcie „oczekuje / rozwiązane”, powiadomienie obsługi. */
    public static function reply(int $cid, int $tid, string $text, string $name): string
    {
        $t = self::ticket($cid, $tid);
        if (!$t) throw new DomainException('Nie znaleziono zgłoszenia.');
        if ($t['status'] === 'zamknięte') throw new DomainException('Zgłoszenie jest zamknięte. Jeśli potrzebujesz pomocy, napisz do biura.');
        $text = trim($text);
        if (mb_strlen($text) < 2) throw new InvalidArgumentException('Napisz treść wiadomości.');
        require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
        $body = hd_sanitize_body(nl2br(h(mb_substr($text, 0, 5000))));
        db_insert('helpdesk_messages', ['ticket_id' => $tid, 'user_id' => $t['requester_id'] ?: null, 'user_name' => $name . ' (Panel Licencji)', 'body' => $body, 'is_internal' => 0]);
        db_exec("UPDATE helpdesk_tickets SET updated_at=?" . (in_array($t['status'], ['oczekuje', 'rozwiązane'], true) ? ", status='otwarte'" : '') . " WHERE id=?", [date('Y-m-d H:i:s'), $tid]);
        try { hd_redmine_push_note($tid, $body, null, false); } catch (\Throwable $e) {}
        try {
            $fresh = db_one("SELECT t.*, op.email AS assigned_email FROM helpdesk_tickets t LEFT JOIN users op ON op.id=t.assigned_to WHERE t.id=?", [$tid]) ?: $t;
            hd_notify_new_message($fresh, ['user_id' => (int)($t['requester_id'] ?? 0), 'user_name' => $name, 'body' => $body, 'is_internal' => 0]);
        } catch (\Throwable $e) { error_log('lk sprawy reply notify: ' . $e->getMessage()); }
        return 'Wiadomość wysłana. Odpowiemy w zgłoszeniu ' . $t['number'] . '.';
    }
}
