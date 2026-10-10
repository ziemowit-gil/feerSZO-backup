<?php
/**
 * modules/ti_exam_notify/logic/tiExamNotify.php — powiadomienia (e-mail + SMS) dla kursantów o egzaminach Equi Exams.
 *
 * Dwa rodzaje powiadomień, każde dla pary (egzamin, kursant) wysyłane TYLKO RAZ na kanał
 * (indeks unikalny w k30_ti_exam_notifications):
 *   • open    — egzamin został udostępniony (is_active=1, open_at pusty albo już minął, close_at nie minął);
 *   • closing — do zamknięcia egzaminu zostało mniej niż 24 h (close_at w ciągu najbliższej doby);
 *               pomijamy kursantów, którzy już oddali pracę (podejście ze submitted_at).
 *
 * Odbiorcy: aktywne zapisy (k30_ti_enrollments.status='active') z aktywnym kontem kursanta; grupy archiwalne
 * (status archived/cancelled, closed_at, is_active=0, nazwa od „#”) są pomijane. Kursant „bez rozliczeń”
 * (no_billing) nie dostaje nic — tak samo jak przy innych powiadomieniach TI. SMS: numery kursanta tylko gdy
 * włączył „SMS o zajęciach” (notify_sms_lessons, jak ti_lesson_sms_notify), opiekun małoletniego — gdy nie
 * wyłączył powiadomień; kontakty kursantów wirtualnych blokują centralnie sms_send / mail_queue_add.
 *
 * Treści: szablony e-mail/SMS `ti_exam_open` i `ti_exam_closing` (Panel dydaktyka → Szablony e-mail / SMS).
 * Rodzaj SMS (nadawca): `ti_exam_notice` (modules/sms_senders). E-mail na adresy spoza @feer.org.pl poza
 * godzinami 7:00–22:00 trafia do kolejki z terminem 7:00 (jak ti_student_onboarding_send).
 *
 * Włącznik: org_setting('ti_exam_notify_enabled') — domyślnie WYŁĄCZONE ('0'); agent kończy od razu, gdy wyłączone.
 * Uruchamianie: cron/agents/ti_exam_notify.php (dyspozytor: co godzinę w oknie 8:00–20:00), idempotentnie.
 *
 * Test hermetyczny: ti_exam_notify_run(['now' => ts, 'mailer' => fn, 'smser' => fn]) — atrapy zamiast wysyłek.
 */

require_once dirname(__DIR__, 3) . '/includes/ti_exams.php';
require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';

const TI_EXAM_NOTIFY_SETTING  = 'ti_exam_notify_enabled';
const TI_EXAM_NOTIFY_SMS_KIND = 'ti_exam_notice';
const TI_EXAM_NOTIFY_LINK     = '/karty30/ti/kursant/index.php?tab=egzaminy';

function ti_exam_notify_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_exam_notifications (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            exam_id   INTEGER NOT NULL,
            client_id INTEGER NOT NULL,
            kind      TEXT    NOT NULL,          -- open | closing
            channel   TEXT    NOT NULL,          -- mail | sms
            sent_at   DATETIME,
            UNIQUE(exam_id, client_id, kind, channel)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_exam_notif_exam ON k30_ti_exam_notifications(exam_id)");
    } catch (\Throwable $e) {}
}

/** Czy powiadomienia o egzaminach są włączone (domyślnie nie). */
function ti_exam_notify_enabled(): bool
{
    // Bez cache org_setting() — po zapisie w tym samym żądaniu (przełącznik w panelu) stan ma być aktualny.
    try { $r = db_one("SELECT value FROM settings WHERE key_=?", [TI_EXAM_NOTIFY_SETTING]); } catch (\Throwable $e) { return false; }
    return (string)($r['value'] ?? '0') === '1';
}

function ti_exam_notify_set_enabled(bool $on): void
{
    org_setting_set(TI_EXAM_NOTIFY_SETTING, $on ? '1' : '0');
}

/** Link do zakładki egzaminów w panelu kursanta. */
function ti_exam_notify_link(): string
{
    return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . TI_EXAM_NOTIFY_LINK;
}

/**
 * Egzaminy, dla których są dziś powiadomienia do wysłania: ['open' => [...], 'closing' => [...]].
 * $now — znacznik czasu „teraz” (do testów).
 */
function ti_exam_notify_candidates(int $now): array
{
    $out = ['open' => [], 'closing' => []];
    $rows = db_all(
        "SELECT e.id, e.course_id, e.title, e.open_at, e.close_at, c.name AS course_name
           FROM k30_ti_exams e
           JOIN k30_ti_courses c ON c.id = e.course_id
          WHERE e.is_active = 1
            AND COALESCE(c.is_active, 1) = 1
            AND COALESCE(c.status, '') NOT IN ('archived', 'cancelled')
            AND (c.closed_at IS NULL OR c.closed_at = '')
            AND c.name NOT LIKE '#%'
          ORDER BY e.id"
    );
    foreach ($rows as $e) {
        $open = trim((string)($e['open_at'] ?? ''));
        $shut = trim((string)($e['close_at'] ?? ''));
        $openTs = $open !== '' ? (int)strtotime($open) : 0;
        $shutTs = $shut !== '' ? (int)strtotime($shut) : 0;
        if ($openTs > $now) continue;                       // jeszcze nieotwarty
        if ($shutTs > 0 && $shutTs <= $now) continue;       // już zamknięty
        $out['open'][] = $e;
        if ($shutTs > 0 && $shutTs - $now <= 86400) $out['closing'][] = $e;
    }
    return $out;
}

