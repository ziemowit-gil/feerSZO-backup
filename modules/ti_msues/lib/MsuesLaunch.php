<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesLaunch.php — „Uruchom certyfikację MSUES” (kierownik): start przygotowań do audytu.
 *
 * Uruchomienie: zapisuje kto i kiedy (msues_launched_at/_by/_name, termin msues_deadline), opcjonalnie zakłada każdej osobie
 * z kadry aktywnych usług roboczą wersję oświadczenia (ogólnego, wypełnioną danymi z SZO) i kolejkuje e-mail informacyjny:
 * co to jest MSUES, po co Fundacji, co prowadzący ma zrobić i do kiedy, oraz krótki SMS z linkiem (rodzaj ti_msues_notice).
 * Każda osoba dostaje osobną wiadomość. E-maile wysyłane od razu (mail_queue_add z $immediate, kontekst 'msues_launch') —
 * nieudane zostają w mail_queue do ponowienia przez CRON i są zgłaszane jako błąd; SMS — od razu. Przypomnienie trafia tylko do osób bez zatwierdzonego oświadczenia; notify() — do jednej wybranej osoby.
 */
require_once __DIR__ . '/MsuesComplianceService.php';

final class MsuesLaunch
{
    public static function launched(): ?array
    {
        $at = msuesSetting('msues_launched_at');
        return $at === '' ? null : ['at' => $at, 'by' => (int)msuesSetting('msues_launched_by'), 'name' => msuesSetting('msues_launched_name'), 'deadline' => msuesSetting('msues_deadline')];
    }

    /** Odbiorcy: kadra aktywnych usług z e-mailem lub telefonem; $onlyMissing — bez osób z zatwierdzonym oświadczeniem. */
    public static function recipients(DeclarationRepository $repo, bool $onlyMissing): array
    {
        $out = [];
        foreach (MsuesSzoData::trainers() as $t) {
            if (self::email($t) === '' && self::phone($t) === '') continue;
            if ($onlyMissing && db_one("SELECT 1 FROM trainer_declarations WHERE trainer_id = ? AND status = 'approved'", [(int)$t['id']])) continue;
            $out[] = $t;
        }
        return $out;
    }

