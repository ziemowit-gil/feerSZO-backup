<?php
/**
 * modules/strategy_actions/logic/ActionsController.php — akcje ekranu Działań (/dzialania).
 * Dostęp: admin i redaktor (middleware trasy); usuwanie — admin albo autor działania.
 */
require_once __DIR__ . '/ActionsService.php';

final class ActionsController
{
    private const VIEW = __DIR__ . '/../view/';

    private function uid(): int { return (int)(current_user()['id'] ?? 0); }

    private function render(string $view, array $data): string
    {
        return Router::view(self::VIEW . $view . '.php', $data);
    }

    private function notFound(): ?string
    {
        flash_set('danger', 'Działanie nie istnieje albo zostało usunięte.');
        return Router::redirect(ActionsService::url());
    }

    /** GET /dzialania — lista / kalendarz (filtry z dawnych adresów strategy/actions/index.php?status=… zachowane). */
    public function index(): string
    {
        $g = fn(string $k) => trim((string)($_GET[$k] ?? ''));
        return $this->render('index', [
            'pageTitle' => 'Działania',
            'actions'   => ActionsService::all(),
            'users'     => ActionsService::coordinators(),
            'grants'    => ActionsService::grantsList(),
            'init'      => ['status' => $g('status'), 'typ' => $g('typ'), 'koord' => $g('koordynator_id'),
                            'grant' => $g('grant_id'), 'od' => $g('data_od'), 'do' => $g('data_do'),
                            'cel' => $g('cel'), 'q' => $g('q'), 'widok' => $g('widok')],
        ]);
    }

    /** GET /dzialania/{id} — karta działania. */
    public function show($id): ?string
    {
        $id = (int)$id;
        $a = ActionsService::find($id);
        if (!$a) return $this->notFound();
        [$crmOn, $participants, $candidates] = $this->crm($id);
        return $this->render('show', [
            'pageTitle'    => $a['nazwa'],
            'a'            => $a,
            'v'            => ActionsService::one($id),
            'crmOn'        => $crmOn,
            'participants' => $participants,
            'candidates'   => $candidates,
            'canDelete'    => ActionsService::canDelete($a),
        ]);
    }

    /** GET /dzialania/nowe[?grant_id=] */
    public function create(): string
    {
        $row = ['typ' => 'inne', 'status' => 'planowane', 'forma' => 'stacjonarne', 'korzysci_tytul' => 'Korzyści',
                'wlasne_dzialanie' => 0, 'cykliczne' => 0, 'grants' => [], 'indicators' => []];
        $gid = (int)($_GET['grant_id'] ?? 0);
        if ($gid && grant_by_id($gid)) $row['grants'] = [['grant_id' => $gid, 'udzial_procent' => null, 'kwota' => null]];
        return $this->form($row, null, []);
    }

    /** POST /dzialania/nowe */
    public function store(): ?string
    {
        $d = ActionsService::normalize($_POST);
        $grants = ActionsService::grantRows($_POST);
        $inds = ActionsService::indicatorRows($_POST);
        if ($errors = ActionsService::validate($d)) {
            return $this->form($d + ['grants' => $grants, 'indicators' => $inds], null, $errors);
        }
        $id = ActionsService::save(null, $d, $grants, $inds, $this->uid());
        flash_set('success', 'Działanie zostało dodane.');
        return Router::redirect(ActionsService::url((string)$id));
    }

    /** GET /dzialania/{id}/edycja */
    public function edit($id): ?string
    {
        $id = (int)$id;
        $a = ActionsService::find($id);
        if (!$a) return $this->notFound();
        return $this->form($a, $id, []);
    }

    /** POST /dzialania/{id}/edycja */
    public function update($id): ?string
    {
        $id = (int)$id;
        $a = ActionsService::find($id);
        if (!$a) return $this->notFound();
        $d = ActionsService::normalize($_POST);
        $grants = ActionsService::grantRows($_POST);
        $inds = ActionsService::indicatorRows($_POST);
        if ($errors = ActionsService::validate($d)) {
            return $this->form($d + ['grants' => $grants, 'indicators' => $inds, 'jrwa_symbol' => $a['jrwa_symbol']], $id, $errors);
        }
        ActionsService::save($id, $d, $grants, $inds, $this->uid());
        flash_set('success', 'Zmiany zostały zapisane.');
        return Router::redirect(ActionsService::url((string)$id));
    }

    /** POST /dzialania/{id}/usun */
    public function destroy($id): ?string
    {
        $id = (int)$id;
        $a = ActionsService::find($id);
        if (!$a) return $this->notFound();
        if (!ActionsService::canDelete($a)) {
            flash_set('danger', 'Działanie może usunąć tylko administrator albo osoba, która je dodała.');
            return Router::redirect(ActionsService::url((string)$id));
        }
        ActionsService::delete($id);
        flash_set('success', 'Usunięto działanie „' . $a['nazwa'] . '”.');
        return Router::redirect(ActionsService::url());
    }

