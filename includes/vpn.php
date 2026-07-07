<?php
/**
 * includes/vpn.php — Moduł dostępu do VPN (sieci firmowej).
 *
 * Cykl życia: użytkownik składa wniosek → administrator zatwierdza (wpisuje
 * nazwę użytkownika VPN + konfigurację/instrukcję) albo odrzuca → dostęp można
 * później cofnąć. „Bieżący" stan użytkownika = najnowszy wiersz vpn_access.
 *
 * UWAGA: ten moduł zarządza PROVISIONINGIEM i EWIDENCJĄ dostępu do VPN. Sieciowa
 * blokada „dany moduł tylko przez VPN" to osobny mechanizm — require_module_vpn()
 * w functions.php (konwencja settings `X_vpn_only` + `X_vpn_allowlist`, np. EZD).
 *
 * Wymaga: db.php, functions.php, auth.php, mail_queue.php.
 */

const VPN_STATUSES = [
    'oczekuje'  => ['label' => 'Oczekuje na akceptację', 'class' => 'warning'],
    'aktywny'   => ['label' => 'Aktywny',                'class' => 'success'],
    'odrzucony' => ['label' => 'Odrzucony',              'class' => 'danger'],
    'cofniety'  => ['label' => 'Cofnięty',               'class' => 'secondary'],
];

