<?php
declare(strict_types=1);

/**
 * modules/ti_dop/logic/DopController.php — „Finanse › Wnioski DOP” w panelu dydaktyka: lista, nowy wniosek DOP1 / DOP2
 * (podpis przez link albo wersja papierowa ze skanem), karta wniosku ze ścieżką obsługi, wydruk, puste wzory.
 */
require_once dirname(__DIR__) . '/lib/DopService.php';
require_once dirname(__DIR__) . '/lib/RopStatement.php';
require_once dirname(__DIR__) . '/lib/SettlementDocs.php';

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
        return $this->view('index.php', ['list' => $this->svc->search($f), 'f' => $f, 'counts' => $this->svc->counts()]);
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

    // ── RKO / PRZ / PRZ-P — dokumenty rozliczeniowe ─────────────────────────────

    public function settleIndex(): string
    {
        $f = ['client' => (int)($_GET['klient'] ?? 0), 'kind' => (string)($_GET['kind'] ?? ''), 'status' => (string)($_GET['status'] ?? '')];
        return $this->view('settle_index.php', ['list' => SettlementDocs::search($f), 'f' => $f]);
    }

    public function settleForm(array $errors = [], ?array $old = null): string
    {
        $kind = SettleKind::tryFrom((string)($_GET['kind'] ?? $_POST['kind'] ?? '')) ?? SettleKind::Rko;
        $cid = (int)($_GET['klient'] ?? $_POST['klient'] ?? 0);
        $c = $cid ? DopService::client($cid) : null;
        $courses = $c ? SettlementDocs::courses($cid) : [];
        return $this->view('settle_form.php', ['kind' => $kind, 'c' => $c, 'courses' => $courses, 'clients' => $c ? [] : DopService::clients((string)($_GET['q'] ?? '')), 'q' => (string)($_GET['q'] ?? ''),
            'errors' => $errors, 'old' => $old ?? ['course_id' => (string)($_GET['grupa'] ?? ''), 'as_of' => ldToday(), 'mode' => 'approval', 'scope' => 'group'],
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
