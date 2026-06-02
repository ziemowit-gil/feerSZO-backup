<?php
/**
 * licensemanager/index.php — Login + Dashboard.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

// Wylogowanie
if (isset($_GET['logout'])) { lm_logout(); header('Location: index.php'); exit; }

// Login POST
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (lm_login($_POST['password'])) {
        header('Location: index.php');
        exit;
    }
    $login_error = 'Nieprawidłowe hasło.';
}

// Pokaż login jeśli niezalogowany
if (!lm_is_logged()) {
    ?><!DOCTYPE html><html lang="pl"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login — License Manager</title>
    <link rel="stylesheet" href="assets/style.css">
    </head><body>
    <div class="lm-login-wrap">
      <div class="lm-login-card">
        <div style="font-size:2.5rem;text-align:center;margin-bottom:1rem">🛡</div>
        <div class="lm-login-title" style="text-align:center">License Manager</div>
        <div class="lm-login-sub" style="text-align:center">Platforma NGO — zarządzanie licencjami</div>
        <?php if ($login_error): ?>
        <div class="lm-alert lm-alert-danger"><?= lm_h($login_error) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="lm-form-group">
            <label class="lm-label">Hasło dostępu</label>
            <input type="password" name="password" class="lm-input" autofocus autocomplete="current-password" placeholder="••••••••">
          </div>
          <button type="submit" class="btn-lm btn-primary" style="width:100%;justify-content:center">
            🔓 Zaloguj się
          </button>
        </form>
      </div>
    </div>
    </body></html>
    <?php exit;
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
lm_head('Dashboard', 'index.php');

$total    = (int)(lm_one("SELECT COUNT(*) AS c FROM licenses")['c'] ?? 0);
$active   = (int)(lm_one("SELECT COUNT(*) AS c FROM licenses WHERE status='active'")['c'] ?? 0);
$trial    = (int)(lm_one("SELECT COUNT(*) AS c FROM licenses WHERE status='trial'")['c'] ?? 0);
$expiring = (int)(lm_one("SELECT COUNT(*) AS c FROM licenses WHERE status='active' AND expires_at <= date('now','+14 days')")['c'] ?? 0);
$expired  = (int)(lm_one("SELECT COUNT(*) AS c FROM licenses WHERE expires_at < date('now') AND status != 'revoked'")['c'] ?? 0);

$recent = lm_all("SELECT * FROM licenses ORDER BY created_at DESC LIMIT 10");
?>

<h2 style="font-size:1.4rem;font-weight:700;margin-bottom:1.25rem;color:#f8fafc">⚡ Dashboard</h2>

<?= lm_flash_html() ?>

<!-- Statystyki -->
<div class="lm-stats">
  <div class="lm-stat">
    <div class="lm-stat-value"><?= $total ?></div>
    <div class="lm-stat-label">Wszystkich licencji</div>
  </div>
  <div class="lm-stat">
    <div class="lm-stat-value" style="color:#86efac"><?= $active ?></div>
    <div class="lm-stat-label">Aktywnych</div>
  </div>
  <div class="lm-stat">
    <div class="lm-stat-value" style="color:#7dd3fc"><?= $trial ?></div>
    <div class="lm-stat-label">Trial</div>
  </div>
  <div class="lm-stat">
    <div class="lm-stat-value" style="color:#fdba74"><?= $expiring ?></div>
    <div class="lm-stat-label">Wygasa w ≤14 dni</div>
  </div>
  <div class="lm-stat">
    <div class="lm-stat-value" style="color:#fca5a5"><?= $expired ?></div>
    <div class="lm-stat-label">Wygasłych</div>
  </div>
</div>

<?php if ($expiring > 0): ?>
<div class="lm-alert lm-alert-info">
  ⚠️ <?= $expiring ?> licencji wygasa w ciągu 14 dni — sprawdź listę i odnów.
</div>
<?php endif; ?>

<!-- Ostatnio dodane -->
<div class="lm-card">
  <div class="lm-card-header">
    🕐 Ostatnie licencje
    <a href="licenses.php" class="btn-lm btn-ghost btn-sm">Wszystkie →</a>
  </div>
  <table class="lm-table">
    <thead>
      <tr>
        <th>URL instalacji</th>
        <th>Organizacja</th>
        <th>Status</th>
        <th>Wygasa</th>
        <th>Ostatni ping</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$recent): ?>
      <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem">Brak licencji. <a href="new.php" style="color:#38bdf8">Dodaj pierwszą</a>.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $lic):
          $days = lm_days_left($lic['expires_at']);
          $bar_color = $days <= 0 ? '#ef4444' : ($days <= 14 ? '#f59e0b' : '#22c55e');
          $bar_pct   = max(0, min(100, round($days / 365 * 100)));
      ?>
      <tr>
        <td>
          <a href="<?= lm_h($lic['install_url']) ?>" target="_blank" style="color:#38bdf8;text-decoration:none;font-size:.82rem">
            <?= lm_h($lic['install_url']) ?>
          </a>
        </td>
        <td><?= lm_h($lic['org_name'] ?: '—') ?></td>
        <td><?= lm_status_badge($lic['status']) ?></td>
        <td>
          <div style="font-size:.78rem"><?= lm_h(date('d.m.Y', strtotime($lic['expires_at']))) ?></div>
          <div class="lm-progress" style="margin-top:.3rem;width:80px">
            <div class="lm-progress-bar" style="width:<?= $bar_pct ?>%;background:<?= $bar_color ?>"></div>
          </div>
        </td>
        <td style="font-size:.75rem;color:#64748b"><?= $lic['last_ping_at'] ? lm_h(date('d.m.Y H:i', strtotime($lic['last_ping_at']))) : '—' ?></td>
        <td>
          <a href="licenses.php?id=<?= $lic['id'] ?>" class="btn-lm btn-ghost btn-sm">Edytuj</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php lm_foot(); ?>
