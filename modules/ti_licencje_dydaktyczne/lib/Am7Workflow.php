<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/Am7Workflow.php — obieg AM7: udostępnienie licencji / dostępu do Office 365 kursantowi
 * (tenant Microsoft 365 ośrodka, nie szkolny).
 *
 *   1. Wniosek AM7/1 (panel, „standardowo” jak AM5): numer sprawy 4021-MS_AM/NNNNNN/AB, wszczęcie = termin 7 dni,
 *      e-mail + SMS do kursanta (kto wszczął, numer sprawy, termin) z linkiem do podpisu wniosku w portalu.
 *   2. Podpis wniosku przez kursanta (portal po tokenie): adnotacja elektroniczna (data, IP) w PDF, alert do biura.
 *   3. Projekt decyzji (biuro): AM7/2 przyznanie albo AM7/3 odmowa — PDF w układzie CIT (podgląd przed zatwierdzeniem).
 *   4. Zatwierdzenie przez kierownika (rola „kierownik” panelu TI albo administrator) → decyzja wysyłana e-mailem w PDF
 *      (fallback: SMS + alert do biura) → przy przyznaniu zlecenie dla Helpdesku IT (konto / licencja w tenancie ośrodka)
 *      i przydział z zaproszeniem do oświadczeń AM7/4 i AM7/5 (7 dni).
 * Wniosek podpisany na papierze: przy projekcie decyzji zaznacza się „wniosek podpisany odręcznie” (fallback portalu).
 */
require_once __DIR__ . '/LicenseManagementService.php';

final class Am7Workflow
{
    public function __construct(private readonly LicenseManagementService $svc) {}

    /** Kierownik zatwierdzający decyzje AM7: administrator panelu albo rola „kierownik” w panelu TI. */
    public static function canApprove(int $userId): bool
    {
        if (function_exists('dyd_is_admin') && dyd_is_admin()) return true;
        return function_exists('ti_panel_role') && ti_panel_role($userId) === 'kierownik';
    }

    // ── 1. Wniosek ──────────────────────────────────────────────────────────────

    /** @return array{id:int, errors:array} */
    public function save(?int $id, Am7RequestDto $dto, array $actor): array
    {
        $errors = $dto->errors(false);
        if ($errors) return ['id' => (int)$id, 'errors' => $errors];
        $course = $dto->courseName !== '' ? $dto->courseName : ($dto->courseId ? LicenseManagementService::courseLabel($dto->courseId) : '');
        $name = $dto->studentName; $email = $dto->studentEmail; $phone = $dto->studentPhone;
        if ($dto->studentKind === 'client' && ($hd = LicenseManagementService::holderData('client', $dto->studentId))) {
            $name = $hd['name']; $email = $email !== '' ? $email : $hd['email']; $phone = $phone !== '' ? $phone : $hd['phone'];
        }
        $cols = ['form_kind' => LicenseForm::Am7->value, 'title' => 'Office 365' . ($dto->plan !== '' ? ' — ' . $dto->plan : ''),
            'work_description' => 'Dostęp do usług Microsoft 365 w tenancie ośrodka na potrzeby kursu.', 'course_id' => $dto->courseId, 'course_name' => $course,
            'licensor_name' => $dto->to !== '' ? $dto->to : ldOrgName(), 'applicant_name' => $name, 'applicant_contact' => $dto->account,
            'interested_kind' => $dto->studentKind, 'interested_id' => $dto->studentId, 'interested_name' => $name, 'interested_email' => $email, 'interested_phone' => $phone,
            'request_data' => json_encode($dto->requestData(), JSON_UNESCAPED_UNICODE), 'period_from' => $dto->periodFrom, 'period_to' => $dto->periodTo,
            'territory' => '', 'seats' => 1];
        if ($id) {
            $l = $this->svc->licenses->get($id);
            if (!$l) throw new DomainException('Nie ma takiej sprawy.');
            if (!$l['requestStatus']->editable()) throw new DomainException('Wniosek po wszczęciu nie podlega edycji.');
            $this->svc->licenses->update($id, $cols);
            $this->svc->licenses->refreshCaseLetters($id);
            $this->svc->licenses->event($id, 'request.edit', 'Zmieniono wniosek roboczy AM7.', $actor);
        } else {
            $id = $this->svc->licenses->create($cols, $actor);
            $this->svc->licenses->event($id, 'request.create', 'Założono sprawę ' . ($this->svc->licenses->get($id)['case_number'] ?? '') . ' — wniosek ' . LicenseDoc::Am7Request->symbol() . ' (Office 365).', $actor);
        }
        return ['id' => $id, 'errors' => []];
    }

