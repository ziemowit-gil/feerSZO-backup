<?php
/**
 * includes/crm_workflows.php — przepływy, czyli szablony akcji.
 *
 * Przepływ to nazwana lista kroków, które w jednym kliknięciu tworzą komplet
 * wpisów: np. „Nowy lead" = kontakt + sprawa + zadanie „zadzwonić w ciągu 2 dni".
 * Kroki wykonują się PO KOLEI, a kontakt utworzony w pierwszym kroku staje się
 * kontaktem dla następnych — dzięki temu przepływ startuje z jednego pola.
 *
 * To NIE jest silnik procesów: nie ma tu ról, akceptacji ani stanów — od tego są
 * Obiegi (includes/obiegi.php) i automatyzacje CRM (includes/crm_automation.php).
 * Przepływ jest skrótem klawiszowym dla powtarzalnej roboty jednej osoby.
 *
 * Szablon kroku:
 *   ['type' => contact|note|case|task, 'title' => 'Zadzwonić do {tytul}', 'owner_id' => 0]
 *
 * Krok „task" tworzy ZADANIE CRM (includes/crm_tasks.php), nie wiersz w module
 * Zadań — stąd osoba zamiast listy i obszaru.
 * W tytule działają znaczniki: {tytul} (to, co wpisano w oknie), {kontakt}
 * (nazwa wybranego/utworzonego kontaktu), {data} (dzisiejsza data).
 *
 * Widoczność: owner_id = użytkownik (prywatny) albo NULL (wspólny dla zespołu —
 * takie zakłada i edytuje administrator).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm_quick.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_workflows (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            icon        TEXT    NOT NULL DEFAULT 'bi-diagram-3',
            steps_json  TEXT    NOT NULL DEFAULT '[]',
            owner_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,   -- NULL = wspólny
            is_active   INTEGER NOT NULL DEFAULT 1,
            created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_wf_owner ON crm_workflows(owner_id, is_active)");
    } catch (\Throwable $e) {}
})();

/** Przepływy widoczne dla użytkownika: jego własne + wspólne. */
function crm_workflows_for(?int $uid = null, bool $only_active = true): array {
    $uid = $uid ?? (int)(current_user()['id'] ?? 0);
    try {
        return db_all(
            "SELECT * FROM crm_workflows
             WHERE (owner_id IS NULL OR owner_id = ?)" . ($only_active ? " AND is_active=1" : '') . "
             ORDER BY (owner_id IS NULL) DESC, name", [$uid]
        );
    } catch (\Throwable $e) { return []; }
}

