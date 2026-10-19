<?php
/**
 * modules/strategy_actions/logic/ActionsController.php — akcje ekranu Działań (/dzialania).
 * Dostęp: admin i redaktor (middleware trasy); usuwanie — admin albo autor działania.
 */
require_once __DIR__ . '/ActionsService.php';
require_once __DIR__ . '/ActionsReport.php';
require_once __DIR__ . '/ActionsGrants.php';

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
            'budgets'   => ActionsGrants::budgets(),
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
            'journal'      => ActionsService::journal($id),
            'budgets'      => ActionsGrants::budgets(),
            'ezdOn'        => ActionsEzd::enabled(),
            'ezdCases'     => ActionsEzd::cases($id),
            'users'        => ActionsService::coordinators(),
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
        ActionsJournal::log($id, 'create', 'Utworzono działanie.');
        flash_set('success', 'Działanie zostało dodane.' . $this->autoCase($id, $d));
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
        $what = ActionsJournal::describeChanges($a, $d, $grants, $inds);
        ActionsService::save($id, $d, $grants, $inds, $this->uid());
        if ($what !== '') ActionsJournal::log($id, 'change', $what);
        flash_set('success', $what !== '' ? 'Zmiany zostały zapisane.' : 'Zapisano — bez zmian.');
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
            ActionsJournal::log($id, 'crm', 'Dodano uczestnika: ' . ($c['imie_nazwisko'] ?? '#' . $cid) . ' (' . $rola . ').');
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
            $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id = ?", [$cid]);
            CrmManager::unlinkFromAction($cid, $id);
            ActionsJournal::log($id, 'crm', 'Usunięto uczestnika: ' . ($c['imie_nazwisko'] ?? '#' . $cid) . '.');
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
        $prev = ActionsService::setStatus($id, $status);
        if ($prev === null) return $this->fail('Działanie nie istnieje.', 404);
        if ($prev !== $status) {
            ActionsJournal::log($id, 'status', 'Status: ' . ActionsService::statusLabel($prev) . ' → ' . ActionsService::statusLabel($status) . '.');
        }
        return ['ok' => true, 'action' => ActionsService::one($id), 'journal' => ActionsService::journal($id),
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
        foreach ($changes as $c) {
            ActionsJournal::log($id, 'indicator', 'Wykonanie wskaźnika „' . $c['nazwa'] . '”: ' . $this->n($c['from']) . ' → ' . $this->n($c['to']) . '.');
        }
        return ['ok' => true, 'action' => ActionsService::one($id), 'changed' => count($changes), 'journal' => ActionsService::journal($id),
                'message' => $changes ? 'Zapisano wykonanie wskaźników (' . count($changes) . ').' : 'Bez zmian we wskaźnikach.'];
    }

    /** POST /dzialania/{id}/duplikuj — kopia działania, od razu do edycji. */
    public function duplicate($id): ?string
    {
        $newId = ActionsService::duplicate((int)$id, $this->uid());
        if (!$newId) return $this->notFound();
        $copy = ActionsService::find($newId);
        flash_set('success', 'Utworzono kopię działania — uzupełnij nazwę i terminy.' . ($copy ? $this->autoCase($newId, $copy) : ''));
        return Router::redirect(ActionsService::url($newId . '/edycja'));
    }

    /** POST /dzialania/{id}/etapy (JSON) — nowy etap: title, due, owner. */
    public function milestoneAdd($id): array
    {
        $id = (int)$id;
        if (!action_by_id($id)) return $this->fail('Działanie nie istnieje.', 404);
        $due = trim((string)($_POST['due'] ?? ''));
        $err = ActionsJournal::addMilestone($id, (string)($_POST['title'] ?? ''), $due !== '' ? $due : null, (int)($_POST['owner'] ?? 0) ?: null);
        return $err ? $this->fail($err) : $this->journalResponse($id, 'Dodano etap.');
    }

    /** POST /dzialania/{id}/etapy/{ms}/przelacz (JSON) — odhaczenie / ponowne otwarcie etapu. */
    public function milestoneToggle($id, $ms): array
    {
        $err = ActionsJournal::toggleMilestone((int)$id, (int)$ms);
        return $err ? $this->fail($err, 404) : $this->journalResponse((int)$id, 'Zapisano etap.');
    }

    /** POST /dzialania/{id}/etapy/{ms}/usun (JSON) */
    public function milestoneDelete($id, $ms): array
    {
        $err = ActionsJournal::deleteMilestone((int)$id, (int)$ms);
        return $err ? $this->fail($err, 404) : $this->journalResponse((int)$id, 'Usunięto etap.');
    }

    /** POST /dzialania/{id}/komentarz (JSON) — komentarz zespołu w historii działania. */
    public function comment($id): array
    {
        $id = (int)$id;
        if (!action_by_id($id)) return $this->fail('Działanie nie istnieje.', 404);
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') return $this->fail('Wpisz treść komentarza.');
        ActionsJournal::log($id, 'comment', $text);
        return $this->journalResponse($id, 'Dodano komentarz.');
    }

    private function journalResponse(int $id, string $message): array
    {
        return ['ok' => true, 'message' => $message, 'journal' => ActionsService::journal($id), 'action' => ActionsService::one($id)];
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }

    /** POST /dzialania/eksport — plik CSV / Excel: format, cols[] (kolumny), ids[] (działania po filtrach ekranu). */
    public function export(): ?string
    {
        $ds = ActionsReport::dataset((array)($_POST['ids'] ?? []), array_map('strval', (array)($_POST['cols'] ?? [])));
        ActionsReport::send($ds, ($_POST['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv', (string)(current_user()['name'] ?? ''));
        return null;
    }

    /** POST /dzialania/ics/link (JSON) — osobisty adres subskrypcji kalendarza; reset=1 unieważnia poprzedni. */
    public function icsLink(): array
    {
        return ['ok' => true, 'url' => ActionsReport::icsUrl($this->uid(), !empty($_POST['reset'])),
                'message' => !empty($_POST['reset']) ? 'Wygenerowano nowy adres — poprzedni przestał działać.' : ''];
    }

    /** GET /dzialania/ics/{token}.ics — kalendarz bez logowania (token zamiast sesji). */
    public function icsFeed($token): ?string
    {
        $token = preg_replace('/\.ics$/', '', (string)$token);
        if (!ActionsReport::icsOwner($token)) {
            http_response_code(404);
            return 'Nie znaleziono kalendarza.';
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="dzialania.ics"');
        header('Cache-Control: no-store');
        echo ActionsReport::ics();
        return null;
    }

    /** GET /dzialania/raport?rok= — realizacja celów statutowych §6. */
    public function report(): string
    {
        [$year, $years] = $this->reportYear();
        return $this->render('report', ['pageTitle' => 'Raport celów §6', 'year' => $year, 'years' => $years,
                                        'report' => ActionsReport::goalsReport($year)]);
    }

    /** GET /dzialania/raport/eksport?rok=&format=csv|xlsx */
    public function reportExport(): ?string
    {
        [$year] = $this->reportYear();
        $ds = ActionsReport::goalsDataset(ActionsReport::goalsReport($year), $year);
        $name = 'dzialania_cele_par6_' . ($year ?? 'wszystkie') . '_' . date('Y-m-d');
        if (($_GET['format'] ?? '') === 'xlsx') AnalyticsExport::xlsx($ds, (string)(current_user()['name'] ?? ''), $name . '.xlsx');
        else AnalyticsExport::csv($ds, $name . '.csv');
        return null;
    }

    /** @return array{0:?int,1:list<int>} wybrany rok (null = wszystkie) i lata do przełącznika */
    private function reportYear(): array
    {
        $years = ActionsReport::years();
        $rok = (string)($_GET['rok'] ?? (string)date('Y'));
        return [$rok === 'wszystkie' ? null : (in_array((int)$rok, $years, true) ? (int)$rok : (int)date('Y')), $years];
    }

    /** POST /dzialania/granty/{gid}/podepnij — istniejące działanie do grantu (z karty grantu): action_id, kwota, udzial. */
    public function grantLink($gid): ?string
    {
        $gid = (int)$gid;
        $err = ActionsGrants::link($gid, (int)($_POST['action_id'] ?? 0), ActionsService::num($_POST['kwota'] ?? ''), ActionsService::num($_POST['udzial'] ?? ''));
        flash_set($err ? 'danger' : 'success', $err ?: 'Działanie podpięte do grantu.');
        return Router::redirect(APP_URL . '/grants/view.php?id=' . $gid . '#dzialania');
    }

    /** POST /dzialania/granty/{gid}/odepnij — action_id */
    public function grantUnlink($gid): ?string
    {
        $gid = (int)$gid;
        $err = ActionsGrants::unlink($gid, (int)($_POST['action_id'] ?? 0));
        flash_set($err ? 'danger' : 'success', $err ?: 'Działanie odpięte od grantu.');
        return Router::redirect(APP_URL . '/grants/view.php?id=' . $gid . '#dzialania');
    }

    /** GET /dzialania/granty/{gid}/eksport?format=csv|xlsx — działania grantu ze wskaźnikami (do sprawozdania). */
    public function grantExport($gid): ?string
    {
        $ds = ActionsGrants::reportDataset((int)$gid);
        if (!$ds) { http_response_code(404); return 'Nie znaleziono grantu.'; }
        $name = 'grant_' . (int)$gid . '_dzialania_' . date('Y-m-d');
        if (($_GET['format'] ?? '') === 'csv') AnalyticsExport::csv($ds, $name . '.csv');
        else AnalyticsExport::xlsx($ds, (string)(current_user()['name'] ?? ''), $name . '.xlsx');
        return null;
    }

    /** POST /dzialania/{id}/ezd/koszulka — nowa koszulka EZD dla działania (segregator tworzony w razie potrzeby). */
    public function ezdCreate($id): ?string
    {
        $id = (int)$id;
        try {
            [, $znak] = ActionsEzd::createCase($id, $this->uid());
            flash_set('success', 'Założono koszulkę EZD ' . $znak . '.');
        } catch (\RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(ActionsService::url((string)$id) . '#ezd');
    }

    /** POST /dzialania/{id}/ezd/przypnij — istniejąca koszulka po znaku. */
    public function ezdAttach($id): ?string
    {
        $id = (int)$id;
        $err = ActionsEzd::attach($id, (string)($_POST['znak'] ?? ''), $this->uid());
        flash_set($err ? 'danger' : 'success', $err ?: 'Przypięto koszulkę EZD.');
        return Router::redirect(ActionsService::url((string)$id) . '#ezd');
    }

    /** POST /dzialania/{id}/ezd/{sid}/odepnij */
    public function ezdDetach($id, $sid): ?string
    {
        $id = (int)$id;
        $err = ActionsEzd::detach($id, (int)$sid, $this->uid());
        flash_set($err ? 'danger' : 'success', $err ?: 'Odpięto koszulkę — zostaje w EZD bez powiązania z działaniem.');
        return Router::redirect(ActionsService::url((string)$id) . '#ezd');
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

    /**
     * Koszulka EZD zakładana automatycznie przy dodaniu działania z celem §6 (gdy EZD jest włączone).
     * Zwraca dopisek do komunikatu; błąd EZD nie blokuje zapisu działania.
     */
    private function autoCase(int $id, array $d): string
    {
        if (empty($d['cel_statutowy']) || !ActionsEzd::enabled()) return '';
        try {
            [, $znak] = ActionsEzd::createCase($id, $this->uid());
            return ' Założono koszulkę EZD ' . $znak . '.';
        } catch (\Throwable $e) {
            error_log('[dzialania] auto-koszulka EZD #' . $id . ': ' . $e->getMessage());
            return ' Koszulki EZD nie założono: ' . ($e instanceof \RuntimeException ? $e->getMessage() : 'błąd EZD') . ' Możesz ją założyć na karcie działania.';
        }
    }

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
            'budgets'   => ActionsGrants::budgets($id),
            'ezdOn'     => ActionsEzd::enabled(),
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