    public static function formValues(array $l): array
    {
        $r = $l['request'] ?? [];
        return ['place' => $r['place'] ?? '', 'date' => $r['date'] ?? '', 'to' => $l['licensor_name'], 'course_id' => $l['course_id'], 'course_name' => $l['course_name'],
            'interested_kind' => $l['interested_kind'] ?: 'client', 'interested_id' => $l['interested_id'], 'interested_id_client' => $l['interested_id'],
            'interested_name' => $l['interested_name'], 'interested_email' => $l['interested_email'], 'interested_phone' => $l['interested_phone'],
            'account' => $r['account'] ?? '', 'plan' => $r['plan'] ?? '', 'access' => $r['access'] ?? 'tenant', 'access_other' => $r['access_other'] ?? '',
            'period_from' => $l['period_from'], 'period_to' => $l['period_to'], 'justification' => $r['justification'] ?? ''];
    }

    /** Wszczęcie: termin 7 dni, PDF, e-mail + SMS do kursanta z linkiem do podpisu wniosku. */
    public function start(array $l, array $actor): array
    {
        $id = (int)$l['id'];
        $errors = Am7RequestDto::fromArray(self::formValues($l))->errors(true);
        if ($errors) return ['errors' => $errors, 'report' => []];
        $now = ldNow();
        $this->svc->licenses->update($id, ['request_status' => LicenseRequestStatus::Started->value, 'started_at' => $now, 'started_by' => $actor['id'] ?: null,
                                           'started_name' => (string)$actor['name'], 'due_on' => ldDue($now)]);
        $this->svc->licenses->event($id, 'request.start', 'Wszczęto proces AM7 — realizacja do ' . LD_REALIZATION_DAYS . ' dni (do ' . ldDate(ldDue($now)) . ').', $actor);
        $this->svc->renderRequest($id);
        $r = $this->sendSignLink($id, $actor, LicenseNotificationType::RequestStarted);
        return ['errors' => [], 'report' => ['Kursant: ' . $r]];
    }

