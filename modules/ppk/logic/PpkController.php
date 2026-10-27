<?php
/**
 * modules/ppk/logic/PpkController.php — strona PPK przy umowie o pracę: stan, podgląd wpłat, druki PPKR/PPKP, historia ustawień.
 * Wgląd: jak w karcie umowy (viewer_owns_contract). Zmiany i druki: can_edit() i umowa niezablokowana.
 */
require_once __DIR__ . '/PpkService.php';
require_once dirname(__DIR__) . '/lib/PpkDocumentPdf.php';

final class PpkController
{
    private const VIEW = __DIR__ . '/../view/';

    /** @return array{0:?array,1:?string} [umowa, komunikat błędu] */
    private function load(int $id, bool $write = false): array
    {
        $c = PpkService::contract($id);
        if (!$c) { http_response_code(404); return [null, 'Nie znaleziono umowy o pracę.']; }
        if (!viewer_owns_contract('praca', $c)) { http_response_code(403); return [null, 'Nie masz dostępu do tej umowy.']; }
        if ($write && (!can_edit() || contract_is_locked($c))) { http_response_code(403); return [null, 'Brak uprawnień do zmian PPK przy tej umowie (albo umowa jest zablokowana).']; }
        return [$c, null];
    }

    private function back(int $id): ?string { return Router::redirect(route('ppk.show', ['id' => $id])); }

    public function show(int $id): string
    {
        [$c, $err] = $this->load($id);
        if (!$c) return $err;
        PpkService::migrate();
        $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['miesiac'] ?? '')) ? $_GET['miesiac'] : date('Y-m');
        $baseGr = PpkCalculator::toGrosze((string)($_GET['podstawa'] ?? '')) ?? (PpkCalculator::toGrosze((string)($c['wynagrodzenie_brutto'] ?? '')) ?? 0);
        $preview = PpkService::previewMonth($id, $month, [['date' => date('Y-m-t', strtotime($month . '-01')), 'base_gr' => $baseGr]]);
        $versions = PpkService::versions($id);
        $cur = PpkService::current($id);
        $autoDue = null;
        if ($cur && $cur['status'] === PpkCalculator::STATUS_RESIGNED) $autoDue = PpkCalculator::nextAutoEnrollment((string)$cur['effective_from'], PpkService::autoEnrollMonth());
        return $this->page('PPK — ' . $c['numer_umowy'], Router::view(self::VIEW . 'show.php', [
            'c' => $c, 'cur' => $cur, 'versions' => $versions, 'declarations' => PpkService::declarations($id),
            'preview' => $preview, 'month' => $month, 'baseGr' => $baseGr, 'autoDue' => $autoDue,
            'canEdit' => can_edit() && !contract_is_locked($c),
        ]));
    }

    public function addVersion(int $id): ?string
    {
        [$c, $err] = $this->load($id, true);
        if (!$c) return $err;
        try {
            $status = (string)($_POST['status'] ?? '');
            $rates = null;
            if ($status === PpkCalculator::STATUS_PARTICIPANT) $rates = $this->ratesFromPost();
            PpkService::addVersion($id, (string)($_POST['effective_from'] ?? ''), $status, $rates, 'reczna', null, (string)($_POST['note'] ?? ''), (int)current_user()['id']);
            flash_set('success', 'Dopisano wersję ustawień PPK.');
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return $this->back($id);
    }

    public function createDeclaration(int $id): ?string
    {
        [$c, $err] = $this->load($id, true);
        if (!$c) return $err;
        try {
            $kind = (string)($_POST['kind'] ?? '');
            $rates = $kind === PpkService::KIND_JOIN ? $this->ratesFromPost() : null;
            $did = PpkService::createDeclaration($id, $kind, $rates, (int)current_user()['id']);
            flash_set('success', 'Przygotowano druk ' . PpkService::declaration($did)['number'] . '. Wydrukuj go do podpisu albo zarejestruj złożenie elektroniczne.');
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return $this->back($id);
    }

    public function pdf(int $declId): ?string
    {
        $d = PpkService::declaration($declId);
        if (!$d) { http_response_code(404); return 'Nie ma takiego druku.'; }
        [$c, $err] = $this->load((int)$d['contract_id']);
        if (!$c) return $err;
        $bytes = PpkDocumentPdf::render($d, $c);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('~[^A-Za-z0-9_.-]~', '_', (string)$d['number']) . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');   // druk zawiera PESEL
        echo $bytes;
        return null;
    }

    public function submit(int $declId): ?string
    {
        $d = PpkService::declaration($declId);
        if (!$d) { http_response_code(404); return 'Nie ma takiego druku.'; }
        [$c, $err] = $this->load((int)$d['contract_id'], true);
        if (!$c) return $err;
        try {
            PpkService::registerSubmission($declId, (string)($_POST['declared_at'] ?? ''), (string)($_POST['form'] ?? ''), (string)($_POST['proof'] ?? ''),
                (string)($_POST['effective_from'] ?? '') ?: null, (int)current_user()['id']);
            flash_set('success', 'Zarejestrowano złożenie druku ' . $d['number'] . ' — ustawienia PPK zaktualizowane.');
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return $this->back((int)$d['contract_id']);
    }

    public function cancel(int $declId): ?string
    {
        $d = PpkService::declaration($declId);
        if (!$d) { http_response_code(404); return 'Nie ma takiego druku.'; }
        [$c, $err] = $this->load((int)$d['contract_id'], true);
        if (!$c) return $err;
        try {
            PpkService::cancelDeclaration($declId, (int)current_user()['id']);
            flash_set('success', 'Druk ' . $d['number'] . ' anulowany.');
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return $this->back((int)$d['contract_id']);
    }

    /** Stawki z formularza (pola w procentach, np. „1,5”). Puste = wartości domyślne. @throws InvalidArgumentException */
    private function ratesFromPost(): PpkRates
    {
        $pick = static function (string $key, int $default): int {
            $raw = trim((string)($_POST[$key] ?? ''));
            if ($raw === '') return $default;
            $bp = PpkRates::percentToBp($raw);
            if ($bp === null) throw new \InvalidArgumentException('Stawka „' . $raw . '” nie jest poprawną liczbą (np. 1,5).');
            return $bp;
        };
        return new PpkRates($pick('employee_base', PpkRates::EMPLOYEE_BASE_MAX_BP), $pick('employee_add', 0),
                            $pick('employer_base', PpkRates::EMPLOYER_BASE_BP), $pick('employer_add', 0));
    }

    private function page(string $title, string $body): string
    {
        $PAGE_TITLE = $title;
        ob_start();
        include dirname(__DIR__, 3) . '/includes/header.php';
        echo $body;
        include dirname(__DIR__, 3) . '/includes/footer.php';
        return (string)ob_get_clean();
    }
}
