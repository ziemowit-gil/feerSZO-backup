<?php
declare(strict_types=1);

/**
 * modules/ti_msues/logic/MsuesController.php — „Certyfikacja MSUES” w panelu dydaktyka.
 *
 * RBAC: kierownik / zastępca / administrator panelu (dyd_is_staff) — wszystko: uruchomienie, przełącznik wymagalności,
 * weryfikacja (zatwierdź / odrzuć), EZD, gotowość usług, paczki ZIP, dokumenty i oświadczenia każdej osoby.
 * Prowadzący — tylko własne: oświadczenia (tworzenie, edycja roboczych, skan, wysłanie / wycofanie), dokumenty, checklista.
 * Wyniki: string = HTML, tablica = JSON (Router), null = akcja sama wysłała odpowiedź (pliki, przekierowania).
 */
require_once dirname(__DIR__) . '/lib/MsuesComplianceService.php';
require_once dirname(__DIR__) . '/lib/DeclarationDocument.php';
require_once dirname(__DIR__) . '/lib/MsuesAuditPackage.php';
require_once dirname(__DIR__) . '/lib/MsuesEzdService.php';
require_once dirname(__DIR__) . '/lib/MsuesLaunch.php';

final class MsuesController
{
    private const VIEW = __DIR__ . '/../view/';
    private MsuesComplianceService $svc;

