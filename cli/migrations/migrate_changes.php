<?php
/** Migracja: aneksy, wnioski o edycję. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$sqls = [
"CREATE TABLE IF NOT EXISTS contract_amendments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50)  NOT NULL,
    contract_id   INTEGER      NOT NULL,
    numer_aneksu  INTEGER      NOT NULL DEFAULT 1,
    requested_by  INTEGER,
    requested_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    opis_zmian    TEXT         NOT NULL,
    plik_aneksu   VARCHAR(500),
    status        VARCHAR(20)  DEFAULT 'oczekuje',
    token         VARCHAR(64)  UNIQUE,
    token_expires DATETIME,
    decided_by    INTEGER,
    decided_at    DATETIME,
    decision_note TEXT,
    via_email     INTEGER      DEFAULT 0,
    email_sent    INTEGER      DEFAULT 0
)",
"CREATE TABLE IF NOT EXISTS contract_edit_requests (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type VARCHAR(50)  NOT NULL,
    contract_id   INTEGER      NOT NULL,
    requested_by  INTEGER,
    requested_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    opis_zmian    TEXT         NOT NULL,
    status        VARCHAR(20)  DEFAULT 'oczekuje',
    token         VARCHAR(64)  UNIQUE,
    token_expires DATETIME,
    decided_by    INTEGER,
    decided_at    DATETIME,
    decision_note TEXT,
    via_email     INTEGER      DEFAULT 0,
    email_sent    INTEGER      DEFAULT 0
)",
"CREATE INDEX IF NOT EXISTS idx_amendments_contract ON contract_amendments(contract_type, contract_id)",
"CREATE INDEX IF NOT EXISTS idx_amendments_token    ON contract_amendments(token)",
"CREATE INDEX IF NOT EXISTS idx_editreq_contract    ON contract_edit_requests(contract_type, contract_id)",
"CREATE INDEX IF NOT EXISTS idx_editreq_token       ON contract_edit_requests(token)",
];

$done = []; $err = [];
foreach ($sqls as $sql) {
    try { db()->exec($sql); $done[] = substr(trim($sql), 0, 70) . '...'; }
    catch (PDOException $e) { $err[] = $e->getMessage(); }
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow-sm"><div class="card-header fw-bold">Migracja — Zmiany w umowach</div>
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
<p class="text-danger small">Usuń <code>migrate_changes.php</code>!</p>
</div></div></div></body></html>