/** Odbiorcy powiadomień w grupie: aktywne zapisy z aktywnym kontem kursanta. */
function ti_exam_notify_recipients(int $course_id): array
{
    return db_all(
        "SELECT cl.id AS client_id, cl.name, cl.email, cl.phone,
                a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified,
                a.is_minor, a.guardian_phone, a.notify_sms_lessons, a.parent_notify_lessons
           FROM k30_ti_enrollments e
           JOIN k30_ti_student_accounts a ON a.client_id = e.client_id AND a.is_active = 1
           JOIN k30_clients cl ON cl.id = e.client_id
          WHERE e.course_id = ? AND e.status = 'active'
          GROUP BY cl.id ORDER BY cl.name",
        [$course_id]
    );
}

/** Numery SMS dla odbiorcy — te same zgody co przy powiadomieniach o lekcjach (ti_lesson_sms_notify). */
function ti_exam_notify_phones(array $r): array
{
    $nums = [];
    if (!empty($r['notify_sms_lessons']) && function_exists('k30_ti_sms_numbers')) $nums = k30_ti_sms_numbers($r);
    elseif (!empty($r['notify_sms_lessons'])) { $p = trim((string)($r['phone'] ?? '')); if ($p !== '') $nums[] = $p; }
    if (!empty($r['is_minor']) && !empty($r['parent_notify_lessons'])) {
        $gp = trim((string)($r['guardian_phone'] ?? ''));
        if ($gp !== '' && !in_array($gp, $nums, true)) $nums[] = $gp;
    }
    return $nums;
}

/** Termin „do kiedy” po polsku (np. „12.10.2026 do 18:00”) albo „do odwołania”. */
function ti_exam_notify_until(string $close_at): string
{
    $close_at = trim($close_at);
    if ($close_at === '' || !($ts = strtotime($close_at))) return 'do odwołania';
    return date('d.m.Y', $ts) . ' do ' . date('H:i', $ts);
}

