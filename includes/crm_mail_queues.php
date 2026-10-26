<?php
/**
 * includes/crm_mail_queues.php — kolejki wiadomości w Skrzynce CRM + domyślna skrzynka faktur (faktury@feer.org.pl).
 *
 * Kolejka = nazwany „przegródkowy” podział wiadomości w skrzynce współdzielonej (np. Faktury, Oferty, Ogólne). Każda wiadomość należy do dokładnie
 * jednej kolejki: jawnej (crm_communications.queue_id, ustawianej ręcznie przy przenoszeniu) albo domyślnej dla skrzynki, na którą wpłynęła
 * (kolejka z mailbox_id = skrzynka i is_default=1), a gdy takiej nie ma — kolejki ogólnej (mailbox_id NULL, is_default=1).
 * Przypisanie do kolejki jest więc LICZONE (crm_mq_expr), nie kopiowane przy skanowaniu — skaner Poczty zostaje bez zmian, a nowa skrzynka
 * od razu trafia do kolejki ogólnej. Przeniesienie obejmuje cały wątek. Status i opiekun to dotychczasowe inbox_status / assigned_to.
 *
 * Domyślna skrzynka faktur: przy pierwszym uruchomieniu (flaga crm_mq_seeded) dopisujemy do poczta_mailboxes adres z ustawienia
 * crm_invoice_mailbox (domyślnie faktury@feer.org.pl; „-” wyłącza) jako skrzynkę współdzieloną, zakładamy kolejkę „Faktury” i nadajemy odczyt osobom
 * z rolami EODoK (dodawanie dokumentów, główny księgowy, zatwierdzanie). Usunięcie skrzynki/kolejki później NIE jest cofane (flaga).
 * Konto M365 jest rozpoznawane przy pierwszym skanie (poczta_mailboxes.ms_user_id) — brak konta = status błędu skrzynki, nic więcej.
 */

const CRM_MQ_DEFAULT_INVOICE_MAILBOX = 'faktury@feer.org.pl';

function crm_mq_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_queues (
            id          {$pk},
            slug        VARCHAR(60) NOT NULL,
            name        VARCHAR(120) NOT NULL,
            color       VARCHAR(9) NOT NULL DEFAULT '#6B7280',
            mailbox_id  INTEGER,
            is_default  INTEGER NOT NULL DEFAULT 0,
            is_active   INTEGER NOT NULL DEFAULT 1,
            sort_order  INTEGER NOT NULL DEFAULT 10,
            created_at  VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_mail_queues_slug ON crm_mail_queues(slug)");
        try { db()->exec("ALTER TABLE crm_communications ADD COLUMN queue_id INTEGER"); } catch (\Throwable $e) {}
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_queue ON crm_communications(queue_id)");
        crm_mq_seed();
    } catch (\Throwable $e) {
        error_log('[crm_mq_migrate] ' . $e->getMessage());
    }
}

/** Jednorazowe wartości domyślne: kolejka ogólna, skrzynka faktur@ i kolejka „Faktury”. */
function crm_mq_seed(): void
{
    if (org_setting('crm_mq_seeded') === '1') return;
    $now = date('Y-m-d H:i:s');
    if (!db_one("SELECT 1 AS x FROM crm_mail_queues WHERE slug = 'ogolne'")) {
        db_insert('crm_mail_queues', ['slug' => 'ogolne', 'name' => 'Ogólne', 'color' => '#6B7280', 'mailbox_id' => null, 'is_default' => 1, 'is_active' => 1, 'sort_order' => 100, 'created_at' => $now]);
    }
    $addr = strtolower(trim(org_setting('crm_invoice_mailbox')));
    if ($addr === '') $addr = CRM_MQ_DEFAULT_INVOICE_MAILBOX;
    if ($addr !== '-' && filter_var($addr, FILTER_VALIDATE_EMAIL)) crm_mq_ensure_invoice_mailbox($addr);
    org_setting_set('crm_mq_seeded', '1');
}

/**
 * Zapewnia skrzynkę faktur: wiersz w poczta_mailboxes (współdzielona, włączona; konto M365 rozpoznaje skaner), kolejkę „Faktury” jako domyślną
 * dla tej skrzynki oraz odczyt dla osób z rolami EODoK. Idempotentne. @return int id skrzynki
 */
