<?php
/**
 * Cron — przetwarzanie zbiorczej kolejki e-mail (bulk_email).
 *
 * Wywołanie (crontab):
 *   * * * * * php /var/www/umowy/cron/bulk_email_process.php >> /var/log/bulk_email.log 2>&1
 *
 * Można też wywołać ręcznie z przeglądarki (wymaga sesji admina).
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/mail_queue.php';
    require_role('admin');
} else {
    define('APP_CLI', true);
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/mail_queue.php';
}

// ── Przetworzenie kolejki ─────────────────────────────────────────────────────

$batch  = (int)($_GET['batch'] ?? $_SERVER['argv'][1] ?? 50);
$result = mail_queue_process($batch);
$stats  = mail_queue_stats();

$log_line = sprintf(
    '[%s] bulk_email_process: wysłano=%d, błędów=%d | kolejka: oczekujące=%d, nieudane=%d, wysłane=%d',
    date('Y-m-d H:i:s'),
    $result['sent'],
    $result['failed'],
    $stats['pending'],
    $stats['failed'],
    $stats['sent']
);

if ($is_cli) {
    echo $log_line . PHP_EOL;
    exit(0);
}

// ── Tryb HTTP — odpowiedź dla przeglądarki ────────────────────────────────────

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge($result, ['stats' => $stats, 'ts' => date('Y-m-d H:i:s')]));
    exit;
}

$PAGE_TITLE = 'Przetwarzanie kolejki e-mail';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 fw-bold">
    <i class="bi bi-send-check text-primary me-2"></i>Kolejka e-mail — przetwarzanie
  </h4>
  <a href="?batch=<?= (int)$batch ?>" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-arrow-clockwise me-1"></i>Uruchom ponownie
  </a>
</div>

<?php if ($result['sent'] > 0 || $result['failed'] > 0): ?>
<div class="alert alert-<?= $result['failed'] > 0 ? 'warning' : 'success' ?> py-2">
  <i class="bi bi-<?= $result['failed'] > 0 ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i>
  Przetworzono: <strong><?= $result['sent'] ?></strong> wysłanych,
  <strong><?= $result['failed'] ?></strong> błędów (batch=<?= (int)$batch ?>)
</div>
<?php else: ?>
<div class="alert alert-secondary py-2">
  <i class="bi bi-inbox me-1"></i>Brak wiadomości do wysyłki w tej chwili.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['Oczekujące',  $stats['pending'],   'warning',   'bi-hourglass-split'],
    ['Wysłane',     $stats['sent'],      'success',   'bi-check-circle'],
    ['Błędy',       $stats['failed'],    'danger',    'bi-x-circle'],
    ['Anulowane',   $stats['cancelled'], 'secondary', 'bi-slash-circle'],
    ['Łącznie',     $stats['total'],     'primary',   'bi-envelope'],
  ] as [$lbl, $val, $col, $ico]): ?>
  <div class="col-6 col-md">
    <div class="card text-center border-<?= $col ?> shadow-sm">
      <div class="card-body py-2">
        <div class="fs-4 fw-bold text-<?= $col ?>"><?= (int)$val ?></div>
        <div class="text-muted" style="font-size:.74rem">
          <i class="bi <?= $ico ?> me-1"></i><?= h($lbl) ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="d-flex gap-2">
  <a href="<?= APP_URL ?>/admin/bulk_email.php" class="btn btn-sm btn-primary">
    <i class="bi bi-send-fill me-1"></i>Masowa wysyłka
  </a>
  <a href="<?= APP_URL ?>/admin/mail_queue.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-list-ul me-1"></i>Kolejka e-mail
  </a>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
