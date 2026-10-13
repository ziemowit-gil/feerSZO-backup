<?php
/**
 * modules/ti_consult_comm/logic/consultComm.php — komunikacja z beneficjentami konsultacji (tryb Konsultacje panelu).
 *
 * Odbiorcy (beneficjenci z kartoteki k30_clients) wg filtra: rezerwacje w dniu (przypomnienie), rezerwacje w okresie,
 * przyszłe rezerwacje konsultanta, kolejka oczekujących (aktywne wpisy), wybrane osoby. Wstawki w treści: {imie},
 * {nazwisko}, {termin} (najbliższa rezerwacja z filtra: data i godzina), {konsultant}. Kanały: e-mail (mail_queue)
 * i SMS (rodzaj ti_consult_message). Log: k30_ti_comm_log z filter_type „kons_…”.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

final class TiConsultComm
{
    public const MODES = ['dzien' => 'Rezerwacje w wybranym dniu', 'okres' => 'Rezerwacje w okresie', 'konsultant' => 'Przyszłe rezerwacje konsultanta',
                          'kolejka' => 'Kolejka oczekujących (aktywne)', 'osoby' => 'Wybrane osoby'];
    public const TEMPLATES = [
        'przypomnienie' => ['Przypomnienie o konsultacji', "Dzień dobry {imie}, przypominamy o konsultacji {termin}. W razie zmiany planów prosimy o kontakt."],
        'zmiana'        => ['Zmiana terminu konsultacji', "Dzień dobry {imie}, termin konsultacji {termin} wymaga zmiany. Prosimy o kontakt w celu ustalenia nowego terminu."],
        'kolejka'       => ['Możemy zaproponować termin konsultacji', "Dzień dobry {imie}, możemy zaproponować termin konsultacji. Prosimy o kontakt telefoniczny lub odpowiedź na tę wiadomość."],
    ];

    /**
     * @return array{0: list<array{client_id:int,name:string,email:string,phone:string,termin:string,konsultant:string}>, 1: string}
     */
    public static function targets(string $mode, array $f): array
    {
        $st = "s.status IN ('preliminary','confirmed')";
        $base = "SELECT c.id AS client_id, c.name, COALESCE(c.email,'') AS email, COALESCE(c.phone,'') AS phone, MIN(s.start_time) AS t, u.name AS konsultant
                   FROM k30_schedules s JOIN k30_clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = s.assigned_to";
        switch ($mode) {
            case 'dzien':
                $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['date'] ?? '')) ? $f['date'] : date('Y-m-d', strtotime('tomorrow'));
                $rows = db_all("$base WHERE $st AND DATE(s.start_time) = ? GROUP BY c.id ORDER BY t", [$d]);
                $label = 'Rezerwacje w dniu ' . date('d.m.Y', strtotime($d)); break;
            case 'okres':
                $a = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? '')) ? $f['from'] : date('Y-m-d');
                $b = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? '')) ? $f['to'] : date('Y-m-d', strtotime('+7 days'));
                $rows = db_all("$base WHERE $st AND DATE(s.start_time) BETWEEN ? AND ? GROUP BY c.id ORDER BY t", [$a, $b]);
                $label = 'Rezerwacje ' . date('d.m', strtotime($a)) . '–' . date('d.m.Y', strtotime($b)); break;
            case 'konsultant':
                $k = (int)($f['consultant'] ?? 0);
                $rows = db_all("$base WHERE $st AND s.assigned_to = ? AND s.start_time >= datetime('now') GROUP BY c.id ORDER BY t", [$k]);
                $label = 'Przyszłe rezerwacje: ' . (string)(db_one("SELECT name FROM users WHERE id=?", [$k])['name'] ?? '—'); break;
            case 'kolejka':
                $rows = db_all("SELECT c.id AS client_id, c.name, COALESCE(c.email,'') AS email, COALESCE(c.phone,'') AS phone, '' AS t, '' AS konsultant
                                  FROM k30_waiting_list w JOIN k30_clients c ON c.id = w.client_id WHERE w.status IN ('waiting','contacted') GROUP BY c.id ORDER BY c.name");
                $label = 'Kolejka oczekujących'; break;
            default:
                $ids = array_values(array_filter(array_map('intval', (array)($f['client_ids'] ?? []))));
                $rows = $ids ? db_all("SELECT c.id AS client_id, c.name, COALESCE(c.email,'') AS email, COALESCE(c.phone,'') AS phone,
                                              (SELECT MIN(start_time) FROM k30_schedules s WHERE s.client_id = c.id AND $st AND s.start_time >= datetime('now')) AS t, '' AS konsultant
                                         FROM k30_clients c WHERE c.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY c.name", $ids) : [];
                $label = 'Wybrane osoby (' . count($rows) . ')';
        }
        $out = [];
        foreach ($rows as $r) $out[] = ['client_id' => (int)$r['client_id'], 'name' => (string)$r['name'], 'email' => trim((string)$r['email']), 'phone' => trim((string)$r['phone']),
                                        'termin' => $r['t'] ? date('d.m.Y', strtotime((string)$r['t'])) . ' o ' . date('H:i', strtotime((string)$r['t'])) : '', 'konsultant' => (string)($r['konsultant'] ?? '')];
        return [$out, $label];
    }

    /** Beneficjenci do wyboru (z rezerwacją, kartą albo w kolejce). */
    public static function people(): array
    {
        return db_all("SELECT id, name FROM k30_clients WHERE id IN (SELECT client_id FROM k30_schedules UNION SELECT client_id FROM k30_consultations UNION SELECT client_id FROM k30_waiting_list) ORDER BY name");
    }

    public static function fill(string $text, array $r): string
    {
        $p = preg_split('/\s+/u', trim($r['name'])) ?: [''];
        return strtr($text, ['{imie}' => $p[0] ?? '', '{nazwisko}' => count($p) > 1 ? end($p) : '', '{termin}' => $r['termin'] !== '' ? $r['termin'] : '(termin do ustalenia)', '{konsultant}' => $r['konsultant']]);
    }

    /** Wysyłka. @return array{ok:int, fail:int} */
    public static function send(array $recips, bool $email, bool $sms, string $subject, string $body, string $mode, string $label, int $uid): array
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $ok = 0; $fail = 0; $smsOn = function_exists('sms_channel_ready') && sms_channel_ready();
        foreach ($recips as $r) {
            $txt = self::fill($body, $r);
            if ($email && $r['email'] !== '') {
                $html = '<div style="font-family:system-ui,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#0f172a">' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</div>';
                try { mail_queue_add($r['email'], $r['name'], self::fill($subject, $r), $html, $txt, 'ti_konsultacje', null, '', false); $ok++; } catch (\Throwable $e) { $fail++; }
            }
            if ($sms && $smsOn && $r['phone'] !== '') {
                try { sms_send($r['phone'], $txt, 'ti_consult_message'); $ok++; } catch (\Throwable $e) { $fail++; }
            }
        }
        try {
            db_insert('k30_ti_comm_log', ['channel' => trim(($email ? 'email' : '') . ($email && $sms ? '+' : '') . ($sms ? 'sms' : '')), 'filter_type' => 'kons_' . $mode,
                'filter_label' => $label, 'subject' => $subject, 'body' => $body, 'recipients' => count($recips), 'sent_ok' => $ok, 'sent_fail' => $fail, 'created_by' => $uid ?: null]);
        } catch (\Throwable $e) {}
        return ['ok' => $ok, 'fail' => $fail];
    }

    public static function log(int $limit = 15): array
    {
        try { return db_all("SELECT l.*, u.name AS by_name FROM k30_ti_comm_log l LEFT JOIN users u ON u.id = l.created_by WHERE l.filter_type LIKE 'kons_%' ORDER BY l.id DESC LIMIT " . (int)$limit); }
        catch (\Throwable $e) { return []; }
    }
}
