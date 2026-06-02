<?php
/**
 * Moduł Struktury Organizacyjnej — helpery DB + auto-migracja.
 *
 * Hierarchia: org_units (self-ref) → org_members (m2m users ↔ units)
 *             Każdy member może być head jednostki, mieć zastępstwo i historię.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_units (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id          INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
        supervisor_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
        supervisor_user_id INTEGER REFERENCES users(id)     ON DELETE SET NULL,
        code               TEXT    NOT NULL UNIQUE,
        name               TEXT    NOT NULL,
        short_name         TEXT    NOT NULL DEFAULT '',
        description        TEXT    NOT NULL DEFAULT '',
        phone              TEXT    NOT NULL DEFAULT '',
        email              TEXT    NOT NULL DEFAULT '',
        location           TEXT    NOT NULL DEFAULT '',
        status             TEXT    NOT NULL DEFAULT 'active',
        sort_order         INTEGER NOT NULL DEFAULT 0,
        created_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_positions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT    NOT NULL,
        code         TEXT    NOT NULL DEFAULT '',
        is_head_role INTEGER NOT NULL DEFAULT 0,
        sort_order   INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_members (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        unit_id       INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
        user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        position_id   INTEGER REFERENCES org_positions(id) ON DELETE SET NULL,
        position_name TEXT    NOT NULL DEFAULT '',
        is_head       INTEGER NOT NULL DEFAULT 0,
        is_primary    INTEGER NOT NULL DEFAULT 0,
        email_service TEXT    NOT NULL DEFAULT '',
        phone_direct  TEXT    NOT NULL DEFAULT '',
        phone_mobile  TEXT    NOT NULL DEFAULT '',
        availability  TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'active',
        substitute_id INTEGER REFERENCES org_members(id) ON DELETE SET NULL,
        valid_from    DATE,
        valid_to      DATE,
        sort_order    INTEGER NOT NULL DEFAULT 0,
        notes         TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_history (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT    NOT NULL,
        entity_id   INTEGER NOT NULL,
        action      TEXT    NOT NULL,
        old_data    TEXT    NOT NULL DEFAULT '',
        new_data    TEXT    NOT NULL DEFAULT '',
        changed_by  INTEGER NOT NULL REFERENCES users(id),
        changed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        note        TEXT    NOT NULL DEFAULT ''
    )");

    // Indeksy
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_org_members_unit  ON org_members(unit_id)",
        "CREATE INDEX IF NOT EXISTS idx_org_members_user  ON org_members(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_org_history_ent   ON org_history(entity_type,entity_id)",
        "CREATE UNIQUE INDEX IF NOT EXISTS uidx_org_unit_head ON org_members(unit_id,is_head) WHERE is_head=1",
    ] as $idx) {
        try { $pdo->exec($idx); } catch (\Throwable $e) {}
    }

    // Rozszerzenie ezd_dekretacje o unit_id
    try {
        $pdo->exec("ALTER TABLE ezd_dekretacje ADD COLUMN unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}

    // Migracja: dodaj sort_order do org_members (dla istniejących baz)
    try {
        $pdo->exec("ALTER TABLE org_members ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}

    // Migracja: nadzorowanie jednostki i osoby
    try {
        $pdo->exec("ALTER TABLE org_units ADD COLUMN supervisor_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE org_units ADD COLUMN supervisor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}

    // Seed: domyślne stanowiska
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM org_positions")->fetchColumn();
    if ($cnt === 0) {
        $ins = $pdo->prepare("INSERT INTO org_positions (name,code,is_head_role,sort_order) VALUES (?,?,?,?)");
        foreach ([
            ['Prezes / Zarząd',          'PREZ',  1, 10],
            ['Dyrektor',                 'DYR',   1, 20],
            ['Kierownik',                'KIER',  1, 30],
            ['Koordynator',              'KOOR',  0, 40],
            ['Specjalista / Referent',   'SPEC',  0, 50],
            ['Asystent',                 'AST',   0, 60],
            ['Wolontariusz',             'WOL',   0, 70],
            ['Stażysta',                 'STAZ',  0, 80],
        ] as [$n,$c,$h,$s]) {
            try { $ins->execute([$n,$c,$h,$s]); } catch (\Throwable $e) {}
        }
    }
})();

// ── Stałe ────────────────────────────────────────────────────────────────────

const ORG_MEMBER_STATUSES = [
    'active'   => ['label' => 'Aktywny',    'class' => 'success'],
    'leave'    => ['label' => 'Urlop',      'class' => 'warning'],
    'sick'     => ['label' => 'Zwolnienie', 'class' => 'danger'],
    'remote'   => ['label' => 'Zdalnie',    'class' => 'info'],
    'inactive' => ['label' => 'Nieaktywny', 'class' => 'secondary'],
];

const ORG_UNIT_STATUSES = [
    'active'   => ['label' => 'Aktywna',    'class' => 'success'],
    'inactive' => ['label' => 'Nieaktywna', 'class' => 'secondary'],
];

// ── Jednostki Organizacyjne ───────────────────────────────────────────────────

function org_unit_get(int $id): ?array {
    return db_one(
        "SELECT u.*,
                p.name  AS parent_name,   p.code AS parent_code,
                su.name AS supervisor_unit_name, su.code AS supervisor_unit_code,
                uu.name AS supervisor_user_name
         FROM org_units u
         LEFT JOIN org_units p  ON p.id  = u.parent_id
         LEFT JOIN org_units su ON su.id = u.supervisor_unit_id
         LEFT JOIN users     uu ON uu.id = u.supervisor_user_id
         WHERE u.id=?", [$id]
    );
}

function org_units_all(string $status = ''): array {
    $where  = $status ? "WHERE u.status=?" : "";
    $params = $status ? [$status] : [];
    return db_all(
        "SELECT u.*,
                p.name  AS parent_name,   p.code AS parent_code,
                su.name AS supervisor_unit_name, su.code AS supervisor_unit_code,
                uu.name AS supervisor_user_name,
                (SELECT COUNT(*) FROM org_members m WHERE m.unit_id=u.id AND m.valid_to IS NULL) AS member_count
         FROM org_units u
         LEFT JOIN org_units p  ON p.id  = u.parent_id
         LEFT JOIN org_units su ON su.id = u.supervisor_unit_id
         LEFT JOIN users     uu ON uu.id = u.supervisor_user_id
         $where ORDER BY u.sort_order, u.code", $params
    );
}

/** Buduje zagnieżdżone drzewo z płaskiej listy */
function org_build_tree(array $flat, ?int $parent_id = null): array {
    $nodes = [];
    foreach ($flat as $u) {
        $pid = $u['parent_id'] === null ? null : (int)$u['parent_id'];
        if ($pid === $parent_id) {
            $u['children'] = org_build_tree($flat, (int)$u['id']);
            $nodes[] = $u;
        }
    }
    usort($nodes, fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['code'], $b['code']));
    return $nodes;
}

