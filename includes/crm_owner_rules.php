<?php
/**
 * includes/crm_owner_rules.php — automatyczne przypisywanie opiekunów kartotek.
 *
 * Kartoteka bez opiekuna to kartoteka, której nikt nie prowadzi — a przy imporcie
 * kilkuset podmiotów naraz nikt nie będzie klikał opiekuna po jednym. Stąd dwa
 * mechanizmy, świadomie w tej kolejności:
 *
 *   1. REGUŁY — „kontakty z tego źródła / tej grupy / tego województwa prowadzi X".
 *      To jest właściwa odpowiedź tam, gdzie podział pracy wynika z czegoś
 *      sensownego: regionu, typu podmiotu, kanału pozyskania.
 *   2. ROZDZIAŁ PO RÓWNO (round-robin) — dopiero dla tego, czego żadna reguła nie
 *      złapała. Rozdaje po kolei między wskazane osoby, żeby nie zostawić reszty
 *      bez właściciela. To fallback, nie strategia.
 *
 * Zasady bezpieczeństwa:
 *   • domyślnie ruszamy TYLKO kartoteki bez opiekuna — nie odbieramy ludziom
 *     ich kontaktów, chyba że ktoś świadomie wybierze nadpisywanie,
 *   • wszystko ma tryb podglądu: widać, kto co dostanie, zanim to zapiszemy,
 *   • każde przypisanie zostawia notatkę w kartotece z podaniem reguły.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_owner_rules (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            match_type  TEXT    NOT NULL DEFAULT 'source',  -- source|type|status|group|tag|wojewodztwo|critical|any
            match_value TEXT    NOT NULL DEFAULT '',
            owner_id    INTEGER NOT NULL,
            priority    INTEGER NOT NULL DEFAULT 100,
            is_active   INTEGER NOT NULL DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_owner_rules ON crm_owner_rules(is_active, priority)");
    } catch (\Throwable $e) {}
})();

/** Rodzaje warunków, po których reguła dopasowuje kartotekę. */
function crm_owner_match_types(): array {
    return [
        'source'      => ['label' => 'Źródło kartoteki',  'hint' => 'np. import_rejestr, formularz, k30_auto'],
        'type'        => ['label' => 'Typ kontaktu',      'hint' => 'osoba, organizacja, kontrahent, partner'],
        'status'      => ['label' => 'Status',            'hint' => 'wartość z katalogu statusów'],
        'group'       => ['label' => 'Grupa',             'hint' => 'ID grupy CRM'],
        'tag'         => ['label' => 'Tag',               'hint' => 'dokładna nazwa tagu'],
        'wojewodztwo' => ['label' => 'Województwo',       'hint' => 'np. małopolskie'],
        'critical'    => ['label' => 'Krytyczny operacyjnie', 'hint' => 'bez wartości — łapie oznaczone kontakty'],
        'any'         => ['label' => 'Każda kartoteka',   'hint' => 'reguła zbiorcza — ustaw jej najniższy priorytet'],
    ];
}

function crm_owner_rules(bool $only_active = true): array {
    try {
        return db_all(
            "SELECT r.*, u.name AS owner_name FROM crm_owner_rules r
             LEFT JOIN users u ON u.id = r.owner_id"
            . ($only_active ? " WHERE r.is_active=1" : '')
            . " ORDER BY r.priority, r.id"
        );
    } catch (\Throwable $e) { return []; }
}

