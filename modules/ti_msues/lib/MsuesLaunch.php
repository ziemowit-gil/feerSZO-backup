<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesLaunch.php — „Uruchom certyfikację MSUES” (kierownik): start przygotowań do audytu.
 *
 * Uruchomienie: zapisuje kto i kiedy (msues_launched_at/_by/_name, termin msues_deadline), opcjonalnie zakłada każdej osobie
 * z kadry aktywnych usług roboczą wersję oświadczenia (ogólnego, wypełnioną danymi z SZO) i kolejkuje e-mail informacyjny:
 * co to jest MSUES, po co Fundacji, co prowadzący ma zrobić i do kiedy. E-maile idą przez mail_queue z terminem (nie od razu —
 * wysyła je CRON kolejki), kontekst 'msues_launch'. Ponowne „Wyślij informację” trafia tylko do osób bez zatwierdzonego oświadczenia.
 */
require_once __DIR__ . '/MsuesComplianceService.php';

final class MsuesLaunch
{
    public static function launched(): ?array
    {
        $at = msuesSetting('msues_launched_at');
        return $at === '' ? null : ['at' => $at, 'by' => (int)msuesSetting('msues_launched_by'), 'name' => msuesSetting('msues_launched_name'), 'deadline' => msuesSetting('msues_deadline')];
    }

    /** Odbiorcy: kadra aktywnych usług z adresem e-mail; $onlyMissing — bez osób z zatwierdzonym oświadczeniem. */
    public static function recipients(DeclarationRepository $repo, bool $onlyMissing): array
    {
        $out = [];
        foreach (MsuesSzoData::trainers() as $t) {
            $email = trim((string)$t['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if ($onlyMissing && db_one("SELECT 1 FROM trainer_declarations WHERE trainer_id = ? AND status = 'approved'", [(int)$t['id']])) continue;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @return array{mails:int, drafts:int, skipped:int}
     */
    public static function launch(array $actor, string $deadline, bool $sendMail, bool $createDrafts, bool $enforce, MsuesComplianceService $svc): array
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
        $mails = 0; $skipped = 0;
        if ($sendMail) [$mails, $skipped] = self::sendInfo($actor, $repo, !$first);
        if (function_exists('audit_log')) { try { audit_log('msues.launch', ['first' => $first, 'mails' => $mails, 'drafts' => $drafts, 'deadline' => $deadline, 'enforce' => $enforce], $actor['id'] ?: null); } catch (\Throwable $e) {} }
        return ['mails' => $mails, 'drafts' => $drafts, 'skipped' => $skipped];
    }

    /** Kolejkuje e-mail informacyjny. @return array{0:int,1:int} [zakolejkowane, pominięte] */
    public static function sendInfo(array $actor, DeclarationRepository $repo, bool $onlyMissing): array
    {
        if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $n = 0; $skip = 0;
        foreach (self::recipients($repo, $onlyMissing) as $t) {
            [$subject, $html] = self::mail((string)$t['name'], (string)$actor['name']);
            try {
                $id = mail_queue_add((string)$t['email'], (string)$t['name'], $subject, $html, '', 'msues_launch', (int)$t['id'], date('Y-m-d H:i:s'));
                $id ? $n++ : $skip++;
            } catch (\Throwable $e) {
                $skip++;
                error_log('msues mail ' . $t['email'] . ': ' . $e->getMessage());
            }
        }
        return [$n, $skip];
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
