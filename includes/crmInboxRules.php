<?php
/**
 * includes/crmInboxRules.php — reguły Skrzynki CRM: warunki (skrzynka, nadawca, temat, załącznik) → akcje
 * (załatwione, przeczytane, spam, ukryj, przypisz, tag kontaktu). Wykonywane przy zapisie nowej wiadomości
 * przychodzącej (includes/poczta.php) oraz na żądanie dla wiadomości już w skrzynce.
 * Warunki łączą się przez I; pola tekstowe przyjmują kilka fraz oddzielonych przecinkiem (wystarczy jedna — LUB).
 */
function crm_inbox_rules_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_inbox_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        mailbox_id INTEGER,
        from_contains TEXT,
        subject_contains TEXT,
        has_attachment TEXT,
        act_archive INTEGER NOT NULL DEFAULT 0,
        act_read INTEGER NOT NULL DEFAULT 0,
        act_spam INTEGER NOT NULL DEFAULT 0,
        act_hide INTEGER NOT NULL DEFAULT 0,
        act_assign INTEGER,
        act_tag TEXT,
        stop_after INTEGER NOT NULL DEFAULT 0,
        hits INTEGER NOT NULL DEFAULT 0,
        last_hit_at TEXT,
        created_by INTEGER,
        created_at TEXT
    )");
}

function crm_inbox_rules_all(bool $only_active = false): array {
    crm_inbox_rules_schema();
    return db_all("SELECT r.*, u.name AS assign_name FROM crm_inbox_rules r LEFT JOIN users u ON u.id=r.act_assign "
        . ($only_active ? "WHERE r.is_active=1 " : "") . "ORDER BY r.sort_order, r.id");
}

function _crm_rule_phrases(?string $s): array {
    return array_values(array_filter(array_map(fn($x) => mb_strtolower(trim($x), 'UTF-8'), explode(',', (string)$s)), fn($x) => $x !== ''));
}

function _crm_rule_has(string $hay, array $phrases): bool {
    $hay = mb_strtolower($hay, 'UTF-8');
    foreach ($phrases as $p) if ($p !== '' && mb_strpos($hay, $p) !== false) return true;
    return false;
}

/** Czy wiadomość (wiersz crm_communications) spełnia warunki reguły. */
function crm_inbox_rule_matches(array $rule, array $m): bool {
    if (!empty($rule['mailbox_id']) && (int)$rule['mailbox_id'] !== (int)($m['mailbox_id'] ?? 0)) return false;
    $from = _crm_rule_phrases($rule['from_contains'] ?? '');
    if ($from && !_crm_rule_has(($m['from_email'] ?? '') . ' ' . ($m['from_name'] ?? ''), $from)) return false;
    $subj = _crm_rule_phrases($rule['subject_contains'] ?? '');
    if ($subj && !_crm_rule_has((string)($m['subject'] ?? ''), $subj)) return false;
    $att = (string)($rule['has_attachment'] ?? '');
    if ($att === '1' && !(int)($m['has_attachments'] ?? 0)) return false;
    if ($att === '0' && (int)($m['has_attachments'] ?? 0)) return false;
    // reguła bez żadnego warunku nie może dotyczyć wszystkiego
    return $from || $subj || $att !== '' || !empty($rule['mailbox_id']);
}

/** Wykonuje reguły na jednej wiadomości przychodzącej. @return list<string> nazwy zastosowanych reguł */
function crm_inbox_rules_apply(int $comm_id): array {
    $m = db_one("SELECT id, contact_id, direction, mailbox_id, from_email, from_name, subject, has_attachments, inbox_status FROM crm_communications WHERE id=?", [$comm_id]);
    if (!$m || ($m['direction'] ?? '') !== 'in') return [];
    $applied = [];
    foreach (crm_inbox_rules_all(true) as $r) {
        if (!crm_inbox_rule_matches($r, $m)) continue;
        $upd = []; $par = [];
        if ((int)$r['act_read'])    { $upd[] = 'is_read=1'; }
        if ((int)$r['act_spam'])    { $upd[] = "inbox_status='spam'"; }
        elseif ((int)$r['act_archive']) { $upd[] = "inbox_status='archived'"; }
        if ((int)$r['act_hide'])    { $upd[] = 'crm_hidden=1'; }
        if ((int)$r['act_assign'])  { $upd[] = 'assigned_to=?'; $par[] = (int)$r['act_assign']; }
        try {
            if ($upd) { $par[] = $comm_id; db()->prepare("UPDATE crm_communications SET " . implode(',', $upd) . " WHERE id=?")->execute($par); }
            $tag = trim((string)($r['act_tag'] ?? ''));
            if ($tag !== '' && !empty($m['contact_id'])) {
                db()->prepare("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)")->execute([(int)$m['contact_id'], $tag]);
            }
            db()->prepare("UPDATE crm_inbox_rules SET hits=hits+1, last_hit_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
        } catch (\Throwable $e) {
            error_log('[crm_inbox_rules] ' . $e->getMessage());
            continue;
        }
        $applied[] = (string)$r['name'];
        if ((int)$r['stop_after']) break;
    }
    return $applied;
}

/** Reguły na wiadomościach już leżących w skrzynce (aktywne, przychodzące) — do przycisku „Zastosuj teraz”. */
function crm_inbox_rules_apply_existing(int $limit = 1000): array {
    $n = 0; $touched = 0;
    if (function_exists('crm_mailbox_schema_heal')) { try { crm_mailbox_schema_heal(); } catch (\Throwable $e) {} }
    foreach (db_all("SELECT id FROM crm_communications WHERE direction='in' AND inbox_status='active' AND COALESCE(crm_hidden,0)=0 ORDER BY sent_at DESC LIMIT " . (int)$limit) as $row) {
        $n++;
        if (crm_inbox_rules_apply((int)$row['id'])) $touched++;
    }
    return ['checked' => $n, 'matched' => $touched];
}
