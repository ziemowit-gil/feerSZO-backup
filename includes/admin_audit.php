<?php
/**
 * Admin Audit Log
 * Provides table migration and logging function for admin actions.
 */

function admin_audit_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            user_name TEXT,
            user_email TEXT,
            action TEXT NOT NULL,
            module TEXT,
            target_id INTEGER,
            target_label TEXT,
            details TEXT,
            ip TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");
    } catch (\Throwable $e) {
        // silently fail
    }
}

function admin_audit(
    string $action,
    string $module = '',
    string $details = '',
    int $target_id = 0,
    string $target_label = ''
): void {
    try {
        $user = current_user();
        $user_id    = $user ? (int)($user['id'] ?? 0) : 0;
        $user_name  = $user ? ($user['name']  ?? '') : '';
        $user_email = $user ? ($user['email'] ?? '') : '';

        $ip         = $_SERVER['REMOTE_ADDR']     ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO admin_audit_log
                (user_id, user_name, user_email, action, module, target_id, target_label, details, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id,
            $user_name,
            $user_email,
            $action,
            $module,
            $target_id ?: null,
            $target_label ?: null,
            $details ?: null,
            $ip,
            $user_agent,
        ]);
    } catch (\Throwable $e) {
        // silently fail
    }
}