function org_unit_create(array $d, int $user_id): int {
    $pdo = db();
    // Walidacja unikalności kodu
    $exists = db_one("SELECT id FROM org_units WHERE code=?", [strtoupper(trim($d['code']))]);
    if ($exists) throw new \RuntimeException("Kod '{$d['code']}' jest już zajęty.");

    $pdo->prepare(
        "INSERT INTO org_units
         (parent_id,supervisor_unit_id,supervisor_user_id,code,name,short_name,description,phone,email,location,status,sort_order,created_by,updated_at)
         VALUES (:pid,:sup_unit,:sup_user,:code,:name,:short,:desc,:phone,:email,:loc,:status,:sort,:uid,datetime('now'))"
    )->execute([
        ':pid'      => $d['parent_id']          ?: null,
        ':sup_unit' => $d['supervisor_unit_id']  ?: null,
        ':sup_user' => $d['supervisor_user_id']  ?: null,
        ':code'     => strtoupper(trim($d['code'])),
        ':name'     => trim($d['name']),
        ':short'    => trim($d['short_name']    ?? ''),
        ':desc'     => trim($d['description']   ?? ''),
        ':phone'    => trim($d['phone']         ?? ''),
        ':email'    => trim($d['email']         ?? ''),
        ':loc'      => trim($d['location']      ?? ''),
        ':status'   => $d['status']             ?? 'active',
        ':sort'     => (int)($d['sort_order']   ?? 0),
        ':uid'      => $user_id,
    ]);
    $id = (int)$pdo->lastInsertId();
    org_log('unit', $id, 'create', [], $d, $user_id, 'Utworzono jednostkę: ' . $d['name']);
    return $id;
}

