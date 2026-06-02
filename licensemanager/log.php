<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
lm_require_login();

$log = lm_all("SELECT ll.*, l.install_url, l.org_name FROM license_log ll JOIN licenses l ON l.id=ll.license_id ORDER BY ll.created_at DESC LIMIT 100");

lm_head('Log zdarzeń', 'log.php');
?>

<h2 style="font-size:1.3rem;font-weight:700;margin-bottom:1.25rem;color:#f8fafc">📋 Log zdarzeń</h2>

<div class="lm-card">
  <table class="lm-table">
    <thead>
      <tr><th>Data</th><th>Instalacja</th><th>Zdarzenie</th><th>Szczegóły</th><th>IP</th></tr>
    </thead>
    <tbody>
      <?php if (!$log): ?>
      <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem">Brak zdarzeń.</td></tr>
      <?php endif; ?>
      <?php foreach ($log as $l): ?>
      <tr>
        <td style="font-size:.78rem;color:#64748b;white-space:nowrap"><?= lm_h(date('d.m.Y H:i:s', strtotime($l['created_at']))) ?></td>
        <td>
          <a href="licenses.php?id=<?= $l['license_id'] ?>" style="color:#38bdf8;text-decoration:none;font-size:.8rem">
            <?= lm_h($l['org_name'] ?: $l['install_url']) ?>
          </a>
        </td>
        <td><code style="font-size:.8rem"><?= lm_h($l['event']) ?></code></td>
        <td style="font-size:.78rem;color:#94a3b8"><?= lm_h($l['detail'] ?? '') ?></td>
        <td style="font-size:.75rem;color:#64748b"><?= lm_h($l['ip'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php lm_foot(); ?>
