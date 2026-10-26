<?php
/**
 * includes/crm_mail_autoreply.php — autoodpowiedzi skrzynek współdzielonych CRM (np. faktury@feer.org.pl: „przyjęliśmy fakturę”).
 *
 * Konfiguracja per skrzynka (crm_mail_autoreply): włączona, tylko poza godzinami pracy (includes/office_hours.php), temat i treść z symbolami
 * {{nadawca}} {{temat}} {{nr}} {{organizacja}} {{otwarte}} (kiedy znów pracujemy), limit odpowiedzi na nadawcę, nadawca = skrzynka albo adres systemowy.
 * Bezpieczniki (pętle i spam): bez odpowiedzi na odbicia, no-reply, autoodpowiedzi i własne adresy; tylko na PIERWSZĄ wiadomość rozmowy;
 * jedna odpowiedź na nadawcę w oknie per_sender_hours; wiadomość musi być nowsza niż chwila włączenia autoodpowiedzi (since_at) — włączenie nie
 * odpowiada wstecz na starą pocztę; każda decyzja w crm_mail_autoreply_log (comm_id UNIQUE → bez dubli przy ponowieniu crona).
 * Wysyłka przez mail_queue_add (zaczep $GLOBALS['crm_mail_sender'] w testach — nic nie wychodzi na zewnątrz).
 */

const CRM_AR_AUTO_SUBJECT = '/^(auto(matic)?[ -]?(reply|response|antwort)|automatyczna odpowied|odpowied[zź] automatyczna|out of office|poza biurem|nieobecno|undeliver|niedostarcz|delivery (status|failure)|mail delivery|returned mail|zwrócona wiadomo|autoreply|abwesenheit|read:|przeczytano:)/iu';
const CRM_AR_AUTO_SENDER = '/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounces?|notifications?|powiadomienia)([+.@_-]|$)/i';

function crm_ar_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_autoreply (
            mailbox_id          INTEGER PRIMARY KEY,
            enabled             INTEGER NOT NULL DEFAULT 0,
            only_outside_hours  INTEGER NOT NULL DEFAULT 1,
            subject             VARCHAR(200) NOT NULL DEFAULT '',
            body                TEXT NOT NULL DEFAULT '',
            from_mode           VARCHAR(8) NOT NULL DEFAULT 'mailbox',
            per_sender_hours    INTEGER NOT NULL DEFAULT 24,
            since_at            VARCHAR(19),
            updated_by          INTEGER,
            updated_at          VARCHAR(19)
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_autoreply_log (
            id          {$pk},
            comm_id     INTEGER NOT NULL,
            mailbox_id  INTEGER NOT NULL,
            to_email    VARCHAR(255) NOT NULL DEFAULT '',
            status      VARCHAR(10) NOT NULL DEFAULT '',
            reason      VARCHAR(200) NOT NULL DEFAULT '',
            created_at  VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_ar_log_comm ON crm_mail_autoreply_log(comm_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_ar_log_to ON crm_mail_autoreply_log(to_email, created_at)");
    } catch (\Throwable $e) {
        error_log('[crm_ar_migrate] ' . $e->getMessage());
    }
}

function crm_ar_default_subject(): string { return 'Potwierdzenie przyjęcia wiadomości: {{temat}}'; }

function crm_ar_default_body(): string
{
    return "Dzień dobry,\n\nDziękujemy za wiadomość. Została przyjęta i trafi do osoby, która się nią zajmie (numer {{nr}}).\n"
         . "Obecnie poza godzinami pracy — odpowiemy {{otwarte}}.\n\nPozdrawiamy\n{{organizacja}}\n\n"
         . "To jest wiadomość automatyczna, prosimy na nią nie odpowiadać.";
}

/** Konfiguracja skrzynki (z domyślnymi, gdy brak wiersza). */
function crm_ar_get(int $mailbox_id): array
{
    crm_ar_migrate();
    $r = null;
    try { $r = db_one("SELECT * FROM crm_mail_autoreply WHERE mailbox_id = ?", [$mailbox_id]); } catch (\Throwable $e) {}
    return $r ?: ['mailbox_id' => $mailbox_id, 'enabled' => 0, 'only_outside_hours' => 1, 'subject' => crm_ar_default_subject(), 'body' => crm_ar_default_body(),
                  'from_mode' => 'mailbox', 'per_sender_hours' => 24, 'since_at' => null, 'updated_by' => null, 'updated_at' => null];
}

/** Zapis konfiguracji. Włączenie (lub pierwszy zapis jako włączona) ustawia since_at = teraz: bez odpowiedzi wstecz na starą pocztę. */
function crm_ar_save(int $mailbox_id, array $d, ?int $by = null): void
{
    crm_ar_migrate();
    if (!db_one("SELECT 1 AS x FROM poczta_mailboxes WHERE id = ?", [$mailbox_id])) throw new \InvalidArgumentException('Nieznana skrzynka.');
    $cur = crm_ar_get($mailbox_id);
    $enabled = !empty($d['enabled']) ? 1 : 0;
    $subject = trim((string)($d['subject'] ?? '')) ?: crm_ar_default_subject();
    $body = trim((string)($d['body'] ?? '')) ?: crm_ar_default_body();
    $row = ['enabled' => $enabled, 'only_outside_hours' => !empty($d['only_outside_hours']) ? 1 : 0, 'subject' => mb_substr($subject, 0, 200), 'body' => mb_substr($body, 0, 5000),
            'from_mode' => ($d['from_mode'] ?? 'mailbox') === 'system' ? 'system' : 'mailbox', 'per_sender_hours' => max(1, min(168, (int)($d['per_sender_hours'] ?? 24))),
            'since_at' => ($enabled && (!(int)$cur['enabled'] || empty($cur['since_at']))) ? date('Y-m-d H:i:s') : ($cur['since_at'] ?? null),
            'updated_by' => $by ?? ((int)(current_user()['id'] ?? 0) ?: null), 'updated_at' => date('Y-m-d H:i:s')];
    if (db_one("SELECT 1 AS x FROM crm_mail_autoreply WHERE mailbox_id = ?", [$mailbox_id])) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
        db()->prepare("UPDATE crm_mail_autoreply SET $set WHERE mailbox_id = :mid")->execute($row + ['mid' => $mailbox_id]);
    } else {
        db_insert('crm_mail_autoreply', $row + ['mailbox_id' => $mailbox_id]);
    }
}

