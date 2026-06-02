<?php
/**
 * Jednorazowy skrypt naprawczy — tworzy tabele w istniejącej bazie.
 * USUŃ ten plik po wykonaniu!
 */
if (!file_exists(__DIR__ . '/config.php')) {
    die('Brak config.php — najpierw uruchom install.php');
}

require_once __DIR__ . '/config.php';

try {
    if (DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->exec('PRAGMA journal_mode=WAL;');
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    // Usuń komentarze liniowe
    $schema = preg_replace('/--[^\n]*/', '', $schema);
    // Podziel na pojedyncze zapytania
    $statements = array_filter(array_map('trim', explode(';', $schema)));

    $ok = 0; $errors = [];
    foreach ($statements as $sql) {
        if (!$sql) continue;
        try {
            $pdo->exec($sql);
            $ok++;
        } catch (PDOException $e) {
            $errors[] = $e->getMessage() . ' — SQL: ' . substr($sql, 0, 80);
        }
    }

    // Sprawdź tabele
    if (DB_TYPE === 'sqlite') {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    die('<pre style="color:red">Błąd połączenia: ' . $e->getMessage() . '</pre>');
}
?>
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Setup DB</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:700px">
  <div class="card shadow-sm">
    <div class="card-header fw-bold">Inicjalizacja bazy danych</div>
    <div class="card-body">
      <p>Wykonano zapytań: <strong><?= $ok ?></strong></p>

      <?php if ($errors): ?>
      <div class="alert alert-warning">
        <b>Ostrzeżenia:</b>
        <ul class="mb-0 small">
          <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <p>Tabele w bazie:</p>
      <ul>
        <?php foreach ($tables as $t): ?>
        <li><code><?= htmlspecialchars($t) ?></code></li>
        <?php endforeach; ?>
      </ul>

      <?php
      $required = ['users','umowy_zlecenie','umowy_uslugi','umowy_wolontariat','umowy_dzielo','umowy_praca','umowy_inne','settings'];
      $missing = array_diff($required, $tables);
      ?>
      <?php if ($missing): ?>
      <div class="alert alert-danger">
        Brakuje tabel: <strong><?= implode(', ', $missing) ?></strong>
      </div>
      <?php else: ?>
      <div class="alert alert-success">
        <b>Wszystkie tabele utworzone pomyślnie!</b>
      </div>

      <?php
      // Sprawdź czy jest admin
      $adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
      if ($adminCount == 0):
      ?>
      <hr>
      <h6>Brak konta administratora — utwórz je poniżej:</h6>
      <?php
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email'])) {
          $name  = trim($_POST['name']);
          $email = trim($_POST['email']);
          $pass  = $_POST['password'];
          if ($name && $email && strlen($pass) >= 8) {
              $hash = password_hash($pass, PASSWORD_BCRYPT);
              $pdo->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, 'admin', 1)")
                  ->execute([$name, $email, $hash]);

              // Dodaj org_name jeśli nie ma
              $orgExists = $pdo->query("SELECT COUNT(*) FROM settings WHERE key_='org_name'")->fetchColumn();
              if (!$orgExists) {
                  $pdo->prepare("INSERT INTO settings (key_, value) VALUES ('org_name', ?)")->execute([ORG_NAME]);
              }
              echo '<div class="alert alert-success">Administrator <b>' . htmlspecialchars($name) . '</b> utworzony. <a href="index.php">Przejdź do rejestru →</a></div>';
          } else {
              echo '<div class="alert alert-danger">Wypełnij wszystkie pola. Hasło min. 8 znaków.</div>';
          }
      }
      ?>
      <form method="post">
        <div class="mb-2"><label class="form-label">Imię i nazwisko</label>
          <input name="name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">E-mail</label>
          <input name="email" type="email" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Hasło (min. 8 znaków)</label>
          <input name="password" type="password" class="form-control" required></div>
        <button type="submit" class="btn btn-success">Utwórz administratora</button>
      </form>
      <?php else: ?>
      <p>Konto administratora istnieje. <a href="index.php" class="btn btn-primary btn-sm">Przejdź do rejestru →</a></p>
      <?php endif; ?>
      <?php endif; ?>

      <hr>
      <p class="text-danger small"><b>Ważne:</b> Usuń plik <code>setup_db.php</code> po zakończeniu!</p>
    </div>
  </div>
</div>
</body>
</html>
