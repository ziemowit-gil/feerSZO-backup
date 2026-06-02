<?php
/**
 * Cron — przetwarzanie kolejki e-mail.
 *
 * Użycie (crontab):
 *   * * * * * php /var/www/umowy/cron/mail_queue.php >> /var/log/mail_queue.log 2>&1
 *
 * Można też wywołać ręcznie z admina (GET/POST z sesją admina).
 */

// ── Wykrywanie trybu (CLI vs HTTP) ────────────────────────────────────────────
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    // W trybie HTTP — wymagaj admina
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/mail_queue.php';
    require_role('admin');
} else {
    // W trybie CLI — załaduj bezpośrednio
    define('APP_CLI', true);
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/mail_queue.php';
}

// ── Przetworzenie ─────────────────────────────────────────────────────────────
$batch  = (int)($_GET['batch'] ?? $_SERVER['argv'][1] ?? 20);
$result = mail_queue_process($batch);
$stats  = mail_queue_stats();

$msg = sprintf(
    '[%s] mail_queue: wysłano=%d, błędów=%d | kolejka: pending=%d, failed=%d, sent=%d',
    date('Y-m-d H:i:s'),
    $result['sent'],
    $result['failed'],
    $stats['pending'],
    $stats['failed'],
    $stats['sent']
);

if ($is_cli) {
    echo $msg . PHP_EOL;
} else {
    // Tryb HTTP — zwróć JSON lub przekieruj
    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json');
        echo json_encode(array_merge($result, ['stats' => $stats, 'ts' => date('Y-m-d H:i:s')]));
        exit;
    }
    $PAGE_TITLE = 'Kolejka e-mail — przetwarzanie';
    include dirname(__DIR__) . '/includes/header.php';
    ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h4 class="mb-0 fw-bold"><i class="bi bi-send-check text-primary me-2"></i>Kolejka e-mail</h4>
      <a href="?batch=<?= $batch ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Uruchom ponownie</a>
    </div>

    <?php if ($result['sent'] > 0 || $result['failed'] > 0): ?>
    <div class="alert alert-<?= $result['failed'] > 0 ? 'warning' : 'success' ?> py-2">
      <i class="bi bi-<?= $result['failed'] > 0 ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i>
      Przetworzono: <strong><?= $result['sent'] ?></strong> wysłanych,
      <strong><?= $result['failed'] ?></strong> błędów (batch=<?= $batch ?>)
    </div>
    <?php else: ?>
    <div class="alert alert-secondary py-2"><i class="bi bi-inbox me-1"></i>Brak wiadomości do wysyłki w tej chwili.</div>
    <?php endif; ?>

    <!-- Statystyki -->
    <div class="row g-3 mb-4">
      <?php foreach([
        ['Oczekujące',  $stats['pending'],   'warning',   'bi-hourglass-split'],
        ['Wysłane',     $stats['sent'],      'success',   'bi-check-circle'],
        ['Błędy',       $stats['failed'],    'danger',    'bi-x-circle'],
        ['Anulowane',   $stats['cancelled'], 'secondary', 'bi-slash-circle'],
        ['Łącznie',     $stats['total'],     'primary',   'bi-envelope'],
      ] as [$lbl,$val,$col,$ico]): ?>
      <div class="col-6 col-md">
        <div class="card text-center border-<?= $col ?> shadow-sm">
          <div class="card-body py-2">
            <div class="fs-4 fw-bold text-<?= $col ?>"><?= $val ?></div>
            <div class="text-muted" style="font-size:.74rem"><i class="bi <?= $ico ?> me-1"></i><?= $lbl ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Lista wiadomości -->
    <?php
    $all_msgs = mail_queue_all('', 100);
    if ($all_msgs):
    ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.88rem">
        <i class="bi bi-list-ul me-1 text-primary"></i>Ostatnie wiadomości (<?= count($all_msgs) ?>)
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead class="table-light">
            <tr><th>Data</th><th>Do</th><th>Temat</th><th>Status</th><th>Próby</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach($all_msgs as $m):
            $sc = match($m['status']) { 'sent'=>'success','failed'=>'danger','pending'=>'warning','sending'=>'info','cancelled'=>'secondary', default=>'secondary' };
          ?>
          <tr>
            <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></td>
            <td><?= h(mb_substr($m['to_email'],0,30)) ?><?= $m['to_name'] ? '<br><small class="text-muted">'.h(mb_substr($m['to_name'],0,25)).'</small>' : '' ?></td>
            <td><?= h(mb_substr($m['subject'],0,55)) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= h($m['status']) ?></span>
              <?php if($m['last_error']): ?><br><small class="text-danger"><?= h(mb_substr($m['last_error'],0,40)) ?></small><?php endif; ?>
            </td>
            <td class="text-center"><?= (int)$m['retry_count'] ?></td>
            <td>
              <?php if(in_array($m['status'],['pending','failed'])): ?>
              <form method="post" action="<?= APP_URL ?>/admin/mail_queue.php" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <?php if($m['status']==='failed'): ?>
                <button name="action" value="retry" class="btn btn-xs btn-outline-success btn-sm" title="Ponów"><i class="bi bi-arrow-clockwise"></i></button>
                <?php endif; ?>
                <button name="action" value="cancel" class="btn btn-xs btn-outline-danger btn-sm" title="Anuluj"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php include dirname(__DIR__) . '/includes/footer.php';
}
