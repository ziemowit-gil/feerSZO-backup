<?php
/**
 * modules/ti_licencje/logic/tiClaudeStatement.php — powiadomienie „w panelu kursanta czeka oświadczenie o opłacie za dostęp do Claude”.
 *
 * Oświadczenie = regulamin TI typu `claude_oplata` (includes/ti_terms.php) dla odbiorcy „claude” (osoby z aktywną licencją Claude
 * w module Licencje). Wysyłka e-mail + SMS (szablony `ti_claude_statement` w rejestrach e-mail i SMS; SMS tylko w ASCII):
 *   • automatycznie — po przypisaniu licencji Claude (TiLicencjeNotifier::send dokleja tę wiadomość, te same kanały i zasady zgód),
 *   • ręcznie — z przycisku w panelu dydaktyka › Regulaminy › Oświadczenie o opłacie za dostęp do Claude (osoby bez akceptacji).
 * Odbiorcy i zgody jak przy powiadomieniu o licencji (TiLicencjeNotifier::recipients): kursant + opiekun małoletniego.
 * Wynik każdej próby → k30_ti_license_notifications (assignment_id = przypisanie licencji Claude; detail z prefiksem „oświadczenie:”).
 */
require_once __DIR__ . '/tiLicencjeNotify.php';
require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';

final class TiClaudeStatement
{
    public const TEMPLATE = 'ti_claude_statement';
    public const TERM     = 'claude_oplata';

