<?php
declare(strict_types=1);

/**
 * modules/ti_dop/logic/DopController.php — „Finanse › Dokumenty rozliczeniowe” w panelu dydaktyka: lista, nowy wniosek DOP1 / DOP2
 * (podpis przez link albo wersja papierowa ze skanem), karta wniosku ze ścieżką obsługi, wydruk, puste wzory.
 */
require_once dirname(__DIR__) . '/lib/DopService.php';
require_once dirname(__DIR__) . '/lib/RopStatement.php';
require_once dirname(__DIR__) . '/lib/SettlementDocs.php';
require_once dirname(__DIR__) . '/lib/AutoRm.php';
require_once dirname(__DIR__) . '/lib/RateReport.php';
require_once dirname(__DIR__) . '/lib/Dop5Statement.php';

final class DopController
{
    private const VIEW = __DIR__ . '/../view/';
    private DopService $svc;

    public function __construct()
    {
        $this->svc = new DopService();
    }

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'svc' => $this->svc, 'canApprove' => DopService::canApprove((int)$this->actor()['id'])]);
    }

    private function req(int $id): array
    {
        $r = $this->svc->get($id);
        if (!$r) { http_response_code(404); exit('Nie ma takiego wniosku.'); }
        return $r;
    }

    private function fail(\Throwable $e, string $to): ?string
    {
        $user = $e instanceof DomainException || $e instanceof InvalidArgumentException;
        flash_set('danger', $user ? $e->getMessage() : 'Błąd: ' . $e->getMessage());
        if (!$user) error_log('dop: ' . $e);
        return Router::redirect($to);
    }

    private function send(array $out, string $name): ?string
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) . '.' . $out['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $out['bytes'];
        return null;
    }

    public function index(): string
    {
        $f = ['status' => (string)($_GET['status'] ?? ''), 'form' => (string)($_GET['form'] ?? ''), 'q' => (string)($_GET['q'] ?? ''), 'client' => (int)($_GET['klient'] ?? 0)];
        return $this->view('dop_list.php', ['list' => $this->svc->search($f), 'f' => $f, 'counts' => $this->svc->counts()]);
    }

    /** Strona startowa „Dokumenty rozliczeniowe uczestnika”: kafle Metro wg grup dokumentów + kolejka „Do zrobienia”. */
    public function hub(): string
    {
        $cnt = fn(string $sql, array $p = []) => (int)(db_one($sql, $p)['c'] ?? 0);
        settleMigrate(); dop5Migrate(); ropMigrate();
        $c = ['dop1' => $cnt("SELECT COUNT(*) c FROM dop_requests WHERE form='DOP1' AND status IN ('signing','verify','approve','execute')"),
              'dop2' => $cnt("SELECT COUNT(*) c FROM dop_requests WHERE form='DOP2' AND status IN ('signing','verify','approve','execute')"),
              'dop5' => $cnt("SELECT COUNT(*) c FROM dop5_statements WHERE status='signing'"),
              'rop'  => $cnt("SELECT COUNT(*) c FROM rop_statements WHERE issued_at >= ?", [date('Y-m-d', strtotime('-30 days'))])];
        foreach (SettleKind::cases() as $k) $c[$k->value] = $cnt("SELECT COUNT(*) c FROM settlement_docs WHERE kind = ? AND status = 'pending'", [$k->value]);
        $todo = [];
        foreach ($this->svc->search() as $r) if (in_array($r['status'], ['verify', 'approve', 'execute', 'signing'], true))
            $todo[] = ['sym' => $r['form'], 'color' => $r['formEnum']->color(), 'number' => $r['number'], 'who' => $r['client_name'], 'what' => $r['statusEnum']->label(), 'due' => $r['due_on'], 'url' => route('dop.show', ['id' => $r['id']])];
        foreach (SettlementDocs::search(['status' => 'pending']) as $r) $todo[] = ['sym' => $r['kind'], 'color' => SettleKind::from($r['kind'])->color(), 'number' => $r['number'], 'who' => $r['client_name'], 'what' => 'czeka na zatwierdzenie', 'due' => '', 'url' => route('settle.show', ['id' => $r['id']])];
        foreach (Dop5Statement::search() as $r) if ($r['status'] === 'signing') $todo[] = ['sym' => 'DOP5', 'color' => '#1d1d1d', 'number' => $r['number'], 'who' => ldOrgName(), 'what' => 'czeka na podpisy', 'due' => '', 'url' => route('dop5.show', ['id' => $r['id']])];
        return $this->view('hub.php', ['c' => $c, 'todo' => $todo, 'ropAuto' => RopStatement::autoEnabled()]);
    }

    public function createForm(array $errors = [], ?array $old = null): string
    {
        $form = DopForm::tryFrom((string)($_GET['form'] ?? $_POST['form'] ?? '')) ?? DopForm::Dop1;
        $cid = (int)($_GET['klient'] ?? $_POST['klient'] ?? 0);
        $c = $cid ? DopService::client($cid) : null;
        return $this->view('form.php', ['form' => $form, 'c' => $c, 'clients' => $c ? [] : DopService::clients((string)($_GET['q'] ?? '')), 'q' => (string)($_GET['q'] ?? ''),
            'errors' => $errors, 'old' => $old ?? ($c ? DopService::defaults($form, $c, $this->actor()) + ['place' => (string)(org_setting('org_miasto') ?: ''), 'sign_mode' => 'link'] : [])]);
    }

    public function create(): ?string
    {
        $form = DopForm::tryFrom((string)($_POST['form'] ?? '')) ?? DopForm::Dop1;
        try {
            $r = $this->svc->create($form, (int)($_POST['klient'] ?? 0), $_POST, (string)($_POST['sign_mode'] ?? 'link'), $this->actor(), $_FILES['skan'] ?? null);
        } catch (\Throwable $e) { return $this->fail($e, route('dop.index')); }
        if ($r['errors']) { echo $this->createForm($r['errors'], $_POST); return null; }
        flash_set('success', 'Złożono wniosek. ' . implode(' · ', $r['report']));
        return Router::redirect(route('dop.show', ['id' => $r['id']]));
    }

    public function show(int $id): string
    {
        $r = $this->req($id);
        return $this->view('show.php', ['r' => $r, 'c' => DopService::client((int)$r['client_id']), 'events' => $this->svc->events($id),
            'notifs' => db_all("SELECT * FROM notification_logs WHERE module = 'ti_dop' AND ref_type = 'dop' AND ref_id = ? ORDER BY id DESC", [$id])]);
    }

    public function pdf(int $id): ?string
    {
        $r = $this->req($id);
        return $this->send($this->svc->document($r), (string)$r['number']);
    }

    public function scan(int $id): ?string
    {
        $r = $this->req($id);
        $p = DopService::fullPath((string)$r['scan_path']);
        if (!$p) { http_response_code(404); return 'Brak skanu.'; }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][pathinfo($p, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        readfile($p);
        return null;
    }

    public function scanUpload(int $id): ?string
    {
        try {
            if (($_FILES['skan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Wybierz plik skanu.');
            flash_set('success', $this->svc->uploadScan($id, $_FILES['skan'], $this->actor()) . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id])); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    public function signLink(int $id): ?string
    {
        try { flash_set('success', 'Link do podpisu: ' . $this->svc->sendSignLink($id, $this->actor(), true) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id])); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    public function verify(int $id): ?string
    {
        try {
            $err = $this->svc->verify($id, $_POST, $this->actor());
            if ($err) { flash_set('danger', implode(' ', $err)); return Router::redirect(route('dop.show', ['id' => $id]) . '#obsluga'); }
            flash_set('success', 'Zapisano weryfikację.');
        } catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id])); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    public function approve(int $id): ?string
    {
        try { $this->svc->approve($id, ($_POST['decision'] ?? '') === 'yes', trim((string)($_POST['uwagi'] ?? '')), $this->actor()); flash_set('success', 'Zapisano decyzję.'); }
        catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id])); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    public function execute(int $id): ?string
    {
        try { flash_set('success', $this->svc->execute($id, (string)($_POST['data_przelewu'] ?? ''), (string)($_POST['transakcja'] ?? ''), $this->actor())); }
        catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id]) . '#obsluga'); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    public function cancel(int $id): ?string
    {
        try { $this->svc->cancel($id, trim((string)($_POST['reason'] ?? '')), $this->actor()); flash_set('success', 'Wycofano wniosek.'); }
        catch (\Throwable $e) { return $this->fail($e, route('dop.show', ['id' => $id])); }
        return Router::redirect(route('dop.show', ['id' => $id]));
    }

    // ── DOP5 — oświadczenie o stanie środków finansowych ────────────────────────

    public function dop5Index(): string
    {
        return $this->view('dop5_index.php', ['list' => Dop5Statement::search()]);
    }

    public function dop5Form(array $errors = [], ?array $old = null): string
    {
        $dop2 = db_all("SELECT number FROM dop_requests WHERE form = 'DOP2' AND status NOT IN ('rejected','cancelled') ORDER BY id DESC LIMIT 50");
        return $this->view('dop5_form.php', ['errors' => $errors, 'old' => $old ?? ['place' => Dop5Statement::org()['city'], 'as_of' => ldToday(), 'sign_mode' => 'link'] + array_combine(array_map(fn($k) => 'org_' . $k, array_keys(Dop5Statement::org())), array_values(Dop5Statement::org())),
            'suggest' => Dop5Statement::suggestions(), 'dop2' => array_column($dop2, 'number'), 'orgAccounts' => (function () { try { require_once dirname(__DIR__, 3) . '/includes/karty30.php'; return k30_ti_org_accounts_list(); } catch (\Throwable $e) { return []; } })()]);
    }

    public function dop5Create(): ?string
    {
        try {
            $r = Dop5Statement::create($_POST, $_FILES, (string)($_POST['sign_mode'] ?? 'link'), $this->actor());
            if ($r['errors']) { echo $this->dop5Form($r['errors'], $_POST); return null; }
            flash_set('success', 'Utworzono DOP5. ' . implode(' · ', $r['report']));
            return Router::redirect(route('dop5.show', ['id' => $r['id']]));
        } catch (\Throwable $e) { return $this->fail($e, route('dop5.index')); }
    }

    public function dop5Show(int $id): string
    {
        $r = Dop5Statement::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego DOP5.'; }
        return $this->view('dop5_show.php', ['r' => $r]);
    }

    public function dop5Pdf(int $id): ?string
    {
        $r = Dop5Statement::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego DOP5.'; }
        return $this->send(Dop5Statement::render($r), (string)$r['number']);
    }

    public function dop5Blank(): ?string
    {
        return $this->send(Dop5Statement::blank(), 'DOP5-wzor');
    }

    public function dop5File(int $id, $i): ?string
    {
        $r = Dop5Statement::get($id);
        $f = $r['filesList'][(int)$i] ?? null;
        $p = $f ? DopService::fullPath((string)$f['path']) : (($i === 'skan' && $r) ? DopService::fullPath((string)$r['scan_path']) : null);
        if (!$p) { http_response_code(404); return 'Brak pliku.'; }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][pathinfo($p, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        readfile($p);
        return null;
    }

    public function dop5Scan(int $id): ?string
    {
        try {
            if (($_FILES['skan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Wybierz plik skanu.');
            Dop5Statement::uploadScan($id, $_FILES['skan'], $this->actor());
            flash_set('success', 'Zapisano skan podpisanego DOP5.');
        } catch (\Throwable $e) { return $this->fail($e, route('dop5.show', ['id' => $id])); }
        return Router::redirect(route('dop5.show', ['id' => $id]));
    }

    public function dop5Link(int $id, int $sid): ?string
    {
        try { flash_set('success', 'Link do podpisu: ' . Dop5Statement::sendLink($sid, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('dop5.show', ['id' => $id])); }
        return Router::redirect(route('dop5.show', ['id' => $id]));
    }

    public function dop5Cancel(int $id): ?string
    {
        Dop5Statement::cancel($id);
        flash_set('success', 'Wycofano DOP5.');
        return Router::redirect(route('dop5.show', ['id' => $id]));
    }

    // ── RKO / PRZ / PRZ-P — dokumenty rozliczeniowe ─────────────────────────────

    public function settleIndex(): string
    {
        $f = ['client' => (int)($_GET['klient'] ?? 0), 'kind' => (string)($_GET['kind'] ?? ''), 'status' => (string)($_GET['status'] ?? '')];
        return $this->view('settle_index.php', ['list' => SettlementDocs::search($f), 'f' => $f, 'autoRm' => $f['kind'] === 'RM' ? AutoRm::enabledList() : []]);
    }

    /** Raport kierownika: godziny i kwota na koncie → stawka godzinowa. */
    public function rates(): ?string
    {
        if (!DopService::canApprove((int)$this->actor()['id'])) { http_response_code(403); return 'Raport stawek jest dostępny dla kierownika i administratora.'; }
        $f = ['year' => isset($_GET['rok']) ? max(0, (int)$_GET['rok']) : (int)date('Y'), 'month' => min(12, max(0, (int)($_GET['mies'] ?? 0))), 'q' => trim((string)($_GET['q'] ?? '')), 'diff' => !empty($_GET['roznice'])];
        $rep = RateReport::build($f);
        if ($f['diff']) $rep['rows'] = array_values(array_filter($rep['rows'], fn($r) => $r['diff']));
        if (!empty($_GET['csv'])) return $this->send(['mime' => 'text/csv; charset=utf-8', 'ext' => 'csv', 'bytes' => RateReport::csv($rep)], 'stawki-godzinowe-' . ($f['year'] ?: 'wszystkie') . ($f['month'] ? '-' . $f['month'] : ''));
        return $this->view('rates.php', ['rep' => $rep, 'f' => $f]);
    }

    /** Włączenie / wyłączenie automatycznego RM co miesiąc (na prośbę uczestnika). */
    public function autoRmSave(): ?string
    {
        $cid = (int)($_POST['klient'] ?? 0);
        $on = ($_POST['enabled'] ?? '') === '1';
        try {
            AutoRm::set($cid, $on, (string)($_POST['requested_on'] ?? ''), (string)($_POST['request_note'] ?? ''), $this->actor());
            flash_set('success', $on ? 'Włączono automatyczne RM — od ' . AutoRm::DAY . '. dnia każdego miesiąca za poprzedni miesiąc, z wysyłką do uczestnika i rodzica.' : 'Wyłączono automatyczne RM.');
        } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
        $back = (string)($_POST['back'] ?? '');
        return Router::redirect($back === 'index' ? route('settle.index', ['kind' => 'RM']) : route('settle.new', ['kind' => 'RM', 'klient' => $cid]));
    }

    public function settleForm(array $errors = [], ?array $old = null): string
    {
        $kind = SettleKind::tryFrom((string)($_GET['kind'] ?? $_POST['kind'] ?? '')) ?? SettleKind::Rko;
        $cid = (int)($_GET['klient'] ?? $_POST['klient'] ?? 0);
        $c = $cid ? DopService::client($cid) : null;
        $courses = $c ? SettlementDocs::courses($cid) : [];
        return $this->view('settle_form.php', ['kind' => $kind, 'c' => $c, 'courses' => $courses, 'clients' => $c ? [] : DopService::clients((string)($_GET['q'] ?? '')), 'q' => (string)($_GET['q'] ?? ''),
            'errors' => $errors, 'old' => $old ?? ['course_id' => (string)($_GET['grupa'] ?? ''), 'as_of' => ldToday(), 'mode' => 'approval', 'scope' => 'group'],
            'autoRm' => $c && $kind === SettleKind::Rm ? AutoRm::get($cid) : null,
            'pfron' => $c && $kind === SettleKind::Pfr1 && function_exists('k30_pfron_contracts_for_client') ? k30_pfron_contracts_for_client($cid) : [],
            'baselines' => $c ? array_map(fn($x) => (int)$x['course_id'], SettlementDocs::search(['client' => $cid, 'kind' => 'PRZ-P', 'status' => 'applied'])) : [], 'requests' => $c ? RopStatement::requests($cid) : []]);
    }

    public function settleCreate(): ?string
    {
        $kind = SettleKind::tryFrom((string)($_POST['kind'] ?? '')) ?? SettleKind::Rko;
        try {
            $r = SettlementDocs::create($kind, (int)($_POST['klient'] ?? 0), $_POST, (string)($_POST['mode'] ?? 'approval'), $this->actor());
            if ($r['errors']) { echo $this->settleForm($r['errors'], $_POST); return null; }
            flash_set('success', 'Wystawiono ' . $kind->value . '. ' . implode(' · ', $r['report']));
            return Router::redirect(route('settle.show', ['id' => $r['id']]));
        } catch (\Throwable $e) { return $this->fail($e, route('settle.index')); }
    }

    public function settleShow(int $id): string
    {
        $r = SettlementDocs::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego dokumentu.'; }
        return $this->view('settle_show.php', ['r' => $r]);
    }

    public function settlePdf(int $id): ?string
    {
        $r = SettlementDocs::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego dokumentu.'; }
        $p = DopService::fullPath((string)$r['pdf_path']);
        if ($p) { while (ob_get_level() > 0) ob_end_clean(); header('Content-Type: application/pdf'); header('Cache-Control: no-store, private'); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['number']) . '.pdf"'); readfile($p); return null; }
        return $this->send(SettlementDocs::render($r), (string)$r['number']);
    }

    public function settleDecide(int $id): ?string
    {
        try { flash_set('success', implode(' ', SettlementDocs::decide($id, ($_POST['decision'] ?? '') === 'approve', trim((string)($_POST['note'] ?? '')), $this->actor()))); }
        catch (\Throwable $e) { return $this->fail($e, route('settle.show', ['id' => $id])); }
        return Router::redirect(route('settle.show', ['id' => $id]));
    }

    public function settleSend(int $id): ?string
    {
        try { flash_set('success', 'Wysłano: ' . SettlementDocs::send($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('settle.show', ['id' => $id])); }
        return Router::redirect(route('settle.show', ['id' => $id]));
    }

    // ── ROP — stan subkonta na dzień wpłaty ─────────────────────────────────────

    public function ropIndex(): string
    {
        $cid = (int)($_GET['klient'] ?? 0);
        $c = $cid ? DopService::client($cid) : null;
        $payments = $c ? db_all("SELECT id, amount, paid_at, note FROM k30_ti_payments WHERE client_id = ? AND amount > 0 AND method NOT IN ('internal','refund') ORDER BY COALESCE(paid_at, date(created_at)) DESC, id DESC LIMIT 30", [$cid]) : [];
        return $this->view('rop.php', ['list' => RopStatement::search(['client' => $cid, 'q' => (string)($_GET['q'] ?? '')]), 'c' => $c, 'payments' => $payments,
            'requests' => $c ? RopStatement::requests($cid) : [], 'clients' => $c ? [] : DopService::clients((string)($_GET['q'] ?? '')), 'q' => (string)($_GET['q'] ?? ''), 'auto' => RopStatement::autoEnabled()]);
    }

    public function ropCreate(): ?string
    {
        $cid = (int)($_POST['klient'] ?? 0);
        $pids = array_values(array_filter(array_map('intval', (array)($_POST['payments'] ?? []))));
        $date = (string)($_POST['as_of'] ?? '');
        try {
            if ($pids && $date === '') $date = (string)(db_one("SELECT MAX(COALESCE(paid_at, date(created_at))) AS d FROM k30_ti_payments WHERE id IN (" . implode(',', array_fill(0, count($pids), '?')) . ")", $pids)['d'] ?? '');
            $r = RopStatement::create($cid, $date, $pids, (string)($_POST['request_number'] ?? ''), $this->actor(), false);
            flash_set('success', 'Wystawiono ' . $r['number'] . '. Wysyłka: ' . $r['report'] . '.');
            return Router::redirect(route('rop.show', ['id' => $r['id']]));
        } catch (\Throwable $e) { return $this->fail($e, route('rop.index', ['klient' => $cid])); }
    }

    public function ropAuto(): ?string
    {
        $on = !RopStatement::autoEnabled();
        org_setting_set('rop_auto_enabled', $on ? '1' : '0');
        flash_set('success', $on ? 'Włączono automatyczny ROP po każdej wpłacie na subkonto.' : 'Wyłączono automatyczny ROP — wystawiaj ręcznie.');
        return Router::redirect(route('rop.index'));
    }

    public function ropShow(int $id): string
    {
        $r = RopStatement::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego dokumentu.'; }
        return $this->view('rop_show.php', ['r' => $r]);
    }

    public function ropPdf(int $id): ?string
    {
        $r = RopStatement::get($id);
        if (!$r) { http_response_code(404); return 'Nie ma takiego dokumentu.'; }
        $p = DopService::fullPath((string)$r['pdf_path']);
        if ($p) { while (ob_get_level() > 0) ob_end_clean(); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $r['number']) . '.pdf"'); header('Cache-Control: no-store, private'); readfile($p); return null; }
        return $this->send(RopStatement::render($r), (string)$r['number']);
    }

    public function ropSend(int $id): ?string
    {
        try { flash_set('success', 'Wysłano ponownie: ' . RopStatement::send($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('rop.show', ['id' => $id])); }
        return Router::redirect(route('rop.show', ['id' => $id]));
    }

    public function blank($form): ?string
    {
        $f = DopForm::tryFrom(strtoupper((string)$form));
        if (!$f) { http_response_code(404); return 'Nie ma takiego wzoru.'; }
        return $this->send(DopDocument::blank($f), $f->value . '-wzor');
    }
}