/** Podstawia symbole; wartości są zabezpieczone (HTML), a treść zamieniana na akapity. @return array{subject:string,html:string,text:string} */
function crm_ar_render(array $cfg, array $vars): array
{
    $plain = fn(string $s) => strtr($s, array_combine(array_map(fn($k) => '{{' . $k . '}}', array_keys($vars)), array_map(fn($v) => (string)$v, array_values($vars))));
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $plain((string)$cfg['subject'])));
    $text = $plain((string)$cfg['body']);
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1d1d1d">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false) . '</div>';
    return ['subject' => mb_substr($subject, 0, 200), 'html' => $html, 'text' => $text];
}

/** Powód pominięcia albo null (wolno odpowiedzieć). $c — wiersz crm_communications, $mb — wiersz poczta_mailboxes. */
function crm_ar_skip_reason(array $c, array $mb, array $cfg): ?string
{
    $from = strtolower(trim((string)($c['from_email'] ?? '')));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return 'brak poprawnego adresu nadawcy';
    if ($from === strtolower((string)$mb['mailbox'])) return 'nadawca to ta sama skrzynka';
    if (db_one("SELECT 1 AS x FROM poczta_mailboxes WHERE LOWER(mailbox) = ?", [$from])) return 'nadawca to skrzynka organizacji';
    if (preg_match(CRM_AR_AUTO_SENDER, $from)) return 'adres automatyczny (no-reply / odbicie)';
    if (preg_match(CRM_AR_AUTO_SUBJECT, trim((string)$c['subject']))) return 'odpowiedź automatyczna / odbicie';
    if (!empty($c['thread_key']) && db_one("SELECT 1 AS x FROM crm_communications n WHERE n.thread_key = ? AND n.id <> ? AND n.sent_at <= ?", [$c['thread_key'], (int)$c['id'], (string)$c['sent_at']])) return 'kolejna wiadomość w rozmowie';
    if ((int)$cfg['only_outside_hours']) {
        require_once __DIR__ . '/office_hours.php';
        if (oh_is_open((string)$c['sent_at'])) return 'wiadomość w godzinach pracy';
    }
    $win = date('Y-m-d H:i:s', strtotime('-' . max(1, (int)$cfg['per_sender_hours']) . ' hours'));
    if (db_one("SELECT 1 AS x FROM crm_mail_autoreply_log WHERE to_email = ? AND status = 'sent' AND mailbox_id = ? AND created_at >= ?", [$from, (int)$mb['id'], $win])) return 'limit: nadawca dostał już odpowiedź w oknie ' . (int)$cfg['per_sender_hours'] . ' h';
    return null;
}