    /** POST /dzialania/{id}/uczestnicy — dopisanie kontaktu CRM do działania. */
    public function participantAdd($id): ?string
    {
        $id = (int)$id;
        [$crmOn] = $this->crm($id, false);
        $cid = (int)($_POST['contact_id'] ?? 0);
        if ($crmOn && $cid && action_by_id($id)) {
            $rola = in_array($_POST['rola'] ?? '', self::crmRoles(), true) ? $_POST['rola'] : 'uczestnik';
            CrmManager::linkToAction($cid, $id, $rola, mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 200));
            $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id = ?", [$cid]);
            flash_set('success', ($c['imie_nazwisko'] ?? 'Kontakt') . ' dodany jako uczestnik.');
        }
        return Router::redirect(ActionsService::url((string)$id) . '#uczestnicy');
    }

    /** POST /dzialania/{id}/uczestnicy/usun */
    public function participantRemove($id): ?string
    {
        $id = (int)$id;
        [$crmOn] = $this->crm($id, false);
        $cid = (int)($_POST['contact_id'] ?? 0);
        if ($crmOn && $cid) {
            CrmManager::unlinkFromAction($cid, $id);
            flash_set('success', 'Uczestnik usunięty z działania.');
        }
        return Router::redirect(ActionsService::url((string)$id) . '#uczestnicy');
    }

    /** POST /dzialania/{id}/status (JSON) — przeniesienie na tablicy / zmiana w panelu. */
    public function status($id): array
    {
        $id = (int)$id;
        $status = (string)($_POST['status'] ?? '');
        if (!isset(ActionsService::STATUSES[$status])) return $this->fail('Nieznany status.');
        if (ActionsService::setStatus($id, $status) === null) return $this->fail('Działanie nie istnieje.', 404);
        return ['ok' => true, 'action' => ActionsService::one($id),
                'message' => 'Status zmieniony na „' . ActionsService::statusLabel($status) . '”.'];
    }

    /** GET /dzialania/{id}/panel (JSON) — panel szczegółów na liście. */
    public function panel($id): array
    {
        $p = ActionsService::panel((int)$id);
        return $p ? ['ok' => true] + $p : $this->fail('Działanie nie istnieje.', 404);
    }

    /** POST /dzialania/{id}/wskazniki (JSON) — wykonanie wskaźników wpisane w panelu: real[ID]=wartość. */
    public function indicators($id): array
    {
        $id = (int)$id;
        if (!action_by_id($id)) return $this->fail('Działanie nie istnieje.', 404);
        $changes = ActionsService::setIndicatorValues($id, (array)($_POST['real'] ?? []));
        return ['ok' => true, 'action' => ActionsService::one($id), 'changed' => count($changes),
                'message' => $changes ? 'Zapisano wykonanie wskaźników (' . count($changes) . ').' : 'Bez zmian we wskaźnikach.'];
    }

    /** POST /dzialania/{id}/duplikuj — kopia działania, od razu do edycji. */
    public function duplicate($id): ?string
    {
        $newId = ActionsService::duplicate((int)$id, $this->uid());
        if (!$newId) return $this->notFound();
        flash_set('success', 'Utworzono kopię działania — uzupełnij nazwę i terminy.');
        return Router::redirect(ActionsService::url($newId . '/edycja'));
    }

    /** GET /dzialania/kalendarz.json — zdarzenia FullCalendar (zastępuje strategy/actions/calendar.php). */
    public function calendarFeed(): array
    {
        return ActionsService::calendarEvents(ActionsService::all());
    }

    public static function crmRoles(): array
    {
        return ['uczestnik', 'wolontariusz', 'prelegent', 'koordynator', 'beneficjent', 'inny'];
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    /** Odpowiedź JSON z błędem (kod HTTP 4xx). */
    private function fail(string $msg, int $code = 422): array
    {
        http_response_code($code);
        return ['ok' => false, 'error' => $msg];
    }

    private function form(array $row, ?int $id, array $errors): string
    {
        return $this->render('form', [
            'pageTitle' => $id ? 'Edycja: ' . ($row['nazwa'] ?? '') : 'Nowe działanie',
            'row'       => $row,
            'id'        => $id,
            'errors'    => $errors,
            'users'     => ActionsService::coordinators(),
            'grants'    => ActionsService::grantsList(),
        ]);
    }

    /** @return array{0:bool,1:array,2:array} [CRM włączony, uczestnicy, kontakty do dodania] */
    private function crm(int $id, bool $load = true): array
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/crm.php';
            crm_migrate();
        } catch (\Throwable $e) {
            return [false, [], []];
        }
        if (!$load) return [true, [], []];
        return [true, CrmManager::getActionContacts($id), CrmManager::getContactsNotInAction($id)];
    }
}
