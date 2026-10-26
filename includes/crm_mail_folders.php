<?php
/**
 * includes/crm_mail_folders.php — własne foldery i tagi wiadomości w Skrzynce CRM (wzór: moduł Custom Folders z FreeScout).
 *
 * FOLDER to ZAPISANY WIDOK, nie przeniesienie: wiadomość jest w folderze i jednocześnie dalej w widokach domyślnych (Nowe, Moje, Wszystkie…).
 * Folder wybiera wiadomości regułami (JSON w crm_mail_folders.rules):
 *   tags[] + tag_mode (any|all) — tagi wiadomości; dopasowanie na poziomie WĄTKU (tag na którejkolwiek wiadomości wątku),
 *   status  (any = aktywne+załatwione | active | archived | spam),
 *   assignee (any | me | none | id użytkownika),
 *   queue_id (kolejka wiadomości — crm_mail_queues), mailbox_id (skrzynka Poczty).
 * Folder osobisty widzi tylko właściciel; wspólny (is_shared=1, zakłada administrator) widzą wszyscy z dostępem do Skrzynki — i tak w zakresie
 * skrzynek z ACL, bo listę buduje crm_mailbox_inbox (poczta_scope_sql). Licznik (counter_mode): all = rozmowy spełniające reguły, active = tylko aktywne.
 * Rozmowa = wątek (thread_key) albo pojedyncza wiadomość bez wątku.
 *
 * TAGI: crm_comm_tags (comm_id, tag, tag_key = mb_strtolower). Dodanie dotyczy wiadomości, usunięcie — całego wątku (żeby tag faktycznie zniknął).
 */

const CRM_MF_MAX_TAGS_PER_MSG = 12;
const CRM_MF_MAX_FOLDERS_PER_USER = 30;

function crm_mf_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_comm_tags (
            id        {$pk},
            comm_id   INTEGER NOT NULL,
            tag       VARCHAR(40) NOT NULL,
            tag_key   VARCHAR(40) NOT NULL,
            created_by INTEGER,
            created_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_comm_tags ON crm_comm_tags(comm_id, tag_key)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_tags_key ON crm_comm_tags(tag_key)");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_folders (
            id            {$pk},
            name          VARCHAR(60) NOT NULL,
            color         VARCHAR(9) NOT NULL DEFAULT '#0176D3',
            owner_user_id INTEGER,
            is_shared     INTEGER NOT NULL DEFAULT 0,
            counter_mode  VARCHAR(8) NOT NULL DEFAULT 'active',
            rules         TEXT NOT NULL DEFAULT '{}',
            sort_order    INTEGER NOT NULL DEFAULT 50,
            created_at    VARCHAR(19)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_mail_folders_owner ON crm_mail_folders(owner_user_id)");
    } catch (\Throwable $e) {
        error_log('[crm_mf_migrate] ' . $e->getMessage());
    }
}

// ── Tagi ────────────────────────────────────────────────────────────────────

/** Normalizuje tag: bez znaków sterujących i przecinków, ≤ 30 znaków. Zwraca [wyświetlany, klucz] albo null. */
function crm_mt_normalize(string $tag): ?array
{
    $t = trim((string)preg_replace('/[\x00-\x1F,;]+/u', ' ', $tag));
    $t = trim((string)preg_replace('/\s+/u', ' ', $t));
    if ($t === '') return null;
    $t = mb_substr($t, 0, 30);
    return [$t, mb_strtolower($t)];
}

/** Id wiadomości wątku (łącznie z podaną). */
function _crm_mt_thread_ids(int $comm_id): array
{
    $row = db_one("SELECT thread_key FROM crm_communications WHERE id = ?", [$comm_id]);
    if (!$row) return [];
    $tk = (string)($row['thread_key'] ?? '');
    if ($tk === '') return [$comm_id];
    return array_map('intval', array_column(db_all("SELECT id FROM crm_communications WHERE thread_key = ?", [$tk]), 'id')) ?: [$comm_id];
}

