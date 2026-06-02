<?php
/** Migracja: tworzy tabelę contract_letters. Usuń po wykonaniu! */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $sql = "CREATE TABLE IF NOT EXISTS contract_letters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT NOT NULL,
        contract_id INTEGER NOT NULL,
        kierunek TEXT NOT NULL DEFAULT 'wychodzące',
        typ_pisma TEXT NOT NULL DEFAULT 'inne',
        tytul TEXT NOT NULL,
        tresc TEXT,
        plik TEXT,
        data_pisma TEXT,
        nadawca TEXT,
        odbiorca TEXT,
        odbiorca_email TEXT,
        uwagi TEXT,
        email_sent INTEGER DEFAULT 0,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
} else {
    $sql = "CREATE TABLE IF NOT EXISTS contract_letters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_type VARCHAR(32) NOT NULL,
        contract_id INT NOT NULL,
        kierunek VARCHAR(32) NOT NULL DEFAULT 'wychodzące',
        typ_pisma VARCHAR(32) NOT NULL DEFAULT 'inne',
        tytul VARCHAR(512) NOT NULL,
        tresc LONGTEXT,
        plik TEXT,
        data_pisma DATE,
        nadawca VARCHAR(255),
        odbiorca VARCHAR(255),
        odbiorca_email VARCHAR(255),
        uwagi TEXT,
        email_sent TINYINT DEFAULT 0,
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4";
}

$done = []; $err = [];
try {
    db()->exec($sql);
    $done[] = 'Tabela contract_letters — OK';
} catch (PDOException $e) {
    $err[] = $e->getMessage();
}
?>
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migracja — pisma</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow-sm">
  <div class="card-header fw-bold">Migracja — moduł pism do umów</div>
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
    <p class="text-danger small">Usuń <code>migrate_letters.php</code> po zakończeniu migracji.</p>
  </div>
</div>
</div></body></html>
