<?php
/**
 * modules/crmApi/logic/CrmApiContactsController.php — kontakty (kartoteki) w API CRM.
 *
 *   GET  /contacts                       lista (filtry jak crm/index.php, strony)
 *   POST /contacts                       nowa kartoteka
 *   GET  /contacts/{id}                  kartoteka + fakty + prawa do rekordu
 *   POST /contacts/{id}                  zmiana pól (prawa do pól egzekwuje CrmManager::updateContact)
 *   POST /contacts/{id}/delete           usunięcie z powodem (jak w klasycznym CRM; spam blokuje nadawcę)
 *   GET  /contacts/{id}/notes            notatki;  POST — nowa notatka;  POST /notes/{id}/delete
 *   GET  /contacts/{id}/activity         zadania, planowane działania, historia komunikacji
 *   GET  /contacts/{id}/cases            sprawy kontaktu (także jako strony)
 *   POST /contacts/{id}/tags             {tag};  POST /contacts/{id}/tags/delete {tag}
 *   POST /contacts/bulk                  {action, ids[], value, block_scope?, note?} — jak crm/api/bulk.php
 *
 * W odróżnieniu od części starych ekranów KAŻDA operacja na rekordzie sprawdza crm_can_access_contact()
 * (konta tylko-CRM ograniczone do grup), a odczyt przechodzi przez crm_mask_contact().
 */
final class CrmApiContactsController
{
    private const FILTER_KEYS = ['q', 'q_all', 'status', 'type', 'tag', 'category', 'group', 'wojewodztwo', 'owner', 'source',
        'branza', 'powiat', 'gmina', 'created_from', 'created_to', 'last_from', 'last_to', 'stale_days', 'has_email', 'has_phone', 'critical'];