    private static function email(array $t): string
    {
        $e = trim((string)($t['email'] ?? ''));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    private static function phone(array $t): string
    {
        return preg_replace('/\D/', '', (string)($t['phone_number'] ?? '')) !== '' ? trim((string)$t['phone_number']) : '';
    }

    /**
     * @return array{mails:int, sms:int, drafts:int, skipped:int}
     */
    public static function launch(array $actor, string $deadline, bool $sendMail, bool $sendSms, bool $createDrafts, bool $enforce, MsuesComplianceService $svc): array
    {
        $deadline = preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? $deadline : '';
        $first = self::launched() === null;
        if ($first) {
            org_setting_set('msues_launched_at', msuesNow());
            org_setting_set('msues_launched_by', (string)(int)$actor['id']);
            org_setting_set('msues_launched_name', (string)$actor['name']);
        }
        org_setting_set('msues_deadline', $deadline);
        if ($enforce) MsuesComplianceService::setDeclarationRequired(true, $actor);
        $repo = $svc->declarations();
        $drafts = 0;
        if ($createDrafts) {
            foreach (MsuesSzoData::trainers() as $t) {
                $tid = (int)$t['id'];
                if ($repo->forTrainer($tid, false)) continue;   // ma już jakieś oświadczenie w obiegu
                $repo->create($tid, null, '', DeclarationForm::prefill($tid, null, $svc->documents()->list($tid)), $actor);
                $drafts++;
            }
        }
        $r = ($sendMail || $sendSms) ? self::sendInfo($actor, $repo, !$first, $sendMail, $sendSms) : ['mails' => 0, 'sms' => 0, 'skipped' => 0, 'errors' => []];
        if (function_exists('audit_log')) { try { audit_log('msues.launch', ['first' => $first, 'mails' => $r['mails'], 'sms' => $r['sms'], 'drafts' => $drafts, 'deadline' => $deadline, 'enforce' => $enforce], $actor['id'] ?: null); } catch (\Throwable $e) {} }
        return ['mails' => $r['mails'], 'sms' => $r['sms'], 'drafts' => $drafts, 'skipped' => $r['skipped'], 'errors' => $r['errors']];
    }

    /**
     * Informacja do kadry — każda osoba osobno (osobny e-mail, osobny SMS).
     * @return array{mails:int, sms:int, skipped:int, errors:list<string>}
     */
    public static function sendInfo(array $actor, DeclarationRepository $repo, bool $onlyMissing, bool $mail = true, bool $sms = false): array
    {
        $o = ['mails' => 0, 'sms' => 0, 'skipped' => 0, 'errors' => []];
        foreach (self::recipients($repo, $onlyMissing) as $t) {
            $r = self::notify($t, $actor, $mail, $sms);
            $o['mails'] += $r['mail'] ? 1 : 0;
            $o['sms'] += $r['sms'] ? 1 : 0;
            if (!$r['mail'] && !$r['sms']) $o['skipped']++;
            array_push($o['errors'], ...$r['errors']);
        }
        return $o;
    }

    /**
     * Powiadomienie jednej osoby: e-mail (od razu, kontekst msues_launch) i/lub SMS (rodzaj ti_msues_notice — od razu).
     * 'mail' = true tylko, gdy wiadomość faktycznie wyszła (status 'sent'), nie gdy trafiła do kolejki.
     * @param array $t wiersz users (id, name, email, phone_number)
     * @return array{mail:bool, sms:bool, errors:list<string>}
     */
    public static function notify(array $t, array $actor, bool $mail, bool $sms): array
    {
        $o = ['mail' => false, 'sms' => false, 'errors' => []];
        $name = (string)$t['name'];
        if ($mail && ($email = self::email($t)) !== '') {
            if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            [$subject, $html] = self::mail($name, (string)$actor['name']);
            try {
                // bez terminu + $immediate — wysyłka od razu; termin w mail_queue_add zawsze oznacza czekanie na CRON
                $id = mail_queue_add($email, $name, $subject, $html, '', 'msues_launch', (int)$t['id'], '', true);
                $row = $id > 0 ? db_one("SELECT status, last_error FROM mail_queue WHERE id = ?", [$id]) : null;
                $o['mail'] = ($row['status'] ?? '') === 'sent';
                if ($id > 0 && !$o['mail']) $o['errors'][] = $name . ' (e-mail): nie wysłano od razu' . (($row['last_error'] ?? '') !== '' ? ' — ' . $row['last_error'] : '') . '; ponowi kolejka';
            }
            catch (\Throwable $e) { $o['errors'][] = $name . ' (e-mail): ' . $e->getMessage(); }
        } elseif ($mail) {
            $o['errors'][] = $name . ': brak adresu e-mail';
        }
        if ($sms && ($phone = self::phone($t)) !== '') {
            if (!function_exists('sms_send')) require_once dirname(__DIR__, 3) . '/includes/sms.php';
            try { $o['sms'] = sms_send($phone, self::smsText(), 'ti_msues_notice') !== ''; }
            catch (\Throwable $e) { $o['errors'][] = $name . ' (SMS): ' . $e->getMessage(); }
        } elseif ($sms) {
            $o['errors'][] = $name . ': brak numeru telefonu';
        }
        if (function_exists('audit_log')) { try { audit_log('msues.notify', ['trainer_id' => (int)$t['id'], 'mail' => $o['mail'], 'sms' => $o['sms']], $actor['id'] ?: null); } catch (\Throwable $e) {} }
        return $o;
    }

    /** Treść SMS bez polskich znaków (160 znaków na część zamiast 70), z linkiem do panelu. */
    public static function smsText(): string
    {
        require_once dirname(__DIR__, 2) . '/ti_panel_urls/lib/tiPanelUrl.php';
        $d = msuesSetting('msues_deadline');
        return 'FEER: przygotowujemy certyfikat jakosci MSUES (WUP Krakow). Prosimy o oswiadczenie o kwalifikacjach, CV i dyplomy w panelu dydaktyka'
            . ($d !== '' ? ' do ' . DeclarationForm::dateLong($d) : '') . ': ' . tiPanelUrl('dydaktyk', 'msues.php?_route=/moje') . ' Szczegoly w e-mailu.';
    }

    /** Treść e-maila informacyjnego: [temat, HTML]. */
    public static function mail(string $name, string $from): array
    {
        require_once dirname(__DIR__, 2) . '/ti_panel_urls/lib/tiPanelUrl.php';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $org = MsuesSzoData::orgName();
        $deadline = msuesSetting('msues_deadline');
        $link = tiPanelUrl('dydaktyk', 'msues.php?_route=/moje');
        $subject = 'Certyfikacja jakości MSUES — prośba o oświadczenie o kwalifikacjach';
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1c1c1c;max-width:640px">'
            . '<div style="background:#10335c;color:#fff;padding:14px 18px;font-size:17px;font-weight:600;border-bottom:3px solid #c8a11a">Certyfikacja jakości MSUES</div>'
            . '<div style="padding:16px 18px;border:1px solid #d0d7e2;border-top:0">'
            . '<p>Dzień dobry' . ($name !== '' ? ' ' . $e($name) : '') . ',</p>'
            . '<p>' . $e($org) . ' rozpoczyna przygotowania do uzyskania <b>znaku jakości MSUES</b>. Prosimy o kilka minut na uzupełnienie dokumentów w panelu dydaktyka.</p>'
            . '<h3 style="font-size:15px;margin:16px 0 4px;color:#10335c">Co to jest MSUES?</h3>'
            . '<p style="margin-top:0">MSUES to <b>Małopolskie Standardy Usług Edukacyjno-Szkoleniowych</b>. Znak jakości MSUES przyznaje Województwo Małopolskie '
            . 'reprezentowane przez Wojewódzki Urząd Pracy w Krakowie (Centrum Zapewniania Jakości Kształcenia) po audycie, który sprawdza m.in. kwalifikacje '
            . 'i doświadczenie osób prowadzących szkolenia i konsultacje.</p>'
            . '<h3 style="font-size:15px;margin:16px 0 4px;color:#10335c">Po co to Fundacji i uczestnikom?</h3>'
            . '<ul style="margin-top:0;padding-left:20px">'
            . '<li>znak potwierdza, że nasze szkolenia spełniają uznany standard jakości — to sygnał dla uczestników, rodziców i partnerów;</li>'
            . '<li>ułatwia ubieganie się o wpis do <b>Bazy Usług Rozwojowych (BUR)</b> i udział w projektach finansowanych ze środków publicznych;</li>'
            . '<li>porządkuje dokumentację kadry — raz złożone dokumenty służą przy kolejnych usługach i kontrolach.</li></ul>'
            . '<h3 style="font-size:15px;margin:16px 0 4px;color:#10335c">Co trzeba zrobić?</h3>'
            . '<ol style="margin-top:0;padding-left:20px">'
            . '<li>Wejdź w panelu dydaktyka w <b>Mój panel › Certyfikacja MSUES</b> (link poniżej).</li>'
            . '<li>Sprawdź i uzupełnij <b>oświadczenie o kwalifikacjach i kompetencjach</b> — część danych wpisaliśmy z systemu (umowa, prowadzone grupy).</li>'
            . '<li>Wgraj <b>CV</b> oraz <b>dyplomy / certyfikaty</b> potwierdzające kwalifikacje.</li>'
            . '<li>Pobierz oświadczenie (PDF), <b>podpisz je</b> i wgraj skan; potem wyślij do weryfikacji. Kierownik zatwierdzi je pod audyt.</li></ol>'
            . ($deadline !== '' ? '<p><b>Termin: ' . $e(DeclarationForm::dateLong($deadline)) . '.</b></p>' : '')
            . '<p style="margin:18px 0"><a href="' . $e($link) . '" style="background:#10335c;color:#fff;text-decoration:none;padding:10px 18px;display:inline-block;font-weight:600">Przejdź do Certyfikacji MSUES</a></p>'
            . '<p style="font-size:12px;color:#5b6677">Oświadczenie nie zastępuje dokumentów potwierdzających formalne uprawnienia, jeżeli przepisy ich wymagają. '
            . 'Dokumenty udostępniamy wyłącznie na potrzeby audytu, z zachowaniem zasad ochrony danych osobowych.</p>'
            . '<p>Pozdrawiamy,<br>' . $e($from !== '' ? $from : 'Kierownictwo') . '<br>' . $e($org) . '</p>'
            . '</div></div>';
        return [$subject, $html];
    }
}
