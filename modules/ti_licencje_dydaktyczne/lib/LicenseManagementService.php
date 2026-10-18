<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseManagementService.php — obieg licencji dydaktycznej (AM5).
 *
 *   1. Wniosek AM5/1 (panel): zapis roboczy → „Wszczęcie”: termin realizacji 7 dni, PDF wniosku, e-mail + SMS do osoby
 *      zainteresowanej licencją (kto wszczął, jaki materiał, termin), e-mail do licencjodawcy z PDF i linkiem do decyzji.
 *      Fallback: licencjodawca bez e-maila / niedoręczony → biuro dostaje alert, decyzję z papieru wpisuje się w panelu.
 *   2. Decyzja licencjodawcy (portal po tokenie albo wpis z papieru): udzielona → licencja trafia do puli (Wolna);
 *      gdy wskazano osobę zainteresowaną — automatyczny przydział. Alert do biura + powiadomienie osoby zainteresowanej.
 *   3. Przydział (Przydzielona): token portalu, termin 7 dni, e-mail + SMS z linkiem do oświadczeń AM5/2 i AM5/3.
 *   4. Złożenie w portalu: zapis zmian danych (audit trail), PDF AM5/2 i AM5/3 (fallback HTML), kopia w profilu osoby,
 *      potwierdzenie dla osoby + alert do biura. Weryfikacja w panelu: zatwierdź (audyt MSUES) / odrzuć (nowy link).
 *   Zawieszenie / wznowienie, wygaśnięcie (po okresie licencji — liczone), cofnięcie przydziału, wycofanie wniosku.
 * AM7 (Office 365): wniosek AM7/1 wszczyna biuro → kursant dostaje e-mail + SMS z linkiem i podpisuje wniosek w portalu (7 dni) →
 * biuro wydaje decyzję AM7/2 (przyznanie) albo AM7/3 (odmowa) — PDF idzie e-mailem do kursanta (fallback SMS + alert) →
 * po przyznaniu i wysyłce: zlecenie dla Helpdesku IT (konto / licencja w tenancie ośrodka) i zaproszenie do oświadczeń AM7/4 i AM7/5.
 * Wyjątki DomainException / InvalidArgumentException niosą komunikat dla użytkownika.
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseEnums.php';
require_once __DIR__ . '/LicenseDto.php';
require_once __DIR__ . '/LicenseRepository.php';
require_once __DIR__ . '/LicenseNotificationService.php';
require_once __DIR__ . '/LicenseDocument.php';
require_once __DIR__ . '/LicenseStorage.php';
require_once __DIR__ . '/Am7Workflow.php';

final class LicenseManagementService
{
    public readonly LicenseRepository $licenses;
    public readonly AssignmentRepository $assignments;
    public readonly LicenseNotificationService $notify;

    public function __construct(?LicenseNotificationService $notify = null)
    {
        $this->licenses = new LicenseRepository();
        $this->assignments = new AssignmentRepository();
        $this->notify = $notify ?? new LicenseNotificationService();
    }