function crm_mq_ensure_invoice_mailbox(string $addr): int
{
    $addr = strtolower(trim($addr));
    $mb = db_one("SELECT id FROM poczta_mailboxes WHERE LOWER(mailbox) = ?", [$addr]);
    $id = $mb ? (int)$mb['id'] : db_insert('poczta_mailboxes', ['mailbox' => $addr, 'ms_user_id' => null, 'display_name' => 'Faktury', 'enabled' => 1, 'kind' => 'shared', 'owner_user_id' => null, 'created_by' => null]);
    $q = db_one("SELECT id FROM crm_mail_queues WHERE slug = 'faktury'");
    if (!$q) {
        db_insert('crm_mail_queues', ['slug' => 'faktury', 'name' => 'Faktury', 'color' => '#0F766E', 'mailbox_id' => $id, 'is_default' => 1, 'is_active' => 1, 'sort_order' => 10, 'created_at' => date('Y-m-d H:i:s')]);
    } else {
        db()->prepare("UPDATE crm_mail_queues SET mailbox_id = ?, is_default = 1, is_active = 1 WHERE id = ?")->execute([$id, (int)$q['id']]);
    }
    try {   // odczyt dla osób obsługujących faktury w EODoK (administrator widzi wszystko bez wpisu)
        require_once __DIR__ . '/poczta_acl.php';
        foreach (db_all("SELECT DISTINCT r.user_id FROM edok_user_roles r JOIN users u ON u.id = r.user_id AND u.is_active = 1 WHERE r.role IN ('upload','ksiegowy','zatwierdza')") as $r) {
            poczta_acl_grant($id, (int)$r['user_id'], false);
        }
    } catch (\Throwable $e) { /* EODoK niewdrożony — pomijamy */ }
    return $id;
}

/**
 * Wyrażenie SQL z id kolejki wiadomości (alias tabeli crm_communications = $c): jawna aktywna → domyślna dla skrzynki → ogólna.
 * Używane w WHERE, GROUP BY i JOIN, więc przypisanie jest wszędzie takie samo.
 */
function crm_mq_expr(string $c = 'c'): string
{
    return "COALESCE("
        . "(SELECT qa.id FROM crm_mail_queues qa WHERE qa.id = {$c}.queue_id AND qa.is_active = 1),"
        . "(SELECT qm.id FROM crm_mail_queues qm WHERE qm.is_active = 1 AND qm.is_default = 1 AND qm.mailbox_id = {$c}.mailbox_id ORDER BY qm.id LIMIT 1),"
        . "(SELECT qg.id FROM crm_mail_queues qg WHERE qg.is_active = 1 AND qg.is_default = 1 AND qg.mailbox_id IS NULL ORDER BY qg.id LIMIT 1))";
}

/** Kolejki (aktywne albo wszystkie) w kolejności wyświetlania. */
function crm_mq_list(bool $onlyActive = true): array
{
    crm_mq_migrate();
    try {
        return db_all("SELECT q.*, m.mailbox AS mailbox_name FROM crm_mail_queues q LEFT JOIN poczta_mailboxes m ON m.id = q.mailbox_id "
            . ($onlyActive ? "WHERE q.is_active = 1 " : "") . "ORDER BY q.sort_order, q.id");
    } catch (\Throwable $e) { return []; }
}

