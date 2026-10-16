<?php
/**
 * modules/it_control/logic/itControlMail.php — e-maile modułów kontroli IT.
 *
 * Dwa rodzaje wiadomości, jeden szablon (itControlEmail):
 *   - 'report' — okresowy raport (dzienny/tygodniowy) z agenta it_control_report,
 *   - 'alert'  — natychmiastowy alert przy znalezisku krytycznym (agenci logs/ops).
 * Każda ma wersję HTML (tabele + style inline — klienci poczty) i tekstową;
 * pełny raport HTML idzie jako załącznik (UPLOAD_DIR/it_control/).
 *
 * Bezpiecznik alertów (itControlProcessAlert): ten sam zestaw krytycznych
 * znalezisk (odcisk) nie częściej niż co itc_alert_cooldown_min, dowolne alerty
 * nie częściej niż co itc_alert_min_gap_min i maks. itc_alert_max_per_day na dobę.
 * Wstrzymane alerty są zapisywane w itc_mail_log ze statusem „suppressed”.
 */

require_once __DIR__ . '/itControlReport.php';

function itControlDashboardUrl(): string {
    return (defined('APP_URL') ? APP_URL : '') . '/modules/it_control/index.php';
}

/**
 * Buduje wiadomość. $opts: period_label, critical (lista znalezisk dla alertu).
 * @return array{subject:string,html:string,text:string}
 */