function crm_owner_rule_save(array $d, ?int $id = null): int {
    $types = crm_owner_match_types();
    $row = [
        'match_type'  => isset($types[$d['match_type'] ?? '']) ? (string)$d['match_type'] : 'source',
        'match_value' => mb_substr(trim((string)($d['match_value'] ?? '')), 0, 120),
        'owner_id'    => (int)($d['owner_id'] ?? 0),
        'priority'    => max(1, min(999, (int)($d['priority'] ?? 100))),
        'is_active'   => !empty($d['is_active']) ? 1 : 0,
    ];
    // Opiekunem może być tylko ktoś z dostępem do CRM — inaczej reguła tworzy
    // przypisania, których adresat nawet nie zobaczy.
    if (!crm_owner_can_be($row['owner_id'])) return 0;
    // Reguła bez wartości ma sens tylko dla warunków, które wartości nie potrzebują
    if ($row['match_value'] === '' && !in_array($row['match_type'], ['any', 'critical'], true)) return 0;

    try {
        if ($id) {
            $sets = implode(',', array_map(static fn($k) => "$k=?", array_keys($row)));
            db()->prepare("UPDATE crm_owner_rules SET {$sets} WHERE id=?")
                ->execute(array_merge(array_values($row), [$id]));
            return $id;
        }
        return (int)db_insert('crm_owner_rules', $row + ['created_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        error_log('[crm_owner_rule_save] ' . $e->getMessage());
        return 0;
    }
}

function crm_owner_rule_delete(int $id): bool {
    try { db()->prepare("DELETE FROM crm_owner_rules WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/**
 * Pierwsza reguła pasująca do kartoteki (wg priorytetu).
 *
 * @return array|null ['owner_id','rule_id','label'] albo null
 */
function crm_owner_match(array $contact, ?array $rules = null): ?array {
    $rules ??= crm_owner_rules();
    $types = crm_owner_match_types();

    foreach ($rules as $r) {
        $val  = trim((string)$r['match_value']);
        $hit  = false;
        $cid  = (int)$contact['id'];

        switch ((string)$r['match_type']) {
            case 'any':
                $hit = true; break;
            case 'critical':
                $hit = !empty($contact['is_critical']); break;
            case 'source':
                $hit = strcasecmp(trim((string)($contact['source'] ?? '')), $val) === 0; break;
            case 'type':
                $hit = strcasecmp(trim((string)($contact['type'] ?? '')), $val) === 0; break;
            case 'status':
                $hit = strcasecmp(trim((string)($contact['status'] ?? '')), $val) === 0; break;
            case 'wojewodztwo':
                $hit = strcasecmp(trim((string)($contact['wojewodztwo'] ?? '')), $val) === 0; break;
            case 'group':
                try {
                    $hit = (bool)db_one("SELECT 1 FROM crm_group_members WHERE contact_id=? AND group_id=?",
                        [$cid, (int)$val]);
                } catch (\Throwable $e) { $hit = false; }
                break;
            case 'tag':
                try {
                    $hit = (bool)db_one("SELECT 1 FROM crm_tags WHERE contact_id=? AND LOWER(tag)=LOWER(?)",
                        [$cid, $val]);
                } catch (\Throwable $e) { $hit = false; }
                break;
        }

        if ($hit) {
            return [
                'owner_id' => (int)$r['owner_id'],
                'rule_id'  => (int)$r['id'],
                'label'    => ($types[$r['match_type']]['label'] ?? $r['match_type'])
                              . ($val !== '' ? ': ' . $val : ''),
            ];
        }
    }
    return null;
}

/**
 * Kto w ogóle może być opiekunem kartoteki.
 *
 * Tylko aktywni użytkownicy, których ROLA ma dostęp do CRM (role_permissions,
 * moduł „crm", zapis lub przynajmniej odczyt) albo którzy dostali CRM
 * indywidualnie (user_permissions). Przypisanie opiekuna komuś, kto nie wejdzie
 * do modułu, to tylko ładny wpis w bazie — nikt się tym nie zajmie.
 */
function crm_owner_candidates(): array {
    try {
        return db_all(
            "SELECT DISTINCT u.id, u.name, u.email
               FROM users u
               JOIN roles r ON r.name = u.role
          LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.module = 'crm'
          LEFT JOIN user_permissions up ON up.user_id = u.id AND up.module = 'crm'
              WHERE u.is_active = 1
                AND (u.role = 'admin'
                     OR COALESCE(rp.can_read, 0) = 1 OR COALESCE(rp.can_write, 0) = 1
                     OR COALESCE(up.can_read, 0) = 1 OR COALESCE(up.can_write, 0) = 1
                     OR COALESCE(r.crm_only, 0) = 1)
           ORDER BY u.name"
        );
    } catch (\Throwable $e) {
        // Gdyby schemat uprawnień wyglądał inaczej, lepiej pokazać wszystkich
        // aktywnych niż nie pozwolić przypisać nikogo.
        try { return db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name"); }
        catch (\Throwable $e2) { return []; }
    }
}

/** Czy dany użytkownik może być opiekunem (walidacja przy zapisie reguły). */
function crm_owner_can_be(int $user_id): bool {
    if ($user_id <= 0) return false;
    foreach (crm_owner_candidates() as $u) if ((int)$u['id'] === $user_id) return true;
    return false;
}

/** Użytkownicy do rozdziału po równo (ustawienie `crm_owner_roundrobin`). */
function crm_owner_roundrobin_users(): array {
    try {
        $raw = (string)(db_one("SELECT value FROM settings WHERE key_='crm_owner_roundrobin'")['value'] ?? '');
    } catch (\Throwable $e) { return []; }
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
    if (!$ids) return [];
    // Przez sito uprawnień: ktoś mógł stracić dostęp do CRM po zapisaniu listy
    return array_values(array_filter(crm_owner_candidates(),
        static fn($u) => in_array((int)$u['id'], $ids, true)));
}

function crm_owner_roundrobin_save(array $user_ids): void {
    $allowed = array_map(static fn($u) => (int)$u['id'], crm_owner_candidates());
    $ids = implode(',', array_values(array_unique(array_filter(
        array_map('intval', $user_ids), static fn($i) => in_array($i, $allowed, true)
    ))));
    try {
        db()->prepare("INSERT INTO settings (key_, value) VALUES ('crm_owner_roundrobin', ?)
                       ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$ids]);
    } catch (\Throwable $e) {
        error_log('[crm_owner_roundrobin_save] ' . $e->getMessage());
    }
}

/**
 * Masowe przypisanie opiekunów.
 *
 * @param array $opt ['apply'=>bool, 'overwrite'=>bool, 'roundrobin'=>bool, 'limit'=>int]
 * @return array ['total','matched','roundrobin','skipped','rows'=>[…]]
 */
function crm_owner_assign_bulk(array $opt = []): array {
    $apply     = !empty($opt['apply']);
    $overwrite = !empty($opt['overwrite']);
    $use_rr    = !empty($opt['roundrobin']);
    $limit     = max(1, min(5000, (int)($opt['limit'] ?? 1000)));

    $rules = crm_owner_rules();
    $rr    = $use_rr ? crm_owner_roundrobin_users() : [];

    $where = $overwrite ? 'crm_active=1' : 'crm_active=1 AND (owner_id IS NULL OR owner_id=0)';
    try {
        $rows = db_all("SELECT id, imie_nazwisko, type, status, source, wojewodztwo, owner_id, is_critical
                          FROM crm_contacts WHERE {$where} ORDER BY id LIMIT {$limit}");
    } catch (\Throwable $e) { $rows = []; }

    $out = ['total' => count($rows), 'matched' => 0, 'roundrobin' => 0, 'skipped' => 0, 'rows' => []];
    $rr_i = 0;
    $now  = date('Y-m-d H:i:s');
    $uid  = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;

    $allowed = array_map(static fn($u) => (int)$u['id'], crm_owner_candidates());

    foreach ($rows as $c) {
        $m = crm_owner_match($c, $rules);
        // Reguła mogła wskazywać kogoś, kto stracił dostęp do CRM — wtedy jej nie ufamy
        if ($m && !in_array((int)$m['owner_id'], $allowed, true)) $m = null;

        if ($m) {
            $owner = (int)$m['owner_id'];
            $why   = 'reguła — ' . $m['label'];
            $out['matched']++;
        } elseif ($rr) {
            $owner = (int)$rr[$rr_i % count($rr)]['id'];
            $rr_i++;
            $why   = 'rozdział po równo';
            $out['roundrobin']++;
        } else {
            $out['skipped']++;
            continue;
        }

        if ((int)($c['owner_id'] ?? 0) === $owner) { $out['skipped']++; continue; }

        $out['rows'][] = [
            'id' => (int)$c['id'], 'name' => (string)$c['imie_nazwisko'],
            'owner_id' => $owner, 'why' => $why,
        ];

        if (!$apply) continue;

        try {
            db()->prepare("UPDATE crm_contacts SET owner_id=?, updated_at=? WHERE id=?")
                ->execute([$owner, $now, (int)$c['id']]);
            db_insert('crm_notes', [
                'contact_id' => (int)$c['id'],
                'body'       => 'Opiekun przypisany automatycznie (' . $why . ').',
                'created_by' => $uid ?: null,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[crm_owner_assign_bulk] ' . $e->getMessage());
        }
    }

    return $out;
}
