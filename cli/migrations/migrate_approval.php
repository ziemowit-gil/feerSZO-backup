<?php
/** Migracja: tabele akceptacji i audit logu. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$sqls = [
"CREATE TABLE IF NOT EXISTS contract_approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50) NOT NULL,
    contract_id   INTEGER NOT NULL,
    requested_by  INTEGER NOT NULL,
    requested_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    token         VARCHAR(64) UNIQUE NOT NULL,
    token_expires DATETIME NOT NULL,
    status        VARCHAR(20) DEFAULT 'oczekuje',
    decided_by    INTEGER,
    decided_at    DATETIME,
    decision_note TEXT,
    email_sent    INTEGER DEFAULT 0,
    via_email     INTEGER DEFAULT 0
)",
"CREATE TABLE IF NOT EXISTS contract_audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50),
    contract_id   INTEGER,
    user_id       INTEGER,
    user_snapshot VARCHAR(255),
    action        VARCHAR(50) NOT NULL,
    note          TEXT,
    ip_address    VARCHAR(45),
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)",
"CREATE INDEX IF NOT EXISTS idx_approvals_contract ON contract_approvals(contract_type, contract_id)",
"CREATE INDEX IF NOT EXISTS idx_approvals_token    ON contract_approvals(token)",
"CREATE INDEX IF NOT EXISTS idx_audit_contract     ON contract_audit_log(contract_type, contract_id)",
];

$done = []; $err = [];
foreach ($sqls as $sql) {
    try { db()->exec($sql); $done[] = substr(trim($sql), 0, 60) . '...'; }
    catch (PDOException $e) { $err[] = $e->getMessage(); }
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow-sm"><div class="card-header fw-bold">Migracja — Akceptacja umów</div>
<div class="card-body">
<?php if ($done): ?>
<div class="alert alert-success small"><b>Wykonano:</b><ul class="mb-0">
<?php foreach($done as $d) echo "<li><code>".htmlspecialchars($d)."</code></li>"; ?>
</ul></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert alert-danger small"><?= implode('<br>', array_map('htmlspecialchars', $err)) ?></div>
<?php endif; ?>
<p>Gotowe. <a href="index.php">Przejdź do rejestru →</a></p>
<p class="text-danger small">Usuń <code>migrate_approval.php</code>!</p>
</div></div></div></body></html>