    /** Aktywne, niewygasłe przypisanie licencji Claude dla beneficjenta (najnowsze) albo null. */
    public static function claudeAssignment(int $clientId): ?array
    {
        foreach (db_all(
            "SELECT cl.id, cl.client_id, cl.expires_at, l.name AS license_name, l.vendor
               FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id
              WHERE cl.client_id = ? AND cl.status = 'active' AND (cl.expires_at IS NULL OR cl.expires_at = '' OR cl.expires_at >= date('now'))
              ORDER BY cl.id DESC", [$clientId]) as $r) {
            if (ti_term_is_claude_license($r)) return $r;
        }
        return null;
    }

    /** Czy oświadczenie jest aktywne i nadal czeka na akceptację tej osoby. */
    public static function pending(int $clientId): bool
    {
        ti_terms_migrate();
        $term = ti_term_get(self::TERM);
        if (!$term || empty($term['is_active'])) return false;
        return !ti_term_accepted($clientId, self::TERM);
    }

    /** Osoby z aktywną licencją Claude, które nie zaakceptowały oświadczenia. @return list<array{client_id:int,name:string,assignment_id:int,software:string}> */
    public static function pendingClients(): array
    {
        $out = [];
        foreach (db_all(
            "SELECT DISTINCT cl.client_id, c.name FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id JOIN k30_clients c ON c.id = cl.client_id
              WHERE cl.status = 'active' AND (cl.expires_at IS NULL OR cl.expires_at = '' OR cl.expires_at >= date('now'))
                AND (lower(l.name) LIKE '%claude%' OR lower(l.vendor) LIKE '%claude%' OR lower(l.vendor) LIKE '%anthropic%' OR lower(l.vendor) LIKE '%antropic%')
              ORDER BY c.name") as $r) {
            $cid = (int)$r['client_id'];
            if (!self::pending($cid)) continue;
            $a = self::claudeAssignment($cid);
            if ($a) $out[] = ['client_id' => $cid, 'name' => (string)$r['name'], 'assignment_id' => (int)$a['id'], 'software' => (string)$a['license_name']];
        }
        return $out;
    }

    /**
     * Wysyłka powiadomienia do kursanta (i opiekuna). Nic nie wysyła, gdy osoba nie ma licencji Claude albo już zaakceptowała oświadczenie.
     * @return array{ok:bool,sent:array,failed:array,skipped:array,msg:string}
     */
    public static function send(int $clientId, ?int $by = null, array $channels = ['email', 'sms']): array
    {
        ti_licencje_migrate();
        $res = ['ok' => false, 'sent' => ['email' => 0, 'sms' => 0], 'failed' => ['email' => 0, 'sms' => 0], 'skipped' => [], 'msg' => ''];
        $channels = array_values(array_intersect(['email', 'sms'], $channels));
        $a = self::claudeAssignment($clientId);
        if (!$a)                     { $res['msg'] = 'brak aktywnej licencji Claude'; return $res; }
        if (!self::pending($clientId)) { $res['msg'] = 'oświadczenie już zaakceptowane (albo nieaktywne)'; return $res; }
        if (!$channels)              { $res['msg'] = 'nie wybrano kanału'; return $res; }
        $client = db_one("SELECT name FROM k30_clients WHERE id = ?", [$clientId]) ?: ['name' => ''];
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $url  = $base . '/karty30/ti/kursant/index.php?tab=regulaminy';
        $org  = defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI';
        $seenEmail = []; $seenPhone = [];
        foreach (TiLicencjeNotifier::recipients($clientId) as $rc) {
            $guardian = $rc['kind'] === 'opiekun';
            if (in_array('email', $channels, true)) {
                $to = $rc['email'];
                if ($rc['email_skip'] !== '') self::note($res, (int)$a['id'], 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', $rc['email_skip'], $by);
                elseif (isset($seenEmail[strtolower($to)])) { /* ten sam adres — jedna wiadomość */ }
                elseif (ti_virtual_blocked($to)) self::note($res, (int)$a['id'], 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', 'kursant wirtualny — bez wiadomości', $by);
                else {
                    $seenEmail[strtolower($to)] = true;
                    $name = $guardian ? $rc['name'] : (string)(explode(' ', trim($rc['name']))[0] ?? '');
                    $r = email_tpl_render(self::TEMPLATE, [
                        'org' => h($org), 'greeting' => $name !== '' ? ' ' . h($name) : '', 'student' => h((string)$client['name']), 'software' => h((string)$a['license_name']),
                        'minor_note' => $guardian ? 'Jako opiekun otrzymujesz tę wiadomość do wiadomości — oświadczenie akceptuje uczestnik we własnym panelu.' : '', 'url' => h($url),
                    ]);
                    if (empty($r['enabled'])) self::note($res, (int)$a['id'], 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', 'szablon e-mail jest wyłączony', $by);
                    else {
                        try {
                            $mid = mail_queue_add($to, $rc['name'], $r['subject'], $r['html'], '', self::TEMPLATE, (int)$a['id'], '', false);
                            self::note($res, (int)$a['id'], 'email', $rc, TiLicencjeNotifier::maskEmail($to), $mid > 0 ? 'sent' : 'skipped', $mid > 0 ? '' : 'zablokowane (kontakt kursanta wirtualnego)', $by);
                        } catch (\Throwable $e) { self::note($res, (int)$a['id'], 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'failed', $e->getMessage(), $by); }
                    }
                }
            }
            if (in_array('sms', $channels, true)) {
                if ($rc['sms_skip'] !== '') { self::note($res, (int)$a['id'], 'sms', $rc, '', 'skipped', $rc['sms_skip'], $by); continue; }
                $r = sms_tpl_render(self::TEMPLATE, ['org' => self::ascii($org), 'student' => self::ascii((string)$client['name']), 'software' => self::ascii((string)$a['license_name']), 'url' => $url]);
                if (empty($r['enabled']) || trim($r['message']) === '') { self::note($res, (int)$a['id'], 'sms', $rc, '', 'skipped', 'szablon SMS jest wyłączony', $by); continue; }
                foreach ($rc['phones'] as $phone) {
                    $key = substr((string)preg_replace('/\D/', '', $phone), -9);
                    if (isset($seenPhone[$key])) continue;
                    $seenPhone[$key] = true;
                    if (ti_virtual_blocked('', $phone)) { self::note($res, (int)$a['id'], 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'skipped', 'kursant wirtualny — bez wiadomości', $by); continue; }
                    try { sms_send($phone, $r['message'], 'ti_claude_statement'); self::note($res, (int)$a['id'], 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'sent', '', $by); }
                    catch (\Throwable $e) { self::note($res, (int)$a['id'], 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'failed', $e->getMessage(), $by); }
                }
            }
        }
        $res['ok'] = ($res['sent']['email'] + $res['sent']['sms']) > 0;
        $parts = [];
        foreach ($channels as $ch) {
            $l = $ch === 'email' ? 'E-mail' : 'SMS';
            if ($res['sent'][$ch] > 0) $parts[] = $l . ': wysłano ' . $res['sent'][$ch];
            elseif ($res['failed'][$ch] > 0) $parts[] = $l . ': błąd';
            else { $why = array_values(array_filter($res['skipped'], fn($s) => $s['channel'] === $ch)); $parts[] = $l . ': pominięto' . ($why ? ' (' . $why[0]['reason'] . ')' : ''); }
        }
        $res['msg'] = implode('. ', $parts) . '.';
        return $res;
    }

    private static function note(array &$res, int $assignmentId, string $channel, array $rc, string $to, string $status, string $detail, ?int $by): void
    {
        db()->prepare("INSERT INTO k30_ti_license_notifications (assignment_id, channel, recipient_kind, recipient, status, detail, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$assignmentId, $channel, $rc['kind'], $to, $status, mb_substr('oświadczenie Claude: ' . $detail, 0, 300), $by, date('Y-m-d H:i:s')]);
        if ($status === 'sent')       $res['sent'][$channel]++;
        elseif ($status === 'failed') $res['failed'][$channel]++;
        else                          $res['skipped'][] = ['who' => $rc['kind'], 'channel' => $channel, 'reason' => $detail];
    }

    private static function ascii(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false ? $t : $s;
    }
}