function crm_workflow(int $id): ?array {
    try { return db_one("SELECT * FROM crm_workflows WHERE id=?", [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

/** Czy użytkownik może edytować dany przepływ (wspólne — tylko administrator). */
function crm_workflow_can_edit(?array $wf): bool {
    if (!$wf) return false;
    $uid = (int)(current_user()['id'] ?? 0);
    if (empty($wf['owner_id'])) return is_admin();
    return (int)$wf['owner_id'] === $uid || is_admin();
}

/** Kroki przepływu po walidacji (odsiewa nieznane typy i puste tytuły). */
function crm_workflow_steps(array $wf): array {
    $steps = json_decode((string)($wf['steps_json'] ?? '[]'), true);
    if (!is_array($steps)) return [];
    $types = crm_quick_types();
    $out   = [];
    foreach ($steps as $s) {
        if (!is_array($s)) continue;
        $t = (string)($s['type'] ?? '');
        if (!isset($types[$t])) continue;
        $title = trim((string)($s['title'] ?? ''));
        if ($title === '') continue;
        $out[] = ['type' => $t, 'title' => $title, 'owner_id' => (int)($s['owner_id'] ?? 0)];
    }
    return $out;
}

/** Zapisuje przepływ (nowy albo istniejący). Zwraca id albo 0. */
function crm_workflow_save(array $data, ?int $id = null): int {
    $uid   = (int)(current_user()['id'] ?? 0);
    $types = crm_quick_types();

    $steps = [];
    foreach ((array)($data['steps'] ?? []) as $s) {
        $t     = (string)($s['type'] ?? '');
        $title = trim((string)($s['title'] ?? ''));
        if (!isset($types[$t]) || $title === '') continue;
        $steps[] = ['type' => $t, 'title' => mb_substr($title, 0, 300), 'owner_id' => (int)($s['owner_id'] ?? 0)];
    }

    $row = [
        'name'        => mb_substr(trim((string)($data['name'] ?? '')), 0, 120),
        'description' => mb_substr(trim((string)($data['description'] ?? '')), 0, 500),
        'icon'        => trim((string)($data['icon'] ?? 'bi-diagram-3')) ?: 'bi-diagram-3',
        'steps_json'  => json_encode(array_slice($steps, 0, 10), JSON_UNESCAPED_UNICODE),
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
    if ($row['name'] === '' || !$steps) return 0;

    // Wspólny przepływ (owner_id NULL) może założyć tylko administrator
    $shared = !empty($data['shared']) && is_admin();

    try {
        if ($id) {
            $wf = crm_workflow($id);
            if (!crm_workflow_can_edit($wf)) return 0;
            $row['owner_id'] = $shared ? null : ((int)($wf['owner_id'] ?? $uid) ?: $uid);
            $sets = implode(',', array_map(static fn($k) => "$k=?", array_keys($row)));
            db()->prepare("UPDATE crm_workflows SET {$sets} WHERE id=?")
                ->execute(array_merge(array_values($row), [$id]));
            return $id;
        }
        $row['owner_id']   = $shared ? null : $uid;
        $row['created_by'] = $uid ?: null;
        $row['created_at'] = date('Y-m-d H:i:s');
        return (int)db_insert('crm_workflows', $row);
    } catch (\Throwable $e) {
        error_log('[crm_workflow_save] ' . $e->getMessage());
        return 0;
    }
}

function crm_workflow_delete(int $id): bool {
    $wf = crm_workflow($id);
    if (!crm_workflow_can_edit($wf)) return false;
    try { db()->prepare("DELETE FROM crm_workflows WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/** Podstawia znaczniki w tytule kroku. */
function _crm_wf_fill(string $tpl, string $subject, string $contact_name): string {
    return trim(str_replace(
        ['{tytul}', '{tytuł}', '{kontakt}', '{data}'],
        [$subject, $subject, $contact_name, date('d.m.Y')],
        $tpl
    ));
}

/**
 * Uruchamia przepływ.
 *
 * @param int   $id    Przepływ
 * @param array $input ['subject' => tekst z okna, 'contact_id' => int, 'owner_id' => int]
 * @return array ['ok','error','results'=>[['label','title','url','ok','error']…],'url'=>pierwszy utworzony]
 */
function crm_workflow_run(int $id, array $input): array {
    $uid = (int)(current_user()['id'] ?? 0);
    $wf  = crm_workflow($id);
    if (!$wf || (int)$wf['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'Przepływ nie istnieje albo jest wyłączony.', 'results' => [], 'url' => ''];
    }
    if (!empty($wf['owner_id']) && (int)$wf['owner_id'] !== $uid && !is_admin()) {
        return ['ok' => false, 'error' => 'To nie jest Twój przepływ.', 'results' => [], 'url' => ''];
    }

    $steps = crm_workflow_steps($wf);
    if (!$steps) return ['ok' => false, 'error' => 'Przepływ nie ma kroków.', 'results' => [], 'url' => ''];

    $subject    = trim((string)($input['subject'] ?? ''));
    if ($subject === '') return ['ok' => false, 'error' => 'Wpisz, czego dotyczy przepływ.', 'results' => [], 'url' => ''];

    $contact_id = (int)($input['contact_id'] ?? 0);
    $owner_id   = (int)($input['owner_id'] ?? 0);

    $contact_name = '';
    if ($contact_id > 0) {
        $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [$contact_id]);
        $contact_name = (string)($c['imie_nazwisko'] ?? '');
    }

    $results = [];
    $first_url = '';
    foreach ($steps as $i => $st) {
        $title = _crm_wf_fill($st['title'], $subject, $contact_name ?: $subject);

        $r = crm_quick_create([
            'type'        => $st['type'],
            'title'       => $title,
            'contact_id'  => $contact_id,
            'owner_id'    => $st['owner_id'] ?: $owner_id,
            'description' => 'Utworzone przepływem „' . $wf['name'] . '".',
        ], $uid);

        // Kontakt z pierwszego kroku prowadzi dalej — po to jest kolejność kroków
        if ($r['ok'] && $st['type'] === 'contact' && $contact_id === 0) {
            $contact_id   = (int)$r['id'];
            $contact_name = $title;
        }

        $results[] = [
            'label' => $r['label'] ?: (crm_quick_types()[$st['type']]['label'] ?? $st['type']),
            'title' => $title,
            'ok'    => (bool)$r['ok'],
            'error' => (string)$r['error'],
            'url'   => (string)$r['url'],
        ];
        if ($r['ok'] && $first_url === '') $first_url = (string)$r['url'];

        // Kroki wymagające kontaktu bez kontaktu → przerywamy, bo dalsze i tak polegną
        if (!$r['ok'] && in_array($st['type'], ['note', 'case'], true) && $contact_id === 0) break;
    }

    $done = count(array_filter($results, static fn($r) => $r['ok']));
    return [
        'ok'      => $done > 0,
        'error'   => $done > 0 ? '' : ($results[0]['error'] ?? 'Nie udało się wykonać przepływu.'),
        'results' => $results,
        'url'     => $first_url,
    ];
}