function vpn_status_badge(string $status): string {
    $s = VPN_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function vpn_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS vpn_access (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        reason         TEXT    NOT NULL DEFAULT '',
        status         TEXT    NOT NULL DEFAULT 'oczekuje',
        vpn_username   TEXT    NOT NULL DEFAULT '',
        vpn_config     TEXT    NOT NULL DEFAULT '',
        instrukcja     TEXT    NOT NULL DEFAULT '',
        decision_note  TEXT    NOT NULL DEFAULT '',
        requested_at   TEXT    NOT NULL DEFAULT (datetime('now')),
        decided_by     INTEGER,
        decided_at     TEXT,
        revoked_by     INTEGER,
        revoked_at     TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_vpn_user ON vpn_access(user_id, id)");
}

/** Najnowszy (bieżący) wiersz dostępu użytkownika lub null. */
function vpn_current_for_user(int $user_id): ?array {
    vpn_init();
    return db_one(
        "SELECT * FROM vpn_access WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        [$user_id]
    );
}

/** Czy użytkownik może teraz złożyć wniosek (brak oczekującego/aktywnego). */
function vpn_can_request(int $user_id): bool {
    $c = vpn_current_for_user($user_id);
    return !$c || in_array($c['status'], ['odrzucony', 'cofniety'], true);
}

/** Składa wniosek o dostęp do VPN. Zwraca id lub rzuca wyjątek. */
function vpn_request(int $user_id, string $reason): int {
    vpn_init();
    if (!vpn_can_request($user_id)) {
        throw new \RuntimeException('Masz już oczekujący lub aktywny wniosek o dostęp do VPN.');
    }
    $id = db_insert('vpn_access', [
        'user_id' => $user_id,
        'reason'  => trim($reason),
        'status'  => 'oczekuje',
    ]);
    vpn_notify_admins($user_id, $reason);
    return $id;
}

function vpn_get(int $id): ?array {
    vpn_init();
    return db_one(
        "SELECT v.*, u.name AS user_name, u.email AS user_email,
                d.name AS decided_by_name
         FROM vpn_access v
         LEFT JOIN users u ON u.id = v.user_id
         LEFT JOIN users d ON d.id = v.decided_by
         WHERE v.id = ?",
        [$id]
    );
}

/** Lista wniosków/dostępów dla panelu admina (opcjonalny filtr statusu). */
function vpn_all(string $status = ''): array {
    vpn_init();
    $where = ''; $p = [];
    if ($status !== '' && isset(VPN_STATUSES[$status])) { $where = "WHERE v.status = ?"; $p[] = $status; }
    return db_all(
        "SELECT v.*, u.name AS user_name, u.email AS user_email
         FROM vpn_access v LEFT JOIN users u ON u.id = v.user_id
         {$where} ORDER BY CASE v.status WHEN 'oczekuje' THEN 0 ELSE 1 END, v.id DESC",
        $p
    );
}

function vpn_pending_count(): int {
    try {
        vpn_init();
        return (int)(db_one("SELECT COUNT(*) c FROM vpn_access WHERE status='oczekuje'")['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Zatwierdza wniosek: nadaje dane VPN i status 'aktywny'. */
function vpn_approve(int $id, string $username, string $config, string $instrukcja, int $by, string $note = ''): void {
    vpn_init();
    $r = vpn_get($id);
    if (!$r || $r['status'] !== 'oczekuje') throw new \RuntimeException('Wniosek nie oczekuje na decyzję.');
    db()->prepare(
        "UPDATE vpn_access SET status='aktywny', vpn_username=?, vpn_config=?, instrukcja=?,
             decision_note=?, decided_by=?, decided_at=datetime('now') WHERE id=?"
    )->execute([trim($username), $config, trim($instrukcja), trim($note), $by, $id]);
    vpn_notify_user($r, 'aktywny', $note);
}

/** Odrzuca wniosek. */
function vpn_reject(int $id, int $by, string $note): void {
    vpn_init();
    $r = vpn_get($id);
    if (!$r || $r['status'] !== 'oczekuje') throw new \RuntimeException('Wniosek nie oczekuje na decyzję.');
    if (trim($note) === '') throw new \RuntimeException('Podaj powód odrzucenia.');
    db()->prepare(
        "UPDATE vpn_access SET status='odrzucony', decision_note=?, decided_by=?, decided_at=datetime('now') WHERE id=?"
    )->execute([trim($note), $by, $id]);
    vpn_notify_user($r, 'odrzucony', $note);
}

/** Cofa aktywny dostęp. */
function vpn_revoke(int $id, int $by, string $note = ''): void {
    vpn_init();
    $r = vpn_get($id);
    if (!$r || $r['status'] !== 'aktywny') throw new \RuntimeException('Ten dostęp nie jest aktywny.');
    db()->prepare(
        "UPDATE vpn_access SET status='cofniety', decision_note=?, revoked_by=?, revoked_at=datetime('now') WHERE id=?"
    )->execute([trim($note), $by, $id]);
    vpn_notify_user($r, 'cofniety', $note);
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

function vpn_notify_admins(int $user_id, string $reason): void {
    if (!function_exists('mail_queue_add')) return;
    try {
        $admins = db_all("SELECT name, email FROM users WHERE role='admin' AND is_active=1 AND user_status='active' AND email<>''");
        $applicant = db_one("SELECT name, email FROM users WHERE id=?", [$user_id]);
    } catch (\Throwable $e) { return; }
    $url  = rtrim(APP_URL, '/') . '/admin/vpn.php';
    $who  = $applicant ? ($applicant['name'] ?: $applicant['email']) : ('#' . $user_id);
    $html = '<p>Nowy wniosek o dostęp do <strong>VPN</strong>.</p>'
          . '<p><strong>Wnioskodawca:</strong> ' . h($who) . '</p>'
          . ($reason ? '<p><strong>Uzasadnienie:</strong><br>' . nl2br(h($reason)) . '</p>' : '')
          . '<p><a href="' . h($url) . '">Rozpatrz wniosek</a></p>';
    foreach ($admins as $a) {
        try { mail_queue_add($a['email'], $a['name'] ?: $a['email'], 'Wniosek o dostęp do VPN: ' . $who, $html, '', 'vpn', $user_id); }
        catch (\Throwable $e) {}
    }
}

function vpn_notify_user(array $rec, string $status, string $note): void {
    if (!function_exists('mail_queue_add')) return;
    $email = (string)($rec['user_email'] ?? '');
    if ($email === '') return;
    $lbl  = VPN_STATUSES[$status]['label'] ?? $status;
    $url  = rtrim(APP_URL, '/') . '/vpn/index.php';
    $html = '<p>Status Twojego wniosku o dostęp do VPN: <strong>' . h($lbl) . '</strong>.</p>';
    if ($status === 'aktywny') {
        $html .= '<p>Dane dostępowe i instrukcja są dostępne w panelu.</p>';
    }
    if (trim($note) !== '') $html .= '<p><strong>Uwagi:</strong><br>' . nl2br(h($note)) . '</p>';
    $html .= '<p><a href="' . h($url) . '">Otwórz panel VPN</a></p>';
    try { mail_queue_add($email, (string)($rec['user_name'] ?? $email), 'VPN — ' . $lbl, $html, '', 'vpn', (int)$rec['id']); }
    catch (\Throwable $e) {}
}