/** Dodaje tag do wiadomości. @return bool true = dodano nowy; false = już był / niepoprawny / limit */
function crm_mt_add(int $comm_id, string $tag): bool
{
    crm_mf_migrate();
    $n = crm_mt_normalize($tag);
    if (!$n || !db_one("SELECT 1 AS x FROM crm_communications WHERE id = ?", [$comm_id])) return false;
    if ((int)db_one("SELECT COUNT(*) AS n FROM crm_comm_tags WHERE comm_id = ?", [$comm_id])['n'] >= CRM_MF_MAX_TAGS_PER_MSG) return false;
    if (db_one("SELECT 1 AS x FROM crm_comm_tags WHERE comm_id = ? AND tag_key = ?", [$comm_id, $n[1]])) return false;
    db_insert('crm_comm_tags', ['comm_id' => $comm_id, 'tag' => $n[0], 'tag_key' => $n[1], 'created_by' => (int)(current_user()['id'] ?? 0) ?: null, 'created_at' => date('Y-m-d H:i:s')]);
    return true;
}

/** Usuwa tag z całego wątku wiadomości. @return int liczba usuniętych wpisów */
function crm_mt_remove(int $comm_id, string $tag): int
{
    crm_mf_migrate();
    $n = crm_mt_normalize($tag);
    $ids = $n ? _crm_mt_thread_ids($comm_id) : [];
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("DELETE FROM crm_comm_tags WHERE tag_key = ? AND comm_id IN ($in)");
    $st->execute(array_merge([$n[1]], $ids));
    return (int)$st->rowCount();
}

/**
 * Tagi wierszy listy (suma tagów całego wątku): [id wiadomości => [tag, …]] — jedno–dwa zapytania zamiast N.
 * @param array $rows wiersze z kluczami id i (opcjonalnie) thread_key
 */
function crm_mt_for_rows(array $rows): array
{
    crm_mf_migrate();
    $ids = []; $tks = [];
    foreach ($rows as $r) { $ids[] = (int)$r['id']; if (!empty($r['thread_key'])) $tks[(string)$r['thread_key']] = true; }
    if (!$ids) return [];
    $out = array_fill_keys($ids, []);
    try {
        $inI = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT t.comm_id, t.tag, c.thread_key FROM crm_comm_tags t JOIN crm_communications c ON c.id = t.comm_id WHERE t.comm_id IN ($inI)";
        $p = $ids;
        if ($tks) { $inK = implode(',', array_fill(0, count($tks), '?')); $sql .= " OR c.thread_key IN ($inK)"; $p = array_merge($p, array_keys($tks)); }
        $byThread = []; $byMsg = [];
        foreach (db_all($sql, $p) as $t) {
            $byMsg[(int)$t['comm_id']][] = $t['tag'];
            if ($t['thread_key'] !== null && $t['thread_key'] !== '') $byThread[(string)$t['thread_key']][] = $t['tag'];
        }
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $set = $byMsg[$id] ?? [];
            if (!empty($r['thread_key'])) $set = array_merge($set, $byThread[(string)$r['thread_key']] ?? []);
            $seen = []; foreach ($set as $tg) $seen[mb_strtolower($tg)] = $tg;
            $out[$id] = array_values($seen);
        }
    } catch (\Throwable $e) { error_log('[crm_mt_for_rows] ' . $e->getMessage()); }
    return $out;
}

/** Podpowiedzi tagów (najczęściej używane) do pola dodawania i reguł folderu. */
function crm_mt_suggest(int $limit = 40): array
{
    crm_mf_migrate();
    try { return array_column(db_all("SELECT MIN(tag) AS tag, COUNT(*) AS n FROM crm_comm_tags GROUP BY tag_key ORDER BY n DESC, tag_key LIMIT " . (int)$limit), 'tag'); }
    catch (\Throwable $e) { return []; }
}

// ── Foldery: reguły i SQL ───────────────────────────────────────────────────

/** Normalizuje reguły z formularza/JSON do znanych kluczy i bezpiecznych wartości. */
function crm_mf_rules(array $in): array
{
    $tags = [];
    $raw = $in['tags'] ?? [];
    if (is_string($raw)) $raw = preg_split('/[,;\n]+/', $raw) ?: [];
    foreach ((array)$raw as $t) { if ($n = crm_mt_normalize((string)$t)) $tags[$n[1]] = $n[0]; }
    $assignee = (string)($in['assignee'] ?? 'any');
    if (!in_array($assignee, ['any', 'me', 'none'], true)) $assignee = ctype_digit($assignee) && (int)$assignee > 0 ? $assignee : 'any';
    return [
        'tags'       => array_slice(array_values($tags), 0, 10),
        'tag_mode'   => ($in['tag_mode'] ?? 'any') === 'all' ? 'all' : 'any',
        'status'     => in_array((string)($in['status'] ?? 'any'), ['any', 'active', 'archived', 'spam'], true) ? (string)($in['status'] ?? 'any') : 'any',
        'assignee'   => $assignee,
        'queue_id'   => max(0, (int)($in['queue_id'] ?? 0)),
        'mailbox_id' => max(0, (int)($in['mailbox_id'] ?? 0)),
    ];
}

