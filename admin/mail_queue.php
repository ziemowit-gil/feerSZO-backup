<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_role('admin');

$PAGE_TITLE = 'Kolejka E-mail';

$status_f = $_GET['status'] ?? 'pending';
$stats    = mail_queue_stats();

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $mid    = (int)($_POST['id'] ?? 0);

    if ($action === 'test_send') {
        // Test diagnostyczny z pełnym logowaniem
        $to  = trim($_POST['test_email'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Podaj prawidłowy adres e-mail do testu.');
        } else {
            $org = defined('ORG_NAME') ? ORG_NAME : 'System';
            $mid_test = mail_queue_add(
                $to, $to,
                "Test wysyłki — {$org}",
                "<html><body><h2>Test e-mail</h2><p>Wysłano: " . date('d.m.Y H:i:s') . "</p>"
                . "<p>Nadawca: " . htmlspecialchars(_mail_from()) . "</p>"
                . "<p>Metoda: " . (_mail_m365_configured() ? 'Microsoft 365 Graph API' : ((_mail_setting('smtp_host') ? 'SMTP: '._mail_setting('smtp_host') : 'PHP mail()'))) . "</p>"
                . "</body></html>"
            );
            // Wymuś natychmiastowe wysłanie z verbose błędem (próbuje M365→SMTP→SMTP2→mail)
            $msg = db_one("SELECT * FROM mail_queue WHERE id=?", [$mid_test]);
            $method = 'PHP mail()';
            $ok = false; $err = '';
            try {
                if (_mail_m365_configured()) {
                    $method = 'Microsoft 365 Graph API';
                    $ok = _mail_send_m365($msg);
                } elseif (_mail_setting('smtp_host')) {
                    $method = 'SMTP: ' . _mail_setting('smtp_host');
                    $ok = _mail_send_smtp($msg, _mail_setting('smtp_host'), 'smtp');
                } elseif (_mail_setting('smtp2_host')) {
                    $method = 'SMTP2 (backup): ' . _mail_setting('smtp2_host');
                    $ok = _mail_send_smtp($msg, _mail_setting('smtp2_host'), 'smtp2');
                } else {
                    $method = 'PHP mail() — fallback';
                    $ok = _mail_send_native($msg);
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
            if ($ok) {
                db()->prepare("UPDATE mail_queue SET status='sent',sent_at=datetime('now') WHERE id=?")->execute([$mid_test]);
                flash_set('success', "✓ Test wysłany do {$to} przez <strong>{$method}</strong>. Sprawdź skrzynkę (też spam).");
            } else {
                db()->prepare("UPDATE mail_queue SET status='failed',last_error=? WHERE id=?")->execute([$err, $mid_test]);
                flash_set('danger', "✗ Błąd wysyłki przez <strong>{$method}</strong>: " . htmlspecialchars($err ?: 'nieznany błąd'));
            }
        }
        header('Location: mail_queue.php?status='.$status_f); exit;

    } elseif ($action === 'process') {
        $res = mail_queue_process((int)($_POST['batch'] ?? 20));
        flash_set('success', "Wysłano: {$res['sent']}, błędy: {$res['failed']}.");
    } elseif ($action === 'retry' && $mid) {
        mail_queue_retry($mid);
        flash_set('success', 'E-mail ponownie zakolejkowany.');
    } elseif ($action === 'cancel' && $mid) {
        mail_queue_cancel($mid);
        flash_set('success', 'E-mail anulowany.');
    } elseif ($action === 'purge') {
        db()->exec("DELETE FROM mail_queue WHERE status IN ('sent','cancelled') AND sent_at < datetime('now','-30 days')");
        flash_set('success', 'Wyczyszczono stare rekordy (>30 dni).');
    }
    header('Location: mail_queue.php?status='.$status_f); exit;
}

$rows = mail_queue_all($status_f === 'all' ? '' : $status_f);

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.stat-chip{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.7rem 1rem;text-align:center}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-send-check text-primary me-2"></i>Kolejka E-mail</h4>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="process">
      <input type="number" name="batch" value="20" min="1" max="100" class="form-control form-control-sm" style="width:70px" title="Batch size">
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-play-fill me-1"></i>Wyślij teraz</button>
    </form>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="purge">
      <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Wyczyścić stare wysłane/anulowane rekordy?')"><i class="bi bi-trash me-1"></i>Wyczyść</button>
    </form>
  </div>
</div>

<?= flash_html() ?>

<!-- Diagnostyka metody wysyłki -->
<?php
$mail_method = 'PHP mail() — brak konfiguracji SMTP/M365';
$mail_status_class = 'warning';
if (_mail_m365_configured()) {
    $mail_method = 'Microsoft 365 Graph API';
    if (_mail_setting('smtp_host'))  $mail_method .= ' → SMTP1: '  . _mail_setting('smtp_host')  . ':' . (_mail_setting('smtp_port')  ?: 587) . ' (backup)';
    if (_mail_setting('smtp2_host')) $mail_method .= ' → SMTP2: '  . _mail_setting('smtp2_host') . ':' . (_mail_setting('smtp2_port') ?: 587) . ' (backup)';
    $mail_status_class = 'success';
} elseif (_mail_setting('smtp_host')) {
    $mail_method = 'SMTP: ' . _mail_setting('smtp_host') . ':' . (_mail_setting('smtp_port') ?: 587);
    if (_mail_setting('smtp2_host')) $mail_method .= ' → SMTP2: '  . _mail_setting('smtp2_host') . ':' . (_mail_setting('smtp2_port') ?: 587) . ' (backup)';
    $mail_status_class = 'info';
} elseif (_mail_setting('smtp2_host')) {
    $mail_method = 'SMTP2 (backup only): ' . _mail_setting('smtp2_host') . ':' . (_mail_setting('smtp2_port') ?: 587);
    $mail_status_class = 'secondary';
}
?>
<div class="alert alert-<?= $mail_status_class ?> d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 mb-3">
  <div class="d-flex align-items-center gap-2">
    <i class="bi bi-<?= $mail_status_class === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> flex-shrink-0"></i>
    <span class="small"><strong>Aktywna metoda wysyłki:</strong> <?= h($mail_method) ?></span>
  </div>
  <form method="post" class="d-flex gap-2 align-items-center flex-shrink-0">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="test_send">
    <input type="email" name="test_email" class="form-control form-control-sm"
           placeholder="twoj@gmail.com" required style="width:200px"
           value="<?= h(current_user()['email'] ?? '') ?>">
    <button type="submit" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-send me-1"></i>Wyślij test
    </button>
  </form>
</div>

<!-- Stats -->
<div class="row g-2 mb-3">
  <?php foreach([
    ['pending','Oczekuje','warning',$stats['pending']],
    ['sent','Wysłane','success',$stats['sent']],
    ['failed','Błędy','danger',$stats['failed']],
    ['cancelled','Anulowane','secondary',$stats['cancelled']],
    ['all','Łącznie','dark',$stats['total']],
  ] as [$sv,$sl,$sc,$sn]): ?>
  <div class="col">
    <a href="?status=<?= $sv ?>" class="stat-chip d-block text-decoration-none text-dark <?= $status_f===$sv?'border-primary':'' ?>">
      <div class="fw-bold fs-5 text-<?= $sc ?>"><?= $sn ?></div>
      <div style="font-size:.7rem;color:#64748b"><?= h($sl) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabela -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-envelope-arrow-up me-1 text-primary"></i>E-maile (<?= count($rows) ?>)</div>
  <?php if($rows): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
    <thead class="table-light">
      <tr><th>#</th><th>Do</th><th>Temat</th><th>Kontekst</th><th>Status</th><th>Próby</th><th>Zaplanowane</th><th>Wysłane</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach($rows as $m):
      $sc = match($m['status']) { 'sent'=>'success','failed'=>'danger','pending'=>'warning','sending'=>'info', default=>'secondary' };
    ?>
    <tr class="<?= $m['status']==='failed'?'table-danger':'' ?>">
      <td class="text-muted"><?= $m['id'] ?></td>
      <td class="text-truncate" style="max-width:160px" title="<?= h($m['to_email']) ?>"><?= h($m['to_name'] ? $m['to_name'].' <'.$m['to_email'].'>' : $m['to_email']) ?></td>
      <td class="text-truncate" style="max-width:200px" title="<?= h($m['subject']) ?>"><?= h($m['subject']) ?></td>
      <td class="text-muted"><?= h($m['context_type']) ?><?= $m['context_id'] ? ' #'.$m['context_id'] : '' ?></td>
      <td><span class="badge bg-<?= $sc ?>"><?= h($m['status']) ?></span></td>
      <td><?= $m['retry_count'] ?>
        <?php if($m['last_error']): ?><span class="text-danger ms-1" title="<?= h($m['last_error']) ?>"><i class="bi bi-exclamation-circle"></i></span><?php endif; ?>
      </td>
      <td class="text-muted text-nowrap"><?= $m['scheduled_at'] ? date('d.m H:i', strtotime($m['scheduled_at'])) : '—' ?></td>
      <td class="text-muted text-nowrap"><?= $m['sent_at'] ? date('d.m H:i', strtotime($m['sent_at'])) : '—' ?></td>
      <td>
        <div class="d-flex gap-1">
          <?php if(in_array($m['status'],['failed','pending'])): ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="retry">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn btn-xs btn-outline-success btn-sm" title="Ponów" style="font-size:.7rem;padding:.2rem .4rem"><i class="bi bi-arrow-clockwise"></i></button>
          </form>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="cancel">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn btn-xs btn-outline-secondary btn-sm" title="Anuluj" style="font-size:.7rem;padding:.2rem .4rem"><i class="bi bi-x-lg"></i></button>
          </form>
          <?php endif; ?>
          <!-- Preview HTML -->
          <button class="btn btn-xs btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#previewModal<?= $m['id'] ?>" style="font-size:.7rem;padding:.2rem .4rem" title="Podgląd treści"><i class="bi bi-eye"></i></button>
        </div>
        <!-- Modal podglądu -->
        <div class="modal fade" id="previewModal<?= $m['id'] ?>" tabindex="-1">
          <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">Podgląd: <?= h($m['subject']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-0"><iframe style="width:100%;height:480px;border:none" srcdoc="<?= htmlspecialchars($m['body_html'], ENT_QUOTES) ?>"></iframe></div>
          </div></div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="text-center py-5 text-muted"><i class="bi bi-send-check" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.25"></i>Brak e-maili w tej kategorii.</div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
