<?php
/**
 * modules/noclegi/logic/NoclegiController.php — akcje modułu Noclegi (trasy w router.php). Dostęp do tras chronią
 * middleware nocl.user / nocl.coord / nocl.log; uprawnienia do konkretnego zgłoszenia sprawdza noclCanView().
 */
require_once __DIR__ . '/noclegi.php';

final class NoclegiController
{
    private const VIEW = __DIR__ . '/../view/';

    private function render(string $v, array $d = []): string {
        $uid = noclUid();
        return Router::view(self::VIEW . $v . '.php', $d + ['uid' => $uid, 'role' => noclRole($uid)]);
    }
    private function notFound(): string { http_response_code(404); return $this->render('message', ['msg' => 'Nie znaleziono zgłoszenia albo brak dostępu.']); }
    private function back(string $name, array $p = []): ?string { return Router::redirect(route($name, $p)); }
    private function flash(?string $err, string $ok): void { flash_set($err ? 'warning' : 'success', $err ?? $ok); }

    // ── Filar 1/4: zgłoszenia i self-service ───────────────────────────────────

    public function index(): string {
        noclAutoComplete();
        $uid = noclUid();
        $rows = noclRequestList($uid, 'own');
        $today = date('Y-m-d');
        $cards = [];
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['potwierdzone', 'zrealizowane'], true) || $r['date_to'] < $today) continue;
            $ins = noclInstruction($r, noclCodesVisible($r, $uid));
            if ($ins) $cards[$r['id']] = $ins;
        }
        return $this->render('index', ['rows' => $rows, 'counts' => noclCounts($uid, 'own'), 'cards' => $cards]);
    }

    public function create(): string {
        $f = ['person_name' => (string)(current_user()['name'] ?? ''), 'person_email' => (string)(current_user()['email'] ?? ''), 'person_role' => 'dydaktyk',
              'date_from' => (string)($_GET['od'] ?? ''), 'date_to' => (string)($_GET['do'] ?? '')];
        return $this->render('requestForm', ['f' => $f, 'errors' => [], 'id' => 0, 'budgets' => noclBudgetList(true)]);
    }

    public function store(): ?string {
        $uid = noclUid();
        [$d, $err] = noclRequestValidate($_POST);
        $forOther = noclIsLogistyk($uid) && !empty($_POST['for_other']);
        if ($err) { flash_set('warning', 'Popraw zaznaczone pola.'); return $this->render('requestForm', ['f' => $_POST, 'errors' => $err, 'id' => 0, 'budgets' => noclBudgetList(true)]); }
        $owner = $forOther ? null : $uid;
        $id = noclRequestCreate($d, $owner, $uid, $forOther ? 'logistyk' : 'form');
        flash_set('success', 'Zgłoszenie zostało zapisane (status: Wniosek). Powiadomimy Cię o potwierdzeniu.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function show(int $id): string {
        noclAutoComplete();
        $uid = noclUid();
        $r = noclRequestGet($id);
        if (!$r || !noclCanView($r, $uid)) return $this->notFound();
        $role = noclRole($uid);
        $isLog = $role === 'logistyk';
        $coord = $role === 'koordynator' && noclCoordCovers($uid, (int)$r['budget_id'] ?: null);
        $ins = noclInstruction($r, noclCodesVisible($r, $uid));
        $canEditNote = $isLog || $coord || (int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid;
        return $this->render('request', [
            'r' => $r, 'log' => noclLogList($id), 'ins' => $ins, 'isLog' => $isLog, 'coord' => $coord,
            'suggest' => $isLog && !(int)$r['room_id'] && !in_array($r['status'], ['zrealizowane', 'anulowane'], true) ? noclSuggestRooms($r) : [],
            'rooms' => $isLog ? db_all("SELECT rm.id, rm.name, rm.beds, rm.accessible, f.name AS fname FROM nocl_rooms rm JOIN nocl_facilities f ON f.id = rm.facility_id WHERE rm.is_active = 1 AND f.is_active = 1 ORDER BY f.name, rm.name") : [],
            'budgets' => noclBudgetList(true), 'canEdit' => $isLog || ($canEditNote && in_array($r['status'], ['wniosek', 'w_trakcie'], true)),
            'sensitive' => $canEditNote, 'transitions' => NOCL_PRZEJSCIA[$r['status']] ?? [],
            'budgetSummary' => (int)$r['budget_id'] ? noclBudgetSummary((int)$r['budget_id']) : null,
        ]);
    }

    public function update(int $id): ?string {
        $uid = noclUid();
        $r = noclRequestGet($id);
        if (!$r || !noclCanView($r, $uid)) return $this->notFound();
        [$d, $err] = noclRequestValidate($_POST);
        if ($err) { flash_set('warning', implode(' ', $err)); return $this->back('nocl.show', ['id' => $id]); }
        if (noclIsLogistyk($uid)) { $d['logistics_note'] = trim((string)($_POST['logistics_note'] ?? '')); }
        if (noclRole($uid) === 'koordynator' || noclIsLogistyk($uid)) { $d['coord_note'] = trim((string)($_POST['coord_note'] ?? $r['coord_note'])); }
        $this->flash(noclRequestUpdate($id, $d, $uid), 'Zapisano zgłoszenie.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function status(int $id): ?string {
        $uid = noclUid();
        $r = noclRequestGet($id);
        if (!$r || !noclCanView($r, $uid)) { $this->notFound(); return null; }
        $this->flash(noclTransition($id, (string)($_POST['to'] ?? ''), $uid, trim((string)($_POST['note'] ?? ''))), 'Zmieniono status zgłoszenia.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function queue(): string {
        noclAutoComplete();
        $uid = noclUid();
        $f = ['status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'budget_id' => (int)($_GET['budget_id'] ?? 0),
              'from' => (string)($_GET['od'] ?? ''), 'to' => (string)($_GET['do'] ?? ''), 'open' => !isset($_GET['status']) || $_GET['status'] === '' ? 1 : 0];
        $scope = noclIsLogistyk($uid) ? 'all' : 'coord';
        return $this->render('queue', ['rows' => noclRequestList($uid, $scope, $f), 'f' => $f, 'budgets' => noclBudgetList(), 'counts' => noclCounts($uid, $scope)]);
    }

    public function approve(int $id): ?string {
        $this->flash(noclApproveBudget($id, noclUid(), ($_POST['do'] ?? 'tak') !== 'cofnij'), 'Zapisano decyzję o koszcie.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    // ── Filar 2: logistyka ────────────────────────────────────────────────────

    public function assign(int $id): ?string {
        $uid = noclUid();
        $rid = (int)($_POST['room_id'] ?? 0);
        $err = $rid ? noclAssignRoom($id, $rid, $uid) : noclUnassignRoom($id, $uid);
        $this->flash($err, $rid ? 'Przydzielono pokój.' : 'Zdjęto przydział pokoju.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function setBudget(int $id): ?string {
        $this->flash(noclSetBudget($id, (int)($_POST['budget_id'] ?? 0) ?: null, noclUid()), 'Zmieniono budżet zgłoszenia.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function sendInstruction(int $id): ?string {
        $ok = noclSendInstruction($id, true);
        flash_set($ok ? 'success' : 'warning', $ok ? 'Instrukcja zameldowania trafiła do kolejki e-mail.' : 'Nie wysłano: zgłoszenie musi być potwierdzone z przydzielonym pokojem, a osoba mieć adres e-mail.');
        return $this->back('nocl.show', ['id' => $id]);
    }

    public function facilities(): string { return $this->render('facilities', ['rows' => noclFacilityList()]); }
    public function facilityNew(): string { return $this->render('facilityForm', ['f' => ['is_active' => 1, 'checkin_from' => '15:00', 'checkin_to' => '22:00', 'checkout_until' => '11:00', 'type' => 'hotel'], 'errors' => [], 'id' => 0, 'rooms' => [], 'editRoom' => null, 'roomErrors' => []]); }

    public function facilityStore(): ?string {
        [$d, $err] = noclFacilityValidate($_POST);
        if ($err) { flash_set('warning', 'Popraw zaznaczone pola.'); return $this->render('facilityForm', ['f' => $_POST, 'errors' => $err, 'id' => 0, 'rooms' => [], 'editRoom' => null, 'roomErrors' => []]); }
        $id = noclFacilitySave($d);
        flash_set('success', 'Dodano obiekt. Teraz dodaj pokoje.');
        return $this->back('nocl.facility', ['id' => $id]);
    }

    public function facility(int $id): string {
        $f = noclFacilityGet($id);
        if (!$f) { http_response_code(404); return $this->render('message', ['msg' => 'Nie znaleziono obiektu.']); }
        $editRoom = isset($_GET['pokoj']) ? noclRoomGet((int)$_GET['pokoj']) : null;
        if ($editRoom && (int)$editRoom['facility_id'] !== $id) $editRoom = null;
        return $this->render('facilityForm', ['f' => $f, 'errors' => [], 'id' => $id, 'rooms' => noclRoomList($id, false), 'editRoom' => $editRoom, 'roomErrors' => []]);
    }

    public function facilityUpdate(int $id): ?string {
        if (!noclFacilityGet($id)) { http_response_code(404); return 'Nie znaleziono obiektu.'; }
        [$d, $err] = noclFacilityValidate($_POST);
        if ($err) { flash_set('warning', 'Popraw zaznaczone pola.'); return $this->render('facilityForm', ['f' => $_POST + ['id' => $id], 'errors' => $err, 'id' => $id, 'rooms' => noclRoomList($id, false), 'editRoom' => null, 'roomErrors' => []]); }
        noclFacilitySave($d, $id);
        flash_set('success', 'Zapisano obiekt.');
        return $this->back('nocl.facility', ['id' => $id]);
    }

    public function roomSave(int $id): ?string {
        if (!noclFacilityGet($id)) { http_response_code(404); return 'Nie znaleziono obiektu.'; }
        $rid = (int)($_POST['room_id'] ?? 0);
        [$d, $err] = noclRoomValidate($_POST);
        if ($err) {
            flash_set('warning', 'Popraw dane pokoju.');
            return $this->render('facilityForm', ['f' => noclFacilityGet($id), 'errors' => [], 'id' => $id, 'rooms' => noclRoomList($id, false), 'editRoom' => $_POST + ['id' => $rid], 'roomErrors' => $err]);
        }
        if ($rid && (int)(noclRoomGet($rid)['facility_id'] ?? 0) !== $id) { http_response_code(404); return 'Nie znaleziono pokoju.'; }
        noclRoomSave($id, $d, $rid);
        flash_set('success', $rid ? 'Zapisano pokój.' : 'Dodano pokój.');
        return $this->back('nocl.facility', ['id' => $id]);
    }

    private function roomingParams(): array {
        $fid = (int)($_GET['obiekt'] ?? 0);
        $from = noclDateOk((string)($_GET['od'] ?? '')) ? (string)$_GET['od'] : date('Y-m-d');
        $to = noclDateOk((string)($_GET['do'] ?? '')) ? (string)$_GET['do'] : date('Y-m-d', strtotime('+30 days'));
        if ($to <= $from) $to = date('Y-m-d', strtotime($from . ' +1 day'));
        return [$fid, $from, $to];
    }

    public function rooming(): string {
        noclAutoComplete();
        [$fid, $from, $to] = $this->roomingParams();
        $facs = noclFacilityList(true);
        if (!$fid && $facs) $fid = (int)$facs[0]['id'];
        $unassigned = db_all("SELECT id, person_name, date_from, date_to, needs_accessible, room_type_pref, city, status FROM nocl_requests
                               WHERE room_id IS NULL AND status IN ('wniosek','w_trakcie') AND date_from < ? AND date_to > ? ORDER BY date_from", [$to, $from]);
        return $this->render('rooming', ['facs' => $facs, 'fid' => $fid, 'from' => $from, 'to' => $to, 'groups' => $fid ? noclRoomingData($fid, $from, $to) : [], 'unassigned' => $unassigned]);
    }

    public function roomingPdf(): ?string {
        [$fid, $from, $to] = $this->roomingParams();
        if (!noclFacilityGet($fid)) { http_response_code(404); return 'Nie znaleziono obiektu.'; }
        require_once dirname(__DIR__) . '/lib/noclegiPdf.php';
        $pdf = noclRoomingPdf($fid, $from, $to);
        noclLog(null, 'rooming', 'PDF obiekt #' . $fid . ' ' . $from . '–' . $to, 'facility');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="rooming_list_' . $fid . '_' . $from . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        return null;
    }

    public function roomingXlsx(): ?string {
        [$fid, $from, $to] = $this->roomingParams();
        if (!noclFacilityGet($fid)) { http_response_code(404); return 'Nie znaleziono obiektu.'; }
        require_once dirname(__DIR__, 3) . '/modules/analytics/logic/analyticsExport.php';
        $ds = noclRoomingDataset($fid, $from, $to);
        noclLog(null, 'rooming', 'Excel obiekt #' . $fid . ' ' . $from . '–' . $to, 'facility');
        AnalyticsExport::xlsx($ds, (string)(current_user()['name'] ?? ''), 'rooming_list_' . $fid . '_' . $from . '.xlsx');
        return null;
    }

    public function importTi(): ?string {
        require_once dirname(__DIR__) . '/lib/noclegiTiSync.php';
        try {
            $r = noclTiSync(noclUid());
            flash_set('success', 'Import z TI: nowych ' . $r['created'] . ', zmienionych terminów ' . $r['updated'] . ', anulowanych ' . $r['cancelled'] . ', pominiętych ' . $r['skipped'] . '.');
        } catch (\Throwable $e) {
            error_log('noclegi: import TI — ' . $e->getMessage());
            flash_set('danger', 'Nie udało się zaimportować noclegów z TI.');
        }
        return Router::redirect(route('nocl.queue'));
    }

    // ── Filar 3: finanse ──────────────────────────────────────────────────────

    public function budgets(): string {
        $uid = noclUid();
        $b = noclCoordBudgets($uid);
        $rows = array_filter(noclBudgetList(), fn($x) => $b === null || in_array((int)$x['id'], $b, true));
        $sum = []; foreach ($rows as $x) $sum[$x['id']] = noclBudgetSummary((int)$x['id']);
        $edit = noclIsLogistyk($uid) && isset($_GET['edytuj']) ? noclBudgetGet((int)$_GET['edytuj']) : null;
        return $this->render('budgets', ['rows' => $rows, 'sum' => $sum, 'edit' => $edit, 'errors' => []]);
    }

    public function budgetSave(): ?string {
        $id = (int)($_POST['id'] ?? 0);
        [$d, $err] = noclBudgetValidate($_POST, $id);
        if ($err) {
            flash_set('warning', 'Popraw dane budżetu.');
            $sum = []; $rows = noclBudgetList(); foreach ($rows as $x) $sum[$x['id']] = noclBudgetSummary((int)$x['id']);
            return $this->render('budgets', ['rows' => $rows, 'sum' => $sum, 'edit' => $_POST + ['id' => $id], 'errors' => $err]);
        }
        noclBudgetSave($d, $id);
        flash_set('success', 'Zapisano budżet.');
        return $this->back('nocl.budgets');
    }

    public function invoices(): string {
        $uid = noclUid();
        $f = ['status' => (string)($_GET['status'] ?? ''), 'budget_id' => (int)($_GET['budget_id'] ?? 0)];
        return $this->render('invoices', ['rows' => noclInvoiceList($f, noclCoordBudgets($uid)), 'f' => $f, 'budgets' => noclBudgetList(), 'facs' => noclFacilityList(), 'errors' => [], 'form' => []]);
    }

    public function invoiceStore(): ?string {
        $uid = noclUid();
        [$d, $err] = noclInvoiceValidate($_POST);
        $file = [];
        if (!$err && !empty($_FILES['file']['tmp_name'])) {
            $path = handle_upload('file', 'noclegi');
            if (!$path) $err['file'] = 'Nie udało się zapisać pliku (PDF, JPG, PNG lub DOCX, do 20 MB).';
            else $file = ['file_path' => $path, 'file_name' => mb_substr(basename((string)$_FILES['file']['name']), 0, 200)];
        }
        if ($err) {
            flash_set('warning', 'Popraw dane faktury.');
            return $this->render('invoices', ['rows' => noclInvoiceList([], null), 'f' => ['status' => '', 'budget_id' => 0], 'budgets' => noclBudgetList(), 'facs' => noclFacilityList(), 'errors' => $err, 'form' => $_POST]);
        }
        noclInvoiceCreate($d, $uid, $file);
        flash_set('success', 'Zapisano fakturę.');
        return $this->back('nocl.invoices');
    }

    public function invoiceStatus(int $id): ?string {
        $uid = noclUid();
        $i = noclInvoiceGet($id);
        if (!$i || (noclCoordBudgets($uid) !== null && !noclCoordCovers($uid, (int)$i['budget_id'] ?: null))) { http_response_code(404); return 'Nie znaleziono faktury.'; }
        $this->flash(noclInvoiceTransition($id, (string)($_POST['to'] ?? ''), $uid), 'Zmieniono status faktury.');
        return $this->back('nocl.invoices');
    }

    public function invoiceFile(int $id): ?string {
        $uid = noclUid();
        $i = noclInvoiceGet($id);
        if (!$i || !$i['file_path'] || (noclCoordBudgets($uid) !== null && !noclCoordCovers($uid, (int)$i['budget_id'] ?: null))) { http_response_code(404); return 'Nie znaleziono pliku.'; }
        $path = realpath(UPLOAD_DIR . $i['file_path']);
        if (!$path || !str_starts_with($path, realpath(UPLOAD_DIR . 'noclegi') ?: '#') || !is_file($path)) { http_response_code(404); return 'Plik niedostępny.'; }
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($i['file_name'] ?: basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        return null;
    }

    // ── Role ──────────────────────────────────────────────────────────────────

    public function roles(): string {
        return $this->render('roles', ['rows' => noclRolesList(), 'budgets' => noclBudgetList(true),
            'users' => db_all("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name")]);
    }

    public function roleAdd(): ?string {
        $ok = noclRoleAssign((int)($_POST['user_id'] ?? 0), (string)($_POST['role'] ?? ''), (int)($_POST['budget_id'] ?? 0), noclUid());
        if ($ok) noclLog(null, 'rola', 'nadano rolę ' . ($_POST['role'] ?? '') . ' użytkownikowi #' . (int)($_POST['user_id'] ?? 0), 'role');
        flash_set($ok ? 'success' : 'warning', $ok ? 'Nadano rolę.' : 'Wybierz osobę i rolę (Koordynator Projektu albo Logistyk).');
        return $this->back('nocl.roles');
    }

    public function roleDel(int $id): ?string {
        noclRoleRevoke($id);
        noclLog(null, 'rola', 'odebrano rolę (wpis #' . $id . ')', 'role');
        flash_set('success', 'Odebrano rolę.');
        return $this->back('nocl.roles');
    }
}