/** Warunek SQL (alias c = crm_communications) dla reguł folderu — zastępuje warunek widoku. Parametry dopisuje do $params. */
function crm_mf_sql(array $rules, int $uid, array &$params): string
{
    $r = crm_mf_rules($rules);
    $w = ["c.direction='in'"];
    $w[] = match ($r['status']) {
        'active' => "c.inbox_status='active'", 'archived' => "c.inbox_status='archived'", 'spam' => "c.inbox_status='spam'",
        default  => "c.inbox_status IN ('active','archived')",
    };
    if ($r['assignee'] === 'me') { $w[] = 'c.assigned_to = CAST(? AS INTEGER)'; $params[] = $uid; }
    elseif ($r['assignee'] === 'none') $w[] = '(c.assigned_to IS NULL OR c.assigned_to = 0)';
    elseif ($r['assignee'] !== 'any') { $w[] = 'c.assigned_to = CAST(? AS INTEGER)'; $params[] = (int)$r['assignee']; }
    if ($r['mailbox_id']) { $w[] = 'c.mailbox_id = CAST(? AS INTEGER)'; $params[] = $r['mailbox_id']; }
    if ($r['queue_id'] && function_exists('crm_mq_expr')) { $w[] = crm_mq_expr('c') . ' = CAST(? AS INTEGER)'; $params[] = $r['queue_id']; }
    $keys = array_map(fn($t) => mb_strtolower($t), $r['tags']);
    if ($keys) {
        // tag na którejkolwiek wiadomości wątku (rozmowy) — wątek bez thread_key = sama wiadomość
        $thr = "EXISTS (SELECT 1 FROM crm_comm_tags t JOIN crm_communications c2 ON c2.id = t.comm_id"
             . " WHERE t.tag_key = ? AND (c2.id = c.id OR (c.thread_key IS NOT NULL AND c.thread_key <> '' AND c2.thread_key = c.thread_key)))";
        if ($r['tag_mode'] === 'all') { foreach ($keys as $k) { $w[] = $thr; $params[] = $k; } }
        else { $w[] = '(' . implode(' OR ', array_fill(0, count($keys), $thr)) . ')'; foreach ($keys as $k) $params[] = $k; }
    }
    return implode(' AND ', $w);
}

/** Warunek „wiadomość (rozmowa) ma tag” — dla szybkiego filtra ?tag=… (alias c). */
function crm_mt_filter_sql(string $tag, array &$params): ?string
{
    $n = crm_mt_normalize($tag);
    if (!$n) return null;
    $params[] = $n[1];
    return "EXISTS (SELECT 1 FROM crm_comm_tags t JOIN crm_communications c2 ON c2.id = t.comm_id WHERE t.tag_key = ?"
         . " AND (c2.id = c.id OR (c.thread_key IS NOT NULL AND c.thread_key <> '' AND c2.thread_key = c.thread_key)))";
}

// ── Foldery: odczyt i zapis ─────────────────────────────────────────────────