    public function __construct()
    {
        $this->svc = new MsuesComplianceService();
    }

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? ''), 'staff' => dyd_is_staff(), 'admin' => dyd_is_admin()];
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'svc' => $this->svc]);
    }

    /** Prowadzący widzi tylko siebie. */
    private function canTrainer(int $trainerId): bool
    {
        $a = $this->actor();
        return $a['staff'] || $a['id'] === $trainerId;
    }

    private function decl(int $id): array
    {
        $d = $this->svc->declarations()->get($id);
        if (!$d || !$this->canTrainer((int)$d['trainer_id'])) { http_response_code(404); exit('Nie znaleziono oświadczenia.'); }
        return $d;
    }

    private function back(string $fallback): ?string
    {
        $b = (string)($_POST['_back'] ?? '');
        $base = Router::current()->url('/');
        return Router::redirect($b !== '' && str_starts_with($b, $base) ? $b : $fallback);
    }

    private function fail(\Throwable $e, string $to): ?string
    {
        flash_set('danger', $e instanceof DomainException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Błąd: ' . $e->getMessage());
        if (!($e instanceof DomainException || $e instanceof InvalidArgumentException)) error_log('msues: ' . $e);
        return Router::redirect($to);
    }

    // ── Kierownik: pulpit, uruchomienie, ustawienia ─────────────────────────────

    public function index(): string
    {
        $services = MsuesSzoData::services();
        $repo = $this->svc->declarations();
        return $this->view('index.php', [
            'services' => $services,
            'overview' => $this->svc->servicesOverview($services),
            'trainers' => MsuesSzoData::trainers(),
            'candidates' => MsuesSzoData::trainerCandidates(),
            'pending'  => $repo->search(['status' => DeclarationStatus::Pending->value]),
            'counts'   => $repo->counts(),
            'launched' => MsuesLaunch::launched(),
            'recipients' => MsuesLaunch::recipients($repo, false),
            'ezdActive' => MsuesEzdService::active(),
        ]);
    }

    public function launch(): ?string
    {
        $a = $this->actor();
        try {
            $r = MsuesLaunch::launch($a, (string)($_POST['deadline'] ?? ''), !empty($_POST['send_mail']), !empty($_POST['create_drafts']), !empty($_POST['enforce']), $this->svc);
            flash_set('success', 'Certyfikacja MSUES uruchomiona. E-maile informacyjne w kolejce: ' . $r['mails'] . ($r['skipped'] ? ' (pominięto ' . $r['skipped'] . ')' : '')
                . '; nowe robocze oświadczenia: ' . $r['drafts'] . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('msues.index')); }
        return Router::redirect(route('msues.index'));
    }

    public function remind(): ?string
    {
        $a = $this->actor();
        [$n, $skip] = MsuesLaunch::sendInfo($a, $this->svc->declarations(), true);
        flash_set('success', 'Przypomnienie w kolejce e-maili: ' . $n . ' os. (bez osób z zatwierdzonym oświadczeniem)' . ($skip ? ', pominięto ' . $skip : '') . '.');
        return Router::redirect(route('msues.index'));
    }

    public function mailPreview(): string
    {
        $a = $this->actor();
        [$subject, $html] = MsuesLaunch::mail('Jan Przykładowy', $a['name']);
        return '<!doctype html><meta charset="utf-8"><title>' . h($subject) . '</title><div style="padding:16px;background:#eef1f5"><p style="font:13px Arial"><b>Temat:</b> ' . h($subject) . '</p>' . $html . '</div>';
    }

    public function toggle(): ?string
    {
        $on = !MsuesComplianceService::declarationRequired();
        MsuesComplianceService::setDeclarationRequired($on, $this->actor());
        flash_set('success', $on ? 'Włączono wymóg formalnego oświadczenia: bez zatwierdzonego oświadczenia prowadzący nie zostanie przypisany do usługi, a paczka audytowa się nie wygeneruje.'
                                 : 'Wyłączono wymóg formalnego oświadczenia — braki są tylko ostrzeżeniami.');
        return $this->back(route('msues.index'));
    }

    public function settings(): ?string
    {
        $a = $this->actor();
        $sym = fn(string $k) => preg_match('/^[0-9A-Za-z.\-]{1,16}$/', trim((string)($_POST[$k] ?? ''))) ? trim((string)$_POST[$k]) : '';
        org_setting_set('msues_ezd_jrwa', $sym('ezd_jrwa'));
        org_setting_set('msues_ezd_jrwa_audit', $sym('ezd_jrwa_audit'));
        org_setting_set('msues_ezd_auto', !empty($_POST['ezd_auto']) ? '1' : '0');
        if (MsuesEzdService::active() && !empty($_POST['ensure_jrwa'])) {
            try { $n = MsuesEzdService::ensureJrwa($a['id']); flash_set('success', 'Zapisano ustawienia. Dodane hasła JRWA: ' . $n . '.'); return Router::redirect(route('msues.index')); }
            catch (\Throwable $e) { return $this->fail($e, route('msues.index')); }
        }
        flash_set('success', 'Zapisano ustawienia MSUES.');
        return Router::redirect(route('msues.index'));
    }

    // ── Usługi: gotowość (HTML + JSON), paczka ZIP ─────────────────────────────

    public function service(int $id): string
    {
        try { $r = $this->svc->serviceReadiness($id); } catch (DomainException $e) { http_response_code(404); return $e->getMessage(); }
        return $this->view('service.php', ['r' => $r, 'ezdActive' => MsuesEzdService::active(), 'audits' => $this->auditCases($id)]);
    }

    public function serviceJson(int $id): array
    {
        try {
            $r = $this->svc->serviceReadiness($id);
        } catch (DomainException $e) {
            http_response_code(404);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $r['staff'] = array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'required' => $p['required'], 'done' => $p['done'], 'complete' => $p['complete'],
            'readiness' => $p['readiness'],
            'items' => array_map(fn($i) => ['code' => $i['code'], 'label' => $i['label'], 'required' => $i['required'], 'ok' => $i['ok'], 'detail' => $i['detail']], $p['items']),
        ], $r['staff']);
        return ['ok' => true, 'generated_at' => date('c')] + $r;
    }

    public function trainerJson(): array
    {
        $tid = (int)($_GET['trainer'] ?? 0);
        $sid = (int)($_GET['service'] ?? 0);
        if (!$tid || !$this->canTrainer($tid)) { http_response_code(403); return ['ok' => false, 'error' => 'Brak dostępu.']; }
        return ['ok' => true, 'trainer_id' => $tid, 'service_id' => $sid] + $this->svc->validateTrainerReadiness($tid, $sid)->toArray();
    }

    public function package(int $id): ?string
    {
        try {
            $p = (new MsuesAuditPackage($this->svc))->build($id, $this->actor());
        } catch (\Throwable $e) { return $this->fail($e, route('msues.service', ['id' => $id])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($p['path']));
        header('Content-Disposition: attachment; filename="' . $p['name'] . '"');
        header('Cache-Control: no-store, private');
        readfile($p['path']);
        @unlink($p['path']);
        return null;
    }

    public function packageEzd(int $id): ?string
    {
        $a = $this->actor();
        try {
            $p = (new MsuesAuditPackage($this->svc))->build($id, $a);
            try { $sid = MsuesEzdService::archiveAuditPackage($id, $p['path'], $p['name'], $p['readiness'], $a); }
            finally { @unlink($p['path']); }
            flash_set('success', 'Paczkę audytową odłożono do koszulki EZD ' . (ezd_sprawa_get($sid)['znak_sprawy'] ?? '#' . $sid) . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('msues.service', ['id' => $id])); }
        return Router::redirect(route('msues.service', ['id' => $id]));
    }

    private function auditCases(int $serviceId): array
    {
        if (!MsuesEzdService::active()) return [];
        try { require_once dirname(__DIR__, 3) . '/includes/ezd.php'; return ezd_sprawy_by_ref(MsuesEzdService::REF_AUDIT, $serviceId); } catch (\Throwable $e) { return []; }
    }

    // ── Kadra: checklista, dokumenty ────────────────────────────────────────────

    public function mine(): string
    {
        return $this->trainer($this->actor()['id']);
    }

    public function trainer(int $id): string
    {
        if (!$this->canTrainer($id)) { http_response_code(403); return 'Brak dostępu.'; }
        $u = MsuesSzoData::user($id);
        if (!$u) { http_response_code(404); return 'Nie ma takiej osoby.'; }
        $services = MsuesSzoData::trainerServices($id);
        $check = [];
        foreach ($services ?: [['id' => 0, 'name' => '']] as $s) $check[(int)$s['id']] = MsuesChecklist::evaluate($id, (int)$s['id'] ?: null, $this->svc->declarations(), $this->svc->documents());
        return $this->view('trainer.php', [
            'u' => $u, 'services' => $services, 'check' => $check,
            'declarations' => $this->svc->declarations()->forTrainer($id),
            'documents' => $this->svc->documents()->list($id),
            'contracts' => MsuesSzoData::contracts($id),
            'launched' => MsuesLaunch::launched(),
        ]);
    }

    public function documentAdd(int $id): ?string
    {
        if (!$this->canTrainer($id)) { http_response_code(403); return 'Brak dostępu.'; }
        $to = route('msues.trainer', ['id' => $id]);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash_set('danger', 'Wybierz plik do wgrania.'); return $this->back($to); }
        try {
            $this->svc->documents()->add($id, (string)($_POST['kind'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['issued_on'] ?? ''), (string)($_POST['valid_to'] ?? ''),
                (string)$f['tmp_name'], (string)$f['name'], $this->actor());
            flash_set('success', 'Dodano dokument.');
        } catch (\Throwable $e) { return $this->fail($e, $to); }
        return $this->back($to);
    }

    public function documentGet(int $id): ?string
    {
        $d = $this->svc->documents()->get($id);
        if (!$d || !$this->canTrainer((int)$d['trainer_id'])) { http_response_code(404); return 'Nie znaleziono.'; }
        return MsuesStorage::send((string)$d['file_path'], (string)$d['file_name'], (string)$d['mime']);
    }

    public function documentDelete(int $id): ?string
    {
        $d = $this->svc->documents()->get($id);
        if (!$d || !$this->canTrainer((int)$d['trainer_id'])) { http_response_code(404); return 'Nie znaleziono.'; }
        $this->svc->documents()->delete($id, $this->actor());
        flash_set('success', 'Usunięto dokument.');
        return $this->back(route('msues.trainer', ['id' => (int)$d['trainer_id']]));
    }

    // ── Oświadczenia ────────────────────────────────────────────────────────────

    public function declarations(): string
    {
        $f = ['status' => (string)($_GET['status'] ?? ''), 'q' => (string)($_GET['q'] ?? ''), 'course' => (int)($_GET['course'] ?? 0)];
        return $this->view('declarations.php', ['f' => $f, 'rows' => $this->svc->declarations()->search($f, $f['status'] === 'superseded'),
            'counts' => $this->svc->declarations()->counts(), 'services' => MsuesSzoData::services(false)]);
    }

    public function create(): ?string
    {
        $a = $this->actor();
        $tid = $a['staff'] ? (int)($_POST['trainer_id'] ?? 0) : $a['id'];
        $cid = (int)($_POST['course_id'] ?? 0) ?: null;
        if (!$tid || !$this->canTrainer($tid)) { flash_set('danger', 'Wybierz prowadzącego.'); return $this->back(route('msues.index')); }
        if ($cid && !$a['staff'] && !in_array($cid, array_map(fn($s) => (int)$s['id'], MsuesSzoData::trainerServices($tid)), true)) $cid = null;
        try {
            $id = $this->svc->declarations()->create($tid, $cid, (string)($_POST['project'] ?? ''), DeclarationForm::prefill($tid, $cid, $this->svc->documents()->list($tid)), $a);
        } catch (\Throwable $e) { return $this->fail($e, route('msues.trainer', ['id' => $tid])); }
        flash_set('success', 'Utworzono oświadczenie — dane z SZO wpisano automatycznie, sprawdź i uzupełnij.');
        return Router::redirect(route('msues.decl', ['id' => $id]));
    }

    public function show(int $id): string
    {
        $d = $this->decl($id);
        $repo = $this->svc->declarations();
        return $this->view('declaration.php', ['d' => $d, 'versions' => $repo->versions((int)$d['series_id']), 'events' => $repo->events((int)$d['series_id']),
            'ezdActive' => MsuesEzdService::active(), 'readiness' => $this->svc->validateTrainerReadiness((int)$d['trainer_id'], (int)($d['course_id'] ?? 0))]);
    }

    public function save(int $id): ?string
    {
        $d = $this->decl($id);
        [$data, $err] = DeclarationForm::normalize($_POST);
        if ($err) { flash_set('danger', implode(' ', $err)); return Router::redirect(route('msues.decl', ['id' => $id])); }
        try {
            $this->svc->declarations()->update($id, $data, (string)($_POST['project'] ?? $d['project']), $this->actor());
            if (($_POST['then'] ?? '') === 'submit') {
                $this->svc->declarations()->transition($id, DeclarationStatus::Pending, $this->actor());
                flash_set('success', 'Zapisano i wysłano do weryfikacji.');
            } else {
                flash_set('success', 'Zapisano oświadczenie.');
            }
        } catch (\Throwable $e) { return $this->fail($e, route('msues.decl', ['id' => $id])); }
        return Router::redirect(route('msues.decl', ['id' => $id]));
    }

    public function status(int $id): ?string
    {
        $this->decl($id);
        $a = $this->actor();
        $to = DeclarationStatus::tryFrom((string)($_POST['to'] ?? ''));
        $back = route('msues.decl', ['id' => $id]);
        if (!$to) { flash_set('danger', 'Nieznany status.'); return $this->back($back); }
        try {
            $this->svc->declarations()->transition($id, $to, $a, (string)($_POST['note'] ?? ''));
            $msg = 'Status: „' . $to->label() . '”.';
            if ($to === DeclarationStatus::Approved && MsuesEzdService::active() && MsuesEzdService::autoOnApprove()) {
                try {
                    $sid = MsuesEzdService::registerDeclaration($this->svc->declarations(), $id, $a);
                    $msg .= ' Zarejestrowano w EZD (koszulka ' . (ezd_sprawa_get($sid)['znak_sprawy'] ?? '#' . $sid) . ').';
                } catch (\Throwable $e) { $msg .= ' Nie udało się zarejestrować w EZD: ' . $e->getMessage(); }
            }
            flash_set('success', $msg);
        } catch (\Throwable $e) { return $this->fail($e, $back); }
        return $this->back($back);
    }

    public function newVersion(int $id): ?string
    {
        $this->decl($id);
        try { $nid = $this->svc->declarations()->newVersion($id, $this->actor()); }
        catch (\Throwable $e) { return $this->fail($e, route('msues.decl', ['id' => $id])); }
        flash_set('success', 'Utworzono nową wersję roboczą — poprzednia zatwierdzona obowiązuje do zatwierdzenia nowej.');
        return Router::redirect(route('msues.decl', ['id' => $nid]));
    }

    public function delete(int $id): ?string
    {
        $d = $this->decl($id);
        try { $this->svc->declarations()->delete($id, $this->actor()); }
        catch (\Throwable $e) { return $this->fail($e, route('msues.decl', ['id' => $id])); }
        flash_set('success', 'Usunięto roboczą wersję oświadczenia.');
        return Router::redirect(route('msues.trainer', ['id' => (int)$d['trainer_id']]));
    }

    public function scanUpload(int $id): ?string
    {
        $this->decl($id);
        $f = $_FILES['scan'] ?? null;
        $back = route('msues.decl', ['id' => $id]);
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash_set('danger', 'Wybierz plik skanu (PDF, JPG, PNG).'); return Router::redirect($back); }
        try { $this->svc->declarations()->attachScan($id, (string)$f['tmp_name'], (string)$f['name'], $this->actor()); flash_set('success', 'Wgrano skan podpisanego oświadczenia.'); }
        catch (\Throwable $e) { return $this->fail($e, $back); }
        return Router::redirect($back);
    }

    public function scanGet(int $id): ?string
    {
        $d = $this->decl($id);
        if ($d['scan_path'] === '') { http_response_code(404); return 'Brak skanu.'; }
        $ext = strtolower(pathinfo((string)$d['scan_name'], PATHINFO_EXTENSION));
        return MsuesStorage::send((string)$d['scan_path'], (string)$d['scan_name'], $ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    }

    public function pdf(int $id): ?string
    {
        $d = $this->decl($id);
        $bytes = DeclarationDocument::pdf($d);
        $this->svc->declarations()->setPdfHash($id, hash('sha256', $bytes));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . DeclarationForm::filename($d, 'pdf') . '"');
        header('Cache-Control: no-store, private');
        echo $bytes;
        return null;
    }

    public function docx(int $id): ?string
    {
        $d = $this->decl($id);
        $bytes = DeclarationDocument::docx($d);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . DeclarationForm::filename($d, 'docx') . '"');
        header('Cache-Control: no-store, private');
        echo $bytes;
        return null;
    }

    public function ezd(int $id): ?string
    {
        $this->decl($id);
        try {
            $sid = MsuesEzdService::registerDeclaration($this->svc->declarations(), $id, $this->actor());
            flash_set('success', 'Zarejestrowano w EZD — koszulka ' . (ezd_sprawa_get($sid)['znak_sprawy'] ?? '#' . $sid) . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('msues.decl', ['id' => $id])); }
        return Router::redirect(route('msues.decl', ['id' => $id]));
    }
}
