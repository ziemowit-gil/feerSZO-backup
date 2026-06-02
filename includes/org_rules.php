<?php
/**
 * includes/org_rules.php — Moduł Zasady i Wprowadzenie.
 *
 * Tabele:
 *   org_rules     — zasady/artykuły (regulaminy, struktura, intro)
 *   org_rules_ack — potwierdzenia przeczytania przez użytkowników
 */

function org_rules_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_rules (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        title        TEXT    NOT NULL,
        content      TEXT    NOT NULL DEFAULT '',
        category     TEXT    NOT NULL DEFAULT 'intro',
        icon         TEXT    NOT NULL DEFAULT 'bi-file-text',
        color        TEXT    NOT NULL DEFAULT '#2563EB',
        is_mandatory INTEGER NOT NULL DEFAULT 0,
        is_public    INTEGER NOT NULL DEFAULT 1,
        sort_order   INTEGER NOT NULL DEFAULT 0,
        status       TEXT    NOT NULL DEFAULT 'active',
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_org_rules_cat ON org_rules(category, status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS org_rules_ack (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        rule_id  INTEGER NOT NULL REFERENCES org_rules(id) ON DELETE CASCADE,
        user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        ack_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip       TEXT,
        UNIQUE(rule_id, user_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_org_rules_ack_user ON org_rules_ack(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_org_rules_ack_rule ON org_rules_ack(rule_id)");
}

// Kategorie z etykietami i ikonami
const ORG_RULE_CATEGORIES = [
    'intro'       => ['label'=>'Wprowadzenie do organizacji', 'icon'=>'bi-building-heart',   'color'=>'#2563EB'],
    'regulamin'   => ['label'=>'Regulaminy i zasady',         'icon'=>'bi-file-earmark-text', 'color'=>'#DC2626'],
    'bhp'         => ['label'=>'BHP i bezpieczeństwo',        'icon'=>'bi-shield-check',      'color'=>'#D97706'],
    'struktura'   => ['label'=>'Struktura organizacji',       'icon'=>'bi-diagram-3-fill',    'color'=>'#7C3AED'],
    'rodo'        => ['label'=>'RODO i dane osobowe',         'icon'=>'bi-lock-fill',         'color'=>'#0F766E'],
    'komunikacja' => ['label'=>'Komunikacja i kontakty',      'icon'=>'bi-chat-dots-fill',    'color'=>'#2E844A'],
    'inne'        => ['label'=>'Inne',                        'icon'=>'bi-three-dots',        'color'=>'#6B7280'],
];

/** Wszystkie aktywne zasady wg kategorii */
function org_rules_all(string $category = '', bool $mandatory_only = false): array {
    org_rules_migrate();
    $where = ["status='active'"];
    $params = [];
    if ($category) { $where[] = "category=?"; $params[] = $category; }
    if ($mandatory_only) { $where[] = "is_mandatory=1"; }
    $w = implode(' AND ', $where);
    return db_all("SELECT * FROM org_rules WHERE {$w} ORDER BY sort_order, created_at", $params);
}

/** Zasady obowiązkowe niepotwierdzonych przez użytkownika */
function org_rules_unread(int $user_id): array {
    org_rules_migrate();
    return db_all(
        "SELECT r.* FROM org_rules r
         WHERE r.is_mandatory=1 AND r.status='active'
           AND NOT EXISTS (
               SELECT 1 FROM org_rules_ack a WHERE a.rule_id=r.id AND a.user_id=?
           )
         ORDER BY r.sort_order, r.created_at",
        [$user_id]
    );
}

/** Czy user przeczytał konkretną zasadę */
function org_rule_is_read(int $rule_id, int $user_id): bool {
    $r = db_one("SELECT id FROM org_rules_ack WHERE rule_id=? AND user_id=?", [$rule_id, $user_id]);
    return (bool)$r;
}

/** Potwierdź przeczytanie */
function org_rule_acknowledge(int $rule_id, int $user_id): void {
    org_rules_migrate();
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO org_rules_ack (rule_id, user_id, ack_at, ip)
             VALUES (?,?,datetime('now'),?)"
        )->execute([$rule_id, $user_id, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) {}
}

/** Statystyki potwierdzenia dla admina */
function org_rule_ack_stats(int $rule_id): array {
    $total   = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE is_active=1 AND role NOT IN ('viewer')")['c'] ?? 0);
    // uwzględnij też viewerów
    $total_all = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE is_active=1")['c'] ?? 0);
    $read    = (int)(db_one("SELECT COUNT(*) AS c FROM org_rules_ack WHERE rule_id=?", [$rule_id])['c'] ?? 0);
    return ['total' => $total_all, 'read' => $read, 'pct' => $total_all > 0 ? round($read/$total_all*100) : 0];
}
