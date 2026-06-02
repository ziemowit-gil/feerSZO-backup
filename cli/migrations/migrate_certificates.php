<?php
/** Migracja: tworzy tabelę certificate_requests. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $sql = "CREATE TABLE IF NOT EXISTS certificate_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL,
        requested_by INTEGER,
        requester_name TEXT NOT NULL,
        requester_email TEXT NOT NULL,
        cel TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'oczekuje',
        issued_by INTEGER,
        issued_at DATETIME,
        rejection_note TEXT,
        certificate_content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
} else {
    $sql = "CREATE TABLE IF NOT EXISTS certificate_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_type VARCHAR(32) NOT NULL,
        contract_id INT NOT NULL,
        requested_by INT,
        requester_name VARCHAR(255) NOT NULL,
        requester_email VARCHAR(255) NOT NULL,
        cel TEXT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'oczekuje',
        issued_by INT,
        issued_at DATETIME,
        rejection_note TEXT,
        certificate_content LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4";
}

$done = []; $err = [];
try {
    db()->exec($sql);
    $done[] = 'Tabela certificate_requests — OK';
} catch (PDOException $e) {
    $err[] = $e->getMessage();
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja — zaświadczenia</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow-sm">
  <div class="card-header fw-bold"><i class="bi bi-table"></i> Migracja — moduł zaświadczeń</div>
  <div class="card-body">
    <?php if ($done): ?>
    <div class="alert alert-success small"><b>Wykonano:</b><ul class="mb-0">
    <?php foreach($done as $d) echo '<li><code>'.htmlspecialchars($d).'</code></li>'; ?>
    </ul></div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger small"><?= implode('<br>', array_map('htmlspecialchars', $err)) ?></div>
    <?php endif; ?>
    <p>Gotowe. <a href="index.php">Przejdź do rejestru →</a></p>
    <p class="text-danger small">Usuń <code>migrate_certificates.php</code> po zakończeniu migracji.</p>
  </div>
</div>
</div></body></html>
