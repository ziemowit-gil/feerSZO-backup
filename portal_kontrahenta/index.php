<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_portal_auth.php';

auth_start();
edok_portal_migrate();
edok_portal_require_login();

$acc  = edok_portal_current();
$docs = edok_portal_documents($acc['nip']);

$PAGE_TITLE = 'Moje dokumenty';
$BREADCRUMB = 'Strona główna / Moje dokumenty';
require __DIR__ . '/_layout_head.php';
?>
<div class="pk-card wide">
  <h1>Witaj, <?= h($acc['nazwa'] ?: $acc['email']) ?></h1>
  <p style="color:var(--pk-muted);font-size:.9rem">
    NIP: <strong><?= h($acc['nip']) ?></strong> &nbsp;·&nbsp; <a href="<?= APP_URL ?>/portal_kontrahenta/logout.php">Wyloguj się</a>
  </p>

  <?php if (!$docs): ?>
  <p style="color:var(--pk-muted)">Brak dokumentów powiązanych z Twoim NIP-em.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="pk-table">
      <thead>
        <tr><th>Numer</th><th>Dokument</th><th>Data</th><th>Kwota</th><th>Termin płatności</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
        <tr>
          <td><code><?= h($d['number']) ?></code></td>
          <td><?= h($d['title'] ?: '—') ?></td>
          <td><?= h($d['data'] ? substr($d['data'], 0, 10) : '—') ?></td>
          <td><?= h($d['kwota'] ?: '—') ?> <?= h($d['waluta']) ?></td>
          <td><?= h($d['termin'] ? substr($d['termin'], 0, 10) : '—') ?></td>
          <td><span class="pk-badge"><?= h($d['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
