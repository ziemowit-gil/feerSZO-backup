<?php
/**
 * modules/crmApi/logic/CrmApiCasesController.php — sprawy CRM w API.
 *
 *   GET  /cases                   rejestr (filtry jak crm/cases/index.php: q, status, priority, mine, noowner, overdue, type_id, contact_id)
 *   POST /cases                   nowa sprawa (jak crm/cases/add.php: numer CRM/NNNN/RRRR, zdarzenie case_created)
 *   GET  /cases/{id}              karta sprawy: dane, SLA, notatki, historia statusów, strony
 *   POST /cases/{id}/notes        {body}
 *   POST /cases/{id}/status       {status, reason} — zamknięcie wymaga powodu (crm_case_close_reasons)
 *
 * Prawo zapisu do sprawy = crm_case_can_edit() (admin, prawo zapisu CRM, autor, udostępnienie do edycji).
 * Konta ograniczone do grup widzą tylko sprawy kontaktów ze swoich grup.
 */
final class CrmApiCasesController
{
    public function __construct()
    {
        require_once dirname(__DIR__, 3) . '/includes/crm_case_extras.php';
        CrmApiAuth::need('cases', 'read');
    }

    public function index(): array
    {
        [$acl, $aclP] = CrmApiAuth::contactAcl('cs.contact_id');
        $uid = CrmApiAuth::userId();
        $where = ['1=1'];
        $p = [];
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(cs.title LIKE ? OR ct.imie_nazwisko LIKE ? OR cs.case_number LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%");
        }
        $status = (string)($_GET['status'] ?? '');
        if ($status === 'active') $where[] = crm_case_active_sql('cs.status');
        elseif ($status !== '' && array_key_exists($status, crm_case_statuses())) { $where[] = 'cs.status=?'; $p[] = $status; }
        $prio = (string)($_GET['priority'] ?? '');
        if (in_array($prio, ['low', 'medium', 'high'], true)) { $where[] = 'cs.priority=?'; $p[] = $prio; }
        if (!empty($_GET['mine']))    { $where[] = 'cs.owner_id=?'; $p[] = $uid; }
        if (!empty($_GET['noowner'])) $where[] = '(cs.owner_id IS NULL OR cs.owner_id=0)';
        if (!empty($_GET['overdue'])) { $where[] = 'cs.due_date IS NOT NULL AND date(cs.due_date) < ? AND ' . crm_case_active_sql('cs.status'); $p[] = date('Y-m-d'); }
        if ((int)($_GET['type_id'] ?? 0) > 0)    { $where[] = 'cs.type_id=?'; $p[] = (int)$_GET['type_id']; }
        if ((int)($_GET['contact_id'] ?? 0) > 0) { $where[] = 'cs.contact_id=?'; $p[] = (int)$_GET['contact_id']; }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where) . $acl;
        $params = array_merge($p, $aclP);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $from = "FROM crm_cases cs LEFT JOIN crm_contacts ct ON ct.id=cs.contact_id LEFT JOIN users u ON u.id=cs.owner_id";
        $total = (int)(db_one("SELECT COUNT(*) n $from $sqlWhere", $params)['n'] ?? 0);
        $rows = db_all("SELECT cs.*, ct.imie_nazwisko contact_name, u.name owner_name,
                               (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=cs.id) notes_count
                        $from $sqlWhere ORDER BY cs.updated_at DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));

        $countActive = static fn(string $extra, array $ep = []) => (int)(db_one(
            "SELECT COUNT(*) n FROM crm_cases cs WHERE " . crm_case_active_sql('cs.status') . " $extra $acl", array_merge($ep, $aclP))['n'] ?? 0);
        return [
            'data' => array_map([CrmApiPresenter::class, 'caseRow'], $rows),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))],
            'counters' => [
                'active'  => $countActive(''),
                'mine'    => $countActive('AND cs.owner_id=?', [$uid]),
                'noowner' => $countActive('AND (cs.owner_id IS NULL OR cs.owner_id=0)'),
                'overdue' => $countActive('AND cs.due_date IS NOT NULL AND date(cs.due_date) < ?', [date('Y-m-d')]),
            ],
        ];
    }

    public function show(int $id): array
    {
        $case = $this->load($id);
        $notes = array_map(fn($n) => [
            'id' => (int)$n['id'], 'body' => (string)$n['body'], 'created_at' => $n['created_at'],
            'author' => trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: ($n['user_name'] ?? null),
        ], db_all("SELECT n.*, u.name user_name, u.first_name, u.last_name FROM crm_case_notes n LEFT JOIN users u ON u.id=n.created_by
                    WHERE n.case_id=? ORDER BY n.created_at DESC", [$id]));
        $history = [];
        try {
            foreach (crm_case_status_history($id) as $h) {
                $history[] = ['from' => $h['from_status'] ?? null, 'to' => $h['to_status'] ?? null,
                              'from_label' => $h['from_status'] ? crm_case_status_label($h['from_status']) : null,
                              'to_label' => crm_case_status_label($h['to_status'] ?? ''),
                              'reason' => $h['reason'] ?? null, 'user' => $h['user_name'] ?? null, 'at' => $h['created_at'] ?? null];
            }
        } catch (\Throwable $e) {}
        $sla = null;
        try {
            $s = crm_case_sla($case);
            if (!empty($s['has'])) {
                $fmt = static fn($x) => $x ? ['deadline' => !empty($x['deadline']) ? date('Y-m-d H:i', (int)$x['deadline']) : null,
                                              'state' => $x['state'] ?? null, 'left_h' => $x['left_h'] ?? null] : null;
                $sla = ['response' => $fmt($s['response'] ?? null), 'close' => $fmt($s['close'] ?? null)];
            }
        } catch (\Throwable $e) {}
        $parties = [];
        try {
            require_once dirname(__DIR__, 3) . '/includes/crm_case_parties.php';
            foreach (crm_case_parties($id) as $pt) {
                $parties[] = ['contact_id' => (int)$pt['id'], 'name' => $pt['imie_nazwisko'] ?? null,
                              'role' => $pt['role_label'] ?? ($pt['role'] ?? null), 'is_main' => !empty($pt['is_main'])];
            }
        } catch (\Throwable $e) {}
        $type = !empty($case['type_id']) ? crm_case_type((int)$case['type_id']) : null;

        return ['data' => CrmApiPresenter::caseRow($case) + [
            'description' => $case['description'] ?? null,
            'closed_reason' => $case['closed_reason'] ?? null,
            'closed_reason_label' => !empty($case['closed_reason']) ? (crm_case_close_reasons()[$case['closed_reason']] ?? $case['closed_reason']) : null,
            'type_name' => $type['name'] ?? null,
            'creator_name' => $case['creator_name'] ?? null,
            'sla' => $sla, 'notes' => $notes, 'history' => $history, 'parties' => $parties,
        ], 'perms' => ['edit' => crm_case_can_edit($case)], 'legacy_url' => rtrim(APP_URL, '/') . '/crm/cases/view.php?id=' . $id];
    }

    public function store(): array
    {
        CrmApiAuth::need('cases', 'write');
        $in = CrmApiAuth::input();
        $err = [];
        $cid = (int)($in['contact_id'] ?? 0);
        $title = trim((string)($in['title'] ?? ''));
        if ($cid <= 0 || !db_one("SELECT 1 FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]) || !crm_can_access_contact($cid)) $err['contact_id'] = 'Wybierz kontakt.';
        if ($title === '') $err['title'] = 'Wpisz tytuł sprawy.';
        elseif (mb_strlen($title) > 300) $err['title'] = 'Tytuł może mieć najwyżej 300 znaków.';
        $due = trim((string)($in['due_date'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $err['due_date'] = 'Podaj datę w formacie RRRR-MM-DD.';
        $typeId = (int)($in['type_id'] ?? 0) ?: null;
        if ($typeId && !crm_case_type($typeId)) $err['type_id'] = 'Nieznany typ sprawy.';
        $ownerId = (int)($in['owner_id'] ?? 0) ?: CrmApiAuth::userId();
        if (!db_one("SELECT 1 FROM users WHERE id=? AND is_active=1", [$ownerId])) $err['owner_id'] = 'Nie ma takiego użytkownika.';
        if ($err) api_json(['error' => 'Popraw zaznaczone pola.', 'fields' => $err], 422);

        $now = date('Y-m-d H:i:s');
        $caseId = db_insert('crm_cases', [
            'contact_id' => $cid, 'title' => $title, 'description' => trim((string)($in['description'] ?? '')) ?: null,
            'status' => 'open', 'priority' => in_array($in['priority'] ?? '', ['low', 'medium', 'high'], true) ? $in['priority'] : 'medium',
            'type_id' => $typeId, 'owner_id' => $ownerId, 'due_date' => $due ?: null,
            'created_by' => CrmApiAuth::userId(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        crm_case_ensure_number((int)$caseId);
        require_once dirname(__DIR__, 3) . '/includes/crm_automation.php';
        crm_automation_fire('case_created', $cid, ['case_id' => $caseId]);
        api_audit('create', 'crm_cases', (int)$caseId, ['contact_id', 'title']);
        http_response_code(201);
        return $this->show((int)$caseId);
    }

    public function addNote(int $id): array
    {
        $case = $this->load($id);
        if (!crm_case_can_edit($case)) api_error('Brak prawa zapisu w tej sprawie.', 403);
        $body = trim((string)(CrmApiAuth::input()['body'] ?? ''));
        if ($body === '') api_json(['error' => 'Wpisz treść notatki.', 'fields' => ['body' => 'Wpisz treść notatki.']], 422);
        db_insert('crm_case_notes', ['case_id' => $id, 'body' => $body, 'created_by' => CrmApiAuth::userId(), 'created_at' => date('Y-m-d H:i:s')]);
        db()->prepare("UPDATE crm_cases SET updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        http_response_code(201);
        return $this->show($id);
    }

    public function setStatus(int $id): array
    {
        $case = $this->load($id);
        if (!crm_case_can_edit($case)) api_error('Brak prawa zapisu w tej sprawie.', 403);
        $in = CrmApiAuth::input();
        $to = (string)($in['status'] ?? '');
        $reason = trim((string)($in['reason'] ?? ''));
        if (!array_key_exists($to, crm_case_statuses())) api_json(['error' => 'Wybierz status.', 'fields' => ['status' => 'Wybierz status.']], 422);
        if (crm_case_is_closed($to) && !array_key_exists($reason, crm_case_close_reasons())) {
            api_json(['error' => 'Zamknięcie sprawy wymaga powodu.', 'fields' => ['reason' => 'Wybierz powód zamknięcia.']], 422);
        }
        $r = crm_case_set_status($id, $to, $reason, CrmApiAuth::userId());
        if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się zmienić statusu.'), 409);
        return $this->show($id);
    }

    private function load(int $id): array
    {
        $case = db_one("SELECT cs.*, ct.imie_nazwisko contact_name, u.name owner_name, cr.name creator_name
                          FROM crm_cases cs LEFT JOIN crm_contacts ct ON ct.id=cs.contact_id
                          LEFT JOIN users u ON u.id=cs.owner_id LEFT JOIN users cr ON cr.id=cs.created_by
                         WHERE cs.id=?", [$id]);
        if (!$case || (!empty($case['contact_id']) && !crm_can_access_contact((int)$case['contact_id']))) {
            api_error('Nie znaleziono sprawy albo nie masz do niej dostępu.', 404);
        }
        return $case;
    }
}