/** Wysyłka przez kolejkę maili; $GLOBALS['crm_mail_sender'](array) — zaczep testowy. @return bool czy przyjęto do wysłania */
function crm_ar_send(array $m): bool
{
    if (isset($GLOBALS['crm_mail_sender']) && is_callable($GLOBALS['crm_mail_sender'])) return (bool)($GLOBALS['crm_mail_sender'])($m);
    require_once __DIR__ . '/mail_queue.php';
    return (bool)mail_queue_add($m['to'], $m['to_name'], $m['subject'], $m['html'], $m['text'], $m['context_type'], $m['context_id'], '', false, [], $m['from'], [], [], $m['headers']);
}

/**
 * Przebieg autoodpowiedzi: nowe wiadomości przychodzące skrzynek z włączoną autoodpowiedzią (od since_at), jeszcze bez wpisu w logu.
 * @return array{sent:int,skipped:int,errors:string[]}
 */
function crm_ar_run(int $limitPerMailbox = 50): array
{
    crm_ar_migrate();
    require_once __DIR__ . '/office_hours.php';
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    try {
        $cfgs = db_all("SELECT a.*, m.mailbox, m.enabled AS mb_enabled FROM crm_mail_autoreply a JOIN poczta_mailboxes m ON m.id = a.mailbox_id WHERE a.enabled = 1 AND m.enabled = 1");
    } catch (\Throwable $e) { $out['errors'][] = $e->getMessage(); return $out; }
    foreach ($cfgs as $cfg) {
        $mb = ['id' => (int)$cfg['mailbox_id'], 'mailbox' => (string)$cfg['mailbox']];
        try {
            $rows = db_all("SELECT c.* FROM crm_communications c WHERE c.mailbox_id = ? AND c.direction = 'in' AND c.sent_at >= ?
                              AND NOT EXISTS (SELECT 1 FROM crm_mail_autoreply_log l WHERE l.comm_id = c.id) ORDER BY c.id LIMIT " . (int)$limitPerMailbox,
                           [(int)$cfg['mailbox_id'], (string)($cfg['since_at'] ?: date('Y-m-d H:i:s'))]);
        } catch (\Throwable $e) { $out['errors'][] = $e->getMessage(); continue; }
        foreach ($rows as $c) {
            $to = strtolower(trim((string)($c['from_email'] ?? '')));
            $log = fn(string $st, string $why) => db()->prepare("INSERT OR IGNORE INTO crm_mail_autoreply_log (comm_id, mailbox_id, to_email, status, reason, created_at) VALUES (?,?,?,?,?,?)")
                ->execute([(int)$c['id'], $mb['id'], $to, $st, mb_substr($why, 0, 200), date('Y-m-d H:i:s')]);
            try {
                if ($why = crm_ar_skip_reason($c, $mb, $cfg)) { $log('skipped', $why); $out['skipped']++; continue; }
                $r = crm_ar_render($cfg, ['nadawca' => trim((string)($c['from_name'] ?? '')) ?: $to, 'temat' => (string)($c['subject'] ?: '(bez tematu)'), 'nr' => (string)($c['msg_no'] ?? ('#' . $c['id'])),
                                          'organizacja' => $org, 'otwarte' => oh_next_open() ?: 'w najbliższym czasie']);
                $ok = crm_ar_send(['to' => $to, 'to_name' => (string)($c['from_name'] ?? ''), 'subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text'],
                                   'context_type' => 'crm_autoreply', 'context_id' => (int)$c['id'], 'from' => $cfg['from_mode'] === 'mailbox' ? $mb['mailbox'] : '',
                                   'headers' => ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All', 'Precedence' => 'auto_reply']]);
                $log($ok ? 'sent' : 'skipped', $ok ? '' : 'kolejka wysyłki odrzuciła wiadomość');
                $ok ? $out['sent']++ : $out['skipped']++;
            } catch (\Throwable $e) {
                $out['errors'][] = '#' . $c['id'] . ': ' . $e->getMessage();
                $log('skipped', 'błąd: ' . $e->getMessage());
            }
        }
    }
    return $out;
}
