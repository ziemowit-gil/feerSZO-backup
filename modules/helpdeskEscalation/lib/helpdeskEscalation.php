<?php
/**
 * modules/helpdeskEscalation/lib/helpdeskEscalation.php — automatyczne podbijanie zgłoszeń po przekroczeniu SLA.
 *
 * Zgłaszający nie podbija już ręcznie — robi to system (cron co godzinę; włącznik hd_autoesc_enabled, domyślnie WYŁĄCZONY,
 * bo wysyła e-maile):
 *   poziom 1 „podbicie”  — gdy przekroczony jest termin reakcji albo rozwiązania (hd_sla, z godzinami pracy i wstrzymaniem):
 *                          zapis w helpdesk_escalations (numer + PDF), powiadomienie operatorów (priorytetowo) i zgłaszającego;
 *   poziom 2 „przełożony” — gdy po $hours (hd_autoesc_l2_hours, domyślnie 24) od poprzedniego podbicia nadal jest przekroczone:
 *                          kolejny zapis, powiadomienie administratorów i adresów z hd_autoesc_notify, opcjonalnie podniesienie
 *                          priorytetu o stopień (hd_autoesc_raise=1) oraz tag „eskalacja”.
 * Najwyżej 2 podbicia automatyczne na zgłoszenie; zgłoszenia rozwiązane/zamknięte, scalone i wstrzymane są pomijane.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 2) . '/helpdeskCollab/lib/helpdeskCollab.php';

function hdAutoEscSettings(): array
{
    return [
        'enabled'  => org_setting('hd_autoesc_enabled') === '1',
        'l2_hours' => max(1, min(720, (int)(org_setting('hd_autoesc_l2_hours') ?: 24))),
        'raise'    => org_setting('hd_autoesc_raise') === '1',
        'notify'   => trim(org_setting('hd_autoesc_notify')),
    ];
}

function hdAutoEscMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    foreach (["ALTER TABLE helpdesk_escalations ADD COLUMN level INTEGER NOT NULL DEFAULT 1",
              "ALTER TABLE helpdesk_escalations ADD COLUMN is_auto INTEGER NOT NULL DEFAULT 0"] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
}

/** Co przekroczone w zgłoszeniu: 'response' / 'resolution' albo null (SLA w porządku, wstrzymane lub zakończone). */
function hdAutoEscBreach(array $ticket): ?string
{
    $sla = hd_sla($ticket);
    foreach (['resolution', 'response'] as $p) if (($sla[$p]['state'] ?? '') === 'breached' && empty($sla[$p]['done_at'])) return $p;
    return null;
}

/**
 * Zgłoszenia do podbicia teraz. @return list<array{ticket:array,level:int,breach:string}>
 * Poziom 2 dopiero, gdy od ostatniego podbicia minęło $l2Hours godzin.
 */
function hdAutoEscCandidates(?int $now = null): array
{
    hdAutoEscMigrate();
    $s = hdAutoEscSettings(); $now = $now ?? time(); $out = [];
    foreach (db_all("SELECT * FROM helpdesk_tickets WHERE merged_into IS NULL AND status NOT IN ('rozwiązane','zamknięte')") as $t) {
        $breach = hdAutoEscBreach($t);
        if ($breach === null) continue;
        $last = db_one("SELECT level, created_at FROM helpdesk_escalations WHERE ticket_id=? AND is_auto=1 ORDER BY id DESC LIMIT 1", [(int)$t['id']]);
        if (!$last) { $out[] = ['ticket' => $t, 'level' => 1, 'breach' => $breach]; continue; }
        if ((int)$last['level'] >= 2) continue;
        if ($now - strtotime((string)$last['created_at']) >= $s['l2_hours'] * 3600) $out[] = ['ticket' => $t, 'level' => 2, 'breach' => $breach];
    }
    return $out;
}

