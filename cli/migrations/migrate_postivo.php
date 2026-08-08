<?php
/**
 * Migration: Postivo.pl postal letter delivery integration
 *
 * Dodaje kolumny do tabeli contract_letters i ustawienia domyślne.
 * Bezpieczne do wielokrotnego uruchamiania (idempotentne).
 */
if (!defined('APP_INSTALLED')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/includes/db.php';

$done = [];
$skip = [];

$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

// ── Kolumny w tabeli contract_letters ─────────────────────────────────────────

$new_columns = [
    'postivo_job_id'        => 'TEXT',
    'postivo_status'        => 'TEXT',
    'postivo_sent_at'       => 'TEXT',
    'postivo_adres'         => 'TEXT',
    'postivo_kod_pocztowy'  => 'TEXT',
    'postivo_miasto'        => 'TEXT',
];

foreach ($new_columns as $col => $type) {
    try {
        if ($driver === 'sqlite') {
            // SQLite: sprawdź czy kolumna istnieje przez PRAGMA
            $cols = db()->query("PRAGMA table_info(contract_letters)")->fetchAll(PDO::FETCH_ASSOC);
            $exists_col = false;
            foreach ($cols as $c) {
                if ($c['name'] === $col) { $exists_col = true; break; }
            }
            if ($exists_col) {
                $skip[] = "Kolumna contract_letters.{$col}";
                continue;
            }
            db()->exec("ALTER TABLE contract_letters ADD COLUMN {$col} {$type}");
        } else {
            // MySQL: próba dodania — jeśli już istnieje, rzuci wyjątek
            db()->exec("ALTER TABLE contract_letters ADD COLUMN {$col} {$type}");
        }
        $done[] = "Kolumna contract_letters.{$col} — dodana";
    } catch (PDOException $e) {
        // MySQL zwraca błąd gdy kolumna już istnieje (kod 1060)
        if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), '1060')) {
            $skip[] = "Kolumna contract_letters.{$col}";
        } else {
            $done[] = "BŁĄD dla {$col}: " . $e->getMessage();
        }
    }
}

// ── Ustawienia domyślne ───────────────────────────────────────────────────────

$defaults = [
    'postivo_enabled'              => '0',
    'postivo_api_key'              => '',
    'postivo_config_id'            => '',   // legacy — zastąpiony przez inline_config
    'postivo_carrier_id'           => '',
    'postivo_service_id'           => '',
    'postivo_paper_id'             => '',
    'postivo_envelope_id'          => '',
    'postivo_color_print'          => '0',
    'postivo_duplex_print'         => '0',
    'postivo_envelope_color_print' => '0',
    'postivo_return_address'       => '',
    'postivo_sender_name'          => '',
];

foreach ($defaults as $key => $val) {
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
    if ($exists) {
        $skip[] = "Ustawienie '$key'";
        continue;
    }
    db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $val]);
    $done[] = "Ustawienie '$key' — dodane";
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Migracja — Postivo.pl</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:640px">
  <div class="card shadow-sm">
    <div class="card-header fw-bold">
      <i class="bi bi-mailbox"></i> Migracja — integracja Postivo.pl
    </div>
    <div class="card-body">

      <?php if ($done): ?>
      <div class="alert alert-success small">
        <b>Wykonano:</b>
        <ul class="mb-0">
          <?php foreach ($done as $d): ?>
          <li><?= htmlspecialchars($d) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($skip): ?>
      <div class="alert alert-light border small">
        <b>Pominięto (już istnieją):</b>
        <ul class="mb-0">
          <?php foreach ($skip as $s): ?>
          <li><?= htmlspecialchars($s) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <p class="mb-1">
        Migracja zakończona.
        <a href="index.php">Przejdź do rejestru &rarr;</a>
      </p>
      <p class="mb-0">
        Skonfiguruj integrację w
        <a href="admin/postivo_settings.php">Administracja &rarr; Postivo (poczta)</a>.
      </p>
    </div>
  </div>
</div>
</body>
</html>