/** Liczba wiadomości w bieżącym widoku i zakresie skrzynek w rozbiciu na kolejki: [queue_id => n]. Bez filtra kolejki — to jest filtr do wyboru. */
function crm_mq_counts(string $view, int $mailbox_id = 0, ?array $folderRules = null): array
{
    crm_mq_migrate();
    $uid = (int)(current_user()['id'] ?? 0);
    $view = isset(CRM_MAILBOX_VIEWS[$view]) ? $view : 'new';
    $params = [];
    $where = _crm_mailbox_scope_where($view, $uid, ['mailbox_id' => $mailbox_id, 'folder_rules' => $folderRules], $params);
    if ($where === null) return [];
    try {
        $rows = db_all("SELECT " . crm_mq_expr('c') . " AS qid, COUNT(*) AS n FROM crm_communications c WHERE " . implode(' AND ', $where) . " GROUP BY qid", $params);
    } catch (\Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[(int)($r['qid'] ?? 0)] = (int)$r['n'];
    return $out;
}

/** Kolejka wiadomości (po wyliczeniu) albo null. */
function crm_mq_of(int $comm_id): ?array
{
    crm_mq_migrate();
    try {
        return db_one("SELECT q.* FROM crm_communications c JOIN crm_mail_queues q ON q.id = " . crm_mq_expr('c') . " WHERE c.id = ?", [$comm_id]) ?: null;
    } catch (\Throwable $e) { return null; }
}

/** Przenosi wiadomość (z całym wątkiem) do kolejki; null = z powrotem do kolejki domyślnej skrzynki. @return int liczba przeniesionych wiadomości */
function crm_mq_move(int $comm_id, ?int $queue_id): int
{
    crm_mq_migrate();
    if ($queue_id !== null && !db_one("SELECT 1 AS x FROM crm_mail_queues WHERE id = ? AND is_active = 1", [$queue_id])) return 0;
    $row = db_one("SELECT thread_key FROM crm_communications WHERE id = ?", [$comm_id]);
    if (!$row) return 0;
    $st = db()->prepare("UPDATE crm_communications SET queue_id = ?, updated_at = datetime('now') WHERE id = ? OR (? <> '' AND thread_key = ?)");
    $tk = (string)($row['thread_key'] ?? '');
    $st->execute([$queue_id, $comm_id, $tk, $tk]);
    $moved = (int)$st->rowCount();
    if ($moved && function_exists('crm_fol_on_change')) {
        $qn = $queue_id ? (db_one("SELECT name FROM crm_mail_queues WHERE id = ?", [$queue_id])['name'] ?? '') : ((crm_mq_of($comm_id)['name'] ?? '') ?: 'domyślnej');
        crm_fol_on_change($comm_id, 'Rozmowa przeniesiona do kolejki: ' . $qn);
    }
    return $moved;
}

/** Zapis kolejki (nowa: $id = null). Zwraca id. Jedna kolejka domyślna na skrzynkę (oraz jedna ogólna). */
function crm_mq_save(array $d, ?int $id = null): int
{
    crm_mq_migrate();
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('Podaj nazwę kolejki.');
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($d['color'] ?? '')) ? (string)$d['color'] : '#6B7280';
    $mb = (int)($d['mailbox_id'] ?? 0) ?: null;
    $def = !empty($d['is_default']) ? 1 : 0;
    if ($id !== null && $mb === null) {   // kolejka ogólna zostaje domyślna — inaczej wiadomości bez kolejki domyślnej skrzynki nie miałyby dokąd trafić
        $cur = db_one("SELECT is_default, mailbox_id FROM crm_mail_queues WHERE id = ?", [$id]);
        if ($cur && (int)$cur['is_default'] === 1 && $cur['mailbox_id'] === null) $def = 1;
    }
    $row = ['name' => mb_substr($name, 0, 120), 'color' => $color, 'mailbox_id' => $mb, 'is_default' => $def, 'sort_order' => (int)($d['sort_order'] ?? 50), 'is_active' => isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1];
    if ($id === null) {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower($name), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'])), '-')) ?: 'kolejka';
        $base = $slug; $i = 2;
        while (db_one("SELECT 1 AS x FROM crm_mail_queues WHERE slug = ?", [$slug])) $slug = $base . '-' . $i++;
        $row += ['slug' => $slug, 'created_at' => date('Y-m-d H:i:s')];
        $id = db_insert('crm_mail_queues', $row);
    } else {
        unset($row['slug']);
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
        $st = db()->prepare("UPDATE crm_mail_queues SET $set WHERE id = :id");
        $st->execute($row + ['id' => $id]);
    }
    if ($def) {   // jedna domyślna na skrzynkę (mailbox_id NULL = ogólna)
        db()->prepare("UPDATE crm_mail_queues SET is_default = 0 WHERE id <> ? AND is_default = 1 AND " . ($mb ? "mailbox_id = ?" : "mailbox_id IS NULL"))->execute($mb ? [$id, $mb] : [$id]);
    }
    return $id;
}

/** Usuwa kolejkę; jej wiadomości wracają do kolejki domyślnej (queue_id = NULL). Kolejki ogólnej (jedynej domyślnej bez skrzynki) nie usuwamy. */
function crm_mq_delete(int $id): void
{
    crm_mq_migrate();
    $q = db_one("SELECT * FROM crm_mail_queues WHERE id = ?", [$id]);
    if (!$q) return;
    if ((int)$q['is_default'] === 1 && $q['mailbox_id'] === null) throw new \RuntimeException('Kolejki ogólnej (domyślnej) nie można usunąć — ustaw najpierw inną jako ogólną.');
    db()->prepare("UPDATE crm_communications SET queue_id = NULL WHERE queue_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM crm_mail_queues WHERE id = ?")->execute([$id]);
}