/** Przebieg automatu (cron). $dryRun — tylko liczy. Zwraca ['l1'=>n,'l2'=>n,'candidates'=>n] albo ['disabled'=>true]. */
function hdAutoEscRun(bool $dryRun = false): array
{
    hdAutoEscMigrate();
    $s = hdAutoEscSettings();
    if (!$s['enabled'] && !$dryRun) return ['disabled' => true, 'l1' => 0, 'l2' => 0, 'candidates' => 0];
    $c = hdAutoEscCandidates();
    $res = ['l1' => 0, 'l2' => 0, 'candidates' => count($c)];
    if ($dryRun) return $res;
    foreach ($c as $x) {
        try {
            hdAutoEscalateTicket($x['ticket'], $x['level'], $x['breach'], $s);
            $res[$x['level'] === 1 ? 'l1' : 'l2']++;
        } catch (\Throwable $e) { error_log('[helpdeskEscalation] #' . $x['ticket']['id'] . ': ' . $e->getMessage()); }
    }
    return $res;
}

function hdAutoEscalateTicket(array $t, int $level, string $breach, array $s): void
{
    $what = $breach === 'resolution' ? 'terminu rozwiązania' : 'terminu reakcji';
    $reason = 'Automatyczne podbicie (poziom ' . $level . '): przekroczenie ' . $what . ' SLA.';
    $esc = hd_escalate($t, $reason, ['id' => null, 'name' => 'System (SLA)'], ['auto' => true, 'level' => $level, 'skip_requester' => $level > 1]);
    if ($level === 2) {
        hdAutoEscNotifySupervisors($t, $esc['number'], $reason, $s);
        if ($s['raise']) {
            $order = array_keys(HD_PRIORITIES);
            $i = array_search($t['priority'], $order, true);
            if ($i !== false && $i < count($order) - 1) {
                db_update('helpdesk_tickets', ['priority' => $order[$i + 1], 'updated_at' => date('Y-m-d H:i:s')], (int)$t['id']);
                db_insert('helpdesk_messages', ['ticket_id' => (int)$t['id'], 'user_id' => null, 'user_name' => 'System (SLA)', 'is_internal' => 1,
                    'body' => 'Priorytet podniesiony: ' . HD_PRIORITIES[$t['priority']]['label'] . ' → ' . HD_PRIORITIES[$order[$i + 1]]['label'] . ' (eskalacja poziomu 2).']);
            }
        }
        hdTagAdd((int)$t['id'], 'eskalacja');
    }
}

/** Powiadomienie przełożonych: administratorzy + adresy z hd_autoesc_notify (po przecinku). */
function hdAutoEscNotifySupervisors(array $t, string $escNumber, string $reason, array $s): void
{
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $to = [];
    foreach (db_all("SELECT email, name FROM users WHERE role='admin' AND is_active=1 AND email IS NOT NULL AND email<>''") as $u)
        if (hdNotifyAllows($u['email'], 'escalation', $t)) $to[strtolower($u['email'])] = $u['name'];
    foreach (preg_split('/[\s,;]+/', $s['notify']) as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $to[strtolower($e)] = '';
    $url = APP_URL . '/helpdesk/view.php?id=' . (int)$t['id'];
    $html = hd_mail_layout(['title' => 'Eskalacja zgłoszenia — poziom 2', 'preheader' => $t['number'] . ' — termin SLA nadal przekroczony',
        'intro' => '<p style="margin:0">Zgłoszenie było już podbite, a termin SLA jest nadal przekroczony. Wymaga decyzji przełożonego.</p>',
        'facts' => [['Zgłoszenie', $t['number']], ['Temat', $t['title']], ['Priorytet', HD_PRIORITIES[$t['priority']]['label'] ?? $t['priority']],
                    ['Przypisane do', ($t['assigned_to'] ? (db_one("SELECT name FROM users WHERE id=?", [(int)$t['assigned_to']])['name'] ?? '—') : 'nieprzypisane')],
                    ['Numer podbicia', $escNumber]],
        'quote' => ['label' => 'Powód', 'html' => hdMailText($reason)], 'cta' => ['label' => 'Otwórz zgłoszenie', 'url' => $url]]);
    foreach ($to as $email => $name) mail_queue_add($email, $name, '[PILNE] Eskalacja poziomu 2: ' . $t['number'] . ' — ' . $t['title'], $html);
}