    public function index(): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $k) $filters[$k] = trim((string)($_GET[$k] ?? ''));
        $filters['group']      = (int)$filters['group'] ?: '';
        $filters['stale_days'] = (int)$filters['stale_days'] ?: '';
        $filters['category']   = preg_replace('/[^a-z0-9_]/', '', $filters['category']);
        foreach (['has_email', 'has_phone'] as $f) $filters[$f] = $filters[$f] !== '' && $filters[$f] !== '0' ? '1' : '';
        $filters['critical'] = $filters['critical'] !== '' && $filters['critical'] !== '0' ? 1 : 0;
        foreach (['created_from', 'created_to', 'last_from', 'last_to'] as $d) {
            if ($filters[$d] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$d])) $filters[$d] = '';
        }
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

        $res  = CrmManager::getContacts($filters, $page, $perPage);
        $rows = $res['rows'] ?? [];
        $prim = [];
        if ($rows) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/crmCategories.php';
                $prim = crm_primary_map(array_map(fn($r) => (int)$r['id'], $rows));
            } catch (\Throwable $e) {}
        }
        $data = [];
        foreach ($rows as $r) {
            $p = $prim[(int)$r['id']] ?? null;
            $data[] = CrmApiPresenter::contactRow(crm_mask_contact($r), $p ? ['slug' => $p['slug'], 'label' => $p['label'], 'color' => $p['color'] ?? null] : null);
        }
        $total = (int)($res['total'] ?? 0);
        return ['data' => $data, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int)ceil($total / $perPage))]];
    }

    public function show(int $id): array
    {
        $c = $this->load($id);
        $masked = crm_mask_contact($c);
        $out = CrmApiPresenter::contactFull($masked);
        $out['owner_name'] = $out['owner_id'] ? (db_one("SELECT name FROM users WHERE id=?", [$out['owner_id']])['name'] ?? null) : null;

        // Fakty (pasek pod nagłówkiem kartoteki — jak .cv-facts w crm/contact/view.php, ale liczone na całości)
        $openCases = (int)(db_one("SELECT COUNT(*) n FROM crm_cases WHERE (contact_id=? OR id IN (SELECT case_id FROM crm_case_parties WHERE contact_id=?)) AND "
            . crm_case_active_sql(), [$id, $id])['n'] ?? 0);
        $last = db_one("SELECT sent_at, direction FROM crm_communications WHERE contact_id=? ORDER BY sent_at DESC LIMIT 1", [$id]);
        $offers = null;
        if (crm_can('offers', 'read')) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/crm_offers.php';
                $s = crm_offer_contact_summary($id);
                $offers = ['open' => (int)($s['open'] ?? 0), 'won' => (int)($s['won'] ?? 0), 'won_value' => (float)($s['won_value'] ?? 0)];
            } catch (\Throwable $e) {}
        }
        $facts = [
            'open_cases' => $openCases,
            'offers' => $offers,
            'last_contact_at' => $last['sent_at'] ?? null,
            'last_contact_in' => ($last['direction'] ?? '') === 'in',
            'notes' => count($c['notes'] ?? []),
        ];
        $fieldView = $fieldEdit = [];
        foreach (array_keys(crm_perm_fields()) as $f) { $fieldView[$f] = crm_field_can_view($f); $fieldEdit[$f] = crm_field_can_edit($f); }
        $perms = [
            'edit' => crm_can('contacts', 'write'),
            'delete' => crm_can('contacts', 'delete'),
            'field_view' => $fieldView,
            'field_edit' => $fieldEdit,
        ];
        return ['data' => $out, 'facts' => $facts, 'perms' => $perms, 'legacy_url' => rtrim(APP_URL, '/') . '/crm/contact/view.php?id=' . $id];
    }

    public function store(): array
    {
        CrmApiAuth::need('contacts', 'write');
        [$data, $errors] = $this->build(CrmApiAuth::input(), true);
        if ($errors) api_json(['error' => 'Popraw zaznaczone pola.', 'fields' => $errors], 422);
        $uid = CrmApiAuth::userId();
        $data['type']   = $data['type'] ?? 'osoba';
        $data['status'] = ($data['status'] ?? '') !== '' ? $data['status'] : crm_status_default();
        $data['source'] = 'crm_app';
        $data['created_by'] = $uid;
        $data['owner_id'] = $data['owner_id'] ?? $uid;
        $id = CrmManager::createContact($data);
        // Konto ograniczone do grup nie zobaczyłoby własnej kartoteki — trafia do pierwszej dostępnej grupy (jak szybkie dodawanie).
        $groups = crm_accessible_group_ids();
        if ($groups) {
            try { CrmManager::addToGroup((int)$groups[0], $id, $uid); } catch (\Throwable $e) {}
        }
        api_audit('create', 'crm_contacts', $id, array_keys($data), 201);
        http_response_code(201);
        return $this->show($id);
    }

    public function update(int $id): array
    {
        CrmApiAuth::need('contacts', 'write');
        $this->load($id);
        [$data, $errors] = $this->build(CrmApiAuth::input(), false);
        if ($errors) api_json(['error' => 'Popraw zaznaczone pola.', 'fields' => $errors], 422);
        if (!$data) api_error('Brak pól do zmiany.', 400);
        CrmManager::updateContact($id, $data);
        api_audit('update', 'crm_contacts', $id, array_keys($data));
        return $this->show($id);
    }

    public function destroy(int $id): array
    {
        CrmApiAuth::need('contacts', 'delete');
        $this->load($id);
        $in = CrmApiAuth::input();
        $reason = (string)($in['reason'] ?? '');
        if (!isset(CRM_DELETE_REASONS[$reason])) api_json(['error' => 'Wskaż powód usunięcia.', 'fields' => ['reason' => 'Wybierz powód.']], 422);
        $scope = ($in['block_scope'] ?? 'email') === 'domain' ? 'domain' : 'email';
        $r = crm_delete_contact_reason($id, $reason, trim((string)($in['note'] ?? '')), $scope);
        if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się usunąć kartoteki.'), 409);
        api_audit('delete', 'crm_contacts', $id, ['reason']);
        return ['data' => ['id' => $id, 'deleted' => true, 'blocked' => $r['blocked'] ?? null]];
    }

    public function notes(int $id): array
    {
        $this->load($id);
        return ['data' => array_map([CrmApiPresenter::class, 'note'], db_all(
            "SELECT n.*, u.name AS author_name FROM crm_notes n LEFT JOIN users u ON u.id=n.created_by
              WHERE n.contact_id=? ORDER BY n.is_pinned DESC, n.created_at DESC", [$id]))];
    }

    public function addNote(int $id): array
    {
        CrmApiAuth::need('contacts', 'write');
        $this->load($id);
        $in = CrmApiAuth::input();
        $body = trim((string)($in['body'] ?? ''));
        if ($body === '') api_json(['error' => 'Wpisz treść notatki.', 'fields' => ['body' => 'Wpisz treść notatki.']], 422);
        if (mb_strlen($body) > 20000) api_json(['error' => 'Notatka jest za długa.', 'fields' => ['body' => 'Maksymalnie 20 000 znaków.']], 422);
        $noteId = CrmManager::addNote($id, $body, CrmApiAuth::userId(), !empty($in['pinned']));
        http_response_code(201);
        return ['data' => CrmApiPresenter::note(db_one("SELECT n.*, u.name AS author_name FROM crm_notes n LEFT JOIN users u ON u.id=n.created_by WHERE n.id=?", [$noteId]) ?? ['id' => $noteId, 'body' => $body])];
    }

    public function deleteNote(int $noteId): array
    {
        $n = db_one("SELECT * FROM crm_notes WHERE id=?", [$noteId]);
        if (!$n) api_error('Nie ma takiej notatki.', 404);
        $this->load((int)$n['contact_id']);
        CrmApiAuth::need('contacts', 'delete');   // jak w kartotece klasycznej (delete_note wymaga prawa usuwania)
        CrmManager::deleteNote($noteId);
        return ['data' => ['id' => $noteId, 'deleted' => true]];
    }

    public function activity(int $id): array
    {
        $c = $this->load($id);
        require_once dirname(__DIR__, 3) . '/includes/crm_tasks.php';
        $tasks = array_map([CrmApiPresenter::class, 'task'], crm_tasks_for_contact($id, false));
        $acts = array_map([CrmApiPresenter::class, 'activity'], db_all(
            "SELECT a.*, u.name AS assigned_name, u.first_name AS assigned_fn, u.last_name AS assigned_ln
               FROM crm_activities a LEFT JOIN users u ON u.id = a.assigned_to
              WHERE a.contact_id = ? ORDER BY a.status ASC, a.scheduled_at IS NULL, a.scheduled_at ASC, a.created_at DESC LIMIT 30", [$id]));
        $comms = array_map([CrmApiPresenter::class, 'communication'], array_values(array_filter(
            $c['communications'] ?? [], fn($m) => empty($m['crm_hidden']))));
        return ['data' => ['tasks' => $tasks, 'activities' => $acts, 'communications' => $comms]];
    }

    public function cases(int $id): array
    {
        $this->load($id);
        if (!crm_can('cases', 'read')) return ['data' => []];
        return ['data' => array_map([CrmApiPresenter::class, 'caseRow'], db_all(
            "SELECT cs.*, c.imie_nazwisko contact_name, u.name owner_name
               FROM crm_cases cs LEFT JOIN crm_contacts c ON c.id=cs.contact_id LEFT JOIN users u ON u.id=cs.owner_id
              WHERE cs.contact_id=? OR cs.id IN (SELECT case_id FROM crm_case_parties WHERE contact_id=?)
              ORDER BY cs.updated_at DESC LIMIT 100", [$id, $id]))];
    }

    public function addTag(int $id): array
    {
        CrmApiAuth::need('contacts', 'write');
        $this->load($id);
        $tag = mb_strtolower(trim((string)(CrmApiAuth::input()['tag'] ?? '')));
        if ($tag === '' || mb_strlen($tag) > 60) api_json(['error' => 'Podaj tag (do 60 znaków).', 'fields' => ['tag' => 'Podaj tag (do 60 znaków).']], 422);
        CrmManager::addTag($id, $tag);
        return ['data' => $this->tags($id)];
    }

    public function removeTag(int $id): array
    {
        CrmApiAuth::need('contacts', 'write');
        $this->load($id);
        $tag = trim((string)(CrmApiAuth::input()['tag'] ?? ''));
        if ($tag === '') api_error('Podaj tag.', 422);
        CrmManager::removeTag($id, $tag);
        return ['data' => $this->tags($id)];
    }

    public function bulk(): array
    {
        CrmApiAuth::need('contacts', 'write');
        $in = CrmApiAuth::input();
        $action = (string)($in['action'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
        if (!$ids) api_error('Zaznacz co najmniej jedną kartotekę.', 422);
        if (count($ids) > 500) api_error('Naraz można zmienić najwyżej 500 kartotek.', 422);
        $ids = array_values(array_filter($ids, fn($cid) => crm_can_access_contact($cid)
            && db_one("SELECT 1 FROM crm_contacts WHERE id=? AND crm_active=1", [$cid])));
        if (!$ids) api_error('Brak dostępnych kartotek w zaznaczeniu.', 404);
        $uid = CrmApiAuth::userId();
        $value = $in['value'] ?? '';
        $affected = 0;
        $extra = [];

        switch ($action) {
            case 'set_status':
                $status = (string)$value;
                if (!array_key_exists($status, crm_statuses())) api_error('Nieprawidłowy status.', 422);
                require_once dirname(__DIR__, 3) . '/includes/crm_automation.php';
                foreach ($ids as $cid) {
                    $before = (string)(db_one("SELECT status FROM crm_contacts WHERE id=?", [$cid])['status'] ?? '');
                    if ($before === $status) continue;
                    db()->prepare("UPDATE crm_contacts SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $cid]);
                    crm_automation_fire('contact_status_changed', $cid, ['from_status' => $before, 'to_status' => $status]);
                    $affected++;
                }
                break;
            case 'set_owner':
                $owner = (int)$value ?: null;
                if ($owner !== null && !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [$owner])) api_error('Nie ma takiego użytkownika.', 422);
                foreach ($ids as $cid) { CrmManager::updateContact($cid, ['owner_id' => $owner]); $affected++; }
                break;
            case 'add_tag':
                $tag = mb_strtolower(trim((string)$value));
                if ($tag === '') api_error('Podaj tag.', 422);
                foreach ($ids as $cid) { try { CrmManager::addTag($cid, $tag); $affected++; } catch (\Throwable $e) {} }
                break;
            case 'remove_tag':
                $tag = trim((string)$value);
                if ($tag === '') api_error('Podaj tag.', 422);
                foreach ($ids as $cid) { CrmManager::removeTag($cid, $tag); $affected++; }
                break;
            case 'add_to_group':
                $gid = (int)$value;
                $allowed = array_map(fn($g) => (int)$g['id'], CrmManager::getGroups());
                if (!in_array($gid, $allowed, true)) api_error('Wybierz grupę.', 422);
                if (crm_accessible_group_ids() !== null && !crm_group_can($gid, 'write')) api_error('Brak prawa zapisu w tej grupie.', 403);
                foreach ($ids as $cid) { try { CrmManager::addToGroup($gid, $cid, $uid); $affected++; } catch (\Throwable $e) {} }
                break;
            case 'delete':
                CrmApiAuth::need('contacts', 'delete');
                $reason = (string)$value;
                if (!isset(CRM_DELETE_REASONS[$reason])) api_error('Wskaż powód usunięcia.', 422);
                $scope = ($in['block_scope'] ?? 'email') === 'domain' ? 'domain' : 'email';
                $blocked = [];
                foreach ($ids as $cid) {
                    $r = crm_delete_contact_reason($cid, $reason, trim((string)($in['note'] ?? '')), $scope);
                    if (!empty($r['ok'])) { $affected++; if (!empty($r['blocked'])) $blocked[] = $r['blocked']; }
                }
                $extra['blocked'] = count(array_unique($blocked));
                break;
            default:
                api_error('Nieznana akcja.', 422);
        }
        api_audit('bulk_' . $action, 'crm_contacts', null, ['ids:' . count($ids)]);
        return ['data' => ['action' => $action, 'affected' => $affected] + $extra];
    }

    // ── pomocnicze ─────────────────────────────────────────────────────────────

    /** Kartoteka z kontrolą dostępu (getContact zwraca null także dla kartotek spoza grup użytkownika). */
    private function load(int $id): array
    {
        $c = CrmManager::getContact($id);
        if (!$c) api_error('Nie znaleziono kartoteki albo nie masz do niej dostępu.', 404);
        return $c;
    }

    private function tags(int $id): array
    {
        return array_map(fn($t) => (string)$t['tag'], db_all("SELECT tag FROM crm_tags WHERE contact_id=? ORDER BY tag", [$id]));
    }

    /**
     * Walidacja wejścia formularza kartoteki. Zwraca [dane, błędy pól]. Pola bez prawa edycji pomija updateContact().
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function build(array $in, bool $create): array
    {
        $data = [];
        $err = [];
        foreach (CrmApiPresenter::CONTACT_WRITABLE as $f) {
            if (!array_key_exists($f, $in)) continue;
            $v = $in[$f];
            if (is_bool($v)) $v = $v ? 1 : 0;
            if (is_string($v)) $v = trim($v);
            if ($v === '') $v = null;
            if ($v !== null && !is_scalar($v)) { $err[$f] = 'Nieprawidłowa wartość.'; continue; }
            if (is_string($v) && mb_strlen($v) > ($f === 'notatka' ? 20000 : 500)) { $err[$f] = 'Za długi tekst.'; continue; }
            $data[$f] = $v;
        }
        if (empty($data['imie_nazwisko'])) {
            $full = trim(($data['imie'] ?? '') . ' ' . ($data['nazwisko'] ?? ''));
            if ($full !== '') $data['imie_nazwisko'] = $full;
        }
        if ($create && empty($data['imie_nazwisko'])) $err['imie_nazwisko'] = 'Podaj imię i nazwisko albo nazwę.';
        if (!$create && array_key_exists('imie_nazwisko', $data) && empty($data['imie_nazwisko'])) $err['imie_nazwisko'] = 'Nazwa nie może być pusta.';
        if (isset($data['type']) && !isset(CRM_CONTACT_TYPES[$data['type']])) $err['type'] = 'Nieznany typ kartoteki.';
        if (!empty($data['status']) && !array_key_exists($data['status'], crm_statuses())) $err['status'] = 'Nieznany status.';
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'Wpisz adres e-mail w formacie nazwa@domena.pl.';
        if (!empty($data['strona_www']) && !preg_match('#^(https?://)?[^\s]+\.[^\s]+$#i', (string)$data['strona_www'])) $err['strona_www'] = 'Wpisz adres strony, np. www.feer.org.pl.';
        foreach (['nip' => 10, 'regon' => null, 'krs' => 10, 'pesel' => 11] as $f => $len) {
            if (empty($data[$f])) continue;
            $data[$f] = preg_replace('/[\s-]/', '', (string)$data[$f]);
            if (!ctype_digit($data[$f])) $err[$f] = 'Wpisz same cyfry.';
            elseif ($len !== null && strlen($data[$f]) !== $len) $err[$f] = "Numer ma {$len} cyfr.";
            elseif ($f === 'regon' && !in_array(strlen($data[$f]), [9, 14], true)) $err[$f] = 'REGON ma 9 albo 14 cyfr.';
        }
        if (!empty($data['data_urodzenia']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['data_urodzenia'])) $err['data_urodzenia'] = 'Podaj datę w formacie RRRR-MM-DD.';
        if (array_key_exists('owner_id', $data)) {
            $data['owner_id'] = (int)$data['owner_id'] ?: null;
            if ($data['owner_id'] && !db_one("SELECT 1 FROM users WHERE id=? AND is_active=1", [$data['owner_id']])) $err['owner_id'] = 'Nie ma takiego użytkownika.';
        }
        if (array_key_exists('is_critical', $data)) $data['is_critical'] = (int)!empty($data['is_critical']);
        return [$data, $err];
    }
}