function org_unit_update(int $id, array $d, int $user_id): void {
    $old = org_unit_get($id);
    // Check code uniqueness (exclude self)
    $exists = db_one("SELECT id FROM org_units WHERE code=? AND id!=?", [strtoupper(trim($d['code'])), $id]);
    if ($exists) throw new \RuntimeException("Kod '{$d['code']}' jest już zajęty przez inną jednostkę.");

    db()->prepare(
        "UPDATE org_units
         SET parent_id=:pid, supervisor_unit_id=:sup_unit, supervisor_user_id=:sup_user,
             code=:code, name=:name, short_name=:short, description=:desc,
             phone=:phone, email=:email, location=:loc, status=:status,
             sort_order=:sort, updated_at=datetime('now')
         WHERE id=:id"
    )->execute([
        ':pid'      => $d['parent_id']          ?: null,
        ':sup_unit' => $d['supervisor_unit_id']  ?: null,
        ':sup_user' => $d['supervisor_user_id']  ?: null,
        ':code'     => strtoupper(trim($d['code'])),
        ':name'     => trim($d['name']),
        ':short'    => trim($d['short_name']    ?? ''),
        ':desc'     => trim($d['description']   ?? ''),
        ':phone'    => trim($d['phone']         ?? ''),
        ':email'    => trim($d['email']         ?? ''),
        ':loc'      => trim($d['location']      ?? ''),
        ':status'   => $d['status']             ?? 'active',
        ':sort'     => (int)($d['sort_order']   ?? 0),
        ':id'       => $id,
    ]);
    org_log('unit', $id, 'update', $old ?? [], $d, $user_id, 'Edytowano jednostkę #' . $id);
}

function org_unit_delete(int $id, int $user_id): void {
    $old = org_unit_get($id);
    // Miękkie usunięcie — dezaktywacja
    db()->prepare("UPDATE org_units SET status='inactive',updated_at=datetime('now') WHERE id=?")->execute([$id]);
    org_log('unit', $id, 'deactivate', $old ?? [], [], $user_id, 'Dezaktywowano jednostkę #' . $id);
}

// ── Kierownicy / Head ─────────────────────────────────────────────────────────

function org_unit_head(int $unit_id): ?array {
    return db_one(
        "SELECT m.*, u.name AS user_name, u.email AS user_email,
                p.name AS position_label
         FROM org_members m
         JOIN users u ON u.id=m.user_id
         LEFT JOIN org_positions p ON p.id=m.position_id
         WHERE m.unit_id=? AND m.is_head=1 AND (m.valid_to IS NULL OR m.valid_to >= date('now'))
         ORDER BY m.created_at DESC LIMIT 1",
        [$unit_id]
    );
}

/** Zwraca wszystkich aktualnych członków jednostki */
function org_members_by_unit(int $unit_id, bool $include_historical = false): array {
    $filter = $include_historical ? "" : "AND (m.valid_to IS NULL OR m.valid_to >= date('now'))";
    return db_all(
        "SELECT m.*, u.name AS user_name, u.email AS user_email,
                p.name AS position_label, p.is_head_role,
                sub.user_id AS sub_user_id, su.name AS sub_name
         FROM org_members m
         JOIN users u ON u.id=m.user_id
         LEFT JOIN org_positions p ON p.id=m.position_id
         LEFT JOIN org_members sub ON sub.id=m.substitute_id
         LEFT JOIN users su ON su.id=sub.user_id
         WHERE m.unit_id=? $filter
         ORDER BY m.is_head DESC, m.sort_order ASC, u.name",
        [$unit_id]
    );
}

