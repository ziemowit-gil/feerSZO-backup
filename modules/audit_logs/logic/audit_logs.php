<?php
/**
 * modules/audit_logs/logic/audit_logs.php — wspólny dziennik audytu operacji krytycznych.
 *
 * Tabela audit_logs (id, user_id, action, details, ip_address, created_at) —
 * jedno miejsce, do którego moduły zapisują zdarzenia wymagające rozliczalności
 * (wydanie hologramu, blokada karty, zatwierdzenie wniosku…). Uzupełnia, a nie
 * zastępuje, historie per moduł (np. holograms_log) i admin_audit_log.
 *
 * Konwencja nazw akcji: „moduł.zdarzenie”, np. holograms.issued,
 * smart_cards.card_blocked. details to JSON (obiekt) — dowolne pola kontekstu.
 *
 * audit_log() używa tego samego połączenia PDO co moduły, więc wywołane wewnątrz
 * otwartej transakcji staje się jej częścią: operacja i jej ślad audytowy
 * zapisują się razem albo wcale. Błąd zapisu audytu jest rzucany dalej —
 * operacja krytyczna bez śladu nie powinna przejść.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

/** Prefiksy akcji znane przeglądarce dziennika (filtr „Moduł”). */
const AUDIT_MODULES = [
    'holograms'   => 'Hologramy',
    'smart_cards' => 'Karty dostępu',
];

function audit_logs_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER,
        action      TEXT NOT NULL,
        details     TEXT,
        ip_address  TEXT,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_action  ON audit_logs(action)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_user    ON audit_logs(user_id)");
}

/** Adres IP żądania (REMOTE_ADDR — nagłówki proxy są łatwe do podrobienia). CLI → 'cli'. */
function audit_client_ip(): string {
    if (PHP_SAPI === 'cli') return 'cli';
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Zapisuje zdarzenie. $userId null → bieżący użytkownik (current_user()), jeśli jest.
 * $details — tablica (zapisywana jako JSON) albo gotowy tekst.
 */
function audit_log(string $action, array|string $details = [], ?int $userId = null): void {
    audit_logs_migrate();
    if ($userId === null && function_exists('current_user')) {
        $u = current_user();
        $userId = $u ? (int)($u['id'] ?? 0) : null;
    }
    if (is_array($details)) {
        $details = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    }
    db()->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$userId ?: null, mb_substr($action, 0, 100), $details, audit_client_ip(), date('Y-m-d H:i:s')]);
}

/**
 * Wyszukiwanie w dzienniku. Filtry: module (prefiks akcji), action, user_id, q (w details),
 * from/to (daty Y-m-d). @return array{rows:array, total:int}
 */
function audit_logs_search(array $f, int $limit, int $offset): array {
    audit_logs_migrate();
    $where = ['1=1'];
    $p = [];
    if (!empty($f['module']) && isset(AUDIT_MODULES[$f['module']])) {
        $where[] = "a.action LIKE ? ESCAPE '\\'";
        $p[] = addcslashes($f['module'], '%_\\') . '.%';
    }
    if (!empty($f['action'])) { $where[] = 'a.action = ?'; $p[] = $f['action']; }
    if (!empty($f['user_id'])) { $where[] = 'a.user_id = ?'; $p[] = (int)$f['user_id']; }
    if (($f['q'] ?? '') !== '') {
        $where[] = "(a.details LIKE ? ESCAPE '\\' OR a.ip_address LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $p[] = $like; $p[] = $like;
    }
    if (!empty($f['from'])) { $where[] = 'a.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))   { $where[] = 'a.created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
    $w = implode(' AND ', $where);

    $pdo = db();
    $c = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$w}");
    $c->execute($p);
    $total = (int)$c->fetchColumn();

    $s = $pdo->prepare("SELECT a.*, u.name AS user_name, u.email AS user_email
                        FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                        WHERE {$w} ORDER BY a.id DESC LIMIT ? OFFSET ?");
    foreach ($p as $i => $v) $s->bindValue($i + 1, $v);
    $s->bindValue(count($p) + 1, $limit, PDO::PARAM_INT);
    $s->bindValue(count($p) + 2, $offset, PDO::PARAM_INT);
    $s->execute();
    return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
}

/** Lista akcji występujących w dzienniku (do filtra). */
function audit_logs_actions(): array {
    audit_logs_migrate();
    return db()->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
}
