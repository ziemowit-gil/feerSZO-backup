<?php
/**
 * includes/crm_case_parties.php — podmioty sprawy CRM i przenoszenie dokumentacji między sprawami.
 *
 * 1. PODMIOTY. Sprawa ma kontakt główny (crm_cases.contact_id), a do tego dowolne inne kontakty/firmy CRM
 *    w roli (strona, pełnomocnik, instytucja, opiekun, inne) — tabela crm_case_parties. Jak „Uczestnicy”
 *    rekordu w Dynamics 365. Panel „Podmioty sprawy” w crm/cases/view.php.
 *
 * 2. PRZENIESIENIE DOKUMENTACJI. Gdy do sprawy wpinamy podmiot, który ma własną (inną) sprawę, jej dokumentacja
 *    przechodzi do bieżącej: pisma (contract_letters crm_case), pliki, notatki, wiadomości (crm_communications),
 *    zadania CRM i powiązania z modułem Zadań. Kontakt główny sprawy źródłowej zostaje podmiotem sprawy docelowej.
 *    Obie sprawy dostają notatkę; źródłową można zamknąć (powód „Duplikat innej sprawy”). Wszystko w audit_log.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crm_case_extras.php';

const CRM_CASE_PARTY_ROLES = [
    'strona'      => 'Strona sprawy',
    'pelnomocnik' => 'Pełnomocnik / przedstawiciel',
    'opiekun'     => 'Opiekun prawny',
    'instytucja'  => 'Instytucja',
    'inne'        => 'Inny podmiot',
];

function crm_case_parties_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('crm_case_parties', __FILE__)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_case_parties (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        case_id    INTEGER NOT NULL REFERENCES crm_cases(id) ON DELETE CASCADE,
        contact_id INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        role       TEXT NOT NULL DEFAULT 'strona',
        note       TEXT,
        added_by   INTEGER,
        added_at   DATETIME
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_case_parties ON crm_case_parties(case_id, contact_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_parties_contact ON crm_case_parties(contact_id)");
}

/** Podmioty sprawy: kontakt główny (is_main) + dopięte. @return list<array> */
function crm_case_parties(int $caseId): array {
    crm_case_parties_schema();
    $out = [];
    $main = db_one("SELECT ct.id, ct.imie_nazwisko, ct.organizacja, ct.type, ct.email FROM crm_cases c JOIN crm_contacts ct ON ct.id = c.contact_id WHERE c.id = ?", [$caseId]);
    if ($main) $out[] = $main + ['party_id' => 0, 'role' => 'glowny', 'role_label' => 'Kontakt główny', 'note' => '', 'is_main' => true];
    foreach (db_all("SELECT p.id AS party_id, p.role, p.note, ct.id, ct.imie_nazwisko, ct.organizacja, ct.type, ct.email
                     FROM crm_case_parties p JOIN crm_contacts ct ON ct.id = p.contact_id WHERE p.case_id = ? ORDER BY p.id", [$caseId]) as $r)
        $out[] = $r + ['role_label' => CRM_CASE_PARTY_ROLES[$r['role']] ?? $r['role'], 'is_main' => false];
    return $out;
}

/** @return string '' = OK, inaczej komunikat */
function crm_case_party_add(int $caseId, int $contactId, string $role, string $note, int $uid): string {
    crm_case_parties_schema();
    $case = db_one("SELECT contact_id FROM crm_cases WHERE id = ?", [$caseId]);
    if (!$case) return 'Nie ma takiej sprawy.';
    if (!db_one("SELECT id FROM crm_contacts WHERE id = ?", [$contactId])) return 'Wybierz kontakt z listy.';
    if ((int)$case['contact_id'] === $contactId) return 'To jest kontakt główny tej sprawy.';
    if (db_one("SELECT id FROM crm_case_parties WHERE case_id = ? AND contact_id = ?", [$caseId, $contactId])) return 'Ten podmiot jest już wpięty do sprawy.';
    db_insert('crm_case_parties', ['case_id' => $caseId, 'contact_id' => $contactId, 'role' => isset(CRM_CASE_PARTY_ROLES[$role]) ? $role : 'strona',
        'note' => mb_substr(trim($note), 0, 300) ?: null, 'added_by' => $uid ?: null, 'added_at' => date('Y-m-d H:i:s')]);
    db_exec("UPDATE crm_cases SET updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $caseId]);
    if (function_exists('audit_log')) audit_log('crm.case_party_add', ['case_id' => $caseId, 'contact_id' => $contactId, 'role' => $role], $uid ?: null);
    return '';
}

function crm_case_party_remove(int $caseId, int $partyId, int $uid): void {
    crm_case_parties_schema();
    $p = db_one("SELECT contact_id FROM crm_case_parties WHERE id = ? AND case_id = ?", [$partyId, $caseId]);
    if (!$p) return;
    db_exec("DELETE FROM crm_case_parties WHERE id = ?", [$partyId]);
    if (function_exists('audit_log')) audit_log('crm.case_party_remove', ['case_id' => $caseId, 'contact_id' => (int)$p['contact_id']], $uid ?: null);
}

/** Ile dokumentów ma sprawa (do podpowiedzi przy przenoszeniu). */
function crm_case_doc_counts(int $caseId): array {
    $n = fn(string $sql) => (int)(db_one($sql, [$caseId])['n'] ?? 0);
    $c = ['pisma' => $n("SELECT COUNT(*) n FROM contract_letters WHERE contract_type = 'crm_case' AND contract_id = ?"),
          'pliki' => $n("SELECT COUNT(*) n FROM crm_case_files WHERE case_id = ?"),
          'notatki' => $n("SELECT COUNT(*) n FROM crm_case_notes WHERE case_id = ?"),
          'wiadomosci' => 0, 'zadania' => 0];
    try { $c['wiadomosci'] = $n("SELECT COUNT(*) n FROM crm_communications WHERE case_id = ?"); } catch (\Throwable $e) {}
    try { $c['zadania'] = $n("SELECT COUNT(*) n FROM crm_tasks WHERE case_id = ?"); } catch (\Throwable $e) {}
    return $c;
}

/** Inne sprawy podmiotu (jako kontakt główny albo wpięty podmiot), z liczbą dokumentów. */
function crm_case_party_other_cases(int $caseId, int $contactId): array {
    crm_case_parties_schema();
    $rows = db_all("SELECT DISTINCT c.id, c.case_number, c.title, c.status, c.created_at FROM crm_cases c
                    LEFT JOIN crm_case_parties p ON p.case_id = c.id
                    WHERE c.id <> ? AND (c.contact_id = ? OR p.contact_id = ?) ORDER BY c.created_at DESC LIMIT 30", [$caseId, $contactId, $contactId]);
    foreach ($rows as &$r) { $r['docs'] = crm_case_doc_counts((int)$r['id']); $r['docs_total'] = array_sum($r['docs']); }
    unset($r);
    return $rows;
}

/**
 * Przenosi dokumentację ze sprawy $srcId do $dstId. @return array{ok:bool, error?:string, moved?:array}
 */
function crm_case_move_docs(int $srcId, int $dstId, int $uid, bool $closeSrc = false): array {
    crm_case_parties_schema();
    if ($srcId === $dstId) return ['ok' => false, 'error' => 'Sprawa źródłowa i docelowa są tą samą sprawą.'];
    $src = db_one("SELECT * FROM crm_cases WHERE id = ?", [$srcId]);
    $dst = db_one("SELECT * FROM crm_cases WHERE id = ?", [$dstId]);
    if (!$src || !$dst) return ['ok' => false, 'error' => 'Nie ma takiej sprawy.'];
    if (function_exists('crm_case_can_edit') && !crm_case_can_edit($src)) return ['ok' => false, 'error' => 'Nie masz prawa edycji sprawy źródłowej ' . ($src['case_number'] ?: '#' . $srcId) . '.'];
    $moved = crm_case_doc_counts($srcId);
    $now = date('Y-m-d H:i:s');
    $pdo = db(); $pdo->beginTransaction();
    try {
        db_exec("UPDATE contract_letters SET contract_id = ? WHERE contract_type = 'crm_case' AND contract_id = ?", [$dstId, $srcId]);
        db_exec("UPDATE crm_case_files SET case_id = ? WHERE case_id = ?", [$dstId, $srcId]);
        db_exec("UPDATE crm_case_notes SET case_id = ? WHERE case_id = ?", [$dstId, $srcId]);
        try { db_exec("UPDATE crm_communications SET case_id = ? WHERE case_id = ?", [$dstId, $srcId]); } catch (\Throwable $e) {}
        try { db_exec("UPDATE crm_tasks SET case_id = ? WHERE case_id = ?", [$dstId, $srcId]); } catch (\Throwable $e) {}
        try {
            db_exec("INSERT OR IGNORE INTO crm_case_tasks (case_id, task_id, created_at) SELECT ?, task_id, created_at FROM crm_case_tasks WHERE case_id = ?", [$dstId, $srcId]);
            db_exec("DELETE FROM crm_case_tasks WHERE case_id = ?", [$srcId]);
        } catch (\Throwable $e) {}
        // podmioty źródłowej (kontakt główny i dopięte) stają się podmiotami docelowej
        $ids = array_merge([(int)$src['contact_id']], array_map('intval', array_column(db_all("SELECT contact_id FROM crm_case_parties WHERE case_id = ?", [$srcId]), 'contact_id')));
        foreach (array_unique(array_filter($ids)) as $cid) {
            if ($cid === (int)$dst['contact_id']) continue;
            db_exec("INSERT OR IGNORE INTO crm_case_parties (case_id, contact_id, role, note, added_by, added_at) VALUES (?, ?, 'strona', ?, ?, ?)",
                [$dstId, $cid, 'z przeniesionej sprawy ' . ($src['case_number'] ?: '#' . $srcId), $uid ?: null, $now]);
        }
        $what = sprintf('%d pism, %d plików, %d notatek, %d wiadomości, %d zadań', $moved['pisma'], $moved['pliki'], $moved['notatki'], $moved['wiadomosci'], $moved['zadania']);
        db_insert('crm_case_notes', ['case_id' => $dstId, 'created_by' => $uid ?: null, 'created_at' => $now,
            'body' => 'Przeniesiono dokumentację ze sprawy ' . ($src['case_number'] ?: '#' . $srcId) . ' „' . $src['title'] . '”: ' . $what . '.']);
        db_insert('crm_case_notes', ['case_id' => $srcId, 'created_by' => $uid ?: null, 'created_at' => $now,
            'body' => 'Dokumentacja tej sprawy (' . $what . ') została przeniesiona do sprawy ' . ($dst['case_number'] ?: '#' . $dstId) . ' „' . $dst['title'] . '”.']);
        db_exec("UPDATE crm_cases SET updated_at = ? WHERE id IN (?, ?)", [$now, $srcId, $dstId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[crm] move docs: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się przenieść dokumentacji: ' . $e->getMessage()];
    }
    if ($closeSrc && !in_array($src['status'], ['closed', 'cancelled'], true)) crm_case_set_status($srcId, 'closed', 'duplikat', $uid ?: null);
    if (function_exists('audit_log')) audit_log('crm.case_move_docs', ['from' => $srcId, 'to' => $dstId, 'moved' => $moved, 'closed_src' => $closeSrc], $uid ?: null);
    return ['ok' => true, 'moved' => $moved];
}
