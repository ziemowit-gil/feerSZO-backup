<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/logic/LicenseOperationController.php — „Licencje › Operacje” w panelu dydaktyka:
 * konta i posiadane licencje osoby, wnioski AM11–AM13, operacje AM20–AM24 (zmiana płatnika, planu, zawieszenie, cofnięcie,
 * przedłużenie), decyzja cyfrowa kierownika, oznaczanie wykonania, wydruk PDF (wzorowany na formularzu CIT) i rekord JSON (#detail-panel).
 * Wejście z kartoteki uczestnika: konta.php › zakładka Licencje (partial view/_kontaPanel.php).
 */
require_once dirname(__DIR__) . '/lib/LicenseOperationService.php';

final class LicenseOperationController
{
    private const VIEW = __DIR__ . '/../view/';
    private LicenseOperationService $ops;

    public function __construct()
    {
        $this->ops = new LicenseOperationService();
    }

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'ops' => $this->ops, 'canApprove' => Am7Workflow::canApprove((int)$this->actor()['id'])]);
    }

    private function op(int $id): array
    {
        $op = $this->ops->get($id);
        if (!$op) { http_response_code(404); exit('Nie ma takiej operacji.'); }
        return $op;
    }

    private function fail(\Throwable $e, string $to): ?string
    {
        $user = $e instanceof DomainException || $e instanceof InvalidArgumentException;
        flash_set('danger', $user ? $e->getMessage() : 'Błąd: ' . $e->getMessage());
        if (!$user) error_log('ld op: ' . $e);
        return Router::redirect($to);
    }

    /** 'client:12' → konto albo 404. */
    private function accountFrom(string $ref): array
    {
        [$k, $i] = array_pad(explode(':', $ref, 2), 2, '0');
        $acc = in_array($k, ['client', 'user'], true) ? LicenseResources::account($k, (int)$i) : null;
        if (!$acc) { http_response_code(404); exit('Nie znaleziono konta.'); }
        return $acc;
    }

    // ── Konta ───────────────────────────────────────────────────────────────────

    public function accounts(): string
    {
        $q = (string)($_GET['q'] ?? '');
        return $this->view('op_accounts.php', ['q' => $q, 'found' => LicenseResources::searchAccounts($q), 'list' => $this->ops->search(['status' => (string)($_GET['status'] ?? '')]),
            'status' => (string)($_GET['status'] ?? ''), 'counts' => $this->ops->counts()]);
    }

    public function account(string $kind, int $id): string
    {
        $acc = $this->accountFrom($kind . ':' . $id);
        return $this->view('op_account.php', ['acc' => $acc, 'res' => LicenseResources::forAccount($acc, true), 'list' => $this->ops->search(['account' => $kind . ':' . $id])]);
    }

    // ── Nowa operacja ───────────────────────────────────────────────────────────

    public function createForm(array $errors = [], ?array $old = null): string
    {
        $acc = $this->accountFrom((string)($_GET['konto'] ?? $_POST['konto'] ?? ''));
        $form = OperationForm::tryFrom((string)($_GET['form'] ?? $_POST['form'] ?? '')) ?? OperationForm::Am20;
        $key = (string)($_GET['zasob'] ?? $_POST['zasob'] ?? '');
        $res = $key !== '' ? LicenseResources::resource($acc, $key) : null;
        return $this->view('op_form.php', ['acc' => $acc, 'form' => $form, 'res' => $res, 'resources' => LicenseResources::forAccount($acc),
            'errors' => $errors, 'old' => $old ?? LicenseOperationService::defaults($form, $acc, $res, $this->actor()), 'orgAccounts' => LicenseOperationService::orgAccounts()]);
    }

    public function create(): ?string
    {
        $acc = $this->accountFrom((string)($_POST['konto'] ?? ''));
        $form = OperationForm::tryFrom((string)($_POST['form'] ?? ''));
        if (!$form) { flash_set('danger', 'Nieznany formularz.'); return Router::redirect(route('ld.account', ['kind' => $acc['kind'], 'id' => $acc['id']])); }
        $in = $_POST;
        if (($in['rachunek_wybor'] ?? '') !== '' && ($in['rachunek_wybor'] ?? '') !== 'inny') $in['rachunek'] = $in['rachunek_wybor'];
        try {
            $r = $this->ops->create($form, $acc, (string)($_POST['zasob'] ?? '') ?: null, $in, $this->actor(), $_FILES['dowod_plik'] ?? null);
        } catch (\Throwable $e) { return $this->fail($e, route('ld.account', ['kind' => $acc['kind'], 'id' => $acc['id']])); }
        if ($r['errors']) { echo $this->createForm($r['errors'], $_POST); return null; }
        flash_set('success', 'Złożono ' . $form->value . '. ' . implode(' · ', $r['report']));
        return Router::redirect(route('ld.op', ['id' => $r['id']]));
    }

    // ── Karta operacji ──────────────────────────────────────────────────────────

    public function show(int $id): string
    {
        $op = $this->op($id);
        $notifs = db_all("SELECT * FROM notification_logs WHERE module = 'ti_licencje_dyd' AND ref_type = 'operation' AND ref_id = ? ORDER BY id DESC", [$id]);
        return $this->view('op_show.php', ['op' => $op, 'record' => $this->ops->record($op), 'events' => $this->ops->events($id), 'notifs' => $notifs,
            'history' => $op['resource_key'] !== '' ? LicenseResources::fundingHistory((string)$op['resource_key']) : []]);
    }

    public function decide(int $id): ?string
    {
        try {
            $r = $this->ops->decide($id, ($_POST['decision'] ?? '') === 'grant', $_POST, $this->actor());
            if ($r['errors']) { flash_set('danger', implode(' ', array_values($r['errors']))); return Router::redirect(route('ld.op', ['id' => $id]) . '#decyzja'); }
            flash_set('success', 'Zapisano decyzję. ' . implode(' · ', $r['report']));
        } catch (\Throwable $e) { return $this->fail($e, route('ld.op', ['id' => $id])); }
        return Router::redirect(route('ld.op', ['id' => $id]));
    }

    public function done(int $id): ?string
    {
        try { $this->ops->markExecuted($id, trim((string)($_POST['notes'] ?? '')), $this->actor()); flash_set('success', 'Oznaczono jako wykonane.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.op', ['id' => $id])); }
        return Router::redirect(route('ld.op', ['id' => $id]));
    }

    public function cancel(int $id): ?string
    {
        try { $this->ops->cancel($id, trim((string)($_POST['reason'] ?? '')), $this->actor()); flash_set('success', 'Wycofano operację.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.op', ['id' => $id])); }
        return Router::redirect(route('ld.op', ['id' => $id]));
    }

    /** AM20: ponowny link do potwierdzenia (poprzedni traci ważność). */
    public function signLink(int $id): ?string
    {
        try { flash_set('success', 'Link do potwierdzenia: ' . $this->ops->sendSignLink($id, $this->actor(), true) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.op', ['id' => $id])); }
        return Router::redirect(route('ld.op', ['id' => $id]));
    }

    public function resend(int $id): ?string
    {
        $op = $this->op($id);
        if (in_array($op['statusEnum'], [OperationStatus::Pending, OperationStatus::Cancelled, OperationStatus::Signing], true)) { flash_set('warning', 'Dokument wysyła się po decyzji.'); return Router::redirect(route('ld.op', ['id' => $id])); }
        try { flash_set('success', 'Dokument wysłany ponownie: ' . $this->ops->sendDecision($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.op', ['id' => $id])); }
        return Router::redirect(route('ld.op', ['id' => $id]));
    }

    public function pdf(int $id): ?string
    {
        $op = $this->op($id);
        $out = $this->ops->document($op);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName((string)$op['number']) . '.' . $out['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $out['bytes'];
        return null;
    }

    public function json(int $id): array
    {
        return $this->ops->record($this->op($id));
    }

    public function proof(int $id): ?string
    {
        $op = $this->op($id);
        return LicenseStorage::send((string)$op['proof_path'], ldFileName('dowod-' . $op['number']));
    }

    /** Pusty wzór operacji do druku: /wzor-op/AM20. */
    public function blank($form): ?string
    {
        $annex = strtoupper((string)$form) === 'AM20-1';
        $f = $annex ? OperationForm::Am20 : OperationForm::tryFrom(strtoupper((string)$form));
        if (!$f) { http_response_code(404); return 'Nie ma takiego wzoru.'; }
        $op = ['formEnum' => $f, 'statusEnum' => OperationStatus::Pending, 'request' => [], 'decision' => [], 'state' => $f->needsResource() ? ['vendor' => '', 'sku' => '', 'type' => ''] : [],
               'number' => '', 'course_name' => '', 'created_name' => '', 'created_at' => '', 'created_ip' => '', 'due_on' => '', 'account_name' => '', 'account_role' => '',
               'account_kind' => '', 'account_id' => 0, 'account_login' => '', 'proof_name' => '', 'decided_name' => '', 'decided_at' => '', 'decided_ip' => '',
               'execution_mode' => '', 'helpdesk_number' => '', 'execution_notes' => ''];
        $out = $annex ? LicenseDocument::render((new LicenseDocument())->am20AnnexOnlyHtml($op), 'Wykaz dokumentów księgowych (AM20-1)', 'AM20-1 — wzór', 'AM20-1')
                      : LicenseDocument::render((new LicenseDocument())->operationHtml($op), $f->title(), $f->value . ' — wzór', $f->value);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ($annex ? 'AM20-1' : $f->value) . '-wzor.' . $out['ext'] . '"');
        echo $out['bytes'];
        return null;
    }
}
