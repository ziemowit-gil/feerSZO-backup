<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/logic/LicenseAdminController.php — „Licencje dydaktyczne (AM5) i oprogramowanie (AM7)” w panelu dydaktyka.
 *
 * Tylko kadra zarządzająca (kierownik / zastępca / administrator panelu — middleware dyd.staff).
 * Pulpit (kafle Metro: pula, wnioski, po terminie 7 dni, do weryfikacji), wniosek AM5/1 (nowy, edycja, wszczęcie),
 * decyzja z papieru, zawieszenie, przydziały, weryfikacja oświadczeń, zmiany danych, dziennik i log powiadomień, puste wzory.
 * Wyniki: string = HTML, null = akcja sama wysłała odpowiedź (pliki, przekierowania).
 */
require_once dirname(__DIR__) . '/lib/LicenseManagementService.php';
require_once dirname(__DIR__) . '/lib/LicenseDeadlineAgent.php';

final class LicenseAdminController
{
    private const VIEW = __DIR__ . '/../view/';
    private LicenseManagementService $svc;

    public function __construct()
    {
        $this->svc = new LicenseManagementService();
    }

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'svc' => $this->svc]);
    }

    private function fail(\Throwable $e, string $to): ?string
    {
        $user = $e instanceof DomainException || $e instanceof InvalidArgumentException;
        flash_set('danger', $user ? $e->getMessage() : 'Błąd: ' . $e->getMessage());
        if (!$user) error_log('ld: ' . $e);
        return Router::redirect($to);
    }

    private function show404(string $what): string
    {
        http_response_code(404);
        return 'Nie znaleziono: ' . $what . '.';
    }

    // ── Pulpit ──────────────────────────────────────────────────────────────────

    public function index(): string
    {
        $f = ['q' => (string)($_GET['q'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'overdue' => !empty($_GET['overdue'])];
        $toVerify = db_all("SELECT a.*, l.title AS license_title FROM license_assignments a JOIN licenses l ON l.id = a.license_id WHERE a.status = 'submitted' ORDER BY a.submitted_at");
        $overdueAsg = db_all("SELECT a.*, l.title AS license_title FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                               WHERE a.status IN ('invited', 'rejected') AND a.due_on <> '' AND a.due_on < ? ORDER BY a.due_on", [ldToday()]);
        return $this->view('index.php', ['rows' => $this->svc->licenses->search($f), 'f' => $f, 'counts' => $this->svc->licenses->counts(),
            'toVerify' => $toVerify, 'overdueAsg' => $overdueAsg, 'alertEmails' => LicenseNotificationService::officeRecipients(),
            'alertSetting' => (string)(db_one("SELECT value FROM settings WHERE key_ = 'ld_alert_email'")['value'] ?? ''), 'agentOn' => LicenseDeadlineAgent::enabled()]);
    }

    public function remindDue(): ?string
    {
        $r = $this->svc->remindDue($this->actor());
        flash_set($r['errors'] ? 'warning' : 'success', 'Przypomnienia wysłane: ' . $r['sent'] . '.' . ($r['errors'] ? ' Problemy: ' . implode('; ', array_slice($r['errors'], 0, 5)) : ''));
        return Router::redirect(route('ld.index'));
    }

    public function settings(): ?string
    {
        $raw = trim((string)($_POST['alert_email'] ?? ''));
        $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: [])));
        foreach ($list as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) { flash_set('danger', 'Nieprawidłowy adres: ' . $m); return Router::redirect(route('ld.index')); }
        org_setting_set('ld_alert_email', implode(', ', $list));
        flash_set('success', $list ? 'Zapisano adresy alertów.' : 'Wyczyszczono — alerty pójdą na e-mail organizacji (fallback).');
        return Router::redirect(route('ld.index'));
    }

    /** Włącznik agenta CRON ti_licencje_reminders (przypomnienia o terminie 7 dni, codziennie od 7:00). */
    public function agentSettings(): ?string
    {
        $on = !empty($_POST['reminders_enabled']);
        org_setting_set(LicenseDeadlineAgent::SETTING, $on ? '1' : '0');
        flash_set('success', $on ? 'Włączono automatyczne przypomnienia — agent CRON wyśle je codziennie od 7:00.' : 'Wyłączono automatyczne przypomnienia — zostaje przycisk na pulpicie.');
        return Router::redirect(route('ld.index'));
    }

    // ── Wniosek AM5/1 ───────────────────────────────────────────────────────────

    public function createForm(array $errors = [], array $old = []): string
    {
        $courseId = (int)($old['course_id'] ?? ($_GET['kurs'] ?? 0));
        return $this->view('form.php', ['l' => null, 'errors' => $errors, 'old' => $old ?: ['applicant_name' => ldOrgName(),
            'applicant_contact' => trim((string)org_setting('org_adres') . "\n" . (string)org_setting('org_email')), 'date' => ldToday(), 'course_id' => $courseId ?: ''],
            'courses' => LicenseManagementService::courses(), 'people' => LicenseManagementService::people($courseId ?: null)]);
    }

    public function create(): ?string
    {
        try {
            $r = $this->svc->saveRequest(null, LicenseRequestDto::fromArray($_POST), $this->actor());
            if ($r['errors']) { echo $this->createForm($r['errors'], $_POST); return null; }
            if (!empty($_POST['start_now'])) return $this->start($r['id']);
            flash_set('success', 'Zapisano wniosek roboczy. Sprawdź treść i wszcznij proces.');
            return Router::redirect(route('ld.show', ['id' => $r['id']]));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.new')); }
    }

    // ── AM7 (oprogramowanie) ────────────────────────────────────────────────────────

    private function am7(): Am7Workflow
    {
        return new Am7Workflow($this->svc);
    }

    public function createAm7Form(array $errors = [], array $old = []): string
    {
        $courseId = (int)($old['course_id'] ?? ($_GET['kurs'] ?? 0));
        return $this->view('form7.php', ['l' => null, 'errors' => $errors, 'old' => $old ?: ['to' => ldOrgName(), 'date' => ldToday(), 'access' => 'tenant', 'interested_kind' => 'client', 'course_id' => $courseId ?: ''],
            'courses' => LicenseManagementService::courses(), 'people' => LicenseManagementService::people($courseId ?: null)]);
    }

    public function createAm7(): ?string
    {
        try {
            $r = $this->am7()->save(null, Am7RequestDto::fromArray($_POST), $this->actor());
            if ($r['errors']) { echo $this->createAm7Form($r['errors'], $_POST); return null; }
            if (!empty($_POST['start_now'])) return $this->start($r['id']);
            flash_set('success', 'Zapisano wniosek roboczy AM7.');
            return Router::redirect(route('ld.show', ['id' => $r['id']]));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.new7')); }
    }

    public function signLink(int $id): ?string
    {
        try { flash_set('success', 'Link do podpisu wniosku: ' . $this->am7()->sendSignLink($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function am7Prepare(int $id): ?string
    {
        try {
            $errors = $this->am7()->prepare($id, Am7DecisionDto::fromArray($_POST), $this->actor());
            if ($errors) {
                $_SESSION['ld_am7_old'] = $_POST;
                flash_set('danger', 'Projekt decyzji: ' . implode(' ', array_values($errors)));
                return Router::redirect(route('ld.show', ['id' => $id]) . '#decyzja');
            }
            unset($_SESSION['ld_am7_old']);
            flash_set('success', 'Zapisano projekt decyzji. Kierownik zatwierdza ją przyciskiem „Zatwierdź i wyślij” — dopiero wtedy decyzja idzie e-mailem w PDF.');
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]) . '#decyzja');
    }

    public function am7Approve(int $id): ?string
    {
        try { flash_set('success', 'Decyzja zatwierdzona i wysłana. ' . implode(' · ', $this->am7()->approve($id, $this->actor()))); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id]) . '#decyzja'); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function am7DecisionPdf(int $id): ?string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l || empty($l['decision'])) return $this->show404('decyzja');
        if (!empty($l['decision']['approved_at']) && LicenseStorage::fullPath((string)$l['decision_pdf'])) {
            return LicenseStorage::send((string)$l['decision_pdf'], ldFileName(LicenseDocument::number(!empty($l['decision']['grant']) ? LicenseDoc::Am7Grant : LicenseDoc::Am7Refusal, $l)));
        }
        $o = $this->am7()->decisionOutput($l);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '-projekt.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    /** Ponowna wysyłka zatwierdzonej decyzji AM7 (np. gdy pierwszy e-mail wyszedł bez PDF). */
    public function am7Resend(int $id): ?string
    {
        try { flash_set('success', $this->am7()->sendDecision($id, null, $this->actor(), true) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function am7Helpdesk(int $id): ?string
    {
        flash_set('info', 'Helpdesk IT: ' . $this->am7()->helpdeskTicket($id, $this->actor()) . '.');
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    // ── Konto w tenancie (AM7) ──────────────────────────────────────────────────

    public function am7TenantForm(int $id, array $errors = [], array $old = []): string
    {
        require_once dirname(__DIR__) . '/lib/Am7TenantAccount.php';
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) return $this->show404('wniosek AM7');
        $svc = new Am7TenantAccount($this->svc);
        $c = $svc->contact($l);
        $tenants = Am7TenantAccount::tenants();
        $skus = [];
        foreach ($tenants as $k => $t) $skus[$k] = $t['ready'] ? $svc->skus($k) : [];
        return $this->view('am7_tenant.php', ['l' => $l, 'c' => $c, 'missing' => $svc->missing($c), 'tenants' => $tenants, 'skus' => $skus, 'errors' => $errors, 'old' => $old]);
    }

    public function am7TenantCreate(int $id): ?string
    {
        require_once dirname(__DIR__) . '/lib/Am7TenantAccount.php';
        try {
            $r = (new Am7TenantAccount($this->svc))->create($id, $_POST, $this->actor());
            if (!$r['ok']) { echo $this->am7TenantForm($id, $r['errors'], $_POST); return null; }
            $msg = 'Konto ' . $r['upn'] . ' utworzone — ' . implode('; ', $r['report']) . '.';
            if (!empty($_POST['no_sms']) || !empty($r['sms_failed'])) $msg .= ' HASŁO TYMCZASOWE (pokazane raz, przekaż osobie bezpiecznym kanałem): ' . $r['password'];
            flash_set(!empty($r['sms_failed']) ? 'warning' : 'success', $msg);
            return Router::redirect(route('ld.show', ['id' => $id]));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
    }

    /** Druk AM99 (podgląd / pobranie PDF). */
    public function am99Pdf(int $id): ?string
    {
        require_once dirname(__DIR__) . '/lib/Am7TenantAccount.php';
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) return $this->show404('wniosek AM7');
        try { $o = (new Am7TenantAccount($this->svc))->am99Output($l); }
        catch (DomainException $e) { flash_set('warning', $e->getMessage()); return Router::redirect(route('ld.show', ['id' => $id])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    /** Ręczna wysyłka AM99 + zamknięcie zgłoszenia w Helpdesku. */
    public function am99Send(int $id): ?string
    {
        require_once dirname(__DIR__) . '/lib/Am7TenantAccount.php';
        try { flash_set('success', (new Am7TenantAccount($this->svc))->sendAm99($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function editForm(int $id, array $errors = [], array $old = []): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l) return $this->show404('wniosek');
        if ($l['form'] === LicenseForm::Am7) {
            return $this->view('form7.php', ['l' => $l, 'errors' => $errors, 'old' => $old ?: Am7Workflow::formValues($l), 'courses' => LicenseManagementService::courses(),
                'people' => LicenseManagementService::people($l['course_id'] ? (int)$l['course_id'] : null)]);
        }
        $old = $old ?: LicenseManagementService::formValues($l);
        return $this->view('form.php', ['l' => $l, 'errors' => $errors, 'old' => $old, 'courses' => LicenseManagementService::courses(),
            'people' => LicenseManagementService::people(((int)($old['course_id'] ?? 0)) ?: null)]);
    }

    public function update(int $id): ?string
    {
        try {
            $l = $this->svc->licenses->get($id);
            $r = $l && $l['form'] === LicenseForm::Am7 ? $this->am7()->save($id, Am7RequestDto::fromArray($_POST), $this->actor())
                                                       : $this->svc->saveRequest($id, LicenseRequestDto::fromArray($_POST), $this->actor());
            if ($r['errors']) { echo $this->editForm($id, $r['errors'], $_POST); return null; }
            if (!empty($_POST['start_now'])) return $this->start($id);
            flash_set('success', 'Zapisano zmiany wniosku.');
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function start(int $id): ?string
    {
        try {
            $r = $this->svc->start($id, $this->actor(), empty($_POST['paper_only']));
            if ($r['errors']) {
                flash_set('danger', 'Uzupełnij wniosek przed wszczęciem: ' . implode(' ', array_values($r['errors'])));
                return Router::redirect(route('ld.edit', ['id' => $id]));
            }
            flash_set('success', 'Wszczęto proces — realizacja do ' . LD_REALIZATION_DAYS . ' dni. ' . implode(' · ', $r['report']));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function show(int $id): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l) return $this->show404('licencja');
        $asg = $this->svc->assignments->forLicense($id);
        $decl = $changes = [];
        foreach ($asg as $a) { $decl[(int)$a['id']] = $this->svc->assignments->declarations((int)$a['id']); $changes[(int)$a['id']] = $this->svc->assignments->changes((int)$a['id']); }
        return $this->view('show.php', ['l' => $l, 'asg' => $asg, 'decl' => $decl, 'changes' => $changes, 'events' => $this->svc->licenses->events($id),
            'notifs' => $this->svc->licenses->notifications($id), 'people' => LicenseManagementService::people($l['course_id'] ? (int)$l['course_id'] : null),
            'canApprove' => Am7Workflow::canApprove((int)$this->actor()['id']), 'am7Old' => $_SESSION['ld_am7_old'] ?? []]);
    }

    public function resendLicensor(int $id): ?string
    {
        try { flash_set('success', 'Wniosek do licencjodawcy: ' . $this->svc->resendLicensor($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    /** Decyzja z papieru (skan/wydruk) — fallback, gdy licencjodawca nie korzysta z portalu. */
    public function decide(int $id): ?string
    {
        try {
            $r = $this->svc->decide($id, LicensorDecisionDto::fromArray($_POST), 'panel', $this->actor());
            if ($r['errors']) { flash_set('danger', 'Decyzja: ' . implode(' ', array_values($r['errors']))); return Router::redirect(route('ld.show', ['id' => $id]) . '#decyzja'); }
            flash_set('success', 'Zapisano decyzję licencjodawcy.' . ($r['report'] ? ' ' . implode(' · ', $r['report']) : ''));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function withdraw(int $id): ?string
    {
        try { $this->svc->withdraw($id, trim((string)($_POST['reason'] ?? '')), $this->actor()); flash_set('success', 'Wycofano wniosek.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function delete(int $id): ?string
    {
        try { $this->svc->deleteDraft($id, $this->actor()); flash_set('success', 'Usunięto wniosek roboczy.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.index'));
    }

    public function suspend(int $id): ?string
    {
        $on = !empty($_POST['on']);
        try { $this->svc->suspend($id, $on, trim((string)($_POST['reason'] ?? '')), $this->actor()); flash_set('success', $on ? 'Zawieszono licencję.' : 'Wznowiono licencję.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id])); }
        return Router::redirect(route('ld.show', ['id' => $id]));
    }

    public function requestPdf(int $id): ?string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l) return $this->show404('wniosek');
        // Robocze zawsze świeże; po wszczęciu — zapisany egzemplarz (ten, który dostał licencjodawca)
        $rel = (string)$l['request_pdf'];
        if ($l['requestStatus'] === LicenseRequestStatus::Draft || $rel === '' || !LicenseStorage::fullPath($rel)) $rel = $this->svc->renderRequest($id)['path'];
        return LicenseStorage::send($rel, ldFileName((string)$l['case_number'] . ' ' . $l['form']->requestDoc()->slug()));
    }

    // ── Przydziały ──────────────────────────────────────────────────────────────

    public function assign(int $id): ?string
    {
        $kind = (string)($_POST['holder_kind'] ?? 'external');
        $hid = (int)($_POST['holder_id_' . $kind] ?? 0);
        try {
            $r = $this->svc->assign($id, ['kind' => $kind, 'id' => $hid ?: null, 'name' => trim((string)($_POST['name'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')), 'phone' => trim((string)($_POST['phone'] ?? ''))], $this->actor());
            flash_set('success', 'Przydzielono licencję: ' . $r['report'] . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $id]) . '#przydzialy'); }
        return Router::redirect(route('ld.show', ['id' => $id]) . '#przydzialy');
    }

    private function asgLicense(int $assignmentId): int
    {
        return (int)($this->svc->assignments->get($assignmentId)['license_id'] ?? 0);
    }

    public function invite(int $id): ?string
    {
        $lid = $this->asgLicense($id);
        try { flash_set('success', 'Link do oświadczeń: ' . $this->svc->invite($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $lid]) . '#przydzialy'); }
        return Router::redirect(route('ld.show', ['id' => $lid]) . '#przydzialy');
    }

    public function revoke(int $id): ?string
    {
        $lid = $this->asgLicense($id);
        try { $this->svc->revoke($id, trim((string)($_POST['reason'] ?? '')), $this->actor()); flash_set('success', 'Cofnięto przydział.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $lid]) . '#przydzialy'); }
        return Router::redirect(route('ld.show', ['id' => $lid]) . '#przydzialy');
    }

    public function verify(int $id): ?string
    {
        $lid = $this->asgLicense($id);
        try { flash_set('success', $this->svc->verify($id, ($_POST['decision'] ?? '') === 'approve', trim((string)($_POST['note'] ?? '')), $this->actor())); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.show', ['id' => $lid]) . '#przydzialy'); }
        return Router::redirect(route('ld.show', ['id' => $lid]) . '#przydzialy');
    }

    public function decideChange(int $id): ?string
    {
        $c = $this->svc->assignments->change($id);
        $lid = $c ? $this->asgLicense((int)$c['assignment_id']) : 0;
        try { flash_set('success', $this->svc->decideChange($id, ($_POST['decision'] ?? '') === 'apply', $this->actor())); }
        catch (\Throwable $e) { return $this->fail($e, $lid ? route('ld.show', ['id' => $lid]) . '#przydzialy' : route('ld.index')); }
        return Router::redirect(route('ld.show', ['id' => $lid]) . '#przydzialy');
    }

    public function declarationFile(int $id): ?string
    {
        $d = $this->svc->assignments->declaration($id);
        if (!$d) return $this->show404('oświadczenie');
        return LicenseStorage::send((string)$d['file_path'], ldFileName((string)$d['number']));
    }

    /** Pusty wzór do wydruku (obieg papierowy — fallback): /wzor/AM5-1 … /wzor/AM7-5. */
    public function blank($doc): ?string
    {
        $d = LicenseDoc::fromSlug((string)$doc);
        if (!$d) return $this->show404('wzór');
        $out = LicenseDocument::render((new LicenseDocument())->blankHtml($d), $d->title(), $d->symbol() . ' — wzór', $d->form()->value);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . $d->slug() . '-wzor.' . $out['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $out['bytes'];
        return null;
    }
}
