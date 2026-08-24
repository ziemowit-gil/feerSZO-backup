<?php
/**
 * crm/api/bulk.php — Operacje masowe na kontaktach CRM.
 * POST JSON: { action, ids[], ...params }
 * actions: set_status, set_owner, set_owner_split, add_tag, remove_tag, add_to_group, convert_type, delete
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_automation.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user())               { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Wymagane logowanie.']); exit; }

crm_require_json('contacts', 'write');
if (!can_write('crm') && !is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Brak uprawnień.']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$ids    = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? []))));
$uid    = (int)(current_user()['id'] ?? 0);

if (!$ids)    { echo json_encode(['ok'=>false,'error'=>'Brak wybranych kontaktów.']); exit; }

// CSRF — sprawdź token przesłany w polu _csrf
if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Błąd CSRF.']); exit;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$affected = 0;

try {
    if ($action === 'set_status') {
        $status = trim($body['value'] ?? '');
        if (!array_key_exists($status, crm_statuses())) { echo json_encode(['ok'=>false,'error'=>'Nieprawidłowy status.']); exit; }
        // Stare statusy — potrzebne do kontekstu zdarzenia contact_status_changed
        $before = db_all("SELECT id, status FROM crm_contacts WHERE id IN ($ph) AND crm_active=1", $ids);
        $st = db()->prepare("UPDATE crm_contacts SET status=?,updated_at=datetime('now') WHERE id IN ($ph) AND crm_active=1");
        $st->execute(array_merge([$status], $ids));
        $affected = $st->rowCount();
        foreach ($before as $c) {
            if ($c['status'] !== $status) {
                crm_automation_fire('contact_status_changed', (int)$c['id'], ['from_status' => $c['status'], 'to_status' => $status]);
            }
        }

    } elseif ($action === 'set_owner') {
        // Wartość 0 / puste = zdejmij opiekuna. Zapis idzie przez updateContact(),
        // żeby zmiana trafiła do historii zmian kartoteki.
        $owner = (int)($body['value'] ?? 0) ?: null;
        if ($owner !== null && !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [$owner])) {
            echo json_encode(['ok'=>false,'error'=>'Nie ma takiego użytkownika.']); exit;
        }
        foreach ($ids as $cid) {
            if (!db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$cid])) continue;
            CrmManager::updateContact((int)$cid, ['owner_id' => $owner]);
            $affected++;
        }

    } elseif ($action === 'set_owner_split') {
        // Rozdział zaznaczenia między KILKU opiekunów.
        //
        // Nie round-robin po kolei, tylko wyrównywanie OBCIĄŻENIA: bierzemy pod
        // uwagę, ile kontaktów każda z tych osób prowadzi już teraz, i każdy
        // kolejny kontakt trafia do najmniej obciążonej. Zwykłe dzielenie po
        // równo pogłębiałoby istniejące dysproporcje — kto miał 200 kontaktów,
        // dostawał tyle samo nowych co ktoś, kto nie miał żadnego.
        $owners = array_values(array_unique(array_filter(array_map('intval', (array)($body['value'] ?? [])))));
        if (!$owners) { echo json_encode(['ok'=>false,'error'=>'Wskaż przynajmniej jedną osobę.']); exit; }

        $valid = db_all("SELECT id FROM users WHERE is_active=1 AND id IN (" . implode(',', array_fill(0, count($owners), '?')) . ")", $owners);
        $owners = array_map(fn($u) => (int)$u['id'], $valid);
        if (!$owners) { echo json_encode(['ok'=>false,'error'=>'Żaden ze wskazanych użytkowników nie jest aktywny.']); exit; }

        // Obciążenie startowe — bez kontaktów z bieżącego zaznaczenia, bo te
        // dopiero mają zostać rozdzielone.
        $load = array_fill_keys($owners, 0);
        foreach (db_all(
            "SELECT owner_id, COUNT(*) AS n FROM crm_contacts
              WHERE crm_active=1 AND owner_id IS NOT NULL AND id NOT IN ($ph)
              GROUP BY owner_id", $ids) as $r) {
            $oid = (int)$r['owner_id'];
            if (isset($load[$oid])) $load[$oid] = (int)$r['n'];
        }

        $split = [];
        foreach ($ids as $cid) {
            if (!db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$cid])) continue;
            // Najmniej obciążony; przy remisie pierwszy z listy — stabilnie,
            // żeby wynik nie zależał od kolejności zwróconej przez bazę.
            asort($load);
            $target = (int)array_key_first($load);
            CrmManager::updateContact((int)$cid, ['owner_id' => $target]);
            $load[$target]++;
            $split[$target] = ($split[$target] ?? 0) + 1;
            $affected++;
        }

        echo json_encode(['ok'=>true, 'affected'=>$affected, 'action'=>$action, 'split'=>$split]); exit;

    } elseif ($action === 'add_tag') {
        $tag = trim($body['value'] ?? '');
        if (!$tag) { echo json_encode(['ok'=>false,'error'=>'Podaj tag.']); exit; }
        foreach ($ids as $cid) {
            try {
                CrmManager::addTag($cid, $tag); // odpala zdarzenie tag_added
                $affected++;
            } catch (\Throwable $e) {} // UNIQUE — ignoruj duplikat
        }

    } elseif ($action === 'remove_tag') {
        $tag = trim($body['value'] ?? '');
        if (!$tag) { echo json_encode(['ok'=>false,'error'=>'Podaj tag.']); exit; }
        $st = db()->prepare("DELETE FROM crm_tags WHERE contact_id IN ($ph) AND tag=?");
        $st->execute(array_merge($ids, [$tag]));
        $affected = $st->rowCount();

    } elseif ($action === 'add_to_group') {
        $gid = (int)($body['value'] ?? 0);
        if (!$gid) { echo json_encode(['ok'=>false,'error'=>'Wybierz grupę.']); exit; }
        foreach ($ids as $cid) {
            try {
                db_insert('crm_group_members', ['group_id'=>$gid,'contact_id'=>$cid,'added_by'=>$uid,'added_at'=>date('Y-m-d H:i:s')]);
                $affected++;
            } catch (\Throwable $e) {}
        }

    } elseif ($action === 'convert_type') {
        $target = trim($body['value'] ?? '');
        if (!in_array($target, ['osoba', 'organizacja'], true)) {
            echo json_encode(['ok'=>false,'error'=>'Nieprawidłowy typ docelowy.']); exit;
        }
        // Pola czyszczone — identycznie jak konwersja pojedynczego kontaktu (crm/contact/view.php).
        $clear = $target === 'organizacja'
            ? ['imie', 'nazwisko', 'pesel', 'data_urodzenia']                       // dane osobowe — nie dotyczą organizacji
            : ['nip', 'krs', 'regon', 'osoba_kontaktowa', 'forma_prawna'];          // dane rejestrowe — nie dotyczą osoby

        // Wyłoń rekordy faktycznie zmieniające typ (do audytu + notatek).
        $sel = db()->prepare("SELECT id FROM crm_contacts WHERE id IN ($ph) AND type<>? AND crm_active=1");
        $sel->execute(array_merge($ids, [$target]));
        $convert_ids = array_map('intval', $sel->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        if ($convert_ids) {
            $cph       = implode(',', array_fill(0, count($convert_ids), '?'));
            $set_clear = implode(',', array_map(fn($c) => "`$c`=NULL", $clear));
            $st = db()->prepare("UPDATE crm_contacts SET type=?,{$set_clear},updated_at=datetime('now') WHERE id IN ($cph)");
            $st->execute(array_merge([$target], $convert_ids));
            $affected = $st->rowCount();

            // Notatka audytowa per kontakt — spójnie z konwersją pojedynczą.
            $labels = ['osoba' => 'Osoba fizyczna', 'organizacja' => 'Organizacja / firma'];
            $note   = 'Konwersja typu kontaktu (masowa) → ' . $labels[$target] . '.';
            foreach ($convert_ids as $cid) {
                try {
                    db_insert('crm_notes', [
                        'contact_id' => $cid, 'body' => $note, 'is_pinned' => 0,
                        'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {}
            }
        }

    } elseif ($action === 'delete') {
        if (!can_delete('crm') && !is_admin()) { echo json_encode(['ok'=>false,'error'=>'Brak uprawnień do usuwania.']); exit; }

        // Powód jest OBOWIĄZKOWY także masowo. Wcześniej masowe usuwanie robiło
        // goły crm_active=0: bez powodu, bez notatki, bez wpisu w historii —
        // i bez zablokowania nadawcy, więc auto-kartoteka odtwarzała skasowany
        // spam przy najbliższym skanowaniu poczty.
        $reason = trim((string)($body['value'] ?? ''));
        if (!isset(CRM_DELETE_REASONS[$reason])) {
            echo json_encode(['ok'=>false,'error'=>'Wskaż powód usunięcia.']); exit;
        }
        $scope   = ($body['block_scope'] ?? 'email') === 'domain' ? 'domain' : 'email';
        $note    = trim((string)($body['note'] ?? ''));
        $blocked = [];

        foreach ($ids as $cid) {
            $r = crm_delete_contact_reason((int)$cid, $reason, $note, $scope);
            if (!empty($r['ok'])) {
                $affected++;
                if (!empty($r['blocked'])) $blocked[] = $r['blocked'];
            }
        }

        echo json_encode([
            'ok'       => true,
            'affected' => $affected,
            'action'   => $action,
            'blocked'  => count(array_unique($blocked)),
        ]); exit;

    } else {
        echo json_encode(['ok'=>false,'error'=>"Nieznana akcja: {$action}"]); exit;
    }

    echo json_encode(['ok'=>true,'affected'=>$affected,'action'=>$action]);

} catch (\Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