    /** Link do podpisu wniosku (nowy token). */
    public function sendSignLink(int $id, array $actor, LicenseNotificationType $type = LicenseNotificationType::Reminder): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['requestStatus'] !== LicenseRequestStatus::Started) throw new DomainException('Wniosek nie czeka na podpis kursanta.');
        if (!empty($l['requester_signed_at'])) throw new DomainException('Kursant już podpisał wniosek.');
        $token = $this->svc->licenses->issueRequesterToken($id);
        $link = LicenseManagementService::publicUrl('/wniosek/' . $token);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $nr = (string)$l['case_number'];
        $due = ldDate((string)$l['due_on']);
        $r = $l['request'];
        $subject = ($type === LicenseNotificationType::Reminder ? 'Przypomnienie: ' : '') . 'Wszczęto proces udostępnienia Office 365 (AM7) — sprawa ' . $nr;
        $body = '<p>Dzień dobry ' . $e($l['interested_name']) . ',</p>'
            . '<p><b>' . $e($l['started_name'] ?: 'pracownik ' . ldOrgName()) . '</b> wszczął(-ęła) w dniu ' . $e(ldDate(substr((string)$l['started_at'], 0, 10)))
            . ' proces udostępnienia Ci licencji / dostępu do <b>Office 365</b> w tenancie ośrodka ' . $e(ldOrgName()) . '.</p>'
            . '<table style="border-collapse:collapse;margin:8px 0" cellpadding="6">'
            . '<tr><td style="background:#f2f2f2;width:180px">Numer sprawy</td><td><b>' . $e($nr) . '</b></td></tr>'
            . '<tr><td style="background:#f2f2f2">Dokument</td><td>' . LicenseDoc::Am7Request->symbol() . ' — ' . $e(LicenseDoc::Am7Request->title()) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Kurs</td><td>' . $e($l['course_name']) . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Plan Microsoft 365</td><td>' . $e($r['plan'] ?? '') . '</td></tr>'
            . '<tr><td style="background:#f2f2f2">Termin realizacji</td><td><b>do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e($due) . '</b></td></tr></table>'
            . '<p>Sprawdź dane we wniosku i <b>podpisz go elektronicznie</b> (przycisk poniżej). Po podpisaniu placówka wyda decyzję — otrzymasz ją e-mailem w PDF.</p>';
        $sms = 'FEER: ' . ($l['started_name'] ?: 'Fundacja') . ' wszczal(a) proces Office 365 (AM7, sprawa ' . $nr . '). Podpisz wniosek do ' . $due . ': ' . $link;
        $res = $this->svc->notify->send($type, $this->svc->interestedContact($l), $subject,
            LicenseNotificationService::layout('Office 365 — wniosek do podpisu', $body, $link, 'Sprawdź i podpisz wniosek', 'AM7'), $sms, ['type' => 'license', 'id' => $id], $actor);
        $this->svc->licenses->event($id, 'request.sign_link', 'Link do podpisu wniosku: ' . $this->svc->describe($res), $actor);
        return $this->svc->describe($res);
    }

    // ── 2. Podpis kursanta ──────────────────────────────────────────────────────

    /** @return array errors */
    public function sign(array $l, Am7SignDto $dto): array
    {
        $errors = $dto->errors();
        if ($errors) return $errors;
        if ($l['requestStatus'] !== LicenseRequestStatus::Started || !empty($l['requester_signed_at'])) throw new DomainException('Wniosek jest już podpisany albo zamknięty.');
        $id = (int)$l['id'];
        $req = $l['request'];
        $req['place'] = $dto->place;
        if ($dto->justification !== '') $req['justification'] = $dto->justification;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $this->svc->licenses->update($id, ['request_data' => json_encode($req, JSON_UNESCAPED_UNICODE), 'requester_signed_at' => ldNow(), 'requester_signed_ip' => $ip,
                                           'requester_signed_name' => $dto->name, 'requester_token_hash' => '']);
        $actor = ['id' => 0, 'name' => $dto->name . ' (kursant, portal)'];
        $this->svc->licenses->event($id, 'request.signed', 'Kursant podpisał wniosek AM7/1 w portalu (IP ' . $ip . ').', $actor);
        try { $this->svc->renderRequest($id); } catch (\Throwable $e) { error_log('am7 sign pdf: ' . $e->getMessage()); }
        $this->svc->notify->alertOffice('Wniosek AM7 podpisany — ' . $l['case_number'],
            '<p><b>' . htmlspecialchars($dto->name) . '</b> podpisał(a) wniosek o dostęp do Office 365 (sprawa <b>' . htmlspecialchars((string)$l['case_number']) . '</b>). '
            . 'Prosimy przygotować decyzję AM7/2 lub AM7/3 w panelu dydaktyka › Licencje — termin do ' . htmlspecialchars(ldDate($l['due_on'])) . '.</p>',
            ['type' => 'license', 'id' => $id], $actor, LicenseNotificationType::RequestSigned);
        return [];
    }

    // ── 3. Projekt decyzji ──────────────────────────────────────────────────────

    /** Projekt decyzji (bez wysyłki). @return array errors */
    public function prepare(int $id, Am7DecisionDto $dto, array $actor): array
    {
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) throw new DomainException('Nie ma takiej sprawy AM7.');
        if ($l['requestStatus'] !== LicenseRequestStatus::Started) throw new DomainException('Decyzja w tej sprawie została już wydana albo wniosek nie został wszczęty.');
        $errors = $dto->errors();
        if (empty($l['requester_signed_at']) && !$dto->paperSigned) $errors['paper_signed'] = 'Kursant nie podpisał jeszcze wniosku w portalu — zaznacz, jeśli podpisał go odręcznie.';
        if ($errors) return $errors;
        $data = $dto->data() + ['prepared_by' => (int)$actor['id'], 'prepared_name' => (string)$actor['name'], 'prepared_at' => ldNow(), 'approved_at' => '', 'paper_signed' => $dto->paperSigned];
        $this->svc->licenses->update($id, ['decision_data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $this->svc->licenses->event($id, 'decision.prepare', 'Przygotowano projekt decyzji ' . ($dto->grant ? 'AM7/2 (przyznanie)' : 'AM7/3 (odmowa)') . ' — czeka na zatwierdzenie kierownika.', $actor);
        $this->svc->notify->alertOffice('Projekt decyzji AM7 do zatwierdzenia — ' . $l['case_number'],
            '<p>' . htmlspecialchars((string)$actor['name']) . ' przygotował(a) projekt decyzji ' . ($dto->grant ? 'o przyznaniu' : 'o odmowie') . ' dostępu do Office 365 dla <b>'
            . htmlspecialchars((string)$l['interested_name']) . '</b>. Decyzja zostanie wysłana kursantowi po zatwierdzeniu przez kierownika.</p>',
            ['type' => 'license', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
        return [];
    }

    /** Wydruk decyzji w układzie CIT: ['out' => render(), 'doc', 'number']; projekt ma czerwony nagłówek „PROJEKT”. */
    public function decisionOutput(array $l): array
    {
        if (empty($l['decision'])) throw new DomainException('Brak decyzji do wydruku.');
        $doc = !empty($l['decision']['grant']) ? LicenseDoc::Am7Grant : LicenseDoc::Am7Refusal;
        $nr = LicenseDocument::number($doc, $l);
        $html = (new LicenseDocument())->am7DecisionHtml($l);
        if (empty($l['decision']['approved_at'])) $html = '<div style="font-family:dejavusans;font-size:9pt;color:#a20025;border:0.4mm solid #a20025;padding:1.5mm;margin-bottom:2mm;text-align:center"><b>PROJEKT — decyzja czeka na zatwierdzenie kierownika</b></div>' . $html;
        return ['out' => LicenseDocument::render($html, $doc->title() . ' — ' . $l['interested_name'], $nr, 'AM7'), 'doc' => $doc, 'number' => $nr];
    }

    /** Zapis wydanej (zatwierdzonej) decyzji. Zwraca ['path','ext','doc','number']. */
    public function storeDecision(int $id): array
    {
        $l = $this->svc->licenses->get($id);
        $o = $this->decisionOutput($l);
        [$rel, $sha] = LicenseStorage::put($o['out']['bytes'], $o['out']['ext'], 'am7d' . $id);
        $this->svc->licenses->update($id, ['decision_pdf' => $rel, 'decision_pdf_sha256' => $sha]);
        return ['path' => $rel, 'ext' => $o['out']['ext'], 'doc' => $o['doc'], 'number' => $o['number']];
    }

    // ── 4. Zatwierdzenie przez kierownika i wysyłka ─────────────────────────────

    /** @return list<string> raport */
    public function approve(int $id, array $actor): array
    {
        if (!self::canApprove((int)$actor['id'])) throw new DomainException('Decyzję AM7 zatwierdza kierownik (rola „kierownik” panelu TI) albo administrator.');
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7 || $l['requestStatus'] !== LicenseRequestStatus::Started) throw new DomainException('Sprawa nie czeka na decyzję.');
        $d = $l['decision'];
        if (!$d || empty($d['prepared_at'])) throw new DomainException('Najpierw przygotuj projekt decyzji.');
        $grant = !empty($d['grant']);
        $d['approved_at'] = ldNow(); $d['approved_by'] = (int)$actor['id']; $d['approved_name'] = (string)$actor['name']; $d['date'] = ldToday();
        $cols = ['decision_data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'request_status' => ($grant ? LicenseRequestStatus::Granted : LicenseRequestStatus::Refused)->value,
                 'decided_at' => ldNow(), 'decided_via' => 'panel', 'decided_by' => $actor['id'] ?: null, 'requester_token_hash' => ''];
        if ($grant) $cols += ['period_from' => (string)$d['period_from'], 'period_to' => (string)$d['period_to'], 'license_kind' => 'niewylaczna', 'seats' => 1];
        $this->svc->licenses->update($id, $cols);
        $this->svc->licenses->event($id, 'decision.approve', 'Kierownik zatwierdził decyzję ' . ($grant ? 'AM7/2 o przyznaniu' : 'AM7/3 o odmowie') . '.', $actor);
        $report = [];
        try { $this->svc->renderRequest($id); } catch (\Throwable $e) { $report[] = 'PDF wniosku: ' . $e->getMessage(); }
        $pdf = $this->storeDecision($id);
        $l = $this->svc->licenses->get($id);

        $report[] = $this->sendDecision($id, $pdf, $actor);

        if ($grant) {
            $report[] = 'Helpdesk IT: ' . $this->helpdeskTicket($id, $actor);
            try {
                $res = $this->svc->assign($id, ['kind' => (string)$l['interested_kind'], 'id' => $l['interested_id'] ? (int)$l['interested_id'] : null] + $this->svc->interestedContact($l), $actor);
                $report[] = 'Oświadczenia AM7/4 i AM7/5: ' . $res['report'];
            } catch (\Throwable $ex) { $report[] = 'Przydział nieudany: ' . $ex->getMessage(); }
        }
        return $report;
    }

    /**
     * Wysyłka wydanej decyzji kursantowi: e-mail z PDF (fallback: SMS z informacją; brak PDF → alert do biura).
     * $pdf = wynik storeDecision(); null = istniejący decision_pdf (ponowna wysyłka). Zwraca linię raportu.
     */
    public function sendDecision(int $id, ?array $pdf, array $actor, bool $resend = false): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7 || empty($l['decision']['approved_at'])) throw new DomainException('Brak zatwierdzonej decyzji do wysłania.');
        $d = $l['decision'];
        $grant = !empty($d['grant']);
        if ($pdf === null) {
            $doc = $grant ? LicenseDoc::Am7Grant : LicenseDoc::Am7Refusal;
            $pdf = LicenseStorage::fullPath((string)$l['decision_pdf'])
                ? ['path' => (string)$l['decision_pdf'], 'ext' => str_ends_with((string)$l['decision_pdf'], '.pdf') ? 'pdf' : 'html', 'doc' => $doc, 'number' => LicenseDocument::number($doc, $l)]
                : $this->storeDecision($id);
        }
        $report = [];
        // Wysyłka decyzji e-mailem w PDF (fallback: SMS z informacją + alert do biura z prośbą o doręczenie)
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $subject = ($grant ? 'Decyzja o przyznaniu dostępu do Office 365' : 'Decyzja o odmowie dostępu do Office 365') . ' — sprawa ' . $l['case_number'];
        $body = '<p>Dzień dobry ' . $e($l['interested_name']) . ',</p><p>w załączniku przesyłamy decyzję <b>' . $e($pdf['number']) . '</b> — ' . $e($pdf['doc']->title()) . '.</p>'
            . ($grant ? '<p>Konto: <b>' . $e($d['account'] ?? '') . '</b>, plan: <b>' . $e($d['plan'] ?? '') . '</b>, okres dostępu: <b>' . $e(ldDate($d['period_from'] ?? '') . ' — ' . ldDate($d['period_to'] ?? '')) . '</b>.</p>'
                      . '<p>Helpdesk IT przygotuje konto w tenancie ośrodka. W osobnej wiadomości otrzymasz link do złożenia oświadczeń <b>AM7/4</b> i <b>AM7/5</b> (do ' . LD_REALIZATION_DAYS . ' dni).</p>'
                      : '<p>Uzasadnienie: ' . $e($d['refusal_reason'] ?? '') . '</p>');
        $sms = 'FEER: decyzja AM7 (sprawa ' . $l['case_number'] . ') - ' . ($grant ? 'PRZYZNANO dostep do Office 365.' : 'odmowa dostepu do Office 365.') . ' PDF wyslalismy e-mailem.';
        $att = LicenseNotificationService::attachment($pdf['path'], $pdf['number']);
        if (!$att) {
            // Fallback: PDF nie powstał (mPDF niedostępny → wersja HTML) — decyzja i tak wychodzi, biuro doręcza wydruk
            $report[] = 'UWAGA: PDF decyzji niedostępny — e-mail bez załącznika, biuro powiadomione';
            $this->svc->licenses->event($id, 'decision.no_pdf', 'Brak PDF decyzji ' . $pdf['number'] . ' (zapisano wersję ' . $pdf['ext'] . ') — e-mail bez załącznika.', $actor);
            $this->svc->notify->alertOffice('Decyzja ' . $pdf['number'] . ' wysłana BEZ PDF',
                '<p>Nie udało się wygenerować PDF decyzji (zapisano wersję ' . htmlspecialchars($pdf['ext']) . '). Prosimy doręczyć decyzję kursantowi <b>' . htmlspecialchars((string)$l['interested_name']) . '</b> inną drogą (wydruk z panelu).</p>',
                ['type' => 'license', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
            $body .= '<p><i>Dokument decyzji doręczymy osobno.</i></p>';
        }
        $res = $this->svc->notify->send(LicenseNotificationType::DecisionInterested, $this->svc->interestedContact($l), $subject,
            LicenseNotificationService::layout($grant ? 'Decyzja o przyznaniu' : 'Decyzja o odmowie', $body, '', '', 'AM7'), $sms,
            ['type' => 'license', 'id' => $id], $actor, true, $att);
        $report[] = ($resend ? 'Ponowna wysyłka decyzji: ' : 'Decyzja do kursanta: ') . $this->svc->describe($res);
        $this->svc->licenses->event($id, 'decision.sent', ($resend ? 'Ponownie wysłano decyzję ' : 'Wysłano decyzję ') . $pdf['number'] . ' (PDF): ' . $this->svc->describe($res), $actor);

        return implode(' · ', $report);
    }

    /** Zlecenie dla Helpdesku IT po wysłaniu decyzji o przyznaniu (fallback: alert do biura, wpis w dzienniku). */
    public function helpdeskTicket(int $id, array $actor): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l) return 'brak sprawy';
        if (!empty($l['helpdesk_ticket_id'])) return 'zgłoszenie już istnieje (' . $l['helpdesk_number'] . ')';
        $d = $l['decision'];
        $desc = "Licencja / dostęp do Office 365 — decyzja " . LicenseDocument::number(LicenseDoc::Am7Grant, $l) . " (zatwierdził(a): " . ($d['approved_name'] ?? '') . ").\n\n"
            . "Kursant: " . $l['interested_name'] . "\nKurs: " . $l['course_name'] . "\n"
            . "Sposób udostępnienia: " . (($d['access'] ?? '') === 'other' ? 'inny — ' . ($d['access_other'] ?? '') : 'konto użytkownika w tenancie ośrodka') . "\n"
            . "Konto do przypisania: " . ($d['account'] ?? '') . "\nPlan Microsoft 365 / SKU: " . ($d['plan'] ?? '') . "\n"
            . "Usługi / aplikacje: " . ($d['services'] ?? '') . "\nOkres aktywnego dostępu: " . ldDate($d['period_from'] ?? '') . ' — ' . ldDate($d['period_to'] ?? '') . "\n"
            . "Ograniczenia: " . ($d['restrictions'] ?? '') . "\nPo zakończeniu: " . ($d['after_end'] ?? '') . "\n\n"
            . "Do zrobienia: utworzyć / przypisać konto w tenancie ośrodka, nadać licencję z planu, przekazać kursantowi dane logowania bezpiecznym kanałem, "
            . "zaplanować odebranie licencji / blokadę po " . ldDate($d['period_to'] ?? '') . ".\nSprawa w SZO: " . $l['case_number'];
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            $me = $actor['id'] ? db_one("SELECT id, name, email FROM users WHERE id = ?", [(int)$actor['id']]) : null;
            $tid = hd_ticket_quick_create(['id' => $me['id'] ?? null, 'name' => $me['name'] ?? (string)$actor['name'], 'email' => $me['email'] ?? ''],
                'Office 365 dla kursanta: ' . $l['interested_name'] . ' (' . $l['case_number'] . ')', $desc, 'it_m365', 'wysoki', 'licencje_am7');
            $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id = ?", [$tid])['number'] ?? '');
            $this->svc->licenses->update($id, ['helpdesk_ticket_id' => $tid, 'helpdesk_number' => $num]);
            $this->svc->licenses->event($id, 'helpdesk.create', 'Utworzono zlecenie Helpdesku IT ' . $num . '.', $actor);
            db_insert('notification_logs', ['module' => LicenseNotificationService::MODULE, 'ref_type' => 'license', 'ref_id' => $id, 'type' => LicenseNotificationType::HelpdeskTicket->value,
                'channel' => 'email', 'recipient' => 'helpdesk ' . $num, 'status' => 'sent', 'error' => '', 'is_fallback' => 0, 'actor_id' => $actor['id'] ?: null, 'created_at' => ldNow()]);
            return 'utworzono zgłoszenie ' . $num;
        } catch (\Throwable $e) {
            error_log('am7 helpdesk: ' . $e);
            $this->svc->licenses->event($id, 'helpdesk.fail', 'Nie utworzono zlecenia Helpdesku: ' . $e->getMessage(), $actor);
            $this->svc->notify->alertOffice('Zlecenie Helpdesku dla ' . $l['case_number'] . ' — NIE UTWORZONO',
                '<p>Automatyczne zgłoszenie do Helpdesku IT nie powstało (' . htmlspecialchars($e->getMessage()) . '). Załóż je ręcznie:</p><pre style="white-space:pre-wrap">' . htmlspecialchars($desc) . '</pre>',
                ['type' => 'license', 'id' => $id], $actor, LicenseNotificationType::HelpdeskTicket);
            return 'błąd — biuro dostało treść zlecenia do ręcznego założenia';
        }
    }

    // ── Oświadczenia AM7/4 i AM7/5 ──────────────────────────────────────────────

    public static function inviteTexts(array $l, array $a, string $link, string $due, LicenseNotificationType $type): array
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $d = $l['decision'];
        $subject = ($type === LicenseNotificationType::Reminder ? 'Przypomnienie: ' : '') . 'Office 365 — złóż oświadczenia AM7/4 i AM7/5 do ' . $due;
        $body = '<p>Dzień dobry ' . $e($a['holder_name']) . ',</p><p>przyznaliśmy Ci dostęp do Office 365 (sprawa <b>' . $e($l['case_number']) . '</b>): konto <b>' . $e($d['account'] ?? '')
            . '</b>, plan <b>' . $e($d['plan'] ?? '') . '</b>, okres ' . $e(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) . '.</p>'
            . '<p>Złóż dwa oświadczenia: <b>AM7/4</b> — krótkie oświadczenie licencjobiorcy — kursanta oraz <b>AM7/5</b> — o nieodstępowaniu licencji i nieudostępnianiu konta.</p>'
            . ($a['status'] === 'rejected' && trim((string)$a['decision_note']) !== '' ? '<p style="border-left:4px solid #a20025;padding-left:10px"><b>Poprzednie oświadczenia odrzucono:</b> ' . $e($a['decision_note']) . '</p>' : '')
            . '<p><b>Realizacja do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e($due) . '.</b> Link jest osobisty i ważny ' . LD_TOKEN_DAYS . ' dni.</p>';
        $sms = 'FEER: Office 365 (sprawa ' . $l['case_number'] . ') - zloz oswiadczenia AM7/4 i AM7/5 do ' . $due . ': ' . $link;
        return [$subject, $body, $sms];
    }

    /** Dane do druku AM7/4 i AM7/5 z decyzji. */
    public static function declarationData(array $l, HolderDeclarationDto $dto, array $data): array
    {
        $d = $l['decision'];
        $data['account'] = (string)($d['account'] ?? '');
        $data['plan_period'] = trim((string)($d['plan'] ?? '') . ', ' . ldDate($l['period_from']) . ' — ' . ldDate($l['period_to']), ', ');
        $data['after_end'] = (string)($d['after_end'] ?? '');
        $data['data_rules'] = trim((string)($d['account_data'] ?? '') . ((string)($d['after_end'] ?? '') !== '' ? "\n" . $d['after_end'] : ''));
        $data['work'] = 'Office 365 — ' . ($d['plan'] ?? '');
        return $data;
    }
}