/** Foldery widoczne dla użytkownika: własne + wspólne; z rozpakowanymi regułami. */
function crm_mf_list(?int $uid = null): array
{
    crm_mf_migrate();
    $uid ??= (int)(current_user()['id'] ?? 0);
    try {
        $rows = db_all("SELECT f.*, u.name AS owner_name FROM crm_mail_folders f LEFT JOIN users u ON u.id = f.owner_user_id
                        WHERE f.is_shared = 1 OR f.owner_user_id = ? ORDER BY f.is_shared DESC, f.sort_order, f.id", [$uid]);
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) $r['rules_arr'] = crm_mf_rules((array)(json_decode((string)$r['rules'], true) ?: []));
    unset($r);   // bez tego zmienna $r zostaje referencją do ostatniego folderu i późniejsze przypisania w wywołującym go zmieniają
    return $rows;
}

function crm_mf_get(int $id, ?int $uid = null): ?array
{
    foreach (crm_mf_list($uid) as $f) if ((int)$f['id'] === $id) return $f;
    return null;
}

/** Czy użytkownik może zmieniać folder: administrator — każdy; reszta — tylko własny osobisty. */
function crm_mf_can_edit(array $f, ?int $uid = null): bool
{
    $uid ??= (int)(current_user()['id'] ?? 0);
    if (function_exists('is_admin') && is_admin()) return true;
    return !(int)$f['is_shared'] && (int)$f['owner_user_id'] === $uid && $uid > 0;
}

/** Zapis folderu. $d: name, color, rules (tablica), counter_mode, is_shared (tylko admin), sort_order. Zwraca id. */
function crm_mf_save(array $d, ?int $id = null, ?int $uid = null): int
{
    crm_mf_migrate();
    $uid ??= (int)(current_user()['id'] ?? 0);
    $isAdmin = function_exists('is_admin') && is_admin();
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('Podaj nazwę folderu.');
    $row = [
        'name' => mb_substr($name, 0, 40),
        'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($d['color'] ?? '')) ? (string)$d['color'] : '#0176D3',
        'counter_mode' => ($d['counter_mode'] ?? 'active') === 'all' ? 'all' : 'active',
        'rules' => json_encode(crm_mf_rules((array)($d['rules'] ?? [])), JSON_UNESCAPED_UNICODE),
        'sort_order' => (int)($d['sort_order'] ?? 50),
    ];
    if ($id === null) {
        if ((int)db_one("SELECT COUNT(*) AS n FROM crm_mail_folders WHERE owner_user_id = ?", [$uid])['n'] >= CRM_MF_MAX_FOLDERS_PER_USER) throw new \RuntimeException('Limit folderów osobistych: ' . CRM_MF_MAX_FOLDERS_PER_USER . '.');
        $shared = $isAdmin && !empty($d['is_shared']) ? 1 : 0;
        return db_insert('crm_mail_folders', $row + ['owner_user_id' => $uid ?: null, 'is_shared' => $shared, 'created_at' => date('Y-m-d H:i:s')]);
    }
    $cur = db_one("SELECT * FROM crm_mail_folders WHERE id = ?", [$id]);
    if (!$cur || !crm_mf_can_edit($cur, $uid)) throw new \RuntimeException('Brak uprawnień do tego folderu.');
    if ($isAdmin) $row['is_shared'] = !empty($d['is_shared']) ? 1 : 0;
    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
    $st = db()->prepare("UPDATE crm_mail_folders SET $set WHERE id = :id");
    $st->execute($row + ['id' => $id]);
    return $id;
}

function crm_mf_delete(int $id, ?int $uid = null): void
{
    crm_mf_migrate();
    $cur = db_one("SELECT * FROM crm_mail_folders WHERE id = ?", [$id]);
    if (!$cur || !crm_mf_can_edit($cur, $uid)) throw new \RuntimeException('Brak uprawnień do tego folderu.');
    db()->prepare("DELETE FROM crm_mail_folders WHERE id = ?")->execute([$id]);
}

/**
 * Liczniki folderów (rozmowy, nie wiadomości) w zakresie dostępnych skrzynek: [id folderu => ['n' => wszystkie wg trybu, 'unread' => nieprzeczytane]].
 * Wymaga crm_mailbox.php (poczta_scope_sql, ukrycie w CRM).
 */
function crm_mf_counts(array $folders): array
{
    $uid = (int)(current_user()['id'] ?? 0);
    $conv = "COUNT(DISTINCT CASE WHEN c.thread_key IS NULL OR c.thread_key = '' THEN 'm' || c.id ELSE c.thread_key END)";
    $out = [];
    foreach ($folders as $f) {
        $p = [];
        $sql = crm_mf_sql($f['rules_arr'], $uid, $p) . " AND COALESCE(c.crm_hidden,0) = 0 AND " . poczta_scope_sql('c.mailbox_id');
        // „Tylko aktywne” zawęża licznik tylko dla folderu bez własnego statusu — folder „załatwione” liczy załatwione
        if ($f['counter_mode'] === 'active' && ($f['rules_arr']['status'] ?? 'any') === 'any') $sql .= " AND c.inbox_status = 'active'";
        try {
            $r = db_one("SELECT $conv AS n, $conv FILTER (WHERE c.is_read = 0) AS u FROM crm_communications c WHERE $sql", $p);
        } catch (\Throwable $e) {
            try { $r = db_one("SELECT $conv AS n FROM crm_communications c WHERE $sql", $p); } catch (\Throwable $e2) { $r = []; }
        }
        $out[(int)$f['id']] = ['n' => (int)($r['n'] ?? 0), 'unread' => (int)($r['u'] ?? 0)];
    }
    return $out;
}