function org_member_get(int $id): ?array {
    return db_one(
        "SELECT m.*, u.name AS user_name, u.email AS user_email,
                o.name AS unit_name, o.code AS unit_code,
                p.name AS position_label,
                sub.user_id AS sub_user_id, su.name AS sub_name
         FROM org_members m
         JOIN users u ON u.id=m.user_id
         JOIN org_units o ON o.id=m.unit_id
         LEFT JOIN org_positions p ON p.id=m.position_id
         LEFT JOIN org_members sub ON sub.id=m.substitute_id
         LEFT JOIN users su ON su.id=sub.user_id
         WHERE m.id=?", [$id]
    );
}

function org_member_add(array $d, int $user_id): int {
    // Sprawdź czy user już jest w tej jednostce (aktywny)
    $exists = db_one(
        "SELECT id FROM org_members WHERE unit_id=? AND user_id=? AND (valid_to IS NULL OR valid_to >= date('now'))",
        [(int)$d['unit_id'], (int)$d['user_id']]
    );
    if ($exists) throw new \RuntimeException('Ta osoba jest już aktywnym członkiem tej jednostki.');

    // Jeśli nowy member ma is_head=1, odznacz poprzedniego
    if (!empty($d['is_head'])) {
        db()->prepare("UPDATE org_members SET is_head=0 WHERE unit_id=? AND (valid_to IS NULL OR valid_to >= date('now'))")
             ->execute([(int)$d['unit_id']]);
    }

    db()->prepare(
        "INSERT INTO org_members
         (unit_id,user_id,position_id,position_name,is_head,is_primary,email_service,phone_direct,phone_mobile,
          availability,status,substitute_id,valid_from,valid_to,notes,created_by,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))"
    )->execute([
        (int)$d['unit_id'],
        (int)$d['user_id'],
        $d['position_id'] ?: null,
        trim($d['position_name'] ?? ''),
        (int)!empty($d['is_head']),
        (int)!empty($d['is_primary']),
        trim($d['email_service'] ?? ''),
        trim($d['phone_direct']  ?? ''),
        trim($d['phone_mobile']  ?? ''),
        trim($d['availability']  ?? ''),
        $d['status'] ?? 'active',
        $d['substitute_id'] ?: null,
        $d['valid_from'] ?: date('Y-m-d'),
        $d['valid_to']   ?: null,
        trim($d['notes'] ?? ''),
        $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    org_log('member', $id, 'add', [], $d, $user_id, "Dodano #{$d['user_id']} do jednostki #{$d['unit_id']}");
    return $id;
}

function org_member_update(int $id, array $d, int $user_id): void {
    $old = org_member_get($id);

    // Jeśli zmiana na is_head=1, odznacz poprzedniego w tej jednostce
    if (!empty($d['is_head']) && !($old['is_head'] ?? 0)) {
        db()->prepare("UPDATE org_members SET is_head=0 WHERE unit_id=? AND id!=? AND (valid_to IS NULL OR valid_to >= date('now'))")
             ->execute([(int)($old['unit_id'] ?? 0), $id]);
        org_log('unit', (int)($old['unit_id'] ?? 0), 'head_change', ['old_head' => $old['user_id'] ?? null], ['new_head' => $d['user_id'] ?? null], $user_id, 'Zmiana kierownika');
    }

    db()->prepare(
        "UPDATE org_members SET position_id=:pid,position_name=:pname,is_head=:head,is_primary=:prim,
         email_service=:email,phone_direct=:pd,phone_mobile=:pm,availability=:av,status=:status,
         substitute_id=:sub,valid_from=:vf,valid_to=:vt,notes=:notes,updated_at=datetime('now')
         WHERE id=:id"
    )->execute([
        ':pid'   => $d['position_id']   ?: null,
        ':pname' => trim($d['position_name'] ?? ''),
        ':head'  => (int)!empty($d['is_head']),
        ':prim'  => (int)!empty($d['is_primary']),
        ':email' => trim($d['email_service'] ?? ''),
        ':pd'    => trim($d['phone_direct']  ?? ''),
        ':pm'    => trim($d['phone_mobile']  ?? ''),
        ':av'    => trim($d['availability']  ?? ''),
        ':status'=> $d['status'] ?? 'active',
        ':sub'   => $d['substitute_id'] ?: null,
        ':vf'    => $d['valid_from'] ?: null,
        ':vt'    => $d['valid_to']   ?: null,
        ':notes' => trim($d['notes'] ?? ''),
        ':id'    => $id,
    ]);
    org_log('member', $id, 'update', $old ?? [], $d, $user_id, 'Edytowano przypisanie #' . $id);
}

function org_member_remove(int $id, int $user_id): void {
    $old = org_member_get($id);
    // Historyczne zamknięcie — ustaw valid_to = dziś
    db()->prepare("UPDATE org_members SET valid_to=date('now'),status='inactive',updated_at=datetime('now') WHERE id=?")
         ->execute([$id]);
    org_log('member', $id, 'remove', $old ?? [], [], $user_id, 'Zakończono przypisanie #' . $id);
}

// ── Zapytania kontekstowe ─────────────────────────────────────────────────────

/** Wszystkie aktywne jednostki użytkownika */
function org_user_units(int $user_id): array {
    return db_all(
        "SELECT m.*, o.name AS unit_name, o.code AS unit_code, o.email AS unit_email,
                p.name AS position_label
         FROM org_members m
         JOIN org_units o ON o.id=m.unit_id
         LEFT JOIN org_positions p ON p.id=m.position_id
         WHERE m.user_id=? AND (m.valid_to IS NULL OR m.valid_to >= date('now'))
         ORDER BY m.is_primary DESC, m.is_head DESC, o.name",
        [$user_id]
    );
}

/** Zwraca przełożonego użytkownika (kierownik jednej z jego jednostek) */
function org_user_supervisor(int $user_id): ?array {
    $units = org_user_units($user_id);
    foreach ($units as $m) {
        $head = org_unit_head((int)$m['unit_id']);
        if ($head && (int)$head['user_id'] !== $user_id) return $head;
    }
    return null;
}

/**
 * Rozwiązuje faktyczny adres e-mail (uwzględnia zastępstwo).
 * Zwraca: ['email', 'name', 'user_id', 'via_substitute', 'original_name']
 */
function org_resolve_email(int $user_id): array {
    $user = db_one("SELECT id,name,email FROM users WHERE id=?", [$user_id]);
    if (!$user) return ['email' => '', 'name' => '', 'user_id' => $user_id, 'via_substitute' => false, 'original_name' => ''];

    // Szukaj aktywnego członkostwa z urlopem/zwolnieniem i ustawionym zastępstwem
    $member = db_one(
        "SELECT m.*, sub.user_id AS sub_user_id, sub.email_service AS sub_email_s
         FROM org_members m
         LEFT JOIN org_members sub ON sub.id=m.substitute_id
         WHERE m.user_id=? AND m.status IN ('leave','sick') AND m.substitute_id IS NOT NULL
           AND (m.valid_to IS NULL OR m.valid_to >= date('now'))
         ORDER BY m.is_primary DESC LIMIT 1",
        [$user_id]
    );

    if ($member && $member['sub_user_id']) {
        $sub_user = db_one("SELECT id,name,email FROM users WHERE id=?", [$member['sub_user_id']]);
        if ($sub_user) {
            $email = $member['sub_email_s'] ?: $sub_user['email'];
            return [
                'email'         => $email,
                'name'          => $sub_user['name'],
                'user_id'       => (int)$sub_user['id'],
                'via_substitute'=> true,
                'original_name' => $user['name'],
            ];
        }
    }

    // Pobierz służbowy e-mail z primary membership
    $primary = db_one(
        "SELECT email_service FROM org_members
         WHERE user_id=? AND (valid_to IS NULL OR valid_to >= date('now'))
         ORDER BY is_primary DESC LIMIT 1",
        [$user_id]
    );
    $email = ($primary['email_service'] ?? '') ?: ($user['email'] ?? '');

    return ['email' => $email, 'name' => $user['name'], 'user_id' => $user_id, 'via_substitute' => false, 'original_name' => ''];
}

// ── Stanowiska ────────────────────────────────────────────────────────────────

function org_positions_all(): array {
    return db_all("SELECT * FROM org_positions ORDER BY sort_order, name");
}

function org_positions_options(): array {
    $out = [];
    foreach (org_positions_all() as $p) {
        $out[$p['id']] = $p['name'];
    }
    return $out;
}

// ── Historia ──────────────────────────────────────────────────────────────────

function org_log(string $entity_type, int $entity_id, string $action, array $old, array $new, int $user_id, string $note = ''): void {
    try {
        db()->prepare(
            "INSERT INTO org_history (entity_type,entity_id,action,old_data,new_data,changed_by,note)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$entity_type, $entity_id, $action, json_encode($old, JSON_UNESCAPED_UNICODE), json_encode($new, JSON_UNESCAPED_UNICODE), $user_id, $note]);
    } catch (\Throwable $e) {}
}

function org_history_all(int $limit = 100): array {
    return db_all(
        "SELECT h.*, u.name AS user_name FROM org_history h
         LEFT JOIN users u ON u.id=h.changed_by
         ORDER BY h.changed_at DESC LIMIT ?", [$limit]
    );
}

// ── UI Helpery ────────────────────────────────────────────────────────────────

function org_status_badge(string $status): string {
    $s = ORG_MEMBER_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function org_unit_status_badge(string $status): string {
    $s = ORG_UNIT_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

/** Renderuje drzewo jednostek jako zagnieżdżony HTML */
function org_render_tree(array $nodes, int $depth = 0): void {
    $indent_px = $depth * 18;
    foreach ($nodes as $node):
        $head   = org_unit_head((int)$node['id']);
        $active = $node['status'] === 'active';
        $cid    = 'orgn-' . $node['id'];
        $has_ch = !empty($node['children']);
        ?>
        <div class="org-node" style="margin-left:<?= $indent_px ?>px">
          <div class="org-node-card d-flex align-items-center gap-2 px-3 py-2 <?= $active ? '' : 'opacity-50' ?>">
            <?php if ($has_ch): ?>
            <button type="button" class="btn btn-link p-0 text-muted org-toggle" data-bs-toggle="collapse" data-bs-target="#<?= $cid ?>" style="font-size:.7rem"><i class="bi bi-chevron-down"></i></button>
            <?php else: ?>
            <span style="width:1.1rem;display:inline-block"></span>
            <?php endif; ?>
            <i class="bi bi-diagram-3 text-primary" style="font-size:.85rem"></i>
            <div class="flex-grow-1">
              <a href="<?= APP_URL ?>/org/units/view.php?id=<?= $node['id'] ?>" class="fw-semibold text-decoration-none text-dark" style="font-size:.88rem"><?= h($node['name']) ?></a>
              <span class="badge bg-light text-dark border font-monospace ms-1" style="font-size:.65rem"><?= h($node['code']) ?></span>
              <?= org_unit_status_badge($node['status']) ?>
            </div>
            <?php if ($head): ?>
            <div class="text-muted d-none d-md-block" style="font-size:.74rem;white-space:nowrap"><i class="bi bi-person-fill me-1"></i><?= h($head['user_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($node['supervisor_unit_name']) || !empty($node['supervisor_user_name'])): ?>
            <div class="text-muted d-none d-lg-flex align-items-center gap-1" style="font-size:.68rem;white-space:nowrap;opacity:.75">
              <i class="bi bi-eye" title="Nadzoruje"></i>
              <?php if (!empty($node['supervisor_unit_name'])): ?>
              <a href="<?= APP_URL ?>/org/units/view.php?id=<?= (int)$node['supervisor_unit_id'] ?>" class="text-muted text-decoration-none"><?= h($node['supervisor_unit_code']) ?></a>
              <?php elseif (!empty($node['supervisor_user_name'])): ?>
              <span><?= h($node['supervisor_user_name']) ?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <span class="badge bg-light text-secondary border" title="Członkowie"><?= (int)$node['member_count'] ?></span>
            <?php if (is_admin()): ?>
            <a href="<?= APP_URL ?>/org/units/edit.php?id=<?= $node['id'] ?>" class="btn btn-link p-0 text-muted" style="font-size:.8rem" title="Edytuj"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
          </div>
          <?php if ($has_ch): ?>
          <div class="collapse show" id="<?= $cid ?>">
            <?php org_render_tree($node['children'], $depth + 1); ?>
          </div>
          <?php endif; ?>
        </div>
        <?php
    endforeach;
}