function itControlEmail(string $kind, array $runs, array $opts = []): array {
    $status = itControlRunsStatus($runs);
    $org = defined('ORG_NAME') ? ORG_NAME : 'SZO';
    $url = itControlDashboardUrl();
    $events = array_sum(array_column($runs, 'events_processed'));
    $critical = [];
    foreach ($runs as $run) foreach (itControlRunFindings($run, ['critical', 'high']) as $f) $critical[] = $f;
    $recs = [];
    foreach ($runs as $run) foreach (itControlRunRecommendations($run) as $rec) $recs[$rec] = true;
    $recs = array_slice(array_keys($recs), 0, 8);

    if ($kind === 'alert') {
        $crit = $opts['critical'] ?? array_values(array_filter($critical, fn($f) => $f['severity'] === 'critical'));
        $subject = '[SZO ALERT] Anomalia krytyczna: ' . ($crit[0]['title'] ?? 'kontrola IT') . (count($crit) > 1 ? ' (+' . (count($crit) - 1) . ')' : '');
        $heading = 'Wykryto anomalię krytyczną';
        $intro = 'Automatyczna kontrola wykryła zdarzenia wymagające natychmiastowej reakcji.';
        $critical = $crit;
    } else {
        $subject = '[SZO Kontrola IT] ' . $status . ' — raport ' . ($opts['period_label'] ?? date('Y-m-d'));
        $heading = 'Raport kontroli IT';
        $intro = 'Podsumowanie automatycznej kontroli dzienników i infrastruktury' . (!empty($opts['period_label']) ? ' za okres ' . $opts['period_label'] : '') . '.';
    }
    $c = ITC_STATUS_COLORS[$status] ?? ITC_STATUS_COLORS['SKIPPED'];

    // ── HTML ──────────────────────────────────────────────────────────────────
    $td = 'padding:8px 12px;border-bottom:1px solid #e2e8f0;font:13px/1.45 Arial,Helvetica,sans-serif;color:#0f172a';
    $html = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . itcEsc($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0">'
        // nagłówek z paskiem w kolorze statusu
        . '<tr><td style="background:#0f172a;padding:18px 24px;border-bottom:4px solid ' . $c['fg'] . '">'
        . '<div style="font:12px Arial,sans-serif;color:#cbd5e1;text-transform:uppercase;letter-spacing:.08em">' . itcEsc($org) . ' · Kontrola IT</div>'
        . '<div style="font:700 20px Arial,sans-serif;color:#ffffff;margin-top:4px">' . itcEsc($heading) . '</div></td></tr>'
        . '<tr><td style="padding:20px 24px 8px;font:14px/1.5 Arial,Helvetica,sans-serif;color:#0f172a">' . itcEsc($intro)
        . '<div style="margin-top:12px">Status: ' . itControlBadge($status) . '</div></td></tr>';
    // KPI
    $html .= '<tr><td style="padding:8px 24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px"><tr>'
        . itControlKpiCell('Zdarzeń', number_format($events, 0, ',', ' '))
        . itControlKpiCell('Krytyczne', count(array_filter($critical, fn($f) => $f['severity'] === 'critical')), $critical ? '#991b1b' : null)
        . itControlKpiCell('FAIL', array_sum(array_column($runs, 'fail_count')))
        . itControlKpiCell('WARNING', array_sum(array_column($runs, 'warning_count')))
        . '</tr></table></td></tr>';
    if ($critical) {
        $html .= '<tr><td style="padding:12px 24px 0"><div style="font:700 14px Arial,sans-serif;color:#991b1b;margin-bottom:6px">Wykryte błędy krytyczne</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:4px solid #991b1b;background:#fef2f2">';
        foreach (array_slice($critical, 0, 10) as $f) {
            $html .= '<tr><td style="' . $td . '"><strong>' . itcEsc($f['title']) . '</strong>'
                . ($f['detail'] !== '' ? '<br><span style="color:#475569">' . itcEsc(mb_substr($f['detail'], 0, 300)) . '</span>' : '')
                . '<br><span style="color:#94a3b8;font-size:11px">' . itcEsc($f['check_title']) . '</span></td></tr>';
        }
        $html .= '</table></td></tr>';
    }
    // zestawienie sprawdzeń per zestaw
    foreach ($runs as $run) {
        $html .= '<tr><td style="padding:16px 24px 0"><div style="font:700 14px Arial,sans-serif;color:#0f172a;margin-bottom:6px">'
            . itcEsc(ITC_SUITES[$run['suite']]['label'] ?? $run['suite']) . ' ' . itControlBadge($run['status']) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px">';
        foreach ($run['results'] as $r) {
            if ($kind === 'alert' && ($r['status'] === 'PASS' || $r['status'] === 'SKIPPED')) continue;
            $html .= '<tr><td style="' . $td . ';width:110px">' . itControlBadge($r['status']) . '</td>'
                . '<td style="' . $td . '"><strong>' . itcEsc($r['title']) . '</strong><br><span style="color:#475569">' . itcEsc($r['summary']) . '</span></td></tr>';
        }
        $html .= '</table></td></tr>';
    }
    if ($recs) {
        $html .= '<tr><td style="padding:16px 24px 0"><div style="font:700 14px Arial,sans-serif;color:#1e3a8a;margin-bottom:6px">Rekomendowane akcje</div>'
            . '<ol style="margin:0;padding-left:20px;font:13px/1.5 Arial,Helvetica,sans-serif;color:#0f172a">';
        foreach ($recs as $rec) $html .= '<li style="margin-bottom:4px">' . itcEsc($rec) . '</li>';
        $html .= '</ol></td></tr>';
    }
    $html .= '<tr><td align="center" style="padding:20px 24px">'
        . '<a href="' . itcEsc($url) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;font:700 14px Arial,sans-serif;padding:10px 20px;border-radius:8px">Otwórz pulpit Kontroli IT</a>'
        . (!empty($opts['attached']) ? '<div style="margin-top:10px;font:12px Arial,sans-serif;color:#64748b">Pełny raport w załączniku (HTML).</div>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:12px 24px;background:#f8fafc;font:11px/1.5 Arial,sans-serif;color:#94a3b8">'
        . 'Wiadomość automatyczna modułu Kontroli IT. Odbiorców, progi i harmonogram zmienisz w ustawieniach modułu (Admin → Kontrola IT).'
        . '</td></tr></table></td></tr></table></body></html>';

    // ── Tekst ─────────────────────────────────────────────────────────────────
    $t = [$heading . ' — ' . $org, str_repeat('=', 40), $intro, '', 'STATUS: ' . $status,
          'Zdarzeń: ' . $events . ' | krytycznych: ' . count(array_filter($critical, fn($f) => $f['severity'] === 'critical'))
          . ' | FAIL: ' . array_sum(array_column($runs, 'fail_count')) . ' | WARNING: ' . array_sum(array_column($runs, 'warning_count'))];
    if ($critical) {
        $t[] = ''; $t[] = 'WYKRYTE BŁĘDY KRYTYCZNE:';
        foreach (array_slice($critical, 0, 10) as $f) $t[] = ' - ' . $f['title'] . ($f['detail'] !== '' ? ' — ' . mb_substr($f['detail'], 0, 200) : '') . ' [' . $f['check_title'] . ']';
    }
    foreach ($runs as $run) {
        $t[] = ''; $t[] = (ITC_SUITES[$run['suite']]['label'] ?? $run['suite']) . ': ' . $run['status'];
        foreach ($run['results'] as $r) {
            if ($kind === 'alert' && ($r['status'] === 'PASS' || $r['status'] === 'SKIPPED')) continue;
            $t[] = sprintf('  [%s] %s — %s', $r['status'], $r['title'], $r['summary']);
        }
    }
    if ($recs) {
        $t[] = ''; $t[] = 'REKOMENDOWANE AKCJE:';
        foreach ($recs as $i => $rec) $t[] = ' ' . ($i + 1) . '. ' . $rec;
    }
    $t[] = ''; $t[] = 'Pulpit: ' . $url;
    if (!empty($opts['attached'])) $t[] = 'Pełny raport w załączniku (HTML).';
    $t[] = '--'; $t[] = 'Wiadomość automatyczna modułu Kontroli IT.';

    return ['subject' => $subject, 'html' => $html, 'text' => implode("\n", $t) . "\n"];
}

/** Zapisuje pełny raport HTML jako plik załącznika. @return array{path,name,mime,size}|null */
function itControlWriteAttachment(array $runs, array $opts): ?array {
    $dir = rtrim(UPLOAD_DIR, '/') . '/it_control';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return null;
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $name = 'raport_kontroli_IT_' . date('Ymd_His') . '.html';
    $html = itControlReportHtml($runs, $opts + ['dashboard_url' => itControlDashboardUrl()]);
    if (@file_put_contents($dir . '/' . $name, $html) === false) return null;
    return ['path' => 'it_control/' . $name, 'name' => $name, 'mime' => 'text/html', 'size' => strlen($html)];
}

/**
 * Wysyła wiadomość do wszystkich odbiorców przez mail_queue_add().
 * @return array{mail_ids:int[], recipients:string[]}
 */
function itControlDeliver(array $settings, array $msg, array $attachments, ?int $contextId): array {
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $ids = []; $to = [];
    foreach (itControlRecipients($settings) as $rcpt) {
        $ids[] = mail_queue_add($rcpt['email'], $rcpt['name'], $msg['subject'], $msg['html'], $msg['text'],
                                'it_control', $contextId, '', false, $attachments);
        $to[] = $rcpt['email'];
    }
    return ['mail_ids' => $ids, 'recipients' => $to];
}

/** Czy przyszła pora na okresowy raport (wg częstotliwości, dnia tygodnia, godziny i ostatniej wysyłki). */
function itControlReportDue(array $settings, int $now): bool {
    if (!(int)$settings['report_enabled']) return false;
    if ((int)date('G', $now) < (int)$settings['report_hour']) return false;
    $weekly = $settings['report_frequency'] === 'weekly';
    if ($weekly && (int)date('N', $now) !== max(1, min(7, (int)$settings['report_weekday']))) return false;
    try {
        $last = db_one("SELECT created_at FROM itc_mail_log WHERE kind='report' AND status='queued' ORDER BY id DESC LIMIT 1");
    } catch (\Throwable $e) { $last = null; }
    if (!$last) return true;
    $lastTs = strtotime($last['created_at']);
    return $weekly ? ($now - $lastTs) > 6 * 86400 : date('Y-m-d', $lastTs) !== date('Y-m-d', $now);
}

/**
 * Raport okresowy z podanych przebiegów. $dry = nic nie zapisuje i nie wysyła.
 * @return array{status:string,reason:string,subject?:string,mail_ids?:array,recipients?:array,message?:array}
 */
function itControlSendReport(array $settings, array $runs, bool $dry = false, bool $force = false): array {
    $now = time();
    if (!$force && !itControlReportDue($settings, $now)) return ['status' => 'skipped', 'reason' => 'Raport nie jest jeszcze należny albo wysyłka wyłączona.'];
    $weekly = $settings['report_frequency'] === 'weekly';
    $since = date('Y-m-d H:i:s', $now - ($weekly ? 7 : 1) * 86400);
    $opts = ['title' => $weekly ? 'Tygodniowy raport kontroli IT' : 'Dzienny raport kontroli IT',
             'period_label' => date('d.m.Y', strtotime($since)) . ' – ' . date('d.m.Y', $now), 'period_since' => $since];
    $recipients = itControlRecipients($settings);
    if ($dry) {
        $msg = itControlEmail('report', $runs, $opts + ['attached' => (bool)$settings['report_attach']]);
        return ['status' => 'dry', 'reason' => 'Tryb próbny — bez wysyłki.', 'subject' => $msg['subject'],
                'recipients' => array_column($recipients, 'email'), 'message' => $msg];
    }
    if (!$recipients) {
        itControlMailLog(['kind' => 'report', 'run_ids' => implode(',', array_filter(array_column($runs, 'id'))), 'status' => 'suppressed', 'reason' => 'Brak odbiorców.']);
        return ['status' => 'suppressed', 'reason' => 'Brak odbiorców.'];
    }
    $att = (int)$settings['report_attach'] ? itControlWriteAttachment($runs, $opts) : null;
    $msg = itControlEmail('report', $runs, $opts + ['attached' => (bool)$att]);
    $d = itControlDeliver($settings, $msg, $att ? [$att] : [], (int)($runs[0]['id'] ?? 0) ?: null);
    itControlMailLog(['kind' => 'report', 'run_ids' => implode(',', array_filter(array_column($runs, 'id'))),
                      'recipients' => implode(', ', $d['recipients']), 'subject' => $msg['subject'],
                      'mail_ids' => implode(',', $d['mail_ids']), 'status' => 'queued']);
    return ['status' => 'queued', 'reason' => 'Wysłano do ' . count($d['recipients']) . ' odbiorców.', 'subject' => $msg['subject']] + $d;
}

/**
 * Alert natychmiastowy po przebiegu z krytycznymi znaleziskami — z bezpiecznikiem.
 * @return array{status:string,reason:string,fingerprint?:string}
 */
function itControlProcessAlert(array $settings, array $run, bool $dry = false): array {
    $crit = itControlRunFindings($run, ['critical']);
    if (!$crit) return ['status' => 'none', 'reason' => 'Brak znalezisk krytycznych.'];
    if (!(int)$settings['alert_enabled']) return ['status' => 'disabled', 'reason' => 'Alerty wyłączone w ustawieniach.'];
    $keys = array_unique(array_map(fn($f) => $f['check_id'] . ':' . $f['key'], $crit));
    sort($keys);
    $fp = sha1(implode('|', $keys));
    $now = time();
    $reason = '';
    try {
        $sameFp = db_one("SELECT created_at FROM itc_mail_log WHERE kind='alert' AND status='queued' AND fingerprint=? ORDER BY id DESC LIMIT 1", [$fp]);
        $anyLast = db_one("SELECT created_at FROM itc_mail_log WHERE kind='alert' AND status='queued' ORDER BY id DESC LIMIT 1");
        $today = (int)(db_one("SELECT COUNT(*) AS n FROM itc_mail_log WHERE kind='alert' AND status='queued' AND created_at >= ?",
                              [date('Y-m-d H:i:s', $now - 86400)])['n'] ?? 0);
    } catch (\Throwable $e) { $sameFp = $anyLast = null; $today = 0; }
    if ($sameFp && $now - strtotime($sameFp['created_at']) < (int)$settings['alert_cooldown_min'] * 60) {
        $reason = 'Ten sam zestaw anomalii zgłoszony ' . $sameFp['created_at'] . ' (okno ' . (int)$settings['alert_cooldown_min'] . ' min).';
    } elseif ($anyLast && $now - strtotime($anyLast['created_at']) < (int)$settings['alert_min_gap_min'] * 60) {
        $reason = 'Minimalny odstęp między alertami: ' . (int)$settings['alert_min_gap_min'] . ' min.';
    } elseif ($today >= max(1, (int)$settings['alert_max_per_day'])) {
        $reason = 'Wyczerpany dobowy limit alertów (' . (int)$settings['alert_max_per_day'] . ').';
    }
    $msg = itControlEmail('alert', [$run], ['critical' => $crit]);
    if ($dry) return ['status' => 'dry', 'reason' => $reason ?: 'Tryb próbny — alert zostałby wysłany.', 'fingerprint' => $fp, 'subject' => $msg['subject'], 'message' => $msg];
    if ($reason !== '') {
        itControlMailLog(['kind' => 'alert', 'run_ids' => (string)$run['id'], 'fingerprint' => $fp, 'subject' => $msg['subject'], 'status' => 'suppressed', 'reason' => $reason]);
        return ['status' => 'suppressed', 'reason' => $reason, 'fingerprint' => $fp];
    }
    if (!itControlRecipients($settings)) {
        itControlMailLog(['kind' => 'alert', 'run_ids' => (string)$run['id'], 'fingerprint' => $fp, 'subject' => $msg['subject'], 'status' => 'suppressed', 'reason' => 'Brak odbiorców.']);
        return ['status' => 'suppressed', 'reason' => 'Brak odbiorców.', 'fingerprint' => $fp];
    }
    $d = itControlDeliver($settings, $msg, [], $run['id'] ? (int)$run['id'] : null);
    itControlMailLog(['kind' => 'alert', 'run_ids' => (string)$run['id'], 'fingerprint' => $fp, 'recipients' => implode(', ', $d['recipients']),
                      'subject' => $msg['subject'], 'mail_ids' => implode(',', $d['mail_ids']), 'status' => 'queued']);
    return ['status' => 'queued', 'reason' => 'Alert wysłany do ' . count($d['recipients']) . ' odbiorców.', 'fingerprint' => $fp];
}