    public static function publicUrl(string $route): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/licencja.php?' . http_build_query(['_route' => $route]);
    }

    private function mustLicense(int $id): array
    {
        $l = $this->licenses->get($id);
        if (!$l) throw new DomainException('Nie ma takiej licencji.');
        return $l;
    }

    private function mustAssignment(int $id): array
    {
        $a = $this->assignments->get($id);
        if (!$a) throw new DomainException('Nie ma takiego przydziału.');
        return $a;
    }

    // ── Osoby (kursant / współpracownik / spoza SZO) ────────────────────────────

    /** Dane kontaktowe osoby z SZO: ['name','email','phone','address','fields' => [pole portalu => wartość]]. */
    public static function holderData(string $kind, ?int $id): ?array
    {
        if (!$id) return null;
        if ($kind === 'client') {
            $c = db_one("SELECT id, name, email, phone, address FROM k30_clients WHERE id = ?", [$id]);
            if (!$c) return null;
            return ['name' => (string)$c['name'], 'email' => (string)$c['email'], 'phone' => (string)$c['phone'], 'address' => (string)$c['address'], 'fields' => []];
        }
        if ($kind === 'user') {
            $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id = ?", [$id]);
            if (!$u) return null;
            $f = [];
            try {
                require_once dirname(__DIR__, 2) . '/collaborator_data/lib/CollaboratorDataService.php';
                $cur = (new CollaboratorDataService($id))->current();
                foreach (['addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city'] as $k) $f[$k] = (string)($cur[$k] ?? '');
            } catch (\Throwable $e) {}
            $addr = trim(trim(($f['addr_street'] ?? '') . ' ' . ($f['addr_house'] ?? '') . (($f['addr_flat'] ?? '') !== '' ? '/' . $f['addr_flat'] : '')) . ', ' . trim(($f['addr_postal'] ?? '') . ' ' . ($f['addr_city'] ?? '')), ', ');
            return ['name' => (string)$u['name'], 'email' => (string)$u['email'], 'phone' => (string)$u['phone_number'], 'address' => $addr, 'fields' => $f];
        }
        return null;
    }

    /** Kandydaci do wskazania (osoba zainteresowana / przydział): kursanci grupy albo wszyscy aktywni + kadra. */
    public static function people(?int $courseId = null): array
    {
        $out = ['client' => [], 'user' => []];
        try {
            $out['client'] = $courseId
                ? db_all("SELECT DISTINCT c.id, c.name, c.email, c.phone FROM k30_ti_enrollments e JOIN k30_clients c ON c.id = e.client_id WHERE e.course_id = ? ORDER BY c.name", [$courseId])
                : db_all("SELECT id, name, email, phone FROM k30_clients WHERE status <> 'archived' ORDER BY name LIMIT 2000");
        } catch (\Throwable $e) {}
        try { $out['user'] = db_all("SELECT id, name, email, phone_number AS phone FROM users WHERE COALESCE(is_active, 1) = 1 ORDER BY name LIMIT 2000"); }
        catch (\Throwable $e) { try { $out['user'] = db_all("SELECT id, name, email, phone_number AS phone FROM users ORDER BY name LIMIT 2000"); } catch (\Throwable $e2) {} }
        return $out;
    }

    public static function courses(): array
    {
        try { return db_all("SELECT id, name, display_name, group_code FROM k30_ti_courses WHERE status <> 'cancelled' ORDER BY is_active DESC, name"); }
        catch (\Throwable $e) { return []; }
    }

    public static function courseLabel(int $id): string
    {
        $c = db_one("SELECT name, display_name, group_code FROM k30_ti_courses WHERE id = ?", [$id]);
        if (!$c) return '';
        $n = trim((string)$c['display_name']) !== '' ? (string)$c['display_name'] : (string)$c['name'];
        return $n . (trim((string)$c['group_code']) !== '' ? ' (' . $c['group_code'] . ')' : '');
    }

    // ── 1. Wniosek AM5/1 ────────────────────────────────────────────────────────

    /** Zapis roboczy (nowy albo edycja). @return array{id:int, errors:array} */
    public function saveRequest(?int $id, LicenseRequestDto $dto, array $actor): array
    {
        $errors = $dto->errors(false);
        if ($errors) return ['id' => (int)$id, 'errors' => $errors];
        $course = $dto->courseName !== '' ? $dto->courseName : ($dto->courseId ? self::courseLabel($dto->courseId) : '');
        // Osoba zainteresowana z SZO: dane kontaktowe dociągane z rekordu, ręczne wpisy są fallbackiem
        $iName = $dto->interestedName; $iEmail = $dto->interestedEmail; $iPhone = $dto->interestedPhone;
        if (($dto->interestedKind === 'client' || $dto->interestedKind === 'user') && ($hd = self::holderData($dto->interestedKind, $dto->interestedId))) {
            $iName = $hd['name']; $iEmail = $iEmail !== '' ? $iEmail : $hd['email']; $iPhone = $iPhone !== '' ? $iPhone : $hd['phone'];
        }
        $cols = ['title' => $dto->title, 'work_description' => $dto->workDescription, 'course_id' => $dto->courseId, 'course_name' => $course,
            'licensor_name' => $dto->licensorName, 'licensor_email' => $dto->licensorEmail, 'licensor_phone' => $dto->licensorPhone, 'licensor_address' => $dto->licensorAddress,
            'applicant_name' => $dto->applicantName !== '' ? $dto->applicantName : ldOrgName(), 'applicant_contact' => $dto->applicantContact,
            'interested_kind' => $dto->interestedKind, 'interested_id' => $dto->interestedId, 'interested_name' => $iName, 'interested_email' => $iEmail, 'interested_phone' => $iPhone,
            'request_data' => json_encode($dto->requestData(), JSON_UNESCAPED_UNICODE), 'period_from' => $dto->periodFrom, 'period_to' => $dto->periodTo,
            'territory' => $dto->territory, 'seats' => $dto->seats];
        if ($id) {
            $l = $this->mustLicense($id);
            if (!$l['requestStatus']->editable()) throw new DomainException('Wniosek po wszczęciu nie podlega edycji — wycofaj go i utwórz nowy.');
            $this->licenses->update($id, $cols);
            $this->licenses->refreshCaseLetters($id);
            $this->licenses->event($id, 'request.edit', 'Zmieniono wniosek roboczy.', $actor);
        } else {
            $id = $this->licenses->create($cols, $actor);
            $this->licenses->event($id, 'request.create', 'Założono sprawę ' . ($this->licenses->get($id)['case_number'] ?? '') . ' — wniosek ' . LicenseDoc::Am5Request->symbol() . '.', $actor);
        }
        return ['id' => $id, 'errors' => []];
    }

    /** Generuje (ponownie) dokument AM5/1 i zapisuje ścieżkę. */
    public function renderRequest(int $id): array
    {
        $l = $this->mustLicense($id);
        $rd = $l['form']->requestDoc();
        $nr = LicenseDocument::number($rd, $l);
        $html = $l['form'] === LicenseForm::Am7 ? (new LicenseDocument())->am7RequestHtml($l) : (new LicenseDocument())->requestHtml($l);
        $doc = LicenseDocument::render($html, $rd->title(), $nr, $l['form']->value);
        [$rel, $sha] = LicenseStorage::put($doc['bytes'], $doc['ext'], strtolower($l['form']->value) . 'w' . $id);
        $this->licenses->update($id, ['request_pdf' => $rel, 'request_pdf_sha256' => $sha]);
        return ['path' => $rel, 'sha' => $sha, 'ext' => $doc['ext'], 'number' => $nr];
    }

    /**
     * Wszczęcie procesu: walidacja kompletu, termin 7 dni, PDF, powiadomienia (osoba zainteresowana: e-mail + SMS; licencjodawca: e-mail).
     * @return array{errors:array, report:list<string>}
     */
    public function start(int $id, array $actor, bool $sendToLicensor = true): array
    {
        $l = $this->mustLicense($id);
        if ($l['requestStatus'] !== LicenseRequestStatus::Draft) throw new DomainException('Wszcząć można tylko wniosek roboczy.');
        if ($l['form'] === LicenseForm::Am7) return (new Am7Workflow($this))->start($l, $actor);
        $dto = LicenseRequestDto::fromArray(self::formValues($l));
        $errors = $dto->errors(true);
        if ($errors) return ['errors' => $errors, 'report' => []];
        $now = ldNow();
        $this->licenses->update($id, ['request_status' => LicenseRequestStatus::Started->value, 'started_at' => $now, 'started_by' => $actor['id'] ?: null,
                                      'started_name' => (string)$actor['name'], 'due_on' => ldDue($now)]);
        $this->licenses->event($id, 'request.start', 'Wszczęto proces — realizacja do ' . LD_REALIZATION_DAYS . ' dni (do ' . ldDate(ldDue($now)) . ').', $actor);
        $doc = $this->renderRequest($id);
        $report = [];
        $l = $this->mustLicense($id);

        // Osoba zainteresowana licencją — e-mail i SMS (z fallbackami)
        $r = $this->notifyInterested($l, LicenseNotificationType::RequestStarted, $actor);
        $report[] = 'Osoba zainteresowana: ' . $this->describe($r);

        // Licencjodawca — e-mail z PDF wniosku i linkiem do decyzji
        if ($sendToLicensor) $report[] = 'Licencjodawca: ' . $this->sendToLicensor($l, $doc, $actor);
        return ['errors' => [], 'report' => $report];
    }

    /** Dane formularza wniosku z zapisanej licencji (do edycji i ponownej walidacji). */
    public static function formValues(array $l): array
    {
        $r = $l['request'] ?? [];
        if (LicenseForm::of($l) === LicenseForm::Am7) return Am7Workflow::formValues($l);
        $v = ['place' => $r['place'] ?? '', 'date' => $r['date'] ?? '', 'title' => $l['title'], 'work_description' => $l['work_description'],
            'course_id' => $l['course_id'], 'course_name' => $l['course_name'], 'licensor_name' => $l['licensor_name'], 'licensor_email' => $l['licensor_email'],
            'licensor_phone' => $l['licensor_phone'], 'licensor_address' => $l['licensor_address'], 'applicant_name' => $l['applicant_name'],
            'applicant_contact' => $l['applicant_contact'], 'interested_kind' => $l['interested_kind'], 'interested_id' => $l['interested_id'],
            'interested_name' => $l['interested_name'], 'interested_email' => $l['interested_email'], 'interested_phone' => $l['interested_phone'],
            'period_from' => $l['period_from'], 'period_to' => $l['period_to'], 'territory' => $l['territory'], 'remuneration' => $r['remuneration'] ?? '', 'seats' => $l['seats']];
        foreach ((array)($r['scopes'] ?? []) as $k => $d) { $v['scope_' . $k] = '1'; $v['scope_' . $k . '_detail'] = $d; }
        return $v;
    }

    public function describe(array $r): string
    {
        if (!$r['channels']) return 'brak danych kontaktowych — powiadomiono biuro';
        return implode(', ', array_map(fn($c, $s) => ($c === 'email' ? 'e-mail' : 'SMS') . ' — ' . LicenseNotificationService::statusLabel($s), array_keys($r['channels']), $r['channels']));
    }

    public function interestedContact(array $l): array
    {
        $c = ['name' => (string)$l['interested_name'], 'email' => (string)$l['interested_email'], 'phone' => (string)$l['interested_phone']];
        // Fallback: brak kontaktu w wniosku → dane z rekordu SZO
        if (($c['email'] === '' || $c['phone'] === '') && ($hd = self::holderData((string)$l['interested_kind'], $l['interested_id'] ? (int)$l['interested_id'] : null))) {
            $c['email'] = $c['email'] ?: $hd['email']; $c['phone'] = $c['phone'] ?: $hd['phone']; $c['name'] = $c['name'] ?: $hd['name'];
        }
        return $c;
    }

    private function notifyInterested(array $l, LicenseNotificationType $type, array $actor): array
    {
        $to = $this->interestedContact($l);
        if ($to['name'] === '' && $to['email'] === '' && $to['phone'] === '') return ['delivered' => false, 'channels' => [], 'errors' => []];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $nr = LicenseDocument::number(LicenseDoc::Am5Request, $l);
        $due = ldDate($l['due_on'] ?? '');
        $course = trim((string)$l['course_name']);
        if ($type === LicenseNotificationType::RequestStarted) {
            $subject = 'Wszczęto proces udzielenia licencji dydaktycznej (' . LD_SYMBOL . ')';
            $body = '<p>Dzień dobry' . ($to['name'] !== '' ? ' ' . $e($to['name']) : '') . ',</p>'
                . '<p>informujemy, że <b>' . $e($l['started_name'] ?: 'pracownik ' . ldOrgName()) . '</b> wszczął(-ęła) w dniu ' . $e(ldDate(substr((string)$l['started_at'], 0, 10)))
                . ' proces udzielenia licencji na korzystanie w celach dydaktycznych z materiału:</p>'
                . '<table style="border-collapse:collapse;margin:8px 0" cellpadding="6">'
                . '<tr><td style="background:#f2f2f2;width:180px">Numer sprawy</td><td><b>' . $e($nr) . '</b></td></tr>'
                . '<tr><td style="background:#f2f2f2">Dokument</td><td>' . LicenseDoc::Am5Request->symbol() . ' — ' . $e(LicenseDoc::Am5Request->title()) . '</td></tr>'
                . '<tr><td style="background:#f2f2f2">Utwór / materiał</td><td>' . $e($l['title']) . '</td></tr>'
                . ($course !== '' ? '<tr><td style="background:#f2f2f2">Kurs / zajęcia</td><td>' . $e($course) . '</td></tr>' : '')
                . '<tr><td style="background:#f2f2f2">Licencjodawca</td><td>' . $e($l['licensor_name']) . '</td></tr>'
                . '<tr><td style="background:#f2f2f2">Termin realizacji</td><td><b>do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e($due) . '</b></td></tr></table>'
                . '<p>Gdy licencjodawca udzieli licencji, otrzymasz link do złożenia oświadczeń <b>' . LicenseDoc::Am5Participant->symbol() . '</b> (oświadczenie licencjobiorcy – kursanta) '
                . 'i <b>' . LicenseDoc::Am5NonTransfer->symbol() . '</b> (o nieodstępowaniu licencji i nieudostępnianiu materiałów).</p>'
                . '<p>Jeśli nie spodziewasz się tej wiadomości, skontaktuj się z biurem ' . $e(ldOrgName()) . '.</p>';
            $sms = 'FEER: ' . ($l['started_name'] ?: 'Fundacja') . ' wszczal(a) proces licencji ' . LD_SYMBOL . ' (sprawa ' . $nr . ') na material "' . mb_strimwidth((string)$l['title'], 0, 30, '...') . '"'
                . ($course !== '' ? ' (' . mb_strimwidth($course, 0, 30, '...') . ')' : '') . '. Realizacja do ' . LD_REALIZATION_DAYS . ' dni (do ' . $due . '). Szczegoly w e-mailu.';
        } else {
            $grant = $l['requestStatus'] === LicenseRequestStatus::Granted;
            $subject = ($grant ? 'Udzielono licencji dydaktycznej' : 'Licencjodawca odmówił licencji') . ' (' . $nr . ')';
            $body = '<p>Dzień dobry' . ($to['name'] !== '' ? ' ' . $e($to['name']) : '') . ',</p>'
                . '<p>w procesie <b>' . $e($nr) . '</b> (materiał: ' . $e($l['title']) . ') licencjodawca <b>' . $e($l['licensor_name']) . '</b> '
                . ($grant ? '<b>udzielił licencji</b> na okres ' . $e(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) . '. Link do złożenia oświadczeń otrzymasz w osobnej wiadomości.'
                          : '<b>odmówił udzielenia licencji</b>' . (trim((string)($l['decision']['refusal_reason'] ?? '')) !== '' ? ': ' . $e($l['decision']['refusal_reason']) : '') . '.') . '</p>';
            $sms = 'FEER: licencja ' . LD_SYMBOL . ' "' . mb_strimwidth((string)$l['title'], 0, 40, '...') . '" - ' . ($grant ? 'UDZIELONA. Link do oswiadczen wyslemy osobno.' : 'odmowa licencjodawcy.');
        }
        // Decyzja licencjodawcy (sekcja H wniosku AM5/1, układ CIT) — PDF w załączniku
        $att = $type === LicenseNotificationType::DecisionInterested ? LicenseNotificationService::attachment((string)$l['request_pdf'], $nr . ' decyzja') : [];
        return $this->notify->send($type, $to, $subject, LicenseNotificationService::layout($subject, $body), $sms, ['type' => 'license', 'id' => (int)$l['id']], $actor, true, $att);
    }

    private function sendToLicensor(array $l, array $doc, array $actor): string
    {
        $email = trim((string)$l['licensor_email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->notify->alertOffice('Wniosek ' . LicenseDocument::number(LicenseDoc::Am5Request, $l) . ' — licencjodawca bez e-maila',
                '<p>Wniosek o licencję na materiał <b>' . htmlspecialchars((string)$l['title']) . '</b> wszczęto, ale licencjodawca <b>' . htmlspecialchars((string)$l['licensor_name'])
                . '</b> nie ma adresu e-mail. Prosimy wysłać wydruk AM5/1 pocztą lub doręczyć osobiście i wpisać decyzję w panelu. Termin realizacji: '
                . htmlspecialchars(ldDate($l['due_on'])) . '.</p>', ['type' => 'license', 'id' => (int)$l['id']], $actor, LicenseNotificationType::LicensorRequest);
            $this->licenses->event((int)$l['id'], 'licensor.paper', 'Licencjodawca bez e-maila — obieg papierowy, biuro powiadomione.', $actor);
            return 'brak e-maila — obieg papierowy (biuro powiadomione)';
        }
        $token = $this->licenses->issueLicensorToken((int)$l['id']);
        $link = self::publicUrl('/decyzja/' . $token);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $nr = LicenseDocument::number(LicenseDoc::Am5Request, $l);
        $subject = 'Wniosek o udzielenie licencji dydaktycznej ' . $nr . ' — ' . ldOrgName();
        $body = '<p>Szanowni Państwo,</p><p>' . $e($l['applicant_name']) . ' zwraca się z prośbą o udzielenie licencji na korzystanie w celach dydaktycznych z utworu lub materiału '
            . '<b>' . $e($l['title']) . '</b>' . (trim((string)$l['course_name']) !== '' ? ' w kursie <b>' . $e($l['course_name']) . '</b>' : '') . '.</p>'
            . '<p>Wniosek (wzór <b>' . LicenseDoc::Am5Request->symbol() . '</b>) jest w załączniku. Decyzję można podjąć przez formularz pod przyciskiem — bez logowania — '
            . 'albo podpisać sekcję H wydruku i odesłać skan.</p><p><b>Prosimy o decyzję w ciągu ' . LD_REALIZATION_DAYS . ' dni — do ' . $e(ldDate($l['due_on'])) . '.</b> '
            . 'Link jest ważny ' . LD_TOKEN_DAYS . ' dni.</p>';
        $att = [];
        if ($doc['ext'] === 'pdf') $att = LicenseNotificationService::attachment((string)$doc['path'], $nr);
        $st = 'failed';
        try {
            if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            $qid = mail_queue_add($email, (string)$l['licensor_name'], $subject, LicenseNotificationService::layout('Wniosek o licencję dydaktyczną', $body, $link, 'Podejmij decyzję'),
                                  '', LicenseNotificationService::MODULE, (int)$l['id'], '', true, $att);
            $row = $qid > 0 ? db_one("SELECT status, last_error FROM mail_queue WHERE id = ?", [$qid]) : null;
            $st = ($row['status'] ?? '') === 'sent' ? 'sent' : ($qid > 0 ? 'queued' : 'failed');
            db_insert('notification_logs', ['module' => LicenseNotificationService::MODULE, 'ref_type' => 'license', 'ref_id' => (int)$l['id'],
                'type' => LicenseNotificationType::LicensorRequest->value, 'channel' => 'email', 'recipient' => LicenseNotificationService::maskEmail($email),
                'status' => $st, 'error' => mb_substr((string)($row['last_error'] ?? ''), 0, 500), 'is_fallback' => 0, 'queue_id' => $qid ?: null,
                'actor_id' => $actor['id'] ?: null, 'created_at' => ldNow()]);
        } catch (\Throwable $ex) {
            error_log('am5 licensor mail: ' . $ex->getMessage());
        }
        if ($st !== 'sent') {
            // Fallback: SMS do licencjodawcy z informacją o e-mailu + alert do biura
            $phone = trim((string)$l['licensor_phone']);
            if ($phone !== '') {
                $this->notify->send(LicenseNotificationType::LicensorRequest, ['name' => (string)$l['licensor_name'], 'phone' => $phone], '', '',
                    'FEER: prosimy o decyzje w sprawie licencji ' . LD_SYMBOL . ' na "' . mb_strimwidth((string)$l['title'], 0, 40, '...') . '" do ' . ldDate($l['due_on']) . ': ' . $link,
                    ['type' => 'license', 'id' => (int)$l['id']], $actor, false);
            }
            $this->notify->alertOffice('Wniosek ' . $nr . ' — e-mail do licencjodawcy nie wyszedł od razu',
                '<p>E-mail z wnioskiem do <b>' . htmlspecialchars((string)$l['licensor_name']) . '</b> ' . ($st === 'queued' ? 'czeka w kolejce na ponowienie' : 'nie został wysłany')
                . '. Link do decyzji można przekazać ręcznie z panelu („Wyślij ponownie”) lub wysłać wydruk pocztą.</p>', ['type' => 'license', 'id' => (int)$l['id']], $actor, LicenseNotificationType::LicensorRequest);
        }
        $this->licenses->event((int)$l['id'], 'licensor.sent', 'Wniosek do licencjodawcy: e-mail — ' . LicenseNotificationService::statusLabel($st) . '.', $actor);
        return 'e-mail — ' . LicenseNotificationService::statusLabel($st);
    }

    /** Ponowna wysyłka wniosku do licencjodawcy (nowy link; poprzedni traci ważność). */
    public function resendLicensor(int $id, array $actor): string
    {
        $l = $this->mustLicense($id);
        if ($l['requestStatus'] !== LicenseRequestStatus::Started) throw new DomainException('Wniosek nie czeka na decyzję licencjodawcy.');
        $doc = ($l['request_pdf'] !== '' && LicenseStorage::fullPath((string)$l['request_pdf'])) ? ['path' => $l['request_pdf'], 'ext' => str_ends_with((string)$l['request_pdf'], '.pdf') ? 'pdf' : 'html'] : $this->renderRequest($id);
        return $this->sendToLicensor($l, $doc, $actor);
    }

    public function withdraw(int $id, string $reason, array $actor): void
    {
        $l = $this->mustLicense($id);
        if (!in_array($l['requestStatus'], [LicenseRequestStatus::Draft, LicenseRequestStatus::Started], true)) throw new DomainException('Wycofać można tylko wniosek przed decyzją.');
        $this->licenses->update($id, ['request_status' => LicenseRequestStatus::Withdrawn->value, 'licensor_token_hash' => '']);
        $this->licenses->event($id, 'request.withdraw', 'Wycofano wniosek. ' . $reason, $actor);
    }

    public function deleteDraft(int $id, array $actor): void
    {
        $l = $this->mustLicense($id);
        if ($l['requestStatus'] !== LicenseRequestStatus::Draft) throw new DomainException('Usunąć można tylko wniosek roboczy (pozostałe zostają w dzienniku audytowym).');
        $this->licenses->delete($id);
        if (function_exists('audit_log')) { try { audit_log('ld.request.delete', ['id' => $id], $actor['id'] ?: null); } catch (\Throwable $e) {} }
    }

    // ── 2. Decyzja licencjodawcy ────────────────────────────────────────────────

    /**
     * @param string $via 'portal' (licencjodawca przez link) | 'panel' (wpis z papieru)
     * @return array{errors:array, report:list<string>}
     */
    public function decide(int $id, LicensorDecisionDto $dto, string $via, array $actor): array
    {
        $l = $this->mustLicense($id);
        if ($l['form'] === LicenseForm::Am7) throw new DomainException('Sprawę AM7 rozstrzyga placówka decyzją AM7/2 lub AM7/3.');
        if ($l['requestStatus'] !== LicenseRequestStatus::Started) throw new DomainException('Decyzja w tej sprawie została już podjęta albo wniosek nie został wszczęty.');
        $errors = $dto->errors();
        if ($errors) return ['errors' => $errors, 'report' => []];
        $cols = ['request_status' => ($dto->grant ? LicenseRequestStatus::Granted : LicenseRequestStatus::Refused)->value,
                 'decision_data' => json_encode($dto->data(), JSON_UNESCAPED_UNICODE), 'decided_at' => ldNow(), 'decided_via' => $via,
                 'decided_ip' => $via === 'portal' ? substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) : '', 'licensor_token_hash' => ''];
        if ($dto->grant) $cols += ['license_kind' => $dto->kind, 'period_from' => $dto->periodFrom, 'period_to' => $dto->periodTo, 'territory' => $dto->territory];
        $this->licenses->update($id, $cols);
        $this->licenses->event($id, $dto->grant ? 'decision.grant' : 'decision.refuse',
            ($dto->grant ? 'Licencjodawca udzielił licencji (' . ($dto->kind === 'wylaczna' ? 'wyłączna' : 'niewyłączna') . ').' : 'Licencjodawca odmówił licencji.')
            . ' Podpisał(a): ' . $dto->signerName . ($via === 'portal' ? ' (portal)' : ' (wpis z papieru)'), $actor);
        $report = [];
        try { $this->renderRequest($id); } catch (\Throwable $e) { $report[] = 'PDF wniosku z decyzją: ' . $e->getMessage(); }
        $l = $this->mustLicense($id);
        $nr = LicenseDocument::number(LicenseDoc::Am5Request, $l);
        $this->notify->alertOffice('Decyzja licencjodawcy — ' . $nr,
            '<p>Licencjodawca <b>' . htmlspecialchars((string)$l['licensor_name']) . '</b> ' . ($dto->grant ? '<b>udzielił</b>' : '<b>odmówił</b>') . ' licencji na materiał <b>'
            . htmlspecialchars((string)$l['title']) . '</b> (' . ($via === 'portal' ? 'przez portal' : 'wpis z papieru') . ').</p>', ['type' => 'license', 'id' => $id], $actor, LicenseNotificationType::DecisionAdmin);
        $r = $this->notifyInterested($l, LicenseNotificationType::DecisionInterested, $actor);
        if ($r['channels']) $report[] = 'Osoba zainteresowana: ' . $this->describe($r);
        // Udzielona + wskazana osoba zainteresowana → automatyczny przydział i zaproszenie do oświadczeń
        if ($dto->grant && $l['interested_kind'] !== '') {
            try {
                $c = $this->interestedContact($l);
                $res = $this->assign($id, ['kind' => (string)$l['interested_kind'], 'id' => $l['interested_id'] ? (int)$l['interested_id'] : null] + $c, $actor);
                $report[] = 'Przydział dla osoby zainteresowanej: ' . $res['report'];
            } catch (\Throwable $e) { $report[] = 'Automatyczny przydział nieudany: ' . $e->getMessage(); }
        }
        return ['errors' => [], 'report' => $report];
    }

    // ── Pula: zawieszenie / wznowienie ──────────────────────────────────────────

    public function suspend(int $id, bool $on, string $reason, array $actor): void
    {
        $l = $this->mustLicense($id);
        if ($l['requestStatus'] !== LicenseRequestStatus::Granted) throw new DomainException('Zawiesić można tylko udzieloną licencję.');
        if ($on && trim($reason) === '') throw new InvalidArgumentException('Podaj powód zawieszenia.');
        $this->licenses->update($id, ['suspended' => $on ? 1 : 0, 'suspended_reason' => $on ? $reason : '']);
        $this->licenses->event($id, $on ? 'license.suspend' : 'license.resume', $on ? 'Zawieszono licencję: ' . $reason : 'Wznowiono licencję.', $actor);
    }

    // ── 3. Przydział ────────────────────────────────────────────────────────────

    /**
     * @param array{kind:string, id:?int, name?:string, email?:string, phone?:string} $holder
     * @return array{id:int, report:string}
     */
    public function assign(int $licenseId, array $holder, array $actor): array
    {
        $l = $this->mustLicense($licenseId);
        $pool = $l['poolStatus'];
        if ($pool === null) throw new DomainException('Licencja nie została jeszcze udzielona przez licencjodawcę.');
        if ($pool === LicenseStatus::Expired) throw new DomainException('Licencja wygasła — nie można jej przydzielić.');
        if ($pool === LicenseStatus::Suspended) throw new DomainException('Licencja jest zawieszona.');
        if ((int)$l['seats'] > 0 && $l['activeAssignments'] >= (int)$l['seats']) throw new DomainException('Brak wolnych miejsc w licencji (limit ' . (int)$l['seats'] . ').');
        $kind = in_array($holder['kind'] ?? '', ['client', 'user', 'external'], true) ? $holder['kind'] : 'external';
        $hid = $kind !== 'external' && !empty($holder['id']) ? (int)$holder['id'] : null;
        $hd = self::holderData($kind, $hid);
        if ($kind !== 'external' && !$hd) throw new DomainException('Nie znaleziono wskazanej osoby w SZO.');
        $name = trim((string)($holder['name'] ?? '')) ?: (string)($hd['name'] ?? '');
        $email = trim((string)($holder['email'] ?? '')) ?: (string)($hd['email'] ?? '');
        $phone = trim((string)($holder['phone'] ?? '')) ?: (string)($hd['phone'] ?? '');
        if ($name === '') throw new InvalidArgumentException('Podaj imię i nazwisko licencjobiorcy.');
        if (!LdInput::email($email)) throw new InvalidArgumentException('Nieprawidłowy e-mail licencjobiorcy.');
        if ($this->assignments->activeFor($licenseId, $kind, $hid, $email)) throw new DomainException($name . ' ma już przydział tej licencji.');
        $now = ldNow();
        $aid = $this->assignments->create(['license_id' => $licenseId, 'holder_kind' => $kind, 'holder_id' => $hid, 'holder_name' => $name,
            'holder_email' => $email, 'holder_phone' => $phone, 'status' => LicenseAssignmentStatus::Invited->value, 'due_on' => ldDue($now),
            'assigned_at' => $now, 'assigned_by' => $actor['id'] ?: null, 'assigned_name' => (string)$actor['name']]);
        $this->licenses->event($licenseId, 'assign', 'Przydzielono licencję: ' . $name . ' — oświadczenia do ' . ldDate(ldDue($now)) . '.', $actor, $aid);
        $r = $this->invite($aid, $actor, LicenseNotificationType::LicenseAssigned);
        return ['id' => $aid, 'report' => $name . ' — ' . $r];
    }

    /** Wysyła (ponownie) link do portalu oświadczeń; nowy token unieważnia poprzedni. */
    public function invite(int $assignmentId, array $actor, LicenseNotificationType $type = LicenseNotificationType::Reminder): string
    {
        $a = $this->mustAssignment($assignmentId);
        if (!$a['statusEnum']->canSubmit()) throw new DomainException('Przydział nie czeka na oświadczenia.');
        $l = $this->mustLicense((int)$a['license_id']);
        $token = $this->assignments->issueToken($assignmentId);
        if ($type === LicenseNotificationType::Reminder && ($a['due_on'] ?? '') < ldToday()) {
            $this->assignments->update($assignmentId, ['due_on' => ldDue()]);   // po terminie: nowe 7 dni od przypomnienia
            $a['due_on'] = ldDue();
        }
        $link = self::publicUrl('/oswiadczenie/' . $token);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $due = ldDate((string)$a['due_on']);
        if ($l['form'] === LicenseForm::Am7) {
            [$subject, $body, $sms] = Am7Workflow::inviteTexts($l, $a, $link, $due, $type);
            $r = $this->notify->send($type, ['name' => (string)$a['holder_name'], 'email' => (string)$a['holder_email'], 'phone' => (string)$a['holder_phone']],
                $subject, LicenseNotificationService::layout('Office 365 — oświadczenia', $body, $link, 'Złóż oświadczenia', 'AM7'), $sms,
                ['type' => 'assignment', 'id' => $assignmentId], $actor);
            $this->licenses->event((int)$a['license_id'], $type === LicenseNotificationType::Reminder ? 'invite.remind' : 'invite.send', 'Link do oświadczeń AM7/4 i AM7/5: ' . $this->describe($r), $actor, $assignmentId);
            return $this->describe($r);
        }
        $subject = ($type === LicenseNotificationType::Reminder ? 'Przypomnienie: ' : '') . 'Licencja dydaktyczna — złóż oświadczenia ' . LD_SYMBOL . ' do ' . $due;
        $body = '<p>Dzień dobry ' . $e($a['holder_name']) . ',</p>'
            . '<p>' . $e(ldOrgName()) . ' przyznała Ci licencję na korzystanie w celach dydaktycznych z materiału <b>' . $e($l['title']) . '</b>'
            . (trim((string)$l['course_name']) !== '' ? ' w kursie <b>' . $e($l['course_name']) . '</b>' : '') . ' (licencjodawca: ' . $e($l['licensor_name'])
            . ', okres ' . $e(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) . ').</p>'
            . '<p>Aby korzystać z materiału, uzupełnij dane i złóż dwa oświadczenia: <b>' . LicenseDoc::Am5Participant->symbol() . '</b> — oświadczenie licencjobiorcy – kursanta '
            . 'oraz <b>' . LicenseDoc::Am5NonTransfer->symbol() . '</b> — o nieodstępowaniu licencji i nieudostępnianiu materiałów.</p>'
            . ($a['statusEnum'] === LicenseAssignmentStatus::Rejected && trim((string)$a['decision_note']) !== ''
                ? '<p style="border-left:4px solid #a20025;padding-left:10px"><b>Poprzednie oświadczenia odrzucono:</b> ' . $e($a['decision_note']) . '. Prosimy o ponowne złożenie.</p>' : '')
            . '<p><b>Realizacja do ' . LD_REALIZATION_DAYS . ' dni — do ' . $e($due) . '.</b> Link jest osobisty i ważny ' . LD_TOKEN_DAYS . ' dni.</p>';
        $sms = 'FEER: przyznano Ci licencje ' . LD_SYMBOL . ' na "' . mb_strimwidth((string)$l['title'], 0, 40, '...') . '". Zloz oswiadczenia do ' . $due . ': ' . $link;
        $r = $this->notify->send($type, ['name' => (string)$a['holder_name'], 'email' => (string)$a['holder_email'], 'phone' => (string)$a['holder_phone']],
            $subject, LicenseNotificationService::layout('Licencja dydaktyczna — oświadczenia', $body, $link, 'Złóż oświadczenia'), $sms,
            ['type' => 'assignment', 'id' => $assignmentId], $actor);
        $this->licenses->event((int)$a['license_id'], $type === LicenseNotificationType::Reminder ? 'invite.remind' : 'invite.send', 'Link do oświadczeń: ' . $this->describe($r), $actor, $assignmentId);
        return $this->describe($r);
    }

    public function revoke(int $assignmentId, string $reason, array $actor): void
    {
        $a = $this->mustAssignment($assignmentId);
        if ($a['statusEnum'] === LicenseAssignmentStatus::Revoked) return;
        if (trim($reason) === '') throw new InvalidArgumentException('Podaj powód cofnięcia przydziału.');
        $this->assignments->update($assignmentId, ['status' => LicenseAssignmentStatus::Revoked->value, 'revoked_at' => ldNow(), 'revoked_reason' => $reason, 'token_hash' => '']);
        $this->licenses->event((int)$a['license_id'], 'assign.revoke', 'Cofnięto przydział (' . $a['holder_name'] . '): ' . $reason, $actor, $assignmentId);
    }

    // ── 4. Oświadczenia z portalu ───────────────────────────────────────────────

    /**
     * Złożenie oświadczeń AM5/2 i AM5/3 przez portal (po tokenie). Zapis zmian danych, PDF-y, kopia w profilu, powiadomienia.
     * @return array{errors:array, numbers:list<string>}
     */
    public function submitDeclarations(array $assignment, HolderDeclarationDto $dto): array
    {
        $errors = $dto->errors();
        if ($errors) return ['errors' => $errors, 'numbers' => []];
        $a = $this->mustAssignment((int)$assignment['id']);
        if (!$a['statusEnum']->canSubmit()) throw new DomainException('Oświadczenia zostały już złożone.');
        $l = $this->mustLicense((int)$a['license_id']);
        if (in_array($l['poolStatus'], [LicenseStatus::Expired, LicenseStatus::Suspended, null], true)) throw new DomainException('Licencja jest ' . ($l['poolStatus']?->label() ?? 'nieaktywna') . ' — skontaktuj się z biurem.');
        $actor = ['id' => 0, 'name' => $dto->name . ' (portal)'];
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $now = ldNow();
        $docs = $l['form']->declarationDocs();
        $version = 1 + (int)(db_one("SELECT COUNT(*) AS c FROM license_declarations WHERE assignment_id = ? AND doc = ?", [(int)$a['id'], $docs[0]->value])['c'] ?? 0);

        $pdo = db();
        $pdo->beginTransaction();
        $files = [];
        try {
            $changes = $this->recordChanges($a, $dto);
            $this->assignments->supersedeDeclarations((int)$a['id']);
            $data = ['name' => $dto->name, 'address' => $dto->addressLine(), 'course' => (string)$l['course_name'],
                     'work' => trim((string)$l['title'] . (trim((string)$l['work_description']) !== '' ? ' — ' . mb_strimwidth((string)$l['work_description'], 0, 300, '…') : '')),
                     'place' => $dto->place, 'date' => substr($now, 0, 10)];
            if ($l['form'] === LicenseForm::Am7) $data = Am7Workflow::declarationData($l, $dto, $data);
            $sig = ['at' => $now, 'ip' => $ip];
            $numbers = [];
            foreach ($docs as $doc) {
                $nr = LicenseDocument::number($doc, $l, $a, $version);
                $out = LicenseDocument::render((new LicenseDocument())->declarationHtml($doc, $l, $a, $data, $sig, $version), $doc->title() . ' — ' . $dto->name, $nr, $l['form']->value);
                [$rel, $sha] = LicenseStorage::put($out['bytes'], $out['ext'], strtolower(str_replace('/', '', $doc->value)) . 'o_' . (int)$a['id']);
                $files[] = ['rel' => $rel, 'nr' => $nr, 'doc' => $doc, 'ext' => $out['ext']];
                $this->assignments->addDeclaration(['assignment_id' => (int)$a['id'], 'doc' => $doc->value, 'number' => $nr,
                    'data' => json_encode($data + ['accepted' => true, 'version' => $version], JSON_UNESCAPED_UNICODE), 'file_path' => $rel, 'file_mime' => $out['mime'],
                    'sha256' => $sha, 'signed_at' => $now, 'signed_ip' => $ip, 'signed_ua' => $ua, 'status' => LicenseDeclarationStatus::Pending->value]);
                $numbers[] = $nr;
            }
            $this->assignments->update((int)$a['id'], ['status' => LicenseAssignmentStatus::Submitted->value, 'submitted_at' => $now, 'holder_name' => $dto->name,
                'holder_email' => $dto->email !== '' ? $dto->email : (string)$a['holder_email'], 'holder_phone' => $dto->phone !== '' ? $dto->phone : (string)$a['holder_phone'], 'token_hash' => '']);
            $this->licenses->event((int)$l['id'], 'declarations.submit', 'Złożono oświadczenia ' . implode(', ', $numbers) . ' (portal, IP ' . $ip . '); zmiany danych: ' . $changes . '.', $actor, (int)$a['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ($files as $f) if ($p = LicenseStorage::fullPath($f['rel'])) @unlink($p);
            throw $e;
        }

        // Po zatwierdzeniu transakcji: kopia w profilu, potwierdzenie, alert (błędy nie cofają złożenia)
        $profile = [];
        foreach ($files as $f) $profile[] = LicenseStorage::archiveToProfile($f['rel'], $f['nr'] . ' ' . $f['doc']->short(), (string)$a['holder_kind'], $a['holder_id'] ? (int)$a['holder_id'] : null);
        $this->licenses->event((int)$l['id'], 'declarations.archive', 'Profil osoby: ' . implode('; ', array_unique($profile)) . '.', $actor, (int)$a['id']);
        $this->confirmToHolder($l, $a, $dto, $files, $actor);
        $this->notify->alertOffice('Nowe oświadczenia do weryfikacji — ' . $dto->name,
            '<p><b>' . htmlspecialchars($dto->name) . '</b> złożył(a) oświadczenia ' . htmlspecialchars(implode(' i ', array_column($files, 'nr'))) . ' do licencji <b>'
            . htmlspecialchars((string)$l['title']) . '</b>. Prosimy o weryfikację w panelu dydaktyka › Licencje dydaktyczne (AM5).</p>',
            ['type' => 'assignment', 'id' => (int)$a['id']], $actor, LicenseNotificationType::DeclarationAdmin);
        return ['errors' => [], 'numbers' => array_column($files, 'nr')];
    }

    private function confirmToHolder(array $l, array $a, HolderDeclarationDto $dto, array $files, array $actor): void
    {
        $att = [];
        foreach ($files as $f) if ($f['ext'] === 'pdf') $att = array_merge($att, LicenseNotificationService::attachment($f['rel'], $f['nr']));
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $subject = 'Potwierdzenie złożenia oświadczeń ' . $l['form']->value . ' — ' . $l['title'];
        $body = '<p>Dzień dobry ' . $e($dto->name) . ',</p><p>potwierdzamy złożenie oświadczeń:</p><ul>'
            . implode('', array_map(fn($f) => '<li><b>' . $e($f['nr']) . '</b> — ' . $e($f['doc']->title()) . '</li>', $files)) . '</ul>'
            . '<p>Weryfikacja przez ' . $e(ldOrgName()) . ' trwa do ' . LD_REALIZATION_DAYS . ' dni. Dokumenty są w załączniku' . (count($att) < count($files) ? ' (część w wersji HTML — do pobrania u biura)' : '') . '.</p>';
        $email = $dto->email !== '' ? $dto->email : (string)$a['holder_email'];
        $phone = $dto->phone !== '' ? $dto->phone : (string)$a['holder_phone'];
        $st = 'skipped';
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                $qid = mail_queue_add($email, $dto->name, $subject, LicenseNotificationService::layout('Oświadczenia złożone', $body, '', '', $l['form']->value), '', LicenseNotificationService::MODULE, (int)$a['id'], '', true, $att);
                $row = $qid > 0 ? db_one("SELECT status, last_error FROM mail_queue WHERE id = ?", [$qid]) : null;
                $st = ($row['status'] ?? '') === 'sent' ? 'sent' : ($qid > 0 ? 'queued' : 'skipped');
                db_insert('notification_logs', ['module' => LicenseNotificationService::MODULE, 'ref_type' => 'assignment', 'ref_id' => (int)$a['id'],
                    'type' => LicenseNotificationType::DeclarationConfirm->value, 'channel' => 'email', 'recipient' => LicenseNotificationService::maskEmail($email),
                    'status' => $st, 'error' => mb_substr((string)($row['last_error'] ?? ''), 0, 500), 'is_fallback' => 0, 'queue_id' => $qid ?: null, 'actor_id' => null, 'created_at' => ldNow()]);
            } catch (\Throwable $ex) { $st = 'failed'; error_log('am5 confirm mail: ' . $ex->getMessage()); }
        }
        // Fallback: e-mail nie wyszedł od razu albo brak adresu → SMS z numerami dokumentów
        if ($st !== 'sent' && $phone !== '') {
            $this->notify->send(LicenseNotificationType::DeclarationConfirm, ['name' => $dto->name, 'phone' => $phone], '', '',
                'FEER: przyjelismy oswiadczenia ' . implode(', ', array_column($files, 'nr')) . '. Weryfikacja do ' . LD_REALIZATION_DAYS . ' dni.', ['type' => 'assignment', 'id' => (int)$a['id']], $actor, false);
        }
    }

    /**
     * Audit trail zmian danych osoby (license_holder_changes): stara → nowa wartość.
     *   kursant  — adres wprowadzany od razu (k30_clients.address), imię/e-mail/telefon czekają na akceptację biura;
     *   współpracownik — telefon i adres trafiają do collaborator_data_changes jako „czeka na akceptację” (ekran Zmiana danych),
     *                    imię i e-mail — do akceptacji w module (zmiana loginu tylko w koncie);
     *   spoza SZO — migawka w przydziale, zapis „wprowadzona”.
     */
    private function recordChanges(array $a, HolderDeclarationDto $dto): string
    {
        $kind = (string)$a['holder_kind'];
        $hid = $a['holder_id'] ? (int)$a['holder_id'] : null;
        $hd = self::holderData($kind, $hid);
        $new = $dto->fields();
        $old = ['name' => (string)($hd['name'] ?? $a['holder_name']), 'email' => (string)($hd['email'] ?? $a['holder_email']), 'phone' => (string)($hd['phone'] ?? $a['holder_phone'])];
        $base = ['assignment_id' => (int)$a['id'], 'holder_kind' => $kind, 'holder_id' => $hid];
        $n = ['applied' => 0, 'pending' => 0];
        $add = function (string $field, string $o, string $nv, string $status, ?int $cdc = null) use ($base, &$n) {
            if (trim($o) === trim($nv) || ($nv === '' && $field !== 'address')) return;
            $this->assignments->addChange($base + ['field' => $field, 'old_value' => $o, 'new_value' => $nv, 'status' => $status, 'cdc_id' => $cdc]);
            $n[$status]++;
        };
        $norm = fn(string $p) => preg_replace('/\D/', '', $p);
        foreach (['name', 'email', 'phone'] as $k) {
            if ($k === 'phone' && $norm($old[$k]) === $norm($new[$k])) continue;
            if ($k === 'email' && mb_strtolower($old[$k]) === mb_strtolower($new[$k])) continue;
            if ($kind === 'user' && $k === 'phone' && $new[$k] !== '') {
                $add('phone', $old[$k], $new[$k], 'pending', $this->cdcPending((int)$hid, 'telefon', $old[$k], $new[$k]));
                continue;
            }
            $add($k, $old[$k], $new[$k], $kind === 'external' ? 'applied' : 'pending');
        }
        if ($kind === 'client') {
            $addr = $dto->addressLine();
            if (trim((string)$hd['address']) !== $addr && $addr !== '') {
                db_exec("UPDATE k30_clients SET address = ?, updated_at = ? WHERE id = ?", [$addr, ldNow(), $hid]);
                $add('address', (string)$hd['address'], $addr, 'applied');
            }
        } elseif ($kind === 'user') {
            foreach (['addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city'] as $k) {
                $o = (string)($hd['fields'][$k] ?? '');
                if (trim($o) !== trim($new[$k]) && $new[$k] !== '') $add($k, $o, $new[$k], 'pending', $this->cdcPending((int)$hid, $k, $o, $new[$k]));
            }
        } else {
            $add('address', '', $dto->addressLine(), 'applied');
        }
        return $n['applied'] + $n['pending'] === 0 ? 'brak' : $n['applied'] . ' wprowadzonych, ' . $n['pending'] . ' do akceptacji';
    }

    /** Zmiana współpracownika do kolejki „Zmiana danych” (collaborator_data_changes, status pending, źródło licencje_am5). */
    private function cdcPending(int $userId, string $field, string $old, string $new): ?int
    {
        try {
            require_once dirname(__DIR__, 2) . '/collaborator_data/lib/CollaboratorDataService.php';
            CollaboratorDataService::schema();
            $svc = new CollaboratorDataService($userId);
            $p = $svc->person();
            return db_insert('collaborator_data_changes', ['user_id' => $userId, 'person_id' => $p['id'] ?? null, 'field' => $field, 'old_value' => $old,
                'new_value' => $new, 'status' => 'pending', 'source' => 'licencje_am5', 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
        } catch (\Throwable $e) {
            error_log('am5 cdc: ' . $e->getMessage());
            return null;   // fallback: zmiana zostaje tylko w license_holder_changes (biuro zobaczy ją w module)
        }
    }

    /** Biuro: wprowadź / odrzuć zmianę oczekującą (kursant: imię/e-mail/telefon; współpracownik: imię). */
    public function decideChange(int $changeId, bool $apply, array $actor): string
    {
        $c = $this->assignments->change($changeId);
        if (!$c || $c['status'] !== 'pending') throw new DomainException('Zmiana nie czeka na decyzję.');
        if (!empty($c['cdc_id'])) throw new DomainException('Tę zmianę zatwierdza się na ekranie „Zmiana danych współpracownika” (kolejka akceptacji).');
        $a = $this->mustAssignment((int)$c['assignment_id']);
        if ($apply) {
            $col = ['name' => 'name', 'email' => 'email', 'phone' => 'phone'][$c['field']] ?? null;
            if ($c['holder_kind'] === 'client' && $col) {
                db_exec("UPDATE k30_clients SET {$col} = ?, updated_at = ? WHERE id = ?", [(string)$c['new_value'], ldNow(), (int)$c['holder_id']]);
            } elseif ($c['holder_kind'] === 'user' && $c['field'] === 'name') {
                db_exec("UPDATE users SET name = ? WHERE id = ?", [(string)$c['new_value'], (int)$c['holder_id']]);
            } elseif ($c['holder_kind'] === 'user') {
                throw new DomainException('Adres e-mail współpracownika (login) zmienia się w koncie użytkownika — tu można zmianę tylko odrzucić.');
            }
        }
        $this->assignments->decideChange($changeId, $apply, (int)$actor['id']);
        $this->licenses->event((int)$a['license_id'], 'data.' . ($apply ? 'apply' : 'reject'), ($apply ? 'Wprowadzono' : 'Odrzucono') . ' zmianę „' . $c['field'] . '”: ' . $c['new_value'], $actor, (int)$a['id']);
        return $apply ? 'Zmiana wprowadzona.' : 'Zmiana odrzucona.';
    }

    /** Weryfikacja oświadczeń (biuro): zatwierdź pod audyt MSUES albo odrzuć (osoba dostaje nowy link). */
    public function verify(int $assignmentId, bool $approve, string $note, array $actor): string
    {
        $a = $this->mustAssignment($assignmentId);
        if ($a['statusEnum'] !== LicenseAssignmentStatus::Submitted) throw new DomainException('Oświadczenia nie czekają na weryfikację.');
        if (!$approve && trim($note) === '') throw new InvalidArgumentException('Podaj powód odrzucenia — osoba go zobaczy.');
        $this->assignments->update($assignmentId, ['status' => ($approve ? LicenseAssignmentStatus::Accepted : LicenseAssignmentStatus::Rejected)->value,
            'decided_at' => ldNow(), 'decided_by' => $actor['id'] ?: null, 'decided_name' => (string)$actor['name'], 'decision_note' => $note,
            'due_on' => $approve ? (string)$a['due_on'] : ldDue()]);
        $this->assignments->setDeclarationsStatus($assignmentId, $approve ? LicenseDeclarationStatus::Approved : LicenseDeclarationStatus::Rejected);
        $this->licenses->event((int)$a['license_id'], $approve ? 'declarations.approve' : 'declarations.reject', ($approve ? 'Zatwierdzono oświadczenia (audyt MSUES).' : 'Odrzucono oświadczenia: ' . $note), $actor, $assignmentId);
        if ($approve) {
            $l = $this->mustLicense((int)$a['license_id']);
            $subject = 'Oświadczenia ' . LD_SYMBOL . ' zatwierdzone — ' . $l['title'];
            $body = '<p>Dzień dobry ' . htmlspecialchars((string)$a['holder_name']) . ',</p><p>Twoje oświadczenia do licencji na materiał <b>' . htmlspecialchars((string)$l['title'])
                . '</b> zostały zatwierdzone. Możesz korzystać z materiału w zakresie i okresie licencji (do ' . htmlspecialchars(ldDate($l['period_to'])) . ').</p>'
                . ($note !== '' ? '<p>Uwagi: ' . htmlspecialchars($note) . '</p>' : '');
            $r = $this->notify->send(LicenseNotificationType::DeclarationDecided, ['name' => (string)$a['holder_name'], 'email' => (string)$a['holder_email'], 'phone' => (string)$a['holder_phone']],
                $subject, LicenseNotificationService::layout('Oświadczenia zatwierdzone', $body), 'FEER: oswiadczenia ' . LD_SYMBOL . ' do licencji "' . mb_strimwidth((string)$l['title'], 0, 40, '...') . '" zatwierdzone.',
                ['type' => 'assignment', 'id' => $assignmentId], $actor);
            return 'Zatwierdzono. Powiadomienie: ' . $this->describe($r) . '.';
        }
        // Odrzucone: nowy link z powodem (przez invite z treścią przypomnienia)
        return 'Odrzucono. Nowy link: ' . $this->invite($assignmentId, $actor, LicenseNotificationType::DeclarationDecided) . '.';
    }

    /** Przypomnienia o terminie 7 dni (przycisk na pulpicie): przydziały „czeka na oświadczenia” po terminie lub w ostatnim dniu. */
    public function remindDue(array $actor): array
    {
        $out = ['sent' => 0, 'errors' => []];
        foreach (db_all("SELECT id FROM license_assignments WHERE status IN ('invited', 'rejected') AND due_on <> '' AND due_on <= ?", [ldToday()]) as $r) {
            try { $this->invite((int)$r['id'], $actor, LicenseNotificationType::Reminder); $out['sent']++; }
            catch (\Throwable $e) { $out['errors'][] = '#' . $r['id'] . ': ' . $e->getMessage(); }
        }
        return $out;
    }
}
