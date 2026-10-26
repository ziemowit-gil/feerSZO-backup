<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/logic/LicenseCorrectionController.php — AMSK „Korekty” w panelu dydaktyka:
 * lista z wyszukiwaniem po kodzie, „Skanuj kod” (Code128 z dowolnego formularza AM → sprawa / operacja / korekta),
 * wystawienie korekty, druk kontrolny AMSK/1 (QR + Code128, podpis odręczny kierownika), potwierdzenie podpisu, druk AMSK, wysyłka.
 */
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
require_once dirname(__DIR__) . '/lib/LicenseDocPackage.php';
require_once dirname(__DIR__) . '/lib/LicenseRotation.php';
require_once dirname(__DIR__) . '/lib/LicenseCaseIndex.php';

final class LicenseCorrectionController
{
    private const VIEW = __DIR__ . '/../view/';
    private LicenseCorrection $corr;

    public function __construct()
    {
        $this->corr = new LicenseCorrection();
    }

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'canApprove' => Am7Workflow::canApprove((int)$this->actor()['id'])]);
    }

    private function fail(\Throwable $e, string $to): ?string
    {
        $user = $e instanceof DomainException || $e instanceof InvalidArgumentException;
        flash_set('danger', $user ? $e->getMessage() : 'Błąd: ' . $e->getMessage());
        if (!$user) error_log('ld corr: ' . $e);
        return Router::redirect($to);
    }

    private function one(int $id): array
    {
        $c = LicenseCorrection::get($id);
        if (!$c) { http_response_code(404); exit('Nie ma takiej korekty.'); }
        return $c;
    }

    public function index(): string
    {
        $q = trim((string)($_GET['q'] ?? ''));
        return $this->view('korekty.php', ['q' => $q, 'rows' => LicenseCorrection::search($q)]);
    }

    /** „Skanuj kod”: LD…/OP…/AMSK-… (z Code128 na formularzu) → właściwy ekran. */
    public function scan(): ?string
    {
        $code = trim((string)($_GET['kod'] ?? ''));
        $r = LicenseCodes::resolve($code);
        if ($r) {
            try {
                if ($r['scope'] === 'correction' && LicenseCorrection::get($r['id'])) return Router::redirect(route('ld.corr', ['id' => $r['id']]));
                if ($r['scope'] === 'rotation' && LicenseRotation::get($r['id'])) return Router::redirect(route('ld.rot', ['id' => $r['id']]));
                if ($r['scope'] === 'package' && ($pk = LicenseDocPackage::get($r['id']))) return Router::redirect(route('ld.corr', ['id' => (int)$pk['correction_id']]) . '#pakiet');
                $exists = $r['scope'] === 'license' ? db_one("SELECT 1 FROM licenses WHERE id = ?", [$r['id']]) : ($r['scope'] === 'operation' ? db_one("SELECT 1 FROM license_operations WHERE id = ?", [$r['id']]) : null);
                if ($exists) {
                    $st = LicenseDocPackage::codeState($r['scope'], (int)$r['id'], $r['symbol'], (int)$r['rev']);
                    if ($st['state'] === 'void') flash_set('danger', 'UWAGA: ten druk jest UNIEWAŻNIONY' . ($st['note'] !== '' ? ' (' . $st['note'] . ')' : ' (rewizja R' . $r['rev'] . ')') . ' — po korekcie AMSK obowiązuje rewizja R' . $st['current'] . '. Użyj druków z rejestru poniżej.');
                    elseif ($st['state'] === 'valid' && $r['rev'] > 0) flash_set('success', 'Druk obowiązuje (rewizja R' . $r['rev'] . ').');
                    return Router::redirect($r['scope'] === 'license' ? route('ld.show', ['id' => $r['id']]) . '#wydruki' : route('ld.op', ['id' => $r['id']]));
                }
            } catch (\Throwable $e) { error_log('ld scan: ' . $e->getMessage()); }
        }
        flash_set('warning', $code === '' ? 'Wpisz lub zeskanuj kod z formularza AM.' : 'Nie rozpoznano kodu „' . $code . '” (oczekiwany np. LD000002/AM7-2/R2, OP000005/AM20, AMSK-000012, UP1-000003 albo PP-000001).');
        return Router::redirect(route('ld.corr.index'));
    }

    public function show(int $id): string
    {
        $c = $this->one($id);
        [$t] = LicenseCorrection::target($c['scope'], (int)$c['target_id']);
        return $this->view('korekta.php', ['c' => $c, 't' => $t]);
    }

    public function create(): ?string
    {
        $scope = (string)($_POST['scope'] ?? ''); $tid = (int)($_POST['target_id'] ?? 0);
        $back = $scope === 'operation' ? route('ld.op', ['id' => $tid]) : route('ld.show', ['id' => $tid]);
        try {
            $changes = [];
            foreach ((array)($_POST['key'] ?? []) as $i => $key) {
                $key = (string)$key;
                if ($key === '') continue;
                $changes[$key] = (string)(((array)($_POST['val'] ?? []))[$i] ?? '');
            }
            $id = $this->corr->create($scope, $tid, $changes, (string)($_POST['reason'] ?? ''), $this->actor());
            flash_set('success', 'Wystawiono korektę ' . LicenseCorrection::code($id) . '. Wydrukuj druk kontrolny AMSK/1 i podpisz go odręcznie (kierownik) — dopiero potem potwierdź podpis w SZO.');
            return Router::redirect(route('ld.corr', ['id' => $id]));
        } catch (\Throwable $e) { return $this->fail($e, $back); }
    }

    /** ?druk=kontrolny (AMSK/1, domyślnie) | korekta (AMSK, po zatwierdzeniu). */
    public function pdf(int $id): ?string
    {
        $c = $this->one($id);
        try { $o = $this->corr->document($c, (string)($_GET['druk'] ?? 'kontrolny') !== 'korekta'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => $id])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    /** Ręczne wygenerowanie pakietu druków AM po zatwierdzonej korekcie (gdy automat nie zadziałał); unieważnia poprzednie druki. */
    public function pack(int $id): ?string
    {
        try { $rep = (new LicenseDocPackage())->generateManual($id, $this->actor()); flash_set(str_contains($rep, 'UWAGA') ? 'warning' : 'success', ucfirst($rep) . '. Pakiet do osoby wyślesz przyciskiem na karcie korekty.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => $id])); }
        return Router::redirect(route('ld.corr', ['id' => $id]));
    }

    /** Regeneracja pakietu bieżącej rewizji z aktualnych danych (ta sama rewizja, kody bez zmian); pakiet wraca do stanu „nie wysłano”. */
    public function packRegen(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        $back = $pk ? route('ld.corr', ['id' => (int)$pk['correction_id']]) . '#pakiet' : route('ld.corr.index');
        try { $rep = (new LicenseDocPackage())->regenerateManual($pid, $this->actor()); flash_set(str_contains($rep, 'UWAGA') ? 'warning' : 'success', ucfirst($rep) . '. Pakiet do osoby wyślesz ponownie przyciskiem na karcie korekty.'); }
        catch (\Throwable $e) { return $this->fail($e, $back); }
        return Router::redirect($back);
    }

    /** Druk AMSG „Regeneracja pakietu” (PDF). */
    public function packAmsg(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        if (!$pk) { http_response_code(404); exit('Nie ma takiego pakietu.'); }
        try { $o = (new LicenseDocPackage())->amsgOutput($pk); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => (int)$pk['correction_id']])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    /** Nowe hasło do archiwum pakietu — wyłącznie kierownik; hasło SMS-em na wskazany numer, nowy ZIP e-mailem do kierownika. */
    public function packPwd(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        $back = $pk ? route('ld.corr', ['id' => (int)$pk['correction_id']]) . '#pakiet' : route('ld.corr.index');
        try { $rep = (new LicenseDocPackage())->regeneratePassword($pid, (string)($_POST['phone'] ?? ''), $this->actor()); flash_set(str_contains($rep, 'UWAGA') ? 'warning' : 'success', $rep . '.'); }
        catch (\Throwable $e) { return $this->fail($e, $back); }
        return Router::redirect($back);
    }

    /** Druk PP — protokół przekazania hasła do pakietu (AM Pre-shared Protocol). */
    public function packPp(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        if (!$pk) { http_response_code(404); exit('Nie ma takiego pakietu.'); }
        try { $o = (new LicenseDocPackage())->ppOutput($pk); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => (int)$pk['correction_id']])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    public function packZip(int $pid): ?string
    {
        try { $z = (new LicenseDocPackage())->zip($pid); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr.index')); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $z['name'] . '"');
        header('Cache-Control: no-store, private');
        echo $z['bytes'];
        return null;
    }

    public function packSend(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        $back = $pk ? route('ld.corr', ['id' => (int)$pk['correction_id']]) : route('ld.corr.index');
        try { $rep = (new LicenseDocPackage())->send($pid, $this->actor()); flash_set(str_contains($rep, 'UWAGA') ? 'warning' : 'success', $rep . '.'); }
        catch (\Throwable $e) { return $this->fail($e, $back); }
        return Router::redirect($back);
    }

    public function approve(int $id): ?string
    {
        try {
            if (empty($_POST['signed'])) throw new InvalidArgumentException('Zaznacz, że druk kontrolny AMSK/1 został podpisany odręcznie przez kierownika.');
            $rep = $this->corr->approve($id, $this->actor());
            flash_set(str_contains($rep, 'UWAGA') ? 'warning' : 'success', 'Podpis potwierdzony — korekta ' . LicenseCorrection::code($id) . ' wprowadzona w danych sprawy. ' . $rep . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => $id])); }
        return Router::redirect(route('ld.corr', ['id' => $id]));
    }

    public function cancel(int $id): ?string
    {
        try { $this->corr->cancel($id, (string)($_POST['reason'] ?? ''), $this->actor()); flash_set('success', 'Korektę anulowano.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => $id])); }
        return Router::redirect(route('ld.corr', ['id' => $id]));
    }

    public function send(int $id): ?string
    {
        try { flash_set('success', $this->corr->send($id, $this->actor()) . '.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => $id])); }
        return Router::redirect(route('ld.corr', ['id' => $id]));
    }

    // ── ASK-UP1: uzasadnienie rotacji licencji ──────────────────────────────────────

    public function rotCreate(): ?string
    {
        $scope = (string)($_POST['scope'] ?? ''); $tid = (int)($_POST['target_id'] ?? 0);
        $back = $scope === 'operation' ? route('ld.op', ['id' => $tid]) : route('ld.show', ['id' => $tid]);
        try {
            $id = (new LicenseRotation())->create($scope, $tid, $_POST, $this->actor());
            flash_set('success', 'Wystawiono uzasadnienie rotacji ' . LicenseRotation::code($id) . '. Wydrukuj ASK-UP1 i podpisz odręcznie (kierownik).');
            return Router::redirect(route('ld.rot', ['id' => $id]));
        } catch (\Throwable $e) { return $this->fail($e, $back); }
    }

    public function rotShow(int $id): string
    {
        $r = LicenseRotation::get($id);
        if (!$r) { http_response_code(404); exit('Nie ma takiego uzasadnienia.'); }
        return $this->view('rotacja.php', ['r' => $r]);
    }

    public function rotPdf(int $id): ?string
    {
        $r = LicenseRotation::get($id);
        if (!$r) { http_response_code(404); exit('Nie ma takiego uzasadnienia.'); }
        try { $o = (new LicenseRotation())->document($r); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.rot', ['id' => $id])); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    public function rotConfirm(int $id): ?string
    {
        try {
            if (empty($_POST['signed'])) throw new InvalidArgumentException('Zaznacz, że druk ASK-UP1 został podpisany odręcznie przez kierownika.');
            (new LicenseRotation())->confirmSigned($id, $this->actor());
            flash_set('success', 'Podpis potwierdzony — ' . LicenseRotation::code($id) . '.');
        } catch (\Throwable $e) { return $this->fail($e, route('ld.rot', ['id' => $id])); }
        return Router::redirect(route('ld.rot', ['id' => $id]));
    }

    public function rotCancel(int $id): ?string
    {
        try { (new LicenseRotation())->cancel($id, (string)($_POST['reason'] ?? ''), $this->actor()); flash_set('success', 'Uzasadnienie anulowano.'); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.rot', ['id' => $id])); }
        return Router::redirect(route('ld.rot', ['id' => $id]));
    }

    // ── AM56 (spis dokumentów sprawy), blokada pakietu i scalony PDF ─────────────────────────────

    private function pdfOut(array $o): ?string
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $o['out']['mime'] . ($o['out']['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName($o['number']) . '.' . $o['out']['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $o['out']['bytes'];
        return null;
    }

    /** Druk AM56 sprawy (scope=license) albo operacji (scope=operation). */
    public function am56(string $scope, int $id): ?string
    {
        try { return $this->pdfOut(LicenseCaseIndex::output($scope, $id)); }
        catch (\Throwable $e) { return $this->fail($e, $scope === 'operation' ? route('ld.op', ['id' => $id]) : route('ld.show', ['id' => $id])); }
    }

    /** Okładka sprawy AM (teczka). */
    private function cover(string $scope, int $id): ?string
    {
        try { return $this->pdfOut(LicenseCaseIndex::coverOutput($scope, $id)); }
        catch (\Throwable $e) { return $this->fail($e, $scope === 'operation' ? route('ld.op', ['id' => $id]) : route('ld.show', ['id' => $id])); }
    }

    public function coverLicense(int $id): ?string { return $this->cover('license', $id); }
    public function coverOperation(int $id): ?string { return $this->cover('operation', $id); }

    public function am56License(int $id): ?string { return $this->am56('license', $id); }
    public function am56Operation(int $id): ?string { return $this->am56('operation', $id); }

    /** „Zablokuj pakiet”: zamrożenie zawartości + jeden scalony PDF (spis AM56 + wszystkie druki pakietu, bez AMSK) otwierany od razu. */
    public function packLock(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        $back = $pk ? route('ld.corr', ['id' => (int)$pk['correction_id']]) . '#pakiet' : route('ld.corr.index');
        try { (new LicenseDocPackage())->lock($pid, $this->actor()); }
        catch (\Throwable $e) { return $this->fail($e, $back); }
        flash_set('success', 'Pakiet zablokowany. Scalony PDF (spis AM56 + wszystkie druki, bez AMSK) jest zapisany w pakiecie — można go pobrać ponownie przyciskiem.');
        return Router::redirect(route('ld.pack.merged', ['pid' => $pid]));
    }

    public function packMerged(int $pid): ?string
    {
        $pk = LicenseDocPackage::get($pid);
        if (!$pk) { http_response_code(404); exit('Nie ma takiego pakietu.'); }
        try { $bytes = LicenseDocPackage::mergedBytes($pk); }
        catch (\Throwable $e) { return $this->fail($e, route('ld.corr', ['id' => (int)$pk['correction_id']]) . '#pakiet'); }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pakiet-AM-R' . (int)$pk['revision'] . '-' . LicenseCodes::caseCode((string)$pk['scope'], (int)$pk['target_id']) . '-scalony.pdf"');
        header('Cache-Control: no-store, private');
        echo $bytes;
        return null;
    }
}