/** Zmienne szablonów ({{imie}}, {{tytul}}, {{kurs}}, {{do_kiedy}}, {{link}}, {{org}}). */
function ti_exam_notify_vars(array $exam, array $r): array
{
    $parts = preg_split('/\s+/', trim((string)($r['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return [
        'imie'     => $parts ? (string)$parts[0] : '',
        'tytul'    => (string)($exam['title'] ?? ''),
        'kurs'     => (string)($exam['course_name'] ?? ''),
        'do_kiedy' => ti_exam_notify_until((string)($exam['close_at'] ?? '')),
        'link'     => ti_exam_notify_link(),
        'org'      => defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI',
    ];
}

/** Domyślna wysyłka e-maila przez kolejkę: poza 7:00–22:00 (adresy spoza @feer.org.pl) termin 7:00 w UTC. */
function ti_exam_notify_mailer(string $to, string $name, string $subject, string $html, string $ctx, int $ctx_id): bool
{
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $scheduled = '';
    if (!str_ends_with(strtolower($to), '@feer.org.pl')) {
        $h = (int)date('G');
        if ($h < 7 || $h >= 22) {
            // Kolejka porównuje czas z datetime('now') (UTC), więc 7:00 czasu polskiego zapisujemy jako UTC.
            $pl = new DateTime($h >= 22 ? 'tomorrow 07:00' : 'today 07:00', new DateTimeZone('Europe/Warsaw'));
            $pl->setTimezone(new DateTimeZone('UTC'));
            $scheduled = $pl->format('Y-m-d H:i:s');
        }
    }
    $id = mail_queue_add($to, $name, $subject, $html, '', $ctx, $ctx_id, $scheduled, false);
    return (int)$id > 0;
}

/** Domyślna wysyłka SMS (rodzaj: ti_exam_notice). */
function ti_exam_notify_smser(string $phone, string $message): bool
{
    require_once dirname(__DIR__, 3) . '/includes/sms.php';
    if (!function_exists('sms_channel_ready') || !sms_channel_ready()) return false;
    sms_send($phone, $message, 'ti_exam_notice');
    return true;
}

/**
 * Przebieg powiadomień. Zwraca statystyki.
 * $opts: now (int, domyślnie time()), dry_run (bool), mailer (callable jak ti_exam_notify_mailer),
 *        smser (callable jak ti_exam_notify_smser), force (bool — ignoruj włącznik; tylko podgląd/test).
 */
function ti_exam_notify_run(array $opts = []): array
{
    ti_exam_notify_migrate();
    $now    = (int)($opts['now'] ?? time());
    $dry    = !empty($opts['dry_run']);
    $mailer = $opts['mailer'] ?? 'ti_exam_notify_mailer';
    $smser  = $opts['smser']  ?? 'ti_exam_notify_smser';
    $res = ['enabled' => ti_exam_notify_enabled(), 'exams' => ['open' => 0, 'closing' => 0],
            'sent' => ['mail' => 0, 'sms' => 0], 'skipped' => [], 'errors' => [], 'note' => ''];
    if (!$res['enabled'] && empty($opts['force'])) {
        $res['note'] = 'Powiadomienia o egzaminach są wyłączone (Panel dydaktyka → Egzaminy).';
        return $res;
    }
    $skip = function (string $why) use (&$res) { $res['skipped'][$why] = ($res['skipped'][$why] ?? 0) + 1; };

    $cand = ti_exam_notify_candidates($now);
    $pdo  = db();
    $reserve = $pdo->prepare("INSERT OR IGNORE INTO k30_ti_exam_notifications (exam_id, client_id, kind, channel, sent_at) VALUES (?, ?, ?, ?, datetime('now'))");
    $unreserve = $pdo->prepare("DELETE FROM k30_ti_exam_notifications WHERE exam_id=? AND client_id=? AND kind=? AND channel=?");

    foreach (['open', 'closing'] as $kind) {
        $tplKey = $kind === 'open' ? 'ti_exam_open' : 'ti_exam_closing';
        foreach ($cand[$kind] as $exam) {
            $res['exams'][$kind]++;
            $exam_id = (int)$exam['id'];
            foreach (ti_exam_notify_recipients((int)$exam['course_id']) as $r) {
                $cid = (int)$r['client_id'];
                if (ti_client_no_billing($cid)) { $skip('kursant bez rozliczeń (no_billing)'); continue; }
                if ($kind === 'closing') {
                    $done = db_one("SELECT 1 FROM k30_ti_exam_attempts WHERE exam_id=? AND client_id=? AND submitted_at IS NOT NULL AND submitted_at != '' LIMIT 1", [$exam_id, $cid]);
                    if ($done) { $skip('praca już oddana'); continue; }
                }
                $vars = ti_exam_notify_vars($exam, $r);

                // ── e-mail ──
                $email = trim((string)($r['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $skip('brak e-maila');
                else {
                    $already = db_one("SELECT 1 FROM k30_ti_exam_notifications WHERE exam_id=? AND client_id=? AND kind=? AND channel='mail'", [$exam_id, $cid, $kind]);
                    if ($already) $skip('e-mail już wysłany');
                    elseif ($dry) $res['sent']['mail']++;
                    else {
                        $t = email_tpl_render($tplKey, $vars);
                        if (!$t['enabled']) $skip('szablon e-mail wyłączony');
                        elseif (trim($t['subject']) === '' || trim(strip_tags($t['html'])) === '') $skip('pusty szablon e-mail');
                        else {
                            $reserve->execute([$exam_id, $cid, $kind, 'mail']);
                            try {
                                if ($mailer($email, (string)$r['name'], $t['subject'], $t['html'], 'ti_exam_' . $kind, $exam_id)) $res['sent']['mail']++;
                                else { $unreserve->execute([$exam_id, $cid, $kind, 'mail']); $skip('adres zablokowany (kursant wirtualny)'); }
                            } catch (\Throwable $ex) {
                                $unreserve->execute([$exam_id, $cid, $kind, 'mail']);
                                $res['errors'][] = "egzamin #{$exam_id}, kursant #{$cid}, e-mail: " . $ex->getMessage();
                            }
                        }
                    }
                }

                // ── SMS ──
                $phones = ti_exam_notify_phones($r);
                if (!$phones) $skip('brak numeru / zgody na SMS');
                else {
                    $already = db_one("SELECT 1 FROM k30_ti_exam_notifications WHERE exam_id=? AND client_id=? AND kind=? AND channel='sms'", [$exam_id, $cid, $kind]);
                    if ($already) $skip('SMS już wysłany');
                    elseif ($dry) $res['sent']['sms']++;
                    else {
                        $m = sms_tpl_render($tplKey, $vars);
                        $msg = trim(function_exists('sms_ascii') ? sms_ascii($m['message']) : $m['message']);   // bez ogonków: 160 znaków w segmencie
                        if (!$m['enabled']) $skip('szablon SMS wyłączony');
                        elseif ($msg === '') $skip('pusty szablon SMS');
                        else {
                            $reserve->execute([$exam_id, $cid, $kind, 'sms']);
                            $ok = false;
                            foreach ($phones as $num) {
                                try { if ($smser($num, $msg)) $ok = true; }
                                catch (\Throwable $ex) { $res['errors'][] = "egzamin #{$exam_id}, kursant #{$cid}, SMS: " . (function_exists('sms_scrub') ? sms_scrub($ex->getMessage()) : $ex->getMessage()); }
                            }
                            if ($ok) $res['sent']['sms']++;
                            else { $unreserve->execute([$exam_id, $cid, $kind, 'sms']); $skip('SMS nie wysłany (brak bramki albo błąd)'); }
                        }
                    }
                }
            }
        }
    }
    return $res;
}
